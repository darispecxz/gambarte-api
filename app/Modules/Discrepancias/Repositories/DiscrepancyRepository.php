<?php

namespace App\Modules\Discrepancias\Repositories;

use App\Modules\Discrepancias\Repositories\Contracts\DiscrepancyRepositoryInterface;
use App\Modules\Shared\Database\RawConnection;

class DiscrepancyRepository implements DiscrepancyRepositoryInterface
{
    public function findById(int $id): ?array
    {
        $rows = RawConnection::select("SELECT id, status FROM cgr_discrepancias WHERE id = :id", [':id' => $id]);
        return $rows[0] ?? null;
    }

    public function findExisting(string $type, $opId, $opType): ?int
    {
        $val = RawConnection::scalar(
            "SELECT id FROM cgr_discrepancias WHERE type = :t AND operation_id = :oid AND operation_type = :ot AND status IN ('OPEN','IN_REVIEW') LIMIT 1",
            [':t' => $type, ':oid' => $opId, ':ot' => $opType]
        );
        return $val !== null ? (int) $val : null;
    }

    public function insert(array $data): int
    {
        RawConnection::execute(
            "INSERT INTO cgr_discrepancias
                (code, type, severity, status, operation_id, operation_type, operation_date,
                 compared_field, value_cgr_bo, value_cgr_cl, value_conta, unit,
                 description, detail_technical, absolute_difference, percentage_difference,
                 is_auto_detected, requires_audit, detected_by)
             VALUES
                (:code, :type, :sev, 'OPEN', :oid, :ot, :odate,
                 :cf, :vbo, :vcl, :vco, :unit,
                 :desc, :det, :adiff, :pdiff,
                 :auto, :audit, :by)",
            $data
        );
        return RawConnection::lastInsertId();
    }

    public function insertLog(int $discrepancyId, string $event, string $message, ?int $userId = null, bool $isSystem = false): void
    {
        if ($userId !== null) {
            RawConnection::execute(
                "INSERT INTO cgr_discrepancia_logs (discrepancy_id, event, message, user_id) VALUES (:id, :event, :msg, :uid)",
                [':id' => $discrepancyId, ':event' => $event, ':msg' => $message, ':uid' => $userId]
            );
        } else {
            RawConnection::execute(
                "INSERT INTO cgr_discrepancia_logs (discrepancy_id, event, message, is_system) VALUES (:id, :event, :msg, :sys)",
                [':id' => $discrepancyId, ':event' => $event, ':msg' => $message, ':sys' => $isSystem ? 1 : 0]
            );
        }
    }

    public function resolve(int $id, string $resolutionType, string $notes, int $userId): void
    {
        RawConnection::execute(
            "UPDATE cgr_discrepancias SET status = 'RESOLVED', resolution_type = :rt, resolution_notes = :notes,
             resolved_by = :uid, resolved_at = NOW(), updated_at = NOW() WHERE id = :id",
            [':rt' => $resolutionType, ':notes' => $notes, ':uid' => $userId, ':id' => $id]
        );
    }

    public function list(array $where, array $params): array
    {
        $sql = 'SELECT * FROM cgr_discrepancias WHERE ' . implode(' AND ', $where)
             . ' ORDER BY FIELD(severity, "CRITICAL","HIGH","MEDIUM","LOW","TOLERANCE"), created_at DESC'
             . ' LIMIT 500';
        return RawConnection::select($sql, $params);
    }

    public function summary(string $whereClause, array $params): array
    {
        $bySeverity = RawConnection::select(
            "SELECT severity, COUNT(*) as total FROM cgr_discrepancias WHERE {$whereClause} AND status IN ('OPEN','IN_REVIEW') GROUP BY severity",
            $params
        );
        $byType = RawConnection::select(
            "SELECT type, COUNT(*) as total FROM cgr_discrepancias WHERE {$whereClause} AND status IN ('OPEN','IN_REVIEW') GROUP BY type ORDER BY total DESC",
            $params
        );
        $byStatus = RawConnection::select(
            "SELECT status, COUNT(*) as total FROM cgr_discrepancias WHERE {$whereClause} GROUP BY status",
            $params
        );
        $totalOpen = RawConnection::scalar(
            "SELECT COUNT(*) FROM cgr_discrepancias WHERE {$whereClause} AND status IN ('OPEN','IN_REVIEW')",
            $params
        );

        return [
            'totalOpen'  => (int) $totalOpen,
            'bySeverity' => $bySeverity,
            'byType'     => $byType,
            'byStatus'   => $byStatus,
        ];
    }

    public function nextCode(): string
    {
        $year = date('Y');
        $last = RawConnection::scalar(
            "SELECT MAX(CAST(SUBSTRING(code, LENGTH(:prefix) + 1) AS UNSIGNED)) FROM cgr_discrepancias WHERE code LIKE :like",
            [':prefix' => "DISC-{$year}-", ':like' => "DISC-{$year}-%"]
        );
        $next = ($last !== null ? (int) $last : 0) + 1;
        return "DISC-{$year}-" . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
