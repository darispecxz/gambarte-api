<?php
namespace App\Modules\Notificaciones\WhatsApp;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DocumentNotificationController
{
    public function __construct(private NotificationService $service) {}

    public function availability()
    {
        return response()->json(['success'=>true,'data'=>['enabled'=>NotificationService::enabled()]]);
    }

    public function preview(Request $request)
    {
        $data = $this->validateDocument($request);
        $claims = $request->attributes->get('jwt_claims');
        return response()->json(['success'=>true,'data'=>$this->service->preview($data['type'],(int)$data['id'],(int)$claims['agencia']['id_agencia'])]);
    }

    public function send(Request $request)
    {
        $data = $this->validateDocument($request);
        $request->validate(['phone'=>'required|string|regex:/^591[67][0-9]{7}$/','consent'=>'accepted']);
        $claims = $request->attributes->get('jwt_claims');
        $result = $this->service->send($data['type'],(int)$data['id'],(int)$claims['agencia']['id_agencia'],(int)$claims['user']['cod_usuario'],$request->input('phone'));
        return response()->json(['success'=>true,'data'=>$result]);
    }

    private function validateDocument(Request $request): array
    {
        return $request->validate(['type'=>['required',Rule::in(DocumentResolver::TYPES)],'id'=>'required|integer|min:1']);
    }
}
