<?php

namespace App\Providers;

use App\Modules\Discrepancias\Repositories\Contracts\DiscrepancyRepositoryInterface;
use App\Modules\Discrepancias\Repositories\DiscrepancyRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(\App\Modules\Notificaciones\WhatsApp\WhatsAppProvider::class, function () {
            if (config('whatsapp.provider') !== 'evolution') {
                throw new \RuntimeException('Proveedor WhatsApp no compatible.');
            }
            return new \App\Modules\Notificaciones\WhatsApp\EvolutionProvider();
        });
        $this->app->bind(DiscrepancyRepositoryInterface::class, DiscrepancyRepository::class);
    }

    public function boot()
    {
        $modules = [
            'Auth',
            'Riesgos',
            'Auditoria',
            'Contabilidad',
            'Bancos',
            'Operaciones',
            'POS',
            'Discrepancias',
            'Uif',
            'RRHH',
            'GestionOperativa',
            'Notificaciones',
            'Cajero',
            'Cripto',
            'UtilidadCambiaria',
            'Manuales',
            'Recargas',
        ];

        foreach ($modules as $module) {
            $routeFile = app_path("Modules/{$module}/Routes/api.php");
            if (file_exists($routeFile)) {
                Route::prefix('api')
                    ->middleware('api')
                    ->group($routeFile);
            }
        }
    }
}
