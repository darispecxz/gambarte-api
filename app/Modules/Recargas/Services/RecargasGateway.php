<?php

namespace App\Modules\Recargas\Services;

class RecargasGateway
{
    public function __construct(
        private ChileRecargasClient $chile,
        private UatRecargasSimulator $simulator
    ) {}

    public function isSimulated(): bool
    {
        return app()->environment('uat') && config('services.recargas.uat_simulation') === true;
    }

    public function request(string $method, string $path, array $payload = []): array
    {
        return $this->isSimulated()
            ? $this->simulator->request($method, $path, $payload)
            : $this->chile->request($method, $path, $payload);
    }
}
