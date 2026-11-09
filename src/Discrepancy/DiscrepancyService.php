<?php

namespace CgrApi\Discrepancy;

class DiscrepancyService
{
    public static function create($data)
    {
        $type     = $data['type'];
        $opId     = isset($data['operation_id']) ? $data['operation_id'] : null;
        $opType   = isset($data['operation_type']) ? $data['operation_type'] : null;

        if ($opId !== null) {
            $exists = DiscrepancyDb::val(
                "SELECT id FROM cgr_discrepancias WHERE type = :t AND operation_id = :oid AND operation_type = :ot AND status IN ('OPEN','IN_REVIEW') LIMIT 1",
                array(':t' => $type, ':oid' => $opId, ':ot' => $opType)
            );
            if ($exists) return null;
        }

        $valueBo   = isset($data['value_cgr_bo']) ? (float) $data['value_cgr_bo'] : null;
        $valueCl   = isset($data['value_cgr_cl']) ? (float) $data['value_cgr_cl'] : null;
        $valueCo   = isset($data['value_conta']) ? (float) $data['value_conta'] : null;

        $nums = array();
        if ($valueBo !== null) $nums[] = $valueBo;
        if ($valueCl !== null) $nums[] = $valueCl;
        if ($valueCo !== null) $nums[] = $valueCo;

        $absDiff = null;
        $pctDiff = null;
        if (count($nums) >= 2) {
            $absDiff = round(abs(max($nums) - min($nums)), 6);
            $minVal  = min(array_map('abs', $nums));
            $pctDiff = $minVal > 0 ? round(($absDiff / $minVal) * 100, 4) : null;
        }

        $severity = self::calcSeverity($type, $absDiff);
        $code     = self::nextCode();

        $detail = isset($data['detail_technical']) ? $data['detail_technical'] : null;

        DiscrepancyDb::exec(
            "INSERT INTO cgr_discrepancias
                (code, type, severity, status, operation_id, operation_type,
                 compared_field, value_cgr_bo, value_cgr_cl, value_conta, unit,
                 description, detail_technical, absolute_difference, percentage_difference,
                 is_auto_detected, requires_audit, detected_by)
             VALUES
                (:code, :type, :sev, 'OPEN', :oid, :ot,
                 :cf, :vbo, :vcl, :vco, :unit,
                 :desc, :det, :adiff, :pdiff,
                 :auto, :audit, :by)",
            array(
                ':code'  => $code,
                ':type'  => $type,
                ':sev'   => $severity,
                ':oid'   => $opId,
                ':ot'    => $opType,
                ':cf'    => isset($data['compared_field']) ? $data['compared_field'] : 'amount',
                ':vbo'   => $valueBo,
                ':vcl'   => $valueCl,
                ':vco'   => $valueCo,
                ':unit'  => isset($data['unit']) ? $data['unit'] : 'BOB',
                ':desc'  => $data['description'],
                ':det'   => $detail !== null ? json_encode($detail) : null,
                ':adiff' => $absDiff,
                ':pdiff' => $pctDiff,
                ':auto'  => 1,
                ':audit' => in_array($type, array(
                    'UNAUTHORIZED_REACTIVATION', 'CHANGE_WITHOUT_LOG',
                    'ANOMALOUS_VALUE', 'MODIFICATION_OFF_HOURS'
                )) ? 1 : 0,
                ':by'    => isset($data['detected_by']) ? $data['detected_by'] : 'RECONCILIATION_ENGINE',
            )
        );

        $id = DiscrepancyDb::lastId();

        DiscrepancyDb::exec(
            "INSERT INTO cgr_discrepancia_logs (discrepancy_id, event, message, is_system) VALUES (:id, 'CREATED', :msg, 1)",
            array(':id' => $id, ':msg' => "Discrepancia {$code} tipo {$type} creada. Severidad: {$severity}.")
        );

        return $id;
    }

    public static function list($filters = array())
    {
        $where  = array('1=1');
        $params = array();

        if (!empty($filters['type'])) {
            $where[]         = 'type = :type';
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['severity'])) {
            $where[]             = 'severity = :sev';
            $params[':sev']      = $filters['severity'];
        }
        if (!empty($filters['status'])) {
            $where[]              = 'status = :status';
            $params[':status']    = $filters['status'];
        }
        if (!empty($filters['operation_type'])) {
            $where[]             = 'operation_type = :ot';
            $params[':ot']       = $filters['operation_type'];
        }
        if (!empty($filters['desde'])) {
            $where[]               = 'created_at >= :desde';
            $params[':desde']      = $filters['desde'] . ' 00:00:00';
        }
        if (!empty($filters['hasta'])) {
            $where[]               = 'created_at <= :hasta';
            $params[':hasta']      = $filters['hasta'] . ' 23:59:59';
        }

        $sql = 'SELECT * FROM cgr_discrepancias WHERE ' . implode(' AND ', $where)
             . ' ORDER BY FIELD(severity, "CRITICAL","HIGH","MEDIUM","LOW","TOLERANCE"), created_at DESC'
             . ' LIMIT 500';

        $rows = DiscrepancyDb::query($sql, $params);

        foreach ($rows as &$r) {
            if (isset($r['detail_technical']) && $r['detail_technical']) {
                $r['detail_technical'] = json_decode($r['detail_technical'], true);
            }
            $r['is_auto_detected'] = (bool) $r['is_auto_detected'];
            $r['requires_audit']   = (bool) $r['requires_audit'];
            $r['is_recurring']     = (bool) $r['is_recurring'];
        }

        return $rows;
    }

    public static function summary($filters = array())
    {
        $where  = array('1=1');
        $params = array();

        if (!empty($filters['desde'])) {
            $where[]          = 'created_at >= :desde';
            $params[':desde'] = $filters['desde'] . ' 00:00:00';
        }
        if (!empty($filters['hasta'])) {
            $where[]          = 'created_at <= :hasta';
            $params[':hasta'] = $filters['hasta'] . ' 23:59:59';
        }

        $w = implode(' AND ', $where);

        $bySeverity = DiscrepancyDb::query(
            "SELECT severity, COUNT(*) as total FROM cgr_discrepancias WHERE {$w} AND status IN ('OPEN','IN_REVIEW') GROUP BY severity",
            $params
        );

        $byType = DiscrepancyDb::query(
            "SELECT type, COUNT(*) as total FROM cgr_discrepancias WHERE {$w} AND status IN ('OPEN','IN_REVIEW') GROUP BY type ORDER BY total DESC",
            $params
        );

        $byStatus = DiscrepancyDb::query(
            "SELECT status, COUNT(*) as total FROM cgr_discrepancias WHERE {$w} GROUP BY status",
            $params
        );

        $totalOpen = DiscrepancyDb::val(
            "SELECT COUNT(*) FROM cgr_discrepancias WHERE {$w} AND status IN ('OPEN','IN_REVIEW')",
            $params
        );

        return array(
            'totalOpen'  => (int) $totalOpen,
            'bySeverity' => $bySeverity,
            'byType'     => $byType,
            'byStatus'   => $byStatus,
        );
    }

    public static function resolve($id, $resolutionType, $notes, $userId)
    {
        $disc = DiscrepancyDb::query("SELECT id, status FROM cgr_discrepancias WHERE id = :id", array(':id' => $id));
        if (empty($disc)) return false;
        if (in_array($disc[0]['status'], array('RESOLVED', 'FALSE_POSITIVE', 'IGNORED'))) return false;

        DiscrepancyDb::exec(
            "UPDATE cgr_discrepancias SET status = 'RESOLVED', resolution_type = :rt, resolution_notes = :notes,
             resolved_by = :uid, resolved_at = NOW(), updated_at = NOW() WHERE id = :id",
            array(':rt' => $resolutionType, ':notes' => $notes, ':uid' => $userId, ':id' => $id)
        );

        DiscrepancyDb::exec(
            "INSERT INTO cgr_discrepancia_logs (discrepancy_id, event, message, user_id) VALUES (:id, 'RESOLVED', :msg, :uid)",
            array(':id' => $id, ':msg' => "Resuelto: {$resolutionType}. {$notes}", ':uid' => $userId)
        );

        return true;
    }

    private static function calcSeverity($type, $absDiff)
    {
        $criticalTypes = array(
            'UNAUTHORIZED_REACTIVATION', 'GHOST_OPERATION',
            'UIF_LIMIT_EXCEEDED', 'DUPLICATE_SAME_SYSTEM'
        );
        if (in_array($type, $criticalTypes)) return 'CRITICAL';

        if ($absDiff === null) return 'MEDIUM';
        if ($absDiff >= 10000) return 'CRITICAL';
        if ($absDiff >= 1000) return 'HIGH';
        if ($absDiff >= 100) return 'MEDIUM';
        if ($absDiff >= 1) return 'LOW';
        return 'TOLERANCE';
    }

    private static function nextCode()
    {
        $year = date('Y');
        $last = DiscrepancyDb::val(
            "SELECT MAX(CAST(SUBSTRING(code, LENGTH(:prefix) + 1) AS UNSIGNED)) FROM cgr_discrepancias WHERE code LIKE :like",
            array(':prefix' => "DISC-{$year}-", ':like' => "DISC-{$year}-%")
        );
        $next = ($last !== null ? (int) $last : 0) + 1;
        return "DISC-{$year}-" . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
