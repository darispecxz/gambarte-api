<?php
namespace App\Modules\Notificaciones\WhatsApp;

use App\Modules\Shared\Database\RawConnection as DB;

final class NotificationService
{
    public function __construct(private DocumentResolver $documents, private WhatsAppProvider $provider) {}

    public static function enabled(): bool
    {
        return (bool) config('whatsapp.enabled') && (!app()->environment('production') || config('whatsapp.allow_production'));
    }

    public function preview(string $type, int $id, int $agency): array
    {
        abort_unless(self::enabled(), 503, 'WhatsApp no habilitado.');
        $phone = $this->documents->recipient($type, $id, $agency);
        $test = (string) config('whatsapp.test_recipient');
        if (!config('whatsapp.allow_production')) {
            abort_if($test === '', 503, 'Configure un destinatario de pruebas.');
            $phone = DocumentResolver::normalize($test);
        }
        return ['phone' => $phone, 'test_mode' => !config('whatsapp.allow_production')];
    }

    public function send(string $type, int $id, int $agency, int $user, string $confirmedPhone): array
    {
        $phone = $this->preview($type, $id, $agency)['phone'];
        abort_unless(hash_equals($phone, $confirmedPhone), 409, 'El teléfono cambió. Confirme nuevamente.');
        $pdf = $this->documents->pdf($type, $id, $agency);
        // One dispatch per document and destination; no automatic retries after uncertain delivery.
        $key = hash('sha256', implode('|', [$agency,$type,$id,$phone]));
        $inserted = DB::execute("INSERT INTO pos_whatsapp_dispatch
            (dedup_key,document_type,operation_id,agency_id,user_id,phone_last4,status,created_at,updated_at)
            VALUES (:key,:type,:id,:ag,:usr,:last4,'PROCESSING',NOW(),NOW())
            ON DUPLICATE KEY UPDATE dedup_key=dedup_key", [
            ':key'=>$key, ':type'=>$type, ':id'=>$id, ':ag'=>$agency, ':usr'=>$user, ':last4'=>substr($phone,-4),
        ]);
        if (!$inserted) {
            $row = DB::selectOne('SELECT status,provider_message_id FROM pos_whatsapp_dispatch WHERE dedup_key=:key', [':key'=>$key]);
            return ['status'=>$row['status'], 'duplicate'=>true];
        }
        try {
            $messageId = $this->provider->send($phone,$pdf,$agency);
        } catch (\Throwable $e) {
            DB::execute("UPDATE pos_whatsapp_dispatch SET status='UNKNOWN',updated_at=NOW() WHERE dedup_key=:key", [':key'=>$key]);
            return ['status'=>'UNKNOWN','duplicate'=>false];
        }
        DB::execute("UPDATE pos_whatsapp_dispatch SET status='ACCEPTED',provider_message_id=:msg,updated_at=NOW() WHERE dedup_key=:key", [':msg'=>$messageId, ':key'=>$key]);
        return ['status'=>'ACCEPTED','duplicate'=>false];
    }
}
