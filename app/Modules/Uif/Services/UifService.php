<?php

namespace App\Modules\Uif\Services;

use App\Modules\Shared\Database\RawConnection;

class UifService
{
    // ===================================================================
    //  Agencies
    // ===================================================================

    public function agencies(): array
    {
        return RawConnection::select(
            'SELECT id_agencia, descripcion FROM agencia WHERE id_agencia > 0 ORDER BY descripcion'
        );
    }

    // ===================================================================
    //  PCC Report (PCC-06 / PCC-08)
    // ===================================================================

    public function pccReport(int $pcc, ?string $desde, ?string $hasta, ?int $agencia): array
    {
        $view   = $pcc === 6 ? 'v_pcc06_uif' : 'v_pcc08';
        $where  = [];
        $params = [];

        if ($agencia !== null) {
            $where[]            = 'id_agencia = :agencia';
            $params[':agencia'] = $agencia;
        }

        if ($desde !== null && $hasta !== null) {
            $where[]           = 'fecha >= :desde AND fecha < DATE_ADD(:hasta2, INTERVAL 1 DAY) AND monto >= 2000';
            $params[':desde']  = $desde;
            $params[':hasta2'] = $hasta;
        } elseif ($desde !== null) {
            $where[]           = 'fecha >= :desde AND fecha < DATE_ADD(:desde2, INTERVAL 1 DAY)';
            $params[':desde']  = $desde;
            $params[':desde2'] = $desde;
        }

        $sql = "SELECT * FROM {$view}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return RawConnection::select($sql, $params);
    }

    // ===================================================================
    //  Cambios
    // ===================================================================

    public function cambios(?string $desde, ?string $hasta, ?int $agencia): array
    {
        $where  = [];
        $params = [];

        if ($agencia !== null) {
            $where[]            = 'c.id_agencia = :agencia';
            $params[':agencia'] = $agencia;
        }
        if ($desde !== null && $hasta !== null) {
            $where[]           = 'c.fec_crea_cambio >= :desde AND c.fec_crea_cambio < DATE_ADD(:hasta2, INTERVAL 1 DAY)';
            $params[':desde']  = $desde;
            $params[':hasta2'] = $hasta;
        } elseif ($desde !== null) {
            $where[]           = 'c.fec_crea_cambio >= :desde AND c.fec_crea_cambio < DATE_ADD(:desde2, INTERVAL 1 DAY)';
            $params[':desde']  = $desde;
            $params[':desde2'] = $desde;
        }

        $sql = "SELECT DISTINCT
    id_cambio AS id_cambio,
    c.id_operacion,
    o.descripcion as tipo_operacion,
    fec_crea_cambio AS fecha,
    u.login_usu AS usuario,
    concat(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) as usu_financiero,
    uf.fecha_nacimiento AS fec_nacimiento,
    TIMESTAMPDIFF(YEAR, uf.fecha_nacimiento, CURDATE()) AS edad,
    tdi.abreviatura as tipo_doc,
    c.num_doc_identidad as doc_identidad,
    e.abreviatura as exp,
    p.descripcion,
    ae.descripcion as act_econo,
    IF(id_pep>0,'SI','NO') AS PEP,
    p.descripcion as pais_residencia,
    uf.domicilio AS domicilio,
    uf.celular AS celular,
    dd.descripcion as ciudad_agencia,
    aa.descripcion as agencia,
    mm.abreviatura as mon_rec,
    c.monto as monto_recibido,
    c.tipo_cambio as TC,
    mmm.abreviatura as mon_entregada,
    c.entregado as monto_entregado,
    'BOLIVIA' AS pais_transaccion,
    c.total_operacion_bob as total_operacion_bob,
    dd.descripcion as dpto_transaccion,
    c.facturado as facturado,
    c.tipo_cambio,
    c.origen,
    c.destino
FROM cambio c
    INNER JOIN operacion o ON c.id_operacion = o.id_operacion
    INNER JOIN usuario u ON c.cod_usuario = u.cod_usuario
    INNER JOIN usuario_financiero uf ON c.id_tipo_doc_identidad = uf.id_tipo_doc_identidad
        AND c.num_doc_identidad = uf.num_doc_identidad AND c.id_expedicion = uf.id_expedicion
    LEFT JOIN tipo_doc_identidad tdi ON c.id_tipo_doc_identidad = tdi.id_tipo_doc_identidad
    LEFT JOIN expedicion e ON c.id_expedicion = e.id_expedicion
    INNER JOIN pais p ON p.id_pais = uf.id_pais
    INNER JOIN actividad_economica ae ON uf.id_actividad_economica = ae.id_actividad_economica
    INNER JOIN agencia aa ON c.id_agencia = aa.id_agencia
    LEFT JOIN pep p2 ON c.num_doc_identidad = p2.num_doc_identidad
        AND p2.id_tipo_doc_identidad = uf.id_tipo_doc_identidad AND p2.id_expedicion = uf.id_expedicion
    INNER JOIN moneda mm ON mm.id_moneda = c.mon_rec
    INNER JOIN moneda mmm ON mmm.id_moneda = c.mon_ent
    INNER JOIN departamento dd ON aa.id_departamento = dd.id_departamento";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.fec_crea_cambio ASC';

        return RawConnection::select($sql, $params);
    }

    // ===================================================================
    //  Giros
    // ===================================================================

    public function giros(?string $desde, ?string $hasta, ?int $agencia): array
    {
        $where  = [];
        $params = [];

        if ($agencia !== null) {
            $where[]            = 'gg.id_agencia_origen = :agencia';
            $params[':agencia'] = $agencia;
        }
        if ($desde !== null && $hasta !== null) {
            $where[]           = 'gg.fec_crea_giro >= :desde AND gg.fec_crea_giro < DATE_ADD(:hasta2, INTERVAL 1 DAY)';
            $params[':desde']  = $desde;
            $params[':hasta2'] = $hasta;
        } elseif ($desde !== null) {
            $where[]           = 'gg.fec_crea_giro >= :desde AND gg.fec_crea_giro < DATE_ADD(:desde2, INTERVAL 1 DAY)';
            $params[':desde']  = $desde;
            $params[':desde2'] = $desde;
        }

        $sql = "SELECT DISTINCT
    id_giro as ID_GIRO,
    gg.codigo AS CODIGO,
    fec_crea_giro AS FECHA,
    u.login_usu AS USUARIO,
    concat(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) as usuario_financiero,
    uf.fecha_nacimiento AS fecha_nacimiento,
    TIMESTAMPDIFF(YEAR, uf.fecha_nacimiento, CURDATE()) AS EDAD,
    tdi.abreviatura as tipo_doc,
    gg.num_doc_identidad AS doc_identidad,
    e.abreviatura as EXTENSION,
    p.descripcion AS DESCRIPCION,
    ae.descripcion as act_economica,
    IF(id_pep>0,'SI','NO') AS PEP,
    p.descripcion as pais_residencia,
    uf.domicilio AS domicilio,
    uf.celular AS celular,
    dd.descripcion as ciudad_agencia,
    aa.descripcion as agencia_origen,
    aaa.descripcion as agencia_destino,
    oo.descripcion as tipo_operacion,
    if(gg.estado = 0, 'PENDIENTE', if(gg.estado=1, 'PAGADO', IF(gg.estado=2,'DEVUELTO','OTRO'))) AS ESTADO,
    mm.abreviatura as mon_rec,
    gg.monto as valor_giro,
    mmm.abreviatura as mon_entregada,
    gg.total_cambio as valor_bob,
    'BOLIVIA' AS pais_transaccion,
    dd.descripcion as dpto_transaccion,
    gg.facturado as FACTURADO,
    gg.tipo_cambio,
    gg.origen,
    gg.destino
FROM giro gg
    INNER JOIN usuario u ON gg.cod_usuario = u.cod_usuario
    INNER JOIN usuario_financiero uf ON gg.id_tipo_doc_identidad = uf.id_tipo_doc_identidad
        AND gg.num_doc_identidad = uf.num_doc_identidad AND gg.id_expedicion = uf.id_expedicion
    LEFT JOIN tipo_doc_identidad tdi ON gg.id_tipo_doc_identidad = tdi.id_tipo_doc_identidad
    LEFT JOIN expedicion e ON gg.id_expedicion = e.id_expedicion
    INNER JOIN pais p ON p.id_pais = uf.id_pais
    INNER JOIN actividad_economica ae ON uf.id_actividad_economica = ae.id_actividad_economica
    INNER JOIN agencia aa ON gg.id_agencia_origen = aa.id_agencia
    INNER JOIN agencia aaa ON gg.id_agencia_destino = aaa.id_agencia
    LEFT JOIN pep p2 ON gg.num_doc_identidad = p2.num_doc_identidad
        AND p2.id_tipo_doc_identidad = uf.id_tipo_doc_identidad AND p2.id_expedicion = uf.id_expedicion
    INNER JOIN operacion oo ON oo.id_operacion = gg.id_operacion
    INNER JOIN moneda mm ON mm.id_moneda = gg.mon_rec
    INNER JOIN moneda mmm ON mmm.id_moneda = gg.mon_ent
    INNER JOIN departamento dd ON aa.id_departamento = dd.id_departamento";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY gg.fec_crea_giro ASC';

        return RawConnection::select($sql, $params);
    }

    // ===================================================================
    //  Remesas
    // ===================================================================

    public function remesas(?string $desde, ?string $hasta, ?int $agencia): array
    {
        $where  = [];
        $params = [];

        if ($agencia !== null) {
            $where[]            = 'gg.id_agencia = :agencia';
            $params[':agencia'] = $agencia;
        }
        if ($desde !== null && $hasta !== null) {
            $where[]           = 'gg.fec_crea_remesa >= :desde AND gg.fec_crea_remesa < DATE_ADD(:hasta2, INTERVAL 1 DAY)';
            $params[':desde']  = $desde;
            $params[':hasta2'] = $hasta;
        } elseif ($desde !== null) {
            $where[]           = 'gg.fec_crea_remesa >= :desde AND gg.fec_crea_remesa < DATE_ADD(:desde2, INTERVAL 1 DAY)';
            $params[':desde']  = $desde;
            $params[':desde2'] = $desde;
        }

        $sql = "SELECT DISTINCT
    id_remesa AS id_remesa,
    gg.codigo AS codigo,
    fec_crea_remesa AS fecha,
    u.login_usu AS usuario,
    1 as nro_oper,
    'CAJAS' as canal_distrib,
    concat(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) as usuario_financiero,
    uf.fecha_nacimiento AS fecha_nacimiento,
    TIMESTAMPDIFF(YEAR, uf.fecha_nacimiento, CURDATE()) AS edad,
    tdi.abreviatura as tipo_doc,
    'BOLIVIANA' as nacionalidad,
    gg.num_doc_identidad as doc_identidad,
    e.abreviatura as EXTENSION,
    p.descripcion AS DESCRIPCION,
    ae.descripcion as act_economica,
    uf.pep as pep,
    p.descripcion as pais_residencia,
    uf.domicilio AS DOMICILIO,
    uf.celular AS CELULAR,
    dd.descripcion as ciudad_agencia,
    aa.descripcion as agencia_origen,
    dest.descripcion as agencia_destino,
    oo.descripcion as tipo_operacion,
    CASE gg.estado
        WHEN 0 THEN 'PENDIENTE'
        WHEN 1 THEN 'PAGADO'
        WHEN 2 THEN 'DEVUELTO'
        WHEN 5 THEN 'ANULADO'
        ELSE 'DESCONOCIDO'
    END AS estado,
    mm.abreviatura as moneda_rec,
    gg.monto as valor,
    mmm.abreviatura as moneda_entr,
    gg.total_cambio as valor_bob,
    'BOLIVIA' AS pais_transaccion,
    dd.descripcion as dpto_transaccion,
    gg.facturado as FACTURADO,
    gg.impresion_pcc08,
    gg.comision_bob,
    gg.tipo_cambio,
    gg.origen,
    gg.destino
FROM remesa gg
    INNER JOIN usuario u ON gg.cod_usuario = u.cod_usuario
    INNER JOIN usuario_financiero uf ON gg.id_tipo_doc_identidad = uf.id_tipo_doc_identidad
        AND gg.num_doc_identidad = uf.num_doc_identidad AND gg.id_expedicion = uf.id_expedicion
    LEFT JOIN tipo_doc_identidad tdi ON gg.id_tipo_doc_identidad = tdi.id_tipo_doc_identidad
    LEFT JOIN expedicion e ON gg.id_expedicion = e.id_expedicion
    INNER JOIN pais p ON p.id_pais = uf.id_pais
    INNER JOIN actividad_economica ae ON uf.id_actividad_economica = ae.id_actividad_economica
    INNER JOIN agencia aa ON gg.id_agencia = aa.id_agencia
    INNER JOIN destino dest ON dest.id_destino = gg.id_destino
    LEFT JOIN pep p2 ON gg.num_doc_identidad = p2.num_doc_identidad
        AND p2.id_tipo_doc_identidad = uf.id_tipo_doc_identidad AND p2.id_expedicion = uf.id_expedicion
    INNER JOIN operacion oo ON oo.id_operacion = gg.id_operacion
    INNER JOIN moneda mm ON mm.id_moneda = gg.mon_rec
    INNER JOIN moneda mmm ON mmm.id_moneda = gg.mon_ent
    INNER JOIN departamento dd ON aa.id_departamento = dd.id_departamento";

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY gg.fec_crea_remesa ASC';

        return RawConnection::select($sql, $params);
    }

    // ===================================================================
    //  Consulta Usuario Financiero (reemplaza vista v_ouf con queries directas)
    // ===================================================================

    public function usuarioFinanciero(
        ?int $tipoDoc, ?string $documento, ?int $expedicion,
        ?string $nombres, ?string $apePat, ?string $apeMat,
        ?int $agencia, ?int $operacion, ?string $desde, ?string $hasta
    ): array {
        $params = [];

        // Filtros sobre usuario_financiero (u) — comunes a todos los sub-queries
        $uf = '';
        if ($tipoDoc !== null) {
            $uf .= ' AND u.id_tipo_doc_identidad = :tipoDoc';
            $params[':tipoDoc'] = $tipoDoc;
        }
        if ($documento !== null && $documento !== '') {
            $uf .= " AND UPPER(u.num_doc_identidad) LIKE UPPER(:doc)";
            $params[':doc'] = "%{$documento}%";
        }
        if ($expedicion !== null) {
            $uf .= ' AND u.id_expedicion = :exp';
            $params[':exp'] = $expedicion;
        }
        if ($nombres !== null && $nombres !== '') {
            $uf .= " AND u.nombres LIKE UPPER(:nom)";
            $params[':nom'] = "%{$nombres}%";
        }
        if ($apePat !== null && $apePat !== '') {
            $uf .= " AND u.ape_pat LIKE UPPER(:pat)";
            $params[':pat'] = "%{$apePat}%";
        }
        if ($apeMat !== null && $apeMat !== '') {
            $uf .= " AND u.ape_mat LIKE UPPER(:mat)";
            $params[':mat'] = "%{$apeMat}%";
        }

        if ($agencia !== null)  $params[':agencia'] = $agencia;
        if ($operacion !== null && $operacion !== -1) $params[':oper'] = $operacion;
        if ($desde !== null && $hasta !== null) {
            $params[':desde']  = $desde;
            $params[':hasta2'] = $hasta;
        } elseif ($desde !== null) {
            $params[':desde']  = $desde;
            $params[':desde2'] = $desde;
        }

        $agF  = fn(string $col) => $agencia !== null ? " AND {$col} = :agencia" : '';
        $opF  = fn(string $col) => $operacion !== null ? " AND {$col} = :oper" : '';
        $dtF  = function (string $col) use ($desde, $hasta) {
            if ($desde !== null && $hasta !== null)
                return " AND {$col} >= :desde AND {$col} < DATE_ADD(:hasta2, INTERVAL 1 DAY)";
            if ($desde !== null)
                return " AND {$col} >= :desde AND {$col} < DATE_ADD(:desde2, INTERVAL 1 DAY)";
            return '';
        };

        $subs = [];

        $pagosOnly = $operacion === -1;

        // 1) CAMBIO (operaciones 1,2)
        if (!$pagosOnly && ($operacion === null || $operacion === 1 || $operacion === 2)) {
            $subs[] = "(SELECT c.id_operacion, c.id_cambio AS id_transaccion, NULL AS codigo,
                c.id_agencia, u.nombres, u.ape_pat, u.ape_mat,
                c.id_tipo_doc_identidad, c.num_doc_identidad, c.id_expedicion,
                c.fec_crea_cambio AS fecha,
                CASE WHEN c.id_operacion=1 THEN c.mon_rec ELSE c.mon_ent END AS moneda,
                CASE WHEN c.id_operacion=1 THEN c.recibido ELSE c.entregado END AS monto,
                CASE WHEN c.id_operacion=1 THEN c.tipo_cambio ELSE c.tipo_cambio_bob END AS tipo_cambio,
                0 AS comision_bob,
                c.total_operacion_bob - c.cambio_bob AS total_operacion_bob,
                f.numero, c.origen, NULL AS destinatario, 'C' AS fuente
            FROM cambio c
            JOIN usuario_financiero u ON u.id_tipo_doc_identidad = c.id_tipo_doc_identidad
                AND u.num_doc_identidad = c.num_doc_identidad AND u.id_expedicion = c.id_expedicion
            JOIN facturado f ON f.id_operacion = c.id_operacion AND f.transaccion = c.id_cambio
                AND f.fecha = CAST(c.fec_crea_cambio AS DATE)
            WHERE c.facturado = 1{$uf}"
                . $agF('c.id_agencia') . $opF('c.id_operacion') . $dtF('c.fec_crea_cambio') . ')';
        }

        // 2) GIRO (operacion 3)
        if (!$pagosOnly && ($operacion === null || $operacion === 3)) {
            $subs[] = "(SELECT g.id_operacion, g.id_giro AS id_transaccion, g.codigo,
                g.id_agencia_origen AS id_agencia, u.nombres, u.ape_pat, u.ape_mat,
                g.id_tipo_doc_identidad, g.num_doc_identidad, g.id_expedicion,
                g.fec_crea_giro AS fecha,
                g.mon_rec AS moneda, g.monto,
                CASE WHEN g.mon_rec<>2 THEN ROUND(g.total_cambio/g.monto,2) ELSE g.tipo_cambio END AS tipo_cambio,
                g.comision_bob, g.total_bob AS total_operacion_bob,
                f.numero, g.origen,
                NULLIF(TRIM(CONCAT_WS(' ', d.nombres, d.ape_pat, d.ape_mat)), '') AS destinatario,
                'G' AS fuente
            FROM giro g
            JOIN usuario_financiero u ON u.id_tipo_doc_identidad = g.id_tipo_doc_identidad
                AND u.num_doc_identidad = g.num_doc_identidad AND u.id_expedicion = g.id_expedicion
            JOIN facturado f ON f.id_operacion = g.id_operacion AND f.transaccion = g.id_giro
                AND f.fecha = CAST(g.fec_crea_giro AS DATE)
            LEFT JOIN destinatario d ON d.id_destinatario = g.id_destinatario
            WHERE g.facturado = 1{$uf}"
                . $agF('g.id_agencia_origen') . $opF('g.id_operacion') . $dtF('g.fec_crea_giro') . ')';
        }

        // 3) REMESA (operacion 4)
        if (!$pagosOnly && ($operacion === null || $operacion === 4 || $operacion === 16)) {
            $subs[] = "(SELECT r.id_operacion, r.id_remesa AS id_transaccion, r.codigo,
                r.id_agencia, u.nombres, u.ape_pat, u.ape_mat,
                r.id_tipo_doc_identidad, r.num_doc_identidad, r.id_expedicion,
                r.fec_crea_remesa AS fecha,
                r.mon_rec AS moneda, r.monto, r.tipo_cambio,
                r.comision_bob, r.total_bob AS total_operacion_bob,
                f.numero, r.origen, NULL AS destinatario, 'R' AS fuente
            FROM remesa r
            JOIN usuario_financiero u ON u.id_tipo_doc_identidad = r.id_tipo_doc_identidad
                AND u.num_doc_identidad = r.num_doc_identidad AND u.id_expedicion = r.id_expedicion
            JOIN facturado f ON f.id_operacion = r.id_operacion AND f.transaccion = r.id_remesa
                AND f.fecha = CAST(r.fec_crea_remesa AS DATE)
            WHERE r.facturado = 1{$uf}"
                . $agF('r.id_agencia') . $opF('r.id_operacion') . $dtF('r.fec_crea_remesa') . ')';
        }

        // 4) PAGO giro (id_operacion = 3)
        if ($pagosOnly || $operacion === null || $operacion === 3) {
            $subs[] = "(SELECT p.id_operacion, p.id_pago AS id_transaccion, p.codigo,
                p.id_agencia, u.nombres, u.ape_pat, u.ape_mat,
                p.id_tipo_doc_identidad, p.num_doc_identidad, p.id_expedicion,
                p.fec_crea_pago AS fecha,
                p.mon_rec AS moneda, p.monto, p.tipo_cambio,
                p.comision_bob, p.total_bob AS total_operacion_bob,
                0 AS numero,
                (SELECT gi.origen FROM giro gi WHERE gi.codigo = p.codigo LIMIT 1) AS origen,
                NULL AS destinatario, 'P' AS fuente
            FROM pago p
            JOIN usuario_financiero u ON u.id_tipo_doc_identidad = p.id_tipo_doc_identidad
                AND u.num_doc_identidad = p.num_doc_identidad AND u.id_expedicion = p.id_expedicion
            WHERE p.id_tipo_pago = 1 AND p.id_operacion = 3{$uf}"
                . $agF('p.id_agencia') . $dtF('p.fec_crea_pago') . ')';
        }

        // 5) PAGO otro (id_operacion <> 3)
        if ($pagosOnly || $operacion === null || ($operacion !== 3)) {
            $w5 = "p.id_tipo_pago = 1 AND p.id_operacion <> 3{$uf}"
                . $agF('p.id_agencia') . $dtF('p.fec_crea_pago');
            if ($operacion !== null && !$pagosOnly) $w5 .= ' AND p.id_operacion = :oper';

            $subs[] = "(SELECT p.id_operacion, p.id_pago AS id_transaccion, p.codigo,
                p.id_agencia, u.nombres, u.ape_pat, u.ape_mat,
                p.id_tipo_doc_identidad, p.num_doc_identidad, p.id_expedicion,
                p.fec_crea_pago AS fecha,
                p.mon_rec AS moneda, p.monto, p.tipo_cambio,
                p.comision_bob, p.total_bob AS total_operacion_bob,
                0 AS numero, 'DECLARADO EN PAIS ORIGEN' AS origen,
                NULL AS destinatario, 'P' AS fuente
            FROM pago p
            JOIN usuario_financiero u ON u.id_tipo_doc_identidad = p.id_tipo_doc_identidad
                AND u.num_doc_identidad = p.num_doc_identidad AND u.id_expedicion = p.id_expedicion
            WHERE {$w5})";
        }

        if (empty($subs)) return [];

        $sql = implode(' UNION ALL ', $subs) . ' ORDER BY fecha DESC LIMIT 5000';
        $rows = RawConnection::select($sql, $params);

        // Enriquecer con datos de Chile para remesas/pagos de remesas
        $codigos = [];
        foreach ($rows as $r) {
            if (!empty($r['codigo']) && in_array((int)$r['id_operacion'], [4, 7, 8, 16])) {
                $codigos[$r['codigo']] = true;
            }
        }

        if (!empty($codigos)) {
            $keys = array_keys($codigos);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $chile = RawConnection::select(
                "SELECT tr.codigo, tr.destinatario, tr.usuario_financiero, tr.doc_des,
                        tr.fec_crea_remesa, o.descripcion AS tipo_chile
                 FROM tmp_reporte_remesa tr
                 INNER JOIN operacion o ON tr.id_operacion = o.id_operacion
                 WHERE tr.codigo IN ({$placeholders})",
                array_values($keys),
                'chile'
            );

            $chileMap = [];
            foreach ($chile as $c) {
                $chileMap[$c['codigo']] = $c;
            }

            foreach ($rows as &$r) {
                if (!empty($r['codigo']) && isset($chileMap[$r['codigo']])) {
                    $ch = $chileMap[$r['codigo']];
                    if ($r['fuente'] === 'P') {
                        $r['destinatario'] = $ch['usuario_financiero'];
                    } else {
                        $r['destinatario'] = $ch['destinatario'];
                    }
                    $r['chile_ordenante'] = $ch['usuario_financiero'];
                    $r['chile_destinatario'] = $ch['destinatario'];
                    $r['chile_doc_des'] = $ch['doc_des'];
                    $r['chile_fec_envio'] = $ch['fec_crea_remesa'];
                    $r['chile_tipo'] = $ch['tipo_chile'];
                }
            }
            unset($r);
        }

        // Resolver tipo de operación y agencia
        $operaciones = RawConnection::select("SELECT id_operacion, descripcion FROM operacion");
        $opMap = [];
        foreach ($operaciones as $o) $opMap[$o['id_operacion']] = $o['descripcion'];

        $agencias = RawConnection::select("SELECT id_agencia, descripcion FROM agencia WHERE id_agencia > 0");
        $agMap = [];
        foreach ($agencias as $a) $agMap[$a['id_agencia']] = $a['descripcion'];

        foreach ($rows as &$r) {
            $r['tipo_operacion'] = $opMap[$r['id_operacion']] ?? $r['id_operacion'];
            $r['agencia'] = $agMap[$r['id_agencia']] ?? $r['id_agencia'];
        }
        unset($r);

        return $rows;
    }

    // ===================================================================
    //  CSV Export
    // ===================================================================

    public function export(string $report, ?string $desde, ?string $hasta, ?int $agencia): array
    {
        $headers = $this->csvHeaders($report);
        $rows    = $this->{$report}($desde, $hasta, $agencia);

        $lines = [];
        $lines[] = implode('|', $headers);

        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $col) {
                $values[] = $row[$col] ?? '';
            }
            $lines[] = implode('|', $values);
        }

        $csv = implode("\r\n", $lines) . "\r\n";

        return [
            'binary'   => $csv,
            'filename' => "reporte_{$report}_uif.csv",
        ];
    }

    private function csvHeaders(string $report): array
    {
        $map = [
            'cambios' => [
                'id_cambio', 'id_operacion', 'tipo_operacion', 'fecha', 'usuario',
                'usu_financiero', 'fec_nacimiento', 'edad', 'tipo_doc', 'doc_identidad',
                'exp', 'descripcion', 'act_econo', 'PEP', 'pais_residencia',
                'domicilio', 'celular', 'ciudad_agencia', 'agencia', 'mon_rec',
                'monto_recibido', 'TC', 'mon_entregada', 'monto_entregado',
                'pais_transaccion', 'total_operacion_bob', 'dpto_transaccion',
                'facturado', 'tipo_cambio', 'origen', 'destino',
            ],
            'giros' => [
                'ID_GIRO', 'CODIGO', 'FECHA', 'USUARIO', 'usuario_financiero',
                'fecha_nacimiento', 'EDAD', 'tipo_doc', 'doc_identidad', 'EXTENSION',
                'DESCRIPCION', 'act_economica', 'PEP', 'pais_residencia',
                'domicilio', 'celular', 'ciudad_agencia', 'agencia_origen',
                'agencia_destino', 'tipo_operacion', 'ESTADO', 'mon_rec',
                'valor_giro', 'mon_entregada', 'valor_bob', 'pais_transaccion',
                'dpto_transaccion', 'FACTURADO', 'tipo_cambio', 'origen', 'destino',
            ],
            'remesas' => [
                'id_remesa', 'codigo', 'fecha', 'usuario', 'nro_oper',
                'canal_distrib', 'usuario_financiero', 'fecha_nacimiento', 'edad',
                'tipo_doc', 'nacionalidad', 'doc_identidad', 'EXTENSION',
                'DESCRIPCION', 'act_economica', 'pep', 'pais_residencia',
                'DOMICILIO', 'CELULAR', 'ciudad_agencia', 'agencia_origen',
                'agencia_destino', 'tipo_operacion', 'estado', 'moneda_rec',
                'valor', 'moneda_entr', 'valor_bob', 'pais_transaccion',
                'dpto_transaccion', 'FACTURADO', 'impresion_pcc08', 'comision_bob',
                'tipo_cambio', 'origen', 'destino',
            ],
        ];

        return $map[$report];
    }
}
