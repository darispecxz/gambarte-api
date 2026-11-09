<?php

namespace App\Modules\Discrepancias\Services;

use App\Modules\Discrepancias\Repositories\Contracts\DiscrepancyRepositoryInterface;

class DiscrepancyService
{
    private DiscrepancyRepositoryInterface $repo;

    public function __construct(DiscrepancyRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    public function create(array $data): ?int
    {
        $type   = $data['type'];
        $opId   = $data['operation_id'] ?? null;
        $opType = $data['operation_type'] ?? null;

        if ($opId !== null) {
            $exists = $this->repo->findExisting($type, $opId, $opType);
            if ($exists) return null;
        }

        $valueBo = isset($data['value_cgr_bo']) ? (float) $data['value_cgr_bo'] : null;
        $valueCl = isset($data['value_cgr_cl']) ? (float) $data['value_cgr_cl'] : null;
        $valueCo = isset($data['value_conta'])  ? (float) $data['value_conta']  : null;

        $nums = array_filter([$valueBo, $valueCl, $valueCo], fn($v) => $v !== null);

        $absDiff = null;
        $pctDiff = null;
        if (count($nums) >= 2) {
            $absDiff = round(abs(max($nums) - min($nums)), 6);
            $minVal  = min(array_map('abs', $nums));
            $pctDiff = $minVal > 0 ? round(($absDiff / $minVal) * 100, 4) : null;
        }

        $severity = $this->calcSeverity($type, $absDiff);
        $code     = $this->repo->nextCode();

        $id = $this->repo->insert([
            ':code'  => $code,
            ':type'  => $type,
            ':sev'   => $severity,
            ':oid'   => $opId,
            ':ot'    => $opType,
            ':odate' => $data['operation_date'] ?? null,
            ':cf'    => $data['compared_field'] ?? 'amount',
            ':vbo'   => $valueBo,
            ':vcl'   => $valueCl,
            ':vco'   => $valueCo,
            ':unit'  => $data['unit'] ?? 'BOB',
            ':desc'  => $data['description'],
            ':det'   => isset($data['detail_technical']) ? json_encode($data['detail_technical']) : null,
            ':adiff' => $absDiff,
            ':pdiff' => $pctDiff,
            ':auto'  => 1,
            ':audit' => in_array($type, [
                'UNAUTHORIZED_REACTIVATION', 'CHANGE_WITHOUT_LOG',
                'ANOMALOUS_VALUE', 'MODIFICATION_OFF_HOURS',
            ]) ? 1 : 0,
            ':by' => $data['detected_by'] ?? 'RECONCILIATION_ENGINE',
        ]);

        $this->repo->insertLog($id, 'CREATED', "Discrepancia {$code} tipo {$type} creada. Severidad: {$severity}.", null, true);

        return $id;
    }

    public function list(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['type']))           { $where[] = 'type = :type';             $params[':type'] = $filters['type']; }
        if (!empty($filters['severity']))        { $where[] = 'severity = :sev';          $params[':sev'] = $filters['severity']; }
        if (!empty($filters['status']))          { $where[] = 'status = :status';         $params[':status'] = $filters['status']; }
        if (!empty($filters['operation_type']))  { $where[] = 'operation_type = :ot';     $params[':ot'] = $filters['operation_type']; }
        if (!empty($filters['desde']))           { $where[] = 'created_at >= :desde';     $params[':desde'] = $filters['desde'] . ' 00:00:00'; }
        if (!empty($filters['hasta']))           { $where[] = 'created_at <= :hasta';     $params[':hasta'] = $filters['hasta'] . ' 23:59:59'; }

        $rows = $this->repo->list($where, $params);

        foreach ($rows as &$r) {
            if (!empty($r['detail_technical'])) {
                $r['detail_technical'] = json_decode($r['detail_technical'], true);
            }
            $r['is_auto_detected'] = (bool) $r['is_auto_detected'];
            $r['requires_audit']   = (bool) $r['requires_audit'];
            $r['is_recurring']     = (bool) $r['is_recurring'];
        }

        return $rows;
    }

    public function summary(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['desde'])) { $where[] = 'created_at >= :desde'; $params[':desde'] = $filters['desde'] . ' 00:00:00'; }
        if (!empty($filters['hasta'])) { $where[] = 'created_at <= :hasta'; $params[':hasta'] = $filters['hasta'] . ' 23:59:59'; }

        return $this->repo->summary(implode(' AND ', $where), $params);
    }

    public function resolve(int $id, string $resolutionType, string $notes, int $userId): bool
    {
        $disc = $this->repo->findById($id);
        if (!$disc) return false;
        if (in_array($disc['status'], ['RESOLVED', 'FALSE_POSITIVE', 'IGNORED'])) return false;

        $this->repo->resolve($id, $resolutionType, $notes, $userId);
        $this->repo->insertLog($id, 'RESOLVED', "Resuelto: {$resolutionType}. {$notes}", $userId);

        return true;
    }

    private function calcSeverity(string $type, ?float $absDiff): string
    {
        $criticalTypes = ['UNAUTHORIZED_REACTIVATION', 'GHOST_OPERATION', 'UIF_LIMIT_EXCEEDED', 'DUPLICATE_SAME_SYSTEM'];
        if (in_array($type, $criticalTypes)) return 'CRITICAL';

        if ($absDiff === null) return 'MEDIUM';
        if ($absDiff >= 10000) return 'CRITICAL';
        if ($absDiff >= 1000) return 'HIGH';
        if ($absDiff >= 100) return 'MEDIUM';
        if ($absDiff >= 1) return 'LOW';
        return 'TOLERANCE';
    }
}
