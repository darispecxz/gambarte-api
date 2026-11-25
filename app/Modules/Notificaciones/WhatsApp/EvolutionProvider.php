<?php
namespace App\Modules\Notificaciones\WhatsApp;

use Illuminate\Support\Facades\Http;

final class EvolutionProvider implements WhatsAppProvider
{
    public function send(string $phone, array $pdf, int $agency): string
    {
        $instance = config('whatsapp.agency_instances')[$agency] ?? config('whatsapp.instance');
        $key = config('whatsapp.key');
        if (!$instance || !$key) {
            throw new \RuntimeException('Proveedor no configurado.');
        }
        $response = Http::withHeaders(['apikey' => $key])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(5)->timeout(25)
            ->post(rtrim(config('whatsapp.url'), '/') . '/message/sendMedia/' . rawurlencode($instance), [
                'number' => $phone,
                'mediatype' => 'document',
                'mimetype' => 'application/pdf',
                'media' => base64_encode($pdf['binary']),
                'fileName' => basename($pdf['filename']),
                'caption' => 'Gambarte Bolivia S.R.L. — Adjuntamos su documento. Gracias por su preferencia.',
            ]);
        if (!$response->successful() || !$response->json('key.id')) {
            throw new \RuntimeException('No se pudo confirmar la aceptación del proveedor.');
        }
        return (string) $response->json('key.id');
    }
}
