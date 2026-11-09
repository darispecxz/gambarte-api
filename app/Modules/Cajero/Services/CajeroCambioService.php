<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;
use App\Modules\Facturacion\FacturacionService;
use App\Modules\Cajero\Services\TripleOperacionService;

class CajeroCambioService
{
    private CajeroBalanceService $balance;

    public function __construct(CajeroBalanceService $balance)
    {
        $this->balance = $balance;
    }

    public function cotizaciones(int $idAgencia): array
    {
        $rows = DB::select("
            SELECT cb.id_moneda, m.abreviatura AS moneda, m.descripcion AS moneda_desc,
                   cb.id_operacion, cb.valor_base
            FROM cotizacion_base cb
            JOIN (
                SELECT id_moneda, id_operacion, MAX(fecha_creacion) AS max_fecha
                FROM cotizacion_base
                WHERE id_agencia = :ag2
                  AND id_operacion IN (1, 2)
                  AND id_moneda != 2
                GROUP BY id_moneda, id_operacion
            ) ult ON ult.id_moneda = cb.id_moneda
                 AND ult.id_operacion = cb.id_operacion
                 AND ult.max_fecha = cb.fecha_creacion
            JOIN moneda m ON m.id_moneda = cb.id_moneda
            WHERE cb.id_agencia = :ag
              AND cb.id_operacion IN (1, 2)
              AND cb.id_moneda != 2
            ORDER BY cb.id_moneda, cb.id_operacion
        ", [':ag' => $idAgencia, ':ag2' => $idAgencia]);

        $map = [];
        foreach ($rows as $r) {
            $mid = (int) $r['id_moneda'];
            if (!isset($map[$mid])) {
                $map[$mid] = [
                    'id_moneda'   => $mid,
                    'moneda'      => $r['moneda'],
                    'moneda_desc' => $r['moneda_desc'],
                    'compra'      => null,
                    'venta'       => null,
                ];
            }
            if ((int) $r['id_operacion'] === 1) {
                $map[$mid]['compra'] = (float) $r['valor_base'];
            } else {
                $map[$mid]['venta'] = (float) $r['valor_base'];
            }
        }

        return array_values($map);
    }

    public function actualizarCliente(array $cliente): int
    {
        $fecha = date('Y-m-d H:i:s');
        return $this->upsertUsuarioFinanciero($cliente, $fecha);
    }

    public function buscarCliente(string $documento, int $tipoDoc, int $expedicion): ?array
    {
        return DB::selectOne("
            SELECT uf.*
            FROM usuario_financiero uf
            WHERE uf.num_doc_identidad = :doc
              AND uf.id_tipo_doc_identidad = :tipo
              AND uf.id_expedicion = :exp
            LIMIT 1
        ", [
            ':doc'  => $documento,
            ':tipo' => $tipoDoc,
            ':exp'  => $expedicion,
        ]);
    }

    public function catalogos(int $idAgencia = 0): array
    {
        $tiposDoc = DB::select("SELECT * FROM tipo_doc_identidad WHERE estado = 'A' ORDER BY id_tipo_doc_identidad");
        $expediciones = DB::select("SELECT * FROM expedicion WHERE estado = 'A' ORDER BY id_expedicion");
        $monedas = DB::select("SELECT * FROM moneda WHERE estado = 'A' ORDER BY id_moneda");
        $sexos = DB::select("SELECT * FROM sexo WHERE estado = 'A' ORDER BY id_sexo");
        $estadosCiviles = DB::select("SELECT * FROM estado_civil WHERE estado = 'A' ORDER BY id_estado_civil");
        $ocupaciones = DB::select("SELECT * FROM ocupacion WHERE estado = 'A' ORDER BY id_ocupacion");
        $actividadesEconomicas = DB::select("SELECT * FROM actividad_economica WHERE estado = 'A' ORDER BY id_actividad_economica");
        $paises = DB::select("SELECT * FROM pais WHERE estado = 'A' ORDER BY descripcion");
        $departamentos = DB::select("SELECT * FROM departamento WHERE estado = 'A' ORDER BY id_departamento");
        $ciudades = DB::select("SELECT * FROM ciudad_pcc WHERE estado = 'A' ORDER BY id_ciudad_pcc");
        $cargos = DB::select("SELECT id, cargo as descripcion, riesgo FROM listas_cargos WHERE estado = 'A' ORDER BY id");

        $defaults = ['id_pais' => 26, 'id_departamento' => 0];
        if ($idAgencia > 0) {
            $ag = DB::selectOne("SELECT id_departamento FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);
            if ($ag) {
                $defaults['id_departamento'] = (int) ($ag['id_departamento'] ?? 0);
            }
        }

        return [
            'tipos_documento'        => $tiposDoc,
            'expediciones'           => $expediciones,
            'monedas'                => $monedas,
            'sexos'                  => $sexos,
            'estados_civiles'        => $estadosCiviles,
            'ocupaciones'            => $ocupaciones,
            'actividades_economicas' => $actividadesEconomicas,
            'paises'                 => $paises,
            'departamentos'          => $departamentos,
            'ciudades'               => $ciudades,
            'cargos'                 => $cargos,
            'defaults'               => $defaults,
        ];
    }

    public function calcularCambioUif(
        int $idOperacion,
        int $idMoneda,
        float $monto,
        string $documento,
        int $tipoDoc,
        int $expedicion
    ): array {
        $params = $this->getParametrosUif();
        $tcOficial = $this->getTipoCambioOficial($idMoneda, $idOperacion);

        $dolarizado = $tcOficial > 0 ? round($monto / $tcOficial, 2) : 0;

        $uf = $this->buscarCliente($documento, $tipoDoc, $expedicion);
        $nivelImpresion = $uf ? (int) ($uf['nivel_impresion_formulario'] ?? 1) : 0;

        $requiereFormulario = false;
        $requierePcc06 = false;

        $opsPrevFormulario = $this->getOperacionesPrevias($documento, $tipoDoc, $expedicion, (int) $params['dias_formulario']);
        $opsPrevPcc06 = $this->getOperacionesPrevias($documento, $tipoDoc, $expedicion, (int) $params['dias_pcc06']);

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

        if ($dolarizado >= (float) $params['unica_pcc06']) {
            $requierePcc06 = true;
        } else {
            $hayPcc06EnPeriodo = $this->hayPcc06EnPeriodo($documento, $tipoDoc, $expedicion, (int) $params['dias_pcc06']);

            if (!$hayPcc06EnPeriodo) {
                if (
                    ($opsPrevPcc06['cantidad'] + 1) >= (int) $params['num_ops_pcc06']
                    && ($opsPrevPcc06['sumatoria'] + $dolarizado) >= (float) $params['multiples_pcc06']
                ) {
                    $requierePcc06 = true;
                }
            } else {
                $ultimasOps = $this->sumaUltimasOperaciones($documento, $tipoDoc, $expedicion, (int) $params['dias_pcc06']);
                if (($ultimasOps + $dolarizado) >= (float) $params['multiples_pcc06']) {
                    $requierePcc06 = true;
                }
            }
        }

        // ── Alertas de listas de control (PEP, OFAC, etc.) ──────────────
        $alertas = $this->verificarListasControl($documento, $tipoDoc, $expedicion);

        // ── Usuario habitual (Art. 37): 3+ ops en N días → forzar formulario ─
        $paramsHabitual = $this->getParametrosHabitual();
        $opsHabitual = $this->getOperacionesPrevias(
            $documento, $tipoDoc, $expedicion, $paramsHabitual['dias']
        );
        $esUsuarioHabitual = ($opsHabitual['cantidad'] + 1) >= $paramsHabitual['ops'];
        if ($esUsuarioHabitual && !$requiereFormulario) {
            $requiereFormulario = true;
        }

        // ── Señales de alerta Art. 55.13 ──────────────────────────────────
        $senales = $this->detectarSenalesAlerta(
            $documento, $tipoDoc, $expedicion, $dolarizado, $uf
        );

        // ── Origen/destino por umbral configurable ────────────────────────
        $umbralOrigenDestino = $this->getParametroFloat('umbral_origen_destino_usd', 1000);
        $requiereOrigenDestino = $dolarizado >= $umbralOrigenDestino;

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
            ", [':doc' => $documento, ':tipo' => $tipoDoc, ':exp' => $expedicion]);

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
            'requiere_pcc06'       => $requierePcc06,
            'operaciones_previas'  => [
                // Este bloque se muestra junto a "Requiere Formulario
                // Complemento", por lo que debe reflejar la misma ventana
                // y acumulado usados para decidir esa bandera.
                'cantidad'         => $opsPrevFormulario['cantidad'],
                'total_dolarizado' => $opsPrevFormulario['sumatoria'],
                'dias_ventana'     => (int) $params['dias_formulario'],
            ],
            'umbrales' => [
                'formulario_unica'    => (float) $params['unica_formulario'],
                'pcc06_unica'         => (float) $params['unica_pcc06'],
                'formulario_multiples' => (float) $params['multiples_formulario'],
                'pcc06_multiples'     => (float) $params['multiples_pcc06'],
            ],
            'alertas'              => $alertas,
            'usuario_habitual'     => $esUsuarioHabitual,
            'senales_alerta'       => $senales,
            'requiere_origen_destino' => $requiereOrigenDestino,
            'umbral_origen_destino'   => $umbralOrigenDestino,
            'dd_incompleta'           => $ddIncompleta,
            'dd_bloqueado'            => $ddBloqueado,
        ];
    }

    public function obtenerComprobante(int $idCambio): ?array
    {
        return DB::selectOne("
            SELECT c.*,
                uf.nombres, uf.ape_pat, uf.ape_mat,
                m_rec.abreviatura AS moneda_recibida,
                m_ent.abreviatura AS moneda_entregada,
                a.descripcion AS agencia
            FROM cambio c
            JOIN usuario_financiero uf ON uf.id = c.usuario_financiero_id
            JOIN moneda m_rec ON m_rec.id_moneda = c.mon_rec
            JOIN moneda m_ent ON m_ent.id_moneda = c.mon_ent
            JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE c.id_cambio = :id
        ", [':id' => $idCambio]);
    }

    public function registrarCambio(
        int $codUsuario,
        string $loginUsuario,
        int $idAgencia,
        int $idOperacion,
        array $cliente,
        array $operacion,
        string $ip,
        string $uuid,
        array $metodosPago = [],
        array $complemento = []
    ): array {
        $hoy = date('Y-m-d');

        $monEnt = (int) $operacion['mon_ent'];
        $monRec = (int) $operacion['mon_rec'];
        $monto = (float) $operacion['monto'];
        $tipoCambio = (float) $operacion['tipo_cambio'];
        $totalCambio = (float) $operacion['total_cambio'];
        $recibido = (float) $operacion['recibido'];
        $entregado = (float) $operacion['entregado'];
        $cambio = (float) $operacion['cambio'];

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

        // ── Validación de importes ANTES de tocar la base ──────────────────────
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0.'];
        }
        if ($tipoCambio <= 0) {
            return ['success' => false, 'message' => 'El tipo de cambio debe ser mayor a 0.'];
        }

        // El total no se acepta tal cual viene del POS: se recalcula y se exige que
        // coincida. El navegador es terreno hostil y este importe determina cuánto
        // efectivo entra o sale de la caja.
        $totalEsperado = round($monto * $tipoCambio, 2);
        if (abs($totalCambio - $totalEsperado) > 0.01) {
            return [
                'success' => false,
                'message' => 'El total de la operación no coincide con monto × tipo de cambio '
                           . "(enviado: {$totalCambio}, esperado: {$totalEsperado}).",
            ];
        }
        $totalCambio = $totalEsperado;

        // Cuadre del cobro: la suma de los métodos de pago debe igualar el total.
        // Antes solo el frontend coloreaba el descuadre y aquí se repartía el efectivo
        // en silencio, dejando la caja descuadrada con la factura emitida por el total.
        $totalMetodos = 0.0;
        foreach ($metodosPago as $mp) {
            $totalMetodos += (float) ($mp['monto'] ?? 0);
        }
        if (abs($totalMetodos - $totalCambio) > 0.01) {
            return [
                'success' => false,
                'message' => 'Los métodos de pago no cuadran con el total de la operación '
                           . '(métodos: ' . number_format($totalMetodos, 2)
                           . ', total: ' . number_format($totalCambio, 2) . ').',
            ];
        }

        // ── Normalización venta (op=2) al formato PROD ────────────────────────
        // El POS siempre envía: monto = divisa, tc = BOB/divisa, total = BOB.
        // PROD almacena op=2 invertido: monto = BOB, tc = divisa/BOB, total = divisa.
        // Se guardan las variables originales (display) para factura, email y response.
        $tipoCambioBase = $tipoCambio;
        $montoDisplay = $monto;
        $totalCambioDisplay = $totalCambio;
        $recibidoDisplay = $recibido;
        $entregadoDisplay = $entregado;
        $tipoCambioDisplay = $tipoCambio;

        if ($idOperacion === 2) {
            $cambioBobOrig = $cambio;
            [$monto, $totalCambio] = [$totalCambio, $monto];
            [$recibido, $entregado] = [$entregado, $recibido];
            $tipoCambio = $monto > 0 ? round($totalCambio / $monto, 6) : 0;
            $cambio = ($cambioBobOrig > 0 && $tipoCambio > 0)
                ? round($cambioBobOrig * $tipoCambio, 2) : 0;
        }

        if (!$this->isBovedaAbierta($idAgencia)) {
            return ['success' => false, 'message' => 'La bóveda no está abierta. No se pueden registrar operaciones.'];
        }

        if (!$this->tieneSesionAbierta($codUsuario)) {
            return ['success' => false, 'message' => 'No tiene ninguna caja abierta. Abra una caja antes de operar.'];
        }

        // Idempotencia: si el uuid ya existe, se devuelve el comprobante previo.
        //
        // Va DELIBERADAMENTE antes de la transacción: si el uuid ya se insertó, no hay
        // nada que revertir, y así dos reintentos concurrentes del mismo POS no chocan
        // contra `uk_uuid` (que antes devolvía un 500 en vez del replay).
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

        $origen = trim($operacion['origen'] ?? '');
        $destino = trim($operacion['destino'] ?? '');
        $dineroSi = (int) ($operacion['dinero_si'] ?? 1);
        $dineroNo = $dineroSi === 1 ? 0 : 1;
        $selectPer = (int) ($operacion['select_per'] ?? 0);
        $nombresDd = mb_strtoupper(trim($operacion['nombres_dd'] ?? ''));
        $docNitDd = mb_strtoupper(trim($operacion['doc_nit_dd'] ?? ''));
        $actEcoDd = (int) ($operacion['act_eco_dd'] ?? 0);
        $domicilioDd = mb_strtoupper(trim($operacion['domicilio_dd'] ?? ''));
        $idDepartamentoDd = (int) ($operacion['id_departamento_dd'] ?? 0);
        $idCiudadPcc = (int) ($operacion['id_ciudad_pcc'] ?? 0);
        $telefonoDd = trim($operacion['telefono_dd'] ?? '');
        $zonaDd = mb_strtoupper(trim($operacion['zona_dd'] ?? ''));
        $impresionPcc06 = ($operacion['impresion_pcc06'] ?? 'N') === 'S' ? 'S' : 'N';
        $observacionesUif = trim($operacion['observaciones_uif'] ?? '');

        if ($impresionPcc06 === 'S') {
            if (mb_strlen($origen) < 20) {
                return ['success' => false, 'message' => 'El origen de fondos debe tener al menos 20 caracteres.'];
            }
            if (mb_strlen($destino) < 20) {
                return ['success' => false, 'message' => 'El destino de fondos debe tener al menos 20 caracteres.'];
            }
            if ($dineroSi === 0) {
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
        }

        $cajaEntrega = DB::selectOne("
            SELECT c.id_caja, c.num_caja
            FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $monEnt]);

        if (!$cajaEntrega) {
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monEnt]);
            return ['success' => false, 'message' => "No tiene caja asignada para la moneda {$monAbr}."];
        }

        $saldoCajaEnt = $this->balance->saldoCaja((int) $cajaEntrega['id_caja'], $hoy);

        if ($saldoCajaEnt < $entregado) {
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monEnt]);
            return [
                'success' => false,
                'message' => "La caja en {$monAbr} no dispone de la cantidad requerida ({$entregado}). Saldo disponible: {$saldoCajaEnt}.",
            ];
        }

        $cajaRecibe = DB::selectOne("
            SELECT c.id_caja, c.num_caja
            FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $monRec]);

        if (!$cajaRecibe) {
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monRec]);
            return ['success' => false, 'message' => "No tiene caja asignada para la moneda {$monAbr}."];
        }

        $monRecAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monRec]);
        $monEntAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monEnt]);
        $agenciaDesc = DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);
        $codAgencia = DB::scalar("SELECT cod_agencia FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);

        $fecRegOpe = date('Y-m-d H:i:s');

        return DB::transaction(function () use (
            $codUsuario, $loginUsuario, $idAgencia, $idOperacion, $cliente, $operacion,
            $monEnt, $monRec, $monto, $tipoCambio, $totalCambio,
            $recibido, $entregado, $cambio, $cajaEntrega, $cajaRecibe,
            $fecRegOpe, $hoy, $ip, $uuid,
            $origen, $destino, $dineroSi, $dineroNo, $selectPer,
            $nombresDd, $docNitDd, $actEcoDd, $domicilioDd,
            $idDepartamentoDd, $idCiudadPcc, $telefonoDd, $zonaDd, $impresionPcc06, $observacionesUif,
            $monRecAbr, $monEntAbr, $agenciaDesc, $codAgencia,
            $metodosPago, $complemento,
            $tipoCambioBase, $montoDisplay, $totalCambioDisplay,
            $recibidoDisplay, $entregadoDisplay, $tipoCambioDisplay
        ) {
            $idUsuarioFinanciero = $this->upsertUsuarioFinanciero($cliente, $fecRegOpe);

            if ($idOperacion === 1) {
                // Compra: cliente trae divisa, recibe BOB → total ya está en BOB
                $tipoCambioBob = 1.0;
                $totalOperacion = $totalCambio;
                $totalOperacionBob = $totalCambio;
                $cambioBob = $cambio;
            } else {
                // Venta: cliente trae BOB, recibe divisa → monto es el lado BOB
                $totalOperacion = round($totalCambio + $cambio, 2);
                $totalOperacionBob = $recibido;
                $tipoCambioBob = $totalOperacion > 0
                    ? round($totalOperacionBob / $totalOperacion, 6)
                    : 0;
                $cambioBob = round($recibido - $monto, 2);
            }

            // 1. INSERT cambio
            DB::execute("
                INSERT INTO cambio (
                    id_operacion, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                    id_agencia, mon_rec, mon_ent, tipo_cambio,
                    monto, total_cambio, recibido, entregado,
                    cambio, total_operacion, tipo_cambio_bob, total_operacion_bob,
                    cambio_bob, fec_crea_cambio, facturado, cod_usuario,
                    origen, destino, dinero_si, dinero_no,
                    select_per, nombres_dd, doc_nit_dd, act_eco_dd,
                    domicilio_dd, id_departamento_dd, id_ciudad_pcc, telefono_dd,
                    zona_dd, impresion_pcc06, observaciones_uif, usuario_financiero_id, persona_factura_id
                ) VALUES (
                    :op, :tipo_doc, :doc, :exp,
                    :ag, :mon_rec, :mon_ent, :tc,
                    :monto, :total, :recibido, :entregado,
                    :cambio, :total_op, :tc_bob, :total_op_bob,
                    :cambio_bob, :fecha, 0, :usr,
                    :origen, :destino, :dinero_si, :dinero_no,
                    :select_per, :nombres_dd, :doc_nit_dd, :act_eco_dd,
                    :domicilio_dd, :id_dpto_dd, :id_ciudad_pcc, :telefono_dd,
                    :zona_dd, :imp_pcc06, :obs_uif, :uf_id, 0
                )
            ", [
                ':op'           => $idOperacion,
                ':tipo_doc'     => (int) $cliente['id_tipo_doc_identidad'],
                ':doc'          => mb_strtoupper(trim($cliente['num_doc_identidad'])),
                ':exp'          => (int) $cliente['id_expedicion'],
                ':ag'           => $idAgencia,
                ':mon_rec'      => $monRec,
                ':mon_ent'      => $monEnt,
                ':tc'           => $tipoCambio,
                ':monto'        => $monto,
                ':total'        => $totalCambio,
                ':recibido'     => $recibido,
                ':entregado'    => $entregado,
                ':cambio'       => $cambio,
                ':total_op'     => $totalOperacion,
                ':tc_bob'       => $tipoCambioBob,
                ':total_op_bob' => $totalOperacionBob,
                ':cambio_bob'   => $cambioBob,
                ':fecha'        => $fecRegOpe,
                ':usr'          => $codUsuario,
                ':origen'       => $origen,
                ':destino'      => $destino,
                ':dinero_si'    => $dineroSi,
                ':dinero_no'    => $dineroNo,
                ':select_per'   => $selectPer,
                ':nombres_dd'   => $nombresDd,
                ':doc_nit_dd'   => $docNitDd,
                ':act_eco_dd'   => $actEcoDd,
                ':domicilio_dd' => $domicilioDd,
                ':id_dpto_dd'   => $idDepartamentoDd,
                ':id_ciudad_pcc' => $idCiudadPcc,
                ':telefono_dd'  => $telefonoDd,
                ':zona_dd'      => $zonaDd,
                ':imp_pcc06'    => $impresionPcc06,
                ':obs_uif'      => $observacionesUif ?: null,
                ':uf_id'        => $idUsuarioFinanciero,
            ]);

            $idCambio = DB::lastInsertId();

            // 1b. Patas intermedias CLP si USD está involucrado (triple operación)
            if (TripleOperacionService::isRequired($monRec, $monEnt)) {
                $tripleService = new TripleOperacionService();
                $tripleResult = $tripleService->registrarPatasClp(
                    $idOperacion,
                    $idAgencia,
                    $totalOperacionBob,
                    $monto,
                    $fecRegOpe,
                    $codUsuario,
                    $idUsuarioFinanciero,
                    [
                        'id_tipo_doc_identidad' => (int) $cliente['id_tipo_doc_identidad'],
                        'num_doc_identidad'     => mb_strtoupper(trim($cliente['num_doc_identidad'])),
                        'id_expedicion'         => (int) $cliente['id_expedicion'],
                    ],
                    [
                        'origen'           => $origen,
                        'destino'          => $destino,
                        'dinero_si'        => $dineroSi,
                        'dinero_no'        => $dineroNo,
                        'select_per'       => $selectPer,
                        'nombres_dd'       => $nombresDd,
                        'doc_nit_dd'       => $docNitDd,
                        'act_eco_dd'       => $actEcoDd,
                        'domicilio_dd'     => $domicilioDd,
                        'id_departamento_dd' => $idDepartamentoDd,
                        'id_ciudad_pcc'    => $idCiudadPcc,
                        'telefono_dd'      => $telefonoDd,
                        'zona_dd'          => $zonaDd,
                    ]
                );
                if (!$tripleResult['success']) {
                    throw new \RuntimeException($tripleResult['error']);
                }
            }

            // 1c. INSERT usuario_financiero_complemento (si hay datos)
            if (!empty($complemento)) {
                $this->registrarComplemento($idUsuarioFinanciero, $complemento, $fecRegOpe);
            }

            // 2. INSERT saldo_caja — split por método de pago (efectivo vs QR/transferencia)
            $montoEfectivoBob = 0.0;
            $montoBancoBob = 0.0;
            $detallesBanco = [];

            if (!empty($metodosPago)) {
                foreach ($metodosPago as $mp) {
                    $forma = (int) ($mp['id_forma_pago'] ?? 1);
                    $mpMonto = (float) ($mp['monto'] ?? 0);
                    if ($forma === 1) {
                        $montoEfectivoBob += $mpMonto;
                    } else {
                        $montoBancoBob += $mpMonto;
                        $detallesBanco[] = $mp;
                    }
                }
            }
            if ($montoEfectivoBob <= 0 && $montoBancoBob <= 0) {
                $montoEfectivoBob = $totalCambioDisplay;
            }

            $totalPago = $montoEfectivoBob + $montoBancoBob;
            $ratioCash = $totalPago > 0 ? $montoEfectivoBob / $totalPago : 1.0;

            $esCajaBob = ($idOperacion === 1) ? $cajaEntrega : $cajaRecibe;
            $esCajaDiv = ($idOperacion === 1) ? $cajaRecibe : $cajaEntrega;

            if ($idOperacion === 1) {
                // COMPRA: EC (recibido divisa) siempre va completo a caja divisa
                DB::execute("
                    INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                    VALUES (:id, :saldo, :fecha, 'EC')
                ", [':id' => $cajaRecibe['id_caja'], ':saldo' => $recibido, ':fecha' => $fecRegOpe]);

                // SC (entregado BOB) — solo la porción efectivo va a saldo_caja
                if ($montoEfectivoBob > 0) {
                    DB::execute("
                        INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                        VALUES (:id, :saldo, :fecha, 'SC')
                    ", [':id' => $cajaEntrega['id_caja'], ':saldo' => -$montoEfectivoBob, ':fecha' => $fecRegOpe]);
                }
            } else {
                // VENTA: EC (recibido BOB) — solo la porción efectivo va a saldo_caja
                if ($montoEfectivoBob > 0) {
                    $ecEfectivo = round($recibido * $ratioCash, 2);
                    DB::execute("
                        INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                        VALUES (:id, :saldo, :fecha, 'EC')
                    ", [':id' => $cajaRecibe['id_caja'], ':saldo' => $ecEfectivo, ':fecha' => $fecRegOpe]);
                }

                // SC (entregado divisa) siempre va completo desde caja divisa
                DB::execute("
                    INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                    VALUES (:id, :saldo, :fecha, 'SC')
                ", [':id' => $cajaEntrega['id_caja'], ':saldo' => -$entregado, ':fecha' => $fecRegOpe]);
            }

            // Porción bancaria → movimiento_cuenta_bancaria
            if ($montoBancoBob > 0 && !empty($detallesBanco)) {
                foreach ($detallesBanco as $db) {
                    $numCuenta = trim($db['num_cuenta_bancaria'] ?? '');
                    $idEntidad = (int) ($db['id_entidad_financiera'] ?? 0);
                    $idMonedaCuenta = (int) ($db['id_moneda_cuenta'] ?? 2);
                    $mpMonto = (float) ($db['monto'] ?? 0);
                    $mpRef = trim($db['referencia'] ?? '');
                    $formaPago = (int) ($db['id_forma_pago'] ?? 2);
                    $concBanco = $formaPago === 3 ? 'QC' : 'TF';
                    $tipoOpDesc = $idOperacion === 1 ? 'COMPRA' : 'VENTA';
                    $glosa = "COBRO POR " . ($formaPago === 3 ? 'QR' : 'TRANSFERENCIA') . " - CAMBIO {$tipoOpDesc}" . ($mpRef ? " REF:{$mpRef}" : '');

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

            // 3. INSERT pos_operacion_log (auditoría + idempotencia)
            $monDivisa = $idOperacion === 1 ? $monRec : $monEnt;
            $tcVigente = (float) DB::scalar("
                SELECT c.valor FROM cotizacion c
                WHERE c.id_moneda = :mon AND c.id_operacion = :op
                  AND c.id_agencia = :ag AND c.estado = 'A'
                LIMIT 1
            ", [':mon' => $monDivisa, ':op' => $idOperacion, ':ag' => $idAgencia]);

            $metadata = json_encode([
                'tc_cotizado'  => $tipoCambio,
                'tc_vigente'   => $tcVigente,
                'tc_diferencia' => $tcVigente > 0 ? round($tipoCambio - $tcVigente, 6) : null,
            ]);

            DB::execute("
                INSERT INTO pos_operacion_log (
                    uuid, tipo_operacion, id_referencia, tabla_referencia,
                    id_caja, cod_usuario, id_agencia, id_moneda,
                    monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                ) VALUES (
                    :uuid, 'cambio', :id_ref, 'cambio',
                    :id_caja, :usr, :ag, :mon,
                    :monto, :monto_bob, 'completado', :ip, :meta, :fecha
                )
            ", [
                ':uuid'      => $uuid,
                ':id_ref'    => $idCambio,
                ':id_caja'   => $cajaEntrega['id_caja'],
                ':usr'       => $codUsuario,
                ':ag'        => $idAgencia,
                ':mon'       => $monDivisa,
                ':monto'     => $totalOperacion,
                ':monto_bob' => $totalOperacionBob,
                ':ip'        => $ip,
                ':meta'      => $metadata,
                ':fecha'     => $fecRegOpe,
            ]);

            $idOperacionLog = DB::lastInsertId();

            // 4. Métodos de pago (múltiples) — con referencia a cuenta bancaria
            if (empty($metodosPago)) {
                $metodosPago = [['id_forma_pago' => 1, 'monto' => 0, 'referencia' => '']];
            }
            foreach ($metodosPago as $mp) {
                $mpForma = (int) ($mp['id_forma_pago'] ?? 1);
                $mpMonto = (float) ($mp['monto'] ?? 0);
                $mpRef = trim($mp['referencia'] ?? '');
                $mpNumCuenta = trim($mp['num_cuenta_bancaria'] ?? '');
                $mpIdEntidad = (int) ($mp['id_entidad_financiera'] ?? 0);
                $mpIdAgMp = (int) ($mp['id_agencia'] ?? 0);
                $mpIdMonCta = (int) ($mp['id_moneda_cuenta'] ?? 0);
                DB::execute("
                    INSERT INTO pos_metodo_pago_operacion (
                        tipo_operacion, id_referencia, id_forma_pago,
                        monto, moneda, referencia_bancaria,
                        num_cuenta_bancaria, id_entidad_financiera, id_agencia, id_moneda_cuenta,
                        created_at
                    ) VALUES (
                        'cambio', :id_ref, :forma,
                        :monto, 'BOB', :ref_bancaria,
                        :num_cuenta, :entidad, :agencia_mp, :moneda_cta,
                        :fecha
                    )
                ", [
                    ':id_ref'       => $idCambio,
                    ':forma'        => $mpForma,
                    ':monto'        => $mpMonto,
                    ':ref_bancaria' => $mpRef ?: null,
                    ':num_cuenta'   => $mpNumCuenta ?: null,
                    ':entidad'      => $mpIdEntidad ?: null,
                    ':agencia_mp'   => $mpIdAgMp ?: null,
                    ':moneda_cta'   => $mpIdMonCta ?: null,
                    ':fecha'        => $fecRegOpe,
                ]);
            }

            // 5. Recibo para re-impresión
            DB::execute("
                INSERT INTO pos_recibo (
                    tipo_operacion, id_referencia, id_operacion_log, created_at
                ) VALUES ('cambio', :id_ref, :id_log, :fecha)
            ", [
                ':id_ref' => $idCambio,
                ':id_log' => $idOperacionLog,
                ':fecha'  => $fecRegOpe,
            ]);

            // 6. Facturación electrónica (debe ejecutarse antes del asiento para obtener número de factura)
            $clienteNombre = mb_strtoupper(trim(
                ($cliente['nombres'] ?? '') . ' ' .
                ($cliente['ape_pat'] ?? '') . ' ' .
                ($cliente['ape_mat'] ?? '')
            ));

            $clienteFacturacion = [
                'nombre'       => $dineroSi === 1
                    ? $clienteNombre
                    : ($selectPer === 1 ? $nombresDd : $clienteNombre),
                'documento'    => $dineroSi === 1
                    ? mb_strtoupper(trim($cliente['num_doc_identidad'] ?? ''))
                    : ($selectPer === 1 ? $docNitDd : mb_strtoupper(trim($cliente['num_doc_identidad'] ?? ''))),
                'tipo_doc_cgr' => $dineroSi === 1
                    ? (int) ($cliente['id_tipo_doc_identidad'] ?? 1)
                    : ($selectPer === 1 ? 4 : (int) ($cliente['id_tipo_doc_identidad'] ?? 1)),
            ];

            $facturacionService = new FacturacionService();
            $cambioData = [
                'mon_rec'            => $monRec,
                'mon_ent'            => $monEnt,
                'tipo_cambio'        => $tipoCambioBase,
                'recibido'           => $recibido,
                'entregado'          => $entregado,
                'id_expedicion'      => (int) ($cliente['id_expedicion'] ?? 0),
                'num_doc_identidad'  => mb_strtoupper(trim($cliente['num_doc_identidad'] ?? '')),
            ];

            $facturaData = $facturacionService->facturarCambio(
                (int) $idCambio,
                $idOperacion,
                $idAgencia,
                $loginUsuario,
                $cambioData,
                $clienteFacturacion,
                $fecRegOpe
            );

            // 7. Asiento contable (o encolar en pos_asiento_pendiente si falla)
            // Resolver cuentas bancarias para partidas contables
            $cuentasBancoPago = [];
            if ($montoBancoBob > 0 && !empty($detallesBanco)) {
                foreach ($detallesBanco as $db) {
                    $numCta = trim($db['num_cuenta_bancaria'] ?? '');
                    $idEnt = (int) ($db['id_entidad_financiera'] ?? 0);
                    $idMonCta = (int) ($db['id_moneda_cuenta'] ?? 2);
                    if ($numCta && $idEnt) {
                        $codSub = DB::scalar(
                            "SELECT codsubcuenta FROM cuenta_bancaria WHERE num_cuenta_bancaria = :num AND id_entidad_financiera = :ent AND id_moneda = :mon AND estado = 'A' LIMIT 1",
                            [':num' => $numCta, ':ent' => $idEnt, ':mon' => $idMonCta]
                        );
                        if ($codSub) {
                            $cuentasBancoPago[] = [
                                'codsubcuenta' => $codSub,
                                'monto_bob'    => (float) ($db['monto'] ?? 0),
                            ];
                        }
                    }
                }
            }

            $this->registrarAsientoContable(
                (int) $idCambio, $idOperacion, $idAgencia, $codAgencia, $agenciaDesc,
                $monRec, $monEnt, $monRecAbr, $monEntAbr,
                $monto, $recibido, $entregado,
                $totalOperacionBob, $fecRegOpe, $idOperacionLog, $codUsuario,
                $clienteNombre, (int) ($facturaData['numero_factura'] ?? 0),
                $montoEfectivoBob, $cuentasBancoPago
            );

            // 8. Email notification
            $emailCliente = trim($cliente['email'] ?? '');
            if ($emailCliente) {
                $tipoOp = $idOperacion === 1 ? 'COMPRA' : 'VENTA';
                $monedaDivisa = $idOperacion === 1 ? $monRecAbr : $monEntAbr;
                $this->encolarNotificacionesCambio(
                    (int) $idCambio, $tipoOp, $clienteNombre,
                    $montoDisplay, $monedaDivisa, $tipoCambioDisplay,
                    $totalCambioDisplay, $recibidoDisplay, $entregadoDisplay,
                    $agenciaDesc, $emailCliente, $facturaData
                );
            }

            return [
                'success' => true,
                'message' => 'Operación registrada correctamente.',
                'data'    => [
                    'id_cambio'        => (int) $idCambio,
                    'id_operacion'     => $idOperacion,
                    'tipo_operacion'   => $idOperacion === 1 ? 'COMPRA' : 'VENTA',
                    'mon_rec'          => $monRec,
                    'mon_ent'          => $monEnt,
                    'moneda_recibida'  => $monRecAbr,
                    'moneda_entregada' => $monEntAbr,
                    'monto'            => $montoDisplay,
                    'tipo_cambio'      => $tipoCambioDisplay,
                    'total_cambio'     => $totalCambioDisplay,
                    'recibido'         => $recibidoDisplay,
                    'entregado'        => $entregadoDisplay,
                    'cambio'           => $cambio,
                    'fecha'            => $fecRegOpe,
                    'agencia'          => $agenciaDesc,
                    'cliente_nombre'   => $clienteNombre,
                    'cliente_documento' => mb_strtoupper(trim($cliente['num_doc_identidad'] ?? '')),
                    'impresion_pcc06'  => $impresionPcc06,
                    'factura'          => $facturaData,
                ],
            ];
        });
    }

    private function registrarAsientoContable(
        int $idCambio,
        int $idOperacion,
        int $idAgencia,
        string $codAgencia,
        string $agenciaDesc,
        int $monRec,
        int $monEnt,
        string $monRecAbr,
        string $monEntAbr,
        float $monto,
        float $recibido,
        float $entregado,
        float $totalOperacionBob,
        string $fecha,
        int $idOperacionLog,
        int $codUsuario,
        string $clienteNombre,
        int $numeroFactura,
        float $montoEfectivoBob = 0.0,
        array $cuentasBancoPago = []
    ): void {
        $codejercicio = DB::scalar(
            "SELECT codejercicio FROM ejercicios WHERE estado = 'ABIERTO' LIMIT 1",
            [], 'conta'
        );
        if (!$codejercicio) {
            $this->encolarAsientoPendiente(
                $idOperacionLog, $idOperacion, $fecha, $codUsuario,
                $monRec, $monEnt, $monRecAbr, $monEntAbr,
                $monto, $recibido, $entregado, $totalOperacionBob,
                $codAgencia, $agenciaDesc, 'No hay ejercicio contable abierto',
                $montoEfectivoBob, $cuentasBancoPago
            );
            return;
        }

        $monDivisa = $idOperacion === 1 ? $monRec : $monEnt;
        $divisa = $idOperacion === 1 ? $monRecAbr : $monEntAbr;
        $montoGlosa = $idOperacion === 1 ? round($recibido, 2) : round($entregado, 2);

        $oficial = (float) DB::scalar("
            SELECT co.valor FROM cotizacion_oficial co
            WHERE co.id_moneda = :mon AND co.id_operacion = 1
            ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1
        ", [':mon' => $monDivisa]) ?: 1.0;

        $cuentaME = DB::scalar(
            "SELECT cuenta_contable FROM moneda WHERE id_moneda = :mon",
            [':mon' => $monDivisa]
        ) ?: '11101201';

        $tipoOperacionDesc = $idOperacion === 1 ? 'COMPRA' : 'VENTA';
        $tipodocumento = $idOperacion === 1 ? 'SC-CME' : 'EC-VME';
        $glosa = mb_strtoupper("{$clienteNombre} POR {$tipoOperacionDesc} DE {$divisa} {$montoGlosa} CON FACTURA #{$numeroFactura} EN BASE A COTIZACION OFICIAL SEGUN BCB, EN {$agenciaDesc}");

        $sigue = (int) DB::scalar(
            "SELECT COALESCE(MAX(numero), 0) FROM co_asientos WHERE codejercicio = :ej",
            [':ej' => $codejercicio], 'conta'
        ) + 1;

        try {
            DB::execute("
                INSERT INTO co_asientos (
                    codejercicio, codplanasiento, concepto, documento, editable,
                    fecha, idasiento, idconcepto, importe, numero, tipodocumento,
                    cod_usuario, fecha_trans, origen
                ) VALUES (
                    :ej, NULL, :concepto, '', 0,
                    :fecha, '', :idconcepto, :importe, :numero, :tipodoc,
                    :usr, :fecha_t, 'POS'
                )
            ", [
                ':ej'         => $codejercicio,
                ':concepto'   => $glosa,
                ':fecha'      => substr($fecha, 0, 10),
                ':idconcepto' => $codAgencia,
                ':importe'    => $totalOperacionBob,
                ':numero'     => $sigue,
                ':tipodoc'    => $tipodocumento,
                ':usr'        => 'CGR',
                ':fecha_t'    => $fecha,
            ], 'conta');

            $idasiento = DB::lastInsertId('conta');

            if ($idOperacion === 1) {
                $this->partidasCompra(
                    $idasiento, $codAgencia, $codejercicio,
                    $monto, $oficial, $totalOperacionBob,
                    $cuentaME, $idCambio, $numeroFactura,
                    $montoEfectivoBob, $cuentasBancoPago
                );
            } else {
                $this->partidasVenta(
                    $idasiento, $codAgencia, $codejercicio,
                    $totalOperacionBob, $oficial, $entregado,
                    $cuentaME, $numeroFactura,
                    $montoEfectivoBob, $cuentasBancoPago
                );
            }

            DB::execute("
                UPDATE co_asientos SET importe = (
                    SELECT COALESCE(SUM(debe), 0) FROM co_partidas WHERE idasiento = :id
                ) WHERE idasiento = :id2
            ", [':id' => $idasiento, ':id2' => $idasiento], 'conta');

        } catch (\Throwable $e) {
            $this->encolarAsientoPendiente(
                $idOperacionLog, $idOperacion, $fecha, $codUsuario,
                $monRec, $monEnt, $monRecAbr, $monEntAbr,
                $monto, $recibido, $entregado, $totalOperacionBob,
                $codAgencia, $agenciaDesc, $e->getMessage(),
                $montoEfectivoBob, $cuentasBancoPago
            );
        }
    }

    private function partidasCompra(
        int $idasiento, string $codAgencia, string $codejercicio,
        float $monto, float $oficial, float $totalOperacionBob,
        string $cuentaME, int $idCambio, int $numeroFactura,
        float $montoEfectivoBob = 0.0, array $cuentasBancoPago = []
    ): void {
        // COMPRA: BOB sale (HABER). Split entre caja efectivo y cuentas bancarias.
        $hasMixto = $montoEfectivoBob > 0 && !empty($cuentasBancoPago);

        if ($hasMixto) {
            // Partida caja efectivo (11101101)
            if ($montoEfectivoBob > 0) {
                $idSubBob = $this->getIdSubcuenta('11101101', $codejercicio);
                $efME = round($montoEfectivoBob / $oficial, 2);
                $efRx = $montoEfectivoBob / $efME;
                DB::execute("
                    INSERT INTO co_partidas (
                        baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                        codsubcuenta, concepto, debe, debeme, documento, factura,
                        haber, haberme, idasiento, idconcepto, idcontrapartida,
                        idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                    ) VALUES (
                        0, '', NULL, 'BOB', :codserie,
                        '11101101', NULL, 0, 0, '', :factura,
                        :haber, :haberme, :asiento, :idconcepto, NULL,
                        '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                    )
                ", [
                    ':codserie'   => $idCambio,
                    ':factura'    => $numeroFactura,
                    ':haber'      => $montoEfectivoBob,
                    ':haberme'    => $efME,
                    ':asiento'    => $idasiento,
                    ':idconcepto' => $codAgencia,
                    ':idsub'      => $idSubBob,
                    ':recargo'    => $oficial,
                    ':tasaconv'   => $efRx,
                ], 'conta');
            }

            // Partidas por cada cuenta bancaria
            foreach ($cuentasBancoPago as $cbp) {
                $codSubBanco = $cbp['codsubcuenta'];
                $montoBob = $cbp['monto_bob'];
                $idSubBanco = $this->getIdSubcuenta($codSubBanco, $codejercicio);
                $bancoME = round($montoBob / $oficial, 2);
                $bancoRx = $montoBob / $bancoME;
                DB::execute("
                    INSERT INTO co_partidas (
                        baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                        codsubcuenta, concepto, debe, debeme, documento, factura,
                        haber, haberme, idasiento, idconcepto, idcontrapartida,
                        idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                    ) VALUES (
                        0, '', NULL, 'BOB', :codserie,
                        :codsubcuenta, NULL, 0, 0, '', :factura,
                        :haber, :haberme, :asiento, :idconcepto, NULL,
                        '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                    )
                ", [
                    ':codserie'     => $idCambio,
                    ':codsubcuenta' => $codSubBanco,
                    ':factura'      => $numeroFactura,
                    ':haber'        => $montoBob,
                    ':haberme'      => $bancoME,
                    ':asiento'      => $idasiento,
                    ':idconcepto'   => $codAgencia,
                    ':idsub'        => $idSubBanco,
                    ':recargo'      => $oficial,
                    ':tasaconv'     => $bancoRx,
                ], 'conta');
            }
        } else {
            // Sin mixto: todo a caja (comportamiento original)
            $idSubBob = $this->getIdSubcuenta('11101101', $codejercicio);
            $totalBobME = round($totalOperacionBob / $oficial, 2);
            $totalBobRx = $totalOperacionBob / $totalBobME;
            DB::execute("
                INSERT INTO co_partidas (
                    baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                    codsubcuenta, concepto, debe, debeme, documento, factura,
                    haber, haberme, idasiento, idconcepto, idcontrapartida,
                    idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                ) VALUES (
                    0, '', NULL, 'BOB', :codserie,
                    '11101101', NULL, 0, 0, '', :factura,
                    :haber, :haberme, :asiento, :idconcepto, NULL,
                    '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                )
            ", [
                ':codserie'   => $idCambio,
                ':factura'    => $numeroFactura,
                ':haber'      => $totalOperacionBob,
                ':haberme'    => $totalBobME,
                ':asiento'    => $idasiento,
                ':idconcepto' => $codAgencia,
                ':idsub'      => $idSubBob,
                ':recargo'    => $oficial,
                ':tasaconv'   => $totalBobRx,
            ], 'conta');
        }

        $idSubDiv = $this->getIdSubcuenta($cuentaME, $codejercicio);
        $debe = round($monto * $oficial, 2);
        $debeme = $monto;
        $rex = $debe / $debeme;

        DB::execute("
            INSERT INTO co_partidas (
                baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                codsubcuenta, concepto, debe, debeme, documento, factura,
                haber, haberme, idasiento, idconcepto, idcontrapartida,
                idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
            ) VALUES (
                0, '', NULL, 'BOB', :codserie,
                :codsubcuenta, NULL, :debe, :debeme, '', :factura,
                0, 0, :asiento, :idconcepto, NULL,
                '', :idsub, 0, 0, :recargo, :tasaconv, NULL
            )
        ", [
            ':codserie'     => $idCambio,
            ':codsubcuenta' => $cuentaME,
            ':debe'         => $debe,
            ':debeme'       => $debeme,
            ':factura'      => $numeroFactura,
            ':asiento'      => $idasiento,
            ':idconcepto'   => $codAgencia,
            ':idsub'        => $idSubDiv,
            ':recargo'      => $oficial,
            ':tasaconv'     => $rex,
        ], 'conta');

        $diferencia = round($totalOperacionBob - $debe, 2);
        if ($diferencia > 0) {
            $perdidame = round($diferencia / $oficial, 2);
            $rxp = $diferencia / $perdidame;
            $idSubPerdida = $this->getIdSubcuenta('44502201', $codejercicio);
            DB::execute("
                INSERT INTO co_partidas (
                    baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                    codsubcuenta, concepto, debe, debeme, documento, factura,
                    haber, haberme, idasiento, idconcepto, idcontrapartida,
                    idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                ) VALUES (
                    0, '', NULL, 'BOB', :codserie,
                    '44502201', NULL, :debe, :debeme, '', :factura,
                    0, 0, :asiento, :idconcepto, NULL,
                    '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                )
            ", [
                ':codserie'   => $idCambio,
                ':debe'       => $diferencia,
                ':debeme'     => $perdidame,
                ':factura'    => $numeroFactura,
                ':asiento'    => $idasiento,
                ':idconcepto' => $codAgencia,
                ':idsub'      => $idSubPerdida,
                ':recargo'    => $oficial,
                ':tasaconv'   => $rxp,
            ], 'conta');
        } elseif ($diferencia < 0) {
            $ganancia = abs($diferencia);
            $gananciame = round($ganancia / $oficial, 2);
            $rxg = $ganancia / $gananciame;
            $idSubGanancia = $this->getIdSubcuenta('54201201', $codejercicio);
            DB::execute("
                INSERT INTO co_partidas (
                    baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                    codsubcuenta, concepto, debe, debeme, documento, factura,
                    haber, haberme, idasiento, idconcepto, idcontrapartida,
                    idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                ) VALUES (
                    0, '', NULL, 'BOB', NULL,
                    '54201201', NULL, 0, 0, '', NULL,
                    :haber, :haberme, :asiento, :idconcepto, NULL,
                    '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                )
            ", [
                ':haber'      => $ganancia,
                ':haberme'    => $gananciame,
                ':asiento'    => $idasiento,
                ':idconcepto' => $codAgencia,
                ':idsub'      => $idSubGanancia,
                ':recargo'    => $oficial,
                ':tasaconv'   => $rxg,
            ], 'conta');
        }
    }

    private function partidasVenta(
        int $idasiento, string $codAgencia, string $codejercicio,
        float $totalOperacionBob, float $oficial, float $entregado,
        string $cuentaME, int $numeroFactura,
        float $montoEfectivoBob = 0.0, array $cuentasBancoPago = []
    ): void {
        // VENTA: BOB entra (DEBE). Split entre caja efectivo y cuentas bancarias.
        $hasMixto = $montoEfectivoBob > 0 && !empty($cuentasBancoPago);

        if ($hasMixto) {
            // Partida caja efectivo (11101101)
            if ($montoEfectivoBob > 0) {
                $idSubBob = $this->getIdSubcuenta('11101101', $codejercicio);
                $efME = round($montoEfectivoBob / $oficial, 2);
                $efRx = $montoEfectivoBob / $efME;
                DB::execute("
                    INSERT INTO co_partidas (
                        baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                        codsubcuenta, concepto, debe, debeme, documento, factura,
                        haber, haberme, idasiento, idconcepto, idcontrapartida,
                        idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                    ) VALUES (
                        0, '', NULL, 'BOB', NULL,
                        '11101101', NULL, :debe, :debeme, '', :factura,
                        0, 0, :asiento, :idconcepto, NULL,
                        '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                    )
                ", [
                    ':debe'       => $montoEfectivoBob,
                    ':debeme'     => $efME,
                    ':factura'    => $numeroFactura,
                    ':asiento'    => $idasiento,
                    ':idconcepto' => $codAgencia,
                    ':idsub'      => $idSubBob,
                    ':recargo'    => $oficial,
                    ':tasaconv'   => $efRx,
                ], 'conta');
            }

            // Partidas por cada cuenta bancaria
            foreach ($cuentasBancoPago as $cbp) {
                $codSubBanco = $cbp['codsubcuenta'];
                $montoBob = $cbp['monto_bob'];
                $idSubBanco = $this->getIdSubcuenta($codSubBanco, $codejercicio);
                $bancoME = round($montoBob / $oficial, 2);
                $bancoRx = $montoBob / $bancoME;
                DB::execute("
                    INSERT INTO co_partidas (
                        baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                        codsubcuenta, concepto, debe, debeme, documento, factura,
                        haber, haberme, idasiento, idconcepto, idcontrapartida,
                        idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                    ) VALUES (
                        0, '', NULL, 'BOB', NULL,
                        :codsubcuenta, NULL, :debe, :debeme, '', :factura,
                        0, 0, :asiento, :idconcepto, NULL,
                        '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                    )
                ", [
                    ':codsubcuenta' => $codSubBanco,
                    ':debe'         => $montoBob,
                    ':debeme'       => $bancoME,
                    ':factura'      => $numeroFactura,
                    ':asiento'      => $idasiento,
                    ':idconcepto'   => $codAgencia,
                    ':idsub'        => $idSubBanco,
                    ':recargo'      => $oficial,
                    ':tasaconv'     => $bancoRx,
                ], 'conta');
            }
        } else {
            // Sin mixto: todo a caja (comportamiento original)
            $idSubBob = $this->getIdSubcuenta('11101101', $codejercicio);
            $totalBobME = round($totalOperacionBob / $oficial, 2);
            $totalBobRx = $totalOperacionBob / $totalBobME;
            DB::execute("
                INSERT INTO co_partidas (
                    baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                    codsubcuenta, concepto, debe, debeme, documento, factura,
                    haber, haberme, idasiento, idconcepto, idcontrapartida,
                    idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                ) VALUES (
                    0, '', NULL, 'BOB', NULL,
                    '11101101', NULL, :debe, :debeme, '', :factura,
                    0, 0, :asiento, :idconcepto, NULL,
                    '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                )
            ", [
                ':debe'       => $totalOperacionBob,
                ':debeme'     => $totalBobME,
                ':factura'    => $numeroFactura,
                ':asiento'    => $idasiento,
                ':idconcepto' => $codAgencia,
                ':idsub'      => $idSubBob,
                ':recargo'    => $oficial,
                ':tasaconv'   => $totalBobRx,
            ], 'conta');
        }

        $idSubDiv = $this->getIdSubcuenta($cuentaME, $codejercicio);
        $haber = round($entregado * $oficial, 2);
        $haberme = $entregado;
        $rex = $haber / $haberme;

        DB::execute("
            INSERT INTO co_partidas (
                baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                codsubcuenta, concepto, debe, debeme, documento, factura,
                haber, haberme, idasiento, idconcepto, idcontrapartida,
                idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
            ) VALUES (
                0, '', NULL, 'BOB', NULL,
                :codsubcuenta, NULL, 0, 0, '', :factura,
                :haber, :haberme, :asiento, :idconcepto, NULL,
                '', :idsub, 0, 0, :recargo, :tasaconv, NULL
            )
        ", [
            ':codsubcuenta' => $cuentaME,
            ':factura'      => $numeroFactura,
            ':haber'        => $haber,
            ':haberme'      => $haberme,
            ':asiento'      => $idasiento,
            ':idconcepto'   => $codAgencia,
            ':idsub'        => $idSubDiv,
            ':recargo'      => $oficial,
            ':tasaconv'     => $rex,
        ], 'conta');

        $diferencia = round($totalOperacionBob - $haber, 2);
        if ($diferencia > 0) {
            $gananciame = round($diferencia / $oficial, 2);
            $rxg = $diferencia / $gananciame;
            $idSubGanancia = $this->getIdSubcuenta('54201201', $codejercicio);
            DB::execute("
                INSERT INTO co_partidas (
                    baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                    codsubcuenta, concepto, debe, debeme, documento, factura,
                    haber, haberme, idasiento, idconcepto, idcontrapartida,
                    idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                ) VALUES (
                    0, '', NULL, 'BOB', NULL,
                    '54201201', NULL, 0, 0, '', :factura,
                    :haber, :haberme, :asiento, :idconcepto, NULL,
                    '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                )
            ", [
                ':factura'    => $numeroFactura,
                ':haber'      => $diferencia,
                ':haberme'    => $gananciame,
                ':asiento'    => $idasiento,
                ':idconcepto' => $codAgencia,
                ':idsub'      => $idSubGanancia,
                ':recargo'    => $oficial,
                ':tasaconv'   => $rxg,
            ], 'conta');
        } elseif ($diferencia < 0) {
            $perdida = abs($diferencia);
            $perdidame = round($perdida / $oficial, 2);
            $rxp = $perdida / $perdidame;
            $idSubPerdida = $this->getIdSubcuenta('44502201', $codejercicio);
            DB::execute("
                INSERT INTO co_partidas (
                    baseimponible, cifnif, codcontrapartida, coddivisa, codserie,
                    codsubcuenta, concepto, debe, debeme, documento, factura,
                    haber, haberme, idasiento, idconcepto, idcontrapartida,
                    idpartida, idsubcuenta, iva, punteada, recargo, tasaconv, tipodocumento
                ) VALUES (
                    0, '', NULL, 'BOB', NULL,
                    '44502201', NULL, :debe, :debeme, '', :factura,
                    0, 0, :asiento, :idconcepto, NULL,
                    '', :idsub, 0, 0, :recargo, :tasaconv, NULL
                )
            ", [
                ':debe'       => $perdida,
                ':debeme'     => $perdidame,
                ':factura'    => $numeroFactura,
                ':asiento'    => $idasiento,
                ':idconcepto' => $codAgencia,
                ':idsub'      => $idSubPerdida,
                ':recargo'    => $oficial,
                ':tasaconv'   => $rxp,
            ], 'conta');
        }
    }

    private function getIdSubcuenta(string $codsubcuenta, string $codejercicio): ?int
    {
        $val = DB::scalar(
            "SELECT idsubcuenta FROM co_subcuentas WHERE codsubcuenta = :cod AND codejercicio = :ej",
            [':cod' => $codsubcuenta, ':ej' => $codejercicio], 'conta'
        );
        return $val ? (int) $val : null;
    }

    private function encolarAsientoPendiente(
        int $idOperacionLog,
        int $idOperacion,
        string $fecha,
        int $codUsuario,
        int $monRec,
        int $monEnt,
        string $monRecAbr,
        string $monEntAbr,
        float $monto,
        float $recibido,
        float $entregado,
        float $totalOperacionBob,
        string $codAgencia,
        string $agenciaDesc,
        string $errorMensaje,
        float $montoEfectivoBob = 0.0,
        array $cuentasBancoPago = []
    ): void {
        $tipoOp = $idOperacion === 1 ? 'COMPRA' : 'VENTA';
        $divisa = $idOperacion === 1 ? $monRecAbr : $monEntAbr;
        $montoGlosa = $idOperacion === 1 ? round($recibido, 2) : round($entregado, 2);
        $concepto = "POR {$tipoOp} DE {$divisa} {$montoGlosa} EN BASE A COTIZACION OFICIAL SEGUN BCB, EN {$agenciaDesc}";

        $hasMixto = $montoEfectivoBob > 0 && !empty($cuentasBancoPago);
        $partidas = [];

        if ($hasMixto) {
            if ($idOperacion === 1) {
                // COMPRA: BOB sale (HABER)
                if ($montoEfectivoBob > 0) {
                    $partidas[] = ['codsubcuenta' => '11101101', 'debe' => 0, 'haber' => $montoEfectivoBob, 'concepto' => $codAgencia];
                }
                foreach ($cuentasBancoPago as $cbp) {
                    $partidas[] = ['codsubcuenta' => $cbp['codsubcuenta'], 'debe' => 0, 'haber' => $cbp['monto_bob'], 'concepto' => $codAgencia];
                }
                $partidas[] = ['codsubcuenta' => '11101201', 'debe' => $monto, 'haber' => 0, 'concepto' => $codAgencia];
            } else {
                // VENTA: BOB entra (DEBE)
                if ($montoEfectivoBob > 0) {
                    $partidas[] = ['codsubcuenta' => '11101101', 'debe' => $montoEfectivoBob, 'haber' => 0, 'concepto' => $codAgencia];
                }
                foreach ($cuentasBancoPago as $cbp) {
                    $partidas[] = ['codsubcuenta' => $cbp['codsubcuenta'], 'debe' => $cbp['monto_bob'], 'haber' => 0, 'concepto' => $codAgencia];
                }
                $partidas[] = ['codsubcuenta' => '11101201', 'debe' => 0, 'haber' => $entregado, 'concepto' => $codAgencia];
            }
        } else {
            if ($idOperacion === 1) {
                $partidas[] = ['codsubcuenta' => '11101101', 'debe' => 0, 'haber' => $totalOperacionBob, 'concepto' => $codAgencia];
                $partidas[] = ['codsubcuenta' => '11101201', 'debe' => $monto, 'haber' => 0, 'concepto' => $codAgencia];
            } else {
                $partidas[] = ['codsubcuenta' => '11101101', 'debe' => $totalOperacionBob, 'haber' => 0, 'concepto' => $codAgencia];
                $partidas[] = ['codsubcuenta' => '11101201', 'debe' => 0, 'haber' => $entregado, 'concepto' => $codAgencia];
            }
        }

        DB::execute("
            INSERT INTO pos_asiento_pendiente (
                id_operacion_log, tipo_operacion, codejercicio, fecha,
                concepto, documento, partidas, estado, error_mensaje, created_at
            ) VALUES (
                :id_log, :tipo, :ej, :fecha,
                :concepto, '', :partidas, 'pendiente', :error, :created
            )
        ", [
            ':id_log'   => $idOperacionLog,
            ':tipo'     => 'cambio',
            ':ej'       => date('Y'),
            ':fecha'    => substr($fecha, 0, 10),
            ':concepto' => $concepto,
            ':partidas' => json_encode($partidas),
            ':error'    => $errorMensaje,
            ':created'  => $fecha,
        ]);
    }

    private function getParametrosUif(): array
    {
        $slugs = [
            'monto_cambio_moneda_operacion_unica',
            'monto_cambio_moneda_pcc06_operacion_unica',
            'monto_cambio_moneda_operaciones_multiples',
            'monto_cambio_moneda_pcc06_operaciones_multiples',
            'dias_control_multiples_cambio_moneda_formulario',
            'dias_control_multiples_cambio_moneda_pcc06',
            'numero_operaciones_multiples_cambio_moneda_formulario',
            'numero_operaciones_multiples_cambio_moneda_pcc06',
        ];

        // Hay slugs históricos duplicados en `parametros`. Tomar el primer
        // registro ordenado por vigencia evita que el valor dependa del orden
        // accidental en que el motor devuelva las filas.
        $rows = DB::select("
            SELECT id, slug, valor, fecha_registro, fecha_modificacion
            FROM parametros
            WHERE estado = 'A' AND slug IN ('" . implode("','", $slugs) . "')
            ORDER BY COALESCE(fecha_modificacion, fecha_registro) DESC, id DESC
        ");

        $map = [];
        foreach ($rows as $r) {
            if (!array_key_exists($r['slug'], $map)) {
                $map[$r['slug']] = $r['valor'];
            }
        }

        return [
            'unica_formulario'    => $map['monto_cambio_moneda_operacion_unica'] ?? 10000,
            'unica_pcc06'         => $map['monto_cambio_moneda_pcc06_operacion_unica'] ?? 10000,
            'multiples_formulario' => $map['monto_cambio_moneda_operaciones_multiples'] ?? 10000,
            'multiples_pcc06'     => $map['monto_cambio_moneda_pcc06_operaciones_multiples'] ?? 10000,
            'dias_formulario'     => $map['dias_control_multiples_cambio_moneda_formulario'] ?? 30,
            'dias_pcc06'          => $map['dias_control_multiples_cambio_moneda_pcc06'] ?? 30,
            'num_ops_formulario'  => $map['numero_operaciones_multiples_cambio_moneda_formulario'] ?? 3,
            'num_ops_pcc06'       => $map['numero_operaciones_multiples_cambio_moneda_pcc06'] ?? 3,
        ];
    }

    private function getTipoCambioOficial(int $idMoneda, int $idOperacion): float
    {
        if ($idMoneda === 1) return 1.0;

        $val = DB::scalar("
            SELECT co.valor_me
            FROM cotizacion_oficial co
            WHERE co.id_moneda = :mon AND co.id_operacion = 1
            ORDER BY co.fec_crea_cotizacion_oficial DESC
            LIMIT 1
        ", [':mon' => $idMoneda]);

        return $val ? (float) $val : 0.0;
    }

    private function getOperacionesPrevias(string $doc, int $tipoDoc, int $exp, int $dias): array
    {
        $row = DB::selectOne("
            SELECT COUNT(*) AS cantidad,
                   COALESCE(SUM(
                       CASE
                           WHEN id_operacion = 1 THEN ROUND(recibido / GREATEST(tipo_cambio_oficial(mon_rec), 0.0001), 2)
                           ELSE ROUND(entregado / GREATEST(tipo_cambio_oficial(mon_ent), 0.0001), 2)
                       END
                   ), 0) AS sumatoria
            FROM cambio
            WHERE num_doc_identidad = :doc
              AND id_tipo_doc_identidad = :tipo
              AND id_expedicion = :exp
              AND fec_crea_cambio >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
        ", [
            ':doc'  => mb_strtoupper(trim($doc)),
            ':tipo' => $tipoDoc,
            ':exp'  => $exp,
            ':dias' => $dias,
        ]);

        return [
            'cantidad'  => (int) ($row['cantidad'] ?? 0),
            'sumatoria' => (float) ($row['sumatoria'] ?? 0),
        ];
    }

    private function hayPcc06EnPeriodo(string $doc, int $tipoDoc, int $exp, int $dias): bool
    {
        $count = (int) DB::scalar("
            SELECT COUNT(*)
            FROM cambio
            WHERE num_doc_identidad = :doc
              AND id_tipo_doc_identidad = :tipo
              AND id_expedicion = :exp
              AND fec_crea_cambio >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
              AND impresion_pcc06 = 'S'
        ", [
            ':doc'  => mb_strtoupper(trim($doc)),
            ':tipo' => $tipoDoc,
            ':exp'  => $exp,
            ':dias' => $dias,
        ]);

        return $count > 0;
    }

    private function sumaUltimasOperaciones(string $doc, int $tipoDoc, int $exp, int $dias): float
    {
        $val = DB::scalar("
            SELECT COALESCE(SUM(sub.dolarizado), 0)
            FROM (
                SELECT
                    CASE
                        WHEN id_operacion = 1 THEN ROUND(recibido / GREATEST(tipo_cambio_oficial(mon_rec), 0.0001), 2)
                        ELSE ROUND(entregado / GREATEST(tipo_cambio_oficial(mon_ent), 0.0001), 2)
                    END AS dolarizado
                FROM cambio
                WHERE num_doc_identidad = :doc
                  AND id_tipo_doc_identidad = :tipo
                  AND id_expedicion = :exp
                  AND fec_crea_cambio >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
                ORDER BY fec_crea_cambio DESC
                LIMIT 3
            ) sub
        ", [
            ':doc'  => mb_strtoupper(trim($doc)),
            ':tipo' => $tipoDoc,
            ':exp'  => $exp,
            ':dias' => $dias,
        ]);

        return (float) ($val ?? 0);
    }

    private function verificarListasControl(string $doc, int $tipoDoc, int $expedicion): array
    {
        $alertas = [];

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

    private function getParametroFloat(string $slug, float $default): float
    {
        $val = DB::scalar(
            "SELECT valor FROM parametros WHERE slug = :s AND estado = 'A' LIMIT 1",
            [':s' => $slug]
        );
        return $val !== null && $val !== false ? (float) $val : $default;
    }

    private function detectarSenalesAlerta(
        string $doc, int $tipoDoc, int $expedicion, float $dolarizado, ?array $uf
    ): array {
        $senales = [];
        $doc = mb_strtoupper(trim($doc));

        $umbralPcc06 = $this->getParametroFloat('monto_cambio_moneda_pcc06_operacion_unica', 3000);
        $margenStructuring = $umbralPcc06 * 0.1;
        if ($dolarizado >= ($umbralPcc06 - $margenStructuring) && $dolarizado < $umbralPcc06) {
            $senales[] = [
                'codigo' => 'STRUCTURING',
                'mensaje' => 'Operación cercana al umbral PCC-06 (posible estructuración)',
                'nivel' => 'medio',
            ];
        }

        $ops30 = $this->getOperacionesPrevias($doc, $tipoDoc, $expedicion, 30);
        $ops7 = $this->getOperacionesPrevias($doc, $tipoDoc, $expedicion, 7);
        if ($ops7['cantidad'] >= 5) {
            $senales[] = [
                'codigo' => 'FREQ_SPIKE',
                'mensaje' => "Alta frecuencia: {$ops7['cantidad']} operaciones en 7 días",
                'nivel' => 'alto',
            ];
        } elseif ($ops30['cantidad'] >= 10) {
            $senales[] = [
                'codigo' => 'FREQ_ELEVADA',
                'mensaje' => "{$ops30['cantidad']} operaciones en 30 días",
                'nivel' => 'medio',
            ];
        }

        if ($uf && !empty($uf['id_actividad_economica'])) {
            $actEconomica = DB::selectOne(
                "SELECT descripcion, riesgo FROM actividad_economica WHERE id_actividad_economica = :id LIMIT 1",
                [':id' => (int) $uf['id_actividad_economica']]
            );
            if ($actEconomica && $dolarizado > 5000) {
                $riesgo = strtoupper($actEconomica['riesgo'] ?? '');
                if (in_array($riesgo, ['ALTO', 'A'])) {
                    $senales[] = [
                        'codigo' => 'ACT_INCONSISTENTE',
                        'mensaje' => 'Monto elevado con actividad económica de riesgo alto: ' . ($actEconomica['descripcion'] ?? ''),
                        'nivel' => 'alto',
                    ];
                }
            }
        }

        return $senales;
    }

    private function tieneSesionAbierta(int $codUsuario): bool
    {
        $count = (int) DB::scalar(
            "SELECT COUNT(*) FROM pos_sesion_caja
             WHERE cod_usuario = :usr AND fecha = CURDATE() AND estado = 'abierta'",
            [':usr' => $codUsuario]
        );
        return $count > 0;
    }

    private function isBovedaAbierta(int $idAgencia): bool
    {
        $count = (int) DB::scalar(
            "SELECT COUNT(*) FROM saldo_boveda
             WHERE id_boveda IN (SELECT id_boveda FROM boveda WHERE id_agencia = :ag)
               AND DATE(fec_crea_saldo) = CURDATE() AND conciliacion = 'A'",
            [':ag' => $idAgencia]
        );
        return $count > 0;
    }

    private function resolveIdCiudad(array $c): int
    {
        $idCiudad = (int) ($c['id_ciudad'] ?? 0);
        if ($idCiudad > 0) {
            $dpto = DB::scalar(
                "SELECT id_departamento FROM ciudad_pcc WHERE id_ciudad_pcc = :id LIMIT 1",
                [':id' => $idCiudad]
            );
            if ($dpto !== null && $dpto !== false) {
                return (int) $dpto;
            }
        }
        return (int) ($c['id_expedicion'] ?? 0);
    }

    private function upsertUsuarioFinanciero(array $c, string $fecha): int
    {
        $idCiudadResolved = $this->resolveIdCiudad($c);

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
                ':ci'    => $idCiudadResolved,
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
            ':ci'    => $idCiudadResolved,
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

    private function registrarComplemento(int $idUsuarioFinanciero, array $c, string $fecha): void
    {
        $cPep = 'N';
        $cPepStipo = 'ninguno';

        $cargoAnteriorRiesgo = 'N';
        $cargoActualRiesgo = 'N';

        $depAnterior = ($c['c_dependiente_estado_anterior'] ?? 'N') === 'S' ? 'S' : 'N';
        $depActual = ($c['c_dependiente_estado_actual'] ?? 'N') === 'S' ? 'S' : 'N';

        $cargoAnteriorId = (int) ($c['c_cargo_trabajo_anterior_id'] ?? 1);
        $cargoActualId = (int) ($c['c_cargo_trabajo_actual_id'] ?? 1);

        if ($depAnterior === 'S' && $cargoAnteriorId > 0) {
            $riesgo = DB::scalar(
                "SELECT riesgo FROM listas_cargos WHERE id = :id",
                [':id' => $cargoAnteriorId]
            );
            $cargoAnteriorRiesgo = ($riesgo === 'S') ? 'S' : 'N';
        }

        if ($depActual === 'S' && $cargoActualId > 0) {
            $riesgo = DB::scalar(
                "SELECT riesgo FROM listas_cargos WHERE id = :id",
                [':id' => $cargoActualId]
            );
            $cargoActualRiesgo = ($riesgo === 'S') ? 'S' : 'N';
        }

        $fechaFinAnterior = trim($c['c_fecha_fin_trabajo_anterior'] ?? '');
        if ($depAnterior === 'S' && $cargoAnteriorRiesgo === 'S' && $fechaFinAnterior) {
            $limiteYears = (int) (DB::scalar(
                "SELECT valor FROM parametros WHERE slug = 'limite_anyos_ya_no_pep' AND estado = 'A'"
            ) ?: 5);

            $ahora = new \DateTime();
            $fin = new \DateTime($fechaFinAnterior);
            $diff = $ahora->diff($fin);
            if ($diff->y > $limiteYears) {
                $cargoAnteriorRiesgo = 'N';
            }
        }

        if ($cargoAnteriorRiesgo === 'S' || $cargoActualRiesgo === 'S') {
            $cPep = 'S';
            $cPepStipo = 'NAL';
        }

        DB::execute("
            INSERT INTO usuario_financiero_complemento (
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
                :uf_id, :apellido_casada, :pais_res, :ciudad_res,
                :ingresos, :fecha_ingreso, :exp_trabajo, :pep, :pep_stipo,
                :act_sec, :ing_act_sec, :exp_act_sec,
                :ref_banc, :ref_com, :ref_per,
                :dep_ant, :ent_ant, :fi_ant,
                :ff_ant, :cargo_ant,
                :dep_act, :ent_act, :cargo_act,
                :nom_con, :ap_con, :am_con,
                :ac_con, :prof_con, :act_prin_con,
                :act_sec_con, :lugar_con, :cargo_con,
                :ing_con, :exp_con, :fecha
            )
        ", [
            ':uf_id'         => $idUsuarioFinanciero,
            ':apellido_casada' => mb_strtoupper(trim($c['c_apellido_casada'] ?? '')),
            ':pais_res'      => (int) ($c['c_pais_residencia_id'] ?? 26),
            ':ciudad_res'    => mb_strtoupper(trim($c['c_ciudad_residencia'] ?? '')),
            ':ingresos'      => (float) ($c['c_ingresos'] ?? 0),
            ':fecha_ingreso' => $c['c_fecha_ingreso'] ?? null,
            ':exp_trabajo'   => mb_strtoupper(trim($c['c_experiencia_trabajo'] ?? '')),
            ':pep'           => $cPep,
            ':pep_stipo'     => $cPepStipo,
            ':act_sec'       => ((int) ($c['c_actividad_secundaria_id'] ?? 0)) ?: 1,
            ':ing_act_sec'   => (float) ($c['c_ingresos_actividad_secundaria'] ?? 0),
            ':exp_act_sec'   => mb_strtoupper(trim($c['c_experiencia_actividad_secundaria'] ?? '')),
            ':ref_banc'      => mb_strtoupper(trim($c['c_referencias_bancarias'] ?? '')),
            ':ref_com'       => mb_strtoupper(trim($c['c_referencias_comerciales'] ?? '')),
            ':ref_per'       => mb_strtoupper(trim($c['c_referencias_personales'] ?? '')),
            ':dep_ant'       => $depAnterior,
            ':ent_ant'       => mb_strtoupper(trim($c['c_entidad_trabajo_anterior'] ?? '')),
            ':fi_ant'        => $c['c_fecha_inicio_trabajo_anterior'] ?? null,
            ':ff_ant'        => $fechaFinAnterior ?: null,
            ':cargo_ant'     => $cargoAnteriorId ?: 1,
            ':dep_act'       => $depActual,
            ':ent_act'       => mb_strtoupper(trim($c['c_entidad_trabajo_actual'] ?? '')),
            ':cargo_act'     => $cargoActualId ?: 1,
            ':nom_con'       => mb_strtoupper(trim($c['c_nombres_conyugue'] ?? '')),
            ':ap_con'        => mb_strtoupper(trim($c['c_apellido_paterno_conyugue'] ?? '')),
            ':am_con'        => mb_strtoupper(trim($c['c_apellido_materno_conyugue'] ?? '')),
            ':ac_con'        => mb_strtoupper(trim($c['c_apellido_casada_conyugue'] ?? '')),
            ':prof_con'      => ((int) ($c['c_profesion_conyugue_id'] ?? 0)) ?: 1,
            ':act_prin_con'  => ((int) ($c['c_actividad_principal_conyugue_id'] ?? 0)) ?: 1,
            ':act_sec_con'   => ((int) ($c['c_actividad_secundaria_conyugue_id'] ?? 0)) ?: 1,
            ':lugar_con'     => mb_strtoupper(trim($c['c_lugar_trabajo_conyugue'] ?? '')),
            ':cargo_con'     => ((int) ($c['c_cargo_conyugue_id'] ?? 0)) ?: 1,
            ':ing_con'       => (float) ($c['c_ingreso_mensual_conyugue'] ?? 0),
            ':exp_con'       => mb_strtoupper(trim($c['c_experiencia_trabajo_conyugue'] ?? '')),
            ':fecha'         => $fecha,
        ]);
    }

    // ─── PDF Factura Cambio ──────────────────────────────────────────

    public function facturaPdf(int $idCambio): array
    {
        $facturado = DB::selectOne("
            SELECT f.*, a.descripcion AS agencia,
                   a.telefono AS agencia_telefono
            FROM facturado f
            JOIN agencia a ON a.id_agencia = f.id_agencia
            WHERE f.id_operacion IN (1, 2) AND f.transaccion = :id
            LIMIT 1
        ", [':id' => $idCambio]);

        if (!$facturado) {
            abort(404, 'Factura no encontrada para este cambio.');
        }

        $feFactura = DB::selectOne("
            SELECT ff.cuf, ff.numero_factura, ff.estado, ff.tipo_emision, ff.created_at,
                   fl.descripcion AS leyenda
            FROM fe_factura ff
            LEFT JOIN fe_leyenda fl ON fl.id_leyenda = ff.id_leyenda
            WHERE ff.id_operacion = :id AND ff.id_tipo_operacion IN (1, 2)
            ORDER BY ff.created_at DESC LIMIT 1
        ", [':id' => $idCambio]);

        $cambio = DB::selectOne("
            SELECT c.id_cambio, c.id_operacion, c.cod_usuario, c.id_agencia,
                   c.mon_rec, c.mon_ent, c.tipo_cambio, c.monto,
                   c.id_tipo_doc_identidad, c.num_doc_identidad, c.id_expedicion,
                   c.recibido, c.entregado,
                   u.login_usu AS operador
            FROM cambio c
            LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
            WHERE c.id_cambio = :id
        ", [':id' => $idCambio]);

        $sucursalPv = DB::selectOne("
            SELECT fs.sucursal AS sucursal_num, fpv.codigo_punto_venta AS pv_num,
                   fs.direccion, fs.municipio
            FROM fe_punto_venta fpv
            INNER JOIN fe_sucursal fs ON fs.id_sucursal = fpv.id_sucursal
            INNER JOIN agencia a ON a.id_agencia = fs.id_agencia
            WHERE a.id_agencia = :ag
            ORDER BY fpv.activo DESC LIMIT 1
        ", [':ag' => $facturado['id_agencia']]);

        $logoPath = public_path('images/logo_texto.png');
        $logoBase64 = file_exists($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
            : '';

        $nitEmpresa  = '335846029';

        $numero        = $facturado['numero'] ?? '';
        $nitCliente    = $facturado['nit'] ?? '';
        $nombreCliente = $facturado['nombre'] ?? '';
        $importe       = (float) ($facturado['importe'] ?? 0);
        $cuf           = $feFactura['cuf'] ?? ($facturado['codigo_control'] ?? '');
        $leyenda       = $feFactura['leyenda'] ?? '';
        $telefono      = $facturado['agencia_telefono'] ?? '';
        $direccion     = $sucursalPv['direccion'] ?? '';
        $municipio     = $sucursalPv['municipio'] ?? '';
        $sucursal      = $sucursalPv['sucursal_num'] ?? '0';
        $puntoVenta    = $sucursalPv['pv_num'] ?? '0';
        $operador      = $cambio['operador'] ?? '';
        $transaccion   = str_pad($idCambio, 7, '0', STR_PAD_LEFT);

        $fechaEmision = $facturado['fecha'] . ' ' . $facturado['hora'];

        $idOperacion = (int) ($cambio['id_operacion'] ?? $facturado['id_operacion']);
        $tipoCambioCV = (float) ($cambio['tipo_cambio'] ?? 0);
        $complemento = (string) (DB::scalar(
            "SELECT abreviatura FROM expedicion WHERE id_expedicion = :exp",
            [':exp' => (int) ($cambio['id_expedicion'] ?? 0)]
        ) ?? '');

        $monDivisa = $idOperacion === 1 ? (int) ($cambio['mon_rec'] ?? 1) : (int) ($cambio['mon_ent'] ?? 1);
        $idOpOficial = 1;
        if ($idOperacion === 2 && $monDivisa === 1) {
            $idOpOficial = 2;
        }
        $tcOficial = (float) DB::scalar("
            SELECT co.valor FROM cotizacion_oficial co
            WHERE co.id_moneda = :mon AND co.id_operacion = :op
            ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1
        ", [':mon' => $monDivisa, ':op' => $idOpOficial]) ?: 1.0;

        $monedaDesc = DB::scalar("SELECT descripcion FROM moneda WHERE id_moneda = :m", [':m' => $monDivisa]) ?: '';
        $monedaAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monDivisa]) ?: '';

        $diferenciaCambio = round(abs($tipoCambioCV - $tcOficial), 2);
        $cantidadMoneda = $idOperacion === 1
            ? (float) ($cambio['recibido'] ?? 0)
            : (float) ($cambio['entregado'] ?? 0);
        $subtotalRaw = $cantidadMoneda * $tipoCambioCV;

        $codigoServicio = $idOperacion === 1
            ? FacturacionService::CODIGO_SERVICIO_COMPRA
            : FacturacionService::CODIGO_SERVICIO_VENTA;
        $servCambio = DB::selectOne("
            SELECT fs.codigo, fs.nombre
            FROM fe_servicio fs
            WHERE fs.codigo = :cod
            ORDER BY fs.updated_at DESC LIMIT 1
        ", [':cod' => $codigoServicio]);
        $servicioNombre = $servCambio
            ? ($servCambio['codigo'] . ' - ' . $servCambio['nombre'])
            : ($codigoServicio . ' - ' . ($idOperacion === 1 ? 'COMPRA DE MONEDA EXTRANJERA' : 'VENTA DE MONEDA EXTRANJERA'));

        $codigoCliente = $this->generarCodigoClienteCambio($nombreCliente, $nitCliente);

        $n = function ($v, int $d = 2) { return number_format($v, $d, ',', '.'); };
        $montoEnLetras = $this->montoALetrasCambio($importe);

        $qrContent = "https://piloto.siat.impuestos.gob.bo/consulta/QR?nit={$nitEmpresa}&cuf={$cuf}&numero={$numero}&t=2";
        $qrBase64 = $this->generarQrBase64Cambio($qrContent);

        $cantidadFmt = number_format($cantidadMoneda, 5, ',', '.');
        $tcCVFmt = number_format($tipoCambioCV, 5, ',', '.');
        $subtotalFmt = number_format($subtotalRaw, 5, ',', '.');

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
  .title { font-size: 13px; font-weight: bold; text-align: center; margin: 6px 0 2px; }
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

  <div class="title">FACTURA DE COMPRA Y VENTA DE<br>MONEDA EXTRANJERA</div>
  <div class="subtitle">(Sin derecho a crédito fiscal)</div>
  <div class="subtitle">Sucursal No. {$sucursal}</div>
  <div class="subtitle">No. Punto de Venta {$puntoVenta}</div>
  <div class="subtitle">{$direccion}</div>
  <div class="subtitle">Tel. {$telefono}</div>
  <div class="subtitle">{$municipio}</div>
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
    <tr><td class="info-lbl">TIPO CAMBIO OFICIAL:</td><td class="info-val">{$n($tcOficial, 5)}</td></tr>
    <tr><td class="info-lbl">TIPO CAMBIO C/V:</td><td class="info-val">{$n($tipoCambioCV, 5)}</td></tr>
    <tr><td class="info-lbl">MONEDA:</td><td class="info-val">{$monedaDesc}</td></tr>
    <tr><td class="info-lbl">INGRESO DIFERENCIA:</td><td class="info-val">{$n($diferenciaCambio)}</td></tr>
  </table>

  <div class="detail-title">DETALLE</div>
  <div class="detail-line"><strong>{$servicioNombre}</strong></div>
  <div class="detail-line">Unidad de medida: Unidad (SERVICIOS)</div>
  <div class="detail-line">{$monedaAbr}-{$cantidadFmt} X {$tcCVFmt} <span style="float:right;">{$subtotalFmt}</span></div>
  <table class="totals">
    <tr><td colspan="2"><div class="sep" style="margin:2px 0;"></div></td></tr>
    <tr><td class="lbl">SUBTOTAL Bs</td><td class="val">{$n($importe)}</td></tr>
    <tr><td class="lbl">DESCUENTO Bs</td><td class="val">0,00</td></tr>
    <tr><td class="lbl">MONTO GIFT CARD Bs</td><td class="val">0,00</td></tr>
    <tr><td class="lbl" style="font-weight:bold;">TOTAL Bs</td><td class="val">{$n($importe)}</td></tr>
  </table>
  <div class="son"><span class="bold">SON:</span> {$montoEnLetras} BOLIVIANOS</div>

  <div class="sep"></div>
  <div class="legal">Esta factura contribuye al desarrollo del país, el uso ilícito será sancionado penalmente de acuerdo a Ley.</div>
  <div class="legal" style="font-style:italic;">{$leyenda}</div>
  <div class="legal">Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea.</div>

  <div class="center" style="margin:8px 0;"><img src="{$qrBase64}" style="width:38mm;height:38mm;" alt="QR"></div>

  <div class="legal">Por favor ingrese al siguiente link<br><strong>https://www.gambarte.com.bo/facturas</strong><br>para consultar la Representación Gráfica y XML de sus Facturas Electrónicas</div>
  <div class="sep"></div>

  <div class="footer-row" style="display:table;width:100%;">
    <span style="display:table-cell;">OPERADOR: {$operador}</span>
    <span style="display:table-cell;text-align:right;">Transacción: {$transaccion}</span>
  </div>

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

        $tipoOp = $idOperacion === 1 ? 'Compra' : 'Venta';

        return [
            'binary'   => $dompdf->output(),
            'filename' => "Factura_{$tipoOp}_{$numero}.pdf",
        ];
    }

    private function getCotizacionOficialVentaCambio(int $idMoneda): float
    {
        return (float) DB::scalar("
            SELECT valor FROM cotizacion_oficial
            WHERE id_moneda = :mon AND id_operacion = 2
              AND DATE(fec_crea_cotizacion_oficial) <= CURDATE()
            ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1
        ", [':mon' => $idMoneda]) ?: 1.0;
    }

    private function generarQrBase64Cambio(string $content): string
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

    private function generarCodigoClienteCambio(string $nombre, string $documento): string
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

    private function montoALetrasCambio(float $monto): string
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

    private function encolarNotificacionesCambio(
        int $idCambio, string $tipoOp, string $clienteNombre,
        float $monto, string $monedaAbr, float $tipoCambio,
        float $totalCambio, float $recibido, float $entregado,
        string $agencia, string $emailCliente, ?array $facturaData
    ): void {
        $montoFmt = number_format($monto, 2);
        $tcFmt = number_format($tipoCambio, 5);
        $totalFmt = number_format($totalCambio, 2);
        $recibidoFmt = number_format($recibido, 2);
        $entregadoFmt = number_format($entregado, 2);
        $fecha = date('d/m/Y H:i');

        $facturaHtml = '';
        if ($facturaData && !empty($facturaData['numero_factura'])) {
            $facturaHtml = $this->emailTableRowCambio('Nro. Factura', $facturaData['numero_factura'])
                . $this->emailTableRowCambio('CUF', $facturaData['cuf'] ?? '');
        }

        $contenido = $this->buildEmailHtmlCambio(
            "Comprobante de {$tipoOp} de Moneda #{$idCambio}",
            "Estimado/a {$clienteNombre},",
            "Su operación de {$tipoOp} de moneda extranjera ha sido registrada exitosamente. Adjuntamos su factura correspondiente.",
            $this->emailTableRowCambio('Nro. Operación', $idCambio)
            . $this->emailTableRowCambio('Tipo', $tipoOp)
            . $this->emailTableRowCambio('Fecha', $fecha)
            . $this->emailTableRowCambio('Monto', "{$montoFmt} {$monedaAbr}")
            . $this->emailTableRowCambio('Tipo de Cambio', $tcFmt)
            . $this->emailTableRowCambio('Total', "{$totalFmt} BOB")
            . $this->emailTableRowCambio('Recibido', "{$recibidoFmt}")
            . $this->emailTableRowCambio('Entregado', "{$entregadoFmt}")
            . $this->emailTableRowCambio('Agencia', $agencia)
            . $facturaHtml,
            'Gracias por confiar en Gambarte Bolivia S.R.L.'
        );

        $adjuntos = [];
        try {
            $factura = $this->facturaPdf($idCambio);
            $adjuntos[] = [
                'data'   => $factura['binary'],
                'nombre' => "Factura_Cambio_{$idCambio}.pdf",
                'mime'   => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("No se pudo generar factura PDF para cambio {$idCambio}: " . $e->getMessage());
        }

        $this->enviarOEncolarCambio($emailCliente, "Gambarte - Comprobante de {$tipoOp} #{$idCambio}", $contenido, $idCambio, $adjuntos);
    }

    private function emailTableRowCambio(string $label, $value): string
    {
        return '<tr>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eee;color:#666;font-size:13px;width:40%">' . htmlspecialchars($label) . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eee;color:#333;font-size:13px;font-weight:600">' . htmlspecialchars((string) $value) . '</td>'
            . '</tr>';
    }

    private function buildEmailHtmlCambio(string $titulo, string $saludo, string $intro, string $tableRows, string $cierre): string
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

    private function enviarOEncolarCambio(string $email, string $asunto, string $contenido, int $idCambio, array $adjuntos = []): void
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
                VALUES ('email', :dest, 'cambio', :ref, :asunto, :contenido, 'enviado', NOW(), 1)
            ", [
                ':dest'      => $email,
                ':ref'       => $idCambio,
                ':asunto'    => $asunto,
                ':contenido' => $contenido,
            ]);
        } catch (\Throwable $e) {
            DB::execute("
                INSERT INTO pos_notificacion_operacion
                    (canal, destinatario, tipo_operacion, id_referencia, asunto, contenido, estado, intentos, error_mensaje)
                VALUES ('email', :dest, 'cambio', :ref, :asunto, :contenido, 'pendiente', 0, :err)
            ", [
                ':dest'      => $email,
                ':ref'       => $idCambio,
                ':asunto'    => $asunto,
                ':contenido' => $contenido,
                ':err'       => mb_substr($e->getMessage(), 0, 500),
            ]);
        } finally {
            set_time_limit((int) $prevLimit);
        }
    }
}
