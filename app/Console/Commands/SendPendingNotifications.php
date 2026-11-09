<?php

namespace App\Console\Commands;

use App\Modules\Shared\Database\RawConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendPendingNotifications extends Command
{
    protected $signature = 'notifications:send {--limit=20}';
    protected $description = 'Procesar y enviar notificaciones pendientes de pos_notificacion_operacion';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $pendientes = RawConnection::select("
            SELECT id, canal, destinatario, tipo_operacion, id_referencia, asunto, contenido, intentos
            FROM pos_notificacion_operacion
            WHERE estado = 'pendiente' AND intentos < 3
            ORDER BY created_at ASC
            LIMIT {$limit}
        ");

        if (empty($pendientes)) {
            $this->info('Sin notificaciones pendientes.');
            return self::SUCCESS;
        }

        $enviados = 0;
        $errores = 0;

        foreach ($pendientes as $n) {
            if ($n['canal'] !== 'email') {
                continue;
            }

            try {
                $contenido = $n['contenido'];
                $isHtml = stripos($contenido, '<html') !== false || stripos($contenido, '<!DOCTYPE') !== false;
                $logoPath = public_path('images/gambarte-logo.png');
                $hasLogo = $isHtml && strpos($contenido, 'cid:gambarte_logo') !== false && file_exists($logoPath);

                if ($isHtml) {
                    Mail::send([], [], function ($message) use ($n, $contenido, $logoPath, $hasLogo) {
                        $message->to($n['destinatario'])
                                ->subject($n['asunto'] ?? 'Notificación Gambarte');
                        $html = $contenido;
                        if ($hasLogo) {
                            $cid = $message->embed($logoPath);
                            $html = str_replace('cid:gambarte_logo', $cid, $html);
                        }
                        $message->setBody($html, 'text/html');
                    });
                } else {
                    Mail::raw($contenido, function ($message) use ($n) {
                        $message->to($n['destinatario'])
                                ->subject($n['asunto'] ?? 'Notificación Gambarte');
                    });
                }

                RawConnection::execute("
                    UPDATE pos_notificacion_operacion
                    SET estado = 'enviado', sent_at = NOW(), intentos = intentos + 1
                    WHERE id = :id
                ", [':id' => $n['id']]);

                $enviados++;
            } catch (\Throwable $e) {
                RawConnection::execute("
                    UPDATE pos_notificacion_operacion
                    SET intentos = intentos + 1, error_mensaje = :err,
                        estado = CASE WHEN intentos + 1 >= 3 THEN 'error' ELSE 'pendiente' END
                    WHERE id = :id
                ", [':id' => $n['id'], ':err' => mb_substr($e->getMessage(), 0, 500)]);

                $errores++;
                $this->warn("Error enviando #{$n['id']}: {$e->getMessage()}");
            }
        }

        $this->info("Procesadas: {$enviados} enviadas, {$errores} errores.");
        return self::SUCCESS;
    }
}
