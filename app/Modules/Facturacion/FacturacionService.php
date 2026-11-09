<?php

namespace App\Modules\Facturacion;

use App\Modules\Shared\Database\RawConnection as DB;

class FacturacionService
{
    private const NIT_EMPRESA       = 335846029;
    private const RAZON_SOCIAL      = 'GIROS Y REMESAS DE DINERO GAMBARTE BOLIVIA S.R.L.';
    private const CODIGO_AMBIENTE   = 2; // 1=produccion, 2=piloto
    private const CODIGO_MODALIDAD  = 1; // electronica en linea
    private const CODIGO_SISTEMA    = '77561E848953107B25A2FFE';
    private const TIPO_FACTURA_DOC  = 2; // sin derecho a credito fiscal
    private const CODIGO_MONEDA_BOB = 1;
    private const METODO_PAGO_EFECTIVO = 1;
    private const UNIDAD_MEDIDA_SERVICIO = 58;
    private const ACTIVIDAD_CVME    = 9;
    public const CODIGO_SERVICIO_VENTA  = 'V-ME-1';
    public const CODIGO_SERVICIO_COMPRA = 'C-ME-2';
    public const CODIGO_SERVICIO_REMESA = 'AAIF-2';
    private const TIPO_OPERACION_SIN_VENTA  = 1;
    private const TIPO_OPERACION_SIN_COMPRA = 2;

    private const DOC_CGR_NIT = 4;

    private SiatClient $siat;

    public function __construct()
    {
        $this->siat = new SiatClient();
    }

    /**
     * Facturar un cambio ya insertado. Se llama DENTRO de DB::transaction.
     * Retorna array con datos de la factura para el recibo, o lanza excepción si falla críticamente.
     */
    public function facturarCambio(
        int $idCambio,
        int $idOperacion,
        int $idAgencia,
        string $loginUsuario,
        array $cambioData,
        array $clienteFacturacion,
        string $fecRegOpe,
        ?string $codigoServicioOverride = null
    ): array {
        // 1. Config punto de venta
        $puntoVenta = $this->getConfigPuntoVenta($idAgencia, $loginUsuario);
        if (!$puntoVenta) {
            throw new \RuntimeException('No se encontró configuración de punto de venta para facturación.');
        }

        // 2. Número de factura
        $facturacion = $this->getFacturacionAgencia($idAgencia);
        if (!$facturacion) {
            throw new \RuntimeException('No se encontró configuración de facturación para la agencia.');
        }
        $numeroFactura = (int) $facturacion['factura'];

        // 3. Tipo documento sector
        $tipoDocSector = DB::selectOne(
            "SELECT codigo_documento_sector, id_actividad FROM fe_tipo_documento_sector WHERE codigo_documento_sector = :cod",
            [':cod' => self::ACTIVIDAD_CVME]
        );
        $codigoDocumentoSector = (int) ($tipoDocSector['codigo_documento_sector'] ?? self::ACTIVIDAD_CVME);

        // 4. Leyenda
        $leyenda = DB::selectOne("SELECT id_leyenda, descripcion FROM fe_leyenda ORDER BY RAND() LIMIT 1");
        $leyendaTexto = $leyenda['descripcion'] ?? 'Esta factura contribuye al desarrollo del país.';
        $idLeyenda = (int) ($leyenda['id_leyenda'] ?? 0);

        // 5. Servicio (código CAEB, catálogo)
        $codigoServicio = $codigoServicioOverride
            ?? ($idOperacion === 1 ? self::CODIGO_SERVICIO_COMPRA : self::CODIGO_SERVICIO_VENTA);
        $servicio = DB::selectOne("
            SELECT fs.codigo, fs.nombre, fc.codigo_catalogo, fa.codigo_caeb
            FROM fe_servicio fs
            INNER JOIN fe_categoria fc ON fs.id_categoria = fc.id_categoria
            INNER JOIN fe_actividad fa ON fa.id_actividad = fc.id_actividad
            WHERE fs.codigo = :cod
            ORDER BY fs.updated_at DESC LIMIT 1
        ", [':cod' => $codigoServicio]);

        // 6. Cotización oficial para el cálculo
        $monDivisa = $idOperacion === 1 ? (int) $cambioData['mon_rec'] : (int) $cambioData['mon_ent'];
        $idOpOficial = 1;
        if ($idOperacion === 2 && $monDivisa === 1) {
            $idOpOficial = 2;
        }
        $oficial = (float) DB::scalar("
            SELECT co.valor FROM cotizacion_oficial co
            WHERE co.id_moneda = :mon AND co.id_operacion = :op
            ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1
        ", [':mon' => $monDivisa, ':op' => $idOpOficial]) ?: 1.0;

        // 7. Datos de la factura
        $cantidadMoneda = $idOperacion === 1
            ? (float) $cambioData['recibido']
            : (float) $cambioData['entregado'];
        $tipoCambio = (float) $cambioData['tipo_cambio'];
        $montoTotalRaw = $cantidadMoneda * $tipoCambio;
        $montoTotal = round($montoTotalRaw, 2);
        $diferenciaCambio = round(abs($tipoCambio - $oficial), 2);
        $montoTotalMoneda = round($montoTotal / $tipoCambio, 2);

        // 8. Datos del cliente para facturación
        $nombreCliente = $clienteFacturacion['nombre'];
        $documentoCliente = $clienteFacturacion['documento'];
        $tipoDocCliente = (int) $clienteFacturacion['tipo_doc_cgr'];

        $tipoDocSIN = SiatUtilidades::documentoIdentidadCGRtoSIN($tipoDocCliente);
        $codigoCliente = SiatUtilidades::generarCodigoCliente($nombreCliente, $documentoCliente);
        $complementoDoc = DB::scalar(
            "SELECT abreviatura FROM expedicion WHERE id_expedicion = :exp",
            [':exp' => (int) ($cambioData['id_expedicion'] ?? 0)]
        ) ?: null;

        $ahora = date('Y-m-d H:i:s');
        $fechaEmision = SiatUtilidades::fechaHoraSIN($ahora);
        $fechaRaw = SiatUtilidades::fechaHoraRaw($ahora);

        // 9. Verificar NIT si corresponde
        $codigoExcepcion = null;
        if ($tipoDocSIN === 5) { // NIT
            $nitResp = $this->siat->verificarNit([
                'codigoAmbiente'      => self::CODIGO_AMBIENTE,
                'codigoModalidad'     => self::CODIGO_MODALIDAD,
                'codigoSistema'       => self::CODIGO_SISTEMA,
                'nit'                 => self::NIT_EMPRESA,
                'codigoSucursal'      => (int) $puntoVenta['sucursal'],
                'codigoPuntoVenta'    => (int) $puntoVenta['codigo_punto_venta'],
                'cuis'                => $puntoVenta['cuis'],
                'nitParaVerificacion' => (int) $documentoCliente,
            ]);
            $verificacion = $nitResp['data']['respuestaVerificarNit'] ?? null;
            if ($verificacion && ($verificacion['transaccion'] ?? false)) {
                $codigoExcepcion = 0;
            } else {
                $codigoExcepcion = 1;
            }
        }

        // Tipo operación SIN (invertido: compra CGR=1 → compra SIN=2, venta CGR=2 → venta SIN=1)
        $tipoOperacionSIN = $idOperacion === 1
            ? self::TIPO_OPERACION_SIN_COMPRA
            : self::TIPO_OPERACION_SIN_VENTA;

        // 10. Construir payload factura
        $facturaPayload = [
            'codigoAmbiente'       => self::CODIGO_AMBIENTE,
            'codigoDocumentoSector' => $codigoDocumentoSector,
            'codigoEmision'        => 1, // EN_LINEA
            'codigoModalidad'      => self::CODIGO_MODALIDAD,
            'codigoPuntoVenta'     => (int) $puntoVenta['codigo_punto_venta'],
            'codigoSistema'        => self::CODIGO_SISTEMA,
            'codigoSucursal'       => (int) $puntoVenta['sucursal'],
            'cufd'                 => $puntoVenta['cufd'],
            'cuis'                 => $puntoVenta['cuis'],
            'nit'                  => self::NIT_EMPRESA,
            'tipoFacturaDocumento' => self::TIPO_FACTURA_DOC,
            'codigoControl'        => $puntoVenta['codigo_control'],
            'fueraLinea'           => false,

            'cabecera' => [
                'nitEmisor'                    => self::NIT_EMPRESA,
                'razonSocialEmisor'            => self::RAZON_SOCIAL,
                'municipio'                    => $puntoVenta['municipio'] ?? '',
                'telefono'                     => $puntoVenta['telefono'] ?? '',
                'numeroFactura'                => $numeroFactura,
                'cufd'                         => $puntoVenta['cufd'],
                'codigoSucursal'               => (int) $puntoVenta['sucursal'],
                'direccion'                    => $puntoVenta['direccion'] ?? '',
                'codigoPuntoVenta'             => (int) $puntoVenta['codigo_punto_venta'],
                'fechaEmision'                 => $fechaEmision,
                'nombreRazonSocial'            => $nombreCliente,
                'codigoTipoDocumentoIdentidad' => $tipoDocSIN,
                'numeroDocumento'              => $documentoCliente,
                'complemento'                  => $complementoDoc,
                'codigoCliente'                => $codigoCliente,
                'codigoMetodoPago'             => self::METODO_PAGO_EFECTIVO,
                'numeroTarjeta'                => null,
                'codigoTipoOperacion'          => $tipoOperacionSIN,
                'tipoCambioOficial'            => round($oficial, 5),
                'tipoCambio'                   => round($tipoCambio, 5),
                'ingresoDiferenciaCambio'      => $diferenciaCambio,
                'montoTotal'                   => $montoTotal,
                'montoTotalSujetoIva'          => 0,
                'codigoMoneda'                 => self::CODIGO_MONEDA_BOB,
                'montoTotalMoneda'             => $montoTotalMoneda,
                'descuentoAdicional'           => 0,
                'codigoExcepcion'              => $codigoExcepcion,
                'cafc'                         => null,
                'leyenda'                      => $leyendaTexto,
                'usuario'                      => $puntoVenta['usuario'] ?? $loginUsuario,
                'codigoDocumentoSector'        => (string) $codigoDocumentoSector,
            ],

            'cuerpo' => [[
                'actividadEconomica' => $servicio['codigo_caeb'] ?? '641900',
                'codigoProductoSin'  => (int) ($servicio['codigo_catalogo'] ?? 0),
                'codigoProducto'     => $servicio['codigo'] ?? $codigoServicio,
                'descripcion'        => $servicio['nombre'] ?? ($idOperacion === 1 ? 'COMPRA DE MONEDA EXTRANJERA' : 'VENTA DE MONEDA EXTRANJERA'),
                'cantidad'           => round($cantidadMoneda, 5),
                'unidadMedida'       => self::UNIDAD_MEDIDA_SERVICIO,
                'precioUnitario'     => round($tipoCambio, 5),
                'montoDescuento'     => 0,
                'subtotal'           => round($montoTotalRaw, 5),
            ]],
        ];

        // 11. Generar CUF localmente
        $cuf = SiatUtilidades::generarCuf(
            $fechaRaw,
            (int) $puntoVenta['sucursal'],
            1, // EN_LINEA
            self::TIPO_FACTURA_DOC,
            $codigoDocumentoSector,
            $numeroFactura,
            (int) $puntoVenta['codigo_punto_venta'],
            $puntoVenta['codigo_control']
        );

        // 12. Enviar al servicio Java SIAT
        $tipoEmisionFinal = 1; // EN_LINEA
        $estadoFactura = null;
        $facturaJson = null;
        $respuestaJson = null;

        $respuesta = $this->siat->enviarFacturaDivisas($facturaPayload);

        $sinResponse = $respuesta['data']['respuestaServicioFacturacion'] ?? null;
        $transaccionOk = $sinResponse && ($sinResponse['transaccion'] ?? false);

        if ($transaccionOk) {
            $estadoFactura = $sinResponse['codigoDescripcion'] ?? 'VALIDADA';
        } else {
            // Contingencia: cambiar a FUERA_DE_LINEA, regenerar CUF
            $tipoEmisionFinal = 2; // FUERA_DE_LINEA
            $cuf = SiatUtilidades::generarCuf(
                $fechaRaw,
                (int) $puntoVenta['sucursal'],
                2, // FUERA_DE_LINEA
                self::TIPO_FACTURA_DOC,
                $codigoDocumentoSector,
                $numeroFactura,
                (int) $puntoVenta['codigo_punto_venta'],
                $puntoVenta['codigo_control']
            );
            // Estado para facturas emitidas FUERA_DE_LINEA.
            //
            // Debe ser 'POR_PROCESAR': es el valor que busca el proceso que
            // sincroniza contra el SIN (existen 65 filas históricas con ese estado
            // y tipo_emision=2, y 2 en 'SINCRONIZACION', que este repositorio nunca
            // escribe). Con 'CONTINGENCIA' las facturas quedaban invisibles para ese
            // proceso: se quedaban sin enviar indefinidamente.
            $estadoFactura = 'POR_PROCESAR';
            $respuestaJson = json_encode($sinResponse ?? $respuesta);

            if ($tipoDocSIN === 5) {
                $facturaPayload['cabecera']['codigoExcepcion'] = 1;
            }

            // Se serializa DESPUÉS de aplicar codigoExcepcion: antes se guardaba el
            // JSON previo y lo persistido no reflejaba lo que se intentó enviar.
            $facturaJson = json_encode($facturaPayload);
        }

        // 13. Guardar en fe_factura
        DB::execute("
            INSERT INTO fe_factura (
                cuf, numero_factura, factura, respuesta,
                id_operacion, id_tipo_operacion, estado, tipo_emision,
                created_at, updated_at, id_cufd, id_evento, id_leyenda
            ) VALUES (
                :cuf, :num, :factura, :respuesta,
                :id_op, :id_tipo_op, :estado, :tipo_emision,
                NOW(3), NOW(3), :id_cufd, NULL, :id_leyenda
            )
        ", [
            ':cuf'          => $cuf,
            ':num'          => $numeroFactura,
            ':factura'      => $facturaJson,
            ':respuesta'    => $respuestaJson,
            ':id_op'        => $idCambio,
            ':id_tipo_op'   => $idOperacion,
            ':estado'       => $estadoFactura,
            ':tipo_emision' => $tipoEmisionFinal,
            ':id_cufd'      => (int) $puntoVenta['id_cufd'],
            ':id_leyenda'   => $idLeyenda,
        ]);

        // 14. Registrar en factura
        [$fechaPart, $horaPart] = explode(' ', $fecRegOpe);
        $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $monDivisa]);
        $detalle = ($idOperacion === 1 ? 'COMPRA' : 'VENTA')
            . " DE {$monAbr} " . number_format($cantidadMoneda, 2, '.', '')
            . " A TC " . number_format($tipoCambio, 6, '.', '')
            . " - BOB " . number_format($montoTotal, 2, '.', '');

        DB::execute("
            INSERT INTO factura (
                id_operacion, transaccion, numero, fecha, hora,
                nit, nombre, detalle, importe, codigo_control, estado
            ) VALUES (
                :id_op, :transaccion, :numero, :fecha, :hora,
                :nit, :nombre, :detalle, :importe, :codigo, 1
            )
        ", [
            ':id_op'       => $idOperacion,
            ':transaccion' => $idCambio,
            ':numero'      => $numeroFactura,
            ':fecha'       => $fechaPart,
            ':hora'        => $horaPart,
            ':nit'         => $documentoCliente,
            ':nombre'      => $nombreCliente,
            ':detalle'     => $detalle,
            ':importe'     => $montoTotal,
            ':codigo'      => $cuf,
        ]);

        // 15. Registrar en facturado
        DB::execute("
            INSERT INTO facturado (
                id_operacion, transaccion, numero, fecha, hora,
                nit, nombre, detalle, importe, codigo_control,
                estado, id_agencia, autorizacion
            ) VALUES (
                :id_op, :transaccion, :numero, :fecha, :hora,
                :nit, :nombre, :detalle, :importe, :codigo,
                1, :agencia, :autorizacion
            )
        ", [
            ':id_op'        => $idOperacion,
            ':transaccion'  => $idCambio,
            ':numero'       => $numeroFactura,
            ':fecha'        => $fechaPart,
            ':hora'         => $horaPart,
            ':nit'          => $documentoCliente,
            ':nombre'       => $nombreCliente,
            ':detalle'      => $detalle,
            ':importe'      => $montoTotal,
            ':codigo'       => $cuf,
            ':agencia'      => $idAgencia,
            ':autorizacion' => $facturacion['autorizacion'] ?? '',
        ]);

        // 16. Incrementar contador
        DB::execute("
            UPDATE facturacion SET factura = factura + 1
            WHERE estado = 'A' AND id_agencia = :ag AND cf = 0 AND LENGTH(llave) > 0
        ", [':ag' => $idAgencia]);

        // 17. Marcar cambio como facturado
        DB::execute(
            "UPDATE cambio SET facturado = 1 WHERE id_cambio = :id",
            [':id' => $idCambio]
        );

        return [
            'cuf'            => $cuf,
            'numero_factura' => $numeroFactura,
            'tipo_emision'   => $tipoEmisionFinal,
            'estado'         => $estadoFactura,
            'monto_total'    => $montoTotal,
            'fecha_emision'  => $fechaEmision,
            'nit_emisor'     => (string) self::NIT_EMPRESA,
        ];
    }

    private function getConfigPuntoVenta(int $idAgencia, string $loginUsuario): ?array
    {
        $idPV = DB::selectOne("
            SELECT fpv.id_punto_venta
            FROM fe_punto_venta fpv
            INNER JOIN fe_sucursal fs ON fpv.id_sucursal = fs.id_sucursal
            INNER JOIN agencia a ON fs.id_agencia = a.id_agencia AND a.id_agencia = :ag
            INNER JOIN usuario u ON u.login_usu = fpv.usuario AND fpv.usuario = :usr
        ", [':ag' => $idAgencia, ':usr' => $loginUsuario]);

        if (!$idPV) {
            $idPV = DB::selectOne("
                SELECT fpv.id_punto_venta
                FROM fe_punto_venta fpv
                INNER JOIN fe_sucursal fs ON fpv.id_sucursal = fs.id_sucursal
                INNER JOIN agencia a ON fs.id_agencia = a.id_agencia AND a.id_agencia = :ag
                WHERE fpv.codigo_punto_venta = 0
            ", [':ag' => $idAgencia]);
        }

        if (!$idPV) return null;

        return DB::selectOne("
            SELECT fc.id_cufd, fc.codigo_control, fc.codigo AS cufd,
                   fpv.codigo_punto_venta, fs.sucursal, fc2.codigo AS cuis,
                   fs.municipio, fs.direccion, a.telefono, fpv.usuario
            FROM fe_cufd fc
            INNER JOIN fe_cuis fc2 ON fc.id_cuis = fc2.id_cuis
            INNER JOIN fe_punto_venta fpv ON fpv.id_punto_venta = fc2.id_punto_venta
                AND fpv.id_punto_venta = :pv
            INNER JOIN fe_sucursal fs ON fs.id_sucursal = fpv.id_sucursal
            INNER JOIN agencia a ON a.id_agencia = fs.id_agencia
            WHERE (CURRENT_TIMESTAMP(3) <= SUBTIME(fc.fecha_vigencia, '04:00:00')
                OR TIMESTAMPDIFF(HOUR, fc.created_at, CURRENT_TIMESTAMP(3)) <= 70)
                AND fpv.activo = TRUE
            ORDER BY fc.created_at DESC
            LIMIT 1
        ", [':pv' => $idPV['id_punto_venta']]);
    }

    private function getFacturacionAgencia(int $idAgencia): ?array
    {
        return DB::selectOne("
            SELECT * FROM facturacion
            WHERE estado = 'A' AND id_agencia = :ag AND LENGTH(llave) > 0 AND cf = 0
        ", [':ag' => $idAgencia]);
    }
}
