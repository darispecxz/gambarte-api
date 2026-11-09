<?php

namespace App\Modules\Discrepancias\Repositories\Contracts;

interface DiscrepancyRepositoryInterface
{
    public function findById(int $id): ?array;
    public function findExisting(string $type, $opId, $opType): ?int;
    public function insert(array $data): int;
    public function insertLog(int $discrepancyId, string $event, string $message, ?int $userId = null, bool $isSystem = false): void;
    public function resolve(int $id, string $resolutionType, string $notes, int $userId): void;
    public function list(array $where, array $params): array;
    public function summary(string $whereClause, array $params): array;
    public function nextCode(): string;
}
