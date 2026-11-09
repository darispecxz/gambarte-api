<?php

namespace Contabilidad;

class OperationsReportService
{
    // ═══════════════════════════════════════════════════════════════
    // Agencias (catálogo)
    // ═══════════════════════════════════════════════════════════════

    public static function getAgencies()
    {
        return Db::q("SELECT id_agencia, descripcion FROM agencia WHERE estado = 'A' ORDER BY descripcion");
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. CAMBIOS
    // ═══════════════════════════════════════════════════════════════

    public static function getCambios($desde, $hasta = null, $idAgencia = null, $idOperacion = null)
    {
        $hasta = $hasta ?: $desde;
        $params = array(':desde' => $desde, ':hasta' => $hasta);

        $where = "c.facturado = '1' AND DATE(c.fec_crea_cambio) BETWEEN :desde AND :hasta";

        if ($idAgencia !== null) {
            $where .= " AND c.id_agencia = :id_agencia";
            $params[':id_agencia'] = $idAgencia;
        }
        if ($idOperacion !== null) {
            $where .= " AND c.id_operacion = :id_operacion";
            $params[':id_operacion'] = $idOperacion;
        }

        $sql = "SELECT c.id_cambio, c.id_operacion, c.id_agencia,
                       c.num_doc_identidad, c.monto, c.tipo_cambio,
                       c.total_cambio, c.recibido, c.entregado, c.cambio,
                       c.total_operacion, c.total_operacion_bob, c.tipo_cambio_bob,
                       c.fec_crea_cambio, c.cod_usuario, c.facturado,
                       c.mon_rec, c.mon_ent,
                       a.descripcion AS agencia,
                       o.descripcion AS operacion,
                       u.login_usu AS operador,
                       CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) AS usuario_financiero,
                       uf.celular AS telefono_uf,
                       CASE c.id_operacion
                           WHEN 1 THEN (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = c.mon_rec)
                           WHEN 2 THEN (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = c.mon_ent)
                       END AS moneda,
                       (SELECT m2.descripcion FROM moneda m2 WHERE m2.id_moneda = c.mon_rec) AS moneda_recibida,
                       (SELECT m3.descripcion FROM moneda m3 WHERE m3.id_moneda = c.mon_ent) AS moneda_entregada
                FROM cambio c
                INNER JOIN agencia a ON a.id_agencia = c.id_agencia
                INNER JOIN operacion o ON o.id_operacion = c.id_operacion
                INNER JOIN trabajador t ON t.cod_trab = c.cod_usuario
                INNER JOIN usuario u ON u.cod_trab = t.cod_trab
                LEFT JOIN (
                    SELECT DISTINCT num_doc_identidad, id_expedicion, id_tipo_doc_identidad,
                           nombres, ape_pat, ape_mat, celular
                    FROM usuario_financiero
                ) uf ON uf.num_doc_identidad = c.num_doc_identidad
                     AND uf.id_expedicion = c.id_expedicion
                     AND uf.id_tipo_doc_identidad = c.id_tipo_doc_identidad
                WHERE {$where}
                ORDER BY c.fec_crea_cambio DESC";

        $rows = Db::q($sql, $params);

        return array_map(function ($r) {
            $esCompra = ((int)$r['id_operacion'] === 1);
            $monto = $esCompra
                ? (float)$r['recibido'] - (float)$r['cambio']
                : (float)$r['entregado'];

            return array(
                'id'               => (int)$r['id_cambio'],
                'idOperacion'      => (int)$r['id_operacion'],
                'tipo'             => $esCompra ? 'COMPRA' : 'VENTA',
                'tipoDesc'         => $r['operacion'],
                'idAgencia'        => (int)$r['id_agencia'],
                'agencia'          => $r['agencia'],
                'operador'         => $r['operador'],
                'usuarioFinanciero' => $r['usuario_financiero'],
                'telefonoUf'       => $r['telefono_uf'],
                'documento'        => $r['num_doc_identidad'],
                'moneda'           => $r['moneda'],
                'monedaRecibida'   => $r['moneda_recibida'],
                'monedaEntregada'  => $r['moneda_entregada'],
                'monto'            => round($monto, 2),
                'tipoCambio'       => (float)$r['tipo_cambio'],
                'tipoCambioBob'    => (float)$r['tipo_cambio_bob'],
                'totalOperacion'   => round((float)$r['total_operacion'], 2),
                'totalOperacionBob' => round((float)$r['total_operacion_bob'], 2),
                'recibido'         => round((float)$r['recibido'], 2),
                'entregado'        => round((float)$r['entregado'], 2),
                'fecha'            => $r['fec_crea_cambio'],
                'facturado'        => (int)$r['facturado'],
            );
        }, $rows);
    }

    public static function getCambioDetail($idCambio)
    {
        $sql = "SELECT c.*, a.descripcion AS agencia,
                       o.descripcion AS operacion,
                       u.login_usu AS operador,
                       CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) AS usuario_financiero,
                       uf.celular AS telefono_uf, uf.domicilio,
                       td.abreviatura AS tipo_doc_abr,
                       td.descripcion AS tipo_doc_desc,
                       ex.abreviatura AS expedicion,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = c.mon_rec) AS mon_rec_abr,
                       (SELECT m.descripcion FROM moneda m WHERE m.id_moneda = c.mon_rec) AS mon_rec_desc,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = c.mon_ent) AS mon_ent_abr,
                       (SELECT m.descripcion FROM moneda m WHERE m.id_moneda = c.mon_ent) AS mon_ent_desc
                FROM cambio c
                INNER JOIN agencia a ON a.id_agencia = c.id_agencia
                INNER JOIN operacion o ON o.id_operacion = c.id_operacion
                INNER JOIN trabajador t ON t.cod_trab = c.cod_usuario
                INNER JOIN usuario u ON u.cod_trab = t.cod_trab
                LEFT JOIN (
                    SELECT DISTINCT num_doc_identidad, id_expedicion, id_tipo_doc_identidad,
                           nombres, ape_pat, ape_mat, celular, domicilio
                    FROM usuario_financiero
                ) uf ON uf.num_doc_identidad = c.num_doc_identidad
                     AND uf.id_expedicion = c.id_expedicion
                     AND uf.id_tipo_doc_identidad = c.id_tipo_doc_identidad
                LEFT JOIN tipo_doc_identidad td ON td.id_tipo_doc_identidad = c.id_tipo_doc_identidad
                LEFT JOIN expedicion ex ON ex.id_expedicion = c.id_expedicion
                WHERE c.id_cambio = :id
                LIMIT 1";

        $rows = Db::q($sql, array(':id' => $idCambio));
        if (empty($rows)) return null;
        $r = $rows[0];

        $esCompra = ((int)$r['id_operacion'] === 1);
        $monto = $esCompra
            ? (float)$r['recibido'] - (float)$r['cambio']
            : (float)$r['entregado'];

        $detail = array(
            'id'               => (int)$r['id_cambio'],
            'idOperacion'      => (int)$r['id_operacion'],
            'tipo'             => $esCompra ? 'COMPRA' : 'VENTA',
            'tipoDesc'         => $r['operacion'],
            'agencia'          => $r['agencia'],
            'operador'         => $r['operador'],
            'fecha'            => $r['fec_crea_cambio'],
            'cliente'          => array(
                'nombre'    => $r['usuario_financiero'],
                'documento' => ($r['tipo_doc_abr'] ?: '') . ' ' . $r['num_doc_identidad'] . ($r['expedicion'] ? ' ' . $r['expedicion'] : ''),
                'telefono'  => $r['telefono_uf'],
                'domicilio' => $r['domicilio'],
            ),
            'monedaRecibida'    => $r['mon_rec_abr'],
            'monedaRecibidaDesc' => $r['mon_rec_desc'],
            'monedaEntregada'   => $r['mon_ent_abr'],
            'monedaEntregadaDesc' => $r['mon_ent_desc'],
            'monto'             => round($monto, 2),
            'tipoCambio'        => (float)$r['tipo_cambio'],
            'tipoCambioBob'     => (float)$r['tipo_cambio_bob'],
            'totalOperacion'    => round((float)$r['total_operacion'], 2),
            'totalOperacionBob' => round((float)$r['total_operacion_bob'], 2),
            'recibido'          => round((float)$r['recibido'], 2),
            'entregado'         => round((float)$r['entregado'], 2),
            'cambio'            => round((float)$r['cambio'], 2),
            'totalCambio'       => round((float)$r['total_cambio'], 2),
            'facturado'         => (int)$r['facturado'],
            'origen'            => $r['origen'],
            'destino'           => $r['destino'],
        );

        $detail['factura'] = self::getFactura((int)$r['id_cambio'], (int)$r['id_operacion']);

        return $detail;
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. GIROS NACIONALES
    // ═══════════════════════════════════════════════════════════════

    public static function getGiros($desde, $hasta = null, $idAgencia = null, $estado = null, $codigo = null)
    {
        $hasta = $hasta ?: $desde;
        $params = array(':desde' => $desde, ':hasta' => $hasta);

        $where = "DATE(g.fec_crea_giro) BETWEEN :desde AND :hasta";

        if ($idAgencia !== null) {
            $where .= " AND g.id_agencia_origen = :id_agencia";
            $params[':id_agencia'] = $idAgencia;
        }
        if ($estado !== null) {
            $where .= " AND g.estado = :estado";
            $params[':estado'] = $estado;
        }
        if ($codigo !== null) {
            $where .= " AND g.codigo = :codigo";
            $params[':codigo'] = $codigo;
        }

        $sql = "SELECT g.id_giro, g.codigo, g.id_operacion, g.id_agencia_origen, g.id_agencia_destino,
                       g.monto, g.tipo_cambio, g.total_cambio,
                       g.comision, g.comision_bob, g.itf, g.total, g.total_bob,
                       g.fec_crea_giro, g.fec_mod_giro, g.estado,
                       g.cod_usuario, g.correlativo_agencia, g.correlativo_destino,
                       g.num_doc_identidad,
                       g.mon_rec, g.mon_ent,
                       ao.descripcion AS agencia_origen,
                       ad.descripcion AS agencia_destino,
                       u.login_usu AS operador,
                       CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) AS usuario_financiero,
                       uf.celular AS telefono_uf,
                       CONCAT(d.nombres, ' ', d.ape_pat, ' ', d.ape_mat) AS destinatario,
                       d.celular AS telefono_dest,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = g.mon_rec) AS moneda
                FROM giro g
                INNER JOIN agencia ao ON ao.id_agencia = g.id_agencia_origen
                INNER JOIN agencia ad ON ad.id_agencia = g.id_agencia_destino
                INNER JOIN trabajador t ON t.cod_trab = g.cod_usuario
                INNER JOIN usuario u ON u.cod_trab = t.cod_trab
                LEFT JOIN (
                    SELECT DISTINCT num_doc_identidad, id_expedicion, id_tipo_doc_identidad,
                           nombres, ape_pat, ape_mat, celular
                    FROM usuario_financiero
                ) uf ON uf.num_doc_identidad = g.num_doc_identidad
                     AND uf.id_expedicion = g.id_expedicion
                     AND uf.id_tipo_doc_identidad = g.id_tipo_doc_identidad
                LEFT JOIN destinatario d ON d.id_destinatario = g.id_destinatario
                WHERE {$where}
                ORDER BY g.fec_crea_giro DESC";

        $rows = Db::q($sql, $params);

        $estadoMap = array(0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO');

        return array_map(function ($r) use ($estadoMap) {
            return array(
                'id'               => (int)$r['id_giro'],
                'codigo'           => (int)$r['codigo'],
                'idAgenciaOrigen'  => (int)$r['id_agencia_origen'],
                'agenciaOrigen'    => $r['agencia_origen'],
                'idAgenciaDestino' => (int)$r['id_agencia_destino'],
                'agenciaDestino'   => $r['agencia_destino'],
                'operador'         => $r['operador'],
                'usuarioFinanciero' => $r['usuario_financiero'],
                'telefonoUf'       => $r['telefono_uf'],
                'documento'        => $r['num_doc_identidad'],
                'destinatario'     => $r['destinatario'],
                'telefonoDest'     => $r['telefono_dest'],
                'moneda'           => $r['moneda'],
                'monto'            => round((float)$r['monto'], 2),
                'tipoCambio'       => (float)$r['tipo_cambio'],
                'totalCambio'      => round((float)$r['total_cambio'], 2),
                'comision'         => round((float)$r['comision'], 2),
                'comisionBob'      => round((float)$r['comision_bob'], 2),
                'itf'              => round((float)$r['itf'], 2),
                'total'            => round((float)$r['total'], 2),
                'totalBob'         => round((float)$r['total_bob'], 2),
                'fecha'            => $r['fec_crea_giro'],
                'fechaPago'        => $r['fec_mod_giro'],
                'estado'           => (int)$r['estado'],
                'estadoDesc'       => isset($estadoMap[(int)$r['estado']]) ? $estadoMap[(int)$r['estado']] : 'DESCONOCIDO',
                'correlativoOrigen' => $r['correlativo_agencia'],
                'correlativoDestino' => $r['correlativo_destino'],
            );
        }, $rows);
    }

    public static function getGiroDetail($idGiro)
    {
        $sql = "SELECT g.*, ao.descripcion AS agencia_origen, ad.descripcion AS agencia_destino,
                       u.login_usu AS operador,
                       CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) AS usuario_financiero,
                       uf.celular AS telefono_uf, uf.domicilio AS domicilio_uf,
                       td.abreviatura AS tipo_doc_abr,
                       ex.abreviatura AS expedicion,
                       CONCAT(d.nombres, ' ', d.ape_pat, ' ', d.ape_mat) AS destinatario,
                       d.celular AS telefono_dest, d.domicilio AS domicilio_dest,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = g.mon_rec) AS mon_rec_abr,
                       (SELECT m.descripcion FROM moneda m WHERE m.id_moneda = g.mon_rec) AS mon_rec_desc,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = g.mon_ent) AS mon_ent_abr,
                       (SELECT m.descripcion FROM moneda m WHERE m.id_moneda = g.mon_ent) AS mon_ent_desc
                FROM giro g
                INNER JOIN agencia ao ON ao.id_agencia = g.id_agencia_origen
                INNER JOIN agencia ad ON ad.id_agencia = g.id_agencia_destino
                INNER JOIN trabajador t ON t.cod_trab = g.cod_usuario
                INNER JOIN usuario u ON u.cod_trab = t.cod_trab
                LEFT JOIN (
                    SELECT DISTINCT num_doc_identidad, id_expedicion, id_tipo_doc_identidad,
                           nombres, ape_pat, ape_mat, celular, domicilio
                    FROM usuario_financiero
                ) uf ON uf.num_doc_identidad = g.num_doc_identidad
                     AND uf.id_expedicion = g.id_expedicion
                     AND uf.id_tipo_doc_identidad = g.id_tipo_doc_identidad
                LEFT JOIN tipo_doc_identidad td ON td.id_tipo_doc_identidad = g.id_tipo_doc_identidad
                LEFT JOIN expedicion ex ON ex.id_expedicion = g.id_expedicion
                LEFT JOIN destinatario d ON d.id_destinatario = g.id_destinatario
                WHERE g.id_giro = :id
                LIMIT 1";

        $rows = Db::q($sql, array(':id' => $idGiro));
        if (empty($rows)) return null;
        $r = $rows[0];

        $estadoMap = array(0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO');

        $detail = array(
            'id'            => (int)$r['id_giro'],
            'codigo'        => (int)$r['codigo'],
            'tipo'          => 'GIRO NACIONAL',
            'agenciaOrigen' => $r['agencia_origen'],
            'agenciaDestino' => $r['agencia_destino'],
            'operador'      => $r['operador'],
            'fecha'         => $r['fec_crea_giro'],
            'fechaPago'     => $r['fec_mod_giro'],
            'estado'        => (int)$r['estado'],
            'estadoDesc'    => isset($estadoMap[(int)$r['estado']]) ? $estadoMap[(int)$r['estado']] : 'DESCONOCIDO',
            'remitente'     => array(
                'nombre'    => $r['usuario_financiero'],
                'documento' => ($r['tipo_doc_abr'] ?: '') . ' ' . $r['num_doc_identidad'] . ($r['expedicion'] ? ' ' . $r['expedicion'] : ''),
                'telefono'  => $r['telefono_uf'],
                'domicilio' => $r['domicilio_uf'],
            ),
            'destinatario'  => array(
                'nombre'    => $r['destinatario'],
                'telefono'  => $r['telefono_dest'],
                'domicilio' => $r['domicilio_dest'],
            ),
            'monedaRecibida'     => $r['mon_rec_abr'],
            'monedaRecibidaDesc' => $r['mon_rec_desc'],
            'monedaEntregada'    => $r['mon_ent_abr'],
            'monedaEntregadaDesc' => $r['mon_ent_desc'],
            'monto'              => round((float)$r['monto'], 2),
            'tipoCambio'         => (float)$r['tipo_cambio'],
            'totalCambio'        => round((float)$r['total_cambio'], 2),
            'comision'           => round((float)$r['comision'], 2),
            'comisionBob'        => round((float)$r['comision_bob'], 2),
            'itf'                => round((float)$r['itf'], 2),
            'total'              => round((float)$r['total'], 2),
            'totalBob'           => round((float)$r['total_bob'], 2),
            'correlativoOrigen'  => $r['correlativo_agencia'],
            'correlativoDestino' => $r['correlativo_destino'],
            'origen'             => $r['origen'],
            'destino'            => $r['destino'],
        );

        $detail['factura'] = self::getFactura((int)$r['id_giro'], 3);

        $detail['pago'] = self::getPago((int)$r['codigo'], 3);

        return $detail;
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. REMESAS
    // ═══════════════════════════════════════════════════════════════

    public static function getRemesas($desde, $hasta = null, $idAgencia = null, $estado = null, $subtipo = null, $codigo = null)
    {
        $hasta = $hasta ?: $desde;
        $params = array(':desde' => $desde, ':hasta' => $hasta);

        $where = "DATE(r.fec_crea_remesa) BETWEEN :desde AND :hasta";

        if ($idAgencia !== null) {
            $where .= " AND r.id_agencia = :id_agencia";
            $params[':id_agencia'] = $idAgencia;
        }
        if ($estado !== null) {
            $where .= " AND r.estado = :estado";
            $params[':estado'] = $estado;
        }
        if ($subtipo !== null) {
            $where .= " AND r.subtipo = :subtipo";
            $params[':subtipo'] = $subtipo;
        }
        if ($codigo !== null) {
            $where .= " AND r.codigo = :codigo";
            $params[':codigo'] = $codigo;
        }

        $sql = "SELECT r.id_remesa, r.codigo, r.id_operacion, r.id_agencia,
                       r.monto, r.tipo_cambio, r.total_cambio,
                       r.comision, r.comision_bob, r.itf, r.total, r.total_bob,
                       r.fec_crea_remesa, r.fec_mod_remesa, r.estado, r.subtipo,
                       r.cod_usuario, r.correlativo, r.id_destino,
                       r.comision_gambarte, r.comision_gambarte_bob,
                       r.gastos_corresponsal, r.gastos_corresponsal_bob,
                       r.iva, r.iva_bob, r.itf_bob,
                       r.num_doc_identidad,
                       r.mon_rec, r.mon_ent,
                       a.descripcion AS agencia,
                       u.login_usu AS operador,
                       CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) AS usuario_financiero,
                       uf.celular AS telefono_uf,
                       CONCAT(d.nombres, ' ', d.ape_pat, ' ', d.ape_mat) AS destinatario,
                       d.celular AS telefono_dest,
                       ds.descripcion AS destino_nombre,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = r.mon_rec) AS moneda
                FROM remesa r
                INNER JOIN agencia a ON a.id_agencia = r.id_agencia
                INNER JOIN trabajador t ON t.cod_trab = r.cod_usuario
                INNER JOIN usuario u ON u.cod_trab = t.cod_trab
                LEFT JOIN (
                    SELECT DISTINCT num_doc_identidad, id_expedicion, id_tipo_doc_identidad,
                           nombres, ape_pat, ape_mat, celular
                    FROM usuario_financiero
                ) uf ON uf.num_doc_identidad = r.num_doc_identidad
                     AND uf.id_expedicion = r.id_expedicion
                     AND uf.id_tipo_doc_identidad = r.id_tipo_doc_identidad
                LEFT JOIN destinatario d ON d.id_destinatario = r.id_destinatario
                LEFT JOIN destino ds ON ds.id_destino = r.id_destino
                WHERE {$where}
                ORDER BY r.fec_crea_remesa DESC";

        $rows = Db::q($sql, $params);

        $estadoMap = array(0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO', 5 => 'ANULADO');

        return array_map(function ($r) use ($estadoMap) {
            return array(
                'id'               => (int)$r['id_remesa'],
                'codigo'           => (int)$r['codigo'],
                'idAgencia'        => (int)$r['id_agencia'],
                'agencia'          => $r['agencia'],
                'operador'         => $r['operador'],
                'usuarioFinanciero' => $r['usuario_financiero'],
                'telefonoUf'       => $r['telefono_uf'],
                'documento'        => $r['num_doc_identidad'],
                'destinatario'     => $r['destinatario'],
                'telefonoDest'     => $r['telefono_dest'],
                'destino'          => $r['destino_nombre'],
                'moneda'           => $r['moneda'],
                'subtipo'          => $r['subtipo'],
                'subtipoDesc'      => $r['subtipo'] === 'R' ? 'Remesa Familiar' : 'Giro Internacional',
                'monto'            => round((float)$r['monto'], 2),
                'tipoCambio'       => (float)$r['tipo_cambio'],
                'totalCambio'      => round((float)$r['total_cambio'], 2),
                'comision'         => round((float)$r['comision'], 2),
                'comisionBob'      => round((float)$r['comision_bob'], 2),
                'comisionGambarte' => round((float)$r['comision_gambarte_bob'], 2),
                'gastosCorresponsal' => round((float)$r['gastos_corresponsal_bob'], 2),
                'iva'              => round((float)$r['iva_bob'], 2),
                'itf'              => round((float)$r['itf_bob'], 2),
                'total'            => round((float)$r['total'], 2),
                'totalBob'         => round((float)$r['total_bob'], 2),
                'fecha'            => $r['fec_crea_remesa'],
                'fechaPago'        => $r['fec_mod_remesa'],
                'estado'           => (int)$r['estado'],
                'estadoDesc'       => isset($estadoMap[(int)$r['estado']]) ? $estadoMap[(int)$r['estado']] : 'DESCONOCIDO',
                'correlativo'      => $r['correlativo'],
            );
        }, $rows);
    }

    public static function getRemesaDetail($idRemesa)
    {
        $sql = "SELECT r.*, a.descripcion AS agencia,
                       u.login_usu AS operador,
                       CONCAT(uf.nombres, ' ', uf.ape_pat, ' ', uf.ape_mat) AS usuario_financiero,
                       uf.celular AS telefono_uf, uf.domicilio AS domicilio_uf,
                       td.abreviatura AS tipo_doc_abr,
                       ex.abreviatura AS expedicion,
                       CONCAT(d.nombres, ' ', d.ape_pat, ' ', d.ape_mat) AS destinatario,
                       d.celular AS telefono_dest, d.domicilio AS domicilio_dest,
                       ds.descripcion AS destino_nombre,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = r.mon_rec) AS mon_rec_abr,
                       (SELECT m.descripcion FROM moneda m WHERE m.id_moneda = r.mon_rec) AS mon_rec_desc,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = r.mon_ent) AS mon_ent_abr,
                       (SELECT m.descripcion FROM moneda m WHERE m.id_moneda = r.mon_ent) AS mon_ent_desc
                FROM remesa r
                INNER JOIN agencia a ON a.id_agencia = r.id_agencia
                INNER JOIN trabajador t ON t.cod_trab = r.cod_usuario
                INNER JOIN usuario u ON u.cod_trab = t.cod_trab
                LEFT JOIN (
                    SELECT DISTINCT num_doc_identidad, id_expedicion, id_tipo_doc_identidad,
                           nombres, ape_pat, ape_mat, celular, domicilio
                    FROM usuario_financiero
                ) uf ON uf.num_doc_identidad = r.num_doc_identidad
                     AND uf.id_expedicion = r.id_expedicion
                     AND uf.id_tipo_doc_identidad = r.id_tipo_doc_identidad
                LEFT JOIN tipo_doc_identidad td ON td.id_tipo_doc_identidad = r.id_tipo_doc_identidad
                LEFT JOIN expedicion ex ON ex.id_expedicion = r.id_expedicion
                LEFT JOIN destinatario d ON d.id_destinatario = r.id_destinatario
                LEFT JOIN destino ds ON ds.id_destino = r.id_destino
                WHERE r.id_remesa = :id
                LIMIT 1";

        $rows = Db::q($sql, array(':id' => $idRemesa));
        if (empty($rows)) return null;
        $r = $rows[0];

        $estadoMap = array(0 => 'PENDIENTE', 1 => 'PAGADO', 2 => 'DEVUELTO', 5 => 'ANULADO');

        $detail = array(
            'id'            => (int)$r['id_remesa'],
            'codigo'        => (int)$r['codigo'],
            'tipo'          => $r['subtipo'] === 'R' ? 'REMESA FAMILIAR' : 'GIRO INTERNACIONAL',
            'subtipo'       => $r['subtipo'],
            'agencia'       => $r['agencia'],
            'destino'       => $r['destino_nombre'],
            'operador'      => $r['operador'],
            'fecha'         => $r['fec_crea_remesa'],
            'fechaPago'     => $r['fec_mod_remesa'],
            'estado'        => (int)$r['estado'],
            'estadoDesc'    => isset($estadoMap[(int)$r['estado']]) ? $estadoMap[(int)$r['estado']] : 'DESCONOCIDO',
            'remitente'     => array(
                'nombre'    => $r['usuario_financiero'],
                'documento' => ($r['tipo_doc_abr'] ?: '') . ' ' . $r['num_doc_identidad'] . ($r['expedicion'] ? ' ' . $r['expedicion'] : ''),
                'telefono'  => $r['telefono_uf'],
                'domicilio' => $r['domicilio_uf'],
            ),
            'destinatario'  => array(
                'nombre'    => $r['destinatario'],
                'telefono'  => $r['telefono_dest'],
                'domicilio' => $r['domicilio_dest'],
            ),
            'monedaRecibida'     => $r['mon_rec_abr'],
            'monedaRecibidaDesc' => $r['mon_rec_desc'],
            'monedaEntregada'    => $r['mon_ent_abr'],
            'monedaEntregadaDesc' => $r['mon_ent_desc'],
            'monto'              => round((float)$r['monto'], 2),
            'tipoCambio'         => (float)$r['tipo_cambio'],
            'totalCambio'        => round((float)$r['total_cambio'], 2),
            'comision'           => round((float)$r['comision'], 2),
            'comisionBob'        => round((float)$r['comision_bob'], 2),
            'comisionGambarte'   => round((float)$r['comision_gambarte_bob'], 2),
            'gastosCorresponsal' => round((float)$r['gastos_corresponsal_bob'], 2),
            'iva'                => round((float)$r['iva_bob'], 2),
            'itf'                => round((float)$r['itf_bob'], 2),
            'total'              => round((float)$r['total'], 2),
            'totalBob'           => round((float)$r['total_bob'], 2),
            'correlativo'        => $r['correlativo'],
            'origen'             => $r['origen'],
            'destinoNarr'        => $r['destino'],
        );

        $opId = (int)$r['id_operacion'];
        $detail['factura'] = self::getFactura((int)$r['id_remesa'], $opId);

        $detail['pago'] = self::getPago((int)$r['codigo'], $opId);

        return $detail;
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers: factura y pago
    // ═══════════════════════════════════════════════════════════════

    private static function getFactura($idTransaccion, $idOperacion)
    {
        $sql = "SELECT f.numero, f.fecha, f.hora, f.nit, f.nombre, f.detalle,
                       f.importe, f.autorizacion, f.id_agencia,
                       a.descripcion AS agencia
                FROM facturado f
                LEFT JOIN agencia a ON a.id_agencia = f.id_agencia
                WHERE f.transaccion = :transaccion AND f.id_operacion = :id_operacion
                ORDER BY f.fecha DESC
                LIMIT 1";

        $rows = Db::q($sql, array(':transaccion' => $idTransaccion, ':id_operacion' => $idOperacion));
        if (empty($rows)) return null;

        $f = $rows[0];
        return array(
            'numero'       => $f['numero'],
            'fecha'        => $f['fecha'],
            'hora'         => $f['hora'],
            'nit'          => $f['nit'],
            'nombre'       => $f['nombre'],
            'detalle'      => $f['detalle'],
            'importe'      => round((float)$f['importe'], 2),
            'autorizacion' => $f['autorizacion'],
            'agencia'      => $f['agencia'],
        );
    }

    private static function getPago($codigo, $idOperacion)
    {
        $sql = "SELECT p.id_pago, p.id_tipo_pago, p.codigo, p.id_agencia,
                       p.mon_rec, p.monto, p.tipo_cambio,
                       p.fec_crea_pago, p.cod_usuario, p.comprobante, p.comision_bob, p.motivo,
                       p.num_doc_identidad,
                       a.descripcion AS agencia,
                       u.login_usu AS operador_pago,
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = p.mon_rec) AS moneda
                FROM pago p
                INNER JOIN agencia a ON a.id_agencia = p.id_agencia
                INNER JOIN trabajador t ON t.cod_trab = p.cod_usuario
                INNER JOIN usuario u ON u.cod_trab = t.cod_trab
                WHERE p.codigo = :codigo AND p.id_operacion = :id_operacion
                ORDER BY p.fec_crea_pago DESC
                LIMIT 1";

        $rows = Db::q($sql, array(':codigo' => $codigo, ':id_operacion' => $idOperacion));
        if (empty($rows)) return null;

        $p = $rows[0];
        return array(
            'id'           => (int)$p['id_pago'],
            'tipoPago'     => (int)$p['id_tipo_pago'] === 1 ? 'PAGO' : 'DEVOLUCION',
            'agencia'      => $p['agencia'],
            'operador'     => $p['operador_pago'],
            'moneda'       => $p['moneda'],
            'monto'        => round((float)$p['monto'], 2),
            'tipoCambio'   => (float)$p['tipo_cambio'],
            'fecha'        => $p['fec_crea_pago'],
            'comprobante'  => $p['comprobante'],
            'comisionBob'  => round((float)$p['comision_bob'], 2),
            'motivo'       => $p['motivo'],
            'documento'    => $p['num_doc_identidad'],
        );
    }
}
