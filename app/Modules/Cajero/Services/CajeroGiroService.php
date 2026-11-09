<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;
use App\Modules\Facturacion\FacturacionService;

class CajeroGiroService
{
    private const IVA_PORCENTAJE = 13;
    private const IT_PORCENTAJE = 3;

    // Slugs de parámetros UIF para giros nacionales (tabla `parametros`)
    private const PARAM_PCC08_UNICA = 'monto_giro_pcc08_operacion_unica';
    private const PARAM_PCC08_MULTIPLES = 'monto_giro_pcc08_operaciones_multiples';
    private const PARAM_PCC08_DIAS = 'dias_control_multiples_giros_pcc08';
    private const PARAM_PCC08_NUM_OPS = 'numero_operaciones_multiples_giros_pcc08';
    private const PARAM_FORMULARIO_UNICA = 'monto_giro_operacion_unica';
    private const PARAM_FORMULARIO_MULTIPLES = 'monto_giro_operaciones_multiples';
    private const PARAM_FORMULARIO_DIAS = 'dias_control_multiples_giros_formulario';
    private const PARAM_FORMULARIO_NUM_OPS = 'numero_operaciones_multiples_giros_formulario';

    // Defaults UIF (fallback si no existen en DB)
    private const DEFAULT_PCC08_UNICA = 10000;
    private const DEFAULT_PCC08_MULTIPLES = 20000;
    private const DEFAULT_PCC08_DIAS = 30;
    private const DEFAULT_PCC08_NUM_OPS = 3;
    private const DEFAULT_FORMULARIO_UNICA = 5000;
    private const DEFAULT_FORMULARIO_MULTIPLES = 10000;
    private const DEFAULT_FORMULARIO_DIAS = 30;
    private const DEFAULT_FORMULARIO_NUM_OPS = 3;

    // Subcuentas contables
    private const SUBCUENTA_GIRO_POR_PAGAR_BOB = '24401101';
    private const SUBCUENTA_GIRO_POR_PAGAR_USD = '24401201';
    private const SUBCUENTA_COMI_GIRO = '54105104';
    private const SUBCUENTA_DEBITO_FISCAL_IVA = '24204102';
    private const SUBCUENTA_TRANSACCION = '45502101';
    private const SUBCUENTA_IMPUESTO_TRANSACCIONES = '24204101';
    private const SUBCUENTA_ENTRADA_CAJA_COMISION = '11101101';
    private const SUBCUENTA_CAJA_BOB = '11101101';
    private const SUBCUENTA_CAJA_USD = '11101201';
    private const SUBCUENTA_RETENCION_ITF = '24203103';
    private const SUBCUENTA_GANANCIA = '54201201';
    private const SUBCUENTA_PERDIDA = '44502201';
    private const SUBCUENTA_INTEROFICINAS = '18308101';

    private const ID_OPERACION_GIRO = 3;

    // ─── Agencias destino ──────────────────────────────────────────────

    public function agenciasDestino(int $idAgenciaActual): array
    {
        return DB::select("
            SELECT id_agencia, cod_agencia, descripcion
            FROM agencia
            WHERE estado = 'A' AND id_agencia != :ag
            ORDER BY descripcion
        ", [':ag' => $idAgenciaActual]);
    }

    public function entidadesFinancieras(): array
    {
        return DB::select("
            SELECT id_entidad_financiera AS id, descripcion AS nombre, nombre_corto
            FROM entidad_financiera
            WHERE estado = 'A' AND id_tipo_entidad_financiera != 8
            ORDER BY descripcion
        ");
    }

    // ─── Destinatarios previos del remitente ────────────────────────────

    public function destinatariosPorRemitente(string $documento, int $tipoDoc, int $expedicion): array
    {
        return DB::select("
            SELECT DISTINCT d.id_destinatario, d.nombres, d.ape_pat, d.ape_mat,
                   d.id_tipo_doc_identidad_d, d.num_doc_identidad_d, d.id_expedicion_d,
                   d.domicilio, d.celular, d.tel_dom
            FROM destinatario d
            JOIN giro g ON g.id_destinatario = d.id_destinatario
            WHERE g.num_doc_identidad = :doc
              AND g.id_tipo_doc_identidad = :tipo
              AND g.id_expedicion = :exp
            ORDER BY g.fec_crea_giro DESC
            LIMIT 20
        ", [
            ':doc'  => mb_strtoupper(trim($documento)),
            ':tipo' => $tipoDoc,
            ':exp'  => $expedicion,
        ]);
    }

    public function buscarBeneficiarioFinal(string $documento): ?array
    {
        return DB::selectOne("
            SELECT id, nombres, apellido_paterno, apellido_materno, apellido_casada,
                   id_tipo_doc_identidad, numero_documento, nit, id_actividad_economica,
                   id_departamento, id_ciudad_pcc, zona, domicilio, telefono
            FROM beneficiario_final
            WHERE numero_documento = :doc
            ORDER BY id DESC LIMIT 1
        ", [':doc' => trim($documento)]);
    }

    // ─── Verificación UIF para giros (PCC-08) ──────────────────────────

    public function verificarUifGiro(
        int $idMoneda,
        float $monto,
        string $documento,
        int $tipoDoc,
        int $expedicion
    ): array {
        $params = $this->getParametrosUifGiro();
        $tcOficial = $this->getTipoCambioOficialGiro($idMoneda);

        // Giros nacionales: umbrales en BOB → bolivianizar (no dolarizar)
        // BOB (id=2): monto directo; USD (id=1): monto * TC
        $bolivianizado = ($idMoneda === 2)
            ? $monto
            : round($monto * $tcOficial, 2);

        $requiereFormulario = false;
        $requierePcc08 = false;

        $doc = mb_strtoupper(trim($documento));

        $opsPrevFormulario = $this->getGirosPrevios($doc, $tipoDoc, $expedicion, (int) $params['dias_formulario']);
        $opsPrevPcc08 = $this->getGirosPrevios($doc, $tipoDoc, $expedicion, (int) $params['dias_pcc08']);

        $uf = $this->buscarUsuarioFinanciero($doc, $tipoDoc, $expedicion);
        $nivelImpresion = $uf ? (int) ($uf['nivel_impresion_formulario'] ?? 1) : 0;

        if (empty($uf) || $nivelImpresion < 2) {
            if ($bolivianizado >= (float) $params['unica_formulario']) {
                $requiereFormulario = true;
            } elseif (
                ($opsPrevFormulario['cantidad'] + 1) >= (int) $params['num_ops_formulario']
                && ($opsPrevFormulario['sumatoria'] + $bolivianizado) >= (float) $params['multiples_formulario']
            ) {
                $requiereFormulario = true;
            }
        }

        if ($bolivianizado >= (float) $params['unica_pcc08']) {
            $requierePcc08 = true;
        } else {
            $hayPcc08EnPeriodo = $this->hayPcc08EnPeriodo($doc, $tipoDoc, $expedicion, (int) $params['dias_pcc08']);

            if (!$hayPcc08EnPeriodo) {
                if (
                    ($opsPrevPcc08['cantidad'] + 1) >= (int) $params['num_ops_pcc08']
                    && ($opsPrevPcc08['sumatoria'] + $bolivianizado) >= (float) $params['multiples_pcc08']
                ) {
                    $requierePcc08 = true;
                }
            }
        }

        $alertas = $this->verificarListasControl($doc, $tipoDoc, $expedicion);

        // Usuario habitual (Art. 37)
        $paramsHabitual = $this->getParametrosHabitual();
        $opsHabitual = $this->getGirosPrevios($doc, $tipoDoc, $expedicion, $paramsHabitual['dias']);
        $esUsuarioHabitual = ($opsHabitual['cantidad'] + 1) >= $paramsHabitual['ops'];
        if ($esUsuarioHabitual && !$requiereFormulario) {
            $requiereFormulario = true;
        }

        // Señales de alerta (Art. 55.13)
        $senales = [];
        $umbralPcc08 = (float) $params['unica_pcc08'];
        $margenStructuring = $umbralPcc08 * 0.1;
        if ($bolivianizado >= ($umbralPcc08 - $margenStructuring) && $bolivianizado < $umbralPcc08) {
            $senales[] = [
                'codigo' => 'STRUCTURING',
                'mensaje' => 'Operación cercana al umbral PCC-08 (posible estructuración)',
                'nivel' => 'medio',
            ];
        }
        $ops7 = $this->getGirosPrevios($doc, $tipoDoc, $expedicion, 7);
        if ($ops7['cantidad'] >= 5) {
            $senales[] = [
                'codigo' => 'FREQ_SPIKE',
                'mensaje' => "Alta frecuencia: {$ops7['cantidad']} giros en 7 días",
                'nivel' => 'alto',
            ];
        }

        // Bloqueo por DD incompleta (Art. 51)
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
            'bolivianizado'        => $bolivianizado,
            'tipo_cambio_oficial'  => $tcOficial,
            'requiere_formulario'  => $requiereFormulario,
            'requiere_pcc08'       => $requierePcc08,
            'operaciones_previas'  => [
                'cantidad'           => $opsPrevPcc08['cantidad'],
                'total_bolivianizado' => $opsPrevPcc08['sumatoria'],
                'dias_ventana'       => (int) $params['dias_pcc08'],
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

    // ─── Cálculo de comisión ────────────────────────────────────────────

    public function calcularComisionGiro(
        int $idAgencia,
        int $idMoneda,
        float $monto,
        int $incluirComision = 0,
        ?int $idMonedaEntrega = null
    ): array {
        $idMonedaEntrega = $idMonedaEntrega ?: $idMoneda;
        if (!in_array($idMoneda, [1, 2], true) || !in_array($idMonedaEntrega, [1, 2], true)) {
            throw new \RuntimeException('Moneda de giro no válida.');
        }
        $ivaPct = (float) $this->getParametro('porcentaje_iva') ?: self::IVA_PORCENTAJE;
        $itPct = (float) $this->getParametro('porcentaje_it') ?: self::IT_PORCENTAJE;
        $itfPct = $this->getItfPorcentaje($idMoneda);
        $cotOficial = $this->getCotizacionOficialVenta(1);

        $comisionEntity = $this->getComisionGiro($idAgencia, $idMoneda, $monto);
        $comisionTarifa = (float) ($comisionEntity['total_comision'] ?? 0);

        // Legacy: tarifa fija si está en rango, porcentaje si rango_superior
        $comision = $comisionTarifa;
        $rangoSuperior = (float) ($comisionEntity['rango_superior'] ?? 0);
        $comisionSuperior = (float) ($comisionEntity['total_comision_superior'] ?? 0);
        if ($rangoSuperior > 0 && $monto >= $rangoSuperior && $comisionSuperior > 0) {
            $comision = ceil($monto * $comisionSuperior / 100);
        }
        $comision = round($comision, 1);

        $itf = round(($monto * $itfPct) / 100, 2);

        if ($idMoneda === 2) {
            $comisionBob = $comision;
            $itfBob = $itf;
            $montoBob = $monto;
        } else {
            $comisionBob = ceil($comision * $cotOficial);
            $itfBob = round($itf * $cotOficial, 1);
            $montoBob = round($monto * $cotOficial, 1);
        }

        $tipoCambioEntrega = $this->getTipoCambioEntregaGiro($idAgencia, $idMoneda, $idMonedaEntrega);
        $montoBeneficiario = max(0, $monto - ($incluirComision === 1 ? $comision : 0));
        $totalCambio = round($montoBeneficiario * $tipoCambioEntrega, 2);

        $ivaBob = round($comisionBob * $ivaPct / 100, 2);
        $itBob = round($comisionBob * $itPct / 100, 2);

        $total = $monto + ($incluirComision === 1 ? 0 : $comision) + $itf;
        $totalBob = $montoBob + ($incluirComision === 1 ? 0 : $comisionBob) + $itfBob;

        return [
            'comision' => $comision,
            'comision_bob' => round($comisionBob, 2),
            'itf' => $itf,
            'itf_bob' => round($itfBob, 2),
            'iva_bob' => $ivaBob,
            'it_bob' => $itBob,
            'total' => round($total, 2),
            'total_bob' => round($totalBob, 2),
            'total_cambio' => round($totalCambio, 2),
            'tipo_cambio' => $tipoCambioEntrega,
            'moneda' => $idMoneda,
            'moneda_entrega' => $idMonedaEntrega,
            'monto' => $monto,
        ];
    }

    private function getTipoCambioEntregaGiro(int $idAgencia, int $idOrigen, int $idDestino): float
    {
        if ($idOrigen === $idDestino) return 1.0;
        $cot = DB::selectOne("
            SELECT valor, valor_me FROM cotizacion
            WHERE id_agencia = :ag AND id_moneda = 1 AND id_operacion = 1 AND estado = 'A'
            ORDER BY fec_crea_cotizacion DESC LIMIT 1
        ", [':ag' => $idAgencia]);
        if (!$cot) throw new \RuntimeException('No existe cotización activa para la moneda de entrega.');
        // Mantiene el mismo criterio del selector legacy de cotización para BOB/USD.
        if ($idOrigen === 1 && $idDestino === 2) {
            $rate = (float) $cot['valor_me'];
            return $rate > 1 ? 1 / $rate : $rate;
        }
        $rate = (float) $cot['valor'];
        if ($rate <= 0) throw new \RuntimeException('La cotización de giro no es válida.');
        return 1 / $rate;
    }

    // ─── Registrar giro ────────────────────────────────────────────────

    public function registrarGiro(
        int $codUsuario,
        string $loginUsuario,
        int $idAgencia,
        array $cliente,
        array $destinatario,
        array $operacion,
        array $comisionData,
        string $ip,
        string $uuid
    ): array {
        // Validación de datos del cliente
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

        // Idempotencia
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
        $monEnt = (int) ($operacion['mon_ent'] ?? $monRec);
        $monto = (float) $operacion['monto'];
        $requierePcc08 = $this->verificarUifGiro(
            $monRec,
            $monto,
            (string) ($cliente['num_doc_identidad'] ?? ''),
            (int) ($cliente['id_tipo_doc_identidad'] ?? 1),
            (int) ($cliente['id_expedicion'] ?? 0)
        )['requiere_pcc08'];
        if ($requierePcc08) {
            foreach (['origen' => 'origen de los fondos', 'destino_pcc' => 'destino de los fondos'] as $field => $label) {
                $value = trim((string) ($operacion[$field] ?? ''));
                if (mb_strlen($value) < 20 || mb_strlen($value) > 200) {
                    return ['success' => false, 'message' => 'El ' . $label . ' debe tener entre 20 y 200 caracteres.'];
                }
            }
            if ((int) ($operacion['dinero_si'] ?? 1) === 0) {
                foreach (['nombres_dd' => 'nombre/razón social', 'doc_nit_dd' => 'documento/NIT', 'domicilio_dd' => 'domicilio', 'telefono_dd' => 'teléfono'] as $field => $label) {
                    if (trim((string) ($operacion[$field] ?? '')) === '') {
                        return ['success' => false, 'message' => 'El ' . $label . ' del tercero es obligatorio.'];
                    }
                }
                if ((int) ($operacion['select_per'] ?? 1) === 1) {
                    if (trim((string) ($operacion['apellido_paterno_dd'] ?? '')) === '' || (int) ($operacion['tipo_doc_dd'] ?? 0) <= 0) {
                        return ['success' => false, 'message' => 'El apellido paterno y el tipo de documento del beneficiario final son obligatorios.'];
                    }
                    foreach (['act_eco_dd' => 'actividad económica', 'id_departamento' => 'departamento', 'id_ciudad_pcc' => 'ciudad'] as $field => $label) {
                        if ((int) ($operacion[$field] ?? 0) <= 0) {
                            return ['success' => false, 'message' => 'Seleccione ' . $label . ' del beneficiario final.'];
                        }
                    }
                }
            }
        }
        $idAgenciaDestino = (int) $operacion['id_agencia_destino'];

        if ($idAgenciaDestino <= 0) {
            return ['success' => false, 'message' => 'La agencia destino es obligatoria.'];
        }
        if ($idAgenciaDestino === $idAgencia) {
            return ['success' => false, 'message' => 'La agencia destino no puede ser la misma agencia de origen.'];
        }

        // Validación autoritativa de comisión
        try {
            $comisionData = $this->validarComisionAutoritativa($comisionData, $idAgencia, $monRec, $monto, $operacion, $monEnt);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->isBovedaAbierta($idAgencia)) {
            return ['success' => false, 'message' => 'La bóveda no está abierta. No se pueden registrar operaciones.'];
        }
        if (!$this->tieneSesionAbierta($codUsuario)) {
            return ['success' => false, 'message' => 'No tiene ninguna caja abierta. Abra una caja antes de operar.'];
        }

        $comisionBob = (float) ($comisionData['comision_bob'] ?? 0);
        $comision = (float) ($comisionData['comision'] ?? 0);
        $itf = (float) ($comisionData['itf'] ?? 0);
        $itfBob = (float) ($comisionData['itf_bob'] ?? 0);
        $totalBob = (float) ($comisionData['total_bob'] ?? 0);
        $total = (float) ($comisionData['total'] ?? 0);
        $totalCambio = (float) ($comisionData['total_cambio'] ?? 0);
        $tipoCambio = (float) ($comisionData['tipo_cambio'] ?? 1);
        $pagoComision = (int) ($operacion['pago_comision'] ?? 0);
        $metodosPago = $operacion['metodos_pago'] ?? [];
        $complemento = is_array($operacion['complemento'] ?? null) ? $operacion['complemento'] : [];

        $origen = mb_strtoupper(trim($operacion['origen'] ?? ''));
        $destino_pcc = mb_strtoupper(trim($operacion['destino_pcc'] ?? ''));
        $dineroSi = (int) ($operacion['dinero_si'] ?? 1);
        $selectPer = (int) ($operacion['select_per'] ?? 0);
        $nombresDd = mb_strtoupper(trim($operacion['nombres_dd'] ?? ''));
        $docNitDd = mb_strtoupper(trim($operacion['doc_nit_dd'] ?? ''));
        $apellidoPaternoDd = mb_strtoupper(trim($operacion['apellido_paterno_dd'] ?? ''));
        $apellidoMaternoDd = mb_strtoupper(trim($operacion['apellido_materno_dd'] ?? ''));
        $tipoDocDd = (int) ($operacion['tipo_doc_dd'] ?? 0);
        $actEcoDd = (int) ($operacion['act_eco_dd'] ?? 0);
        $domicilioDd = mb_strtoupper(trim($operacion['domicilio_dd'] ?? ''));
        $idDepartamento = (int) ($operacion['id_departamento'] ?? 0);
        $idCiudadPcc = (int) ($operacion['id_ciudad_pcc'] ?? 0);
        $telefonoDd = trim($operacion['telefono_dd'] ?? '');
        $zonaDd = mb_strtoupper(trim($operacion['zona_dd'] ?? ''));
        $impresionPcc08 = ($operacion['impresion_pcc08'] ?? 'N') === 'S' ? 'S' : 'N';
        $impresionPcc08 = $requierePcc08 ? 'S' : 'N';
        $observacionesUif = trim($operacion['observaciones_uif'] ?? '');

        // Cuenta bancaria destino (giros a cuentas)
        $cuentaBancaria = (int) ($operacion['cuenta_bancaria'] ?? 0);

        $cajaRecibe = DB::selectOne("
            SELECT c.id_caja, c.num_caja
            FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $monRec]);

        if (!$cajaRecibe) {
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monRec]);
            $monEntAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monEnt]);
            return ['success' => false, 'message' => "No tiene caja asignada para la moneda {$monAbr}."];
        }

        $agenciaRow = DB::selectOne("SELECT cod_agencia, descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);
        $codAgencia = $agenciaRow['cod_agencia'] ?? '';
        $agenciaDesc = $agenciaRow['descripcion'] ?? '';
        $agenciaDestinoDesc = DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgenciaDestino]) ?: '';

        $fecRegOpe = date('Y-m-d H:i:s');

        return DB::transaction(function () use (
            $codUsuario, $loginUsuario, $idAgencia, $idAgenciaDestino, $cliente, $destinatario, $operacion,
            $monRec, $monEnt, $monto, $tipoCambio, $totalCambio, $pagoComision,
            $comision, $comisionBob, $total, $totalBob, $itf, $itfBob,
            $cajaRecibe, $fecRegOpe, $ip, $uuid, $codAgencia, $agenciaDesc, $agenciaDestinoDesc,
            $origen, $destino_pcc, $dineroSi, $selectPer, $nombresDd, $docNitDd,
            $apellidoPaternoDd, $apellidoMaternoDd, $tipoDocDd,
            $actEcoDd, $domicilioDd, $idDepartamento, $idCiudadPcc, $telefonoDd, $zonaDd,
            $impresionPcc08, $observacionesUif, $comisionData, $metodosPago, $cuentaBancaria, $complemento,
            $requierePcc08
        ) {
            // 1. Upsert usuario financiero
            $idUsuarioFinanciero = $this->upsertUsuarioFinanciero($cliente, $fecRegOpe);
            if ($complemento) {
                $this->registrarComplemento($idUsuarioFinanciero, $complemento, $fecRegOpe);
            }

            // 2. Upsert destinatario
            $idDestinatario = $this->upsertDestinatarioGiro($cliente, $destinatario, $fecRegOpe);

            // 3. Generar código y correlativos
            $codigo = $this->generarCodigoGiro();
            $correlativoAgencia = ((int) DB::scalar(
                "SELECT COALESCE(MAX(correlativo_agencia), 0) FROM giro WHERE id_agencia_origen = :ag",
                [':ag' => $idAgencia]
            )) + 1;
            $correlativoDestino = ((int) DB::scalar(
                "SELECT COALESCE(MAX(correlativo_destino), 0) FROM giro WHERE id_agencia_origen = :ag AND id_agencia_destino = :dest",
                [':ag' => $idAgencia, ':dest' => $idAgenciaDestino]
            )) + 1;

            // 4. Registrar cuenta bancaria giro (si aplica)
            $idCuentaBancariaGiro = null;
            if ($cuentaBancaria === 1) {
                $idEntidadFinanciera = (int) ($destinatario['id_entidad_financiera'] ?? 0);
                $numeroCuenta = trim($destinatario['numero_cuenta'] ?? '');
                if ($idEntidadFinanciera > 0 && $numeroCuenta) {
                    $existeCuenta = DB::selectOne("
                        SELECT id_cuenta_bancaria FROM cuenta_bancaria_giros
                        WHERE id_destinatario = :dest AND id_entidad_financiera = :ent AND numero_cuenta = :num
                        LIMIT 1
                    ", [':dest' => $idDestinatario, ':ent' => $idEntidadFinanciera, ':num' => $numeroCuenta]);
                    if (!$existeCuenta) {
                        DB::execute("
                            INSERT INTO cuenta_bancaria_giros (id_destinatario, id_entidad_financiera, numero_cuenta, fecha_creacion)
                            VALUES (:dest, :ent, :num, :fecha)
                        ", [':dest' => $idDestinatario, ':ent' => $idEntidadFinanciera, ':num' => $numeroCuenta, ':fecha' => $fecRegOpe]);
                    }
                    $idCuentaBancariaGiro = (int) (DB::scalar("
                        SELECT id_cuenta_bancaria FROM cuenta_bancaria_giros
                        WHERE id_destinatario = :dest AND id_entidad_financiera = :ent AND numero_cuenta = :num
                        ORDER BY id_cuenta_bancaria DESC LIMIT 1
                    ", [':dest' => $idDestinatario, ':ent' => $idEntidadFinanciera, ':num' => $numeroCuenta]) ?: 0);
                    if ($idCuentaBancariaGiro <= 0) $idCuentaBancariaGiro = null;
                }
            }

            // 5. INSERT giro
            $personaFacturaId = 0;
            if ($requierePcc08 && $dineroSi === 0 && $selectPer === 1) {
                $personaFacturaId = (int) (DB::scalar(
                    "SELECT id FROM beneficiario_final WHERE numero_documento = :doc ORDER BY id DESC LIMIT 1",
                    [':doc' => $docNitDd]
                ) ?: 0);
                if ($personaFacturaId > 0) {
                    DB::execute("UPDATE beneficiario_final SET
                        nombres = :nombres, apellido_paterno = :ape_pat, apellido_materno = :ape_mat,
                        id_tipo_doc_identidad = :tipo_doc, id_actividad_economica = :actividad,
                        id_departamento = :dpto, id_ciudad_pcc = :ciudad, zona = :zona,
                        domicilio = :domicilio, telefono = :telefono, fecha_modificacion = :fecha
                        WHERE id = :id", [
                        ':nombres' => $nombresDd, ':ape_pat' => $apellidoPaternoDd, ':ape_mat' => $apellidoMaternoDd,
                        ':tipo_doc' => $tipoDocDd, ':actividad' => $actEcoDd, ':dpto' => $idDepartamento,
                        ':ciudad' => $idCiudadPcc, ':zona' => $zonaDd, ':domicilio' => $domicilioDd,
                        ':telefono' => $telefonoDd, ':fecha' => $fecRegOpe, ':id' => $personaFacturaId,
                    ]);
                } else {
                    DB::execute("INSERT INTO beneficiario_final (
                        nombres, apellido_paterno, apellido_materno, apellido_casada,
                        id_tipo_doc_identidad, numero_documento, nit, id_actividad_economica,
                        id_departamento, id_ciudad_pcc, zona, domicilio, telefono, fecha_registro
                    ) VALUES (
                        :nombres, :ape_pat, :ape_mat, '', :tipo_doc, :doc, 0, :actividad,
                        :dpto, :ciudad, :zona, :domicilio, :telefono, :fecha
                    )", [
                        ':nombres' => $nombresDd, ':ape_pat' => $apellidoPaternoDd, ':ape_mat' => $apellidoMaternoDd,
                        ':tipo_doc' => $tipoDocDd, ':doc' => $docNitDd, ':actividad' => $actEcoDd,
                        ':dpto' => $idDepartamento, ':ciudad' => $idCiudadPcc, ':zona' => $zonaDd,
                        ':domicilio' => $domicilioDd, ':telefono' => $telefonoDd, ':fecha' => $fecRegOpe,
                    ]);
                    $personaFacturaId = DB::lastInsertId();
                }
            }
            DB::execute("
                INSERT INTO giro (
                    codigo, id_operacion, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                    id_destinatario, id_agencia_origen, id_agencia_destino, pago_comision,
                    mon_rec, monto, mon_ent, tipo_cambio, total_cambio,
                    comision, itf, total, comision_bob, total_bob, itf_bob,
                    fec_crea_giro, fec_mod_giro, facturado, cod_usuario, estado,
                    origen, destino, correlativo_agencia, correlativo_destino,
                    dinero_si, dinero_no, select_per, nombres_dd, doc_nit_dd, act_eco_dd,
                    domicilio_dd, id_departamento, id_ciudad_pcc, telefono_dd, zona_dd,
                    impresion_pcc08, usuario_financiero_id, persona_factura_id, cuenta_bancaria,
                    id_cuenta_bancaria_giro
                ) VALUES (
                    :codigo, :op, :tipo_doc, :doc, :exp,
                    :id_dest, :ag_orig, :ag_dest, :pago_com,
                    :mon_rec, :monto, :mon_ent, :tc, :total_cambio,
                    :comision, :itf, :total, :comision_bob, :total_bob, :itf_bob,
                    :fecha, NULL, 0, :usr, 0,
                    :origen, :destino, :corr_ag, :corr_dest,
                    :dinero_si, :dinero_no, :select_per, :nombres_dd, :doc_nit_dd, :act_eco_dd,
                    :domicilio_dd, :id_dpto, :id_ciudad_pcc, :telefono_dd, :zona_dd,
                    :imp_pcc08, :uf_id, :persona_factura, :cuenta_bancaria,
                    :id_cuenta_bancaria_giro
                )
            ", [
                ':codigo'      => $codigo,
                ':op'          => self::ID_OPERACION_GIRO,
                ':tipo_doc'    => (int) $cliente['id_tipo_doc_identidad'],
                ':doc'         => mb_strtoupper(trim($cliente['num_doc_identidad'])),
                ':exp'         => (int) $cliente['id_expedicion'],
                ':id_dest'     => $idDestinatario,
                ':ag_orig'     => $idAgencia,
                ':ag_dest'     => $idAgenciaDestino,
                ':pago_com'    => $pagoComision,
                ':mon_rec'     => $monRec,
                ':monto'       => $monto,
                    ':mon_ent'     => $monEnt,
                ':tc'          => $tipoCambio,
                ':total_cambio' => $totalCambio,
                ':comision'    => $comision,
                ':itf'         => $itf,
                ':total'       => $total,
                ':comision_bob' => $comisionBob,
                ':total_bob'   => $totalBob,
                ':itf_bob'     => $itfBob,
                ':fecha'       => $fecRegOpe,
                ':usr'         => $codUsuario,
                ':origen'      => $origen,
                ':destino'     => $destino_pcc,
                ':corr_ag'     => $correlativoAgencia,
                ':corr_dest'   => $correlativoDestino,
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
                ':uf_id'       => $idUsuarioFinanciero,
                ':persona_factura' => $personaFacturaId,
                ':cuenta_bancaria' => $cuentaBancaria,
                ':id_cuenta_bancaria_giro' => $idCuentaBancariaGiro,
            ]);

            $idGiro = DB::lastInsertId();

            // 6. Métodos de pago
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
                        VALUES ('giro', :ref, :fp, :monto, 'BOB',
                                :referencia, :obs,
                                :num_cuenta, :entidad, :agencia_mp, :moneda_cta,
                                :fecha)
                    ", [
                        ':ref'        => $idGiro,
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

            // 7. Flujo de caja: EG (Envío Giro)
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, :fecha, 'EG')
            ", [
                ':id'    => $cajaRecibe['id_caja'],
                ':saldo' => $monto,
                ':fecha' => $fecRegOpe,
            ]);

            // 8. ITF en BOB
            if ($itf > 0) {
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
                            VALUES (:id, :saldo, :fecha, 'IG')
                        ", [':id' => $cajaBob['id_caja'], ':saldo' => $itfEfectivo, ':fecha' => $fecRegOpe]);
                    }
                }
            }

            // 9. Comisión en BOB
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
                        VALUES (:id, :saldo, :fecha, 'OG')
                    ", [':id' => $cajaBobComision['id_caja'], ':saldo' => $comisionEfectivo, ':fecha' => $fecRegOpe]);
                }
            }

            // 9b. Porción bancaria → movimiento_cuenta_bancaria
            if ($montoBancoBob > 0 && !empty($detallesBanco)) {
                foreach ($detallesBanco as $db) {
                    $numCuenta = trim($db['num_cuenta_bancaria'] ?? '');
                    $idEntidad = (int) ($db['id_entidad_financiera'] ?? 0);
                    $idMonedaCuenta = (int) ($db['id_moneda_cuenta'] ?? 2);
                    $mpMonto = (float) ($db['monto'] ?? 0);
                    $mpRef = trim($db['referencia'] ?? '');
                    $formaPago = (int) ($db['id_forma_pago'] ?? 2);
                    $concBanco = $formaPago === 3 ? 'QC' : 'TF';
                    $glosa = "COBRO POR " . ($formaPago === 3 ? 'QR' : 'TRANSFERENCIA') . " - GIRO #{$codigo}" . ($mpRef ? " REF:{$mpRef}" : '');

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

            // 10. Log de auditoría
            DB::execute("
                INSERT INTO pos_operacion_log (
                    uuid, tipo_operacion, id_referencia, tabla_referencia,
                    id_caja, cod_usuario, id_agencia, id_moneda,
                    monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                ) VALUES (
                    :uuid, 'giro', :id_ref, 'giro',
                    :id_caja, :usr, :ag, :mon,
                    :monto, :monto_bob, 'completado', :ip, :meta, :fecha
                )
            ", [
                ':uuid'      => $uuid,
                ':id_ref'    => $idGiro,
                ':id_caja'   => $cajaRecibe['id_caja'],
                ':usr'       => $codUsuario,
                ':ag'        => $idAgencia,
                ':mon'       => $monRec,
                ':monto'     => $total,
                ':monto_bob' => $totalBob,
                ':ip'        => $ip,
                ':meta'      => json_encode(['codigo' => $codigo, 'correlativo' => $correlativoAgencia, 'agencia_destino' => $idAgenciaDestino]),
                ':fecha'     => $fecRegOpe,
            ]);

            $idOperacionLog = DB::lastInsertId();

            // 11. Facturación electrónica (sobre la comisión)
            $clienteNombre = mb_strtoupper(trim(
                ($cliente['nombres'] ?? '') . ' ' .
                ($cliente['ape_pat'] ?? '') . ' ' .
                ($cliente['ape_mat'] ?? '')
            ));

            $facturaData = $this->facturarGiro(
                $idGiro, $codigo, $idAgencia, $loginUsuario,
                $cliente, $monRec, $comisionBob, $fecRegOpe, $clienteNombre
            );

            // 12. Asiento contable
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

            $this->registrarAsientoContableGiro(
                'EC-EG', $idGiro, $codigo, $idAgencia, $idAgenciaDestino, $codAgencia,
                $agenciaDesc, $monRec, $monto, $totalCambio,
                $comisionBob, $itfBob, $fecRegOpe, $clienteNombre,
                $idOperacionLog, $montoEfectivoBob, $cuentasBancoPago
            );

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monRec]);
            $destNombre = mb_strtoupper(trim(
                ($destinatario['nombres'] ?? '') . ' ' .
                ($destinatario['ape_pat'] ?? '') . ' ' .
                ($destinatario['ape_mat'] ?? '')
            ));

            return [
                'success' => true,
                'message' => 'Giro registrado correctamente.',
                'data'    => [
                    'id_giro'              => (int) $idGiro,
                    'codigo'               => $codigo,
                    'correlativo_agencia'  => $correlativoAgencia,
                    'correlativo_destino'  => $correlativoDestino,
                    'moneda'               => $monAbr,
                    'moneda_entrega'       => $monEntAbr,
                    'mon_rec'              => $monRec,
                    'mon_ent'              => $monEnt,
                    'monto'                => $monto,
                    'comision'             => $comision,
                    'comision_bob'         => $comisionBob,
                    'itf'                  => $itf,
                    'itf_bob'              => $itfBob,
                    'total'                => $total,
                    'total_bob'            => $totalBob,
                    'total_cambio'         => $totalCambio,
                    'tipo_cambio'          => $tipoCambio,
                    'fecha'                => $fecRegOpe,
                    'agencia_origen'       => $agenciaDesc,
                    'agencia_destino'      => $agenciaDestinoDesc,
                    'cliente_nombre'       => $clienteNombre,
                    'destinatario_nombre'  => $destNombre,
                    'pago_comision'        => $pagoComision,
                    'factura'              => $facturaData,
                    'cod_agencia'          => $codAgencia,
                    'login_usuario'        => $loginUsuario,
                ],
            ];
        });
    }

    // ─── Buscar giro por código ─────────────────────────────────────────

    public function buscarGiroPorCodigo(int $codigo): ?array
    {
        $giro = DB::selectOne("
            SELECT g.*,
                   CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', COALESCE(uf.ape_mat, '')) AS remitente,
                   uf.num_doc_identidad AS remitente_doc,
                   d.nombres AS dest_nombres, d.ape_pat AS dest_ape_pat, d.ape_mat AS dest_ape_mat,
                   d.id_tipo_doc_identidad_d, d.num_doc_identidad_d, d.id_expedicion_d,
                   d.celular AS dest_celular, d.domicilio AS dest_domicilio,
                   ao.descripcion AS agencia_origen, ad.descripcion AS agencia_destino,
                    m.abreviatura AS moneda, me.abreviatura AS moneda_entrega
            FROM giro g
            LEFT JOIN usuario_financiero uf ON uf.id = g.usuario_financiero_id
            LEFT JOIN destinatario d ON d.id_destinatario = g.id_destinatario
            LEFT JOIN agencia ao ON ao.id_agencia = g.id_agencia_origen
            LEFT JOIN agencia ad ON ad.id_agencia = g.id_agencia_destino
            LEFT JOIN moneda m ON m.id_moneda = g.mon_rec
            LEFT JOIN moneda me ON me.id_moneda = g.mon_ent
            WHERE g.codigo = :codigo
            ORDER BY g.fec_crea_giro DESC
            LIMIT 1
        ", [':codigo' => $codigo]);

        if (!$giro) return null;

        $estadoMap = [0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO', 3 => 'ANULADO'];

        return [
            'id_giro'            => (int) $giro['id_giro'],
            'codigo'             => (int) $giro['codigo'],
            'estado'             => (int) $giro['estado'],
            'estadoDesc'         => $estadoMap[(int) $giro['estado']] ?? 'DESCONOCIDO',
            'monto'              => (float) $giro['monto'],
            'comision'           => (float) $giro['comision'],
            'comision_bob'       => (float) $giro['comision_bob'],
            'itf'                => (float) $giro['itf'],
            'total'              => (float) $giro['total'],
            'total_bob'          => (float) $giro['total_bob'],
            'total_cambio'       => (float) $giro['total_cambio'],
            'tipo_cambio'        => (float) $giro['tipo_cambio'],
            'moneda'             => $giro['moneda'],
            'moneda_entrega'     => $giro['moneda_entrega'] ?? $giro['moneda'],
            'mon_rec'            => (int) $giro['mon_rec'],
            'remitente'          => trim($giro['remitente']),
            'remitente_doc'      => $giro['remitente_doc'],
            'destinatario'       => trim(($giro['dest_nombres'] ?? '') . ' ' . ($giro['dest_ape_pat'] ?? '') . ' ' . ($giro['dest_ape_mat'] ?? '')),
            'destinatario_doc'   => $giro['num_doc_identidad_d'] ?? '',
            'destinatario_celular' => $giro['dest_celular'] ?? '',
            'id_agencia_origen'  => (int) $giro['id_agencia_origen'],
            'id_agencia_destino' => (int) $giro['id_agencia_destino'],
            'agencia_origen'     => $giro['agencia_origen'],
            'agencia_destino'    => $giro['agencia_destino'],
            'fecha'              => $giro['fec_crea_giro'],
            'cuenta_bancaria'    => (int) ($giro['cuenta_bancaria'] ?? 0),
        ];
    }

    // ─── Pagar giro ────────────────────────────────────────────────────

    public function pagarGiro(
        int $codUsuario,
        string $loginUsuario,
        int $idAgencia,
        int $codigo,
        array $destinatarioDoc,
        string $ip,
        string $uuid
    ): array {
        // Idempotencia
        $existente = DB::selectOne(
            "SELECT id_referencia FROM pos_operacion_log WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );
        if ($existente) {
            return ['success' => true, 'message' => 'Pago ya registrado.', 'data' => ['id_giro' => (int) $existente['id_referencia'], 'codigo' => $codigo]];
        }

        $giro = DB::selectOne("SELECT * FROM giro WHERE codigo = :c LIMIT 1", [':c' => $codigo]);
        if (!$giro) {
            return ['success' => false, 'message' => 'Giro no encontrado.'];
        }
        if ((int) $giro['estado'] === 1) {
            return ['success' => false, 'message' => 'Este giro ya fue pagado.'];
        }
        if ((int) $giro['estado'] === 2) {
            return ['success' => false, 'message' => 'Este giro fue devuelto.'];
        }
        if ((int) $giro['estado'] !== 0) {
            return ['success' => false, 'message' => 'Estado del giro no permite pago.'];
        }
        if ((int) $giro['id_agencia_destino'] !== $idAgencia) {
            return ['success' => false, 'message' => 'Este giro no corresponde a esta agencia. Destino: ' . DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $giro['id_agencia_destino']])];
        }

        if (!$this->isBovedaAbierta($idAgencia)) {
            return ['success' => false, 'message' => 'La bóveda no está abierta.'];
        }
        if (!$this->tieneSesionAbierta($codUsuario)) {
            return ['success' => false, 'message' => 'No tiene ninguna caja abierta.'];
        }

        $monto = (float) $giro['total_cambio'];
        $monRec = (int) ($giro['mon_ent'] ?? $giro['mon_rec']);
        $montoBobPago = $monRec === 2 ? $monto : round($monto * $this->getTipoCambioOficialGiro($monRec), 2);
        $fecPago = date('Y-m-d H:i:s');

        $cajaPago = DB::selectOne("
            SELECT c.id_caja FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $monRec]);

        if (!$cajaPago) {
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monRec]);
            return ['success' => false, 'message' => "No tiene caja asignada para la moneda {$monAbr}."];
        }

        return DB::transaction(function () use (
            $codUsuario, $loginUsuario, $idAgencia, $giro, $codigo, $monto, $monRec, $montoBobPago,
            $cajaPago, $fecPago, $ip, $uuid, $destinatarioDoc
        ) {
            $idGiro = (int) $giro['id_giro'];

            // Actualizar documento del destinatario si viene
            $tipoDocD = (int) ($destinatarioDoc['id_tipo_doc_identidad'] ?? 0);
            $numDocD = trim($destinatarioDoc['num_doc_identidad'] ?? '');
            $expDocD = (int) ($destinatarioDoc['id_expedicion'] ?? 0);
            if ($tipoDocD > 0 && $numDocD) {
                DB::execute("
                    UPDATE destinatario SET
                        id_tipo_doc_identidad_d = :tipo, num_doc_identidad_d = :doc, id_expedicion_d = :exp,
                        fec_mod_destinatario = :fecha
                    WHERE id_destinatario = :id
                ", [
                    ':tipo'  => $tipoDocD,
                    ':doc'   => mb_strtoupper($numDocD),
                    ':exp'   => $expDocD,
                    ':fecha' => $fecPago,
                    ':id'    => (int) $giro['id_destinatario'],
                ]);
            }

            // INSERT pago
            DB::execute("
                INSERT INTO pago (
                    id_forma_pago, id_tipo_pago, codigo, id_operacion,
                    id_agencia, cod_usuario, mon_rec, monto, tipo_cambio, total_bob,
                    fec_crea_pago, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                    comision_bob
                ) VALUES (
                    1, 1, :codigo, :op,
                    :ag, :usr, :mon, :monto, :tc, :total_bob,
                    :fecha, :tipo_doc, :doc, :exp,
                    :comision_bob
                )
            ", [
                ':codigo'      => $codigo,
                ':op'          => self::ID_OPERACION_GIRO,
                ':ag'          => $idAgencia,
                ':usr'         => $codUsuario,
                ':mon'         => $monRec,
                ':monto'       => $monto,
                ':tc'          => (float) $giro['tipo_cambio'],
                ':total_bob'   => (float) $giro['total_bob'],
                ':fecha'       => $fecPago,
                ':tipo_doc'    => $tipoDocD ?: (int) $giro['id_tipo_doc_identidad'],
                ':doc'         => $numDocD ? mb_strtoupper($numDocD) : $giro['num_doc_identidad'],
                ':exp'         => $expDocD ?: (int) $giro['id_expedicion'],
                ':comision_bob' => (float) $giro['comision_bob'],
            ]);

            // UPDATE giro estado
            DB::execute("UPDATE giro SET estado = 1, fec_mod_giro = :fecha WHERE id_giro = :id",
                [':fecha' => $fecPago, ':id' => $idGiro]);

            // Cash flow: SG (Salida Giro) - negativo
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, :fecha, 'SG')
            ", [
                ':id'    => $cajaPago['id_caja'],
                ':saldo' => -$monto,
                ':fecha' => $fecPago,
            ]);

            // Log
            DB::execute("
                INSERT INTO pos_operacion_log (
                    uuid, tipo_operacion, id_referencia, tabla_referencia,
                    id_caja, cod_usuario, id_agencia, id_moneda,
                    monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                ) VALUES (
                    :uuid, 'pago_giro', :id_ref, 'giro',
                    :id_caja, :usr, :ag, :mon,
                    :monto, :monto_bob, 'completado', :ip, :meta, :fecha
                )
            ", [
                ':uuid'      => $uuid,
                ':id_ref'    => $idGiro,
                ':id_caja'   => $cajaPago['id_caja'],
                ':usr'       => $codUsuario,
                ':ag'        => $idAgencia,
                ':mon'       => $monRec,
                ':monto'     => $monto,
                ':monto_bob' => $montoBobPago,
                ':ip'        => $ip,
                ':meta'      => json_encode(['codigo' => $codigo, 'tipo' => 'pago']),
                ':fecha'     => $fecPago,
            ]);

            $idOperacionLog = DB::lastInsertId();

            // Asiento contable pago
            $agenciaRow = DB::selectOne("SELECT cod_agencia, descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);
            $this->registrarAsientoContableGiro(
                'SC-PG', $idGiro, $codigo, (int) $giro['id_agencia_origen'], $idAgencia,
                $agenciaRow['cod_agencia'] ?? '', $agenciaRow['descripcion'] ?? '',
                $monRec, $monto, (float) $giro['total_cambio'],
                0, 0, $fecPago, '',
                $idOperacionLog, 0, []
            );

            return [
                'success' => true,
                'message' => 'Giro pagado correctamente.',
                'data'    => [
                    'id_giro' => $idGiro,
                    'codigo'  => $codigo,
                    'monto'   => $monto,
                    'moneda'  => DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monRec]),
                    'fecha_pago' => $fecPago,
                ],
            ];
        });
    }

    // ─── Devolver giro ─────────────────────────────────────────────────

    public function devolverGiro(
        int $codUsuario,
        string $loginUsuario,
        int $idAgencia,
        int $codigo,
        string $motivo,
        string $ip,
        string $uuid
    ): array {
        $existente = DB::selectOne(
            "SELECT id_referencia FROM pos_operacion_log WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );
        if ($existente) {
            return ['success' => true, 'message' => 'Devolución ya registrada.', 'data' => ['id_giro' => (int) $existente['id_referencia'], 'codigo' => $codigo]];
        }

        $giro = DB::selectOne("SELECT * FROM giro WHERE codigo = :c LIMIT 1", [':c' => $codigo]);
        if (!$giro) {
            return ['success' => false, 'message' => 'Giro no encontrado.'];
        }
        if ((int) $giro['estado'] !== 0) {
            $estadoMap = [1 => 'pagado', 2 => 'devuelto', 3 => 'anulado'];
            $desc = $estadoMap[(int) $giro['estado']] ?? 'no disponible';
            return ['success' => false, 'message' => "No se puede devolver: el giro ya está {$desc}."];
        }
        if ((int) $giro['id_agencia_origen'] !== $idAgencia) {
            return ['success' => false, 'message' => 'Solo la agencia de origen puede devolver el giro.'];
        }

        if (!$this->tieneSesionAbierta($codUsuario)) {
            return ['success' => false, 'message' => 'No tiene ninguna caja abierta.'];
        }

        $monto = (float) $giro['monto'];
        $monRec = (int) $giro['mon_rec'];
        $fecDevolucion = date('Y-m-d H:i:s');

        $cajaDev = DB::selectOne("
            SELECT c.id_caja FROM caja c
            WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.id_moneda = :mon
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            LIMIT 1
        ", [':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $monRec]);

        if (!$cajaDev) {
            return ['success' => false, 'message' => 'No tiene caja asignada para la moneda del giro.'];
        }

        return DB::transaction(function () use (
            $codUsuario, $loginUsuario, $idAgencia, $giro, $codigo, $monto, $monRec,
            $cajaDev, $fecDevolucion, $ip, $uuid, $motivo
        ) {
            $idGiro = (int) $giro['id_giro'];

            // INSERT pago (tipo 2 = devolución)
            DB::execute("
                INSERT INTO pago (
                    id_forma_pago, id_tipo_pago, codigo, id_operacion,
                    id_agencia, cod_usuario, mon_rec, monto, tipo_cambio, total_bob,
                    fec_crea_pago, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                    motivo
                ) VALUES (
                    1, 2, :codigo, :op,
                    :ag, :usr, :mon, :monto, :tc, :total_bob,
                    :fecha, :tipo_doc, :doc, :exp,
                    :motivo
                )
            ", [
                ':codigo'    => $codigo,
                ':op'        => self::ID_OPERACION_GIRO,
                ':ag'        => $idAgencia,
                ':usr'       => $codUsuario,
                ':mon'       => $monRec,
                ':monto'     => $monto,
                ':tc'        => (float) $giro['tipo_cambio'],
                ':total_bob' => (float) $giro['total_bob'],
                ':fecha'     => $fecDevolucion,
                ':tipo_doc'  => (int) $giro['id_tipo_doc_identidad'],
                ':doc'       => $giro['num_doc_identidad'],
                ':exp'       => (int) $giro['id_expedicion'],
                ':motivo'    => mb_strtoupper(trim($motivo)),
            ]);

            // UPDATE estado
            DB::execute("UPDATE giro SET estado = 2, fec_mod_giro = :fecha WHERE id_giro = :id",
                [':fecha' => $fecDevolucion, ':id' => $idGiro]);

            // Cash flow: DG (Devolución Giro) — salida
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, :fecha, 'DG')
            ", [
                ':id'    => $cajaDev['id_caja'],
                ':saldo' => -$monto,
                ':fecha' => $fecDevolucion,
            ]);

            // Log
            DB::execute("
                INSERT INTO pos_operacion_log (
                    uuid, tipo_operacion, id_referencia, tabla_referencia,
                    id_caja, cod_usuario, id_agencia, id_moneda,
                    monto_total, monto_total_bob, estado, ip_origen, metadata, created_at
                ) VALUES (
                    :uuid, 'devolucion_giro', :id_ref, 'giro',
                    :id_caja, :usr, :ag, :mon,
                    :monto, :monto_bob, 'completado', :ip, :meta, :fecha
                )
            ", [
                ':uuid'      => $uuid,
                ':id_ref'    => $idGiro,
                ':id_caja'   => $cajaDev['id_caja'],
                ':usr'       => $codUsuario,
                ':ag'        => $idAgencia,
                ':mon'       => $monRec,
                ':monto'     => $monto,
                ':monto_bob' => (float) $giro['total_cambio'],
                ':ip'        => $ip,
                ':meta'      => json_encode(['codigo' => $codigo, 'tipo' => 'devolucion', 'motivo' => $motivo]),
                ':fecha'     => $fecDevolucion,
            ]);

            $idOperacionLog = DB::lastInsertId();

            // Asiento contable devolución
            $agenciaRow = DB::selectOne("SELECT cod_agencia, descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgencia]);
            $this->registrarAsientoContableGiro(
                'SC-DG', $idGiro, $codigo, $idAgencia, (int) $giro['id_agencia_destino'],
                $agenciaRow['cod_agencia'] ?? '', $agenciaRow['descripcion'] ?? '',
                $monRec, $monto, (float) $giro['total_cambio'],
                0, 0, $fecDevolucion, '',
                $idOperacionLog, 0, []
            );

            return [
                'success' => true,
                'message' => 'Giro devuelto correctamente.',
                'data'    => [
                    'id_giro' => $idGiro,
                    'codigo'  => $codigo,
                    'monto'   => $monto,
                    'motivo'  => $motivo,
                    'fecha_devolucion' => $fecDevolucion,
                ],
            ];
        });
    }

    // ─── Giros pendientes para la agencia ───────────────────────────────

    public function girosPendientes(int $idAgencia): array
    {
        $rows = DB::select("
            SELECT g.id_giro, g.codigo, g.monto, g.total_cambio, g.comision_bob, g.total_bob,
                   g.fec_crea_giro, g.mon_rec, g.cuenta_bancaria,
                   CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', COALESCE(uf.ape_mat, '')) AS remitente,
                   CONCAT(d.nombres, ' ', d.ape_pat, ' ', COALESCE(d.ape_mat, '')) AS destinatario,
                   d.num_doc_identidad_d, d.celular AS dest_celular,
                   ao.descripcion AS agencia_origen,
                   ad.descripcion AS agencia_destino,
                   ef.descripcion AS entidad_financiera,
                   cbg.numero_cuenta AS numero_cuenta,
                   m.abreviatura AS moneda
            FROM giro g
            LEFT JOIN usuario_financiero uf ON uf.id = g.usuario_financiero_id
            LEFT JOIN destinatario d ON d.id_destinatario = g.id_destinatario
            LEFT JOIN agencia ao ON ao.id_agencia = g.id_agencia_origen
            LEFT JOIN agencia ad ON ad.id_agencia = g.id_agencia_destino
            LEFT JOIN moneda m ON m.id_moneda = g.mon_rec
            LEFT JOIN cuenta_bancaria_giros cbg ON cbg.id_cuenta_bancaria = COALESCE(
                g.id_cuenta_bancaria_giro,
                (SELECT MAX(cbg2.id_cuenta_bancaria) FROM cuenta_bancaria_giros cbg2 WHERE cbg2.id_destinatario = g.id_destinatario)
            )
            LEFT JOIN entidad_financiera ef ON ef.id_entidad_financiera = cbg.id_entidad_financiera
            WHERE g.id_agencia_destino = :ag AND g.estado = 0
            ORDER BY g.fec_crea_giro DESC
        ", [':ag' => $idAgencia]);

        return array_map(fn($r) => [
            'id'               => (int) $r['id_giro'],
            'codigo'           => (int) $r['codigo'],
            'remitente'        => trim($r['remitente']),
            'destinatario'     => trim($r['destinatario']),
            'destinatario_doc' => $r['num_doc_identidad_d'] ?? '',
            'moneda'           => $r['moneda'],
            'monto'            => round((float) $r['monto'], 2),
            'totalBob'         => round((float) $r['total_bob'], 2),
            'agencia_origen'   => $r['agencia_origen'],
            'agencia_destino'  => $r['agencia_destino'],
            'fecha'            => $r['fec_crea_giro'],
            'cuenta_bancaria'  => (int) ($r['cuenta_bancaria'] ?? 0),
            'cuentaBancaria'   => (int) ($r['cuenta_bancaria'] ?? 0),
            'formaPagoDesc'    => (int) ($r['cuenta_bancaria'] ?? 0) === 1 ? 'Depósito' : 'Efectivo',
            'entidad_financiera' => $r['entidad_financiera'] ?? null,
            'numero_cuenta'    => $r['numero_cuenta'] ?? null,
        ], $rows);
    }

    // ─── Historial de giros ─────────────────────────────────────────────

    public function historialGiros(int $codUsuario, int $idAgencia, string $desde, string $hasta, ?int $estado = null, ?string $codigo = null): array
    {
        $params = [':ag' => $idAgencia, ':desde' => $desde, ':hasta' => $hasta];
        $where = "(g.id_agencia_origen = :ag OR g.id_agencia_destino = :ag2) AND DATE(g.fec_crea_giro) BETWEEN :desde AND :hasta";
        $params[':ag2'] = $idAgencia;

        if ($estado !== null) {
            $where .= " AND g.estado = :estado";
            $params[':estado'] = $estado;
        }
        if ($codigo !== null && $codigo !== '') {
            $where .= " AND g.codigo = :codigo";
            $params[':codigo'] = $codigo;
        }

        $sql = "SELECT g.id_giro, g.codigo, g.monto, g.tipo_cambio, g.total_cambio,
                       g.comision, g.comision_bob, g.itf, g.total, g.total_bob,
                       g.fec_crea_giro, g.estado, g.correlativo_agencia, g.mon_rec,
                       g.id_agencia_origen, g.id_agencia_destino, g.cuenta_bancaria,
                       m.abreviatura AS moneda,
                       CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', COALESCE(uf.ape_mat, '')) AS remitente,
                       CONCAT(d.nombres, ' ', d.ape_pat, ' ', COALESCE(d.ape_mat, '')) AS destinatario,
                       ao.descripcion AS agencia_origen, ad.descripcion AS agencia_destino,
                       cbg.numero_cuenta AS banco_cuenta, ef.descripcion AS banco_nombre
                FROM giro g
                LEFT JOIN usuario_financiero uf ON uf.id = g.usuario_financiero_id
                LEFT JOIN destinatario d ON d.id_destinatario = g.id_destinatario
                LEFT JOIN moneda m ON m.id_moneda = g.mon_rec
                LEFT JOIN agencia ao ON ao.id_agencia = g.id_agencia_origen
                LEFT JOIN agencia ad ON ad.id_agencia = g.id_agencia_destino
                LEFT JOIN cuenta_bancaria_giros cbg ON cbg.id_destinatario = g.id_destinatario
                LEFT JOIN entidad_financiera ef ON ef.id_entidad_financiera = cbg.id_entidad_financiera
                WHERE {$where}
                ORDER BY g.fec_crea_giro DESC";

        $rows = DB::select($sql, $params);
        $estadoMap = [0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO', 3 => 'ANULADO'];

        return array_map(fn($r) => [
            'id'               => (int) $r['id_giro'],
            'codigo'           => (int) $r['codigo'],
            'correlativo'      => $r['correlativo_agencia'],
            'remitente'        => trim($r['remitente']),
            'destinatario'     => trim($r['destinatario']),
            'agencia_origen'   => $r['agencia_origen'],
            'agencia_destino'  => $r['agencia_destino'],
            'moneda'           => $r['moneda'],
            'monto'            => round((float) $r['monto'], 2),
            'comision'         => round((float) $r['comision'], 2),
            'comisionBob'      => round((float) $r['comision_bob'], 2),
            'total'            => round((float) $r['total'], 2),
            'totalBob'         => round((float) $r['total_bob'], 2),
            'fecha'            => $r['fec_crea_giro'],
            'estado'           => (int) $r['estado'],
            'estadoDesc'       => $estadoMap[(int) $r['estado']] ?? 'DESCONOCIDO',
            'cuentaBancaria'   => (int) ($r['cuenta_bancaria'] ?? 0),
            'formaPagoDesc'    => (int) ($r['cuenta_bancaria'] ?? 0) === 1 ? 'Depósito' : 'Efectivo',
            'bancoCuenta'      => $r['banco_cuenta'] ?? null,
            'bancoNombre'      => $r['banco_nombre'] ?? null,
        ], $rows);
    }

    /** Persiste el formulario Complemento UIF dentro de la misma transacción del giro. */
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

    // ─── Comprobante ────────────────────────────────────────────────────

    public function obtenerComprobante(int $idGiro): ?array
    {
        return DB::selectOne("
            SELECT g.*,
                uf.nombres, uf.ape_pat, uf.ape_mat,
                d.nombres AS dest_nombres, d.ape_pat AS dest_ape_pat, d.ape_mat AS dest_ape_mat,
                m.abreviatura AS moneda,
                ao.descripcion AS agencia_origen,
                ad.descripcion AS agencia_destino
            FROM giro g
            JOIN usuario_financiero uf ON uf.id = g.usuario_financiero_id
            JOIN destinatario d ON d.id_destinatario = g.id_destinatario
            JOIN moneda m ON m.id_moneda = g.mon_rec
            JOIN agencia ao ON ao.id_agencia = g.id_agencia_origen
            LEFT JOIN agencia ad ON ad.id_agencia = g.id_agencia_destino
            WHERE g.id_giro = :id
        ", [':id' => $idGiro]);
    }

    // ─── Asiento contable giro ──────────────────────────────────────────

    private function registrarAsientoContableGiro(
        string $tipoDocumento,
        int $idGiro, int $codigo, int $idAgenciaOrigen, int $idAgenciaDestino,
        string $codAgencia, string $agenciaDesc,
        int $monRec, float $monto, float $totalCambio,
        float $comisionBob, float $itfBob,
        string $fecha, string $clienteNombre,
        int $idOperacionLog,
        float $montoEfectivoBob = 0.0, array $cuentasBancoPago = []
    ): void {
        $codejercicio = DB::scalar(
            "SELECT codejercicio FROM ejercicios WHERE estado = 'ABIERTO' LIMIT 1",
            [], 'conta'
        );
        if (!$codejercicio) {
            $this->encolarAsientoPendiente($idOperacionLog, $fecha, 'No hay ejercicio contable abierto');
            return;
        }

        $oficial = (float) DB::scalar("
            SELECT co.valor_me FROM cotizacion_oficial co
            WHERE co.id_moneda = 1 AND co.id_operacion = 1
            ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1
        ") ?: 1.0;

        $agOrigenDesc = DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgenciaOrigen]) ?: '';
        $agDestinoDesc = DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgenciaDestino]) ?: '';

        try {
            $fechaAsiento = substr($fecha, 0, 10);
            $sigue = (int) DB::scalar(
                "SELECT COALESCE(MAX(numero), 0) FROM co_asientos WHERE codejercicio = :ej",
                [':ej' => $codejercicio], 'conta'
            ) + 1;

            $subcuentaGiro = $monRec === 1 ? self::SUBCUENTA_GIRO_POR_PAGAR_USD : self::SUBCUENTA_GIRO_POR_PAGAR_BOB;
            $subcuentaCaja = $monRec === 1 ? self::SUBCUENTA_CAJA_USD : self::SUBCUENTA_CAJA_BOB;

            if ($tipoDocumento === 'EC-EG') {
                // Envío de giro
                $montoBob = $monRec === 2 ? $monto : round($monto * $oficial, 2);
                $montoMe = $monRec === 2 ? round($monto / $oficial, 2) : $monto;

                $glosa = mb_strtoupper("{$clienteNombre} - {$agenciaDesc} - ENVIO DE GIRO NACIONAL #{$codigo} - {$agOrigenDesc} A {$agDestinoDesc}");

                DB::execute("
                    INSERT INTO co_asientos (
                        codejercicio, codplanasiento, concepto, documento, editable,
                        fecha, idasiento, idconcepto, importe, numero, tipodocumento,
                        cod_usuario, fecha_trans, origen
                    ) VALUES (
                        :ej, NULL, :concepto, '', 0,
                        :fecha, '', :idconcepto, 0, :numero, 'EC-EG',
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
                $this->insertPartida($idasiento, $codejercicio, $subcuentaCaja, $codAgencia, $codigo,
                    $montoBob, $montoMe, 0, 0, $oficial);

                // P2: Giro por pagar (HABER)
                $this->insertPartida($idasiento, $codejercicio, $subcuentaGiro, $codAgencia, $codigo,
                    0, 0, $montoBob, $montoMe, $oficial);

                // P3: Comisión giro (HABER) — neta de IVA (87%)
                if ($comisionBob > 0) {
                    $comisionNeta = round($comisionBob * 0.87, 2);
                    $comisionNetaMe = round($comisionNeta / $oficial, 2);
                    $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_COMI_GIRO, $codAgencia, $codigo,
                        0, 0, $comisionNeta, $comisionNetaMe, $oficial);

                    // P4: IVA (HABER)
                    $ivaBob = round($comisionBob * 0.13, 2);
                    $ivaMe = round($ivaBob / $oficial, 2);
                    $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_DEBITO_FISCAL_IVA, $codAgencia, $codigo,
                        0, 0, $ivaBob, $ivaMe, $oficial);

                    // P5: IT (DEBE)
                    $itBob = round($comisionBob * 0.03, 2);
                    $itMe = round($itBob / $oficial, 2);
                    $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_TRANSACCION, $codAgencia, $codigo,
                        $itBob, $itMe, 0, 0, $oficial);

                    // P6: IT por pagar (HABER)
                    $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_IMPUESTO_TRANSACCIONES, $codAgencia, $codigo,
                        0, 0, $itBob, $itMe, $oficial);

                    // P7: Entrada caja comisión+ITF (DEBE)
                    $debeCom = round($comisionBob + $itfBob, 2);
                    $debeComMe = round($debeCom / $oficial, 2);

                    if ($montoEfectivoBob > 0 && !empty($cuentasBancoPago)) {
                        $totalPago = $montoEfectivoBob;
                        foreach ($cuentasBancoPago as $cbp) $totalPago += $cbp['monto'];
                        $ratioEf = $totalPago > 0 ? $montoEfectivoBob / $totalPago : 1.0;

                        $debeEf = round($debeCom * $ratioEf, 2);
                        if ($debeEf > 0) {
                            $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_ENTRADA_CAJA_COMISION, $codAgencia, $codigo,
                                $debeEf, round($debeEf / $oficial, 2), 0, 0, $oficial);
                        }
                        $acum = $debeEf;
                        foreach ($cuentasBancoPago as $idx => $cbp) {
                            $debeBanco = $idx === count($cuentasBancoPago) - 1
                                ? round($debeCom - $acum, 2)
                                : round($debeCom * ($cbp['monto'] / $totalPago), 2);
                            $acum += $debeBanco;
                            if ($debeBanco > 0) {
                                $this->insertPartida($idasiento, $codejercicio, $cbp['codsubcuenta'], $codAgencia, $codigo,
                                    $debeBanco, round($debeBanco / $oficial, 2), 0, 0, $oficial);
                            }
                        }
                    } else {
                        $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_ENTRADA_CAJA_COMISION, $codAgencia, $codigo,
                            $debeCom, $debeComMe, 0, 0, $oficial);
                    }

                    // P8: Retención ITF (HABER)
                    if ($itfBob > 0) {
                        $itfMe = round($itfBob / $oficial, 2);
                        $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_RETENCION_ITF, $codAgencia, $codigo,
                            0, 0, $itfBob, $itfMe, $oficial);
                    }
                }

                // P9: Interoficinas (DEBE destino, HABER origen)
                $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_INTEROFICINAS, $codAgencia, $codigo,
                    $montoBob, $montoMe, 0, 0, $oficial);
                $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_INTEROFICINAS, $codAgencia, $codigo,
                    0, 0, $montoBob, $montoMe, $oficial);

                DB::execute("
                    UPDATE co_asientos SET importe = (
                        SELECT COALESCE(SUM(debe), 0) FROM co_partidas WHERE idasiento = :id
                    ) WHERE idasiento = :id2
                ", [':id' => $idasiento, ':id2' => $idasiento], 'conta');

            } elseif ($tipoDocumento === 'SC-PG') {
                // Pago de giro
                $montoBob = $monRec === 2 ? $monto : round($monto * $oficial, 2);
                $montoMe = $monRec === 2 ? round($monto / $oficial, 2) : $monto;

                $glosa = mb_strtoupper("PAGO DE GIRO NACIONAL #{$codigo} - {$agDestinoDesc}");

                DB::execute("
                    INSERT INTO co_asientos (
                        codejercicio, codplanasiento, concepto, documento, editable,
                        fecha, idasiento, idconcepto, importe, numero, tipodocumento,
                        cod_usuario, fecha_trans, origen
                    ) VALUES (
                        :ej, NULL, :concepto, '', 0,
                        :fecha, '', :idconcepto, 0, :numero, 'SC-PG',
                        'CGR', :fecha_t, 'POS'
                    )
                ", [
                    ':ej' => $codejercicio, ':concepto' => $glosa, ':fecha' => $fechaAsiento,
                    ':idconcepto' => $codAgencia, ':numero' => $sigue, ':fecha_t' => $fecha,
                ], 'conta');

                $idasiento = DB::lastInsertId('conta');

                // Giro por pagar (DEBE)
                $this->insertPartida($idasiento, $codejercicio, $subcuentaGiro, $codAgencia, $codigo,
                    $montoBob, $montoMe, 0, 0, $oficial);
                // Salida caja (HABER)
                $this->insertPartida($idasiento, $codejercicio, $subcuentaCaja, $codAgencia, $codigo,
                    0, 0, $montoBob, $montoMe, $oficial);

                DB::execute("
                    UPDATE co_asientos SET importe = (
                        SELECT COALESCE(SUM(debe), 0) FROM co_partidas WHERE idasiento = :id
                    ) WHERE idasiento = :id2
                ", [':id' => $idasiento, ':id2' => $idasiento], 'conta');

            } elseif ($tipoDocumento === 'SC-DG') {
                // Devolución de giro
                $montoBob = $monRec === 2 ? $monto : round($monto * $oficial, 2);
                $montoMe = $monRec === 2 ? round($monto / $oficial, 2) : $monto;

                $glosa = mb_strtoupper("DEVOLUCION DE GIRO NACIONAL #{$codigo} - {$agOrigenDesc}");

                DB::execute("
                    INSERT INTO co_asientos (
                        codejercicio, codplanasiento, concepto, documento, editable,
                        fecha, idasiento, idconcepto, importe, numero, tipodocumento,
                        cod_usuario, fecha_trans, origen
                    ) VALUES (
                        :ej, NULL, :concepto, '', 0,
                        :fecha, '', :idconcepto, 0, :numero, 'SC-DG',
                        'CGR', :fecha_t, 'POS'
                    )
                ", [
                    ':ej' => $codejercicio, ':concepto' => $glosa, ':fecha' => $fechaAsiento,
                    ':idconcepto' => $codAgencia, ':numero' => $sigue, ':fecha_t' => $fecha,
                ], 'conta');

                $idasiento = DB::lastInsertId('conta');

                // Giro por pagar (DEBE)
                $this->insertPartida($idasiento, $codejercicio, $subcuentaGiro, $codAgencia, $codigo,
                    $montoBob, $montoMe, 0, 0, $oficial);
                // Salida caja (HABER)
                $this->insertPartida($idasiento, $codejercicio, $subcuentaCaja, $codAgencia, $codigo,
                    0, 0, $montoBob, $montoMe, $oficial);

                // Interoficinas compensación
                $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_INTEROFICINAS, $codAgencia, $codigo,
                    $montoBob, $montoMe, 0, 0, $oficial);
                $this->insertPartida($idasiento, $codejercicio, self::SUBCUENTA_INTEROFICINAS, $codAgencia, $codigo,
                    0, 0, $montoBob, $montoMe, $oficial);

                DB::execute("
                    UPDATE co_asientos SET importe = (
                        SELECT COALESCE(SUM(debe), 0) FROM co_partidas WHERE idasiento = :id
                    ) WHERE idasiento = :id2
                ", [':id' => $idasiento, ':id2' => $idasiento], 'conta');
            }

        } catch (\Throwable $e) {
            $this->encolarAsientoPendiente($idOperacionLog, $fecha, $e->getMessage());
        }
    }

    // ─── Facturación giro ──────────────────────────────────────────────

    private function facturarGiro(
        int $idGiro, int $codigo, int $idAgencia,
        string $loginUsuario, array $cliente, int $monRec,
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
                $idGiro,
                self::ID_OPERACION_GIRO,
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

    // ─── Validación autoritativa de comisión ─────────────────────────────

    private function validarComisionAutoritativa(
        array $comisionData,
        int $idAgencia,
        int $idMoneda,
        float $monto,
        array $operacion,
        ?int $idMonedaEntrega = null
    ): array {
        if (empty($comisionData)) {
            throw new \RuntimeException('Falta el detalle de comisión de la operación.');
        }

        $incluirComision = (int) ($operacion['pago_comision'] ?? 0) === 1 ? 1 : 0;

        $calculado = $this->calcularComisionGiro($idAgencia, $idMoneda, $monto, $incluirComision, $idMonedaEntrega);

        foreach (['comision_bob', 'total_bob', 'total_cambio'] as $clave) {
            $calc = (float) ($calculado[$clave] ?? 0);
            $decl = (float) ($comisionData[$clave] ?? 0);

            $tolerancia = max(0.50, abs($calc) * 0.01);
            if (abs($calc - $decl) > $tolerancia) {
                $etiqueta = $clave === 'comision_bob' ? 'comisión' : ($clave === 'total_cambio' ? 'monto a entregar' : 'total');
                throw new \RuntimeException(
                    "La {$etiqueta} no coincide con la calculada (declarada: "
                    . number_format($decl, 2)
                    . ', calculada: ' . number_format($calc, 2) . ').'
                );
            }
        }

        return $calculado;
    }

    // ─── Helpers privados ──────────────────────────────────────────────

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

    private function getIdSubcuenta(string $codsubcuenta, string $codejercicio): ?int
    {
        $val = DB::scalar(
            "SELECT idsubcuenta FROM co_subcuentas WHERE codsubcuenta = :cod AND codejercicio = :ej",
            [':cod' => $codsubcuenta, ':ej' => $codejercicio], 'conta'
        );
        return $val ? (int) $val : null;
    }

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

    private function upsertDestinatarioGiro(array $cliente, array $d, string $fecha): int
    {
        $docD = mb_strtoupper(trim($d['num_doc_identidad_d'] ?? ''));
        $tipoD = (int) ($d['id_tipo_doc_identidad_d'] ?? 1);
        $expD = (int) ($d['id_expedicion_d'] ?? 0);

        if ($docD) {
            $existing = DB::selectOne("
                SELECT id_destinatario FROM destinatario
                WHERE id_tipo_doc_identidad_d = :tipo AND num_doc_identidad_d = :doc AND id_expedicion_d = :exp
                LIMIT 1
            ", [':doc' => $docD, ':tipo' => $tipoD, ':exp' => $expD]);

            if ($existing) {
                DB::execute("
                    UPDATE destinatario SET
                        nombres = :nom, ape_pat = :ap, ape_mat = :am,
                        domicilio = :dom, tel_dom = :td, tel_trab = :tt,
                        celular = :cel, fec_mod_destinatario = :fecha
                    WHERE id_destinatario = :id
                ", [
                    ':nom'   => mb_strtoupper(trim($d['nombres'] ?? '')),
                    ':ap'    => mb_strtoupper(trim($d['ape_pat'] ?? '')),
                    ':am'    => mb_strtoupper(trim($d['ape_mat'] ?? '')),
                    ':dom'   => mb_strtoupper(trim($d['domicilio'] ?? '')),
                    ':td'    => $d['tel_dom'] ?? '',
                    ':tt'    => $d['tel_trab'] ?? '',
                    ':cel'   => $d['celular'] ?? '',
                    ':fecha' => $fecha,
                    ':id'    => (int) $existing['id_destinatario'],
                ]);
                return (int) $existing['id_destinatario'];
            }
        }

        DB::execute("
            INSERT INTO destinatario (
                id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                nombres, ape_pat, ape_mat,
                id_tipo_doc_identidad_d, num_doc_identidad_d, id_expedicion_d,
                domicilio, tel_dom, tel_trab, celular,
                fec_crea_destinatario, fec_mod_destinatario
            ) VALUES (
                :tipo_rem, :doc_rem, :exp_rem,
                :nom, :ap, :am,
                :tipo_d, :doc_d, :exp_d,
                :dom, :td, :tt, :cel,
                :fecha, NULL
            )
        ", [
            ':tipo_rem' => (int) $cliente['id_tipo_doc_identidad'],
            ':doc_rem'  => mb_strtoupper(trim($cliente['num_doc_identidad'])),
            ':exp_rem'  => (int) $cliente['id_expedicion'],
            ':nom'      => mb_strtoupper(trim($d['nombres'] ?? '')),
            ':ap'       => mb_strtoupper(trim($d['ape_pat'] ?? '')),
            ':am'       => mb_strtoupper(trim($d['ape_mat'] ?? '')),
            ':tipo_d'   => $tipoD,
            ':doc_d'    => $docD,
            ':exp_d'    => $expD,
            ':dom'      => mb_strtoupper(trim($d['domicilio'] ?? '')),
            ':td'       => $d['tel_dom'] ?? '',
            ':tt'       => $d['tel_trab'] ?? '',
            ':cel'      => $d['celular'] ?? '',
            ':fecha'    => $fecha,
        ]);

        return (int) DB::lastInsertId();
    }

    private function generarCodigoGiro(): int
    {
        for ($i = 0; $i < 50; $i++) {
            $codigo = rand(1, 99999);
            $exists = DB::scalar("SELECT COUNT(*) FROM giro WHERE codigo = :c", [':c' => $codigo]);
            if ((int) $exists === 0) return $codigo;
        }
        return rand(100000, 999999);
    }

    private function getComisionGiro(int $idAgencia, int $idMoneda, float $monto): array
    {
        return DB::selectOne("
            SELECT total_comision, rango_superior, total_comision_superior
            FROM comision
            WHERE id_agencia = :ag AND id_moneda = :mon
              AND id_operacion = :op AND estado = 'A'
              AND :monto BETWEEN rango_inicial AND rango_final
            LIMIT 1
        ", [
            ':ag'    => $idAgencia,
            ':mon'   => $idMoneda,
            ':op'    => self::ID_OPERACION_GIRO,
            ':monto' => $monto,
        ]) ?: ['total_comision' => 0, 'rango_superior' => 0, 'total_comision_superior' => 0];
    }

    private function getParametrosUifGiro(): array
    {
        return [
            'unica_formulario'    => $this->getParametro(self::PARAM_FORMULARIO_UNICA) ?: self::DEFAULT_FORMULARIO_UNICA,
            'multiples_formulario' => $this->getParametro(self::PARAM_FORMULARIO_MULTIPLES) ?: self::DEFAULT_FORMULARIO_MULTIPLES,
            'dias_formulario'     => $this->getParametro(self::PARAM_FORMULARIO_DIAS) ?: self::DEFAULT_FORMULARIO_DIAS,
            'num_ops_formulario'  => $this->getParametro(self::PARAM_FORMULARIO_NUM_OPS) ?: self::DEFAULT_FORMULARIO_NUM_OPS,
            'unica_pcc08'         => $this->getParametro(self::PARAM_PCC08_UNICA) ?: self::DEFAULT_PCC08_UNICA,
            'multiples_pcc08'     => $this->getParametro(self::PARAM_PCC08_MULTIPLES) ?: self::DEFAULT_PCC08_MULTIPLES,
            'dias_pcc08'          => $this->getParametro(self::PARAM_PCC08_DIAS) ?: self::DEFAULT_PCC08_DIAS,
            'num_ops_pcc08'       => $this->getParametro(self::PARAM_PCC08_NUM_OPS) ?: self::DEFAULT_PCC08_NUM_OPS,
        ];
    }

    private function getParametrosHabitual(): array
    {
        return [
            'ops'  => (int) ($this->getParametro('usuario_habitual_ops') ?: 3),
            'dias' => (int) ($this->getParametro('usuario_habitual_dias') ?: 30),
        ];
    }

    private function getTipoCambioOficialGiro(int $idMoneda): float
    {
        if ($idMoneda === 2) return 1.0;
        return $this->getCotizacionOficialVenta(1);
    }

    private function getGirosPrevios(string $doc, int $tipoDoc, int $expedicion, int $dias): array
    {
        $row = DB::selectOne("
            SELECT COUNT(*) AS cantidad, COALESCE(SUM(CASE WHEN g.mon_rec != 2 THEN g.monto * co.valor_me ELSE g.monto END), 0) AS sumatoria
            FROM giro g
            LEFT JOIN (
                SELECT valor_me FROM cotizacion_oficial WHERE id_moneda = 1 AND id_operacion = 1
                ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1
            ) co ON 1=1
            WHERE g.num_doc_identidad = :doc
              AND g.id_tipo_doc_identidad = :tipo
              AND g.id_expedicion = :exp
              AND g.fec_crea_giro >= DATE_SUB(NOW(), INTERVAL :dias DAY)
              AND g.estado <= 3
        ", [':doc' => $doc, ':tipo' => $tipoDoc, ':exp' => $expedicion, ':dias' => $dias]);

        return [
            'cantidad'   => (int) ($row['cantidad'] ?? 0),
            'sumatoria'  => round((float) ($row['sumatoria'] ?? 0), 2),
        ];
    }

    private function hayPcc08EnPeriodo(string $doc, int $tipoDoc, int $expedicion, int $dias): bool
    {
        return (int) DB::scalar("
            SELECT COUNT(*) FROM giro
            WHERE num_doc_identidad = :doc
              AND id_tipo_doc_identidad = :tipo
              AND id_expedicion = :exp
              AND impresion_pcc08 = 'S'
              AND fec_crea_giro >= DATE_SUB(NOW(), INTERVAL :dias DAY)
        ", [':doc' => $doc, ':tipo' => $tipoDoc, ':exp' => $expedicion, ':dias' => $dias]) > 0;
    }

    private function sumaUltimosGiros(string $doc, int $tipoDoc, int $expedicion, int $dias): float
    {
        return (float) DB::scalar("
            SELECT COALESCE(SUM(CASE WHEN g.mon_rec != 2 THEN g.monto * co.valor_me ELSE g.monto END), 0)
            FROM giro g
            LEFT JOIN (
                SELECT valor_me FROM cotizacion_oficial WHERE id_moneda = 1 AND id_operacion = 1
                ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1
            ) co ON 1=1
            WHERE g.num_doc_identidad = :doc
              AND g.id_tipo_doc_identidad = :tipo
              AND g.id_expedicion = :exp
              AND g.impresion_pcc08 = 'S'
              AND g.fec_crea_giro >= DATE_SUB(NOW(), INTERVAL :dias DAY)
        ", [':doc' => $doc, ':tipo' => $tipoDoc, ':exp' => $expedicion, ':dias' => $dias]) ?: 0;
    }

    private function buscarUsuarioFinanciero(string $doc, int $tipoDoc, int $expedicion): ?array
    {
        return DB::selectOne("
            SELECT id, nivel_impresion_formulario FROM usuario_financiero
            WHERE num_doc_identidad = :doc AND id_tipo_doc_identidad = :tipo AND id_expedicion = :exp
            LIMIT 1
        ", [':doc' => $doc, ':tipo' => $tipoDoc, ':exp' => $expedicion]);
    }

    private function verificarListasControl(string $doc, int $tipoDoc, int $expedicion): array
    {
        $alertas = [];

        $pep = DB::selectOne("
            SELECT p.num_doc_identidad, p.nombres, p.ape_pat, p.cargo, p.entidad
            FROM pep p WHERE p.num_doc_identidad = :doc AND p.estado = 'A' LIMIT 1
        ", [':doc' => $doc]);

        if ($pep) {
            $alertas[] = [
                'tipo'  => 'PEP', 'nivel' => 'alto', 'mensaje' => 'Persona Políticamente Expuesta',
                'detalle' => trim(($pep['cargo'] ?? '') . ' - ' . ($pep['entidad'] ?? ''), ' -'),
            ];
        }

        $riesgo = DB::selectOne("
            SELECT pr.numero_documento, pr.cargo, pr.justificacion
            FROM personas_riesgos pr WHERE pr.numero_documento = :doc LIMIT 1
        ", [':doc' => $doc]);

        if ($riesgo) {
            $alertas[] = [
                'tipo'  => 'LISTA_RIESGO', 'nivel' => 'critico', 'mensaje' => 'Persona en lista de riesgo/control',
                'detalle' => trim(($riesgo['cargo'] ?? '') . ' — ' . ($riesgo['justificacion'] ?? ''), ' —'),
            ];
        }

        $ufPep = DB::selectOne("
            SELECT pep, stipo_pep FROM usuario_financiero
            WHERE num_doc_identidad = :doc AND id_tipo_doc_identidad = :tipo AND id_expedicion = :exp AND pep = 'S'
            LIMIT 1
        ", [':doc' => $doc, ':tipo' => $tipoDoc, ':exp' => $expedicion]);

        if ($ufPep) {
            $subtipo = $ufPep['stipo_pep'] ?? '';
            $label = $subtipo === 'NAL' ? 'Nacional' : ($subtipo === 'EXT' ? 'Extranjero' : $subtipo);
            $alertas[] = [
                'tipo'  => 'PEP_REGISTRADO', 'nivel' => 'alto',
                'mensaje' => "PEP registrado en sistema ({$label})", 'detalle' => '',
            ];
        }

        return $alertas;
    }

    private function getCotizacionOficialVenta(int $idMoneda): float
    {
        return (float) DB::scalar("
            SELECT valor FROM cotizacion_oficial
            WHERE id_moneda = :mon AND id_operacion = 2
              AND DATE(fec_crea_cotizacion_oficial) <= CURDATE()
            ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1
        ", [':mon' => $idMoneda]) ?: 1.0;
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

    private function tieneSesionAbierta(int $codUsuario): bool
    {
        return (int) DB::scalar(
            "SELECT COUNT(*) FROM pos_sesion_caja WHERE cod_usuario = :usr AND fecha = CURDATE() AND estado = 'abierta'",
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

    private function encolarAsientoPendiente(int $idOperacionLog, string $fecha, string $error): void
    {
        DB::execute("
            INSERT INTO pos_asiento_pendiente (
                id_operacion_log, tipo_operacion, codejercicio, fecha,
                estado, error_mensaje, created_at
            ) VALUES (
                :log, 'giro', '', :fecha,
                'pendiente', :error, :fecha2
            )
        ", [
            ':log'    => $idOperacionLog,
            ':fecha'  => substr($fecha, 0, 10),
            ':error'  => $error,
            ':fecha2' => $fecha,
        ]);
    }
}
