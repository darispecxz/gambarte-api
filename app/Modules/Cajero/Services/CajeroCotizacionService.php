<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;

class CajeroCotizacionService
{
    private const ADMIN_ROLES = [1, 2];

    public function dashboard(int $idAgenciaUsuario, int $rol, ?int $idAgencia = null): array
    {
        $agencia = $this->resolveAgency($idAgenciaUsuario, $rol, $idAgencia);
        $params = [];
        $where = '';
        if ($agencia !== null) {
            $where = ' AND c.id_agencia = :agencia';
            $params[':agencia'] = $agencia;
        }

        $tasas = DB::select(" 
            SELECT c.id_agencia, a.descripcion AS agencia, c.id_moneda,
                   m.abreviatura AS moneda, m.descripcion AS moneda_desc,
                   MAX(CASE WHEN c.id_operacion = 1 THEN c.valor END) AS compra_bob,
                   MAX(CASE WHEN c.id_operacion = 2 THEN c.valor END) AS venta_bob,
                   MAX(CASE WHEN c.id_operacion = 1 THEN c.valor_me END) AS compra_usd,
                   MAX(CASE WHEN c.id_operacion = 2 THEN c.valor_me END) AS venta_usd,
                   MAX(c.fec_mod_cotizacion) AS actualizada,
                   COALESCE((SELECT cb.valor_base FROM cotizacion_base cb
                             WHERE cb.id_agencia = c.id_agencia AND cb.id_moneda = c.id_moneda
                               AND cb.id_operacion = 1 ORDER BY cb.fecha_creacion DESC LIMIT 1), 0) AS valor_base_compra,
                   COALESCE((SELECT cb.valor_base FROM cotizacion_base cb
                             WHERE cb.id_agencia = c.id_agencia AND cb.id_moneda = c.id_moneda
                               AND cb.id_operacion = 2 ORDER BY cb.fecha_creacion DESC LIMIT 1), 0) AS valor_base_venta
            FROM cotizacion c
            INNER JOIN moneda m ON m.id_moneda = c.id_moneda
            INNER JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE c.estado = 'A' AND c.id_operacion IN (1, 2) AND c.id_moneda <> 2 {$where}
            GROUP BY c.id_agencia, a.descripcion, c.id_moneda, m.abreviatura, m.descripcion
            ORDER BY c.id_agencia, c.id_moneda
        ", $params);

        $auditoriaParams = [];
        $auditoriaWhere = '';
        if ($agencia !== null) {
            $auditoriaWhere = ' AND cb.id_agencia = :aud_agencia';
            $auditoriaParams[':aud_agencia'] = $agencia;
        }
        $auditoria = DB::select(" 
            SELECT cb.id_moneda, cb.id_operacion, cb.id_agencia,
                   m.abreviatura AS moneda, a.descripcion AS agencia,
                   cb.valor_base, cb.fecha_creacion,
                   COALESCE(u.login_usu, CONCAT('Usuario #', cb.user_id)) AS usuario
            FROM cotizacion_base cb
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            INNER JOIN agencia a ON a.id_agencia = cb.id_agencia
            LEFT JOIN usuario u ON u.cod_usuario = cb.user_id
            WHERE cb.id_operacion IN (1, 2) {$auditoriaWhere}
            ORDER BY cb.fecha_creacion DESC
            LIMIT 12
        ", $auditoriaParams);

        $agencias = in_array($rol, self::ADMIN_ROLES, true)
            ? DB::select("SELECT id_agencia, descripcion AS agencia FROM agencia WHERE id_agencia <> 99 ORDER BY descripcion")
            : DB::select("SELECT id_agencia, descripcion AS agencia FROM agencia WHERE id_agencia = :ag", [':ag' => $idAgenciaUsuario]);

        $latest = null;
        foreach ($tasas as $tasa) {
            if ($latest === null || ($tasa['actualizada'] ?? '') > $latest) $latest = $tasa['actualizada'];
        }

        return [
            'tasas' => array_map(fn (array $r) => $this->normalizeRate($r), $tasas),
            'auditoria' => array_map(fn (array $r) => $this->normalizeAudit($r), $auditoria),
            'agencias' => $agencias,
            'resumen' => [
                'monedas_activas' => count(array_unique(array_map(fn ($r) => $r['id_moneda'], $tasas))),
                'ultima_actualizacion' => $latest,
                'cambios_hoy' => $this->countToday($agencia),
                'alertas' => $this->countAlerts($tasas),
            ],
            'agencia_seleccionada' => $agencia,
        ];
    }

    public function update(array $input, int $idAgenciaUsuario, int $rol, int $codUsuario): array
    {
        $idAgencia = (int) ($input['id_agencia'] ?? 0);
        $todasAgencias = filter_var($input['todas_agencias'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $idMoneda = (int) ($input['id_moneda'] ?? 0);
        $compra = $this->rate($input['compra_bob'] ?? null, 'Compra');
        $venta = $this->rate($input['venta_bob'] ?? null, 'Venta');

        if (!$todasAgencias && $idAgencia <= 0) throw new \InvalidArgumentException('Agencia y moneda son obligatorias.');
        if ($todasAgencias && !in_array($rol, self::ADMIN_ROLES, true)) throw new \RuntimeException('Solo un administrador puede publicar en todas las agencias.');
        if ($idMoneda === 2) throw new \InvalidArgumentException('La moneda nacional no se administra como divisa.');
        if ($venta < $compra) throw new \InvalidArgumentException('La tasa de venta no puede ser menor que la tasa de compra.');
        if (!$todasAgencias && !in_array($rol, self::ADMIN_ROLES, true) && $idAgencia !== $idAgenciaUsuario) {
            throw new \RuntimeException('No puede modificar cotizaciones de otra agencia.');
        }

        $moneda = DB::selectOne('SELECT id_moneda, abreviatura FROM moneda WHERE id_moneda = :m', [':m' => $idMoneda]);
        if (!$moneda) throw new \InvalidArgumentException('La moneda seleccionada no existe.');

        $agenciasObjetivo = $todasAgencias
            ? array_map(fn ($row) => (int) $row['id_agencia'], DB::select("SELECT id_agencia FROM agencia WHERE id_agencia <> 99 ORDER BY id_agencia"))
            : [$idAgencia];

        if (!in_array($rol, self::ADMIN_ROLES, true)) {
            $bases = DB::select("SELECT id_operacion, valor_base FROM cotizacion_base
                                WHERE id_agencia = :ag AND id_moneda = :m AND id_operacion IN (1,2)
                                ORDER BY fecha_creacion DESC", [':ag' => $idAgencia, ':m' => $idMoneda]);
            foreach ([1 => $compra, 2 => $venta] as $op => $value) {
                $base = $this->latestBase($bases, $op);
                if ($base > 0 && abs($value - $base) > max(abs($base) * 0.03, 0.000001)) {
                    throw new \InvalidArgumentException("La tasa de " . ($op === 1 ? 'compra' : 'venta') . ' excede el margen permitido de 3%.');
                }
            }
        }

        return DB::transaction(function () use ($agenciasObjetivo, $idMoneda, $compra, $venta, $codUsuario, $rol) {
            foreach ($agenciasObjetivo as $agencia) {
                $this->publishForAgency($agencia, $idMoneda, $compra, $venta, $codUsuario);
            }
            return $this->dashboard(0, $rol, count($agenciasObjetivo) === 1 ? $agenciasObjetivo[0] : null);
        });
    }

    private function publishForAgency(int $idAgencia, int $idMoneda, float $compra, float $venta, int $codUsuario): void
    {
            $usdCompra = $idMoneda === 1 ? $compra : (float) (DB::scalar(
                "SELECT valor FROM cotizacion WHERE id_agencia = :ag AND id_moneda = 1 AND id_operacion = 1 AND estado = 'A' LIMIT 1",
                [':ag' => $idAgencia]
            ) ?: 1);
            $usdVenta = $idMoneda === 1 ? $venta : (float) (DB::scalar(
                "SELECT valor FROM cotizacion WHERE id_agencia = :ag AND id_moneda = 1 AND id_operacion = 2 AND estado = 'A' LIMIT 1",
                [':ag' => $idAgencia]
            ) ?: 1);

            $this->upsertRate($idMoneda, 1, $idAgencia, $compra, $idMoneda === 1 ? 1 : $compra / $usdCompra);
            $this->upsertRate($idMoneda, 2, $idAgencia, $venta, $idMoneda === 1 ? 1 : $venta / $usdVenta);
            DB::execute('INSERT INTO cotizacion_base (id_moneda, id_operacion, id_agencia, valor_base, user_id) VALUES (:m, 1, :ag, :v, :u), (:m, 2, :ag, :v2, :u)', [
                ':m' => $idMoneda, ':ag' => $idAgencia, ':v' => $compra, ':v2' => $venta, ':u' => $codUsuario,
            ]);

            if ($idMoneda === 1) {
                DB::execute("UPDATE cotizacion SET valor_me = valor / :compra, fec_mod_cotizacion = NOW()
                             WHERE id_agencia = :ag AND id_moneda <> 1 AND id_operacion = 1 AND estado = 'A'", [':compra' => $compra, ':ag' => $idAgencia]);
                DB::execute("UPDATE cotizacion SET valor_me = valor / :venta, fec_mod_cotizacion = NOW()
                             WHERE id_agencia = :ag AND id_moneda <> 1 AND id_operacion = 2 AND estado = 'A'", [':venta' => $venta, ':ag' => $idAgencia]);
            }
    }

    private function upsertRate(int $moneda, int $operacion, int $agencia, float $valor, float $valorMe): void
    {
        $exists = DB::scalar("SELECT COUNT(*) FROM cotizacion WHERE id_moneda = :m AND id_operacion = :op AND id_agencia = :ag", [':m' => $moneda, ':op' => $operacion, ':ag' => $agencia]);
        $params = [':v' => $valor, ':me' => $valorMe, ':m' => $moneda, ':op' => $operacion, ':ag' => $agencia];
        if ((int) $exists > 0) {
            DB::execute("UPDATE cotizacion SET valor = :v, valor_me = :me, fec_mod_cotizacion = NOW(), estado = 'A' WHERE id_moneda = :m AND id_operacion = :op AND id_agencia = :ag", $params);
        } else {
            DB::execute("INSERT INTO cotizacion (id_moneda, id_operacion, id_agencia, valor, valor_me, fec_crea_cotizacion, fec_mod_cotizacion, estado) VALUES (:m, :op, :ag, :v, :me, NOW(), NULL, 'A')", $params);
        }
    }

    private function resolveAgency(int $userAgency, int $rol, ?int $requested): ?int
    {
        return in_array($rol, self::ADMIN_ROLES, true) ? ($requested ?: null) : $userAgency;
    }

    private function rate($value, string $label): float
    {
        if (!is_numeric($value) || (float) $value <= 0) throw new \InvalidArgumentException("La tasa de {$label} debe ser mayor a cero.");
        $value = (float) $value;
        if (strlen(substr(strrchr(rtrim(sprintf('%.8f', $value), '0'), '.'), 1)) > 5) throw new \InvalidArgumentException("La tasa de {$label} admite máximo 5 decimales.");
        return round($value, 5);
    }

    private function latestBase(array $bases, int $op): float
    {
        foreach ($bases as $base) if ((int) $base['id_operacion'] === $op) return (float) $base['valor_base'];
        return 0.0;
    }

    private function normalizeRate(array $r): array
    {
        foreach (['compra_bob','venta_bob','compra_usd','venta_usd','valor_base_compra','valor_base_venta'] as $k) $r[$k] = $r[$k] === null ? null : (float) $r[$k];
        $r['id_agencia'] = (int) $r['id_agencia']; $r['id_moneda'] = (int) $r['id_moneda'];
        $r['estado'] = ((float) ($r['compra_bob'] ?? 0) > 0 && (float) ($r['venta_bob'] ?? 0) > 0) ? 'activa' : 'incompleta';
        return $r;
    }

    private function normalizeAudit(array $r): array
    {
        $r['id_moneda'] = (int) $r['id_moneda']; $r['id_agencia'] = (int) $r['id_agencia'];
        $r['id_operacion'] = (int) $r['id_operacion']; $r['valor_base'] = (float) $r['valor_base'];
        $r['operacion'] = $r['id_operacion'] === 1 ? 'compra' : 'venta';
        return $r;
    }

    private function countToday(?int $agencia): int
    {
        $where = $agencia === null ? '' : ' AND id_agencia = :ag';
        return (int) DB::scalar("SELECT COUNT(*) FROM cotizacion_base WHERE DATE(fecha_creacion) = CURDATE() {$where}", $agencia === null ? [] : [':ag' => $agencia]);
    }

    private function countAlerts(array $tasas): int
    {
        return count(array_filter($tasas, fn ($r) => (float) $r['valor_base_compra'] > 0 && abs((float) $r['compra_bob'] - (float) $r['valor_base_compra']) > (float) $r['valor_base_compra'] * 0.03));
    }
}
