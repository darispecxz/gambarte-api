<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;

class CajeroCotizacionProgramadaService
{
    public function listar(int $idAgenciaUsuario, int $rol, ?string $estado = null, ?string $busqueda = null, int $pagina = 1, int $porPagina = 10): array
    {
        $params = [];
        $where = [];
        if (!in_array($rol, [1, 2], true)) {
            $where[] = 'cp.id_agencia = :agencia';
            $params[':agencia'] = $idAgenciaUsuario;
        }
        if ($estado && in_array($estado, ['P', 'A', 'I'], true)) {
            $where[] = 'cp.estado = :estado';
            $params[':estado'] = $estado;
        }
        if ($busqueda) {
            $where[] = '(CAST(cp.id_cotizacion AS CHAR) LIKE :busqueda OR a.descripcion LIKE :busqueda OR cp.usuario_registro LIKE :busqueda)';
            $params[':busqueda'] = '%' . $busqueda . '%';
        }
        $condition = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $pagina = max(1, $pagina);
        $porPagina = in_array($porPagina, [5, 10, 25, 50], true) ? $porPagina : 10;
        $offset = ($pagina - 1) * $porPagina;
        $total = (int) DB::scalar("SELECT COUNT(*)
                                   FROM cotizacion_programada cp
                                   INNER JOIN agencia a ON a.id_agencia = cp.id_agencia
                                   {$condition}", $params);
        $registros = array_map(function (array $row): array {
            $row['id_cotizacion'] = (int) $row['id_cotizacion'];
            $row['id_agencia'] = (int) $row['id_agencia'];
            $row['total_monedas'] = (int) $row['total_monedas'];
            $row['estado_texto'] = ['P' => 'Pendiente', 'A' => 'Aplicada', 'I' => 'Error'][$row['estado']] ?? 'Desconocido';
            return $row;
        }, DB::select("SELECT cp.id_cotizacion, cp.id_agencia, a.descripcion AS agencia,
                              a.abreviatura, cp.fecha_aplicacion, cp.hora_aplicacion,
                              cp.estado, cp.observaciones, cp.usuario_registro,
                              cp.fec_creacion, cp.fec_modificacion,
                              (SELECT COUNT(*) FROM cotizacion_programada_detalle cpd
                               WHERE cpd.id_cotizacion = cp.id_cotizacion) AS total_monedas
                       FROM cotizacion_programada cp
                       INNER JOIN agencia a ON a.id_agencia = cp.id_agencia
                       {$condition}
                       ORDER BY cp.fecha_aplicacion DESC, cp.hora_aplicacion DESC, cp.fec_creacion DESC
                       LIMIT {$porPagina} OFFSET {$offset}", $params));
        return [
            'registros' => $registros,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
        ];
    }

    public function catalogo(int $idAgencia): array
    {
        $rows = DB::select("SELECT m.id_moneda, m.abreviatura AS moneda, m.descripcion AS moneda_desc, m.bandera,
                                  COALESCE((SELECT cb.valor_base FROM cotizacion_base cb
                                            WHERE cb.id_agencia = :ag_compra AND cb.id_moneda = m.id_moneda
                                              AND cb.id_operacion = 1
                                            ORDER BY cb.fecha_creacion DESC LIMIT 1), 0) AS compra_actual,
                                  COALESCE((SELECT cb.valor_base FROM cotizacion_base cb
                                            WHERE cb.id_agencia = :ag_venta AND cb.id_moneda = m.id_moneda
                                              AND cb.id_operacion = 2
                                            ORDER BY cb.fecha_creacion DESC LIMIT 1), 0) AS venta_actual
                           FROM moneda m WHERE m.id_moneda <> 2 ORDER BY m.descripcion", [
            ':ag_compra' => $idAgencia,
            ':ag_venta' => $idAgencia,
        ]);
        return array_map(function (array $row): array {
            $row['id_moneda'] = (int) $row['id_moneda'];
            $row['compra_actual'] = (float) $row['compra_actual'];
            $row['venta_actual'] = (float) $row['venta_actual'];
            return $row;
        }, $rows);
    }

    public function detalle(int $id, int $idAgenciaUsuario, int $rol): array
    {
        $params = [':id' => $id];
        $scope = '';
        if (!in_array($rol, [1, 2], true)) {
            $scope = ' AND cp.id_agencia = :agencia';
            $params[':agencia'] = $idAgenciaUsuario;
        }
        $cabecera = DB::selectOne("SELECT cp.id_cotizacion, cp.id_agencia, a.descripcion AS agencia,
                                          cp.fecha_aplicacion, cp.hora_aplicacion, cp.estado,
                                          cp.observaciones, cp.usuario_registro, cp.fec_creacion,
                                          cp.fec_modificacion
                                   FROM cotizacion_programada cp
                                   INNER JOIN agencia a ON a.id_agencia = cp.id_agencia
                                   WHERE cp.id_cotizacion = :id {$scope}", $params);
        if (!$cabecera) throw new \RuntimeException('Cotización programada no encontrada.');
        $detalles = DB::select("SELECT cpd.id_moneda, m.abreviatura AS moneda,
                                       m.descripcion AS moneda_desc, m.bandera,
                                       cpd.compra, cpd.venta, cpd.diferencial
                                FROM cotizacion_programada_detalle cpd
                                INNER JOIN moneda m ON m.id_moneda = cpd.id_moneda
                                WHERE cpd.id_cotizacion = :id ORDER BY m.descripcion", [':id' => $id]);
        $cabecera['id_cotizacion'] = (int) $cabecera['id_cotizacion'];
        $cabecera['id_agencia'] = (int) $cabecera['id_agencia'];
        $cabecera['estado_texto'] = ['P' => 'Pendiente', 'A' => 'Aplicada', 'I' => 'Error'][$cabecera['estado']] ?? 'Desconocido';
        $cabecera['total_monedas'] = count($detalles);
        foreach ($detalles as &$detalle) {
            $detalle['id_moneda'] = (int) $detalle['id_moneda'];
            foreach (['compra', 'venta', 'diferencial'] as $key) $detalle[$key] = (float) $detalle[$key];
        }
        return ['cabecera' => $cabecera, 'detalles' => $detalles];
    }

    public function crear(array $input, int $idAgenciaUsuario, int $rol, int $codUsuario, string $login): int
    {
        $idAgencia = (int) ($input['id_agencia'] ?? $idAgenciaUsuario);
        if (!in_array($rol, [1, 2], true) && $idAgencia !== $idAgenciaUsuario) {
            throw new \RuntimeException('No puede programar cotizaciones para otra agencia.');
        }
        if ($idAgencia <= 0) throw new \InvalidArgumentException('La agencia es obligatoria.');
        [$fecha, $hora] = $this->siguienteHorarioPublicacion();
        $detalles = $input['cotizaciones'] ?? [];
        if (!is_array($detalles) || !$detalles) throw new \InvalidArgumentException('Debe registrar al menos una moneda.');

        $validos = [];
        foreach ($detalles as $detalle) {
            $moneda = (int) ($detalle['id_moneda'] ?? 0);
            $compra = $this->amount($detalle['compra'] ?? null, 'Compra');
            $venta = $this->amount($detalle['venta'] ?? null, 'Venta');
            if ($moneda <= 0 || $moneda === 2) throw new \InvalidArgumentException('La moneda seleccionada no es válida.');
            if ($venta < $compra) throw new \InvalidArgumentException('La venta no puede ser menor que la compra.');
            $validos[$moneda] = ['compra' => $compra, 'venta' => $venta, 'diferencial' => round($venta - $compra, 5)];
        }

        return DB::transaction(function () use ($idAgencia, $fecha, $hora, $input, $login, $validos) {
            DB::execute("INSERT INTO cotizacion_programada
                         (id_agencia, fecha_aplicacion, hora_aplicacion, estado, observaciones, usuario_registro, fec_creacion)
                         VALUES (:agencia, :fecha, :hora, 'P', :observaciones, :usuario, NOW())", [
                ':agencia' => $idAgencia,
                ':fecha' => $fecha,
                ':hora' => $hora,
                ':observaciones' => trim((string) ($input['observaciones'] ?? '')),
                ':usuario' => $login,
            ]);
            $id = DB::lastInsertId();
            foreach ($validos as $idMoneda => $detalle) {
                DB::execute("INSERT INTO cotizacion_programada_detalle
                             (id_cotizacion, id_moneda, compra, venta, diferencial)
                             VALUES (:id, :moneda, :compra, :venta, :diferencial)", [
                    ':id' => $id, ':moneda' => $idMoneda, ':compra' => $detalle['compra'],
                    ':venta' => $detalle['venta'], ':diferencial' => $detalle['diferencial'],
                ]);
            }
            return $id;
        });
    }

    public function pdf(int $id, int $idAgenciaUsuario, int $rol): array
    {
        $data = $this->detalle($id, $idAgenciaUsuario, $rol);
        $cabecera = $data['cabecera'];
        $detalles = $data['detalles'];
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $n = static fn ($value): string => number_format((float) $value, 5, '.', ',');
        $estado = ['P' => 'Pendiente', 'A' => 'Aplicada', 'I' => 'Error'][$cabecera['estado']] ?? 'Desconocido';
        $logoPath = public_path('images/gambarte-logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath)) : '';
        $filas = '';
        foreach ($detalles as $detalle) {
            $filas .= '<tr><td><b>' . $e($detalle['moneda']) . '</b><br><span>' . $e($detalle['moneda_desc']) . '</span></td>'
                . '<td class="number">' . $n($detalle['compra']) . '</td>'
                . '<td class="number">' . $n($detalle['venta']) . '</td>'
                . '<td class="number spread">' . $n($detalle['diferencial']) . '</td></tr>';
        }
        $observaciones = trim((string) ($cabecera['observaciones'] ?? ''));
        $html = '<html><head><meta charset="utf-8"><style>
            @page{size:letter portrait;margin:14mm}*{box-sizing:border-box}body{font-family:DejaVu Sans,Arial,sans-serif;color:#172b4d;font-size:10px;margin:0}
            .head{width:100%;border-collapse:collapse;margin-bottom:18px}.head td{border:0;vertical-align:middle}.logo{width:190px}.title{text-align:right}.title h1{font-size:18px;margin:0 0 4px}.muted{color:#7890aa}
            .summary{background:#f5f7fa;border:1px solid #dfe7ef;border-radius:8px;padding:12px;margin-bottom:16px}.summary table{width:100%;border-collapse:collapse}.summary td{width:50%;padding:4px 6px}.label{font-size:8px;text-transform:uppercase;color:#7890aa;font-weight:bold;display:block;margin-bottom:2px}
            table.rates{width:100%;border-collapse:collapse}.rates th{background:#172b4d;color:white;text-align:left;padding:8px}.rates td{border-bottom:1px solid #dfe7ef;padding:8px}.rates span{font-size:8px;color:#7890aa}.rates .number{text-align:right;font-family:DejaVu Sans Mono,monospace}.spread{color:#bd5d13;font-weight:bold}
            .notes{margin-top:16px;padding:10px 12px;background:#fff7e8;border-left:3px solid #f58634}.footer{margin-top:22px;border-top:1px solid #dfe7ef;padding-top:8px;color:#7890aa;font-size:8px;text-align:center}
        </style></head><body><table class="head"><tr><td>' . ($logo !== '' ? '<img class="logo" src="' . $logo . '">' : '<b>GAMBARTE</b>') . '</td><td class="title"><h1>Cotización programada #' . (int) $cabecera['id_cotizacion'] . '</h1><div class="muted">Detalle de tasas de compra y venta</div></td></tr></table>
        <div class="summary"><table><tr><td><span class="label">Agencia</span><b>' . $e($cabecera['agencia']) . '</b></td><td><span class="label">Estado</span><b>' . $e($estado) . '</b></td></tr><tr><td><span class="label">Fecha y hora de aplicación</span><b>' . $e($cabecera['fecha_aplicacion']) . ' ' . $e(substr((string) $cabecera['hora_aplicacion'], 0, 5)) . '</b></td><td><span class="label">Registrada por</span><b>' . $e($cabecera['usuario_registro']) . '</b></td></tr></table></div>
        <table class="rates"><thead><tr><th>Moneda</th><th style="text-align:right">Compra (BOB)</th><th style="text-align:right">Venta (BOB)</th><th style="text-align:right">Diferencial</th></tr></thead><tbody>' . $filas . '</tbody></table>'
        . ($observaciones !== '' ? '<div class="notes"><b>Observaciones</b><br>' . nl2br($e($observaciones)) . '</div>' : '') .
        '<div class="footer">Generado el ' . $e((new \DateTimeImmutable('now', new \DateTimeZone('America/La_Paz')))->format('d/m/Y H:i:s')) . ' · GAMBARTE BOLIVIA SRL</div></body></html>';

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return [
            'binary' => $dompdf->output(),
            'filename' => 'Cotizacion_Programada_' . (int) $cabecera['id_cotizacion'] . '.pdf',
        ];
    }

    private function amount($value, string $label): float
    {
        if (!is_numeric($value) || (float) $value <= 0) throw new \InvalidArgumentException("La tasa de {$label} debe ser mayor a cero.");
        return round((float) $value, 5);
    }

    /** Replica la regla del legacy: 10:30, 15:00 o 10:30 del día siguiente. */
    private function siguienteHorarioPublicacion(): array
    {
        $ahora = new \DateTimeImmutable('now', new \DateTimeZone('America/La_Paz'));
        $minutos = ((int) $ahora->format('H') * 60) + (int) $ahora->format('i');
        if ($minutos < 10 * 60 + 30) {
            return [$ahora->format('Y-m-d'), '10:30:00'];
        }
        if ($minutos < 15 * 60) {
            return [$ahora->format('Y-m-d'), '15:00:00'];
        }
        return [$ahora->modify('+1 day')->format('Y-m-d'), '10:30:00'];
    }
}
