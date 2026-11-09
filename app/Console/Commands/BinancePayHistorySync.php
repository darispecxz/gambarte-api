<?php

namespace App\Console\Commands;

use App\Modules\Cripto\Services\BinancePayHistoryService;
use Illuminate\Console\Command;

class BinancePayHistorySync extends Command
{
    protected $signature = 'cripto:binance-pay-sync {--from= : Start date/time (UTC), max 90-day interval} {--to= : End date/time (UTC)}';
    protected $description = 'Importar historial de Binance Pay en modo solo lectura';

    public function handle(BinancePayHistoryService $sync): int
    {
        $from = $this->option('from') ? strtotime($this->option('from') . ' UTC') : null;
        $to = $this->option('to') ? strtotime($this->option('to') . ' UTC') : null;
        if (($this->option('from') && !$from) || ($this->option('to') && !$to)) {
            $this->error('Use fechas UTC válidas, por ejemplo --from="2026-10-01 00:00:00" --to="2026-10-02 00:00:00".');
            return self::FAILURE;
        }
        $result = $sync->sync($from ? $from * 1000 : null, $to ? $to * 1000 : null);
        if (!$result['success']) { $this->error($result['message']); return self::FAILURE; }
        $this->info('Historial consultado. Nuevos registros: ' . ($result['data']['inserted'] ?? 0));
        return self::SUCCESS;
    }
}
