<?php
namespace App\Modules\Cajero\Services;
use App\Modules\Shared\Database\RawConnection as DB;

class CajeroIncidenciaService {
    public function registrar(int $caja, int $agencia, int $usuario, float $diferencia, string $justificacion): void {
        if (abs($diferencia) < 0.0001) return;
        if (mb_strlen(trim($justificacion)) < 20) throw new \RuntimeException('La declaración jurada debe explicar la diferencia con al menos 20 caracteres.');
        $tipo = $diferencia < 0 ? 'faltante' : 'sobrante';
        $firma = DB::selectOne('SELECT firma_path FROM pos_usuario_preferencia WHERE cod_usuario=:u', [':u'=>$usuario]);
        if (!$firma || empty($firma['firma_path'])) throw new \RuntimeException('Debe registrar su firma digital en Mi cuenta antes de declarar un faltante o sobrante.');
        DB::execute('INSERT INTO pos_incidencia_caja (id_caja,id_agencia,cod_usuario,fecha,tipo,diferencia,justificacion,declaracion_firmada_en,firma_path)
          VALUES (:c,:a,:u,CURDATE(),:t,:d,:j,NOW(),:f)', [':c'=>$caja,':a'=>$agencia,':u'=>$usuario,':t'=>$tipo,':d'=>$diferencia,':j'=>trim($justificacion),':f'=>$firma['firma_path']]);
        $asunto = "POS: {$tipo} de caja requiere revisión";
        $contenido = "Se registró un {$tipo} de caja. Caja: {$caja}; agencia: {$agencia}; diferencia: ".number_format(abs($diferencia),2).". Declaración jurada: ".trim($justificacion);
        foreach (['darispe@gambarte.com.bo','info@gambarte.com.bo'] as $email) DB::execute("INSERT INTO pos_notificacion_operacion (canal,destinatario,tipo_operacion,id_referencia,asunto,contenido,estado,intentos) VALUES ('email',:d,'incidencia_caja',:r,:a,:c,'pendiente',0)", [':d'=>$email,':r'=>$caja,':a'=>$asunto,':c'=>$contenido]);
    }
}
