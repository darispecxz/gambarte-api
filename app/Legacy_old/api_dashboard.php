<?php
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(120);
ob_start();
header('Content-Type: application/json');

try {
    // Credenciales desde la configuración del proyecto (única fuente; no versionada).
    // Evita tener credenciales hardcodeadas en este archivo.
    $__cfg = require __DIR__ . '/riesgo_cambiario/config.php';
    $__c   = $__cfg['cgr'];
    $db = new PDO(
        "mysql:host={$__c['host']};port={$__c['port']};dbname={$__c['name']};charset=utf8",
        $__c['user'],
        $__c['pass']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['error' => 'Conexión fallida: ' . $e->getMessage()]);
    exit;
}

ob_end_clean();

$desde  = isset($_GET['desde'])  ? $_GET['desde']  : date('Y-m-01');
$hasta  = isset($_GET['hasta'])  ? $_GET['hasta']  : date('Y-m-d') . ' 23:59:59';
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'operativo';

// ── helpers ──────────────────────────────────────────────────────────
function query($db, $sql, $params=array()) {
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Tablas pre-agregadas de cotizaciones (evita subquery por fila) ───
// Última cotización oficial por moneda
$sql_co = "
    SELECT co.id_moneda, co.valor
    FROM cotizacion_oficial co
    INNER JOIN (
        SELECT id_moneda, MAX(fec_crea_cotizacion_oficial) AS max_fecha
        FROM cotizacion_oficial WHERE id_operacion=1
        GROUP BY id_moneda
    ) ult ON ult.id_moneda=co.id_moneda AND ult.max_fecha=co.fec_crea_cotizacion_oficial
    WHERE co.id_operacion=1
";
// Última cotización base por moneda+agencia
$sql_cb = "
    SELECT cb.id_moneda, cb.id_agencia, cb.valor_base
    FROM cotizacion_base cb
    INNER JOIN (
        SELECT id_moneda, id_agencia, MAX(fecha_creacion) AS max_fecha
        FROM cotizacion_base WHERE id_operacion=1
        GROUP BY id_moneda, id_agencia
    ) ult ON ult.id_moneda=cb.id_moneda AND ult.id_agencia=cb.id_agencia AND ult.max_fecha=cb.fecha_creacion
    WHERE cb.id_operacion=1
";

// ── CTEs de triples (reutilizables) ──────────────────────────────────
$cte_triples = "
WITH triple_keys AS (
    SELECT num_doc_identidad, id_expedicion, id_agencia, fec_crea_cambio
    FROM cambio
    WHERE fec_crea_cambio BETWEEN :desde AND :hasta
    GROUP BY num_doc_identidad, id_expedicion, id_agencia, fec_crea_cambio
    HAVING COUNT(*)=3
       AND SUM(CASE WHEN id_operacion=1 AND mon_rec=3 AND mon_ent=2 THEN 1 ELSE 0 END)=1
       AND SUM(CASE WHEN id_operacion=2 AND mon_rec=2 AND mon_ent=3 THEN 1 ELSE 0 END)=1
       AND (SUM(CASE WHEN id_operacion=1 AND mon_rec=1 AND mon_ent=2 THEN 1 ELSE 0 END)=1
         OR SUM(CASE WHEN id_operacion=2 AND mon_rec=2 AND mon_ent=1 THEN 1 ELSE 0 END)=1)
),
triple_ids AS (
    SELECT c.id_cambio FROM triple_keys tk
    JOIN cambio c ON c.num_doc_identidad=tk.num_doc_identidad AND c.id_expedicion=tk.id_expedicion
                 AND c.id_agencia=tk.id_agencia AND c.fec_crea_cambio=tk.fec_crea_cambio
),
triple_compra AS (
    SELECT op_usd.id_agencia, op_usd.fec_crea_cambio,
        op_usd.monto AS monto_usd,
        op_clp.total_cambio / op_usd.monto AS tc_efectivo
    FROM triple_keys tk
    JOIN cambio op_clp ON op_clp.num_doc_identidad=tk.num_doc_identidad AND op_clp.id_expedicion=tk.id_expedicion
                      AND op_clp.id_agencia=tk.id_agencia AND op_clp.fec_crea_cambio=tk.fec_crea_cambio
                      AND op_clp.id_operacion=1 AND op_clp.mon_rec=3
    JOIN cambio op_usd ON op_usd.num_doc_identidad=tk.num_doc_identidad AND op_usd.id_expedicion=tk.id_expedicion
                      AND op_usd.id_agencia=tk.id_agencia AND op_usd.fec_crea_cambio=tk.fec_crea_cambio
                      AND op_usd.id_operacion=1 AND op_usd.mon_rec=1
),
triple_venta AS (
    SELECT op_usd.id_agencia, op_usd.fec_crea_cambio,
        op_usd.total_cambio AS monto_usd,
        op_bob.monto / op_usd.total_cambio AS tc_bob_efectivo
    FROM triple_keys tk
    JOIN cambio op_bob ON op_bob.num_doc_identidad=tk.num_doc_identidad AND op_bob.id_expedicion=tk.id_expedicion
                      AND op_bob.id_agencia=tk.id_agencia AND op_bob.fec_crea_cambio=tk.fec_crea_cambio
                      AND op_bob.id_operacion=2 AND op_bob.mon_ent=3
    JOIN cambio op_usd ON op_usd.num_doc_identidad=tk.num_doc_identidad AND op_usd.id_expedicion=tk.id_expedicion
                      AND op_usd.id_agencia=tk.id_agencia AND op_usd.fec_crea_cambio=tk.fec_crea_cambio
                      AND op_usd.id_operacion=2 AND op_usd.mon_ent=1
)
";
$params = [':desde' => $desde, ':hasta' => $hasta];

// ── OPERATIVO ─────────────────────────────────────────────────────────
if ($accion === 'operativo') {
    $sql = "
        SELECT m.abreviatura AS moneda,
            COUNT(CASE WHEN c.id_operacion=1 THEN 1 END) AS ops_compra,
            ROUND(SUM(CASE WHEN c.id_operacion=1 THEN c.monto ELSE 0 END),2) AS vol_compra,
            COUNT(CASE WHEN c.id_operacion=2 THEN 1 END) AS ops_venta,
            ROUND(SUM(CASE WHEN c.id_operacion=2 THEN c.monto ELSE 0 END),2) AS vol_venta
        FROM cambio c
        JOIN moneda m ON m.id_moneda=CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
        WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
          AND NOT (c.mon_rec=2 AND c.mon_ent=2)
        GROUP BY m.abreviatura
        ORDER BY 3 DESC
    ";
    echo json_encode(query($db, $sql, $params));
}

// ── UTILIDADES (con triples como USD) ────────────────────────────────
elseif ($accion === 'utilidades') {
    $sql = $cte_triples . "
        , base AS (
            SELECT m.abreviatura AS moneda,
                ROUND(SUM(CASE WHEN c.id_operacion=1 THEN c.monto*(co.valor-c.tipo_cambio) ELSE 0 END),2) AS util_compra,
                ROUND(SUM(CASE WHEN c.id_operacion=2 THEN c.monto*(c.tipo_cambio_bob-cb.valor_base) ELSE 0 END),2) AS util_venta,
                ROUND(SUM(c.monto),2) AS vol_total
            FROM cambio c
            JOIN moneda m ON m.id_moneda=CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
            LEFT JOIN ($sql_co) co ON co.id_moneda=CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
            LEFT JOIN ($sql_cb) cb ON cb.id_moneda=c.mon_ent AND cb.id_agencia=c.id_agencia
            WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
              AND NOT (c.mon_rec=2 AND c.mon_ent=2)
              AND c.id_cambio NOT IN (SELECT id_cambio FROM triple_ids)
            GROUP BY m.abreviatura
            UNION ALL
            SELECT 'USD',
                ROUND(SUM(tc.monto_usd*(co2.valor-tc.tc_efectivo)),2), 0,
                ROUND(SUM(tc.monto_usd),2)
            FROM triple_compra tc
            LEFT JOIN ($sql_co) co2 ON co2.id_moneda=1
            UNION ALL
            SELECT 'USD', 0,
                ROUND(SUM(tv.monto_usd*(tv.tc_bob_efectivo-cb2.valor_base)),2),
                ROUND(SUM(tv.monto_usd),2)
            FROM triple_venta tv
            LEFT JOIN ($sql_cb) cb2 ON cb2.id_moneda=1 AND cb2.id_agencia=tv.id_agencia
        )
        SELECT moneda,
            ROUND(SUM(util_compra),2) AS util_compra,
            ROUND(SUM(util_venta),2)  AS util_venta,
            ROUND(SUM(util_compra)+SUM(util_venta),2) AS util_total,
            ROUND(SUM(vol_total),2) AS vol_total
        FROM base
        GROUP BY moneda
        ORDER BY util_total DESC
    ";
    echo json_encode(query($db, $sql, $params));
}

// ── POR AGENCIA (con ganancia) ────────────────────────────────────────
elseif ($accion === 'agencias') {
    $sql = "
        SELECT a.descripcion AS agencia, m.abreviatura AS moneda,
            COUNT(CASE WHEN c.id_operacion=1 THEN 1 END) AS ops_compra,
            ROUND(SUM(CASE WHEN c.id_operacion=1 THEN c.monto ELSE 0 END),2) AS vol_compra,
            COUNT(CASE WHEN c.id_operacion=2 THEN 1 END) AS ops_venta,
            ROUND(SUM(CASE WHEN c.id_operacion=2 THEN c.monto ELSE 0 END),2) AS vol_venta,
            ROUND(SUM(CASE WHEN c.id_operacion=1 THEN c.monto*(co.valor-c.tipo_cambio) ELSE 0 END),2) AS util_compra,
            ROUND(SUM(CASE WHEN c.id_operacion=2 THEN c.monto*(c.tipo_cambio_bob-cb.valor_base) ELSE 0 END),2) AS util_venta
        FROM cambio c
        JOIN agencia a ON a.id_agencia=c.id_agencia
        JOIN moneda m ON m.id_moneda=CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
        LEFT JOIN ($sql_co) co ON co.id_moneda=CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
        LEFT JOIN ($sql_cb) cb ON cb.id_moneda=c.mon_ent AND cb.id_agencia=c.id_agencia
        WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
          AND NOT (c.mon_rec=2 AND c.mon_ent=2)
        GROUP BY a.descripcion, m.abreviatura
        ORDER BY a.descripcion,
            (SUM(CASE WHEN c.id_operacion=1 THEN c.monto ELSE 0 END)+SUM(CASE WHEN c.id_operacion=2 THEN c.monto ELSE 0 END)) DESC
    ";
    echo json_encode(query($db, $sql, $params));
}

// ── LIVE (hoy) ────────────────────────────────────────────────────────
elseif ($accion === 'live') {
    $sql = "
        SELECT m.abreviatura AS moneda,
            COUNT(CASE WHEN c.id_operacion=1 THEN 1 END) AS compras_hoy,
            ROUND(SUM(CASE WHEN c.id_operacion=1 THEN c.monto ELSE 0 END),2) AS vol_compra_hoy,
            COUNT(CASE WHEN c.id_operacion=2 THEN 1 END) AS ventas_hoy,
            ROUND(SUM(CASE WHEN c.id_operacion=2 THEN c.monto ELSE 0 END),2) AS vol_venta_hoy
        FROM cambio c
        JOIN moneda m ON m.id_moneda=CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
        WHERE DATE(c.fec_crea_cambio)=CURDATE()
          AND NOT (c.mon_rec=2 AND c.mon_ent=2)
        GROUP BY m.abreviatura
        ORDER BY (COUNT(CASE WHEN c.id_operacion=1 THEN 1 END)+COUNT(CASE WHEN c.id_operacion=2 THEN 1 END)) DESC
    ";
    echo json_encode(query($db, $sql, []));
}

// ── CAJEROS ───────────────────────────────────────────────
elseif ($accion === 'cajeros') {
    $sql_cambio = "
        SELECT a.descripcion AS agencia,
               u.login_usu AS login,
               TRIM(CONCAT(t.nom_trab, ' ', t.ape_pat_trab, IFNULL(CONCAT(' ', t.ape_mat_trab), ''))) AS nombre,
               COUNT(*) AS n_cambio
        FROM cambio c
        JOIN usuario u ON u.cod_usuario = c.cod_usuario
        LEFT JOIN trabajador t ON t.cod_trab = c.cod_usuario
        JOIN agencia a ON a.id_agencia = c.id_agencia
        WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
          AND NOT (c.mon_rec=2 AND c.mon_ent=2)
        GROUP BY c.cod_usuario, a.descripcion, u.login_usu, t.nom_trab, t.ape_pat_trab, t.ape_mat_trab
    ";
    try {
        $rows = query($db, $sql_cambio, $params);
    } catch(Exception $e) {
        echo json_encode(['__debug_error' => $e->getMessage()]);
        exit;
    }

    $giro_map = array();
    try {
        $gr = query($db, "
            SELECT u.login_usu AS login, COUNT(*) AS n FROM giro g
            JOIN usuario u ON u.cod_usuario=g.cod_usuario
            WHERE g.fec_crea_giro BETWEEN :desde AND :hasta
            GROUP BY u.login_usu
        ", $params);
        foreach($gr as $r) $giro_map[$r['login']] = intval($r['n']);
    } catch(Exception $e) {}

    $remesa_map = array();
    try {
        $rr = query($db, "
            SELECT u.login_usu AS login, COUNT(*) AS n FROM remesa r
            JOIN usuario u ON u.cod_usuario=r.cod_usuario
            WHERE r.fec_crea_remesa BETWEEN :desde AND :hasta
            GROUP BY u.login_usu
        ", $params);
        foreach($rr as $r) $remesa_map[$r['login']] = intval($r['n']);
    } catch(Exception $e) {}

    $result = array();
    foreach($rows as $r) {
        $lg = $r['login'];
        $nc = intval($r['n_cambio']);
        $ng = isset($giro_map[$lg])   ? $giro_map[$lg]   : 0;
        $nr = isset($remesa_map[$lg]) ? $remesa_map[$lg] : 0;
        $result[] = array(
            'agencia' => $r['agencia'],
            'login'   => $lg,
            'nombre'  => trim($r['nombre']),
            'cambio'  => $nc,
            'giro'    => $ng,
            'remesa'  => $nr,
            'total'   => $nc + $ng + $nr
        );
    }
    usort($result, function($a,$b){ return $b['total'] - $a['total']; });
    echo json_encode(array_values($result));
}

// ── PRECIOS (max/min/avg de cotizacion_base por moneda) ──
elseif ($accion === 'precios') {
    $sql = "
        SELECT m.abreviatura AS moneda,
               ROUND(MAX(CASE WHEN cb.id_operacion=1 THEN cb.valor_base END),6) AS max_compra,
               ROUND(MIN(CASE WHEN cb.id_operacion=1 THEN cb.valor_base END),6) AS min_compra,
               ROUND(AVG(CASE WHEN cb.id_operacion=1 THEN cb.valor_base END),6) AS prom_compra,
               ROUND(MAX(CASE WHEN cb.id_operacion=2 THEN cb.valor_base END),6) AS max_venta,
               ROUND(MIN(CASE WHEN cb.id_operacion=2 THEN cb.valor_base END),6) AS min_venta,
               ROUND(AVG(CASE WHEN cb.id_operacion=2 THEN cb.valor_base END),6) AS prom_venta,
               COUNT(DISTINCT DATE(cb.fecha_creacion)) AS dias_datos
        FROM cotizacion_base cb
        JOIN moneda m ON m.id_moneda=cb.id_moneda
        WHERE cb.fecha_creacion BETWEEN :desde AND :hasta
          AND cb.id_moneda != 2
        GROUP BY m.id_moneda, m.abreviatura
        HAVING max_compra IS NOT NULL OR max_venta IS NOT NULL
        ORDER BY m.abreviatura
    ";
    echo json_encode(query($db, $sql, $params));
}

// ── COTIZACIONES (historial diario de cotizacion_base) ───
elseif ($accion === 'cotizaciones') {
    $sql = "
        SELECT DATE(cb.fecha_creacion) AS fecha,
               a.descripcion AS agencia,
               m.abreviatura AS moneda,
               CASE cb.id_operacion WHEN 1 THEN 'COMPRA' ELSE 'VENTA' END AS tipo,
               ROUND(MIN(cb.valor_base),6) AS minimo,
               ROUND(MAX(cb.valor_base),6) AS maximo,
               ROUND(AVG(cb.valor_base),6) AS promedio
        FROM cotizacion_base cb
        JOIN agencia a ON a.id_agencia=cb.id_agencia
        JOIN moneda m ON m.id_moneda=cb.id_moneda
        WHERE cb.fecha_creacion BETWEEN :desde AND :hasta
          AND cb.id_moneda != 2
        GROUP BY DATE(cb.fecha_creacion), cb.id_agencia, cb.id_moneda, cb.id_operacion
        ORDER BY fecha ASC, agencia, moneda, tipo
    ";
    echo json_encode(query($db, $sql, $params));
}

// ── COMPARATIVO BOLIVIA vs CHILE ─────────────────────────────────────
elseif ($accion === 'plazas') {
    /*
     * Arbitraje por plaza: ¿en qué país conviene más operar cada divisa?
     * Spread % = (venta - compra) / compra × 100  → comparable entre países.
     * Bolivia: tasas vigentes de cotizacion_base (agencia 10, última por moneda/op).
     * Chile:   tasas vigentes de cotizacion (estado='A', promedio por moneda/op).
     */

    // ── BOLIVIA ──────────────────────────────────────────────
    // Última tasa publicada por moneda y tipo de operación (agencia 10)
    $sql_bo = "
        SELECT
            m.abreviatura AS moneda,
            m.id_moneda,
            MAX(CASE WHEN cb.id_operacion=1 THEN cb.valor_base END) AS compra_bob,
            MAX(CASE WHEN cb.id_operacion=2 THEN cb.valor_base END) AS venta_bob
        FROM cotizacion_base cb
        JOIN (
            SELECT id_moneda, id_operacion, MAX(fecha_creacion) AS max_fc
            FROM cotizacion_base
            WHERE id_agencia = 10
            GROUP BY id_moneda, id_operacion
        ) latest ON latest.id_moneda    = cb.id_moneda
                AND latest.id_operacion = cb.id_operacion
                AND latest.max_fc       = cb.fecha_creacion
        JOIN moneda m ON m.id_moneda = cb.id_moneda
        WHERE cb.id_agencia = 10
        GROUP BY m.id_moneda, m.abreviatura
        ORDER BY m.abreviatura
    ";
    $bo_rates = query($db, $sql_bo, []) ?: array();

    // TC USD/BOB vigente (para normalizar a USD el spread de otras monedas)
    $tc_usd_bob = 6.96;
    foreach ($bo_rates as $r) {
        if ($r['moneda'] === 'USD' && floatval($r['venta_bob']) > 0) {
            $tc_usd_bob = floatval($r['venta_bob']);
            break;
        }
    }

    // Volumen del período por moneda (para contexto, no para las tasas)
    $sql_bo_vol = "
        SELECT
            m.abreviatura AS moneda,
            SUM(CASE WHEN c.id_operacion=1 THEN 1 ELSE 0 END) AS n_compra,
            SUM(CASE WHEN c.id_operacion=2 THEN 1 ELSE 0 END) AS n_venta,
            ROUND(SUM(CASE WHEN c.id_operacion=1 THEN c.monto ELSE 0 END),2) AS vol_compra,
            ROUND(SUM(CASE WHEN c.id_operacion=2 THEN c.monto ELSE 0 END),2) AS vol_venta
        FROM cambio c
        JOIN moneda m ON m.id_moneda = CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
        WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
          AND NOT (c.mon_rec=2 AND c.mon_ent=2)
        GROUP BY m.abreviatura
    ";
    $bo_vol_rows = query($db, $sql_bo_vol, $params) ?: array();
    $bo_vol = array();
    foreach ($bo_vol_rows as $r) {
        $bo_vol[$r['moneda']] = $r;
    }

    $bo_byMon = array();
    foreach ($bo_rates as $r) {
        $mon    = $r['moneda'];
        $compra = floatval($r['compra_bob']);
        $venta  = floatval($r['venta_bob']);
        if ($compra <= 0 || $venta <= 0) continue;
        $spread_pct = round(($venta - $compra) / $compra * 100, 4);
        // USD equivalente ganado por cada 1000 unidades de la moneda operada:
        // spread_bob × 1000 / tc_usd_bob
        $usd_x1000  = round(($venta - $compra) * 1000 / $tc_usd_bob, 2);
        $vol = isset($bo_vol[$mon]) ? $bo_vol[$mon] : array('n_compra'=>0,'n_venta'=>0,'vol_compra'=>0,'vol_venta'=>0);
        $bo_byMon[$mon] = array(
            'compra'     => $compra,
            'venta'      => $venta,
            'spread_pct' => $spread_pct,
            'usd_x1000'  => $usd_x1000,
            'n_compra'   => intval($vol['n_compra']),
            'n_venta'    => intval($vol['n_venta']),
            'vol_compra' => floatval($vol['vol_compra']),
            'vol_venta'  => floatval($vol['vol_venta'])
        );
    }

    // ── CHILE ────────────────────────────────────────────────
    $cl_byMon = array();
    $cl_error = null;
    $tc_usd_clp = 930;
    try {
        $db_cl = new PDO(
            "mysql:host=" . env('DB_CHILE_HOST', '127.0.0.1')
            . ";port=" . env('DB_CHILE_PORT', '3306')
            . ";dbname=" . env('DB_CHILE_DATABASE', '')
            . ";charset=utf8",
            env('DB_CHILE_USERNAME', ''),
            env('DB_CHILE_PASSWORD', '')
        );
        $db_cl->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Tasas vigentes por moneda (promedio de todas las agencias activas)
        $sql_cl_rates = "
            SELECT
                m.abreviatura AS moneda,
                ROUND(AVG(CASE WHEN co.id_operacion=1 THEN co.valor END), 4) AS compra_clp,
                ROUND(AVG(CASE WHEN co.id_operacion=2 THEN co.valor END), 4) AS venta_clp
            FROM cotizacion co
            JOIN moneda m ON m.id_moneda = co.id_moneda
            WHERE co.estado = 'A'
              AND m.abreviatura <> 'CLP'
            GROUP BY m.id_moneda, m.abreviatura
            ORDER BY m.abreviatura
        ";
        $cl_rates = query($db_cl, $sql_cl_rates, []) ?: array();

        // TC USD/CLP vigente
        foreach ($cl_rates as $r) {
            if ($r['moneda'] === 'USD' && floatval($r['venta_clp']) > 0) {
                $tc_usd_clp = floatval($r['venta_clp']);
                break;
            }
        }

        // Volumen del período para contexto
        $sql_cl_vol = "
            SELECT
                m.abreviatura AS moneda,
                SUM(CASE WHEN c.id_operacion=1 THEN 1 ELSE 0 END) AS n_compra,
                SUM(CASE WHEN c.id_operacion=2 THEN 1 ELSE 0 END) AS n_venta,
                ROUND(SUM(CASE WHEN c.id_operacion=1 THEN c.monto ELSE 0 END),2) AS vol_compra,
                ROUND(SUM(CASE WHEN c.id_operacion=2 THEN c.monto ELSE 0 END),2) AS vol_venta
            FROM cambio c
            JOIN moneda m ON m.id_moneda = CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
            WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
              AND m.abreviatura <> 'CLP'
            GROUP BY m.abreviatura
        ";
        $cl_vol_rows = query($db_cl, $sql_cl_vol, $params) ?: array();
        $cl_vol = array();
        foreach ($cl_vol_rows as $r) {
            $cl_vol[$r['moneda']] = $r;
        }

        foreach ($cl_rates as $r) {
            $mon    = $r['moneda'];
            $compra = floatval($r['compra_clp']);
            $venta  = floatval($r['venta_clp']);
            if ($compra <= 0 || $venta <= 0) continue;
            $spread_pct = round(($venta - $compra) / $compra * 100, 4);
            // USD ganado por cada 1000 unidades = spread_clp × 1000 / tc_usd_clp
            $usd_x1000  = round(($venta - $compra) * 1000 / $tc_usd_clp, 2);
            $vol = isset($cl_vol[$mon]) ? $cl_vol[$mon] : array('n_compra'=>0,'n_venta'=>0,'vol_compra'=>0,'vol_venta'=>0);
            $cl_byMon[$mon] = array(
                'compra'     => $compra,
                'venta'      => $venta,
                'spread_pct' => $spread_pct,
                'usd_x1000'  => $usd_x1000,
                'n_compra'   => intval($vol['n_compra']),
                'n_venta'    => intval($vol['n_venta']),
                'vol_compra' => floatval($vol['vol_compra']),
                'vol_venta'  => floatval($vol['vol_venta'])
            );
        }
    } catch (Exception $e) {
        $cl_error = $e->getMessage();
    }

    // ── Unir monedas de ambos países ─────────────────────────
    $all_monedas = array_unique(array_merge(array_keys($bo_byMon), array_keys($cl_byMon)));
    $resultado   = array();
    foreach ($all_monedas as $mon) {
        $bo = isset($bo_byMon[$mon]) ? $bo_byMon[$mon] : null;
        $cl = isset($cl_byMon[$mon]) ? $cl_byMon[$mon] : null;
        // Determinar mejor plaza para VENDER (mayor spread%)
        $mejor = null;
        if ($bo && $cl && $bo['spread_pct'] !== null && $cl['spread_pct'] !== null) {
            $mejor = $bo['spread_pct'] >= $cl['spread_pct'] ? 'bolivia' : 'chile';
        } elseif ($bo && $bo['spread_pct'] !== null) {
            $mejor = 'bolivia';
        } elseif ($cl && $cl['spread_pct'] !== null) {
            $mejor = 'chile';
        }
        $resultado[] = array(
            'moneda'  => $mon,
            'bolivia' => $bo,
            'chile'   => $cl,
            'mejor'   => $mejor
        );
    }

    // Ordenar: primero los que tienen datos en ambas, luego por volumen
    usort($resultado, function($a, $b) {
        $a_both = $a['bolivia'] && $a['chile'] ? 1 : 0;
        $b_both = $b['bolivia'] && $b['chile'] ? 1 : 0;
        if ($a_both !== $b_both) return $b_both - $a_both;
        $a_vol = ($a['bolivia'] ? $a['bolivia']['n_compra']+$a['bolivia']['n_venta'] : 0)
               + ($a['chile']   ? $a['chile']['n_compra']  +$a['chile']['n_venta']   : 0);
        $b_vol = ($b['bolivia'] ? $b['bolivia']['n_compra']+$b['bolivia']['n_venta'] : 0)
               + ($b['chile']   ? $b['chile']['n_compra']  +$b['chile']['n_venta']   : 0);
        return $b_vol - $a_vol;
    });

    echo json_encode(array(
        'monedas'    => $resultado,
        'tc_usd_bob' => $tc_usd_bob,
        'tc_usd_clp' => $tc_usd_clp,
        'cl_error'   => $cl_error
    ));
}

// ── SALDOS DE CAJA POR AGENCIA ───────────────────────────────────────
elseif ($accion === 'saldos') {
    $sql = "
        SELECT
            a.descripcion AS agencia,
            m.abreviatura AS moneda,
            m.id_moneda,
            ROUND(SUM(sc.saldo), 2) AS saldo
        FROM agencia a
        INNER JOIN caja c  ON c.id_agencia = a.id_agencia
        INNER JOIN moneda m ON m.id_moneda  = c.id_moneda
        INNER JOIN saldo_caja sc ON sc.id_caja = c.id_caja
        WHERE sc.conciliacion <> 'C'
          AND a.id_agencia > 1
          AND sc.fec_crea_saldo >= CURDATE()
          AND sc.fec_crea_saldo <  CURDATE() + INTERVAL 1 DAY
        GROUP BY a.descripcion, m.id_moneda, m.abreviatura
        ORDER BY a.descripcion, m.id_moneda
    ";
    $rows = query($db, $sql, []);

    $agencias = array();
    $monedas  = array();
    foreach ($rows as $r) {
        $ag  = $r['agencia'];
        $mon = $r['moneda'];
        if (!isset($agencias[$ag])) $agencias[$ag] = array('agencia' => $ag);
        $agencias[$ag][$mon] = floatval($r['saldo']);
        if (!in_array($mon, $monedas)) $monedas[] = $mon;
    }

    // TC oficial por moneda (BOB por 1 unidad de moneda extranjera)
    $tc_rows = query($db, "
        SELECT m.abreviatura, co.valor AS tc_bob
        FROM cotizacion_oficial co
        INNER JOIN (
            SELECT id_moneda, MAX(fec_crea_cotizacion_oficial) AS max_fecha
            FROM cotizacion_oficial WHERE id_operacion=1
            GROUP BY id_moneda
        ) ult ON ult.id_moneda=co.id_moneda AND ult.max_fecha=co.fec_crea_cotizacion_oficial
        JOIN moneda m ON m.id_moneda=co.id_moneda
        WHERE co.id_operacion=1
    ", []);
    $tc = array();
    foreach ($tc_rows as $r) $tc[$r['abreviatura']] = floatval($r['tc_bob']);

    echo json_encode(array('monedas' => $monedas, 'rows' => array_values($agencias), 'tc' => $tc));
}

// ── OPERACIONES POR HORA ──────────────────────────────────────────────
elseif ($accion === 'horas') {
    $sql = "
        SELECT HOUR(c.fec_crea_cambio) AS hora,
               a.descripcion AS agencia,
               COUNT(*) AS ops,
               COUNT(CASE WHEN c.id_operacion=1 THEN 1 END) AS compras,
               COUNT(CASE WHEN c.id_operacion=2 THEN 1 END) AS ventas
        FROM cambio c
        JOIN agencia a ON a.id_agencia=c.id_agencia
        WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
          AND NOT (c.mon_rec=2 AND c.mon_ent=2)
        GROUP BY HOUR(c.fec_crea_cambio), c.id_agencia, a.descripcion
        ORDER BY hora, agencia
    ";
    echo json_encode(query($db, $sql, $params));
}

// ── MARGEN PROMEDIO POR MONEDA ────────────────────────────────────────
elseif ($accion === 'margen') {
    $sql = "
        SELECT m.abreviatura AS moneda,
               COUNT(CASE WHEN c.id_operacion=1 THEN 1 END) AS n_compra,
               ROUND(AVG(CASE WHEN c.id_operacion=1 THEN co.valor - c.tipo_cambio END),4) AS margen_compra_unit,
               ROUND(AVG(CASE WHEN c.id_operacion=1 AND co.valor>0 THEN (co.valor-c.tipo_cambio)/co.valor*100 END),2) AS margen_compra_pct,
               COUNT(CASE WHEN c.id_operacion=2 THEN 1 END) AS n_venta,
               ROUND(AVG(CASE WHEN c.id_operacion=2 THEN c.tipo_cambio_bob - cb.valor_base END),4) AS margen_venta_unit,
               ROUND(AVG(CASE WHEN c.id_operacion=2 AND cb.valor_base>0 THEN (c.tipo_cambio_bob-cb.valor_base)/cb.valor_base*100 END),2) AS margen_venta_pct
        FROM cambio c
        JOIN moneda m ON m.id_moneda=CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
        LEFT JOIN ($sql_co) co ON co.id_moneda=CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
        LEFT JOIN ($sql_cb) cb ON cb.id_moneda=c.mon_ent AND cb.id_agencia=c.id_agencia
        WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
          AND NOT (c.mon_rec=2 AND c.mon_ent=2)
        GROUP BY m.id_moneda, m.abreviatura
        HAVING n_compra > 0 OR n_venta > 0
        ORDER BY m.abreviatura
    ";
    echo json_encode(query($db, $sql, $params));
}

// ── TENDENCIAS DE COTIZACIONES ───────────────────────────────────────
elseif ($accion === 'tendencias') {
    /*
     * Devuelve dos cosas según parámetros:
     *  - Sin ?moneda : semáforo resumen de todas las monedas (SMA7, SMA30, vol, score)
     *  - Con ?moneda=USD : serie histórica diaria de compra/venta para gráfico
     */
    $id_agencia = 10;

    if (isset($_GET['moneda']) && $_GET['moneda'] !== '') {
        // ── Serie histórica para gráfico ─────────────────────────────
        $moneda = $_GET['moneda'];
        $dias   = isset($_GET['dias']) ? intval($_GET['dias']) : 90;
        if ($dias < 7)  $dias = 7;
        if ($dias > 365) $dias = 365;

        $sql = "
            SELECT DATE(cb.fecha_creacion) AS fecha,
                   ROUND(AVG(CASE WHEN cb.id_operacion=1 THEN cb.valor_base END), 6) AS compra,
                   ROUND(AVG(CASE WHEN cb.id_operacion=2 THEN cb.valor_base END), 6) AS venta
            FROM cotizacion_base cb
            JOIN moneda m ON m.id_moneda = cb.id_moneda
            WHERE cb.id_agencia = :ag
              AND m.abreviatura = :mon
              AND cb.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
            GROUP BY DATE(cb.fecha_creacion)
            ORDER BY fecha ASC
        ";
        $rows = query($db, $sql, array(':ag' => $id_agencia, ':mon' => $moneda, ':dias' => $dias));

        // Volumen diario de esa moneda (operaciones en tabla cambio)
        $sql_vol = "
            SELECT DATE(c.fec_crea_cambio) AS fecha,
                   COUNT(*) AS ops,
                   ROUND(SUM(CASE WHEN c.id_operacion=1 THEN c.monto ELSE 0 END),2) AS vol_compra,
                   ROUND(SUM(CASE WHEN c.id_operacion=2 THEN c.monto ELSE 0 END),2) AS vol_venta
            FROM cambio c
            JOIN moneda m ON m.id_moneda = CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
            WHERE m.abreviatura = :mon
              AND c.fec_crea_cambio >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
            GROUP BY DATE(c.fec_crea_cambio)
            ORDER BY fecha ASC
        ";
        $vol_rows = query($db, $sql_vol, array(':mon' => $moneda, ':dias' => $dias));
        $vol_by_fecha = array();
        foreach ($vol_rows as $vr) $vol_by_fecha[$vr['fecha']] = $vr;

        $serie = array();
        foreach ($rows as $r) {
            $v = isset($vol_by_fecha[$r['fecha']]) ? $vol_by_fecha[$r['fecha']] : array();
            $serie[] = array(
                'fecha'      => $r['fecha'],
                'compra'     => floatval($r['compra']),
                'venta'      => floatval($r['venta']),
                'ops'        => isset($v['ops'])        ? intval($v['ops'])          : 0,
                'vol_compra' => isset($v['vol_compra']) ? floatval($v['vol_compra']) : 0,
                'vol_venta'  => isset($v['vol_venta'])  ? floatval($v['vol_venta'])  : 0,
            );
        }
        echo json_encode(array('moneda' => $moneda, 'serie' => $serie));

    } else {
        // ── Semáforo resumen de todas las monedas ────────────────────
        // Paso 1: último día con datos por moneda (sin subconsulta correlacionada)
        $sql_last = "
            SELECT id_moneda, MAX(DATE(fecha_creacion)) AS last_date
            FROM cotizacion_base
            WHERE id_agencia = :ag AND id_moneda != 2
            GROUP BY id_moneda
        ";
        $last_rows = query($db, $sql_last, array(':ag' => $id_agencia));
        $last_by_mon = array();
        foreach ($last_rows as $lr) $last_by_mon[$lr['id_moneda']] = $lr['last_date'];

        // Paso 2: SMA, volatilidad y días en un solo scan de 30 días
        $sql = "
            SELECT
                m.abreviatura AS moneda,
                m.id_moneda,
                ROUND(AVG(CASE WHEN cb.id_operacion=2
                    AND cb.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    THEN cb.valor_base END), 6) AS sma7,
                ROUND(AVG(CASE WHEN cb.id_operacion=2
                    AND cb.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    THEN cb.valor_base END), 6) AS sma30,
                ROUND(AVG(CASE WHEN cb.id_operacion=1
                    AND cb.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    THEN cb.valor_base END), 6) AS sma30_compra,
                ROUND(AVG(CASE WHEN cb.id_operacion=2
                    AND cb.fecha_creacion BETWEEN DATE_SUB(CURDATE(), INTERVAL 21 DAY)
                                              AND DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                    THEN cb.valor_base END), 6) AS sma7_lag,
                ROUND(STDDEV(CASE WHEN cb.id_operacion=2
                    AND cb.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    THEN cb.valor_base END)
                / NULLIF(AVG(CASE WHEN cb.id_operacion=2
                    AND cb.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    THEN cb.valor_base END), 0) * 100, 3) AS volatilidad_pct,
                COUNT(DISTINCT CASE WHEN cb.id_operacion=2
                    AND cb.fecha_creacion >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    THEN DATE(cb.fecha_creacion) END) AS dias_30
            FROM cotizacion_base cb
            JOIN moneda m ON m.id_moneda = cb.id_moneda
            WHERE cb.id_agencia = :ag AND cb.id_moneda != 2
            GROUP BY m.id_moneda, m.abreviatura
            HAVING sma7 IS NOT NULL AND sma30 IS NOT NULL
            ORDER BY m.abreviatura
        ";
        $rows = query($db, $sql, array(':ag' => $id_agencia));

        // Paso 3: precio actual por moneda (separado, usando last_date)
        $sql_cur = "
            SELECT m.abreviatura AS moneda,
                   ROUND(AVG(CASE WHEN cb.id_operacion=2 THEN cb.valor_base END), 6) AS venta_actual,
                   ROUND(AVG(CASE WHEN cb.id_operacion=1 THEN cb.valor_base END), 6) AS compra_actual
            FROM cotizacion_base cb
            JOIN moneda m ON m.id_moneda = cb.id_moneda
            JOIN (
                SELECT id_moneda, MAX(DATE(fecha_creacion)) AS last_date
                FROM cotizacion_base WHERE id_agencia = :ag AND id_moneda != 2
                GROUP BY id_moneda
            ) ld ON ld.id_moneda = cb.id_moneda AND DATE(cb.fecha_creacion) = ld.last_date
            WHERE cb.id_agencia = :ag2
            GROUP BY m.id_moneda, m.abreviatura
        ";
        $cur_rows = query($db, $sql_cur, array(':ag' => $id_agencia, ':ag2' => $id_agencia));
        $cur_by_mon = array();
        foreach ($cur_rows as $cr) $cur_by_mon[$cr['moneda']] = $cr;

        // Volumen últimos 30 días por moneda (demanda interna)
        $sql_vol = "
            SELECT m.abreviatura AS moneda,
                   COUNT(*) AS ops_30d,
                   COUNT(CASE WHEN c.fec_crea_cambio >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) AS ops_7d
            FROM cambio c
            JOIN moneda m ON m.id_moneda = CASE c.id_operacion WHEN 1 THEN c.mon_rec ELSE c.mon_ent END
            WHERE c.fec_crea_cambio >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND NOT (c.mon_rec=2 AND c.mon_ent=2)
            GROUP BY m.id_moneda, m.abreviatura
        ";
        $vol_rows = query($db, $sql_vol, []);
        $vol_by_mon = array();
        foreach ($vol_rows as $vr) $vol_by_mon[$vr['moneda']] = $vr;

        $resultado = array();
        foreach ($rows as $r) {
            $mon        = $r['moneda'];
            $sma7       = floatval($r['sma7']);
            $sma30      = floatval($r['sma30']);
            $sma7_lag   = floatval($r['sma7_lag']);
            $vol_pct    = floatval($r['volatilidad_pct']);
            $cur        = isset($cur_by_mon[$mon]) ? $cur_by_mon[$mon] : null;
            $venta_act  = $cur ? floatval($cur['venta_actual'])  : $sma7;
            $compra_act = $cur ? floatval($cur['compra_actual']) : floatval($r['sma30_compra']);

            // Tendencia: diferencia SMA7 vs SMA30 en porcentaje
            $tend_pct = $sma30 > 0 ? ($sma7 - $sma30) / $sma30 * 100 : 0;
            // Pendiente: SMA7 ahora vs SMA7 hace 14 días
            $slope_pct = ($sma7_lag > 0 && $sma7 > 0) ? ($sma7 - $sma7_lag) / $sma7_lag * 100 : 0;

            // Score de tendencia (-2 a +2)
            if ($tend_pct > 0.5)       $s_tend = 2;
            elseif ($tend_pct > 0.1)   $s_tend = 1;
            elseif ($tend_pct < -0.5)  $s_tend = -2;
            elseif ($tend_pct < -0.1)  $s_tend = -1;
            else                        $s_tend = 0;

            // Score de pendiente (-1 a +1)
            $s_slope = $slope_pct > 0.2 ? 1 : ($slope_pct < -0.2 ? -1 : 0);

            // Score de volatilidad (-1 a +1, alta vol = malo)
            $s_vol = $vol_pct > 3 ? -1 : ($vol_pct < 1 ? 1 : 0);

            // Score de demanda (-1 a +1)
            $v = isset($vol_by_mon[$mon]) ? $vol_by_mon[$mon] : null;
            $ops_30 = $v ? intval($v['ops_30d']) : 0;
            $ops_7  = $v ? intval($v['ops_7d'])  : 0;
            // Demanda creciente si ops_7 * (30/7) > ops_30
            $ops_7_proj = $ops_7 > 0 ? $ops_7 * (30.0/7.0) : 0;
            $s_dem = ($ops_30 > 0 && $ops_7_proj > $ops_30 * 1.15) ? 1
                   : (($ops_30 > 0 && $ops_7_proj < $ops_30 * 0.85) ? -1 : 0);

            $score = $s_tend * 2 + $s_slope + $s_vol + $s_dem;
            // Normalizar a -5..+5 aproximadamente → rango real -6..+6
            if ($score >= 3)       $señal = 'COMPRAR';
            elseif ($score >= 1)   $señal = 'ACUMULAR';
            elseif ($score <= -3)  $señal = 'REDUCIR';
            elseif ($score <= -1)  $señal = 'PRECAUCION';
            else                   $señal = 'MANTENER';

            $resultado[] = array(
                'moneda'       => $mon,
                'venta_actual' => $venta_act,
                'compra_actual'=> $compra_act,
                'spread_pct'   => $compra_act > 0 ? round(($venta_act - $compra_act) / $compra_act * 100, 3) : 0,
                'sma7'         => $sma7,
                'sma30'        => $sma30,
                'tend_pct'     => round($tend_pct, 3),
                'slope_pct'    => round($slope_pct, 3),
                'volatilidad'  => $vol_pct,
                'ops_30d'      => $ops_30,
                'ops_7d'       => $ops_7,
                'score'        => $score,
                'señal'        => $señal,
                'dias_30'      => intval($r['dias_30']),
            );
        }

        // Ordenar: primero por score descendente
        usort($resultado, function($a, $b) { return $b['score'] - $a['score']; });
        echo json_encode($resultado);
    }
}

// ── COMPARATIVO PERÍODO ANTERIOR ──────────────────────────────────────
elseif ($accion === 'comparativo') {
    $desde_ant = isset($_GET['desde_ant']) ? $_GET['desde_ant'] : '';
    $hasta_ant  = isset($_GET['hasta_ant'])  ? $_GET['hasta_ant'].' 23:59:59' : '';

    $sql_ops = "
        SELECT COUNT(*) AS total_ops,
               COUNT(CASE WHEN id_operacion=1 THEN 1 END) AS compras,
               COUNT(CASE WHEN id_operacion=2 THEN 1 END) AS ventas,
               ROUND(SUM(CASE WHEN id_operacion=1 THEN monto ELSE 0 END),2) AS vol_compra,
               ROUND(SUM(CASE WHEN id_operacion=2 THEN monto ELSE 0 END),2) AS vol_venta
        FROM cambio
        WHERE fec_crea_cambio BETWEEN :desde AND :hasta
          AND NOT (mon_rec=2 AND mon_ent=2)
    ";
    $res_act = query($db, $sql_ops, $params);
    $actual  = $res_act ? $res_act[0] : array('total_ops'=>0,'compras'=>0,'ventas'=>0,'vol_compra'=>0,'vol_venta'=>0);

    $anterior = array('total_ops'=>0,'compras'=>0,'ventas'=>0,'vol_compra'=>0,'vol_venta'=>0);
    if ($desde_ant && $hasta_ant) {
        $ant_params = array(':desde'=>$desde_ant, ':hasta'=>$hasta_ant);
        $res_ant = query($db, $sql_ops, $ant_params);
        if ($res_ant) $anterior = $res_ant[0];
    }

    echo json_encode(array('actual'=>$actual, 'anterior'=>$anterior));
}

else {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no reconocida']);
}
