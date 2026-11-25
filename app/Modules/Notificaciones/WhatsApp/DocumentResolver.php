<?php
namespace App\Modules\Notificaciones\WhatsApp;

use App\Modules\Cajero\Services\CajeroCambioService;
use App\Modules\Cajero\Services\CajeroRemesaService;
use App\Modules\Shared\Database\RawConnection as DB;

final class DocumentResolver
{
    public const TYPES = ['FACTURA_CAMBIO', 'FACTURA_REMESA', 'COMPROBANTE_REMESA', 'COMPROBANTE_PAGO_REMESA'];

    public function __construct(private CajeroCambioService $change, private CajeroRemesaService $remittance) {}

    public function recipient(string $type, int $id, int $agency): string
    {
        $params = [':id' => $id, ':ag' => $agency];
        if ($type === 'FACTURA_CAMBIO') {
            $sql = "SELECT uf.celular FROM cambio o JOIN usuario_financiero uf ON uf.id=o.usuario_financiero_id
                WHERE o.id_cambio=:id AND o.id_agencia=:ag AND o.id_operacion IN (1,2)
                AND EXISTS (SELECT 1 FROM pos_operacion_log l WHERE l.tipo_operacion='cambio' AND l.id_referencia=o.id_cambio AND l.id_agencia=o.id_agencia AND l.estado='completado')";
        } elseif (in_array($type, ['FACTURA_REMESA','COMPROBANTE_REMESA'], true)) {
            $sql = "SELECT uf.celular FROM remesa o JOIN usuario_financiero uf ON uf.id=o.usuario_financiero_id
                WHERE o.id_remesa=:id AND o.id_agencia=:ag AND o.estado IN (0,1) AND o.id_operacion IN (4,16)
                AND EXISTS (SELECT 1 FROM pos_operacion_log l WHERE l.tipo_operacion='remesa' AND l.id_referencia=o.id_remesa AND l.id_agencia=o.id_agencia AND l.estado='completado')";
        } elseif ($type === 'COMPROBANTE_PAGO_REMESA') {
            $sql = "SELECT uf.celular FROM pago o JOIN usuario_financiero uf ON uf.num_doc_identidad=o.num_doc_identidad
                AND uf.id_tipo_doc_identidad=o.id_tipo_doc_identidad AND uf.id_expedicion=o.id_expedicion
                WHERE o.id_pago=:id AND o.id_agencia=:ag AND o.id_tipo_pago=1
                AND EXISTS (SELECT 1 FROM pos_operacion_log l WHERE l.tipo_operacion='pago_remesa' AND l.id_referencia=o.id_pago AND l.id_agencia=o.id_agencia AND l.estado='completado')";
        } else {
            abort(422, 'Documento no compatible.');
        }
        $rows = DB::select($sql, $params);
        abort_if(count($rows) !== 1, 404, 'Documento o destinatario no disponible en esta agencia.');
        return self::normalize((string) $rows[0]['celular']);
    }

    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[\s()+-]/', '', trim($phone));
        if (preg_match('/^[67][0-9]{7}$/D', $phone)) $phone = '591' . $phone;
        abort_unless(preg_match('/^591[67][0-9]{7}$/D', $phone), 422, 'Celular boliviano inválido o no registrado.');
        return $phone;
    }

    public function pdf(string $type, int $id, int $agency): array
    {
        if (in_array($type, ['FACTURA_CAMBIO','FACTURA_REMESA'], true)) {
            $ops = $type === 'FACTURA_CAMBIO' ? '1,2' : '4,16';
            $rows = DB::select("SELECT id_agencia FROM facturado WHERE transaccion=:id AND id_operacion IN ($ops)", [':id'=>$id]);
            abort_unless(count($rows) === 1 && (int)$rows[0]['id_agencia'] === $agency, 422, 'Factura inexistente o ambigua.');
        }
        $pdf = match ($type) {
            'FACTURA_CAMBIO' => $this->change->facturaPdf($id),
            'FACTURA_REMESA' => $this->remittance->facturaPdf($id),
            'COMPROBANTE_REMESA' => $this->remittance->comprobantePdf($id),
            'COMPROBANTE_PAGO_REMESA' => $this->remittance->comprobantePagoPdf($id),
            default => abort(422, 'Documento no compatible.'),
        };
        abort_unless(isset($pdf['binary'], $pdf['filename']) && str_starts_with($pdf['binary'], '%PDF-') && strlen($pdf['binary']) <= 10*1024*1024, 422, 'PDF inválido o demasiado grande.');
        return $pdf;
    }
}
