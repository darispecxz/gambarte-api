<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;
use App\Modules\Facturacion\FacturacionService;

class CajeroRemesaService
{
    private const CHILE_API_TIMEOUT = 5;
    private const CACHE_TTL_HOURS = 4;

    private const IVA_PORCENTAJE = 13;
    private const IT_PORCENTAJE = 3;
    private const ITF_PORCENTAJE = 0.3;

    // Slugs de parametros UIF para remesas (tabla `parametros`)
    private const PARAM_PCC08_UNICA = 'monto_remesa_pcc08_operacion_unica';
    private const PARAM_PCC08_MULTIPLES = 'monto_remesa_pcc08_operaciones_multiples';
    private const PARAM_PCC08_DIAS = 'dias_control_multiples_remesa_pcc08';
    private const PARAM_PCC08_NUM_OPS = 'numero_operaciones_multiples_remesa_pcc08';
    private const PARAM_FORMULARIO_UNICA = 'monto_remesa_formulario_operacion_unica';
    private const PARAM_FORMULARIO_MULTIPLES = 'monto_remesa_formulario_operaciones_multiples';
    private const PARAM_FORMULARIO_DIAS = 'dias_control_multiples_remesa_formulario';
    private const PARAM_FORMULARIO_NUM_OPS = 'numero_operaciones_multiples_remesa_formulario';

    // Defaults UIF (fallback si no existen en DB)
    private const DEFAULT_PCC08_UNICA = 1000;
    private const DEFAULT_PCC08_MULTIPLES = 2000;
    private const DEFAULT_PCC08_DIAS = 30;
    private const DEFAULT_PCC08_NUM_OPS = 3;
    private const DEFAULT_FORMULARIO_UNICA = 500;
    private const DEFAULT_FORMULARIO_MULTIPLES = 1000;
    private const DEFAULT_FORMULARIO_DIAS = 30;
    private const DEFAULT_FORMULARIO_NUM_OPS = 3;

    /** Chile queda en solo lectura mientras esta bandera esté activa. */
    private function chileSyncPausado(): bool
    {
        return filter_var(env('CHILE_SYNC_PAUSADO', true), FILTER_VALIDATE_BOOLEAN);
    }

    private const PARAM_MONTO_MAX_REMESA = 'monto_maximo_remesa';
    private const PARAM_COTIZACION_VALIDEZ_HORAS = 'cotizacion_remesa_validez_horas';
    private const DEFAULT_COTIZACION_VALIDEZ_HORAS = 4;
    private const DEFAULT_MONTO_MAX_REMESA = 50000;

    private const SUBCUENTA_REMESA_POR_PAGAR = '24401202';
    private const SUBCUENTA_REMESA_INT_ME = '24401202';
    private const SUBCUENTA_CAJA_BOB = '11101101';
    private const SUBCUENTA_CAJA_USD = '11101201';
    private const SUBCUENTA_COMI_GIRO = '54105104';
    private const SUBCUENTA_DEBITO_FISCAL_IVA = '24204102';
    private const SUBCUENTA_TRANSACCION = '45502101';
    private const SUBCUENTA_IMPUESTO_TRANSACCIONES = '24204101';
    private const SUBCUENTA_ENTRADA_CAJA_COMISION = '11101101';
    private const SUBCUENTA_RETENCION_ITF = '24203103';
    private const SUBCUENTA_GANANCIA = '54201201';
    private const SUBCUENTA_CARG_CORRESPONSALIA = '24299202';
    private const SUBCUENTA_PERDIDA = '44502201';

    public function destinos(): array
    {
        return DB::select("
            SELECT id_destino, descripcion, estado
            FROM destino
            WHERE estado = 'A'
            ORDER BY descripcion
        ");
    }

    public function bancosChile(): array
    {
        return DB::select("
            SELECT id, nombre, nombre_corto
            FROM pos_banco_chile
            WHERE estado = 'A'
            ORDER BY orden, nombre
        ");
    }

    public function formasPagoChile(): array
    {
        return DB::select("
            SELECT id, descripcion, requiere_cuenta
            FROM pos_forma_pago_chile
            WHERE estado = 'A'
            ORDER BY id
        ");
    }

    public function crearCotizacionRemesa(
        int $codUsuario, int $idAgencia, int $idMoneda, int $idDestino,
        float $monto, int $tipoComision, int $incluirComision, array $snapshotComision
    ): array {
        $validezHoras = (int) ($this->getParametro(self::PARAM_COTIZACION_VALIDEZ_HORAS) ?: self::DEFAULT_COTIZACION_VALIDEZ_HORAS);
        $codigo = strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8));

        DB::execute("
            INSERT INTO pos_cotizacion_remesa
                (codigo, cod_usuario, id_agencia, id_moneda, id_destino, monto,
                 tipo_comision, incluir_comision, snapshot_comision, valido_hasta)
            VALUES (:codigo, :usr, :ag, :mon, :dest, :monto,
                    :tc, :ic, :snap, DATE_ADD(NOW(), INTERVAL :horas HOUR))
        ", [
            ':codigo' => $codigo,
            ':usr'    => $codUsuario,
            ':ag'     => $idAgencia,
            ':mon'    => $idMoneda,
            ':dest'   => $idDestino,
            ':monto'  => $monto,
            ':tc'     => $tipoComision,
            ':ic'     => $incluirComision,
            ':snap'   => json_encode($snapshotComision, JSON_UNESCAPED_UNICODE),
            ':horas'  => $validezHoras,
        ]);

        return [
            'codigo'       => $codigo,
            'valido_hasta' => date('Y-m-d H:i:s', strtotime("+{$validezHoras} hours")),
            'horas'        => $validezHoras,
        ];
    }

    public function buscarCotizacionRemesa(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));
        $row = DB::selectOne("
            SELECT * FROM pos_cotizacion_remesa
            WHERE codigo = :c LIMIT 1
        ", [':c' => $codigo]);

        if (!$row) {
            return ['success' => false, 'message' => 'Cotización no encontrada.'];
        }
        if ($row['estado'] === 'aplicada') {
            return ['success' => false, 'message' => 'Esta cotización ya fue utilizada.'];
        }
        if ($row['estado'] === 'expirada' || strtotime($row['valido_hasta']) < time()) {
            if ($row['estado'] !== 'expirada') {
                DB::execute("UPDATE pos_cotizacion_remesa SET estado = 'expirada' WHERE id = :id", [':id' => $row['id']]);
            }
            return ['success' => false, 'message' => 'Esta cotización ha expirado.'];
        }

        $snapshot = json_decode($row['snapshot_comision'], true);

        return [
            'success'   => true,
            'cotizacion' => [
                'id'               => (int) $row['id'],
                'codigo'           => $row['codigo'],
                'id_moneda'        => (int) $row['id_moneda'],
                'id_destino'       => (int) $row['id_destino'],
                'monto'            => (float) $row['monto'],
                'tipo_comision'    => (int) $row['tipo_comision'],
                'incluir_comision' => (int) $row['incluir_comision'],
                'snapshot'         => $snapshot,
                'valido_hasta'     => $row['valido_hasta'],
            ],
        ];
    }

    public function marcarCotizacionAplicada(int $idCotizacion, int $idRemesa): void
    {
        DB::execute("
            UPDATE pos_cotizacion_remesa
            SET estado = 'aplicada', aplicada_en = :rem
            WHERE id = :id AND estado = 'vigente'
        ", [':id' => $idCotizacion, ':rem' => $idRemesa]);
    }

    public function formasPagoCobro(): array
    {
        return DB::select("
            SELECT id, descripcion, codigo
            FROM pos_forma_pago_cobro
            WHERE estado = 'A'
            ORDER BY id
        ");
    }

    public function buscarDestinatario(string $documento, int $tipoDoc, int $expedicion): ?array
    {
        return DB::selectOne("
            SELECT d.*
            FROM destinatario d
            WHERE d.num_doc_identidad_d = :doc
              AND d.id_tipo_doc_identidad_d = :tipo
              AND d.id_expedicion_d = :exp
            LIMIT 1
        ", [
            ':doc'  => mb_strtoupper(trim($documento)),
            ':tipo' => $tipoDoc,
            ':exp'  => $expedicion,
        ]);
    }

    public function destinatariosPorRemitente(string $documento, int $tipoDoc, int $expedicion): array
    {
        return DB::select("
            SELECT MIN(d.id_destinatario) AS id_destinatario,
                   d.nombres, d.ape_pat, d.ape_mat,
                   d.id_tipo_doc_identidad_d, d.num_doc_identidad_d,
                   d.id_expedicion_d, d.domicilio, d.tel_dom,
                   d.tel_trab, d.celular, d.cuenta, d.id_banco_chile
            FROM destinatario d
            INNER JOIN remesa r ON d.id_destinatario = r.id_destinatario
            WHERE r.id_tipo_doc_identidad = :tipo
              AND r.num_doc_identidad = :doc
              AND r.id_expedicion = :exp
            GROUP BY d.nombres, d.ape_pat, d.ape_mat,
                     d.id_tipo_doc_identidad_d, d.num_doc_identidad_d,
                     d.id_expedicion_d, d.domicilio, d.tel_dom,
                     d.tel_trab, d.celular, d.cuenta, d.id_banco_chile
            ORDER BY d.nombres, d.ape_pat, d.ape_mat
        ", [
            ':doc'  => mb_strtoupper(trim($documento)),
            ':tipo' => $tipoDoc,
            ':exp'  => $expedicion,
        ]);
    }

    public function obtenerTcOficialVenta(): array
    {
        $tc = $this->getCotizacionOficialVenta(1);
        return [
            'tc_oficial_venta' => $tc,
            'moneda' => 'USD',
        ];
    }

    // ─── Cotización Chile con cache ───────────────────────────────────

    public function obtenerCotizacionChile(int $idDestino): array
    {
        $chileApiUrl = env('CHILE_API_URL', 'https://cgr.gambarte.cl');

        try {
            $comando = base64_encode("1|{$idDestino}");
            $url = "{$chileApiUrl}/ws/ws.php?t={$comando}";

            $ctx = stream_context_create([
                'http' => [
                    'timeout' => self::CHILE_API_TIMEOUT,
                    'method'  => 'GET',
                ],
            ]);

            $response = @file_get_contents($url, false, $ctx);

            if ($response !== false) {
                $json = json_decode($response, true);
                if (!empty($json[0]['valor'])) {
                    $valor = (float) base64_decode($json[0]['valor']);
                    if ($valor > 0) {
                        $this->guardarCacheChile($idDestino, $valor);
                        return ['success' => true, 'valor' => $valor, 'source' => 'api'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to cache
        }

        $cached = $this->obtenerCacheChile($idDestino);
        if ($cached) {
            return ['success' => true, 'valor' => (float) $cached['valor'], 'source' => 'cache'];
        }

        return ['success' => false, 'valor' => 0, 'source' => 'none'];
    }

    private function guardarCacheChile(int $idDestino, float $valor): void
    {
        DB::execute("
            INSERT INTO cotizacion_chile_cache (id_destino, valor, created_at)
            VALUES (:dest, :val, NOW())
        ", [':dest' => $idDestino, ':val' => $valor]);
    }

    private function obtenerCacheChile(int $idDestino): ?array
    {
        return DB::selectOne("
            SELECT valor, created_at
            FROM cotizacion_chile_cache
            WHERE id_destino = :dest
              AND created_at >= DATE_SUB(NOW(), INTERVAL :ttl HOUR)
            ORDER BY created_at DESC
            LIMIT 1
        ", [':dest' => $idDestino, ':ttl' => self::CACHE_TTL_HOURS]);
    }

    public function listarComisionesPreferenciales(): array
    {
        return DB::select("
            SELECT id_comision_preferencial AS id,
                   CONCAT('Preferencial ', porcentaje_comision_preferencial, '%') AS descripcion
            FROM comision_preferencial
            WHERE id_operacion IN (4, 16) AND estado = 'A'
            ORDER BY porcentaje_comision_preferencial
        ");
    }

    // ─── Verificación UIF ──────────────────────────────────────────────

    public function verificarUifRemesa(
        int $idMoneda,
        float $monto,
        string $documento,
        int $tipoDoc,
        int $expedicion
    ): array {
        $params = $this->getParametrosUifRemesa();
        $tcOficial = $this->getTipoCambioOficialRemesa($idMoneda);

        // `valor_me` expresa el equivalente en USD por unidad de la moneda
        // recibida; para dolarizar el monto corresponde multiplicar.
        $dolarizado = $tcOficial > 0 ? round($monto * $tcOficial, 2) : 0;

        $requiereFormulario = false;
        $requierePcc08 = false;

        $doc = mb_strtoupper(trim($documento));

        $opsPrevFormulario = $this->getRemesasPrevias($doc, $tipoDoc, $expedicion, (int) $params['dias_formulario']);
        $opsPrevPcc08 = $this->getRemesasPrevias($doc, $tipoDoc, $expedicion, (int) $params['dias_pcc08']);

        // Formulario complemento: operación única o acumulado múltiples
        $uf = $this->buscarUsuarioFinanciero($doc, $tipoDoc, $expedicion);
        $nivelImpresion = $uf ? (int) ($uf['nivel_impresion_formulario'] ?? 1) : 0;

        if (empty($uf) || $nivelImpresion < 2) {
            if ($dolarizado >= (float) $params['unica_formulario']) {
                $requiereFormulario = true;
            } elseif (
                ($opsPrevFormulario['cantidad'] + 1) >= (int) $params['num_ops_formulario']
                && ($opsPrevFormulario['sumatoria'] + $dolarizado) >= (float) $params['multiples_formulario']
            ) {
                $requiereFormulario = true;
            }
        }

        // PCC-08: operación única o acumulado múltiples
        if ($dolarizado >= (float) $params['unica_pcc08']) {
            $requierePcc08 = true;
        } else {
            $hayPcc08EnPeriodo = $this->hayPcc08EnPeriodo($doc, $tipoDoc, $expedicion, (int) $params['dias_pcc08']);

            if (!$hayPcc08EnPeriodo) {
                if (
                    ($opsPrevPcc08['cantidad'] + 1) >= (int) $params['num_ops_pcc08']
                    && ($opsPrevPcc08['sumatoria'] + $dolarizado) >= (float) $params['multiples_pcc08']
                ) {
                    $requierePcc08 = true;
                }
            } else {
                $ultimasOps = $this->sumaUltimasRemesas($doc, $tipoDoc, $expedicion, (int) $params['dias_pcc08']);
                if (($ultimasOps + $dolarizado) >= (float) $params['multiples_pcc08']) {
                    $requierePcc08 = true;
                }
            }
        }

        // Listas de control
        $alertas = $this->verificarListasControl($doc, $tipoDoc, $expedicion);

        // ── Usuario habitual (Art. 37) ────────────────────────────────────
        $paramsHabitual = $this->getParametrosHabitual();
        $opsHabitual = $this->getRemesasPrevias($doc, $tipoDoc, $expedicion, $paramsHabitual['dias']);
        $esUsuarioHabitual = ($opsHabitual['cantidad'] + 1) >= $paramsHabitual['ops'];
        if ($esUsuarioHabitual && !$requiereFormulario) {
            $requiereFormulario = true;
        }

        // ── Señales de alerta ─────────────────────────────────────────────
        $senales = [];
        $umbralPcc08 = (float) $params['unica_pcc08'];
        $margenStructuring = $umbralPcc08 * 0.1;
        if ($dolarizado >= ($umbralPcc08 - $margenStructuring) && $dolarizado < $umbralPcc08) {
            $senales[] = [
                'codigo' => 'STRUCTURING',
                'mensaje' => 'Operación cercana al umbral PCC-08 (posible estructuración)',
                'nivel' => 'medio',
            ];
        }
        $ops7 = $this->getRemesasPrevias($doc, $tipoDoc, $expedicion, 7);
        if ($ops7['cantidad'] >= 5) {
            $senales[] = [
                'codigo' => 'FREQ_SPIKE',
                'mensaje' => "Alta frecuencia: {$ops7['cantidad']} remesas en 7 días",
                'nivel' => 'alto',
            ];
        }

        // ── Bloqueo por DD incompleta (Art. 51) ───────────────────────────
        $ddIncompleta = false;
        $ddBloqueado = false;
        if (!empty($alertas)) {
            $tieneComplemento = (bool) DB::scalar("
                SELECT COUNT(*) FROM usuario_financiero_complemento ufc
                JOIN usuario_financiero uf ON uf.id = ufc.usuario_financiero_id
                WHERE uf.num_doc_identidad = :doc
                  AND uf.id_tipo_doc_identidad = :tipo
                  AND uf.id_expedicion = :exp
            ", [':doc' => $doc, ':tipo' => $tipoDoc, ':exp' => $expedicion]);

            if (!$tieneComplemento) {
                $ddIncompleta = true;
                $tieneAlertaCritica = false;
                foreach ($alertas as $a) {
                    if (in_array($a['nivel'], ['critico', 'alto'])) {
                        $tieneAlertaCritica = true;
                        break;
                    }
                }
                $ddBloqueado = $tieneAlertaCritica;
            }
        }

        return [
            'dolarizado'           => $dolarizado,
            'tipo_cambio_oficial'  => $tcOficial,
            'requiere_formulario'  => $requiereFormulario,
            'requiere_pcc08'       => $requierePcc08,
            'operaciones_previas'  => [
                'cantidad'         => $opsPrevPcc08['cantidad'],
                'total_dolarizado' => $opsPrevPcc08['sumatoria'],
                'dias_ventana'     => (int) $params['dias_pcc08'],
            ],
            'umbrales' => [
                'formulario_unica'    => (float) $params['unica_formulario'],
                'pcc08_unica'         => (float) $params['unica_pcc08'],
                'formulario_multiples' => (float) $params['multiples_formulario'],
                'pcc08_multiples'     => (float) $params['multiples_pcc08'],
            ],
            'alertas'              => $alertas,
            'usuario_habitual'     => $esUsuarioHabitual,
            'senales_alerta'       => $senales,
            'dd_incompleta'        => $ddIncompleta,
            'dd_bloqueado'         => $ddBloqueado,
        ];
    }

    private function verificarListasControl(string $doc, int $tipoDoc, int $expedicion): array
    {
        $alertas = [];

        // 1. PEP — Persona Políticamente Expuesta
        $pep = DB::selectOne("
            SELECT p.num_doc_identidad, p.nombres, p.ape_pat, p.cargo, p.entidad
            FROM pep p
            WHERE p.num_doc_identidad = :doc AND p.estado = 'A'
            LIMIT 1
        ", [':doc' => $doc]);

        if ($pep) {
            $alertas[] = [
                'tipo'  => 'PEP',
                'nivel' => 'alto',
                'mensaje' => 'Persona Políticamente Expuesta',
                'detalle' => trim(($pep['cargo'] ?? '') . ' - ' . ($pep['entidad'] ?? ''), ' -'),
            ];
        }

        // 2. Personas de riesgo (listas OFAC, ONU, etc.)
        $riesgo = DB::selectOne("
            SELECT pr.numero_documento, pr.primer_nombre, pr.apellido_paterno,
                   pr.cargo, pr.entidad, pr.justificacion
            FROM personas_riesgos pr
            WHERE pr.numero_documento = :doc
            LIMIT 1
        ", [':doc' => $doc]);

        if ($riesgo) {
            $alertas[] = [
                'tipo'  => 'LISTA_RIESGO',
                'nivel' => 'critico',
                'mensaje' => 'Persona en lista de riesgo/control',
                'detalle' => trim(($riesgo['cargo'] ?? '') . ' — ' . ($riesgo['justificacion'] ?? ''), ' —'),
            ];
        }

        // 3. PEP registrado en usuario_financiero (determinado por formulario complemento)
        $ufPep = DB::selectOne("
            SELECT pep, stipo_pep
            FROM usuario_financiero
            WHERE num_doc_identidad = :doc
              AND id_tipo_doc_identidad = :tipo
              AND id_expedicion = :exp
              AND pep = 'S'
            LIMIT 1
        ", [':doc' => $doc, ':tipo' => $tipoDoc, ':exp' => $expedicion]);

        if ($ufPep) {
            $subtipo = $ufPep['stipo_pep'] ?? '';
            $label = $subtipo === 'NAL' ? 'Nacional' : ($subtipo === 'EXT' ? 'Extranjero' : $subtipo);
            $alertas[] = [
                'tipo'  => 'PEP_REGISTRADO',
                'nivel' => 'alto',
                'mensaje' => "PEP registrado en sistema ({$label})",
                'detalle' => '',
            ];
        }

        return $alertas;
    }

    private function getParametrosHabitual(): array
    {
        $slugs = ['usuario_habitual_ops', 'usuario_habitual_dias'];
        $rows = DB::select("
            SELECT slug, valor FROM parametros
            WHERE estado = 'A' AND slug IN ('" . implode("','", $slugs) . "')
        ");
        $map = [];
        foreach ($rows as $r) {
            $map[$r['slug']] = $r['valor'];
        }
        return [
            'ops'  => (int) ($map['usuario_habitual_ops']  ?? 3),
            'dias' => (int) ($map['usuario_habitual_dias'] ?? 30),
        ];
    }

    private function getParametrosUifRemesa(): array
    {
        $slugs = [
            self::PARAM_PCC08_UNICA,
            self::PARAM_PCC08_MULTIPLES,
            self::PARAM_PCC08_DIAS,
            self::PARAM_PCC08_NUM_OPS,
            self::PARAM_FORMULARIO_UNICA,
            self::PARAM_FORMULARIO_MULTIPLES,
            self::PARAM_FORMULARIO_DIAS,
            self::PARAM_FORMULARIO_NUM_OPS,
        ];

        $rows = DB::select("
            SELECT slug, valor FROM parametros
            WHERE estado = 'A' AND slug IN ('" . implode("','", $slugs) . "')
        ");

        $map = [];
        foreach ($rows as $r) {
            $map[$r['slug']] = $r['valor'];
        }

        return [
            'unica_pcc08'          => $map[self::PARAM_PCC08_UNICA] ?? self::DEFAULT_PCC08_UNICA,
            'multiples_pcc08'      => $map[self::PARAM_PCC08_MULTIPLES] ?? self::DEFAULT_PCC08_MULTIPLES,
            'dias_pcc08'           => $map[self::PARAM_PCC08_DIAS] ?? self::DEFAULT_PCC08_DIAS,
            'num_ops_pcc08'        => $map[self::PARAM_PCC08_NUM_OPS] ?? self::DEFAULT_PCC08_NUM_OPS,
            'unica_formulario'     => $map[self::PARAM_FORMULARIO_UNICA] ?? self::DEFAULT_FORMULARIO_UNICA,
            'multiples_formulario' => $map[self::PARAM_FORMULARIO_MULTIPLES] ?? self::DEFAULT_FORMULARIO_MULTIPLES,
            'dias_formulario'      => $map[self::PARAM_FORMULARIO_DIAS] ?? self::DEFAULT_FORMULARIO_DIAS,
            'num_ops_formulario'   => $map[self::PARAM_FORMULARIO_NUM_OPS] ?? self::DEFAULT_FORMULARIO_NUM_OPS,
        ];
    }

    private function getTipoCambioOficialRemesa(int $idMoneda): float
    {
        if ($idMoneda === 1) return 1.0; // USD

        $val = DB::scalar("
            SELECT co.valor_me
            FROM cotizacion_oficial co
            WHERE co.id_moneda = :mon AND co.id_operacion = 1 AND co.estado = 'A'
            ORDER BY co.fec_crea_cotizacion_oficial DESC
            LIMIT 1
        ", [':mon' => $idMoneda]);

        return $val ? (float) $val : 0.0;
    }

    private function buscarUsuarioFinanciero(string $doc, int $tipoDoc, int $expedicion): ?array
    {
        return DB::selectOne("
            SELECT nivel_impresion_formulario
            FROM usuario_financiero
            WHERE num_doc_identidad = :doc
              AND id_tipo_doc_identidad = :tipo
              AND id_expedicion = :exp
            LIMIT 1
        ", [':doc' => $doc, ':tipo' => $tipoDoc, ':exp' => $expedicion]);
    }

    private function getRemesasPrevias(string $doc, int $tipoDoc, int $expedicion, int $dias): array
    {
        $row = DB::selectOne("
            SELECT COUNT(*) AS cantidad,
                       COALESCE(SUM(
                           ROUND(r.monto * GREATEST(
                           (SELECT co.valor_me FROM cotizacion_oficial co
                            WHERE co.id_moneda = r.mon_rec AND co.id_operacion = 1 AND co.estado = 'A'
                            ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1),
                               0
                       ), 2)
                   ), 0) AS sumatoria
            FROM remesa r
            WHERE r.num_doc_identidad = :doc
              AND r.id_tipo_doc_identidad = :tipo
              AND r.id_expedicion = :exp
              AND r.fec_crea_remesa >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
              AND r.estado IN (0, 1)
        ", [
            ':doc'  => $doc,
            ':tipo' => $tipoDoc,
            ':exp'  => $expedicion,
            ':dias' => $dias,
        ]);

        return [
            'cantidad'  => (int) ($row['cantidad'] ?? 0),
            'sumatoria' => (float) ($row['sumatoria'] ?? 0),
        ];
    }

    private function hayPcc08EnPeriodo(string $doc, int $tipoDoc, int $expedicion, int $dias): bool
    {
        $count = (int) DB::scalar("
            SELECT COUNT(*)
            FROM remesa
            WHERE num_doc_identidad = :doc
              AND id_tipo_doc_identidad = :tipo
              AND id_expedicion = :exp
              AND fec_crea_remesa >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
              AND impresion_pcc08 = 'S'
              AND estado IN (0, 1)
        ", [
            ':doc'  => $doc,
            ':tipo' => $tipoDoc,
            ':exp'  => $expedicion,
            ':dias' => $dias,
        ]);

        return $count > 0;
    }

    private function sumaUltimasRemesas(string $doc, int $tipoDoc, int $expedicion, int $dias): float
    {
        $val = DB::scalar("
            SELECT COALESCE(SUM(sub.dolarizado), 0)
            FROM (
                SELECT
                    ROUND(r.monto * GREATEST(
                        (SELECT co.valor_me FROM cotizacion_oficial co
                         WHERE co.id_moneda = r.mon_rec AND co.id_operacion = 1 AND co.estado = 'A'
                         ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1),
                        0
                    ), 2) AS dolarizado
                FROM remesa r
                WHERE r.num_doc_identidad = :doc
                  AND r.id_tipo_doc_identidad = :tipo
                  AND r.id_expedicion = :exp
                  AND r.fec_crea_remesa >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
                  AND r.estado IN (0, 1)
                ORDER BY r.fec_crea_remesa DESC
                LIMIT 3
            ) sub
        ", [
            ':doc'  => $doc,
            ':tipo' => $tipoDoc,
            ':exp'  => $expedicion,
            ':dias' => $dias,
        ]);

        return (float) ($val ?? 0);
    }

    // ─── Cálculo de comisión ──────────────────────────────────────────

    /**
     * Recalcula la comisión en el servidor y sustituye la que envía el navegador.
     *
     * ── Por qué ──────────────────────────────────────────────────────────────
     * `registrarRemesa()` persistía el snapshot de comisión tal cual llegaba del
     * POS (`comision.bob.comision_total`, `total_operacion`, IVA, ITF, reparto
     * con el corresponsal…). Esos importes mueven efectivo de caja, se facturan y
     * generan asiento, así que un cliente manipulado podía fijarlos a voluntad.
     *
     * ── Por qué recalcular y no solo cotejar ─────────────────────────────────
     * El valor autoritativo es el que calcula `calcularComision()`, la MISMA
     * función que alimenta el endpoint `/cajero/calcular-comision` que ya usa el
     * POS para mostrar la comisión al cajero. Al compartir la función, la UI y el
     * registro no pueden divergir.
     *
     * Se comprobó contra el histórico real: `calcularComision()` reproduce el
     * 100% de las remesas de 2026 (las vigentes). Las de 2024 y parte de 2025
     * responden a tarifas anteriores ya cambiadas, por eso NO se valida contra
     * operaciones ya persistidas — solo contra las nuevas.
     *
     * Se rechaza si lo declarado difiere de forma material. Tolerancia relativa
     * del 1% con suelo absoluto de 0.50 para absorber redondeos, en lugar de
     * aceptar silenciosamente la corrección: una divergencia real debe aflorar
     * como error, no como un importe distinto al pactado con el cliente.
     *
     * @throws \RuntimeException si la comisión declarada no cuadra con la calculada.
     */
    private function validarComisionAutoritativa(
        array $comisionData,
        int $idAgencia,
        int $idOperacion,
        int $monRec,
        float $monto,
        array $operacion
    ): array {
        if (empty($comisionData)) {
            throw new \RuntimeException('Falta el detalle de comisión de la operación.');
        }

        // `tipo_comision` no lo envía el POS en el registro, pero:
        //  - el endpoint /calcular-comision ya lo usa, y
        //  - el snapshot incluye `is_preferencial`.
        // Se toma del request si viene y, si no, se deduce del snapshot.
        $tipoComision = (int) ($operacion['tipo_comision'] ?? -1);
        if ($tipoComision < 0) {
            $tipoComision = !empty($comisionData['is_preferencial']) ? 1 : 0;
        }

        // `incluir_comision` sí viaja como `pago_comision`.
        $incluirComision = (int) ($operacion['pago_comision'] ?? 0) === 1 ? 1 : 0;

        // La comisión de remesa se tarifa y se cobra en BOB, que es lo que
        // factura y lo que mueve la caja en bolivianos. La moneda del envío
        // (`mon_rec`) no es la base de la comisión: verificado contra el
        // histórico, donde las remesas en USD se tarifan con la tabla en BOB.
        $idMoneda = 2;

        // Base del cálculo, verificada contra las 17 remesas de 2026 (100%):
        //  · `incluir_comision = 1` → el monto pactado YA lleva la comisión
        //    incorporada, así que la base es el total de la operación.
        //  · `incluir_comision = 0` → la base es el monto enviado.
        $montoEnviadoX   = (float) ($comisionData['monto_enviado_x'] ?? 0);
        $montoEnviadoBob = (float) ($comisionData['monto_enviado_bob'] ?? 0);
        $metodosPago = $operacion['metodos_pago'] ?? [];
        $totalCobrado = 0.0;
        foreach ($metodosPago as $metodo) {
            $totalCobrado += (float) ($metodo['monto'] ?? 0);
        }

        if ($incluirComision === 1) {
            // La UI envía el monto neto en `operacion.monto`, pero el total
            // cobrado se encuentra en los métodos de pago. La comisión debe
            // calcularse sobre ese total bruto, sin volver a sumarla.
            $base = $totalCobrado > 0
                ? $totalCobrado
                : max($monto, (float) ($comisionData['bob']['total_operacion'] ?? 0));
        } else {
            $base = $montoEnviadoX > 0 ? $montoEnviadoX : $monto;
        }
        if ($base <= 0) {
            $base = $monto;
        }

        $idDestino = (int) ($operacion['id_destino'] ?? 1);

        try {
            $calculado = $this->calcularComision(
                $tipoComision, $idAgencia, $idMoneda, $idOperacion, $base, $idDestino, $incluirComision
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('No se pudo recalcular la comisión de la operación: ' . $e->getMessage());
        }

        $autoritativo = $calculado['bob'] ?? [];
        $declarado    = $comisionData['bob'] ?? [];

        foreach (['comision_total', 'total_operacion'] as $clave) {
            $calc = (float) ($autoritativo[$clave] ?? 0);
            $decl = (float) ($declarado[$clave] ?? 0);

            $tolerancia = max(0.50, abs($calc) * 0.01);
            if (abs($calc - $decl) > $tolerancia) {
                $etiqueta = $clave === 'comision_total' ? 'comisión' : 'total';
                throw new \RuntimeException(
                    "La {$etiqueta} de la operación no coincide con la calculada por el sistema "
                    . '(declarada: ' . number_format($decl, 2)
                    . ', calculada: ' . number_format($calc, 2) . ').'
                );
            }
        }

        // Con comisión incluida, el monto neto debe provenir del cálculo
        // autoritativo. Con comisión separada se conserva el monto pactado.
        if ($incluirComision !== 1) {
            $calculado['monto_enviado_bob'] = $comisionData['monto_enviado_bob'] ?? $base;
            $calculado['monto_enviado_x']   = $comisionData['monto_enviado_x'] ?? $monto;
        }

        return $calculado;
    }

    public function calcularComision(
        int $tipoComision,
        int $idAgencia,
        int $idMoneda,
        int $idOperacion,
        float $montoEnviado,
        int $idDestino = 1,
        int $incluirComision = 0
    ): array {
        if ($tipoComision === 0) {
            if ($idMoneda === 3) {
                return $this->comisionHabitualCLP($idAgencia, $idMoneda, $idOperacion, $montoEnviado, $idDestino);
            } elseif ($idMoneda === 2) {
                return $this->comisionHabitualBOB($idAgencia, $idMoneda, $idOperacion, $montoEnviado, $incluirComision);
            } else {
                return $this->comisionHabitualUSD($idAgencia, $idMoneda, $idOperacion, $montoEnviado, $idDestino, $incluirComision);
            }
        }
        return $this->comisionPreferencial($tipoComision, $idAgencia, $idMoneda, $idOperacion, $montoEnviado);
    }

    private function comisionHabitualUSD(int $idAgencia, int $idMoneda, int $idOperacion, float $montoEnviado, int $idDestino = 1, int $incluirComision = 0): array
    {
        $cotOficial = $this->getCotizacionVenta(1, $idAgencia);
        $cotOficialCompra = $this->getCotizacionOficialCompra(1);
        $rangoLimite = $this->getParametro('limite_importe_exterior');

        $ivaPct = (float) $this->getParametro('porcentaje_iva');
        $itPct = (float) $this->getParametro('porcentaje_it');
        $itfPct = $this->getItfPorcentaje(1);

        $comisionEntity = $this->getComisionRemesa($idAgencia, $idMoneda, 4, $montoEnviado);
        $comisionTotal = round((float) $comisionEntity['total_comision'], 4);
        $gambartePorc = (float) $comisionEntity['porcentaje_gambarte'];
        $corresponsalPorc = (float) $comisionEntity['porcentaje_corresponsal'];

        if (in_array($idOperacion, [4, 16])) {
            // already set from entity
        } else {
            $comisionTotal = 0;
        }

        $comisionX = ($montoEnviado * $comisionTotal) / 100;
        $comisionBob = $comisionX * $cotOficial;

        $ivaBob = round(($comisionX * $cotOficial * $ivaPct) / 100, 2);
        $ivaX = round($ivaBob / $cotOficial, 2);

        $itBob = round(($itPct * $comisionBob) / 100, 2);
        $itX = round($itBob / $cotOficial, 2);

        $itfX = round(($montoEnviado * $itfPct) / 100, 2);
        $itfBob = round($itfX * $cotOficialCompra, 2);

        $gambarteBob = round(($comisionBob / $comisionTotal) * $gambartePorc, 2);
        $gambarteX = round($gambarteBob / $cotOficial, 2);

        $corresponsalBob = round(($comisionBob / $comisionTotal) * $corresponsalPorc, 2);
        $corresponsalX = round($corresponsalBob / $cotOficial, 2);

        $montoEnviadoBob = round($montoEnviado * $cotOficial, 2);

        $totalOpX = round(
            $montoEnviado + ($incluirComision
                ? -$gambarteX - $corresponsalX
                : $gambarteX + $corresponsalX + $itX + $ivaX + $itfX),
            2
        );
        $totalOpBob = round(
            $montoEnviadoBob + ($incluirComision
                ? -$gambarteBob - $corresponsalBob
                : $gambarteBob + $corresponsalBob + $itBob + $ivaBob + $itfBob),
            2
        );

        if ($idMoneda === 2) {
            if ($cotOficial > 0) {
                if ($montoEnviado >= $rangoLimite) {
                    $corresponsalBob += $itBob;
                    $corresponsalX += $itX;
                    $corresponsalPorc += ($itBob / $montoEnviadoBob) * 100;
                } else {
                    $corresponsalBob += $itBob + $gambarteBob;
                    $corresponsalX += $itX + $gambarteX;
                    $corresponsalPorc += ($itBob / $montoEnviadoBob) * 100 + $gambartePorc;
                    $gambarteX = 0; $gambarteBob = 0; $gambartePorc = 0;
                }
            }
        } elseif ($idMoneda === 1) {
            if ($montoEnviado > $rangoLimite) {
                $gambarteBob += $itBob;
                $gambarteX += $itX;
                $corresponsalPorc += ($itBob / $montoEnviadoBob) * 100;
                $corresponsalX = 0; $corresponsalBob = 0; $corresponsalPorc = 0;
            } else {
                $corresponsalBob += $itBob + $gambarteBob;
                $corresponsalX += $itX + $gambarteX;
                $corresponsalPorc += ($itBob / $montoEnviadoBob) * 100 + $gambartePorc;
                $gambarteX = 0; $gambarteBob = 0; $gambartePorc = 0;
            }
        }

        return [
            'porcentaje' => [
                'comision_total' => round($comisionTotal, 5),
                'gastos_corresponsal' => round($corresponsalPorc, 2),
                'comision_gambarte' => round($gambartePorc, 2),
                'itf' => round($itfPct, 2),
                'iva' => round(($ivaBob / $montoEnviadoBob) * 100, 2),
                'it' => round($itPct, 2),
            ],
            'bob' => [
                'comision_total' => round($comisionBob, 2),
                'gastos_corresponsal' => round($corresponsalBob, 2),
                'comision_gambarte' => round($gambarteBob, 2),
                'itf' => round($itfBob, 2),
                'iva' => round($ivaBob, 2),
                'it' => round($itBob, 2),
                'total_operacion' => round($incluirComision ? $montoEnviadoBob : $totalOpBob, 1),
            ],
            'moneda_x' => [
                'comision_total' => round($comisionX, 2),
                'gastos_corresponsal' => round($corresponsalX, 2),
                'comision_gambarte' => round($gambarteX, 2),
                'itf' => round($itfX, 2),
                'iva' => round($ivaX, 2),
                'it' => round($itX, 2),
                'total_operacion' => round($incluirComision ? $montoEnviado : $totalOpX, 2),
            ],
            'monto_enviado_bob' => $incluirComision ? $totalOpBob : $montoEnviadoBob,
            'monto_enviado_x' => $incluirComision ? $totalOpX : $montoEnviado,
            'is_preferencial' => false,
            'equivalente_usd' => $incluirComision ? $totalOpX : $montoEnviado,
            'total_clp' => $this->calcularTotalClpParaUsd(
                $incluirComision ? $totalOpX : $montoEnviado, $idDestino
            ),
            'tipo_cambio_clp' => $this->obtenerTcClpUsd($idDestino),
        ];
    }

    private function getCotizacionChileCached(int $idDestino): array
    {
        static $cache = [];
        if (!isset($cache[$idDestino])) {
            $cache[$idDestino] = $this->obtenerCotizacionChile($idDestino);
        }
        return $cache[$idDestino];
    }

    private function calcularTotalClpParaUsd(float $montoUsd, int $idDestino): ?float
    {
        $cotChile = $this->getCotizacionChileCached($idDestino);
        if (!$cotChile['success']) {
            return null;
        }
        return round($montoUsd * $cotChile['valor']);
    }

    private function obtenerTcClpUsd(int $idDestino): ?float
    {
        $cotChile = $this->getCotizacionChileCached($idDestino);
        return $cotChile['success'] ? $cotChile['valor'] : null;
    }

    private function calcularTotalClpParaBob(float $montoBob, float $tcParaleloBobUsd, int $idDestino): ?float
    {
        $cotChile = $this->getCotizacionChileCached($idDestino);
        if (!$cotChile['success']) {
            return null;
        }
        $montoUsd = $montoBob / $tcParaleloBobUsd;
        return round($montoUsd * $cotChile['valor']);
    }

    private function comisionHabitualCLP(int $idAgencia, int $idMoneda, int $idOperacion, float $montoEnviado, int $idDestino): array
    {
        $rangoLimite = (float) $this->getParametro('limite_importe_exterior');
        $tcOficialClpBob = $this->getCotizacionOficialCompra($idMoneda);
        $tcParaleloBobUsd = $this->getCotizacionParalela($idAgencia, 1);

        $cotChile = $this->obtenerCotizacionChile($idDestino);
        if (!$cotChile['success']) {
            return ['error' => 'No se pudo obtener cotización Chile. Intente nuevamente.'];
        }
        $tcClpUsd = $cotChile['valor'];

        $tcComercialClpBob = round($tcParaleloBobUsd / $tcClpUsd, 5);

        $comisionEntity = $this->getComisionRemesa($idAgencia, $idMoneda, 4, $montoEnviado);
        $porcComisionClp = (float) $comisionEntity['total_comision'];
        $gambartePorc = (float) $comisionEntity['porcentaje_gambarte'];
        $corresponsalPorc = (float) $comisionEntity['porcentaje_corresponsal'];

        $montoEnviadoClp = ($montoEnviado * $tcParaleloBobUsd) / $tcComercialClpBob;
        $montoEnviadoClp = round($montoEnviadoClp / 10) * 10;
        $comisionTotalClp = $montoEnviadoClp * round($porcComisionClp / 100, 8);

        $montoEnviadoBob = $montoEnviadoClp * $tcOficialClpBob;
        $comisionTotalBob = round($comisionTotalClp * $tcOficialClpBob, 1);

        $ivaBob = round($comisionTotalBob * 0.13, 2);
        $ivaX = $comisionTotalClp * 0.13;
        $itBob = round($comisionTotalBob * 0.03, 2);
        $itX = $comisionTotalClp * 0.03;
        $itfX = $montoEnviadoClp * 0.003;
        $itfBob = round($montoEnviadoBob * 0.003, 1);

        $gambarteBob = round(($comisionTotalBob / $porcComisionClp) * $gambartePorc, 2);
        $gambarteX = round(($comisionTotalClp / $porcComisionClp) * $gambartePorc, 2);
        $corresponsalBob = round(($comisionTotalBob / $porcComisionClp) * $corresponsalPorc, 2);
        $corresponsalX = round(($comisionTotalClp / $porcComisionClp) * $corresponsalPorc, 2);

        $totalOpBob = round(($montoEnviadoClp * $tcComercialClpBob) + $comisionTotalBob + $itfBob, 2);
        $totalOpX = round($montoEnviadoClp + $comisionTotalClp + $itfX, 2);

        if ($idMoneda === 3) {
            if ($montoEnviado > $rangoLimite) {
                $gambarteBob += $itBob;
                $gambarteX += $itX;
                $gambartePorc += ($itBob / $montoEnviadoBob) * 100 + $corresponsalPorc;
                $corresponsalX = 0; $corresponsalBob = 0; $corresponsalPorc = 0;
            } else {
                $corresponsalBob += $itBob + $gambarteBob;
                $corresponsalX += $itX + $gambarteX;
                $corresponsalPorc += ($itBob / $montoEnviadoBob) * 100 + $gambartePorc;
                $gambarteX = 0; $gambarteBob = 0; $gambartePorc = 0;
            }
        }

        return [
            'porcentaje' => [
                'comision_total' => round($porcComisionClp, 4),
                'gastos_corresponsal' => round($corresponsalPorc, 2),
                'comision_gambarte' => round($gambartePorc, 2),
                'itf' => round(0.03, 3),
                'iva' => round(($ivaBob / $montoEnviadoBob) * 100, 2),
                'it' => 0,
            ],
            'bob' => [
                'comision_total' => round($comisionTotalBob, 2),
                'gastos_corresponsal' => round($corresponsalBob, 2),
                'comision_gambarte' => round($gambarteBob, 2),
                'itf' => round($itfBob, 2),
                'iva' => round($ivaBob, 2),
                'it' => round($itBob, 2),
                'total_operacion' => round($totalOpBob, 2),
            ],
            'moneda_x' => [
                'comision_total' => round($comisionTotalClp, 2),
                'gastos_corresponsal' => round($corresponsalX, 2),
                'comision_gambarte' => round($gambarteX, 2),
                'itf' => round($itfX, 2),
                'iva' => round($ivaX, 2),
                'it' => round($itX, 2),
                'total_operacion' => round(round($totalOpX / 10) * 10),
            ],
            'monto_enviado_bob' => $montoEnviadoClp * $tcComercialClpBob,
            'monto_enviado_x' => $montoEnviadoClp,
            'is_preferencial' => false,
            'tipo_cambio_clp' => $tcClpUsd,
            'tipo_cambio_comercial' => $tcComercialClpBob,
            'total_clp' => $montoEnviado * $tcClpUsd,
        ];
    }

    private function comisionHabitualBOB(int $idAgencia, int $idMoneda, int $idOperacion, float $montoEnviado, int $incluirComision = 1): array
    {
        $cotOficial = $this->getCotizacionVenta(1, $idAgencia);
        $cotOficialCompra = $this->getCotizacionOficialCompra(1);
        $rangoLimite = (float) $this->getParametro('limite_importe_exterior');
        $ivaPct = (float) $this->getParametro('porcentaje_iva');
        $itPct = (float) $this->getParametro('porcentaje_it');

        $comisionEntity = $this->getComisionRemesa($idAgencia, $idMoneda, 4, $montoEnviado / $cotOficialCompra);
        $comisionTotal = (in_array($idOperacion, [4, 16]))
            ? round((float) $comisionEntity['total_comision'], 4)
            : 0;

        $comisionBob = round(($montoEnviado * $comisionTotal) / 100, 2);
        $itBob = round(($itPct * $comisionBob) / 100, 2);
        $comisionTotalIt = $comisionBob + $itBob;
        $ivaBob = round(($comisionTotalIt * $ivaPct) / 100, 2);

        $gambarteBob = round(($comisionTotalIt / $comisionTotal) * $comisionEntity['porcentaje_gambarte'], 2);
        $corresponsalBob = round(($comisionTotalIt / $comisionTotal) * $comisionEntity['porcentaje_corresponsal'], 2);

        $montoEnviadoBob = round($montoEnviado, 2);
        // Con comisión incluida, el monto ingresado es el total que paga el
        // cliente y el beneficiario recibe el neto después de la comisión.
        $montoNetoBob = $incluirComision
            ? round(max(0, $montoEnviadoBob - $comisionTotalIt), 2)
            : $montoEnviadoBob;
        // La comisión total ya incluye el IT y el IVA queda únicamente como
        // desglose fiscal. No debe volver a sumarse al total cobrado.
        $totalOpBob = round(
            $incluirComision ? $montoEnviadoBob : $montoEnviadoBob + $comisionTotalIt,
            2
        );

        $itBob = round(($itPct * ($gambarteBob + $corresponsalBob)) / 100, 2);

        $gambartePorc = (float) $comisionEntity['porcentaje_gambarte'];
        $corresponsalPorc = (float) $comisionEntity['porcentaje_corresponsal'];

        if (($totalOpBob / $cotOficialCompra) > $rangoLimite) {
            $gambarteBob += $corresponsalBob - $ivaBob;
            $gambartePorc += ($itBob / $montoEnviadoBob) * 100;
            $corresponsalBob = 0;
        } else {
            $corresponsalBob += $gambarteBob - $ivaBob;
            $corresponsalPorc += ($itBob / $montoEnviadoBob) * 100 + $gambartePorc;
            $gambarteBob = 0;
        }

        $tcParalelo = $this->getCotizacionParalela($idAgencia, 1);
        $equivalenteUsd = $montoNetoBob / $tcParalelo;

        return [
            'porcentaje' => [
                'comision_total' => round($comisionTotal, 5),
                'gastos_corresponsal' => round($corresponsalPorc, 2),
                'comision_gambarte' => round($gambartePorc, 2),
                'itf' => null,
                'iva' => round(($ivaBob / $montoEnviadoBob) * 100, 2),
                'it' => round($itPct, 2),
            ],
            'bob' => [
                'comision_total' => round($comisionTotalIt, 2),
                'gastos_corresponsal' => round($corresponsalBob, 2),
                'comision_gambarte' => round($gambarteBob, 2),
                'itf' => null,
                'iva' => round($ivaBob, 2),
                'it' => round($itBob, 2),
                'total_operacion' => round($incluirComision ? $montoEnviadoBob : $totalOpBob, 2),
            ],
            'moneda_x' => [
                'comision_total' => round($comisionTotalIt, 2),
                'gastos_corresponsal' => round($corresponsalBob, 2),
                'comision_gambarte' => round($gambarteBob, 2),
                'itf' => null,
                'iva' => round($ivaBob, 2),
                'it' => round($itBob, 2),
                'total_operacion' => round($incluirComision ? $montoEnviado : $totalOpBob, 2),
            ],
            'monto_enviado_bob' => $montoNetoBob,
            'monto_enviado_x' => $montoNetoBob,
            'is_preferencial' => false,
            'equivalente_usd' => $equivalenteUsd,
            'total_clp' => $this->calcularTotalClpParaBob($montoNetoBob, $tcParalelo, 1),
            'tipo_cambio_clp' => $this->obtenerTcClpUsd(1),
        ];
    }

    private function comisionPreferencial(int $tipoComision, int $idAgencia, int $idMoneda, int $idOperacion, float $montoEnviado): array
    {
        $cotOficial = $this->getCotizacionVenta($idMoneda, $idAgencia);
        $ivaPct = (float) $this->getParametro('porcentaje_iva');
        $itPct = (float) $this->getParametro('porcentaje_it');
        $itfPct = $this->getItfPorcentaje($idMoneda);
        $rangoLimite = (float) $this->getParametro('limite_importe_exterior');

        $pref = DB::selectOne("
            SELECT porcentaje_comision_preferencial
            FROM comision_preferencial
            WHERE id_operacion IN (4, 16) AND id_comision_preferencial = :id AND estado = 'A'
        ", [':id' => $tipoComision]);

        $comisionTotal = round((float) ($pref['porcentaje_comision_preferencial'] ?? 0), 4);

        $comisionX = ($montoEnviado * $comisionTotal) / 100;
        $comisionBob = $comisionX * $cotOficial;

        $ivaBob = round(($comisionX * $cotOficial * $ivaPct) / 100, 2);
        $ivaX = round($ivaBob / $cotOficial, 2);

        $itBob = round(($itPct * $comisionBob) / 100, 2);
        $itX = round($itBob / $cotOficial, 2);

        $itfX = round(($montoEnviado * $itfPct) / 100, 2);
        $itfBob = round($itfX * $cotOficial, 2);

        $montoEnviadoBob = round($montoEnviado * $cotOficial, 2);

        $totalOpX = round($montoEnviado + $comisionX + $itfX, 2);
        $totalOpBob = round($montoEnviadoBob + $comisionBob + $itfBob, 2);

        $gambarteBob = 0; $gambarteX = 0; $gambartePorc = 0;
        $corresponsalBob = 0; $corresponsalX = 0; $corresponsalPorc = 0;

        if ($montoEnviado >= $rangoLimite) {
            $gambarteBob = $comisionBob - $ivaBob;
            $gambarteX = $comisionX - $ivaX;
            $gambartePorc = $comisionTotal - ($ivaBob / $montoEnviadoBob) * 100;
        } else {
            $corresponsalBob = $comisionBob - $ivaBob;
            $corresponsalX = $comisionX - $ivaX;
            $corresponsalPorc = $comisionTotal - ($ivaBob / $montoEnviadoBob) * 100;
        }

        return [
            'porcentaje' => [
                'comision_total' => round($comisionTotal, 4),
                'gastos_corresponsal' => round($corresponsalPorc, 2),
                'comision_gambarte' => round($gambartePorc, 2),
                'itf' => round($itfPct, 2),
                'iva' => round(($ivaBob / $montoEnviadoBob) * 100, 2),
                'it' => round($itPct, 2),
            ],
            'bob' => [
                'comision_total' => round($comisionBob, 2),
                'gastos_corresponsal' => round($corresponsalBob, 2),
                'comision_gambarte' => round($gambarteBob, 2),
                'itf' => round($itfBob, 2),
                'iva' => round($ivaBob, 2),
                'it' => round($itBob, 2),
                'total_operacion' => round($totalOpBob, 1),
            ],
            'moneda_x' => [
                'comision_total' => round($comisionX, 2),
                'gastos_corresponsal' => round($corresponsalX, 2),
                'comision_gambarte' => round($gambarteX, 2),
                'itf' => round($itfX, 2),
                'iva' => round($ivaX, 2),
                'it' => round($itX, 2),
                'total_operacion' => round($totalOpX, 2),
            ],
            'monto_enviado_bob' => $montoEnviadoBob,
            'monto_enviado_x' => $montoEnviado,
            'is_preferencial' => true,
        ];
    }

    /** Persiste el formulario complemento UIF dentro de la misma transacción de remesa. */
    private function registrarComplemento(int $idUsuarioFinanciero, array $c, string $fecha): void
    {
        $depAnterior = ($c['c_dependiente_estado_anterior'] ?? 'N') === 'S' ? 'S' : 'N';
        $depActual = ($c['c_dependiente_estado_actual'] ?? 'N') === 'S' ? 'S' : 'N';
        $cargoAnterior = (int) ($c['c_cargo_trabajo_anterior_id'] ?? 1) ?: 1;
        $cargoActual = (int) ($c['c_cargo_trabajo_actual_id'] ?? 1) ?: 1;
        $riesgoAnterior = $depAnterior === 'S' ? DB::scalar("SELECT riesgo FROM listas_cargos WHERE id = :id", [':id' => $cargoAnterior]) : 'N';
        $riesgoActual = $depActual === 'S' ? DB::scalar("SELECT riesgo FROM listas_cargos WHERE id = :id", [':id' => $cargoActual]) : 'N';
        $fechaFinAnterior = trim($c['c_fecha_fin_trabajo_anterior'] ?? '');
        if ($depAnterior === 'S' && $riesgoAnterior === 'S' && $fechaFinAnterior) {
            $limiteYears = (int) (DB::scalar("SELECT valor FROM parametros WHERE slug = 'limite_anyos_ya_no_pep' AND estado = 'A'") ?: 5);
            if ((new \DateTime())->diff(new \DateTime($fechaFinAnterior))->y > $limiteYears) $riesgoAnterior = 'N';
        }
        $pep = ($riesgoAnterior === 'S' || $riesgoActual === 'S') ? 'S' : 'N';

        DB::execute("INSERT INTO usuario_financiero_complemento (
            usuario_financiero_id, c_apellido_casada, c_pais_residencia_id, c_ciudad_residencia,
            c_ingresos, c_fecha_ingreso, c_experiencia_trabajo, c_pep, c_pep_stipo,
            c_actividad_secundaria_id, c_ingresos_actividad_secundaria, c_experiencia_actividad_secundaria,
            c_referencias_bancarias, c_referencias_comerciales, c_referencias_personales,
            c_dependiente_estado_anterior, c_entidad_trabajo_anterior, c_fecha_inicio_trabajo_anterior,
            c_fecha_fin_trabajo_anterior, c_cargo_trabajo_anterior_id,
            c_dependiente_estado_actual, c_entidad_trabajo_actual, c_cargo_trabajo_actual_id,
            c_nombres_conyugue, c_apellido_paterno_conyugue, c_apellido_materno_conyugue,
            c_apellido_casada_conyugue, c_profesion_conyugue_id, c_actividad_principal_conyugue_id,
            c_actividad_secundaria_conyugue_id, c_lugar_trabajo_conyugue, c_cargo_conyugue_id,
            c_ingreso_mensual_conyugue, c_experiencia_trabajo_conyugue, fecha_registro
        ) VALUES (
            :uf_id, :apellido_casada, :pais, :ciudad,
            :ingresos, :fecha_ingreso, :exp_trabajo, :pep, :pep_tipo,
            :act_sec, :ing_act_sec, :exp_act_sec,
            :ref_banc, :ref_com, :ref_per,
            :dep_ant, :ent_ant, :fi_ant, :ff_ant, :cargo_ant,
            :dep_act, :ent_act, :cargo_act,
            :nom_con, :ap_con, :am_con, :ac_con, :prof_con, :act_prin_con,
            :act_sec_con, :lugar_con, :cargo_con, :ing_con, :exp_con, :fecha
        )", [
            ':uf_id' => $idUsuarioFinanciero,
            ':apellido_casada' => mb_strtoupper(trim($c['c_apellido_casada'] ?? '')),
            ':pais' => (int) ($c['c_pais_residencia_id'] ?? 26),
            ':ciudad' => mb_strtoupper(trim($c['c_ciudad_residencia'] ?? '')),
            ':ingresos' => (float) ($c['c_ingresos'] ?? 0),
            ':fecha_ingreso' => $c['c_fecha_ingreso'] ?? null,
            ':exp_trabajo' => mb_strtoupper(trim($c['c_experiencia_trabajo'] ?? '')),
            ':pep' => $pep, ':pep_tipo' => $pep === 'S' ? 'NAL' : 'ninguno',
            ':act_sec' => ((int) ($c['c_actividad_secundaria_id'] ?? 0)) ?: 1,
            ':ing_act_sec' => (float) ($c['c_ingresos_actividad_secundaria'] ?? 0),
            ':exp_act_sec' => mb_strtoupper(trim($c['c_experiencia_actividad_secundaria'] ?? '')),
            ':ref_banc' => mb_strtoupper(trim($c['c_referencias_bancarias'] ?? '')),
            ':ref_com' => mb_strtoupper(trim($c['c_referencias_comerciales'] ?? '')),
            ':ref_per' => mb_strtoupper(trim($c['c_referencias_personales'] ?? '')),
            ':dep_ant' => $depAnterior, ':ent_ant' => mb_strtoupper(trim($c['c_entidad_trabajo_anterior'] ?? '')),
            ':fi_ant' => $c['c_fecha_inicio_trabajo_anterior'] ?: null,
            ':ff_ant' => $fechaFinAnterior ?: null, ':cargo_ant' => $cargoAnterior,
            ':dep_act' => $depActual, ':ent_act' => mb_strtoupper(trim($c['c_entidad_trabajo_actual'] ?? '')),
            ':cargo_act' => $cargoActual,
            ':nom_con' => mb_strtoupper(trim($c['c_nombres_conyugue'] ?? '')),
            ':ap_con' => mb_strtoupper(trim($c['c_apellido_paterno_conyugue'] ?? '')),
            ':am_con' => mb_strtoupper(trim($c['c_apellido_materno_conyugue'] ?? '')),
            ':ac_con' => mb_strtoupper(trim($c['c_apellido_casada_conyugue'] ?? '')),
            ':prof_con' => ((int) ($c['c_profesion_conyugue_id'] ?? 0)) ?: 1,
            ':act_prin_con' => ((int) ($c['c_actividad_principal_conyugue_id'] ?? 0)) ?: 1,
            ':act_sec_con' => ((int) ($c['c_actividad_secundaria_conyugue_id'] ?? 0)) ?: 1,
            ':lugar_con' => mb_strtoupper(trim($c['c_lugar_trabajo_conyugue'] ?? '')),
            ':cargo_con' => ((int) ($c['c_cargo_conyugue_id'] ?? 0)) ?: 1,
            ':ing_con' => (float) ($c['c_ingreso_mensual_conyugue'] ?? 0),
            ':exp_con' => mb_strtoupper(trim($c['c_experiencia_trabajo_conyugue'] ?? '')),
            ':fecha' => $fecha,
        ]);
    }

    // ─── Registro de remesa ───────────────────────────────────────────

    public function registrarRemesa(
        int $codUsuario,
        string $loginUsuario,
        int $idAgencia,
        int $idOperacion,
        array $cliente,
        array $destinatario,
        array $operacion,
        array $comisionData,
        string $ip,
        string $uuid,
        array $complemento = []
    ): array {
        // ── Validación de datos del cliente ────────────────────────────────────
        $idPais = (int) ($cliente['id_pais'] ?? 26);
        $esExtranjero = $idPais !== 26;

        if (empty(trim($cliente['nombres'] ?? ''))) {
            return ['success' => false, 'message' => 'El nombre del cliente es obligatorio.'];
        }
        if (empty(trim($cliente['ape_pat'] ?? ''))) {
            return ['success' => false, 'message' => 'El apellido paterno del cliente es obligatorio.'];
        }
        if (empty(trim($cliente['celular'] ?? ''))) {
            return ['success' => false, 'message' => 'El celular del cliente es obligatorio.'];
        }
        if (empty(trim($cliente['fecha_nacimiento'] ?? ''))) {
            return ['success' => false, 'message' => 'La fecha de nacimiento es obligatoria.'];
        }

        if ($esExtranjero) {
            if ($idPais <= 0) {
                return ['success' => false, 'message' => 'El país de origen es obligatorio para extranjeros.'];
            }
        } else {
            if ((int) ($cliente['id_expedicion'] ?? 0) <= 0) {
                return ['success' => false, 'message' => 'La expedición del documento es obligatoria.'];
            }
            if (empty(trim($cliente['domicilio'] ?? ''))) {
                return ['success' => false, 'message' => 'El domicilio es obligatorio para clientes nacionales.'];
            }
            if ((int) ($cliente['id_actividad_economica'] ?? 0) <= 0) {
                return ['success' => false, 'message' => 'La actividad económica es obligatoria.'];
            }
        }

        $existente = DB::selectOne(
            "SELECT id_referencia FROM pos_operacion_log WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );
        if ($existente) {
            $comprobante = $this->obtenerComprobante((int) $existente['id_referencia']);
            if ($comprobante) {
                return ['success' => true, 'message' => 'Operación ya registrada.', 'data' => $comprobante];
            }
        }

        $monRec = (int) $operacion['mon_rec'];
        $monEnt = (int) $operacion['mon_ent'];
        $monto = (float) $operacion['monto'];

        // La comisión es dinero que cobra la casa de cambio: se recalcula en el
        // servidor y NO se acepta la cifra que envíe el navegador. Se valida antes
        // de tocar la base, para no gastar consultas en un payload manipulado.
        // Ver `validarComisionAutoritativa()`.
        try {
            $comisionData = $this->validarComisionAutoritativa(
                $comisionData, $idAgencia, $idOperacion, $monRec, $monto, $operacion
            );
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->isBovedaAbierta($idAgencia)) {
            return ['success' => false, 'message' => 'La bóveda no está abierta. No se pueden registrar operaciones.'];
        }

        if (!$this->tieneSesionAbierta($codUsuario)) {
            return ['success' => false, 'message' => 'No tiene ninguna caja abierta. Abra una caja antes de operar.'];
        }

        $montoMax = $this->getParametro(self::PARAM_MONTO_MAX_REMESA) ?: self::DEFAULT_MONTO_MAX_REMESA;
        if ($monto > $montoMax) {
            return ['success' => false, 'message' => "El monto excede el límite permitido ({$montoMax}). Contacte a su supervisor."];
        }
        $tipoCambio = (float) $operacion['tipo_cambio'];
        $totalCambio = (float) $operacion['total_cambio'];
        $idDestino = (int) $operacion['id_destino'];
        $tipoCambioClp = (float) ($operacion['tipo_cambio_clp'] ?? 0);
        // Usar el resultado autoritativo evita conservar el total bruto en CLP
        // cuando la comisión está incluida en el monto ingresado.
        $totalClp = (float) ($comisionData['total_clp'] ?? ($operacion['total_clp'] ?? 0));
        $pagoComision = (int) ($operacion['pago_comision'] ?? 0);
        $metodosPago = $operacion['metodos_pago'] ?? [];
        $idFormaPagoChile = (int) ($operacion['id_forma_pago_chile'] ?? 1);
        $idBancoChile = $idFormaPagoChile === 2 ? (int) ($destinatario['id_banco_chile'] ?? 0) : null;
        $cuentaDestinoChile = $idFormaPagoChile === 2 ? trim($destinatario['cuenta'] ?? '') : '';

        $comisionBob = (float) ($comisionData['bob']['comision_total'] ?? 0);
        $comisionX = (float) ($comisionData['moneda_x']['comision_total'] ?? 0);
        $totalBob = (float) ($comisionData['bob']['total_operacion'] ?? 0);
        $totalX = (float) ($comisionData['moneda_x']['total_operacion'] ?? 0);
        $itfX = (float) ($comisionData['moneda_x']['itf'] ?? 0);
        $itfBob = (float) ($comisionData['bob']['itf'] ?? 0);
        $ivaX = (float) ($comisionData['moneda_x']['iva'] ?? 0);
        $ivaBob = (float) ($comisionData['bob']['iva'] ?? 0);
        $gambarteX = (float) ($comisionData['moneda_x']['comision_gambarte'] ?? 0);
        $gambarteBob = (float) ($comisionData['bob']['comision_gambarte'] ?? 0);
        $corresponsalX = (float) ($comisionData['moneda_x']['gastos_corresponsal'] ?? 0);
        $corresponsalBob = (float) ($comisionData['bob']['gastos_corresponsal'] ?? 0);
        $montoEnviadoBob = (float) ($comisionData['monto_enviado_bob'] ?? 0);
        $montoEnviadoX = (float) ($comisionData['monto_enviado_x'] ?? $monto);
        $itfBobCpl = (float) ($operacion['itf_bob'] ?? $itfBob);

        $idMonedaRecibida = $monEnt === 3 ? 3 : $monRec;
        $idMonedaEnviada = $monEnt;
        $montoEnviado = $monEnt === 3
            ? $montoEnviadoX
            : ($pagoComision === 1 ? $montoEnviadoBob : $monto);

        $cajaRecibe = DB::selectOne("
            SELECT c.id_caja, c.num_caja
            FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $idMonedaRecibida]);

        if (!$cajaRecibe) {
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $idMonedaRecibida]);
            return ['success' => false, 'message' => "No tiene caja asignada para la moneda {$monAbr}."];
        }

        $agenciaRow = DB::selectOne("SELECT cod_agencia, descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);
        $codAgencia = $agenciaRow['cod_agencia'] ?? '';
        $agenciaDesc = $agenciaRow['descripcion'] ?? '';

        $fecRegOpe = date('Y-m-d H:i:s');

        $origen = mb_strtoupper(trim($operacion['origen'] ?? ''));
        $destino_pcc = mb_strtoupper(trim($operacion['destino_pcc'] ?? ''));
        $dineroSi = (int) ($operacion['dinero_si'] ?? 1);
        $selectPer = (int) ($operacion['select_per'] ?? 0);
        $nombresDd = mb_strtoupper(trim($operacion['nombres_dd'] ?? ''));
        $docNitDd = mb_strtoupper(trim($operacion['doc_nit_dd'] ?? ''));
        $actEcoDd = (int) ($operacion['act_eco_dd'] ?? 0);
        $domicilioDd = mb_strtoupper(trim($operacion['domicilio_dd'] ?? ''));
        $idDepartamento = (int) ($operacion['id_departamento'] ?? 0);
        $idCiudadPcc = (int) ($operacion['id_ciudad_pcc'] ?? 0);
        $telefonoDd = trim($operacion['telefono_dd'] ?? '');
        $zonaDd = mb_strtoupper(trim($operacion['zona_dd'] ?? ''));
        $impresionPcc08 = ($operacion['impresion_pcc08'] ?? 'N') === 'S' ? 'S' : 'N';
        $observacionesUif = trim($operacion['observaciones_uif'] ?? '');

        if ($impresionPcc08 === 'S' && $dineroSi === 0) {
            if (empty($nombresDd)) {
                return ['success' => false, 'message' => 'El nombre del beneficiario (tercero) es obligatorio.'];
            }
            if (empty($docNitDd)) {
                return ['success' => false, 'message' => 'El documento del beneficiario (tercero) es obligatorio.'];
            }
            if (empty($domicilioDd)) {
                return ['success' => false, 'message' => 'El domicilio del beneficiario (tercero) es obligatorio.'];
            }
            if (empty($telefonoDd)) {
                return ['success' => false, 'message' => 'El teléfono del beneficiario (tercero) es obligatorio.'];
            }
        }

        $compraUsd = (bool) ($operacion['compra_usd'] ?? false);
        $nitCompra = trim($operacion['nit_compra'] ?? '');

        return DB::transaction(function () use (
            $codUsuario, $loginUsuario, $idAgencia, $idOperacion, $cliente, $destinatario, $operacion,
            $monRec, $monEnt, $monto, $tipoCambio, $totalCambio, $idDestino,
            $tipoCambioClp, $totalClp, $pagoComision,
            $comisionBob, $comisionX, $totalBob, $totalX,
            $itfX, $itfBob, $ivaX, $ivaBob, $gambarteX, $gambarteBob,
            $corresponsalX, $corresponsalBob, $montoEnviadoBob, $montoEnviadoX, $itfBobCpl,
            $idMonedaRecibida, $idMonedaEnviada, $montoEnviado, $complemento,
            $cajaRecibe, $fecRegOpe, $ip, $uuid, $codAgencia, $agenciaDesc,
            $origen, $destino_pcc, $dineroSi, $selectPer, $nombresDd, $docNitDd,
            $actEcoDd, $domicilioDd, $idDepartamento, $idCiudadPcc, $telefonoDd, $zonaDd,
            $impresionPcc08, $observacionesUif, $comisionData, $compraUsd, $nitCompra,
            $metodosPago, $idFormaPagoChile, $idBancoChile, $cuentaDestinoChile
        ) {
            // 1. Upsert usuario financiero
            $idUsuarioFinanciero = $this->upsertUsuarioFinanciero($cliente, $fecRegOpe);
            if ($complemento) {
                $this->registrarComplemento($idUsuarioFinanciero, $complemento, $fecRegOpe);
            }

            // 2. Upsert destinatario
            $idDestinatario = $this->upsertDestinatario($cliente, $destinatario, $fecRegOpe);

            // 3. Generar código y correlativo
            $codigo = $this->generarCodigoRemesa();
            $correlativo = ((int) DB::scalar(
                "SELECT COALESCE(MAX(correlativo), 0) FROM remesa WHERE id_agencia = :ag",
                [':ag' => $idAgencia]
            )) + 1;

            $totalClpCalc = $totalClp > 0 ? $totalClp : round($monto * $tipoCambioClp);
            $snapshotStr = json_encode($comisionData);

            // 4. INSERT remesa
            DB::execute("
                INSERT INTO remesa (
                    codigo, id_operacion, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                    id_destinatario, id_agencia, pago_comision,
                    mon_rec, monto, mon_ent, tipo_cambio, total_cambio,
                    id_destino, comision, itf, total, comision_bob, total_bob,
                    fec_crea_remesa, fec_mod_remesa, facturado, cod_usuario, estado,
                    correlativo, origen, destino, tipo_cambio_clp, total_clp,
                    dinero_si, dinero_no, select_per, nombres_dd, doc_nit_dd, act_eco_dd,
                    domicilio_dd, id_departamento, id_ciudad_pcc, telefono_dd, zona_dd,
                    impresion_pcc08, observaciones_uif, usuario_financiero_id, persona_factura_id,
                    itf_bob, iva, iva_bob, comision_gambarte, comision_gambarte_bob,
                    gastos_corresponsal, gastos_corresponsal_bob, remesa_snapshot, subtipo,
                    id_forma_pago_chile
                ) VALUES (
                    :codigo, :op, :tipo_doc, :doc, :exp,
                    :id_dest, :ag, :pago_com,
                    :mon_rec, :monto, :mon_ent, :tc, :total_cambio,
                    :id_destino, :comision, :itf, :total, :comision_bob, :total_bob,
                    :fecha, NULL, 0, :usr, 0,
                    :correlativo, :origen, :destino, :tc_clp, :total_clp,
                    :dinero_si, :dinero_no, :select_per, :nombres_dd, :doc_nit_dd, :act_eco_dd,
                    :domicilio_dd, :id_dpto, :id_ciudad_pcc, :telefono_dd, :zona_dd,
                    :imp_pcc08, :obs_uif, :uf_id, 0,
                    :itf_bob, :iva, :iva_bob, :gambarte, :gambarte_bob,
                    :corresponsal, :corresponsal_bob, :snapshot, 1,
                    :fp_chile
                )
            ", [
                ':codigo'      => $codigo,
                ':op'          => $idOperacion,
                ':tipo_doc'    => (int) $cliente['id_tipo_doc_identidad'],
                ':doc'         => mb_strtoupper(trim($cliente['num_doc_identidad'])),
                ':exp'         => (int) $cliente['id_expedicion'],
                ':id_dest'     => $idDestinatario,
                ':ag'          => $idAgencia,
                ':pago_com'    => $pagoComision,
                ':mon_rec'     => $idMonedaRecibida,
                ':monto'       => $montoEnviado,
                ':mon_ent'     => $idMonedaEnviada,
                ':tc'          => $tipoCambio,
                ':total_cambio' => $totalCambio,
                ':id_destino'  => $idDestino,
                ':comision'    => $comisionX,
                ':itf'         => $itfX,
                ':total'       => $totalX,
                ':comision_bob' => $comisionBob,
                ':total_bob'   => $totalBob,
                ':fecha'       => $fecRegOpe,
                ':usr'         => $codUsuario,
                ':correlativo' => $correlativo,
                ':origen'      => $origen,
                ':destino'     => $destino_pcc,
                ':tc_clp'      => $tipoCambioClp,
                ':total_clp'   => $totalClpCalc,
                ':dinero_si'   => $dineroSi,
                ':dinero_no'   => $dineroSi === 1 ? 0 : 1,
                ':select_per'  => $selectPer,
                ':nombres_dd'  => $nombresDd,
                ':doc_nit_dd'  => $docNitDd,
                ':act_eco_dd'  => $actEcoDd,
                ':domicilio_dd' => $domicilioDd,
                ':id_dpto'     => $idDepartamento,
                ':id_ciudad_pcc' => $idCiudadPcc,
                ':telefono_dd' => $telefonoDd,
                ':zona_dd'     => $zonaDd,
                ':imp_pcc08'   => $impresionPcc08,
                ':obs_uif'     => $observacionesUif ?: null,
                ':uf_id'       => $idUsuarioFinanciero,
                ':itf_bob'     => $itfBob,
                ':iva'         => $ivaX,
                ':iva_bob'     => $ivaBob,
                ':gambarte'    => $gambarteX,
                ':gambarte_bob' => $gambarteBob,
                ':corresponsal' => $corresponsalX,
                ':corresponsal_bob' => $corresponsalBob,
                ':snapshot'    => $snapshotStr,
                ':fp_chile'    => $idFormaPagoChile,
            ]);

            $idRemesa = DB::lastInsertId();

            // 4b. Registrar métodos de pago (cobro en Bolivia) — con referencia a cuenta bancaria
            $montoEfectivoBob = 0.0;
            $montoBancoBob = 0.0;
            $detallesBanco = [];

            if (is_array($metodosPago) && count($metodosPago) > 0) {
                foreach ($metodosPago as $mp) {
                    $fpId = (int) ($mp['id_forma_pago'] ?? 1);
                    $fpMonto = (float) ($mp['monto'] ?? 0);
                    $fpRef = trim($mp['referencia'] ?? '');
                    $fpObs = trim($mp['observaciones'] ?? '');
                    $mpNumCuenta = trim($mp['num_cuenta_bancaria'] ?? '');
                    $mpIdEntidad = (int) ($mp['id_entidad_financiera'] ?? 0);
                    $mpIdAgMp = (int) ($mp['id_agencia'] ?? 0);
                    $mpIdMonCta = (int) ($mp['id_moneda_cuenta'] ?? 0);

                    if ($fpId === 1) {
                        $montoEfectivoBob += $fpMonto;
                    } else {
                        $montoBancoBob += $fpMonto;
                        $detallesBanco[] = $mp;
                    }

                    DB::execute("
                        INSERT INTO pos_metodo_pago_operacion
                            (tipo_operacion, id_referencia, id_forma_pago, monto, moneda,
                             referencia_bancaria, observaciones,
                             num_cuenta_bancaria, id_entidad_financiera, id_agencia, id_moneda_cuenta,
                             created_at)
                        VALUES ('remesa', :ref, :fp, :monto, 'BOB',
                                :referencia, :obs,
                                :num_cuenta, :entidad, :agencia_mp, :moneda_cta,
                                :fecha)
                    ", [
                        ':ref'        => $idRemesa,
                        ':fp'         => $fpId,
                        ':monto'      => $fpMonto,
                        ':referencia' => $fpRef ?: null,
                        ':obs'        => $fpObs ?: null,
                        ':num_cuenta' => $mpNumCuenta ?: null,
                        ':entidad'    => $mpIdEntidad ?: null,
                        ':agencia_mp' => $mpIdAgMp ?: null,
                        ':moneda_cta' => $mpIdMonCta ?: null,
                        ':fecha'      => $fecRegOpe,
                    ]);
                }
            }

            if ($montoEfectivoBob <= 0 && $montoBancoBob <= 0) {
                $montoEfectivoBob = $totalBob;
            }

            $totalPagoBob = $montoEfectivoBob + $montoBancoBob;
            $ratioCash = $totalPagoBob > 0 ? $montoEfectivoBob / $totalPagoBob : 1.0;

            // 5. Flujo de caja: ER (Entrada Remesa) — divisa siempre va completa a caja divisa
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, :fecha, 'ER')
            ", [
                ':id'    => $cajaRecibe['id_caja'],
                ':saldo' => $montoEnviado,
                ':fecha' => $fecRegOpe,
            ]);

            // 6. ITF en BOB — solo porción efectivo a saldo_caja
            if ($itfX > 0) {
                $cajaBob = DB::selectOne("
                    SELECT c.id_caja FROM caja c
                    WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = 2
                      AND c.estado = 'A' AND c.id_tipo_unidad = 2
                    LIMIT 1
                ", [':usr' => $codUsuario, ':ag' => $idAgencia]);

                if ($cajaBob) {
                    $itfEfectivo = round($itfBob * $ratioCash, 2);
                    if ($itfEfectivo > 0) {
                        DB::execute("
                            INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                            VALUES (:id, :saldo, :fecha, 'IR')
                        ", [
                            ':id'    => $cajaBob['id_caja'],
                            ':saldo' => $itfEfectivo,
                            ':fecha' => $fecRegOpe,
                        ]);
                    }
                }
            }

            // 7. Comisión en BOB — solo porción efectivo a saldo_caja
            $cajaBobComision = DB::selectOne("
                SELECT c.id_caja FROM caja c
                WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = 2
                  AND c.estado = 'A' AND c.id_tipo_unidad = 2
                LIMIT 1
            ", [':usr' => $codUsuario, ':ag' => $idAgencia]);

            if ($cajaBobComision) {
                $comisionEfectivo = round($comisionBob * $ratioCash, 2);
                if ($comisionEfectivo > 0) {
                    DB::execute("
                        INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                        VALUES (:id, :saldo, :fecha, 'OR')
                    ", [
                        ':id'    => $cajaBobComision['id_caja'],
                        ':saldo' => $comisionEfectivo,
                        ':fecha' => $fecRegOpe,
                    ]);
                }
            }

            // 7b. Porción bancaria → movimiento_cuenta_bancaria
            if ($montoBancoBob > 0 && !empty($detallesBanco)) {
                foreach ($detallesBanco as $db) {
                    $numCuenta = trim($db['num_cuenta_bancaria'] ?? '');
                    $idEntidad = (int) ($db['id_entidad_financiera'] ?? 0);
                    $idMonedaCuenta = (int) ($db['id_moneda_cuenta'] ?? 2);
                    $mpMonto = (float) ($db['monto'] ?? 0);
                    $mpRef = trim($db['referencia'] ?? '');
                    $formaPago = (int) ($db['id_forma_pago'] ?? 2);
                    $concBanco = $formaPago === 3 ? 'QC' : 'TF';
                    $glosa = "COBRO POR " . ($formaPago === 3 ? 'QR' : 'TRANSFERENCIA') . " - REMESA #{$codigo}" . ($mpRef ? " REF:{$mpRef}" : '');

                    if ($numCuenta && $idEntidad) {
                        DB::execute("
                            INSERT INTO movimiento_cuenta_bancaria (
                                id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera,
                                num_cuenta_bancaria, id_moneda, monto, conciliacion,
                                glosa, fec_crea_movimiento_cuenta_bancaria, cod_usuario
                            ) VALUES (
                                :ag, :tipo_cuenta, :entidad,
                                :num_cuenta, :moneda, :monto, :conc,
                                :glosa, :fecha, :usr
                            )
                        ", [
                            ':ag'           => $idAgencia,
                            ':tipo_cuenta'  => (int) ($db['id_tipo_cuenta_bancaria'] ?? 1),
                            ':entidad'      => $idEntidad,
                            ':num_cuenta'   => $numCuenta,
                            ':moneda'       => $idMonedaCuenta,
                            ':monto'        => $mpMonto,
                            ':conc'         => $concBanco,
                            ':glosa'        => $glosa,
                            ':fecha'        => $fecRegOpe,
                            ':usr'          => $codUsuario,
                        ]);
                    }
                }
            }

            // 8. pos_operacion_log (auditoría + idempotencia)
            DB::execute("
                INSERT INTO pos_operacion_log (
                    uuid, tipo_operacion, id_referencia, tabla_referencia,
                    id_caja, cod_usuario, id_agencia, id_moneda,
                    monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                ) VALUES (
                    :uuid, 'remesa', :id_ref, 'remesa',
                    :id_caja, :usr, :ag, :mon,
                    :monto, :monto_bob, 'completado', :ip, :meta, :fecha
                )
            ", [
                ':uuid'      => $uuid,
                ':id_ref'    => $idRemesa,
                ':id_caja'   => $cajaRecibe['id_caja'],
                ':usr'       => $codUsuario,
                ':ag'        => $idAgencia,
                ':mon'       => $idMonedaEnviada,
                ':monto'     => $totalX,
                ':monto_bob' => $totalBob,
                ':ip'        => $ip,
                ':meta'      => json_encode(['codigo' => $codigo, 'correlativo' => $correlativo]),
                ':fecha'     => $fecRegOpe,
            ]);

            $idOperacionLog = DB::lastInsertId();

            // 9. Facturación electrónica (sobre la comisión)
            $clienteNombre = mb_strtoupper(trim(
                ($cliente['nombres'] ?? '') . ' ' .
                ($cliente['ape_pat'] ?? '') . ' ' .
                ($cliente['ape_mat'] ?? '')
            ));

            $facturaData = $this->facturarRemesa(
                $idRemesa, $codigo, $idOperacion, $idAgencia, $loginUsuario,
                $cliente, $monEnt, $comisionBob, $fecRegOpe, $clienteNombre
            );

            // 11. Contabilidad
            $cuentasBancoPago = [];
            if ($montoBancoBob > 0 && !empty($detallesBanco)) {
                foreach ($detallesBanco as $db) {
                    $numCta = trim($db['num_cuenta_bancaria'] ?? '');
                    $idEnt = (int) ($db['id_entidad_financiera'] ?? 0);
                    if ($numCta && $idEnt) {
                        $cbRow = DB::selectOne("
                            SELECT codsubcuenta FROM cuenta_bancaria
                            WHERE num_cuenta_bancaria = :nc AND id_entidad_financiera = :ef AND estado = 'A'
                            LIMIT 1
                        ", [':nc' => $numCta, ':ef' => $idEnt]);
                        if ($cbRow && !empty($cbRow['codsubcuenta'])) {
                            $cuentasBancoPago[] = [
                                'codsubcuenta' => $cbRow['codsubcuenta'],
                                'monto'        => (float) ($db['monto'] ?? 0),
                            ];
                        }
                    }
                }
            }

            $this->registrarAsientoContableRemesa(
                $idRemesa, $codigo, $idOperacion, $idAgencia, $codAgencia, $agenciaDesc,
                $monEnt, $monto, $montoEnviado, $montoEnviadoBob, $totalCambio,
                $comisionBob, $itfBobCpl, $fecRegOpe,
                $clienteNombre, $destinatario, $idDestino, $idOperacionLog,
                $comisionData, $montoEfectivoBob, $cuentasBancoPago
            );

            // 12. Compra USD (cuando el cliente trae USD)
            $compraData = null;
            if ($compraUsd && $idMonedaRecibida === 1) {
                $compraData = $this->registrarCompraUsd(
                    $codUsuario, $loginUsuario, $idAgencia,
                    $cliente, $montoEnviado, $fecRegOpe,
                    $idUsuarioFinanciero, $nitCompra
                );
            }

            $monEntAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monRec]);
            $destinoDesc = DB::scalar("SELECT descripcion FROM destino WHERE id_destino = :d", [':d' => $idDestino]);

            $monRecAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $idMonedaRecibida]);

            $destNombre = mb_strtoupper(trim(
                ($destinatario['nombres'] ?? '') . ' ' .
                ($destinatario['ape_pat'] ?? '') . ' ' .
                ($destinatario['ape_mat'] ?? '')
            ));

            $formaPagoChileDesc = $idFormaPagoChile === 2 ? 'Depósito a Cuenta Bancaria' : 'Efectivo por Ventanilla';
            $bancoChileNombre = $idBancoChile ? DB::scalar("SELECT nombre FROM pos_banco_chile WHERE id = :id", [':id' => $idBancoChile]) : null;

            $this->encolarNotificacionesRemesa(
                (int) $idRemesa, $codigo, $clienteNombre, $destNombre,
                $montoEnviado, $monEntAbr, $totalClpCalc, $comisionBob, $totalBob,
                $agenciaDesc, $destinoDesc, $formaPagoChileDesc, $bancoChileNombre, $cuentaDestinoChile,
                trim($cliente['email'] ?? ''), trim($destinatario['email'] ?? ''),
                $facturaData
            );

            return [
                'success' => true,
                'message' => 'Remesa registrada correctamente.',
                'data'    => [
                    'id_remesa'         => (int) $idRemesa,
                    'codigo'            => $codigo,
                    'correlativo'       => $correlativo,
                    'id_operacion'      => $idOperacion,
                    'moneda_enviada'    => $monEntAbr,
                    'moneda_enviada_abr' => $monEntAbr,
                    'moneda_recibida_abr' => $monRecAbr,
                    'mon_rec'           => $monRec,
                    'mon_ent'           => $monEnt,
                    'monto'             => $montoEnviado,
                    'destino'           => $destinoDesc,
                    'comision_bob'      => $comisionBob,
                    'total_bob'         => $totalBob,
                    'total_clp'         => $totalClp,
                    'tipo_cambio_clp'   => $tipoCambioClp,
                    'fecha'             => $fecRegOpe,
                    'agencia'           => $agenciaDesc,
                    'cliente_nombre'    => $clienteNombre,
                    'destinatario_nombre' => $destNombre,
                    'destinatario_doc'  => trim($destinatario['num_doc_identidad_d'] ?? ''),
                    'destinatario_cuenta' => trim($destinatario['cuenta'] ?? ''),
                    'pago_comision'     => $pagoComision,
                    'forma_pago_chile'  => $formaPagoChileDesc,
                    'banco_chile'       => $bancoChileNombre,
                    'cuenta_destino_chile' => $cuentaDestinoChile ?: null,
                    'factura'           => $facturaData,
                    'compra_usd'        => $compraData,
                    'cod_agencia'       => $codAgencia,
                    'login_usuario'     => $loginUsuario,
                    'corresponsal_bob'  => $corresponsalBob,
                    'comision_gambarte_bob' => $gambarteBob,
                    'iva_bob'           => $ivaBob,
                    'concepto'          => $destino_pcc,
                    'equivalente_usd'   => $comisionData['equivalente_usd'] ?? null,
                ],
            ];
        });
    }

    public function obtenerComprobante(int $idRemesa): ?array
    {
        return DB::selectOne("
            SELECT r.*,
                uf.nombres, uf.ape_pat, uf.ape_mat,
                d.nombres AS dest_nombres, d.ape_pat AS dest_ape_pat, d.ape_mat AS dest_ape_mat,
                d.cuenta AS dest_cuenta, d.id_banco_chile AS dest_id_banco,
                m_rec.abreviatura AS moneda_recibida,
                m_ent.abreviatura AS moneda_enviada,
                a.descripcion AS agencia,
                dest.descripcion AS destino_desc,
                pbc.nombre AS banco_chile_nombre
            FROM remesa r
            JOIN usuario_financiero uf ON uf.id = r.usuario_financiero_id
            JOIN destinatario d ON d.id_destinatario = r.id_destinatario
            JOIN moneda m_rec ON m_rec.id_moneda = r.mon_rec
            JOIN moneda m_ent ON m_ent.id_moneda = r.mon_ent
            JOIN agencia a ON a.id_agencia = r.id_agencia
            LEFT JOIN destino dest ON dest.id_destino = r.id_destino
            LEFT JOIN pos_banco_chile pbc ON pbc.id = d.id_banco_chile
            WHERE r.id_remesa = :id
        ", [':id' => $idRemesa]);
    }

    // ─── Historial de remesas (cajero) ──────────────────────────────────

    public function historialRemesas(int $codUsuario, int $idAgencia, string $desde, string $hasta, ?int $estado = null, ?string $codigo = null): array
    {
        $params = [':usr' => $codUsuario, ':ag' => $idAgencia, ':desde' => $desde, ':hasta' => $hasta];
        $where = "r.cod_usuario = :usr AND r.id_agencia = :ag AND DATE(r.fec_crea_remesa) BETWEEN :desde AND :hasta";

        if ($estado !== null) {
            $where .= " AND r.estado = :estado";
            $params[':estado'] = $estado;
        }
        if ($codigo !== null && $codigo !== '') {
            $where .= " AND r.codigo = :codigo";
            $params[':codigo'] = $codigo;
        }

        $sql = "SELECT r.id_remesa, r.codigo, r.monto, r.tipo_cambio, r.total_cambio,
                       r.comision, r.comision_bob, r.total_bob, r.fec_crea_remesa, r.estado, r.correlativo,
                       r.mon_rec, r.mon_ent, r.subtipo, r.id_forma_pago_chile, r.pago_comision,
                       (SELECT p.id_pago FROM pago p
                        WHERE p.codigo = r.codigo
                          AND p.id_tipo_pago = 1
                          AND p.id_operacion IN (4, 7, 8, 16)
                        ORDER BY p.fec_crea_pago DESC LIMIT 1) AS id_pago,
                       CASE
                           WHEN r.mon_ent = 2 THEN ROUND(
                               r.comision_bob / NULLIF(CASE WHEN r.pago_comision = 1 THEN r.total_bob ELSE r.monto END, 0) * 100,
                               2
                           )
                           WHEN r.mon_ent = 1 THEN ROUND(
                               r.comision * COALESCE((
                                   SELECT cof.valor FROM cotizacion cof
                                   WHERE cof.id_moneda = r.mon_ent AND cof.id_operacion = 2
                                     AND CAST(cof.fec_crea_cotizacion AS DATE) <= DATE(r.fec_crea_remesa)
                                   ORDER BY cof.fec_crea_cotizacion DESC LIMIT 1
                               ), r.tipo_cambio) / NULLIF(r.monto * r.tipo_cambio, 0) * 100, 2)
                           ELSE ROUND(r.comision / NULLIF(r.monto, 0) * 100, 2)
                       END AS porcentaje_comision,
                       CASE
                           WHEN r.mon_rec = 2 AND r.pago_comision = 1
                               THEN ROUND(r.total_bob - r.comision_bob, 2)
                           WHEN r.mon_rec = 2
                               THEN r.monto
                           ELSE ROUND(r.monto * r.tipo_cambio, 2)
                       END AS monto_en_bob,
                       r.comision_gambarte_bob, r.gastos_corresponsal_bob, r.iva_bob,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = r.mon_rec) AS moneda,
                       CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) AS remitente,
                       CONCAT(d.nombres, ' ', d.ape_pat, ' ', d.ape_mat) AS destinatario,
                       ds.descripcion AS destino
                FROM remesa r
                LEFT JOIN (
                    SELECT DISTINCT num_doc_identidad, id_expedicion, id_tipo_doc_identidad,
                           nombres, ape_pat, ape_mat
                    FROM usuario_financiero
                ) uf ON uf.num_doc_identidad = r.num_doc_identidad
                     AND uf.id_expedicion = r.id_expedicion
                     AND uf.id_tipo_doc_identidad = r.id_tipo_doc_identidad
                LEFT JOIN destinatario d ON d.id_destinatario = r.id_destinatario
                LEFT JOIN destino ds ON ds.id_destino = r.id_destino
                WHERE {$where}
                ORDER BY r.fec_crea_remesa DESC";

        $rows = DB::select($sql, $params);
        $estadoMap = [0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO', 5 => 'ANULADO'];

        return array_map(function ($r) use ($estadoMap) {
            return [
                'id'            => (int) $r['id_remesa'],
                'codigo'        => (int) $r['codigo'],
                'correlativo'   => $r['correlativo'],
                'remitente'     => $r['remitente'],
                'destinatario'  => $r['destinatario'],
                'destino'       => $r['destino'],
                'moneda'        => $r['moneda'],
                'monto'         => round((float) $r['monto'], 2),
                'tipoCambio'    => (float) $r['tipo_cambio'],
                'totalCambio'   => round((float) $r['total_cambio'], 2),
                'montoBob'      => round((float) ($r['monto_en_bob'] ?? 0), 2),
                'comision'      => round((float) $r['comision'], 2),
                'comisionBob'   => round((float) $r['comision_bob'], 2),
                'porcentajeComision' => round((float) ($r['porcentaje_comision'] ?? 0), 2),
                'comisionGambarteBob' => round((float) ($r['comision_gambarte_bob'] ?? 0), 2),
                'gastosCorresponsalBob' => round((float) ($r['gastos_corresponsal_bob'] ?? 0), 2),
                'ivaBob'        => round((float) ($r['iva_bob'] ?? 0), 2),
                // `total_bob` contiene registros históricos donde el IVA fue
                // sumado nuevamente. El total operativo correcto es el monto
                // enviado más la comisión total, que ya contempla ese IVA.
                'totalBob'      => round(
                    (float) ($r['monto_en_bob'] ?? 0) + (float) ($r['comision_bob'] ?? 0),
                    2
                ),
                'fecha'         => $r['fec_crea_remesa'],
                'estado'        => (int) $r['estado'],
                'estadoDesc'    => $estadoMap[(int) $r['estado']] ?? 'DESCONOCIDO',
                'subtipo'       => $r['subtipo'],
                'subtipoDesc'   => $r['subtipo'] === 'R' ? 'Remesa Familiar' : 'Giro Internacional',
                'formaPago'     => (int) $r['id_forma_pago_chile'],
                'formaPagoDesc' => (int) $r['id_forma_pago_chile'] === 2 ? 'Depósito' : 'Efectivo',
                'idPago'        => $r['id_pago'] !== null ? (int) $r['id_pago'] : null,
            ];
        }, $rows);
    }

    // ─── Listar cotizaciones (cajero) ────────────────────────────────

    public function listarCotizaciones(int $codUsuario, int $idAgencia, ?string $estado = null): array
    {
        $params = [':usr' => $codUsuario, ':ag' => $idAgencia];
        $where = "c.cod_usuario = :usr AND c.id_agencia = :ag";

        if ($estado !== null && $estado !== '') {
            $where .= " AND c.estado = :estado";
            $params[':estado'] = $estado;
        }

        $sql = "SELECT c.id, c.codigo, c.id_moneda, c.id_destino, c.monto,
                       c.tipo_comision, c.incluir_comision, c.estado, c.valido_hasta,
                       c.aplicada_en, c.created_at,
                       m.abreviatura AS moneda,
                       ds.descripcion AS destino
                FROM pos_cotizacion_remesa c
                LEFT JOIN moneda m ON m.id_moneda = c.id_moneda
                LEFT JOIN destino ds ON ds.id_destino = c.id_destino
                WHERE {$where}
                ORDER BY c.created_at DESC
                LIMIT 200";

        $rows = DB::select($sql, $params);

        return array_map(function ($r) {
            $expirada = $r['estado'] === 'vigente' && strtotime($r['valido_hasta']) < time();
            return [
                'id'              => (int) $r['id'],
                'codigo'          => $r['codigo'],
                'moneda'          => $r['moneda'],
                'destino'         => $r['destino'],
                'monto'           => round((float) $r['monto'], 2),
                'estado'          => $expirada ? 'expirada' : $r['estado'],
                'validoHasta'     => $r['valido_hasta'],
                'aplicadaEn'      => $r['aplicada_en'] ? (int) $r['aplicada_en'] : null,
                'createdAt'       => $r['created_at'],
            ];
        }, $rows);
    }

    // ─── Comprobante PDF (80mm térmico) ────────────────────────────────

    public function comprobantePdf(int $idRemesa): array
    {
        $r = DB::selectOne("
            SELECT r.*,
                uf.nombres, uf.ape_pat, uf.ape_mat,
                uf.celular AS rem_celular, uf.domicilio AS rem_domicilio,
                td.abreviatura AS tipo_doc_abr,
                ex.abreviatura AS expedicion_abr,
                d.nombres AS dest_nombres, d.ape_pat AS dest_ape_pat, d.ape_mat AS dest_ape_mat,
                d.celular AS dest_celular, d.domicilio AS dest_domicilio,
                d.cuenta AS dest_cuenta, d.id_banco_chile AS dest_id_banco,
                m_rec.abreviatura AS moneda_recibida,
                m_ent.abreviatura AS moneda_enviada,
                a.descripcion AS agencia, a.cod_agencia,
                dest.descripcion AS destino_desc,
                u.login_usu AS login_usuario,
                fpc.descripcion AS forma_pago_chile_desc,
                pbc.nombre AS banco_chile_nombre
            FROM remesa r
            JOIN usuario_financiero uf ON uf.id = r.usuario_financiero_id
            JOIN destinatario d ON d.id_destinatario = r.id_destinatario
            JOIN moneda m_rec ON m_rec.id_moneda = r.mon_rec
            JOIN moneda m_ent ON m_ent.id_moneda = r.mon_ent
            JOIN agencia a ON a.id_agencia = r.id_agencia
            LEFT JOIN destino dest ON dest.id_destino = r.id_destino
            LEFT JOIN tipo_doc_identidad td ON td.id_tipo_doc_identidad = r.id_tipo_doc_identidad
            LEFT JOIN expedicion ex ON ex.id_expedicion = r.id_expedicion
            LEFT JOIN usuario u ON u.cod_usuario = r.cod_usuario
            LEFT JOIN pos_forma_pago_chile fpc ON fpc.id = r.id_forma_pago_chile
            LEFT JOIN pos_banco_chile pbc ON pbc.id = d.id_banco_chile
            WHERE r.id_remesa = :id
        ", [':id' => $idRemesa]);

        if (!$r) {
            abort(404, 'Remesa no encontrada.');
        }

        $logoPath = public_path('images/logo_texto.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }

        $remitente    = mb_strtoupper(trim($r['nombres'] . ' ' . $r['ape_pat'] . ' ' . $r['ape_mat']));
        $destinatario = mb_strtoupper(trim($r['dest_nombres'] . ' ' . $r['dest_ape_pat'] . ' ' . $r['dest_ape_mat']));
        $docRemitente = trim(($r['tipo_doc_abr'] ?? '') . ' ' . $r['num_doc_identidad'] . ' ' . ($r['expedicion_abr'] ?? ''));
        $agencia      = $r['agencia'];
        $codAgencia   = $r['cod_agencia'] ?? '';
        $loginUsuario = $r['login_usuario'] ?? '';
        $monedaAbr    = $r['moneda_recibida'];
        $destino      = $r['destino_desc'] ?? '';

        $fecha = $r['fec_crea_remesa'];
        $dt    = new \DateTime($fecha);
        $fechaFmt = $dt->format('d-m-Y');
        $horaFmt  = $dt->format('H:i:s');

        $monto           = (float) $r['monto'];
        $monRec          = (int) $r['mon_rec'];
        $comisionBob     = (float) ($r['comision_bob'] ?? 0);
        $corresponsalBob = (float) ($r['gastos_corresponsal_bob'] ?? 0);
        $ivaBob          = (float) ($r['iva_bob'] ?? 0);
        $totalBob        = (float) ($r['total_bob'] ?? 0);
        $pagoComision    = (int) ($r['pago_comision'] ?? 0);
        // Corrige también remesas antiguas que guardaron el monto bruto aun
        // cuando la comisión estaba incluida.
        if ($pagoComision === 1 && $monRec === 2 && $totalBob > 0) {
            $monto = round($totalBob - $comisionBob, 2);
        }
        $montoCobrado    = $pagoComision === 1 ? $totalBob : $monto;
        $equivalenteUsd  = (float) ($r['total_cambio'] ?? 0);
        $totalClp        = (float) ($r['total_clp'] ?? 0);
        $concepto        = mb_strtoupper(trim($r['destino'] ?? ''));

        $n = function ($v) { return number_format($v, 2, '.', ','); };

        $opRows = '';
        $opRows .= "<tr><td class='lb'>DESTINO</td><td class='vr'>CHILE - {$destino}</td></tr>";
        $opRows .= "<tr><td class='lb'>POR ENTREGAR [{$monedaAbr}]</td><td class='vr'>{$n($monto)}</td></tr>";
        $opRows .= "<tr><td class='lb'>RECIBIDO [{$monedaAbr}]</td><td class='vr'>{$n($montoCobrado)}</td></tr>";
        $opRows .= "<tr><td class='lb'>COMISION [BOB]</td><td class='vr'>{$n($comisionBob)}</td></tr>";
        $opRows .= "<tr><td class='lb'>CORRESPONSAL [BOB]</td><td class='vr'>{$n($corresponsalBob)}</td></tr>";
        $opRows .= "<tr><td class='lb'>IVA [BOB]</td><td class='vr'>{$n($ivaBob)}</td></tr>";

        if ($monRec === 2 && $equivalenteUsd > 0) {
            $opRows .= "<tr><td class='lb'>EQUIVALENTE [USD]*</td><td class='vr'>{$n($equivalenteUsd)}</td></tr>";
        }
        if ($monRec === 2 && $totalClp > 0) {
            $opRows .= "<tr><td class='lb'>EQUIVALENTE [CLP]*</td><td class='vr'>{$n($totalClp)}</td></tr>";
        }

        $remDomicilio = mb_strtoupper(trim($r['rem_domicilio'] ?? ''));
        $remCelular   = trim($r['rem_celular'] ?? '');
        $destDomicilio = mb_strtoupper(trim($r['dest_domicilio'] ?? ''));
        $destCelular   = trim($r['dest_celular'] ?? '');

        $remRows  = "<tr><td class='lb'>DOCUMENTO</td><td>{$docRemitente}</td></tr>";
        if ($remCelular)   $remRows .= "<tr><td class='lb'>TELEFONO</td><td>{$remCelular}</td></tr>";
        if ($remDomicilio) $remRows .= "<tr><td class='lb'>DIRECCION</td><td>{$remDomicilio}</td></tr>";

        $formaPagoChile = mb_strtoupper(trim($r['forma_pago_chile_desc'] ?? ''));
        $bancoChile     = mb_strtoupper(trim($r['banco_chile_nombre'] ?? ''));
        $cuentaDest     = trim($r['dest_cuenta'] ?? '');

        $destRows = "<tr><td class='lb'>NOMBRE</td><td>{$destinatario}</td></tr>";
        if ($destCelular)   $destRows .= "<tr><td class='lb'>TELEFONO</td><td>{$destCelular}</td></tr>";
        if ($destDomicilio) $destRows .= "<tr><td class='lb'>DIRECCION</td><td>{$destDomicilio}</td></tr>";
        if ($formaPagoChile) $destRows .= "<tr><td class='lb'>FORMA DE PAGO</td><td>{$formaPagoChile}</td></tr>";
        if ($bancoChile)     $destRows .= "<tr><td class='lb'>BANCO</td><td>{$bancoChile}</td></tr>";
        if ($cuentaDest)     $destRows .= "<tr><td class='lb'>NRO. CUENTA</td><td>{$cuentaDest}</td></tr>";

        $codigo = $r['codigo'];

        $correlativo = str_pad($r['correlativo'] ?? $codigo, 7, '0', STR_PAD_LEFT);

        $qrContent = "https://www.gambarte.com.bo/comprobante/{$codigo}";
        $qrBase64 = $this->generarQrBase64($qrContent);

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  @page { size: 72mm 350mm; margin: 2mm; }
  body { font-family: 'Courier New', Courier, monospace; font-size: 11px; margin: 0; padding: 0; color: #000; line-height: 1.3; width: 68mm; word-wrap: break-word; overflow-wrap: break-word; }
  .center { text-align: center; }
  .bold { font-weight: bold; }
  .logo { width: 48mm; display: block; margin: 0 auto 2px; }
  .site { font-size: 10px; text-align: center; margin: 1px 0; }
  .phone { font-size: 10px; text-align: center; margin-bottom: 2px; }
  .title { font-size: 14px; font-weight: bold; text-align: center; margin: 4px 0; letter-spacing: 1px; }
  .sep { border-top: 1px solid #000; margin: 2px 0; }
  .subtitle { text-align: center; font-size: 10px; font-weight: bold; margin: 2px 0; }
  .section-hdr { text-align: center; font-weight: bold; font-size: 11px; border-bottom: 1px solid #000; margin: 4px 0 1px; padding-bottom: 1px; }
  .center-name { text-align: center; font-weight: bold; margin: 1px 0; font-size: 11px; }
  .t { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 11px; }
  .t td { padding: 1px 0; vertical-align: top; word-wrap: break-word; }
  .t .lb { font-weight: bold; text-align: left; width: 50%; white-space: nowrap; }
  .t .vr { text-align: right; }
  .t4 { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 2px; }
  .t4 td { padding: 1px 0; vertical-align: top; }
  .t4 .lb { font-weight: bold; text-align: left; }
  .concepto { font-size: 11px; margin: 2px 0; text-transform: uppercase; }
  .sign-tbl { width: 100%; margin-top: 35px; font-size: 11px; border-collapse: collapse; }
  .sign-tbl td { text-align: center; width: 50%; vertical-align: bottom; padding: 0 4px; }
  .sign-line { border-bottom: 1px solid #000; height: 1px; margin-bottom: 2px; }
  .nota { font-size: 8px; margin-top: 3px; text-align: center; }
  .nota strong { text-decoration: underline; }
  .codigo-row { table-layout: fixed; width: 100%; border-collapse: collapse; margin: 2px 0; }
  .codigo-row td { vertical-align: middle; width: 50%; }
  .codigo-qr { text-align: right; padding-right: 2mm; }
  .codigo-qr img { width: 23mm; height: 23mm; }
  .codigo-txt { text-align: left; padding-left: 2mm; font-weight: bold; }
  .codigo-txt .lbl { font-size: 12px; font-style: italic; text-decoration: underline; }
  .codigo-txt .num { font-size: 22px; }
</style>
</head><body>
  <div class="center">
    <img src="{$logoBase64}" class="logo" alt="Gambarte">
    <div class="site">www.gambarte.com.bo</div>
    <div class="phone">TELEFONO DE CONTACTO: 68355517</div>
    <div class="title">COMPROBANTE DE ENVÍO</div>
  </div>
  <div class="sep"></div>

  <table class="codigo-row">
    <tr>
      <td class="codigo-qr"><img src="{$qrBase64}" alt="QR"></td>
      <td class="codigo-txt"><div class="lbl">CODIGO</div><div class="num">{$codigo}</div></td>
    </tr>
  </table>

  <div class="sep"></div>
  <div class="subtitle">GIRO INTERNACIONAL BOLIVIA - AGENCIA<br>{$agencia}</div>
  <div class="sep"></div>

  <table class="t4">
    <tr>
      <td class="lb" style="width:18%;">FECHA</td>
      <td style="width:32%;">{$fechaFmt}</td>
      <td class="lb" style="width:15%;">HORA</td>
      <td style="width:35%;">{$horaFmt}</td>
    </tr>
    <tr>
      <td class="lb">CAGR</td>
      <td>{$correlativo}</td>
      <td class="lb">OPE</td>
      <td>{$loginUsuario}</td>
    </tr>
  </table>
  <div class="sep"></div>

  <div class="section-hdr">DATOS DEL REMITENTE</div>
  <div class="center-name">{$remitente}</div>
  <table class="t">{$remRows}</table>
  <div class="sep"></div>

  <div class="section-hdr">DATOS DEL DESTINATARIO</div>
  <table class="t">{$destRows}</table>
  <div class="sep"></div>

  <div class="section-hdr">DATOS DE LA OPERACION</div>
  <table class="t">{$opRows}</table>
  <div class="sep"></div>

  <div class="section-hdr">CONCEPTO</div>
  <div class="concepto">{$concepto}</div>
  <div class="sep"></div>

  <table class="sign-tbl">
    <tr><td><div class="sign-line">&nbsp;</div><span class="bold">FIRMA CAJERO</span></td>
        <td><div class="sign-line">&nbsp;</div><span class="bold">FIRMA CLIENTE</span></td></tr>
  </table>

  <div class="sep"></div>
  <div class="nota"><strong>NOTA.-</strong> AL FIRMAR ESTE DOCUMENTO SE ACEPTAN LOS TERMINOS Y CONDICIONES DE GAMBARTE S.R.L.</div>
  <div class="nota">(*) Montos Referenciales</div>
</body></html>
HTML;

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Courier');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, 204.09, 992.13], 'portrait');
        $dompdf->render();

        return [
            'binary'   => $dompdf->output(),
            'filename' => "Comprobante_Remesa_{$codigo}.pdf",
        ];
    }

    // ─── Comprobante Envío Chile PDF (80mm térmico) ────────────────────

    public function comprobanteEnvioChilePdf(int $codigo): array
    {
        $r = DB::selectOne("
            SELECT correlativo_pais, codigo, fec_crea_remesa, abr_ori, abr_des,
                   estado, moneda, envio, usuario_financiero, destinatario,
                   doc_des, id_destino, id_operacion
            FROM tmp_reporte_remesa
            WHERE codigo = :c
            LIMIT 1
        ", [':c' => $codigo], 'chile');

        if (!$r) {
            abort(404, 'Remesa no encontrada.');
        }

        $logoPath = public_path('images/logo_texto.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }

        $remitente = mb_strtoupper(trim($r['usuario_financiero'] ?? ''));
        $destinatarioRaw = $r['destinatario'] ?? '';
        $docDes = mb_strtoupper(trim($r['doc_des'] ?? ''));
        $moneda = $r['moneda'] ?? 'BOB';
        $monto = (float) ($r['envio'] ?? 0);
        $origen = mb_strtoupper(trim($r['abr_ori'] ?? ''));
        $destino = mb_strtoupper(trim($r['abr_des'] ?? ''));
        $correlativo = $r['correlativo_pais'] ?? $codigo;

        $destinatarioNombre = $destinatarioRaw;
        $bancoDest = '';
        $cuentaDest = '';
        if (str_contains($destinatarioRaw, '//')) {
            $partes = array_map('trim', explode('//', $destinatarioRaw));
            $destinatarioNombre = $partes[0] ?? $destinatarioRaw;
            $bancoDest = $partes[1] ?? '';
            $cuentaDest = $partes[2] ?? '';
        }
        $destinatarioNombre = mb_strtoupper(trim($destinatarioNombre));

        $agencia = '';
        if (!empty($r['id_destino'])) {
            $ag = DB::selectOne("SELECT descripcion FROM agencia WHERE id_agencia = :id", [':id' => (int) $r['id_destino']]);
            $agencia = $ag ? $ag['descripcion'] : '';
        }

        $fecha = $r['fec_crea_remesa'] ?? '';
        $fechaFmt = '';
        $horaFmt = '';
        if ($fecha) {
            $dt = new \DateTime($fecha);
            $fechaFmt = $dt->format('d-m-Y');
            $horaFmt = $dt->format('H:i:s');
        }

        $n = function ($v) { return number_format($v, 2, '.', ','); };

        $opRows = '';
        $opRows .= "<tr><td class='lb'>ORIGEN</td><td class='vr'>CHILE - {$origen}</td></tr>";
        $opRows .= "<tr><td class='lb'>DESTINO</td><td class='vr'>BOLIVIA - {$destino}</td></tr>";
        $opRows .= "<tr><td class='lb'>POR ENTREGAR [{$moneda}]</td><td class='vr'>{$n($monto)}</td></tr>";

        $destRows = "<tr><td class='lb'>NOMBRE</td><td>" . mb_strtoupper($destinatarioNombre) . "</td></tr>";
        if ($docDes) $destRows .= "<tr><td class='lb'>DOCUMENTO</td><td>{$docDes}</td></tr>";
        if ($bancoDest) $destRows .= "<tr><td class='lb'>BANCO</td><td>" . mb_strtoupper($bancoDest) . "</td></tr>";
        if ($cuentaDest) $destRows .= "<tr><td class='lb'>NRO. CUENTA</td><td>" . mb_strtoupper($cuentaDest) . "</td></tr>";

        $qrContent = "https://www.gambarte.com.bo/comprobante/{$codigo}";
        $qrBase64 = $this->generarQrBase64($qrContent);

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  @page { size: 72mm 300mm; margin: 2mm; }
  body { font-family: 'Courier New', Courier, monospace; font-size: 11px; margin: 0; padding: 0; color: #000; line-height: 1.3; width: 68mm; word-wrap: break-word; overflow-wrap: break-word; }
  .center { text-align: center; }
  .bold { font-weight: bold; }
  .logo { width: 48mm; display: block; margin: 0 auto 2px; }
  .site { font-size: 10px; text-align: center; margin: 1px 0; }
  .phone { font-size: 10px; text-align: center; margin-bottom: 2px; }
  .title { font-size: 14px; font-weight: bold; text-align: center; margin: 4px 0; letter-spacing: 1px; }
  .sep { border-top: 1px solid #000; margin: 2px 0; }
  .subtitle { text-align: center; font-size: 10px; font-weight: bold; margin: 2px 0; }
  .section-hdr { text-align: center; font-weight: bold; font-size: 11px; border-bottom: 1px solid #000; margin: 4px 0 1px; padding-bottom: 1px; }
  .center-name { text-align: center; font-weight: bold; margin: 1px 0; font-size: 11px; }
  .t { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 11px; }
  .t td { padding: 1px 0; vertical-align: top; word-wrap: break-word; }
  .t .lb { font-weight: bold; text-align: left; width: 50%; white-space: nowrap; }
  .t .vr { text-align: right; }
  .t4 { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 2px; }
  .t4 td { padding: 1px 0; vertical-align: top; }
  .t4 .lb { font-weight: bold; text-align: left; }
  .codigo-row { table-layout: fixed; width: 100%; border-collapse: collapse; margin: 2px 0; }
  .codigo-row td { vertical-align: middle; width: 50%; }
  .codigo-qr { text-align: right; padding-right: 2mm; }
  .codigo-qr img { width: 23mm; height: 23mm; }
  .codigo-txt { text-align: left; padding-left: 2mm; font-weight: bold; }
  .codigo-txt .lbl { font-size: 12px; font-style: italic; text-decoration: underline; }
  .codigo-txt .num { font-size: 22px; }
  .nota { font-size: 8px; margin-top: 3px; text-align: center; }
  .nota strong { text-decoration: underline; }
</style>
</head><body>
  <div class="center">
    <img src="{$logoBase64}" class="logo" alt="Gambarte">
    <div class="site">www.gambarte.com.bo</div>
    <div class="phone">TELEFONO DE CONTACTO: 68355517</div>
    <div class="title">COMPROBANTE DE ENVÍO</div>
  </div>
  <div class="sep"></div>

  <table class="codigo-row">
    <tr>
      <td class="codigo-qr"><img src="{$qrBase64}" alt="QR"></td>
      <td class="codigo-txt"><div class="lbl">CODIGO</div><div class="num">{$codigo}</div></td>
    </tr>
  </table>

  <div class="sep"></div>
  <div class="subtitle">GIRO INTERNACIONAL CHILE - BOLIVIA<br>{$agencia}</div>
  <div class="sep"></div>

  <table class="t4">
    <tr>
      <td class="lb" style="width:18%;">FECHA</td>
      <td style="width:32%;">{$fechaFmt}</td>
      <td class="lb" style="width:15%;">HORA</td>
      <td style="width:35%;">{$horaFmt}</td>
    </tr>
    <tr>
      <td class="lb">CORR</td>
      <td>{$correlativo}</td>
      <td></td>
      <td></td>
    </tr>
  </table>
  <div class="sep"></div>

  <div class="section-hdr">DATOS DEL REMITENTE</div>
  <div class="center-name">{$remitente}</div>
  <div class="sep"></div>

  <div class="section-hdr">DATOS DEL DESTINATARIO</div>
  <table class="t">{$destRows}</table>
  <div class="sep"></div>

  <div class="section-hdr">DATOS DE LA OPERACION</div>
  <table class="t">{$opRows}</table>
  <div class="sep"></div>

  <div class="nota"><strong>NOTA.-</strong> AL FIRMAR ESTE DOCUMENTO SE ACEPTAN LOS TERMINOS Y CONDICIONES DE GAMBARTE S.R.L.</div>
</body></html>
HTML;

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Courier');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, 204.09, 850.39], 'portrait');
        $dompdf->render();

        return [
            'binary'   => $dompdf->output(),
            'filename' => "Comprobante_Envio_{$codigo}.pdf",
        ];
    }

    // ─── Factura PDF (80mm térmico) ─────────────────────────────────────

    public function facturaPdf(int $idRemesa): array
    {
        $facturado = DB::selectOne("
            SELECT f.*, a.descripcion AS agencia,
                   a.telefono AS agencia_telefono
            FROM facturado f
            JOIN agencia a ON a.id_agencia = f.id_agencia
            WHERE f.id_operacion IN (4, 16) AND f.transaccion = :id
            LIMIT 1
        ", [':id' => $idRemesa]);

        if (!$facturado) {
            abort(404, 'Factura no encontrada para esta remesa.');
        }

        $feFactura = DB::selectOne("
            SELECT ff.cuf, ff.numero_factura, ff.estado, ff.tipo_emision, ff.created_at,
                   fl.descripcion AS leyenda
            FROM fe_factura ff
            LEFT JOIN fe_leyenda fl ON fl.id_leyenda = ff.id_leyenda
            WHERE ff.id_operacion = :id AND ff.id_tipo_operacion IN (4, 16)
            ORDER BY ff.created_at DESC LIMIT 1
        ", [':id' => $idRemesa]);

        $remesa = DB::selectOne("
            SELECT r.codigo, r.cod_usuario, r.id_agencia,
                   u.login_usu AS operador
            FROM remesa r
            LEFT JOIN usuario u ON u.cod_usuario = r.cod_usuario
            WHERE r.id_remesa = :id
        ", [':id' => $idRemesa]);

        $sucursalPv = DB::selectOne("
            SELECT fs.sucursal AS sucursal_num, fpv.codigo_punto_venta AS pv_num,
                   fs.direccion, fs.municipio
            FROM fe_punto_venta fpv
            INNER JOIN fe_sucursal fs ON fs.id_sucursal = fpv.id_sucursal
            INNER JOIN agencia a ON a.id_agencia = fs.id_agencia
            WHERE a.id_agencia = :ag
            ORDER BY fpv.activo DESC LIMIT 1
        ", [':ag' => $facturado['id_agencia']]);

        $tcOficial = $this->getCotizacionOficialVenta(1);

        $logoPath = public_path('images/logo_texto.png');
        $logoBase64 = file_exists($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
            : '';

        $nitEmpresa  = '335846029';
        $razonSocial = 'GIROS Y REMESAS DE DINERO GAMBARTE BOLIVIA S.R.L.';

        $numero        = $facturado['numero'] ?? '';
        $nitCliente    = $facturado['nit'] ?? '';
        $nombreCliente = $facturado['nombre'] ?? '';
        $detalle       = $facturado['detalle'] ?? '';
        $importe       = (float) ($facturado['importe'] ?? 0);
        $cuf           = $feFactura['cuf'] ?? ($facturado['codigo_control'] ?? '');
        $leyenda       = $feFactura['leyenda'] ?? '';
        $telefono      = $facturado['agencia_telefono'] ?? '';
        $direccion     = $sucursalPv['direccion'] ?? '';
        $sucursal      = $sucursalPv['sucursal_num'] ?? '0';
        $puntoVenta    = $sucursalPv['pv_num'] ?? '0';
        $operador      = $remesa['operador'] ?? '';
        $transaccion   = str_pad($idRemesa, 7, '0', STR_PAD_LEFT);

        $fechaEmision = $facturado['fecha'] . ' ' . $facturado['hora'];

        $servRemesa = DB::selectOne("
            SELECT fs.codigo, fs.nombre
            FROM fe_servicio fs
            WHERE fs.codigo = :cod
            ORDER BY fs.updated_at DESC LIMIT 1
        ", [':cod' => FacturacionService::CODIGO_SERVICIO_REMESA]);
        $servicioDesc = $servRemesa
            ? ($servRemesa['codigo'] . ' - ' . $servRemesa['nombre'])
            : (FacturacionService::CODIGO_SERVICIO_REMESA . ' - COMISIÓN POR REMESA');

        $complemento = '';
        $codigoCliente = $this->generarCodigoCliente($nombreCliente, $nitCliente);

        $n = function ($v) { return number_format($v, 2, ',', '.'); };
        $montoEnLetras = $this->montoALetras($importe);

        $qrContent = "https://piloto.siat.impuestos.gob.bo/consulta/QR?nit={$nitEmpresa}&cuf={$cuf}&numero={$numero}&t=2";
        $qrBase64 = $this->generarQrBase64($qrContent);

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  @page { size: 72mm 400mm; margin: 2mm; }
  body { font-family: 'Courier New', Courier, monospace; font-size: 11px; margin: 0; padding: 0; color: #000; line-height: 1.4; width: 68mm; word-wrap: break-word; overflow-wrap: break-word; }
  .center { text-align: center; }
  .bold { font-weight: bold; }
  .logo { width: 50mm; display: block; margin: 0 auto 3px; }
  .sep { border-top: 1px solid #000; margin: 3px 0; }
  .title { font-size: 15px; font-weight: bold; text-align: center; margin: 6px 0 2px; }
  .subtitle { font-size: 11px; text-align: center; margin: 1px 0; }
  .row { margin: 0; font-size: 11px; line-height: 1.4; }
  .row .lbl { font-weight: bold; }
  .right { text-align: right; }
  .totals { width: 100%; font-size: 11px; border-collapse: collapse; }
  .totals td { padding: 0; line-height: 1.5; }
  .totals .lbl { text-align: right; padding-right: 6px; }
  .totals .val { text-align: right; font-weight: bold; width: 50px; }
  .detail-title { text-align: center; font-weight: bold; font-size: 12px; margin: 4px 0 2px; border-bottom: 1px solid #000; padding-bottom: 2px; }
  .legal { font-size: 9px; text-align: center; margin: 3px 0; line-height: 1.3; }
  .info { table-layout: fixed; width: 100%; }
  .info-lbl { font-weight: bold; vertical-align: top; text-align: right; padding-right: 3px; width: 50%; overflow: hidden; }
  .info td { vertical-align: top; }
  .info .info-val { width: 50%; word-wrap: break-word; word-break: break-all; overflow-wrap: break-word; }
  .footer-row { font-size: 9px; margin: 1px 0; }
  .detail-line { margin: 0; font-size: 11px; }
  .son { font-size: 11px; margin: 1px 0; }
</style>
</head><body>

  <div class="center">
    <img src="{$logoBase64}" class="logo" alt="Gambarte">
  </div>

  <div class="title">FACTURA</div>
  <div class="subtitle">(Con derecho a crédito fiscal)</div>
  <div class="subtitle">Sucursal No. {$sucursal}</div>
  <div class="subtitle">No. Punto de Venta {$puntoVenta}</div>
  <div class="subtitle">Tel. {$telefono}</div>
  <div class="sep"></div>

  <table class="info" style="font-size:11px;border-collapse:collapse;">
    <tr><td class="info-lbl" style="font-size:14px;">NIT</td><td class="info-val bold" style="font-size:14px;">{$nitEmpresa}</td></tr>
    <tr><td class="info-lbl" style="font-size:12px;">FACTURA N°</td><td class="info-val bold" style="font-size:12px;">{$numero}</td></tr>
    <tr><td class="info-lbl">CÓD AUTORIZACIÓN</td><td class="info-val bold" style="font-size:9px;">{$cuf}</td></tr>
  </table>
  <div class="sep"></div>

  <table class="info" style="font-size:11px;border-collapse:collapse;">
    <tr><td class="info-lbl">NOMBRE/RAZÓN SOCIAL:</td><td class="info-val">{$nombreCliente}</td></tr>
    <tr><td class="info-lbl">NIT/CI/CEX:</td><td class="info-val">{$nitCliente}</td></tr>
    <tr><td class="info-lbl">COMPLEMENTO:</td><td class="info-val">{$complemento}</td></tr>
    <tr><td class="info-lbl">COD. CLIENTE:</td><td class="info-val">{$codigoCliente}</td></tr>
    <tr><td class="info-lbl">FECHA EMISIÓN:</td><td class="info-val">{$fechaEmision}</td></tr>
  </table>

  <div class="detail-title">DETALLE</div>
  <div class="detail-line"><strong>{$servicioDesc}</strong></div>
  <div class="detail-line">Unidad de medida: UNIDAD (SERVICIOS)</div>
  <div class="detail-line">1,00 X {$n($importe)} - 0,00 <span style="float:right;">{$n($importe)}</span></div>
  <table class="totals">
    <tr><td colspan="2"><div class="sep" style="margin:2px 0;"></div></td></tr>
    <tr><td class="lbl">SUBTOTAL Bs</td><td class="val">{$n($importe)}</td></tr>
    <tr><td class="lbl">MONTO ARRENDAMIENTO FINANCIERO Bs</td><td class="val">0,00</td></tr>
    <tr><td class="lbl">DESCUENTO Bs</td><td class="val">0,00</td></tr>
    <tr><td class="lbl">MONTO GIFT CARD Bs</td><td class="val">0,00</td></tr>
    <tr><td class="lbl" style="font-weight:bold;">TOTAL Bs</td><td class="val">{$n($importe)}</td></tr>
    <tr><td class="lbl">IMPORTE BASE CRÉDITO FISCAL Bs</td><td class="val">{$n($importe)}</td></tr>
  </table>
  <div class="son"><span class="bold">SON:</span> {$montoEnLetras}</div>

  <div class="sep"></div>
  <div class="legal">Esta factura contribuye al desarrollo del país, el uso ilícito será sancionado penalmente de acuerdo a Ley.</div>
  <div class="legal" style="font-style:italic;">{$leyenda}</div>
  <div class="legal">Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea.</div>

  <div class="center" style="margin:8px 0;"><img src="{$qrBase64}" style="width:38mm;height:38mm;" alt="QR"></div>

  <div class="legal">Por favor ingrese al siguiente link<br><strong>https://www.gambarte.com.bo/facturas</strong><br>para consultar la Representación Gráfica y XML de sus Facturas Electrónicas</div>
  <div class="sep"></div>

  <div class="footer-row" style="display:table;width:100%;">
    <span style="display:table-cell;">TC OFICIAL USD: BOB {$n($tcOficial)}</span>
    <span style="display:table-cell;text-align:right;">Transacción: {$transaccion}</span>
  </div>
  <div class="footer-row" style="text-align:right;">OPERADOR: {$operador}</div>

</body></html>
HTML;

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Courier');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, 204.09, 1133.86], 'portrait');
        $dompdf->render();

        return [
            'binary'   => $dompdf->output(),
            'filename' => "Factura_Remesa_{$numero}.pdf",
        ];
    }

    private function generarQrBase64(string $content): string
    {
        $qrCode = \BaconQrCode\Encoder\Encoder::encode(
            $content,
            \BaconQrCode\Common\ErrorCorrectionLevel::L(),
            'UTF-8'
        );
        $matrix = $qrCode->getMatrix();
        $matrixWidth = $matrix->getWidth();
        $scale = 6;
        $margin = 2;
        $imgSize = ($matrixWidth + $margin * 2) * $scale;

        $im = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefill($im, 0, 0, $white);

        for ($y = 0; $y < $matrixWidth; $y++) {
            for ($x = 0; $x < $matrixWidth; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $px = ($x + $margin) * $scale;
                    $py = ($y + $margin) * $scale;
                    imagefilledrectangle($im, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function generarCodigoCliente(string $nombre, string $documento): string
    {
        $partes = explode(' ', trim($nombre));
        $iniciales = '';
        foreach ($partes as $p) {
            $p = trim($p);
            if ($p !== '' && $p !== 'DE') {
                $iniciales .= mb_strtoupper(mb_substr($p, 0, 1));
            }
        }
        return $iniciales . '-' . $documento;
    }

    private function montoALetras(float $monto): string
    {
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);
        $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $especiales = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE',
            'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        $decenas = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA',
            'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
            'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

        $convertir = function (int $n) use ($unidades, $especiales, $decenas, $centenas): string {
            if ($n === 0) return 'CERO';
            if ($n === 100) return 'CIEN';
            $r = '';
            if ($n >= 100) {
                $r .= $centenas[(int) ($n / 100)] . ' ';
                $n %= 100;
            }
            if ($n >= 20) {
                $d = (int) ($n / 10);
                $u = $n % 10;
                $r .= $decenas[$d];
                if ($u > 0) $r .= ' Y ' . $unidades[$u];
            } elseif ($n >= 10) {
                $r .= $especiales[$n - 10];
            } elseif ($n > 0) {
                $r .= $unidades[$n];
            }
            return trim($r);
        };

        if ($entero === 0) {
            $texto = 'CERO';
        } elseif ($entero < 1000) {
            $texto = $convertir($entero);
        } elseif ($entero < 1000000) {
            $miles = (int) ($entero / 1000);
            $resto = $entero % 1000;
            $texto = ($miles === 1 ? 'UN MIL' : $convertir($miles) . ' MIL');
            if ($resto > 0) $texto .= ' ' . $convertir($resto);
        } else {
            $millones = (int) ($entero / 1000000);
            $resto = $entero % 1000000;
            $texto = ($millones === 1 ? 'UN MILLON' : $convertir($millones) . ' MILLONES');
            if ($resto > 0) {
                $miles = (int) ($resto / 1000);
                $unid = $resto % 1000;
                if ($miles > 0) $texto .= ' ' . ($miles === 1 ? 'UN MIL' : $convertir($miles) . ' MIL');
                if ($unid > 0) $texto .= ' ' . $convertir($unid);
            }
        }

        return $texto . ' ' . str_pad($centavos, 2, '0', STR_PAD_LEFT) . '/100';
    }

    // ─── Contabilidad ─────────────────────────────────────────────────

    private function registrarAsientoContableRemesa(
        int $idRemesa, int $codigo, int $idOperacion, int $idAgencia,
        string $codAgencia, string $agenciaDesc,
        int $monEnt, float $monto, float $montoEnviado, float $montoEnviadoBob,
        float $totalCambio, float $comisionBob, float $itfBobCpl,
        string $fecha, string $clienteNombre, array $destinatario,
        int $idDestino, int $idOperacionLog, array $comisionData,
        float $montoEfectivoBob = 0.0, array $cuentasBancoPago = []
    ): void {
        $codejercicio = DB::scalar(
            "SELECT codejercicio FROM ejercicios WHERE estado = 'ABIERTO' LIMIT 1",
            [], 'conta'
        );
        if (!$codejercicio) {
            $this->encolarAsientoPendienteRemesa($idOperacionLog, $fecha, 'No hay ejercicio contable abierto');
            return;
        }

        $oficial = (float) DB::scalar("
            SELECT co.valor_me FROM cotizacion_oficial co
            WHERE co.id_moneda = 1 AND co.id_operacion = 1 AND co.estado = 'A'
            ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1
        ") ?: 1.0;

        $oficialClp = (float) ($comisionData['tipo_cambio_comercial'] ?? 0);

        $divisa = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monEnt]) ?: 'USD';
        $destinoDesc = DB::scalar("SELECT descripcion FROM destino WHERE id_destino = :d", [':d' => $idDestino]) ?: '';

        $beneficiario = mb_strtoupper(trim(
            ($destinatario['nombres'] ?? '') . ' ' .
            ($destinatario['ape_pat'] ?? '') . ' ' .
            ($destinatario['ape_mat'] ?? '')
        ));

        // ITF real calculation (matching legacy)
        $itfReal = 0;
        $ganancia = 0;
        if ($monEnt === 1) {
            $itfReal = ($monto * $oficial) * 0.003;
            $ganancia = $itfBobCpl - $itfReal;
        } elseif ($monEnt === 2) {
            $itfReal = $monto * 0.003;
            $ganancia = $itfBobCpl - $itfReal;
            if ($ganancia < 0) $comisionBob += $ganancia;
        } elseif ($monEnt === 3) {
            $itfReal = round(round($totalCambio * $oficialClp, 1) * 0.003, 2);
            $ganancia = round($itfBobCpl - $itfReal, 2);
        }

        // Envío en BOB para CLP
        $envioBobClp = $monEnt === 3 ? $totalCambio * $oficialClp : 0;
        $comisionBobClp = $comisionBob;

        $glosa = mb_strtoupper("{$clienteNombre} - {$agenciaDesc} - {$beneficiario} - {$destinoDesc} - ENVIO DE REMESA EXTERIOR #{$codigo} DE {$divisa} " . number_format($montoEnviado, 2, '.', ''));

        try {
            $fechaAsiento = substr($fecha, 0, 10);
            $sigue = (int) DB::scalar(
                "SELECT COALESCE(MAX(numero), 0) FROM co_asientos WHERE codejercicio = :ej",
                [':ej' => $codejercicio], 'conta'
            ) + 1;

            // ─── ASIENTO 1: EC-ER ───
            DB::execute("
                INSERT INTO co_asientos (
                    codejercicio, codplanasiento, concepto, documento, editable,
                    fecha, idasiento, idconcepto, importe, numero, tipodocumento,
                    cod_usuario, fecha_trans, origen
                ) VALUES (
                    :ej, NULL, :concepto, '', 0,
                    :fecha, '', :idconcepto, 0, :numero, 'EC-ER',
                    'CGR', :fecha_t, 'POS'
                )
            ", [
                ':ej'         => $codejercicio,
                ':concepto'   => $glosa,
                ':fecha'      => $fechaAsiento,
                ':idconcepto' => $codAgencia,
                ':numero'     => $sigue,
                ':fecha_t'    => $fecha,
            ], 'conta');

            $idasiento = DB::lastInsertId('conta');

            // P1: Entrada caja (DEBE)
            if ($monEnt === 2) {
                $codsubcuenta = '11101101';
                $debe = round($monto, 2);
                $debeme = round($monto / $oficial, 2);
            } elseif ($monEnt === 1) {
                $codsubcuenta = '11101201';
                $debe = round($monto * $oficial, 2);
                $debeme = round($monto, 2);
            } else { // CLP
                $codsubcuenta = '11101206';
                $debe = round($envioBobClp, 2);
                $debeme = round($envioBobClp / $oficial, 2);
            }

            $this->insertPartida($idasiento, $codejercicio, $codsubcuenta, $codAgencia, $codigo,
                $debe, $debeme, 0, 0, $oficial);

            // P2: Remesa a pagar (HABER)
            if ($monEnt === 2) {
                $haber = round($monto, 2);
                $haberme = round($haber / $oficial, 2);
            } elseif ($monEnt === 1) {
                $haber = round($monto * $oficial, 2);
                $haberme = round($monto, 2);
            } else {
                $haber = round($envioBobClp, 2);
                $haberme = round($haber / $oficial, 2);
            }

            $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_REMESA_INT_ME, $codAgencia, $codigo,
                0, 0, $haber, $haberme, $oficial);

            // P3: Comisión giro remesa exterior (HABER)
            $comisionRemesa = round($comisionBob * 0.87, 2);
            $corresponsalia = DB::selectOne("SELECT com_pag FROM corresponsalia WHERE fec_crea_corresponsalia <= :f ORDER BY fec_crea_corresponsalia DESC LIMIT 1", [':f' => $fecha]);
            $comPag = (float) ($corresponsalia['com_pag'] ?? 1);
            $bolivia = round($comisionRemesa * $comPag, 2);

            if ($monEnt === 3) {
                $haberCom = round($comisionBobClp * 0.87, 2);
                $habermeCom = round($haberCom / $oficial, 2);
            } else {
                $haberCom = $bolivia;
                $habermeCom = round($haberCom / $oficial, 2);
            }

            $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_COMI_GIRO, $codAgencia, $codigo,
                0, 0, $haberCom, $habermeCom, $oficial);

            $comisionGiroHaber = $haberCom;
            $comisionGiroHaberMe = $habermeCom;

            // P4: Comisión por pagar Gambarte Chile (HABER) — only non-CLP
            if ($monEnt !== 3) {
                $codsubcuentaChile = $monEnt === 2 ? '24207201' : '24207101';
                $haberChile = round($comisionRemesa - $bolivia, 2);
                $habermeChile = round($haberChile / $oficial, 2);

                if ($haberChile > 0) {
                    $this->insertPartida($idasiento, $codejercicio, $codsubcuentaChile, $codAgencia, $codigo,
                        0, 0, $haberChile, $habermeChile, $oficial);
                }
            }

            // P5: Débito fiscal IVA (HABER)
            if ($monEnt === 3) {
                $haberIva = round($comisionBobClp * 0.13, 2);
            } else {
                $haberIva = round($comisionBob * 0.13, 2);
            }
            $habermeIva = round($haberIva / $oficial, 2);

            $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_DEBITO_FISCAL_IVA, $codAgencia, $codigo,
                0, 0, $haberIva, $habermeIva, $oficial);

            // P6: Transacción IT (DEBE)
            if ($monEnt === 3) {
                $debeIt = round($comisionBobClp * 0.03, 2);
            } else {
                $debeIt = round($comisionBob * 0.03, 2);
            }
            $debemeIt = round($debeIt / $oficial, 2);

            $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_TRANSACCION, $codAgencia, $codigo,
                $debeIt, $debemeIt, 0, 0, $oficial);

            // P7: Impuesto a transacciones (HABER)
            $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_IMPUESTO_TRANSACCIONES, $codAgencia, $codigo,
                0, 0, $debeIt, $debemeIt, $oficial);

            // P8: Entrada caja por comisión (DEBE) — split por método de pago
            if ($monEnt === 3) {
                $debeCom = round($comisionBobClp + $itfBobCpl, 2);
            } else {
                $debeCom = round($comisionBob + $itfBobCpl, 2);
            }

            $hasMixtoRemesa = $montoEfectivoBob > 0 && !empty($cuentasBancoPago);
            if ($hasMixtoRemesa) {
                $totalPagoMixto = $montoEfectivoBob;
                foreach ($cuentasBancoPago as $cbp) {
                    $totalPagoMixto += $cbp['monto'];
                }
                $ratioEfectivo = $totalPagoMixto > 0 ? $montoEfectivoBob / $totalPagoMixto : 1.0;

                $debeComEfectivo = round($debeCom * $ratioEfectivo, 2);
                if ($debeComEfectivo > 0) {
                    $debemeComEf = round($debeComEfectivo / $oficial, 2);
                    $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_ENTRADA_CAJA_COMISION, $codAgencia, $codigo,
                        $debeComEfectivo, $debemeComEf, 0, 0, $oficial);
                }

                $debeComBancoAcum = $debeComEfectivo;
                foreach ($cuentasBancoPago as $idx => $cbp) {
                    if ($idx === count($cuentasBancoPago) - 1) {
                        $debeComBanco = round($debeCom - $debeComBancoAcum, 2);
                    } else {
                        $ratioBanco = $totalPagoMixto > 0 ? $cbp['monto'] / $totalPagoMixto : 0;
                        $debeComBanco = round($debeCom * $ratioBanco, 2);
                        $debeComBancoAcum += $debeComBanco;
                    }
                    if ($debeComBanco > 0) {
                        $debemeComBanco = round($debeComBanco / $oficial, 2);
                        $this->insertPartida($idasiento, $codejercicio, $cbp['codsubcuenta'], $codAgencia, $codigo,
                            $debeComBanco, $debemeComBanco, 0, 0, $oficial);
                    }
                }
            } else {
                $debemeCom = round($debeCom / $oficial, 2);
                $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_ENTRADA_CAJA_COMISION, $codAgencia, $codigo,
                    $debeCom, $debemeCom, 0, 0, $oficial);
            }

            // P9: Retención ITF (HABER)
            $haberItf = round($itfReal, 2);
            $habermeItf = round($haberItf / $oficial);

            $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_RETENCION_ITF, $codAgencia, $codigo,
                0, 0, $haberItf, $habermeItf, $oficial);

            // P10: Ganancia o Pérdida por cambio
            if ($ganancia > 0) {
                $haberGan = round($ganancia, 2);
                $habermeGan = round($haberGan / $oficial);
                $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_GANANCIA, $codAgencia, $codigo,
                    0, 0, $haberGan, $habermeGan, $oficial);
            } else {
                $debePer = round($ganancia * (-1), 2);
                $debemePer = round($debePer / $oficial);
                $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_PERDIDA, $codAgencia, $codigo,
                    $debePer, $debemePer, 0, 0, $oficial);
            }

            // Finalizar asiento 1
            DB::execute("
                UPDATE co_asientos SET importe = (
                    SELECT COALESCE(SUM(debe), 0) FROM co_partidas WHERE idasiento = :id
                ) WHERE idasiento = :id2
            ", [':id' => $idasiento, ':id2' => $idasiento], 'conta');

            // ─── ASIENTO 2: Corresponsalía (tipo 'T') ───
            $casoClp = true;
            if ($monEnt === 3) {
                if ($monto > 1000) $casoClp = false;
            } elseif ($monEnt === 1) {
                $comisiones = $this->getComisionRemesa($idAgencia, 1, $idOperacion, $monto);
                $porcCorresponsal = (float) ($comisiones['porcentaje_corresponsal'] ?? 0);
                if ($porcCorresponsal == 0) $casoClp = false;
            }

            if ($casoClp) {
                $glosaT = mb_strtoupper("{$clienteNombre} - {$agenciaDesc} - {$beneficiario} - {$destinoDesc} -REGISTRO POR CARGOS DEL CORRESPONSAL FINANCIERO PENDIENTE DE PAGO POR EL ENVIO DE REMESA #{$codigo} DE {$divisa} " . number_format($monto, 2, '.', ''));

                DB::execute("
                    INSERT INTO co_asientos (
                        codejercicio, codplanasiento, concepto, documento, editable,
                        fecha, idasiento, idconcepto, importe, numero, tipodocumento,
                        cod_usuario, fecha_trans, origen
                    ) VALUES (
                        :ej, NULL, :concepto, '', 0,
                        :fecha, '', :idconcepto, 0, :numero, 'T',
                        'CGR', :fecha_t, 'POS'
                    )
                ", [
                    ':ej'         => $codejercicio,
                    ':concepto'   => $glosaT,
                    ':fecha'      => $fechaAsiento,
                    ':idconcepto' => $codAgencia,
                    ':numero'     => $sigue,
                    ':fecha_t'    => $fecha,
                ], 'conta');

                $idasiento2 = DB::lastInsertId('conta');

                // Adjust comision_giro for specific cases
                if ($monEnt === 1 && $monto >= 1000) {
                    $comisiones = $this->getComisionRemesa($idAgencia, 1, $idOperacion, $monto);
                    $porcTotalCom = (float) $comisiones['total_comision'];
                    $porcCorresponsal = (float) $comisiones['porcentaje_corresponsal'];
                    $comisionGiroHaber = ($comisionRemesa / $porcTotalCom) * $porcCorresponsal;
                    $comisionGiroHaberMe = round($comisionGiroHaber / $oficial, 2);
                } elseif ($monEnt === 3 && $monto < 1000) {
                    // comisionGiroHaber already set from CLP calculation above
                    $comisionGiroHaberMe = round($comisionGiroHaber / $oficial, 2);
                }

                // T-P1: Comisión giro (DEBE)
                $this->insertPartida($idasiento2, $codejercicio, self::SUBCUENTA_COMI_GIRO, $codAgencia, $codigo,
                    $comisionGiroHaber, $comisionGiroHaberMe, 0, 0, $oficial);

                // T-P2: Cargos corresponsalía (HABER)
                $this->insertPartida($idasiento2, $codejercicio, self::SUBCUENTA_CARG_CORRESPONSALIA, $codAgencia, $codigo,
                    0, 0, $comisionGiroHaber, $comisionGiroHaberMe, $oficial);

                // Finalizar asiento 2
                DB::execute("
                    UPDATE co_asientos SET importe = (
                        SELECT COALESCE(SUM(debe), 0) FROM co_partidas WHERE idasiento = :id
                    ) WHERE idasiento = :id2
                ", [':id' => $idasiento2, ':id2' => $idasiento2], 'conta');
            }

        } catch (\Throwable $e) {
            $this->encolarAsientoPendienteRemesa($idOperacionLog, $fecha, $e->getMessage());
        }
    }

    private function insertPartida(
        int $idasiento, string $codejercicio, string $codsubcuenta,
        string $idconcepto, int $codserie,
        float $debe, float $debeme, float $haber, float $haberme,
        float $recargo
    ): void {
        $idsubcuenta = $this->getIdSubcuenta($codsubcuenta, $codejercicio);
        $tasaconv = 0;
        if ($debeme > 0) $tasaconv = $debe / $debeme;
        elseif ($haberme > 0) $tasaconv = $haber / $haberme;

        DB::execute("
            INSERT INTO co_partidas (
                baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                codsubcuenta, concepto, debe, debeme, documento, factura,
                haber, haberme, idasiento, idconcepto, idcontrapartida,
                idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
            ) VALUES (
                0, '', NULL, 'BOB', :codserie,
                :codsubcuenta, NULL, :debe, :debeme, '', NULL,
                :haber, :haberme, :asiento, :idconcepto, NULL,
                '', :idsub, 0, 0, :recargo, :tasaconv, NULL
            )
        ", [
            ':codserie'     => $codserie,
            ':codsubcuenta' => $codsubcuenta,
            ':debe'         => $debe,
            ':debeme'       => $debeme,
            ':haber'        => $haber,
            ':haberme'      => $haberme,
            ':asiento'      => $idasiento,
            ':idconcepto'   => $idconcepto,
            ':idsub'        => $idsubcuenta,
            ':recargo'      => $recargo,
            ':tasaconv'     => $tasaconv,
        ], 'conta');
    }

    // ─── Facturación ──────────────────────────────────────────────────

    private function facturarRemesa(
        int $idRemesa, int $codigo, int $idOperacion, int $idAgencia,
        string $loginUsuario, array $cliente, int $monEnt,
        float $comisionBob, string $fecha, string $clienteNombre
    ): array {
        try {
            $facturacionService = new FacturacionService();
            $cambioData = [
                'mon_rec'           => 2,
                'mon_ent'           => 2,
                'tipo_cambio'       => 1,
                'recibido'          => $comisionBob,
                'entregado'         => $comisionBob,
                'num_doc_identidad' => mb_strtoupper(trim($cliente['num_doc_identidad'] ?? '')),
            ];

            $clienteFacturacion = [
                'nombre'       => $clienteNombre,
                'documento'    => mb_strtoupper(trim($cliente['num_doc_identidad'] ?? '')),
                'tipo_doc_cgr' => (int) ($cliente['id_tipo_doc_identidad'] ?? 1),
            ];

            return $facturacionService->facturarCambio(
                $idRemesa,
                $idOperacion,
                $idAgencia,
                $loginUsuario,
                $cambioData,
                $clienteFacturacion,
                $fecha,
                FacturacionService::CODIGO_SERVICIO_REMESA
            );
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'numero_factura' => 0];
        }
    }

    private function registrarCompraUsd(
        int $codUsuario, string $loginUsuario, int $idAgencia,
        array $cliente, float $montoUsd, string $fecRegOpe,
        int $idUsuarioFinanciero, string $nitCompra = ''
    ): array {
        $tcOficialVenta = $this->getCotizacionOficialVenta(1);
        $totalBob = round($montoUsd * $tcOficialVenta, 2);

        DB::execute("
            INSERT INTO cambio (
                id_operacion, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                id_agencia, mon_rec, mon_ent, tipo_cambio,
                monto, total_cambio, recibido, entregado,
                cambio, total_operacion, tipo_cambio_bob, total_operacion_bob,
                cambio_bob, fec_crea_cambio, facturado, cod_usuario,
                usuario_financiero_id, persona_factura_id
            ) VALUES (
                1, :tipo_doc, :doc, :exp,
                :ag, 1, 2, :tc,
                :monto, :total, :monto, :total,
                0, :total, :tc, :total,
                0, :fecha, 0, :usr,
                :uf_id, 0
            )
        ", [
            ':tipo_doc' => (int) $cliente['id_tipo_doc_identidad'],
            ':doc'      => mb_strtoupper(trim($cliente['num_doc_identidad'])),
            ':exp'      => (int) $cliente['id_expedicion'],
            ':ag'       => $idAgencia,
            ':tc'       => $tcOficialVenta,
            ':monto'    => $montoUsd,
            ':total'    => $totalBob,
            ':fecha'    => $fecRegOpe,
            ':usr'      => $codUsuario,
            ':uf_id'    => $idUsuarioFinanciero,
        ]);

        $idCambio = (int) DB::lastInsertId();

        $cajaUsd = DB::selectOne("
            SELECT c.id_caja FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = 1
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia]);

        if ($cajaUsd) {
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, :fecha, 'EC')
            ", [
                ':id'    => $cajaUsd['id_caja'],
                ':saldo' => $montoUsd,
                ':fecha' => $fecRegOpe,
            ]);
        }

        $cajaBob = DB::selectOne("
            SELECT c.id_caja FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = 2
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia]);

        if ($cajaBob) {
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, :fecha, 'SC')
            ", [
                ':id'    => $cajaBob['id_caja'],
                ':saldo' => -$totalBob,
                ':fecha' => $fecRegOpe,
            ]);
        }

        $clienteNombre = mb_strtoupper(trim(
            ($cliente['nombres'] ?? '') . ' ' .
            ($cliente['ape_pat'] ?? '') . ' ' .
            ($cliente['ape_mat'] ?? '')
        ));

        $usarNit = $nitCompra !== '';
        $docFactura = $usarNit ? $nitCompra : mb_strtoupper(trim($cliente['num_doc_identidad'] ?? ''));
        $tipoDocFactura = $usarNit ? 4 : (int) ($cliente['id_tipo_doc_identidad'] ?? 1);
        $nombreFactura = $usarNit ? $clienteNombre : $clienteNombre;

        $facturaCompra = [];
        try {
            $facturacionService = new FacturacionService();
            $cambioData = [
                'mon_rec'           => 1,
                'mon_ent'           => 2,
                'tipo_cambio'       => $tcOficialVenta,
                'recibido'          => $montoUsd,
                'entregado'         => $totalBob,
                'num_doc_identidad' => $docFactura,
            ];
            $clienteFacturacion = [
                'nombre'       => $nombreFactura,
                'documento'    => $docFactura,
                'tipo_doc_cgr' => $tipoDocFactura,
            ];
            $facturaCompra = $facturacionService->facturarCambio(
                $idCambio, 1, $idAgencia, $loginUsuario,
                $cambioData, $clienteFacturacion, $fecRegOpe
            );
        } catch (\Throwable $e) {
            $facturaCompra = ['error' => $e->getMessage(), 'numero_factura' => 0];
        }

        return [
            'id_cambio'        => $idCambio,
            'tc_oficial_venta' => $tcOficialVenta,
            'monto_usd'        => $montoUsd,
            'total_bob'        => $totalBob,
            'factura'          => $facturaCompra,
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private function upsertUsuarioFinanciero(array $c, string $fecha): int
    {
        $existing = DB::selectOne("
            SELECT id FROM usuario_financiero
            WHERE num_doc_identidad = :doc
              AND id_tipo_doc_identidad = :tipo
              AND id_expedicion = :exp
            LIMIT 1
        ", [
            ':doc'  => mb_strtoupper(trim($c['num_doc_identidad'])),
            ':tipo' => (int) $c['id_tipo_doc_identidad'],
            ':exp'  => (int) $c['id_expedicion'],
        ]);

        $idCiudad = (int) ($c['id_expedicion'] ?? 0);

        if ($existing) {
            DB::execute("
                UPDATE usuario_financiero SET
                    nombres = :nom, ape_pat = :ap, ape_mat = :am,
                    id_sexo = :sexo, fecha_nacimiento = :fn, id_pais = :pais,
                    lugar_nacimiento = :ln, id_estado_civil = :ec, id_ocupacion = :oc,
                    id_actividad_economica = :ae, id_ciudad = :ci, zona = :zo,
                    domicilio = :dom, tel_dom = :td, tel_trab = :tt,
                    celular = :cel, e_trabajo = :et, e_cargo = :ec2, e_direccion = :ed,
                    fec_mod_usu_fin = :fecha, nit = :nit
                WHERE id = :id
            ", [
                ':nom'   => mb_strtoupper(trim($c['nombres'] ?? '')),
                ':ap'    => mb_strtoupper(trim($c['ape_pat'] ?? '')),
                ':am'    => mb_strtoupper(trim($c['ape_mat'] ?? '')),
                ':sexo'  => (int) ($c['id_sexo'] ?? 1),
                ':fn'    => $c['fecha_nacimiento'] ?? null,
                ':pais'  => (int) ($c['id_pais'] ?? 1),
                ':ln'    => mb_strtoupper(trim($c['lugar_nacimiento'] ?? '')),
                ':ec'    => (int) ($c['id_estado_civil'] ?? 1),
                ':oc'    => (int) ($c['id_ocupacion'] ?? 1),
                ':ae'    => (int) ($c['id_actividad_economica'] ?? 1),
                ':ci'    => $idCiudad ?: null,
                ':zo'    => mb_strtoupper(trim($c['zona'] ?? '')),
                ':dom'   => mb_strtoupper(trim($c['domicilio'] ?? '')),
                ':td'    => $c['tel_dom'] ?? '',
                ':tt'    => $c['tel_trab'] ?? '',
                ':cel'   => $c['celular'] ?? '',
                ':et'    => mb_strtoupper(trim($c['e_trabajo'] ?? '')),
                ':ec2'   => mb_strtoupper(trim($c['e_cargo'] ?? '')),
                ':ed'    => mb_strtoupper(trim($c['e_direccion'] ?? '')),
                ':fecha' => $fecha,
                ':nit'   => mb_strtoupper(trim($c['nit'] ?? '')),
                ':id'    => (int) $existing['id'],
            ]);
            return (int) $existing['id'];
        }

        DB::execute("
            INSERT INTO usuario_financiero (
                id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                id_sexo, nombres, ape_pat, ape_mat,
                fecha_nacimiento, id_pais, lugar_nacimiento,
                id_estado_civil, id_ocupacion, id_actividad_economica,
                id_ciudad, zona, domicilio, tel_dom, tel_trab, celular,
                e_trabajo, e_cargo, e_direccion,
                fec_crea_usu_fin, fec_mod_usu_fin,
                riesgo, nivel_impresion_formulario,
                imprimir_formulario, encontrado_listas_control,
                imprimir_resultado_control, nivel_impresion_lista_control,
                pep, stipo_pep, nit
            ) VALUES (
                :tipo, :doc, :exp,
                :sexo, :nom, :ap, :am,
                :fn, :pais, :ln,
                :ec, :oc, :ae,
                :ci, :zo, :dom, :td, :tt, :cel,
                :et, :ec2, :ed,
                :fecha, NULL,
                'N', 1, 'S', 'N', 'S', 1, 'N', 'ninguno', :nit
            )
        ", [
            ':tipo'  => (int) $c['id_tipo_doc_identidad'],
            ':doc'   => mb_strtoupper(trim($c['num_doc_identidad'])),
            ':exp'   => (int) $c['id_expedicion'],
            ':sexo'  => (int) ($c['id_sexo'] ?? 1),
            ':nom'   => mb_strtoupper(trim($c['nombres'] ?? '')),
            ':ap'    => mb_strtoupper(trim($c['ape_pat'] ?? '')),
            ':am'    => mb_strtoupper(trim($c['ape_mat'] ?? '')),
            ':fn'    => $c['fecha_nacimiento'] ?? null,
            ':pais'  => (int) ($c['id_pais'] ?? 1),
            ':ln'    => mb_strtoupper(trim($c['lugar_nacimiento'] ?? '')),
            ':ec'    => (int) ($c['id_estado_civil'] ?? 1),
            ':oc'    => (int) ($c['id_ocupacion'] ?? 1),
            ':ae'    => (int) ($c['id_actividad_economica'] ?? 1),
            ':ci'    => $idCiudad ?: null,
            ':zo'    => mb_strtoupper(trim($c['zona'] ?? '')),
            ':dom'   => mb_strtoupper(trim($c['domicilio'] ?? '')),
            ':td'    => $c['tel_dom'] ?? '',
            ':tt'    => $c['tel_trab'] ?? '',
            ':cel'   => $c['celular'] ?? '',
            ':et'    => mb_strtoupper(trim($c['e_trabajo'] ?? '')),
            ':ec2'   => mb_strtoupper(trim($c['e_cargo'] ?? '')),
            ':ed'    => mb_strtoupper(trim($c['e_direccion'] ?? '')),
            ':fecha' => $fecha,
            ':nit'   => mb_strtoupper(trim($c['nit'] ?? '')),
        ]);

        return (int) DB::lastInsertId();
    }

    private function upsertDestinatario(array $cliente, array $d, string $fecha): int
    {
        $existing = DB::selectOne("
            SELECT id_destinatario FROM destinatario
            WHERE id_tipo_doc_identidad_d = :tipo
              AND num_doc_identidad_d = :doc
              AND id_expedicion_d = :exp
            LIMIT 1
        ", [
            ':doc'  => mb_strtoupper(trim($d['num_doc_identidad_d'] ?? '')),
            ':tipo' => (int) ($d['id_tipo_doc_identidad_d'] ?? 1),
            ':exp'  => (int) ($d['id_expedicion_d'] ?? 0),
        ]);

        if ($existing) {
            DB::execute("
                UPDATE destinatario SET
                    nombres = :nom, ape_pat = :ap, ape_mat = :am,
                    domicilio = :dom, tel_dom = :td, tel_trab = :tt,
                    celular = :cel, cuenta = :cuenta,
                    id_banco_chile = :banco_chile,
                    fec_mod_destinatario = :fecha
                WHERE id_destinatario = :id
            ", [
                ':nom'   => mb_strtoupper(trim($d['nombres'] ?? '')),
                ':ap'    => mb_strtoupper(trim($d['ape_pat'] ?? '')),
                ':am'    => mb_strtoupper(trim($d['ape_mat'] ?? '')),
                ':dom'   => mb_strtoupper(trim($d['domicilio'] ?? '')),
                ':td'    => $d['tel_dom'] ?? '',
                ':tt'    => $d['tel_trab'] ?? '',
                ':cel'   => $d['celular'] ?? '',
                ':cuenta' => mb_strtoupper(trim($d['cuenta'] ?? '')),
                ':banco_chile' => isset($d['id_banco_chile']) ? (int) $d['id_banco_chile'] : null,
                ':fecha' => $fecha,
                ':id'    => (int) $existing['id_destinatario'],
            ]);
            return (int) $existing['id_destinatario'];
        }

        DB::execute("
            INSERT INTO destinatario (
                id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                nombres, ape_pat, ape_mat,
                id_tipo_doc_identidad_d, num_doc_identidad_d, id_expedicion_d,
                domicilio, tel_dom, tel_trab, celular, cuenta,
                id_banco_chile,
                fec_crea_destinatario, fec_mod_destinatario
            ) VALUES (
                :tipo_rem, :doc_rem, :exp_rem,
                :nom, :ap, :am,
                :tipo_d, :doc_d, :exp_d,
                :dom, :td, :tt, :cel, :cuenta,
                :banco_chile,
                :fecha, NULL
            )
        ", [
            ':tipo_rem' => (int) $cliente['id_tipo_doc_identidad'],
            ':doc_rem'  => mb_strtoupper(trim($cliente['num_doc_identidad'])),
            ':exp_rem'  => (int) $cliente['id_expedicion'],
            ':nom'      => mb_strtoupper(trim($d['nombres'] ?? '')),
            ':ap'       => mb_strtoupper(trim($d['ape_pat'] ?? '')),
            ':am'       => mb_strtoupper(trim($d['ape_mat'] ?? '')),
            ':tipo_d'   => (int) ($d['id_tipo_doc_identidad_d'] ?? 1),
            ':doc_d'    => mb_strtoupper(trim($d['num_doc_identidad_d'] ?? '')),
            ':exp_d'    => (int) ($d['id_expedicion_d'] ?? 0),
            ':dom'      => mb_strtoupper(trim($d['domicilio'] ?? '')),
            ':td'       => $d['tel_dom'] ?? '',
            ':tt'       => $d['tel_trab'] ?? '',
            ':cel'      => $d['celular'] ?? '',
            ':cuenta'   => mb_strtoupper(trim($d['cuenta'] ?? '')),
            ':banco_chile' => isset($d['id_banco_chile']) ? (int) $d['id_banco_chile'] : null,
            ':fecha'    => $fecha,
        ]);

        return (int) DB::lastInsertId();
    }

    private function generarCodigoRemesa(): int
    {
        $maxAttempts = 50;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $codigo = rand(1, 99999);
            $exists = DB::scalar(
                "SELECT COUNT(*) FROM remesa WHERE codigo = :c",
                [':c' => $codigo]
            );
            if ((int) $exists === 0) {
                return $codigo;
            }
        }
        return rand(100000, 999999);
    }

    private function getIdSubcuenta(string $codsubcuenta, string $codejercicio): ?int
    {
        $val = DB::scalar(
            "SELECT idsubcuenta FROM co_subcuentas WHERE codsubcuenta = :cod AND codejercicio = :ej",
            [':cod' => $codsubcuenta, ':ej' => $codejercicio], 'conta'
        );
        return $val ? (int) $val : null;
    }

    private function getCotizacionVenta(int $idMoneda, int $idAgencia): float
    {
        return (float) DB::scalar("
            SELECT c.valor FROM cotizacion c
            WHERE c.id_moneda = :mon AND c.id_agencia = :ag AND c.id_operacion = 2
              AND c.estado = 'A'
            ORDER BY c.fec_crea_cotizacion DESC LIMIT 1
        ", [':mon' => $idMoneda, ':ag' => $idAgencia]) ?: 1.0;
    }

    private function getCotizacionOficialCompra(int $idMoneda): float
    {
        return (float) DB::scalar("
            SELECT valor FROM cotizacion_oficial
            WHERE id_moneda = :mon AND id_operacion = 1 AND estado = 'A'
              AND DATE(fec_crea_cotizacion_oficial) <= CURDATE()
            ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1
        ", [':mon' => $idMoneda]) ?: 1.0;
    }

    private function getCotizacionOficialVenta(int $idMoneda): float
    {
        return (float) DB::scalar("
            SELECT valor FROM cotizacion_oficial
            WHERE id_moneda = :mon AND id_operacion = 2 AND estado = 'A'
              AND DATE(fec_crea_cotizacion_oficial) <= CURDATE()
            ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1
        ", [':mon' => $idMoneda]) ?: 1.0;
    }

    private function getCotizacionParalela(int $idAgencia, int $idMoneda): float
    {
        return (float) DB::scalar("
            SELECT valor FROM cotizacion
            WHERE id_agencia = :ag AND id_moneda = :mon AND id_operacion = 4 AND estado = 'I'
            ORDER BY fec_crea_cotizacion DESC LIMIT 1
        ", [':ag' => $idAgencia, ':mon' => $idMoneda]) ?: 1.0;
    }

    private function getItfPorcentaje(int $idMoneda): float
    {
        return (float) DB::scalar("
            SELECT porcentaje FROM itf WHERE id_moneda = :mon AND estado = 'A' LIMIT 1
        ", [':mon' => $idMoneda]) ?: 0.3;
    }

    private function getParametro(string $nombre): float
    {
        return (float) DB::scalar(
            "SELECT valor FROM parametros WHERE slug = :n AND estado = 'A' LIMIT 1",
            [':n' => $nombre]
        ) ?: 0;
    }

    private function getComisionRemesa(int $idAgencia, int $idMoneda, int $idOperacion, float $monto): array
    {
        return DB::selectOne("
            SELECT total_comision, porcentaje_gambarte, porcentaje_corresponsal
            FROM comision
            WHERE id_agencia = :ag AND id_moneda = :mon
              AND id_operacion = :op AND estado = 'A'
              AND :monto BETWEEN rango_inicial AND rango_final
            LIMIT 1
        ", [
            ':ag'    => $idAgencia,
            ':mon'   => $idMoneda,
            ':op'    => $idOperacion,
            ':monto' => $monto,
        ]) ?: ['total_comision' => 0, 'porcentaje_gambarte' => 0, 'porcentaje_corresponsal' => 0];
    }

    private function tieneSesionAbierta(int $codUsuario): bool
    {
        return (int) DB::scalar(
            "SELECT COUNT(*) FROM pos_sesion_caja
             WHERE cod_usuario = :usr AND fecha = CURDATE() AND estado = 'abierta'",
            [':usr' => $codUsuario]
        ) > 0;
    }

    private function isBovedaAbierta(int $idAgencia): bool
    {
        return (int) DB::scalar(
            "SELECT COUNT(*) FROM saldo_boveda
             WHERE id_boveda IN (SELECT id_boveda FROM boveda WHERE id_agencia = :ag)
               AND DATE(fec_crea_saldo) = CURDATE() AND conciliacion = 'A'",
            [':ag' => $idAgencia]
        ) > 0;
    }

    private function encolarAsientoPendienteRemesa(int $idOperacionLog, string $fecha, string $error): void
    {
        DB::execute("
            INSERT INTO pos_asiento_pendiente (
                id_operacion_log, tipo_operacion, codejercicio, fecha,
                concepto, documento, partidas, estado, error_mensaje, created_at
            ) VALUES (
                :id_log, 'remesa', :ej, :fecha,
                'ASIENTO PENDIENTE REMESA', '', '[]', 'pendiente', :error, :created
            )
        ", [
            ':id_log'  => $idOperacionLog,
            ':ej'      => date('Y'),
            ':fecha'   => substr($fecha, 0, 10),
            ':error'   => $error,
            ':created' => $fecha,
        ]);
    }

    private function encolarNotificacionesRemesa(
        int $idRemesa, int $codigo, string $clienteNombre, string $destNombre,
        float $montoEnviado, string $monedaAbr, float $totalClp,
        float $comisionBob, float $totalBob,
        string $agencia, string $destino, string $formaPagoChile, ?string $bancoChile, string $cuentaChile,
        string $emailCliente, string $emailDestinatario,
        ?array $facturaData
    ): void {
        $montoFmt = number_format($montoEnviado, 2);
        $clpFmt = number_format($totalClp, 0, ',', '.');
        $comFmt = number_format($comisionBob, 2);
        $totalFmt = number_format($totalBob, 2);
        $fecha = date('d/m/Y H:i');

        $pagoInfo = $formaPagoChile;
        if ($bancoChile) {
            $pagoInfo .= " — {$bancoChile}";
            if ($cuentaChile) $pagoInfo .= " (Cta: {$cuentaChile})";
        }

        if ($emailCliente) {
            $facturaHtml = '';
            if ($facturaData && !empty($facturaData['numero_factura'])) {
                $facturaHtml = $this->emailTableRow('Nro. Factura', $facturaData['numero_factura'])
                    . $this->emailTableRow('CUF', $facturaData['cuf'] ?? '');
            }

            $contenido = $this->buildEmailHtml(
                "Comprobante de Remesa #{$codigo}",
                "Estimado/a {$clienteNombre},",
                'Su remesa internacional ha sido registrada exitosamente. Adjuntamos su comprobante de envío y factura de comisión.',
                $this->emailTableRow('Código', $codigo)
                . $this->emailTableRow('Fecha', $fecha)
                . $this->emailTableRow('Monto enviado', "{$montoFmt} {$monedaAbr}")
                . $this->emailTableRow('Comisión', "{$comFmt} BOB")
                . $this->emailTableRow('Total pagado', "{$totalFmt} BOB")
                . $this->emailTableRow('Monto a entregar', "{$clpFmt} CLP")
                . $this->emailTableRow('Beneficiario', $destNombre)
                . $this->emailTableRow('Destino', $destino)
                . $this->emailTableRow('Forma de pago', $pagoInfo)
                . $this->emailTableRow('Agencia', $agencia)
                . $facturaHtml,
                'Gracias por confiar en Gambarte Bolivia S.R.L.'
            );

            $adjuntos = [];
            try {
                $comprobante = $this->comprobantePdf($idRemesa);
                $adjuntos[] = [
                    'data'   => $comprobante['binary'],
                    'nombre' => "Comprobante_Envio_{$codigo}.pdf",
                    'mime'   => 'application/pdf',
                ];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("No se pudo generar comprobante PDF para remesa {$idRemesa}: " . $e->getMessage());
            }

            try {
                $factura = $this->facturaPdf($idRemesa);
                $adjuntos[] = [
                    'data'   => $factura['binary'],
                    'nombre' => "Factura_Comision_{$codigo}.pdf",
                    'mime'   => 'application/pdf',
                ];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("No se pudo generar factura PDF para remesa {$idRemesa}: " . $e->getMessage());
            }

            $this->enviarOEncolar($emailCliente, "Gambarte - Comprobante de Remesa #{$codigo}", $contenido, $idRemesa, $adjuntos);
        }

        if ($emailDestinatario) {
            $instruccion = $formaPagoChile === 'Efectivo por Ventanilla'
                ? 'Por favor, acérquese a la agencia de destino con su documento de identidad para cobrar la remesa.'
                : 'Su remesa está en proceso de acreditación. Le avisaremos cuando el monto esté disponible en su cuenta.';

            $contenidoD = $this->buildEmailHtml(
                'Ha recibido una Remesa Internacional',
                "Estimado/a {$destNombre},",
                'Le informamos que ha recibido una remesa internacional.',
                $this->emailTableRow('Código', $codigo)
                . $this->emailTableRow('Fecha', $fecha)
                . $this->emailTableRow('Monto a recibir', "{$clpFmt} CLP")
                . $this->emailTableRow('Forma de pago', $pagoInfo)
                . $this->emailTableRow('Destino', $destino)
                . $this->emailTableRow('Remitente', $clienteNombre)
                . $this->emailTableRow('Agencia origen', $agencia),
                $instruccion,
                true
            );

            $this->enviarOEncolar($emailDestinatario, 'Gambarte - Ha recibido una remesa internacional', $contenidoD, $idRemesa);
        }
    }

    private function emailTableRow(string $label, $value): string
    {
        return '<tr>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eee;color:#666;font-size:13px;width:40%">' . htmlspecialchars($label) . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eee;color:#333;font-size:13px;font-weight:600">' . htmlspecialchars((string) $value) . '</td>'
            . '</tr>';
    }

    private function buildEmailHtml(string $titulo, string $saludo, string $intro, string $tableRows, string $cierre, bool $mostrarBotonConsulta = false): string
    {
        $naranja = '#F58634';
        $negro = '#373435';

        return '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

  <!-- Header -->
  <tr><td style="background:#ffffff;padding:24px 30px;text-align:center;border-bottom:4px solid ' . $naranja . '">
    <img src="cid:gambarte_logo" alt="Gambarte - Giros y Remesas de Dinero" width="220" style="display:block;margin:0 auto">
  </td></tr>

  <!-- Título -->
  <tr><td style="padding:28px 30px 0;text-align:center">
    <h1 style="margin:0;font-size:20px;color:' . $negro . '">' . htmlspecialchars($titulo) . '</h1>
  </td></tr>

  <!-- Cuerpo -->
  <tr><td style="padding:20px 30px">
    <p style="font-size:14px;color:#333;margin:0 0 6px">' . htmlspecialchars($saludo) . '</p>
    <p style="font-size:14px;color:#555;margin:0 0 20px">' . htmlspecialchars($intro) . '</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eee;border-radius:6px;overflow:hidden">
      <tr><td colspan="2" style="background:' . $naranja . ';padding:10px 12px;color:#fff;font-size:13px;font-weight:700">Detalle de la operación</td></tr>
      ' . $tableRows . '
    </table>

    <p style="font-size:14px;color:#555;margin:20px 0 0;padding:14px;background:#fef6ee;border-left:3px solid ' . $naranja . ';border-radius:4px">'
        . htmlspecialchars($cierre) .
    '</p>

    ' . ($mostrarBotonConsulta ? '
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0 0">
      <tr><td align="center">
        <a href="#" style="display:inline-block;padding:14px 32px;background:' . $naranja . ';color:#fff;font-size:14px;font-weight:700;text-decoration:none;border-radius:6px;letter-spacing:0.5px;text-transform:uppercase">Consultar mi remesa</a>
      </td></tr>
    </table>' : '') . '
  </td></tr>

  <!-- Footer -->
  <tr><td style="background:#fafafa;padding:20px 30px;border-top:1px solid #eee;text-align:center">
    <p style="margin:0 0 4px;font-size:12px;color:#999">Gambarte Bolivia S.R.L.</p>
    <p style="margin:0;font-size:11px;color:#bbb">Este correo fue generado automáticamente, por favor no responda a este mensaje.</p>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>';
    }

    private function enviarOEncolar(string $email, string $asunto, string $contenido, int $idRemesa, array $adjuntos = []): void
    {
        $prevLimit = ini_get('max_execution_time');
        try {
            set_time_limit((int) $prevLimit + 10);
            $logoPath = public_path('images/gambarte-logo.png');
            $hasLogo = file_exists($logoPath);

            \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($email, $asunto, $contenido, $logoPath, $hasLogo, $adjuntos) {
                $message->to($email)->subject($asunto);
                $html = $contenido;
                if ($hasLogo) {
                    $cid = $message->embed($logoPath);
                    $html = str_replace('cid:gambarte_logo', $cid, $html);
                }
                $message->setBody($html, 'text/html');

                foreach ($adjuntos as $adj) {
                    $message->attachData($adj['data'], $adj['nombre'], ['mime' => $adj['mime'] ?? 'application/pdf']);
                }
            });

            DB::execute("
                INSERT INTO pos_notificacion_operacion
                    (canal, destinatario, tipo_operacion, id_referencia, asunto, contenido, estado, sent_at, intentos)
                VALUES ('email', :dest, 'remesa', :ref, :asunto, :contenido, 'enviado', NOW(), 1)
            ", [
                ':dest'      => $email,
                ':ref'       => $idRemesa,
                ':asunto'    => $asunto,
                ':contenido' => $contenido,
            ]);
        } catch (\Throwable $e) {
            DB::execute("
                INSERT INTO pos_notificacion_operacion
                    (canal, destinatario, tipo_operacion, id_referencia, asunto, contenido, estado, intentos, error_mensaje)
                VALUES ('email', :dest, 'remesa', :ref, :asunto, :contenido, 'pendiente', 0, :err)
            ", [
                ':dest'      => $email,
                ':ref'       => $idRemesa,
                ':asunto'    => $asunto,
                ':contenido' => $contenido,
                ':err'       => mb_substr($e->getMessage(), 0, 500),
            ]);
        } finally {
            set_time_limit((int) $prevLimit);
        }
    }

    // ─── Pago de Remesas (Chile → Bolivia) ────────────────────────────

    private function esRemesaDeposito(array $remesa): bool
    {
        $partes = array_map('trim', explode('//', trim((string) ($remesa['destinatario'] ?? ''))));
        if (count($partes) >= 3) {
            return trim((string) end($partes)) !== '';
        }
        return count($partes) === 2 && preg_match('/\d/', (string) ($partes[1] ?? '')) === 1;
    }

    /**
     * Replica el filtro del legacy reporte_deposito_pendiente_cgr.php:
     * únicamente se muestran las remesas cubiertas por una asignación activa
     * de la agencia (banco, operación especial o extra).
     */
    private function asignacionesDeposito(int $idAgencia): array
    {
        $rows = DB::select("
            SELECT tipo_asignacion
            FROM asignacion
            WHERE id_agencia = :agencia
              AND estado = 'A'
        ", [':agencia' => $idAgencia]);

        $asignaciones = [
            'bancos' => [],
            'operaciones' => [],
            'extras' => [],
        ];

        foreach ($rows as $row) {
            $tipo = trim((string) ($row['tipo_asignacion'] ?? ''));
            if ($tipo === '') continue;

            if (in_array($tipo, ['6', '8'], true)) {
                $asignaciones['operaciones'][] = (int) $tipo;
            } elseif (in_array($tipo, ['17', '18'], true)) {
                $asignaciones['extras'][] = (int) $tipo;
            } else {
                $asignaciones['bancos'][] = mb_strtoupper($tipo);
            }
        }

        return $asignaciones;
    }

    private function remesaHabilitadaParaDeposito(array $remesa, array $asignaciones): bool
    {
        $idOperacion = (int) ($remesa['id_operacion'] ?? 0);
        $destinatario = mb_strtoupper((string) ($remesa['destinatario'] ?? ''));
        $moneda = mb_strtoupper(trim((string) ($remesa['moneda'] ?? '')));
        $envio = (float) ($remesa['envio'] ?? 0);

        // Asignaciones de operación (legacy: id_operacion IN (6,8)).
        if (in_array($idOperacion, $asignaciones['operaciones'], true)) {
            return true;
        }

        // Remesa bancaria (legacy: id_operacion = 5 y banco habilitado).
        if ($idOperacion === 5) {
            foreach ($asignaciones['bancos'] as $banco) {
                if (mb_stripos($destinatario, $banco) !== false) return true;
            }
        }

        // Extras del legacy: BOB desde 10.000 o cualquier remesa en USD.
        if (in_array(17, $asignaciones['extras'], true) && $moneda === 'BOB' && $envio >= 10000) {
            return true;
        }
        if (in_array(18, $asignaciones['extras'], true) && $moneda === 'USD') {
            return true;
        }

        return false;
    }

    /** UUID estable por lote y código, siempre dentro del límite de 36 chars. */
    private function uuidPreproceso(string $loteUuid, int $codigo): string
    {
        $hex = sha1($loteUuid . '|' . $codigo);
        return sprintf(
            '%s-%s-5%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public function remesasPendientesPago(int $idAgencia, ?int $tipoPago = null): array
    {
        // El legacy no restringía los depósitos por id_destino: se podían
        // pagar desde la agencia según sus bancos habilitados. Para efectivo
        // sí conservamos el filtro por destino de la agencia.
        $where = 'estado = 0';
        $params = [];
        if ($tipoPago !== 1) {
            $where .= ' AND id_destino = :ag';
            $params[':ag'] = $idAgencia;
        }

        $pendientes = DB::select("
            SELECT codigo, moneda, envio, usuario_financiero, destinatario,
                   doc_des, fec_crea_remesa, abr_ori, abr_des, id_destino, id_operacion
            FROM tmp_reporte_remesa
            WHERE {$where}
            ORDER BY fec_crea_remesa
        ", $params, 'chile');

        // En Chile las remesas en efectivo no incluyen banco/cuenta en el
        // destinatario. El filtro se aplica solo al flujo de depósitos; el
        // flujo de efectivo debe seguir viendo todas las remesas pendientes.
        if ($tipoPago === 1) {
            $asignaciones = $this->asignacionesDeposito($idAgencia);
            $pendientes = array_values(array_filter(
                $pendientes,
                fn ($r) => $this->remesaHabilitadaParaDeposito($r, $asignaciones)
            ));

            // Cuando Chile está pausado, el preproceso local es la fuente de
            // estado para la UI. No volver a mostrar como pendiente una remesa
            // que ya fue preparada y debe aparecer en "En proceso".
            $preprocesosActivos = DB::select("SELECT codigo FROM preproceso WHERE estado = 0");
            $codigosEnProceso = array_fill_keys(
                array_map(static fn ($r) => (int) $r['codigo'], $preprocesosActivos),
                true
            );
            $pendientes = array_values(array_filter(
                $pendientes,
                static fn ($r) => !isset($codigosEnProceso[(int) $r['codigo']])
            ));
        }
        $codigos = array_column($pendientes, 'codigo');
        $yaPageadas = [];
        if (!empty($codigos)) {
            $codigoList = implode(',', array_map('intval', $codigos));
            $pagos = DB::select("
                SELECT codigo FROM pago
                WHERE codigo IN ({$codigoList})
                  AND id_tipo_pago = 1 AND id_operacion IN (4, 7, 8, 16)
            ");
            foreach ($pagos as $p) {
                $yaPageadas[(int) $p['codigo']] = true;
            }
        }

        return array_values(array_filter(array_map(function ($r) use ($yaPageadas) {
            $codigo = (int) $r['codigo'];
            if (isset($yaPageadas[$codigo])) return null;
            return [
                'codigo'       => $codigo,
                'remitente'    => $r['usuario_financiero'] ?? '',
                'destinatario' => $r['destinatario'] ?? '',
                'monto'        => (float) ($r['envio'] ?? 0),
                'moneda'       => $r['moneda'] ?? 'BOB',
                'fecha'        => $r['fec_crea_remesa'] ?? '',
                'origen'       => $r['abr_ori'] ?? '',
                'destino'      => $r['abr_des'] ?? '',
            ];
        }, $pendientes)));
    }

    /**
     * Prepara varias remesas para pago por depósito, siguiendo el flujo del
     * legacy: se crea el preproceso local y la remesa pasa a estado 7 en Chile.
     * El pago contable continúa siendo individual al confirmar cada depósito.
     */
    public function preprocesarRemesas(int $codUsuario, int $idAgencia, array $codigos, string $ip, string $uuid): array
    {
        if (!$this->isBovedaAbierta($idAgencia)) {
            return ['success' => false, 'message' => 'La bóveda no está abierta.'];
        }
        if (!$this->tieneSesionAbierta($codUsuario)) {
            return ['success' => false, 'message' => 'No tiene ninguna caja abierta.'];
        }

        $preparables = [];
        $omitidas = [];
        $asignaciones = $this->asignacionesDeposito($idAgencia);
        foreach ($codigos as $codigo) {
            $codigo = (int) $codigo;
            $remesa = DB::selectOne("
                SELECT codigo, moneda, envio, id_destino, id_operacion, destinatario, estado
                FROM tmp_reporte_remesa
                WHERE codigo = :c
                LIMIT 1
            ", [':c' => $codigo], 'chile');

            if (!$remesa) {
                $omitidas[] = ['codigo' => $codigo, 'motivo' => 'No encontrada'];
                continue;
            }
            if ((int) $remesa['estado'] !== 0) {
                $omitidas[] = ['codigo' => $codigo, 'motivo' => 'Ya no está pendiente'];
                continue;
            }
            if (!$this->remesaHabilitadaParaDeposito($remesa, $asignaciones)) {
                $omitidas[] = ['codigo' => $codigo, 'motivo' => 'Banco u operación no habilitada para esta agencia'];
                continue;
            }

            $yaPagada = DB::selectOne("SELECT 1 FROM pago WHERE codigo = :c AND id_tipo_pago = 1 AND id_operacion IN (4, 7, 8, 16) LIMIT 1", [':c' => $codigo]);
            if ($yaPagada) {
                $omitidas[] = ['codigo' => $codigo, 'motivo' => 'Ya tiene un pago registrado'];
                continue;
            }

            // La PK de preproceso es (id_caja, codigo), por lo que también
            // deben considerarse los registros históricos ya confirmados.
            // Revisar solo estado = 0 permitía intentar insertar nuevamente
            // una remesa y provocaba una violación de clave duplicada.
            $yaPreparada = DB::selectOne(
                "SELECT estado FROM preproceso WHERE codigo = :c LIMIT 1",
                [':c' => $codigo]
            );
            if ($yaPreparada) {
                $motivo = (int) ($yaPreparada['estado'] ?? 0) === 0
                    ? 'Ya está en proceso'
                    : 'Ya tiene un preproceso registrado';
                $omitidas[] = ['codigo' => $codigo, 'motivo' => $motivo];
                continue;
            }

            $monedaAbr = strtoupper(trim((string) ($remesa['moneda'] ?? 'BOB')));
            $moneda = $monedaAbr === 'USD' ? 1 : 2;
            $caja = DB::selectOne("
                SELECT c.id_caja
                FROM caja c
                WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
                  AND c.estado = 'A' AND c.id_tipo_unidad = 2
                LIMIT 1
            ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $moneda]);
            if (!$caja) {
                $omitidas[] = ['codigo' => $codigo, 'motivo' => "No tiene caja {$monedaAbr} asignada"];
                continue;
            }

            $preparables[] = [
                'codigo' => $codigo,
                'id_caja' => (int) $caja['id_caja'],
                'moneda' => $moneda,
                'monto' => (float) ($remesa['envio'] ?? 0),
            ];
        }

        if (empty($preparables)) {
            return [
                'success' => true,
                'message' => 'No hay remesas nuevas para preparar.',
                'data' => ['procesadas' => [], 'omitidas' => $omitidas, 'total' => 0],
            ];
        }

        $fecha = date('Y-m-d H:i:s');
        DB::transaction(function () use ($preparables, $codUsuario, $idAgencia, $ip, $uuid, $fecha) {
            foreach ($preparables as $item) {
                DB::execute("
                    INSERT INTO preproceso (id_caja, codigo, fec_crea_preproceso, estado)
                    VALUES (:id_caja, :codigo, :fecha, 0)
                ", [
                    ':id_caja' => $item['id_caja'],
                    ':codigo' => $item['codigo'],
                    ':fecha' => $fecha,
                ]);

                DB::execute("
                    INSERT INTO pos_operacion_log (
                        uuid, tipo_operacion, id_referencia, tabla_referencia,
                        id_caja, cod_usuario, id_agencia, id_moneda,
                        monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                    ) VALUES (
                        :uuid, 'preproceso_remesa', :codigo, 'preproceso',
                        :id_caja, :usr, :ag, :mon,
                        :monto, :monto_bob, 'pendiente', :ip, :meta, :fecha
                    )
                ", [
                    ':uuid' => $this->uuidPreproceso($uuid, (int) $item['codigo']),
                    ':codigo' => $item['codigo'],
                    ':id_caja' => $item['id_caja'],
                    ':usr' => $codUsuario,
                    ':ag' => $idAgencia,
                    ':mon' => $item['moneda'],
                    ':monto' => $item['monto'],
                    ':monto_bob' => $item['moneda'] === 2 ? $item['monto'] : 0,
                    ':ip' => $ip,
                    ':meta' => json_encode([
                        'codigo' => $item['codigo'],
                        'tipo' => 'preproceso_remesa',
                        'tipo_pago' => 'deposito',
                        'lote_uuid' => $uuid,
                    ]),
                    ':fecha' => $fecha,
                ]);
            }
        });

        $procesadas = [];
        foreach ($preparables as $item) {
            $codigo = $item['codigo'];
            try {
                if ($this->chileSyncPausado()) {
                    DB::execute("
                        INSERT INTO pos_sync_chile_pendiente (tipo, codigo, payload, estado, created_at)
                        VALUES ('pago_remesa', :codigo, :payload, 'pendiente', :fecha)
                    ", [
                        ':codigo' => $codigo,
                        ':payload' => json_encode(['estado' => 7]),
                        ':fecha' => $fecha,
                    ]);
                } else {
                    DB::execute("UPDATE tmp_reporte_remesa SET estado = 7 WHERE codigo = :c AND estado = 0", [':c' => $codigo], 'chile');
                }
                $procesadas[] = $codigo;
            } catch (\Throwable $e) {
                DB::execute("
                    INSERT INTO pos_sync_chile_pendiente (tipo, codigo, payload, estado, created_at)
                    VALUES ('pago_remesa', :codigo, :payload, 'pendiente', :fecha)
                ", [
                    ':codigo' => $codigo,
                    ':payload' => json_encode(['estado' => 7]),
                    ':fecha' => $fecha,
                ]);
                $procesadas[] = $codigo;
            }
        }

        return [
            'success' => true,
            'message' => count($procesadas) . ' remesa(s) preparada(s) para pago.',
            'data' => ['procesadas' => $procesadas, 'omitidas' => $omitidas, 'total' => count($procesadas)],
        ];
    }

    public function buscarRemesaChile(int $codigo): array
    {
        $remesa = DB::selectOne("
            SELECT *
            FROM tmp_reporte_remesa
            WHERE codigo = :c
            LIMIT 1
        ", [':c' => $codigo], 'chile');

        if (!$remesa) {
            return ['success' => false, 'message' => 'Remesa no encontrada en el sistema de Chile.'];
        }

        $estadoMap = [0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO', 5 => 'ANULADO', 7 => 'EN PROCESO'];
        $estadoInt = (int) $remesa['estado'];

        // La descripción del destinatario llega desde Chile con el formato:
        // NOMBRE // BANCO // CUENTA (MONEDA). Exponer sus partes permite que
        // el formulario de pago reutilice la información ya capturada.
        $destinatarioRaw = trim($remesa['destinatario'] ?? '');
        $destinatarioParts = array_map('trim', explode('//', $destinatarioRaw));
        $bancoDestinatario = trim((string) ($remesa['banco_destinatario'] ?? $remesa['banco'] ?? ''));
        $cuentaDestinatario = trim((string) ($remesa['cuenta_destinatario'] ?? $remesa['cuenta'] ?? ''));
        $bancoDestinatario = $bancoDestinatario ?: (count($destinatarioParts) >= 3 ? ($destinatarioParts[1] ?? '') : '');
        $cuentaDestinatario = $cuentaDestinatario ?: (count($destinatarioParts) >= 3
            ? trim((string) ($destinatarioParts[2] ?? ''))
            : '');
        $cuentaDestinatario = preg_replace('/\s*\([^)]*\)\s*$/', '', $cuentaDestinatario) ?: '';
        $docDes = trim((string) ($remesa['doc_des'] ?? ''));
        $docDestinatario = trim((string) (
            $remesa['num_doc_identidad_d']
            ?? $remesa['num_doc_destinatario']
            ?? $remesa['documento_destino']
            ?? $remesa['doc_destinatario']
            ?? ''
        ));
        // Compatibilidad con el formato actual: doc_des puede ser solo "CIB".
        if (!$docDestinatario && preg_match('/\d/', $docDes)) {
            $docDestinatario = $docDes;
        }
        $tipoDocDestinatario = preg_match('/^(CIB|CI|CEDULA|CÉDULA)/iu', $docDes) ? 1 : null;
        $expedicionDestinatario = (int) (
            $remesa['id_expedicion_d']
            ?? $remesa['id_expedicion_destinatario']
            ?? $remesa['expedicion_d']
            ?? 0
        );

        $pago = DB::selectOne("
            SELECT fec_crea_pago FROM pago
            WHERE codigo = :c AND id_tipo_pago = 1 AND id_operacion IN (4, 7, 8, 16)
            LIMIT 1
        ", [':c' => $codigo]);

        return [
            'success' => true,
            'remesa'  => [
                'codigo'          => (int) $remesa['codigo'],
                'remitente'       => $remesa['usuario_financiero'] ?? '',
                'destinatario'    => $remesa['destinatario'] ?? '',
                'docDestinatario' => $docDestinatario,
                'tipoDocDestinatario' => $tipoDocDestinatario,
                'expedicionDestinatario' => $expedicionDestinatario > 0 ? $expedicionDestinatario : null,
                'bancoDestinatario' => $bancoDestinatario,
                'cuentaDestinatario' => $cuentaDestinatario,
                'monto'           => (float) ($remesa['envio'] ?? 0),
                'moneda'          => $remesa['moneda'] ?? 'BOB',
                'origen'          => $remesa['abr_ori'] ?? '',
                'destino'         => $remesa['abr_des'] ?? '',
                'fecha'           => $remesa['fec_crea_remesa'] ?? '',
                'estado'          => $estadoInt,
                'estadoDesc'      => $estadoMap[$estadoInt] ?? "Estado {$estadoInt}",
                'idOperacion'     => (int) ($remesa['id_operacion'] ?? 4),
                'idDestino'       => (int) ($remesa['id_destino'] ?? 0),
                'yaPagada'        => $pago !== null,
                'fechaPago'       => $pago['fec_crea_pago'] ?? null,
            ],
        ];
    }

    public function pagarRemesa(
        int $codUsuario, string $loginUsuario, int $idAgencia,
        int $codigo, array $destinatarioDoc,
        int $tipoPago, array $datosBancarios,
        string $ip, string $uuid
    ): array {
        // 1. Idempotencia
        $existente = DB::selectOne(
            "SELECT id_referencia FROM pos_operacion_log WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );
        if ($existente) {
            return ['success' => true, 'message' => 'Pago ya registrado.', 'data' => ['codigo' => $codigo]];
        }

        // 2. Fetch de Chile
        $remesa = DB::selectOne("
            SELECT codigo, moneda, envio, usuario_financiero, destinatario,
                   doc_des, id_destino, id_operacion, estado
            FROM tmp_reporte_remesa
            WHERE codigo = :c
            LIMIT 1
        ", [':c' => $codigo], 'chile');

        if (!$remesa) {
            return ['success' => false, 'message' => 'Remesa no encontrada en el sistema de Chile.'];
        }
        $estadoChile = (int) $remesa['estado'];
        if ($estadoChile === 1) {
            return ['success' => false, 'message' => 'Esta remesa ya fue pagada.'];
        }
        if ($estadoChile === 2) {
            return ['success' => false, 'message' => 'Esta remesa fue devuelta.'];
        }
        if ($estadoChile === 5) {
            return ['success' => false, 'message' => 'Esta remesa fue anulada.'];
        }
        if ($estadoChile !== 0) {
            return ['success' => false, 'message' => 'Estado de la remesa no permite pago.'];
        }

        // 3. Validar disponibilidad según el tipo de pago.
        // Los depósitos se habilitan por banco/asignación de la agencia, tal
        // como en el legacy; no por el destino chileno de la remesa.
        if ($tipoPago === 1) {
            $asignaciones = $this->asignacionesDeposito($idAgencia);
            if (!$this->remesaHabilitadaParaDeposito($remesa, $asignaciones)) {
                return ['success' => false, 'message' => 'El banco u operación de esta remesa no está habilitado para esta agencia.'];
            }
        } else {
            $idDestinoChile = (int) $remesa['id_destino'];
            if ($idDestinoChile !== $idAgencia) {
                $agDesc = DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idDestinoChile]) ?: "Agencia #{$idDestinoChile}";
                return ['success' => false, 'message' => "Esta remesa no corresponde a esta agencia. Destino: {$agDesc}"];
            }
        }

        // 4. Validaciones locales
        if (!$this->isBovedaAbierta($idAgencia)) {
            return ['success' => false, 'message' => 'La bóveda no está abierta.'];
        }
        if (!$this->tieneSesionAbierta($codUsuario)) {
            return ['success' => false, 'message' => 'No tiene ninguna caja abierta.'];
        }

        // Verificar pago duplicado en DB local
        $pagoDuplicado = DB::selectOne("
            SELECT fec_crea_pago FROM pago
            WHERE codigo = :c AND id_tipo_pago = 1 AND id_operacion IN (4, 7, 8, 16)
            LIMIT 1
        ", [':c' => $codigo]);
        if ($pagoDuplicado) {
            return ['success' => false, 'message' => 'Esta remesa ya tiene un pago registrado localmente.'];
        }

        // 5. Mapear moneda y buscar caja
        $monedaAbr = $remesa['moneda'] ?? 'BOB';
        $monRec = $monedaAbr === 'USD' ? 1 : 2;
        $monto = (float) $remesa['envio'];
        $idOperacion = (int) ($remesa['id_operacion'] ?? 4);
        $fecPago = date('Y-m-d H:i:s');

        $cajaPago = DB::selectOne("
            SELECT c.id_caja FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $monRec]);

        if (!$cajaPago) {
            return ['success' => false, 'message' => "No tiene caja asignada para la moneda {$monedaAbr}."];
        }

        // 6. Transacción local (Bolivia)
        $resultado = DB::transaction(function () use (
            $codUsuario, $loginUsuario, $idAgencia, $remesa, $codigo, $monto, $monRec, $monedaAbr,
            $cajaPago, $fecPago, $ip, $uuid, $destinatarioDoc, $tipoPago, $datosBancarios, $idOperacion
        ) {
            $tipoDocD = (int) ($destinatarioDoc['id_tipo_doc_identidad'] ?? 0);
            $numDocD  = trim($destinatarioDoc['num_doc_identidad'] ?? '');
            $expDocD  = (int) ($destinatarioDoc['id_expedicion'] ?? 0);

            if ($tipoPago === 1) {
                // Depósito: insertar en preproceso (estado intermedio "En Proceso")
                DB::execute("
                    INSERT INTO preproceso (id_caja, codigo, fec_crea_preproceso, estado)
                    VALUES (:id_caja, :codigo, :fecha, 0)
                ", [
                    ':id_caja' => $cajaPago['id_caja'],
                    ':codigo'  => $codigo,
                    ':fecha'   => $fecPago,
                ]);

                // pos_operacion_log para trazar el preproceso
                DB::execute("
                    INSERT INTO pos_operacion_log (
                        uuid, tipo_operacion, id_referencia, tabla_referencia,
                        id_caja, cod_usuario, id_agencia, id_moneda,
                        monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                    ) VALUES (
                        :uuid, 'preproceso_remesa', :codigo, 'preproceso',
                        :id_caja, :usr, :ag, :mon,
                        :monto, :monto_bob, 'pendiente', :ip, :meta, :fecha
                    )
                ", [
                    ':uuid'      => $uuid,
                    ':codigo'    => $codigo,
                    ':id_caja'   => $cajaPago['id_caja'],
                    ':usr'       => $codUsuario,
                    ':ag'        => $idAgencia,
                    ':mon'       => $monRec,
                    ':monto'     => $monto,
                    ':monto_bob' => $monRec === 2 ? $monto : 0,
                    ':ip'        => $ip,
                    ':meta'      => json_encode([
                        'codigo' => $codigo,
                        'tipo' => 'preproceso_remesa',
                        'tipo_pago' => 'deposito',
                        'datos_bancarios' => $datosBancarios,
                        'destinatario_doc' => [
                            'id_tipo_doc_identidad' => $tipoDocD,
                            'num_doc_identidad' => mb_strtoupper($numDocD),
                            'id_expedicion' => $expDocD,
                        ],
                    ]),
                    ':fecha'     => $fecPago,
                ]);

                return [
                    'success'     => true,
                    'message'     => 'Remesa en proceso de acreditación por depósito.',
                    'preproceso'  => true,
                    'data'        => [
                        'preproceso' => true,
                        'codigo'     => $codigo,
                        'monto'      => $monto,
                        'moneda'     => $monedaAbr,
                        'fecha'      => $fecPago,
                    ],
                ];
            }

            // Efectivo: pago inmediato
            DB::execute("
                INSERT INTO pago (
                    id_forma_pago, id_tipo_pago, codigo, id_operacion,
                    id_agencia, cod_usuario, mon_rec, monto, tipo_cambio, total_bob,
                    fec_crea_pago, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                    comision_bob
                ) VALUES (
                    1, 1, :codigo, :op,
                    :ag, :usr, :mon, :monto, 1, :total_bob,
                    :fecha, :tipo_doc, :doc, :exp, 0
                )
            ", [
                ':codigo'    => $codigo,
                ':op'        => $idOperacion,
                ':ag'        => $idAgencia,
                ':usr'       => $codUsuario,
                ':mon'       => $monRec,
                ':monto'     => $monto,
                ':total_bob' => $monRec === 2 ? $monto : 0,
                ':fecha'     => $fecPago,
                ':tipo_doc'  => $tipoDocD,
                ':doc'       => mb_strtoupper($numDocD),
                ':exp'       => $expDocD,
            ]);
            $idPago = DB::lastInsertId();

            // Efectivo: saldo_caja negativo con conciliación SR
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, :fecha, 'SR')
            ", [
                ':id'    => $cajaPago['id_caja'],
                ':saldo' => -$monto,
                ':fecha' => $fecPago,
            ]);

            // pos_metodo_pago_operacion
            DB::execute("
                INSERT INTO pos_metodo_pago_operacion (
                    tipo_operacion, id_referencia, id_forma_pago, monto, moneda,
                    referencia_bancaria, id_agencia, created_at
                ) VALUES (
                    'pago_remesa', :id_ref, 1, :monto, 'BOB',
                    NULL, :ag, :fecha
                )
            ", [
                ':id_ref' => $idPago,
                ':monto'  => $monto,
                ':ag'     => $idAgencia,
                ':fecha'  => $fecPago,
            ]);

            // pos_operacion_log
            DB::execute("
                INSERT INTO pos_operacion_log (
                    uuid, tipo_operacion, id_referencia, tabla_referencia,
                    id_caja, cod_usuario, id_agencia, id_moneda,
                    monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                ) VALUES (
                    :uuid, 'pago_remesa', :id_ref, 'pago',
                    :id_caja, :usr, :ag, :mon,
                    :monto, :monto_bob, 'completado', :ip, :meta, :fecha
                )
            ", [
                ':uuid'      => $uuid,
                ':id_ref'    => $idPago,
                ':id_caja'   => $cajaPago['id_caja'],
                ':usr'       => $codUsuario,
                ':ag'        => $idAgencia,
                ':mon'       => $monRec,
                ':monto'     => $monto,
                ':monto_bob' => $monRec === 2 ? $monto : 0,
                ':ip'        => $ip,
                ':meta'      => json_encode(['codigo' => $codigo, 'tipo' => 'pago_remesa', 'tipo_pago' => 'efectivo']),
                ':fecha'     => $fecPago,
            ]);
            $idOperacionLog = DB::lastInsertId();

            // Asiento contable SC-PR
            $agenciaRow = DB::selectOne("SELECT cod_agencia, descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);
            $this->registrarAsientoContablePagoRemesa(
                $idOperacionLog, $codigo, $idAgencia,
                $agenciaRow['cod_agencia'] ?? '', $agenciaRow['descripcion'] ?? '',
                $monRec, $monto, $fecPago, $tipoPago, $datosBancarios
            );

            return [
                'success'   => true,
                'message'   => 'Remesa pagada correctamente.',
                'data'      => [
                    'id_pago'    => $idPago,
                    'codigo'     => $codigo,
                    'monto'      => $monto,
                    'moneda'     => $monedaAbr,
                    'fecha_pago' => $fecPago,
                ],
            ];
        });

        if (!$resultado['success']) {
            return $resultado;
        }

        // 7. Sincronizar con Chile
        $nuevoEstadoChile = ($resultado['preproceso'] ?? false) ? 7 : 1;
        $chileSynced = false;
        $syncPausado = $this->chileSyncPausado();

        if ($syncPausado) {
            DB::execute("
                INSERT INTO pos_sync_chile_pendiente (tipo, codigo, payload, estado, created_at)
                VALUES ('pago_remesa', :codigo, :payload, 'pendiente', :fecha)
            ", [
                ':codigo'  => $codigo,
                ':payload' => json_encode(['estado' => $nuevoEstadoChile]),
                ':fecha'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            try {
                DB::execute("
                    UPDATE tmp_reporte_remesa SET estado = :estado WHERE codigo = :c
                ", [':estado' => $nuevoEstadoChile, ':c' => $codigo], 'chile');
                $chileSynced = true;
            } catch (\Throwable $e) {
                DB::execute("
                    INSERT INTO pos_sync_chile_pendiente (tipo, codigo, payload, estado, created_at)
                    VALUES ('pago_remesa', :codigo, :payload, 'pendiente', :fecha)
                ", [
                    ':codigo'  => $codigo,
                    ':payload' => json_encode(['estado' => $nuevoEstadoChile]),
                    ':fecha'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $resultado['data']['chile_synced'] = $chileSynced;
        return $resultado;
    }

    public function confirmarPreproceso(
        int $codUsuario, string $loginUsuario, int $idAgencia,
        int $codigo, string $ip, string $uuid
    ): array {
        // Idempotencia
        $existente = DB::selectOne(
            "SELECT id_referencia FROM pos_operacion_log WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );
        if ($existente) {
            return ['success' => true, 'message' => 'Confirmación ya registrada.', 'data' => ['codigo' => $codigo]];
        }

        // Verificar que existe preproceso activo
        $pp = DB::selectOne("
            SELECT pp.id_caja, pp.codigo, pp.fec_crea_preproceso
            FROM preproceso pp
            WHERE pp.codigo = :c AND pp.estado = 0
            LIMIT 1
        ", [':c' => $codigo]);

        if (!$pp) {
            return ['success' => false, 'message' => 'No existe un preproceso activo para esta remesa.'];
        }

        // Recuperar metadata del preproceso (datos bancarios y doc destinatario) del log
        $logPreproceso = DB::selectOne("
            SELECT metadata FROM pos_operacion_log
            WHERE tipo_operacion = 'preproceso_remesa' AND id_referencia = :c AND tabla_referencia = 'preproceso'
            ORDER BY created_at DESC LIMIT 1
        ", [':c' => $codigo]);

        $meta = $logPreproceso ? json_decode($logPreproceso['metadata'], true) : [];
        $datosBancarios = $meta['datos_bancarios'] ?? [];
        $destDoc = $meta['destinatario_doc'] ?? [];

        // Fetch remesa de Chile
        $remesa = DB::selectOne("
            SELECT codigo, moneda, envio, id_operacion, id_destino
            FROM tmp_reporte_remesa
            WHERE codigo = :c
            LIMIT 1
        ", [':c' => $codigo], 'chile');

        if (!$remesa) {
            return ['success' => false, 'message' => 'Remesa no encontrada en Chile.'];
        }

        $monedaAbr = $remesa['moneda'] ?? 'BOB';
        $monRec = $monedaAbr === 'USD' ? 1 : 2;
        $monto = (float) $remesa['envio'];
        $idOperacion = (int) ($remesa['id_operacion'] ?? 4);
        $fecConfirmacion = date('Y-m-d H:i:s');

        $cajaPago = DB::selectOne("
            SELECT c.id_caja FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $monRec]);

        if (!$cajaPago) {
            return ['success' => false, 'message' => "No tiene caja asignada para la moneda {$monedaAbr}."];
        }

        $resultado = DB::transaction(function () use (
            $codUsuario, $loginUsuario, $idAgencia, $codigo, $monto, $monRec, $monedaAbr,
            $cajaPago, $fecConfirmacion, $ip, $uuid, $destDoc, $datosBancarios, $idOperacion
        ) {
            // Completar preproceso
            DB::execute("
                UPDATE preproceso SET estado = 1, fec_mod_preproceso = :fecha
                WHERE codigo = :c AND estado = 0
            ", [':fecha' => $fecConfirmacion, ':c' => $codigo]);

            // Crear pago
            $tipoDocD = (int) ($destDoc['id_tipo_doc_identidad'] ?? 0);
            $numDocD  = trim($destDoc['num_doc_identidad'] ?? '');
            $expDocD  = (int) ($destDoc['id_expedicion'] ?? 0);

            DB::execute("
                INSERT INTO pago (
                    id_forma_pago, id_tipo_pago, codigo, id_operacion,
                    id_agencia, cod_usuario, mon_rec, monto, tipo_cambio, total_bob,
                    fec_crea_pago, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                    comision_bob
                ) VALUES (
                    2, 1, :codigo, :op,
                    :ag, :usr, :mon, :monto, 1, :total_bob,
                    :fecha, :tipo_doc, :doc, :exp, 0
                )
            ", [
                ':codigo'    => $codigo,
                ':op'        => $idOperacion,
                ':ag'        => $idAgencia,
                ':usr'       => $codUsuario,
                ':mon'       => $monRec,
                ':monto'     => $monto,
                ':total_bob' => $monRec === 2 ? $monto : 0,
                ':fecha'     => $fecConfirmacion,
                ':tipo_doc'  => $tipoDocD,
                ':doc'       => mb_strtoupper($numDocD),
                ':exp'       => $expDocD,
            ]);
            $idPago = DB::lastInsertId();

            // Movimiento cuenta bancaria
            $numCuenta    = $datosBancarios['num_cuenta_bancaria'] ?? '';
            $idEntidad    = (int) ($datosBancarios['id_entidad_financiera'] ?? 0);
            $idMonCuenta  = (int) ($datosBancarios['id_moneda_cuenta'] ?? $monRec);
            $idTipoCuenta = (int) ($datosBancarios['id_tipo_cuenta_bancaria'] ?? 1);

            DB::execute("
                INSERT INTO movimiento_cuenta_bancaria (
                    id_entidad_financiera, num_cuenta_bancaria, id_tipo_cuenta_bancaria,
                    id_moneda, id_agencia, monto, glosa, conciliacion, fec_crea_movimiento_cuenta_bancaria
                ) VALUES (
                    :entidad, :cuenta, :tipo_cuenta,
                    :moneda, :ag, :monto, :glosa, 'LR', :fecha
                )
            ", [
                ':entidad'      => $idEntidad,
                ':cuenta'       => $numCuenta,
                ':tipo_cuenta'  => $idTipoCuenta,
                ':moneda'       => $idMonCuenta,
                ':ag'           => $idAgencia,
                ':monto'        => -$monto,
                ':glosa'        => "PAGO REMESA #{$codigo} - DEPOSITO A CUENTA",
                ':fecha'        => $fecConfirmacion,
            ]);

            // pos_metodo_pago_operacion
            DB::execute("
                INSERT INTO pos_metodo_pago_operacion (
                    tipo_operacion, id_referencia, id_forma_pago, monto, moneda,
                    referencia_bancaria, id_agencia, created_at
                ) VALUES (
                    'pago_remesa', :id_ref, 2, :monto, 'BOB',
                    :ref_bancaria, :ag, :fecha
                )
            ", [
                ':id_ref'       => $idPago,
                ':monto'        => $monto,
                ':ref_bancaria' => $numCuenta,
                ':ag'           => $idAgencia,
                ':fecha'        => $fecConfirmacion,
            ]);

            // pos_operacion_log
            DB::execute("
                INSERT INTO pos_operacion_log (
                    uuid, tipo_operacion, id_referencia, tabla_referencia,
                    id_caja, cod_usuario, id_agencia, id_moneda,
                    monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                ) VALUES (
                    :uuid, 'pago_remesa', :id_ref, 'pago',
                    :id_caja, :usr, :ag, :mon,
                    :monto, :monto_bob, 'completado', :ip, :meta, :fecha
                )
            ", [
                ':uuid'      => $uuid,
                ':id_ref'    => $idPago,
                ':id_caja'   => $cajaPago['id_caja'],
                ':usr'       => $codUsuario,
                ':ag'        => $idAgencia,
                ':mon'       => $monRec,
                ':monto'     => $monto,
                ':monto_bob' => $monRec === 2 ? $monto : 0,
                ':ip'        => $ip,
                ':meta'      => json_encode(['codigo' => $codigo, 'tipo' => 'pago_remesa', 'tipo_pago' => 'deposito', 'confirmacion' => true]),
                ':fecha'     => $fecConfirmacion,
            ]);
            $idOperacionLog = DB::lastInsertId();

            // Asiento contable SC-PR
            $agenciaRow = DB::selectOne("SELECT cod_agencia, descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);
            $this->registrarAsientoContablePagoRemesa(
                $idOperacionLog, $codigo, $idAgencia,
                $agenciaRow['cod_agencia'] ?? '', $agenciaRow['descripcion'] ?? '',
                $monRec, $monto, $fecConfirmacion, 1, $datosBancarios
            );

            return [
                'success'    => true,
                'message'    => 'Depósito confirmado. Pago de remesa completado.',
                'data'       => [
                    'id_pago'    => $idPago,
                    'codigo'     => $codigo,
                    'monto'      => $monto,
                    'moneda'     => $monedaAbr,
                    'fecha_pago' => $fecConfirmacion,
                ],
            ];
        });

        if (!$resultado['success']) {
            return $resultado;
        }

        // Sincronizar con Chile: estado 7 → 1
        $chileSynced = false;
        $syncPausado = $this->chileSyncPausado();

        if ($syncPausado) {
            DB::execute("
                INSERT INTO pos_sync_chile_pendiente (tipo, codigo, payload, estado, created_at)
                VALUES ('pago_remesa', :codigo, :payload, 'pendiente', :fecha)
            ", [
                ':codigo'  => $codigo,
                ':payload' => json_encode(['estado' => 1]),
                ':fecha'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            try {
                DB::execute("
                    UPDATE tmp_reporte_remesa SET estado = 1 WHERE codigo = :c
                ", [':c' => $codigo], 'chile');
                $chileSynced = true;
            } catch (\Throwable $e) {
                DB::execute("
                    INSERT INTO pos_sync_chile_pendiente (tipo, codigo, payload, estado, created_at)
                    VALUES ('pago_remesa', :codigo, :payload, 'pendiente', :fecha)
                ", [
                    ':codigo'  => $codigo,
                    ':payload' => json_encode(['estado' => 1]),
                    ':fecha'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $resultado['data']['chile_synced'] = $chileSynced;
        return $resultado;
    }

    public function preprocesosActivos(int $idAgencia): array
    {
        $rows = DB::select("
            SELECT pp.codigo, pp.id_caja, pp.fec_crea_preproceso,
                   c.id_agencia, u.login_usu AS cajero,
                   a.descripcion AS agencia_desc
            FROM preproceso pp
            INNER JOIN caja c ON c.id_caja = pp.id_caja
            LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE pp.estado = 0 AND c.id_agencia = :ag
            ORDER BY pp.fec_crea_preproceso DESC
        ", [':ag' => $idAgencia]);

        $codigos = array_column($rows, 'codigo');
        $remesaData = [];
        $logData = [];
        if (!empty($codigos)) {
            $codigoList = implode(',', array_map('intval', $codigos));
            try {
                $remesas = DB::select("
                    SELECT codigo, usuario_financiero, destinatario, moneda, envio, abr_ori, abr_des, fec_crea_remesa
                    FROM tmp_reporte_remesa
                    WHERE codigo IN ({$codigoList})
                ", [], 'chile');
                foreach ($remesas as $r) {
                    $remesaData[(int) $r['codigo']] = $r;
                }
            } catch (\Throwable $e) {
                // Chile DB unavailable
            }

            $logs = DB::select("
                SELECT id_referencia, metadata
                FROM pos_operacion_log
                WHERE tipo_operacion = 'preproceso_remesa'
                  AND id_referencia IN ({$codigoList})
                  AND estado = 'pendiente'
            ");
            foreach ($logs as $l) {
                $logData[(int) $l['id_referencia']] = json_decode($l['metadata'] ?? '{}', true) ?: [];
            }
        }

        return array_map(function ($pp) use ($remesaData, $logData) {
            $cod = (int) $pp['codigo'];
            $rem = $remesaData[$cod] ?? null;
            $meta = $logData[$cod] ?? [];
            $banco = $meta['datos_bancarios'] ?? [];

            // Los lotes antiguos no guardaban estos datos en metadata. En ese
            // caso se reconstruyen desde el formato que entrega Chile:
            // NOMBRE // BANCO // CUENTA (MONEDA).
            $partesDest = array_map('trim', explode('//', (string) ($rem['destinatario'] ?? '')));
            $bancoDesc = $banco['entidad_desc'] ?? ($partesDest[1] ?? '');
            $numCuenta = $banco['num_cuenta_bancaria'] ?? ($partesDest[2] ?? '');
            $numCuenta = preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $numCuenta) ?: '';

            return [
                'codigo'       => $cod,
                'cajero'       => $pp['cajero'] ?? '',
                'fechaInicio'  => $pp['fec_crea_preproceso'],
                'remitente'    => $rem['usuario_financiero'] ?? '',
                'destinatario' => $rem['destinatario'] ?? '',
                'monto'        => (float) ($rem['envio'] ?? 0),
                'moneda'       => $rem['moneda'] ?? 'BOB',
                'origen'       => $rem['abr_ori'] ?? '',
                'destino'      => $rem['abr_des'] ?? '',
                'fechaRemesa'  => $rem['fec_crea_remesa'] ?? '',
                'agenciaPago'  => $pp['agencia_desc'] ?? '',
                'banco'        => $bancoDesc,
                'numCuenta'    => $numCuenta,
            ];
        }, $rows);
    }

    public function obtenerComprobantePago(int $idPago): ?array
    {
        $p = DB::selectOne("
            SELECT p.*, m.abreviatura AS moneda_abr,
                   a.descripcion AS agencia, a.cod_agencia,
                   u.login_usu AS login_usuario,
                   td.abreviatura AS tipo_doc_abr,
                   ex.abreviatura AS expedicion_abr
            FROM pago p
            JOIN moneda m ON m.id_moneda = p.mon_rec
            JOIN agencia a ON a.id_agencia = p.id_agencia
            LEFT JOIN usuario u ON u.cod_usuario = p.cod_usuario
            LEFT JOIN tipo_doc_identidad td ON td.id_tipo_doc_identidad = p.id_tipo_doc_identidad
            LEFT JOIN expedicion ex ON ex.id_expedicion = p.id_expedicion
            WHERE p.id_pago = :id
        ", [':id' => $idPago]);

        if (!$p) return null;

        $remesa = DB::selectOne("
            SELECT usuario_financiero, destinatario, doc_des, abr_ori, abr_des
            FROM tmp_reporte_remesa WHERE codigo = :c LIMIT 1
        ", [':c' => $p['codigo']], 'chile');

        return [
            'id_pago'       => $idPago,
            'codigo'        => $p['codigo'],
            'monto'         => (float) $p['monto'],
            'moneda'        => $p['moneda_abr'],
            'fecha_pago'    => $p['fec_crea_pago'],
            'agencia'       => $p['agencia'],
            'cod_agencia'   => $p['cod_agencia'] ?? '',
            'cajero'        => $p['login_usuario'] ?? '',
            'tipo_pago'     => (int) $p['id_forma_pago'] === 1 ? 'EFECTIVO POR VENTANILLA' : 'DEPOSITO A CUENTA BANCARIA',
            'doc_beneficiario' => trim(($p['tipo_doc_abr'] ?? '') . ' ' . ($p['num_doc_identidad'] ?? '') . ' ' . ($p['expedicion_abr'] ?? '')),
            'remitente'     => $remesa['usuario_financiero'] ?? '',
            'destinatario'  => $remesa['destinatario'] ?? '',
            'doc_destinatario' => $remesa['doc_des'] ?? '',
            'origen'        => $remesa['abr_ori'] ?? '',
            'destino'       => $remesa['abr_des'] ?? '',
        ];
    }

    public function comprobantePagoPdf(int $idPago): array
    {
        $data = $this->obtenerComprobantePago($idPago);
        if (!$data) {
            abort(404, 'Pago no encontrado.');
        }

        $logoPath = public_path('images/logo_texto.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }

        $n = function ($v) { return number_format($v, 2, '.', ','); };
        $dt = new \DateTime($data['fecha_pago']);
        $fechaFmt = $dt->format('d-m-Y');
        $horaFmt  = $dt->format('H:i:s');
        $codigo   = $data['codigo'];

        $qrContent = "https://www.gambarte.com.bo/pago/{$codigo}";
        $qrBase64  = $this->generarQrBase64($qrContent);

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<style>
  @page { size: 72mm 280mm; margin: 2mm; }
  body { font-family: 'Courier New', Courier, monospace; font-size: 11px; margin: 0; padding: 0; color: #000; line-height: 1.3; width: 68mm; word-wrap: break-word; }
  .center { text-align: center; }
  .bold { font-weight: bold; }
  .logo { width: 48mm; display: block; margin: 0 auto 2px; }
  .site { font-size: 10px; text-align: center; margin: 1px 0; }
  .phone { font-size: 10px; text-align: center; margin-bottom: 2px; }
  .title { font-size: 14px; font-weight: bold; text-align: center; margin: 4px 0; letter-spacing: 1px; }
  .sep { border-top: 1px solid #000; margin: 2px 0; }
  .subtitle { text-align: center; font-size: 10px; font-weight: bold; margin: 2px 0; }
  .section-hdr { text-align: center; font-weight: bold; font-size: 11px; border-bottom: 1px solid #000; margin: 4px 0 1px; padding-bottom: 1px; }
  .t { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 11px; }
  .t td { padding: 1px 0; vertical-align: top; word-wrap: break-word; }
  .t .lb { font-weight: bold; text-align: left; width: 50%; }
  .t .vr { text-align: right; }
  .t4 { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 2px; }
  .t4 td { padding: 1px 0; vertical-align: top; }
  .t4 .lb { font-weight: bold; text-align: left; }
  .sign-tbl { width: 100%; margin-top: 35px; font-size: 11px; border-collapse: collapse; }
  .sign-tbl td { text-align: center; width: 50%; vertical-align: bottom; padding: 0 4px; }
  .sign-line { border-bottom: 1px solid #000; height: 1px; margin-bottom: 2px; }
  .nota { font-size: 8px; margin-top: 3px; text-align: center; }
  .codigo-row { table-layout: fixed; width: 100%; border-collapse: collapse; margin: 2px 0; }
  .codigo-row td { vertical-align: middle; width: 50%; }
  .codigo-qr { text-align: right; padding-right: 2mm; }
  .codigo-qr img { width: 23mm; height: 23mm; }
  .codigo-txt { text-align: left; padding-left: 2mm; font-weight: bold; }
  .codigo-txt .lbl { font-size: 12px; font-style: italic; text-decoration: underline; }
  .codigo-txt .num { font-size: 22px; }
</style>
</head><body>
  <div class="center">
    <img src="{$logoBase64}" class="logo" alt="Gambarte">
    <div class="site">www.gambarte.com.bo</div>
    <div class="phone">TELEFONO DE CONTACTO: 68355517</div>
    <div class="title">COMPROBANTE DE PAGO</div>
  </div>
  <div class="sep"></div>

  <table class="codigo-row">
    <tr>
      <td class="codigo-qr"><img src="{$qrBase64}" alt="QR"></td>
      <td class="codigo-txt"><div class="lbl">CODIGO</div><div class="num">{$codigo}</div></td>
    </tr>
  </table>

  <div class="sep"></div>
  <div class="subtitle">PAGO DE REMESA INTERNACIONAL<br>AGENCIA {$data['agencia']}</div>
  <div class="sep"></div>

  <table class="t4">
    <tr>
      <td class="lb" style="width:18%;">FECHA</td>
      <td style="width:32%;">{$fechaFmt}</td>
      <td class="lb" style="width:15%;">HORA</td>
      <td style="width:35%;">{$horaFmt}</td>
    </tr>
    <tr>
      <td class="lb">CAGR</td>
      <td>{$data['cod_agencia']}</td>
      <td class="lb">OPE</td>
      <td>{$data['cajero']}</td>
    </tr>
  </table>

  <div class="section-hdr">REMITENTE</div>
  <table class="t">
    <tr><td class="lb">NOMBRE</td><td>{$data['remitente']}</td></tr>
    <tr><td class="lb">ORIGEN</td><td>{$data['origen']}</td></tr>
  </table>

  <div class="section-hdr">BENEFICIARIO</div>
  <table class="t">
    <tr><td class="lb">NOMBRE</td><td>{$data['destinatario']}</td></tr>
    <tr><td class="lb">DOCUMENTO</td><td>{$data['doc_beneficiario']}</td></tr>
    <tr><td class="lb">DESTINO</td><td>{$data['destino']}</td></tr>
  </table>

  <div class="section-hdr">DETALLE DEL PAGO</div>
  <table class="t">
    <tr><td class="lb">MONTO [{$data['moneda']}]</td><td class="vr">{$n($data['monto'])}</td></tr>
    <tr><td class="lb">FORMA DE PAGO</td><td class="vr">{$data['tipo_pago']}</td></tr>
  </table>

  <div class="sep"></div>
  <table class="sign-tbl">
    <tr>
      <td><div class="sign-line"></div>CAJERO</td>
      <td><div class="sign-line"></div>BENEFICIARIO</td>
    </tr>
  </table>

  <div class="nota">ESTE DOCUMENTO ES UN COMPROBANTE DE PAGO DE REMESA INTERNACIONAL</div>
</body></html>
HTML;

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Courier');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, 204.09, 793.70], 'portrait');
        $dompdf->render();

        return [
            'binary'   => $dompdf->output(),
            'filename' => "pago_remesa_{$codigo}.pdf",
        ];
    }

    public function enviarComprobantePagoEmail(int $idPago, string $email): array
    {
        $data = $this->obtenerComprobantePago($idPago);
        if (!$data) {
            return ['success' => false, 'message' => 'Pago no encontrado.'];
        }

        $n = function ($v) { return number_format($v, 2, '.', ','); };
        $codigo = $data['codigo'];

        $contenido = "
        <div style='font-family:Arial,sans-serif; max-width:500px; margin:0 auto;'>
            <div style='text-align:center; padding:10px;'>
                <img src='cid:gambarte_logo' alt='Gambarte' style='width:200px;'>
            </div>
            <h2 style='color:#F58634; text-align:center;'>Comprobante de Pago de Remesa</h2>
            <table style='width:100%; border-collapse:collapse; font-size:14px;'>
                <tr><td style='padding:6px; font-weight:bold;'>Codigo:</td><td style='padding:6px;'>{$codigo}</td></tr>
                <tr><td style='padding:6px; font-weight:bold;'>Remitente:</td><td style='padding:6px;'>{$data['remitente']}</td></tr>
                <tr><td style='padding:6px; font-weight:bold;'>Beneficiario:</td><td style='padding:6px;'>{$data['destinatario']}</td></tr>
                <tr><td style='padding:6px; font-weight:bold;'>Monto:</td><td style='padding:6px;'>{$n($data['monto'])} {$data['moneda']}</td></tr>
                <tr><td style='padding:6px; font-weight:bold;'>Forma de Pago:</td><td style='padding:6px;'>{$data['tipo_pago']}</td></tr>
                <tr><td style='padding:6px; font-weight:bold;'>Fecha:</td><td style='padding:6px;'>{$data['fecha_pago']}</td></tr>
                <tr><td style='padding:6px; font-weight:bold;'>Agencia:</td><td style='padding:6px;'>{$data['agencia']}</td></tr>
            </table>
            <p style='font-size:12px; color:#666; text-align:center; margin-top:20px;'>Gambarte - Giros y Remesas de Dinero</p>
        </div>";

        $pdfResult = $this->comprobantePagoPdf($idPago);
        $adjuntos = [['data' => $pdfResult['binary'], 'nombre' => $pdfResult['filename'], 'mime' => 'application/pdf']];

        $this->enviarOEncolar($email, "Gambarte - Comprobante de Pago Remesa #{$codigo}", $contenido, $idPago, $adjuntos);

        return ['success' => true, 'message' => 'Comprobante enviado.'];
    }

    public function subirBoletaPagoRemesa(int $codigo, \Illuminate\Http\UploadedFile $file): array
    {
        $pago = DB::selectOne("
            SELECT p.id_pago, p.codigo, p.id_forma_pago
            FROM pago p
            WHERE p.codigo = :c AND p.id_tipo_pago = 1
            ORDER BY p.fec_crea_pago DESC LIMIT 1
        ", [':c' => $codigo]);

        if (!$pago) {
            return ['success' => false, 'message' => 'No se encontró un pago registrado para esta remesa.'];
        }

        $formaPago = (int) $pago['id_forma_pago'];
        $subdir = $formaPago === 2 ? 'deposito' : 'efectivo';

        $root = env('CGR_PUBLIC_PATH', '/home/u752765750/domains/cgr.owl-tech.net/public_html');
        $dir = $root . '/boletas/' . $codigo;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext = strtolower($file->getClientOriginalExtension());

        foreach (['jpg', 'jpeg', 'png', 'pdf'] as $oldExt) {
            $oldFile = $dir . '/' . $subdir . '.' . $oldExt;
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }

        $file->move($dir, $subdir . '.' . $ext);

        $url = env('CGR_PUBLIC_URL', 'https://cgr.owl-tech.net') . '/boletas/' . $codigo . '/' . $subdir . '.' . $ext;

        return ['success' => true, 'message' => 'Comprobante subido correctamente.', 'data' => ['url' => $url]];
    }

    private function registrarAsientoContablePagoRemesa(
        int $idOperacionLog, int $codigo, int $idAgencia,
        string $codAgencia, string $agenciaDesc,
        int $monRec, float $monto, string $fecha,
        int $tipoPago, array $datosBancarios
    ): void {
        $codejercicio = DB::scalar(
            "SELECT codejercicio FROM ejercicios WHERE estado = 'ABIERTO' LIMIT 1",
            [], 'conta'
        );
        if (!$codejercicio) {
            $this->encolarAsientoPendienteRemesa($idOperacionLog, $fecha, 'No hay ejercicio contable abierto');
            return;
        }

        $oficial = (float) DB::scalar("
            SELECT co.valor_me FROM cotizacion_oficial co
            WHERE co.id_moneda = 1 AND co.id_operacion = 1 AND co.estado = 'A'
            ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1
        ") ?: 1.0;

        try {
            $fechaAsiento = substr($fecha, 0, 10);
            $sigue = (int) DB::scalar(
                "SELECT COALESCE(MAX(numero), 0) FROM co_asientos WHERE codejercicio = :ej",
                [':ej' => $codejercicio], 'conta'
            ) + 1;

            $montoBob = $monRec === 2 ? $monto : round($monto * $oficial, 2);
            $montoMe  = $monRec === 2 ? round($monto / $oficial, 2) : $monto;

            $glosa = mb_strtoupper("PAGO DE REMESA INTERNACIONAL #{$codigo} - {$agenciaDesc}");

            DB::execute("
                INSERT INTO co_asientos (
                    codejercicio, codplanasiento, concepto, documento, editable,
                    fecha, idasiento, idconcepto, importe, numero, tipodocumento,
                    cod_usuario, fecha_trans, origen
                ) VALUES (
                    :ej, NULL, :concepto, '', 0,
                    :fecha, '', :idconcepto, 0, :numero, 'SC-PR',
                    'CGR', :fecha_t, 'POS'
                )
            ", [
                ':ej'         => $codejercicio,
                ':concepto'   => $glosa,
                ':fecha'      => $fechaAsiento,
                ':idconcepto' => $codAgencia,
                ':numero'     => $sigue,
                ':fecha_t'    => $fecha,
            ], 'conta');

            $idasiento = DB::lastInsertId('conta');

            // Remesa por pagar (DEBE) - revierte la obligación
            $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_REMESA_POR_PAGAR, $codAgencia, $codigo,
                $montoBob, $montoMe, 0, 0, $oficial);

            if ($tipoPago === 0) {
                // Efectivo: Salida caja (HABER)
                $subcuentaCaja = $monRec === 1 ? self::SUBCUENTA_CAJA_USD : self::SUBCUENTA_CAJA_BOB;
                $this->insertPartida($idasiento, $codejercicio, $subcuentaCaja, $codAgencia, $codigo,
                    0, 0, $montoBob, $montoMe, $oficial);
            } else {
                // Depósito: Salida banco (HABER)
                $codsubcuenta = $datosBancarios['codsubcuenta'] ?? '';
                if (!$codsubcuenta) {
                    $codsubcuenta = DB::scalar("
                        SELECT codsubcuenta FROM cuenta_bancaria
                        WHERE num_cuenta_bancaria = :cuenta AND id_entidad_financiera = :entidad AND estado = 'A'
                        LIMIT 1
                    ", [
                        ':cuenta'  => $datosBancarios['num_cuenta_bancaria'] ?? '',
                        ':entidad' => (int) ($datosBancarios['id_entidad_financiera'] ?? 0),
                    ]) ?: self::SUBCUENTA_CAJA_BOB;
                }
                $this->insertPartida($idasiento, $codejercicio, $codsubcuenta, $codAgencia, $codigo,
                    0, 0, $montoBob, $montoMe, $oficial);
            }

            DB::execute("
                UPDATE co_asientos SET importe = (
                    SELECT COALESCE(SUM(debe), 0) FROM co_partidas WHERE idasiento = :id
                ) WHERE idasiento = :id2
            ", [':id' => $idasiento, ':id2' => $idasiento], 'conta');

        } catch (\Throwable $e) {
            $this->encolarAsientoPendienteRemesa($idOperacionLog, $fecha, $e->getMessage());
        }
    }

    public function reporteRemesas(int $idAgencia, string $desde, string $hasta, ?int $estado = null, ?int $codigo = null, ?int $tipoPago = null): array
    {
        $estadoMap = [0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO', 5 => 'ANULADO', 7 => 'EN PROCESO'];

        $sql = "
            SELECT codigo, moneda, envio, usuario_financiero, destinatario,
                   doc_des, fec_crea_remesa, abr_ori, abr_des, id_destino, id_operacion, estado
            FROM tmp_reporte_remesa
            WHERE id_destino = :ag
              AND DATE(fec_crea_remesa) BETWEEN :desde AND :hasta
        ";
        $params = [':ag' => $idAgencia, ':desde' => $desde, ':hasta' => $hasta];

        if ($estado !== null) {
            $sql .= " AND estado = :estado";
            $params[':estado'] = $estado;
        }
        if ($codigo !== null) {
            $sql .= " AND codigo = :codigo";
            $params[':codigo'] = $codigo;
        }

        $sql .= " ORDER BY fec_crea_remesa DESC";

        $remesas = DB::select($sql, $params, 'chile');

        $codigos = array_unique(array_column($remesas, 'codigo'));
        $pagosLocal = [];
        $preprocesoLocal = [];
        if (!empty($codigos)) {
            $codigoList = implode(',', array_map('intval', $codigos));
            $pagos = DB::select("
                SELECT p.codigo, p.id_pago, p.fec_crea_pago, p.id_forma_pago,
                       u.login_usu AS cajero, a.descripcion AS agencia_pago
                FROM pago p
                LEFT JOIN usuario u ON u.cod_usuario = p.cod_usuario
                LEFT JOIN agencia a ON a.id_agencia = p.id_agencia
                WHERE p.codigo IN ({$codigoList})
                  AND p.id_tipo_pago = 1
                  AND p.id_operacion IN (4, 7, 8, 16)
            ");
            foreach ($pagos as $p) {
                $pagosLocal[(int) $p['codigo']] = $p;
            }

            $preprocesos = DB::select("
                SELECT pp.codigo, pp.fec_crea_preproceso,
                       u.login_usu AS cajero, a.descripcion AS agencia_pp
                FROM preproceso pp
                INNER JOIN caja c ON c.id_caja = pp.id_caja
                LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
                LEFT JOIN agencia a ON a.id_agencia = c.id_agencia
                WHERE pp.codigo IN ({$codigoList})
                  AND pp.estado = 0
            ");
            foreach ($preprocesos as $pp) {
                $preprocesoLocal[(int) $pp['codigo']] = $pp;
            }
        }

        $result = array_map(function ($r) use ($pagosLocal, $preprocesoLocal, $estadoMap) {
            $cod = (int) $r['codigo'];
            $est = (int) $r['estado'];
            $pago = $pagosLocal[$cod] ?? null;
            $pp = $preprocesoLocal[$cod] ?? null;

            return [
                'codigo'          => $cod,
                'monto'           => round((float) ($r['envio'] ?? 0), 2),
                'moneda'          => $r['moneda'] ?? 'BOB',
                'fechaEnvio'      => $r['fec_crea_remesa'] ?? '',
                'remitente'       => $r['usuario_financiero'] ?? '',
                'destinatario'    => $r['destinatario'] ?? '',
                'docDestinatario' => $r['doc_des'] ?? '',
                'origen'          => $r['abr_ori'] ?? '',
                'destino'         => $r['abr_des'] ?? '',
                'estado'          => $est,
                'estadoDesc'      => $estadoMap[$est] ?? "Estado {$est}",
                'idPago'          => $pago ? (int) $pago['id_pago'] : null,
                'fechaPago'       => $pago['fec_crea_pago'] ?? null,
                'formaPago'       => $pago ? (int) $pago['id_forma_pago'] : null,
                'formaPagoDesc'   => $pago ? ((int) $pago['id_forma_pago'] === 1 ? 'Efectivo' : 'Deposito') : null,
                'cajero'          => $pago['cajero'] ?? ($pp['cajero'] ?? null),
                'agenciaPago'     => $pago['agencia_pago'] ?? ($pp['agencia_pp'] ?? null),
                'fechaPreproceso' => $pp['fec_crea_preproceso'] ?? null,
            ];
        }, $remesas);

        if ($tipoPago !== null) {
            $result = array_values(array_filter($result, function ($r) use ($tipoPago) {
                return $r['formaPago'] === $tipoPago;
            }));
        }

        return $result;
    }

    public function reintentarSyncChile(): array
    {
        if ($this->chileSyncPausado()) {
            return [
                'completados' => 0,
                'fallidos' => 0,
                'total' => 0,
                'pausado' => true,
                'mensaje' => 'La sincronización con Chile está pausada; no se realizaron escrituras.',
            ];
        }

        $pendientes = DB::select("
            SELECT id, tipo, codigo, payload, intentos, max_intentos
            FROM pos_sync_chile_pendiente
            WHERE estado = 'pendiente' AND intentos < max_intentos
            ORDER BY created_at
            LIMIT 50
        ");

        $resultados = ['completados' => 0, 'fallidos' => 0, 'total' => count($pendientes)];

        foreach ($pendientes as $p) {
            $payload = json_decode($p['payload'], true);
            $now = date('Y-m-d H:i:s');

            try {
                if ($p['tipo'] === 'pago_remesa') {
                    $nuevoEstado = $payload['estado'] ?? 1;
                    DB::execute("
                        UPDATE tmp_reporte_remesa SET estado = :estado WHERE codigo = :c
                    ", [':estado' => $nuevoEstado, ':c' => (int) $p['codigo']], 'chile');
                }

                DB::execute("
                    UPDATE pos_sync_chile_pendiente
                    SET estado = 'completado', completed_at = :now, updated_at = :now2
                    WHERE id = :id
                ", [':now' => $now, ':now2' => $now, ':id' => $p['id']]);

                $resultados['completados']++;
            } catch (\Throwable $e) {
                $intentos = (int) $p['intentos'] + 1;
                $nuevoEstado = $intentos >= (int) $p['max_intentos'] ? 'fallido' : 'pendiente';

                DB::execute("
                    UPDATE pos_sync_chile_pendiente
                    SET intentos = :int, ultimo_error = :err, estado = :est, updated_at = :now
                    WHERE id = :id
                ", [
                    ':int' => $intentos,
                    ':err' => mb_substr($e->getMessage(), 0, 500),
                    ':est' => $nuevoEstado,
                    ':now' => $now,
                    ':id'  => $p['id'],
                ]);

                $resultados['fallidos']++;
            }
        }

        return $resultados;
    }
}
