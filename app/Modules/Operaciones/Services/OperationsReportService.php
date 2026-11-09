<?php

namespace App\Modules\Operaciones\Services;

use App\Modules\Shared\Database\RawConnection;

class OperationsReportService
{
    public function agencies()
    {
        return RawConnection::select("SELECT id_agencia, descripcion FROM agencia WHERE estado = 'A' ORDER BY descripcion");
    }

    // ═══════════════════════════════════════════════════════════════
    // CAMBIOS
    // ═══════════════════════════════════════════════════════════════

    public function cambios(string $desde, ?string $hasta = null, ?int $idAgencia = null, ?int $idOperacion = null, ?int $codUsuario = null)
    {
        $hasta = $hasta ?: $desde;
        $params = array(':desde' => $desde, ':hasta' => $hasta);

        $where = "DATE(c.fec_crea_cambio) BETWEEN :desde AND :hasta";

        if ($idAgencia !== null) {
            $where .= " AND c.id_agencia = :id_agencia";
            $params[':id_agencia'] = $idAgencia;
        }
        if ($idOperacion !== null) {
            $where .= " AND c.id_operacion = :id_operacion";
            $params[':id_operacion'] = $idOperacion;
        }
        if ($codUsuario !== null) {
            $where .= " AND c.cod_usuario = :cod_usuario";
            $params[':cod_usuario'] = $codUsuario;
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
                       (SELECT m3.descripcion FROM moneda m3 WHERE m3.id_moneda = c.mon_ent) AS moneda_entregada,
                       co.valor AS cotizacion_oficial
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
                LEFT JOIN cotizacion_oficial co ON co.id_moneda = CASE WHEN c.id_operacion = 1 THEN c.mon_rec ELSE c.mon_ent END
                     AND co.id_operacion = 1
                     AND co.fec_crea_cotizacion_oficial = (
                         SELECT MAX(co2.fec_crea_cotizacion_oficial)
                         FROM cotizacion_oficial co2
                         WHERE co2.id_moneda = CASE WHEN c.id_operacion = 1 THEN c.mon_rec ELSE c.mon_ent END
                           AND co2.id_operacion = 1
                           AND co2.fec_crea_cotizacion_oficial <= c.fec_crea_cambio
                     )
                WHERE {$where}
                ORDER BY c.fec_crea_cambio ASC";

        $rows = RawConnection::select($sql, $params);

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
                'cotizacionOficial' => $r['cotizacion_oficial'] !== null ? (float)$r['cotizacion_oficial'] : null,
                'totalOperacion'   => round((float)$r['total_operacion'], 2),
                'totalOperacionBob' => round((float)$r['total_operacion_bob'], 2),
                'diferenciaBob'    => $r['cotizacion_oficial'] !== null
                    ? round((float)$r['total_operacion_bob'] - ($monto * (float)$r['cotizacion_oficial']), 2)
                    : null,
                'recibido'         => round((float)$r['recibido'], 2),
                'entregado'        => round((float)$r['entregado'], 2),
                'fecha'            => $r['fec_crea_cambio'],
                'facturado'        => (int)$r['facturado'],
            );
        }, $rows);
    }

    public function cambioDetail(int $idCambio)
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

        $rows = RawConnection::select($sql, array(':id' => $idCambio));
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

        $detail['factura'] = $this->getFactura((int)$r['id_cambio'], (int)$r['id_operacion']);

        return $detail;
    }

    // ═══════════════════════════════════════════════════════════════
    // GIROS NACIONALES
    // ═══════════════════════════════════════════════════════════════

    public function giros(string $desde, ?string $hasta = null, ?int $idAgencia = null, ?string $estado = null, ?int $codigo = null)
    {
        $hasta = $hasta ?: $desde;
        $params = array(':desde' => $desde, ':hasta' => $hasta);

        $where = "DATE(g.fec_crea_giro) BETWEEN :desde AND :hasta";

        if ($idAgencia !== null) {
            $where .= " AND g.id_agencia_origen = :id_agencia";
            $params[':id_agencia'] = $idAgencia;
        }
        if ($estado !== null) {
            $estados = array_map('intval', explode(',', $estado));
            $placeholders = array();
            foreach ($estados as $i => $e) {
                $key = ':estado' . $i;
                $placeholders[] = $key;
                $params[$key] = $e;
            }
            $where .= " AND g.estado IN (" . implode(',', $placeholders) . ")";
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
                ORDER BY g.fec_crea_giro ASC";

        $rows = RawConnection::select($sql, $params);

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

    public function giroDetail(int $idGiro)
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

        $rows = RawConnection::select($sql, array(':id' => $idGiro));
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

        $detail['factura'] = $this->getFactura((int)$r['id_giro'], 3);
        $detail['pago'] = $this->getPago((int)$r['codigo'], 3);

        return $detail;
    }

    // ═══════════════════════════════════════════════════════════════
    // REMESAS
    // ═══════════════════════════════════════════════════════════════

    public function remesas(string $desde, ?string $hasta = null, ?int $idAgencia = null, ?string $estado = null, ?string $subtipo = null, ?int $codigo = null)
    {
        $hasta = $hasta ?: $desde;
        $params = array(':desde' => $desde, ':hasta' => $hasta);

        $where = "DATE(r.fec_crea_remesa) BETWEEN :desde AND :hasta";

        if ($idAgencia !== null) {
            $where .= " AND r.id_agencia = :id_agencia";
            $params[':id_agencia'] = $idAgencia;
        }
        if ($estado !== null) {
            $estados = array_map('intval', explode(',', $estado));
            $placeholders = array();
            foreach ($estados as $i => $e) {
                $key = ':estado' . $i;
                $placeholders[] = $key;
                $params[$key] = $e;
            }
            $where .= " AND r.estado IN (" . implode(',', $placeholders) . ")";
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
                       (SELECT m.abreviatura FROM moneda m WHERE m.id_moneda = r.mon_rec) AS moneda,
                       CASE
                           WHEN r.mon_ent = 2 THEN ROUND(r.comision_bob / NULLIF(r.monto, 0) * 100, 2)
                           WHEN r.mon_ent = 1 THEN ROUND(
                               r.comision * COALESCE((
                                   SELECT cof.valor FROM cotizacion cof
                                   WHERE cof.id_moneda = r.mon_ent AND cof.id_operacion = 2
                                     AND CAST(cof.fec_crea_cotizacion AS DATE) <= r.fec_crea_remesa
                                   ORDER BY cof.fec_crea_cotizacion DESC LIMIT 1
                               ), r.tipo_cambio)
                               / NULLIF(r.monto * r.tipo_cambio, 0) * 100, 2)
                           ELSE ROUND(r.comision / NULLIF(r.monto, 0) * 100, 2)
                       END AS com_total
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
                ORDER BY r.fec_crea_remesa ASC";

        $rows = RawConnection::select($sql, $params);

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
                'porcentajeComision' => round((float)($r['com_total'] ?? 0), 2),
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

    public function remesaDetail(int $idRemesa)
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

        $rows = RawConnection::select($sql, array(':id' => $idRemesa));
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
        $detail['factura'] = $this->getFactura((int)$r['id_remesa'], $opId);
        $detail['pago'] = $this->getPago((int)$r['codigo'], $opId);

        return $detail;
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers: factura y pago
    // ═══════════════════════════════════════════════════════════════

    private function getFactura(int $idTransaccion, int $idOperacion)
    {
        $sql = "SELECT f.numero, f.fecha, f.hora, f.nit, f.nombre, f.detalle,
                       f.importe, f.autorizacion, f.id_agencia,
                       a.descripcion AS agencia
                FROM facturado f
                LEFT JOIN agencia a ON a.id_agencia = f.id_agencia
                WHERE f.transaccion = :transaccion AND f.id_operacion = :id_operacion
                ORDER BY f.fecha DESC
                LIMIT 1";

        $rows = RawConnection::select($sql, array(':transaccion' => $idTransaccion, ':id_operacion' => $idOperacion));
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

    private function getPago(int $codigo, int $idOperacion)
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

        $rows = RawConnection::select($sql, array(':codigo' => $codigo, ':id_operacion' => $idOperacion));
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

    // ═══════════════════════════════════════════════════════════════
    // REMESAS CGR (enviadas desde Chile a Bolivia)
    // ═══════════════════════════════════════════════════════════════

    private const ESTADO_CGR = [
        0 => 'Pendiente',
        1 => 'Pagada',
        2 => 'Devuelta',
        5 => 'Anulada',
        7 => 'En Proceso',
    ];

    public function remesasCgr(
        string $desde,
        ?string $hasta = null,
        ?int $idAgencia = null,
        ?string $estado = null,
        ?int $codigo = null,
        ?int $correlativoPais = null
    ): array {
        $hasta = $hasta ?: $desde;

        $maxDays = 90;
        $diffDays = (int)((strtotime($hasta) - strtotime($desde)) / 86400);
        if ($diffDays > $maxDays) {
            $desde = date('Y-m-d', strtotime($hasta . " - {$maxDays} days"));
        }

        $where = "DATE(fec_crea_remesa) BETWEEN '{$desde}' AND '{$hasta}'";
        if ($codigo !== null) {
            $where .= " AND codigo = '{$codigo}'";
        }
        if ($correlativoPais !== null) {
            $where .= " AND correlativo_pais = '{$correlativoPais}'";
        }
        if ($idAgencia !== null) {
            $where .= " AND id_agencia = '{$idAgencia}'";
        }

        $estadoValues = null;
        if ($estado !== null && $estado !== '') {
            $estadoValues = array_map('intval', explode(',', $estado));
            $estadoIn = implode(',', $estadoValues);
            $where .= " AND estado IN ({$estadoIn})";
        }

        $sql = "SELECT * FROM tmp_reporte_remesa WHERE {$where} ORDER BY correlativo_pais DESC LIMIT 5000";

        $chileRows = RawConnection::select($sql, [], 'chile');

        $codigos = array_column($chileRows, 'codigo');
        $pagoMap = [];
        $preprocesoMap = [];

        if (!empty($codigos)) {
            $codigoList = implode(',', array_map(function ($c) { return "'" . addslashes($c) . "'"; }, $codigos));

            $pagos = RawConnection::select("
                SELECT p.codigo, p.fec_crea_pago, a.abreviatura AS agencia_abr
                FROM pago p
                INNER JOIN agencia a ON a.id_agencia = p.id_agencia
                WHERE p.codigo IN ({$codigoList})
                  AND p.id_tipo_pago = 1
                  AND p.id_operacion != 3
            ");
            foreach ($pagos as $p) {
                $pagoMap[$p['codigo']] = $p;
            }

            $preprocesos = RawConnection::select("
                SELECT pp.codigo, a.abreviatura AS agencia_abr
                FROM preproceso pp
                INNER JOIN caja ca ON ca.id_caja = pp.id_caja
                INNER JOIN agencia a ON a.id_agencia = ca.id_agencia
                WHERE pp.codigo IN ({$codigoList})
                  AND pp.estado = 0
            ");
            foreach ($preprocesos as $pp) {
                $preprocesoMap[$pp['codigo']] = $pp;
            }
        }

        $agenciaCache = [];

        return array_map(function ($r) use ($pagoMap, $preprocesoMap, &$agenciaCache) {
            $codigo = $r['codigo'];
            $estadoInt = (int)$r['estado'];

            $destino = $r['abr_des'] ?? '';
            $fechaPago = null;

            if ($estadoInt === 1 && isset($pagoMap[$codigo])) {
                $destino = $pagoMap[$codigo]['agencia_abr'];
                $fechaPago = $pagoMap[$codigo]['fec_crea_pago'];
            } elseif ($estadoInt === 7 && isset($preprocesoMap[$codigo])) {
                $destino = $preprocesoMap[$codigo]['agencia_abr'];
            }

            $moneda = $r['moneda'] ?? 'BOB';
            $envio = (float)($r['envio'] ?? 0);
            $montoBob = $moneda === 'BOB' ? $envio : 0;
            $montoUsd = $moneda === 'USD' ? $envio : 0;

            return [
                'correlativoPais' => $r['correlativo_pais'] ?? '',
                'codigo'          => $codigo,
                'fecha'           => $r['fec_crea_remesa'] ?? '',
                'origen'          => $r['abr_ori'] ?? '',
                'destino'         => $destino,
                'fechaPago'       => $fechaPago,
                'estado'          => $estadoInt,
                'estadoDesc'      => self::ESTADO_CGR[$estadoInt] ?? "Estado {$estadoInt}",
                'montoBob'        => round($montoBob, 2),
                'montoUsd'        => round($montoUsd, 2),
                'moneda'          => $moneda,
                'remitente'       => $r['usuario_financiero'] ?? '',
                'destinatario'    => $r['destinatario'] ?? '',
            ];
        }, $chileRows);
    }
}
