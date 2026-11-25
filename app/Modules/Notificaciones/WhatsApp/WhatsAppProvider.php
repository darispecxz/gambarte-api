<?php
namespace App\Modules\Notificaciones\WhatsApp;

interface WhatsAppProvider
{
    public function send(string $phone, array $pdf, int $agency): string;
}
