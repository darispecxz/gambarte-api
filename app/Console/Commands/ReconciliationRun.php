<?php

namespace App\Console\Commands;

use App\Modules\Discrepancias\Services\ReconciliationEngine;
use Illuminate\Console\Command;

class ReconciliationRun extends Command
{
    protected $signature = 'reconciliation:run';
    protected $description = 'Ejecutar motor de reconciliación para detectar discrepancias contables';

    public function handle(ReconciliationEngine $engine): int
    {
        $start   = microtime(true);
        $created = $engine->run();
        $elapsed = round(microtime(true) - $start, 2);

        $msg = "Reconciliación completada en {$elapsed}s. Nuevas discrepancias: {$created}";
        $this->info($msg);

        return self::SUCCESS;
    }
}
