<?php

namespace App\Modules\Riesgos\Views;

use App\Modules\Riesgos\Services\Fmt;

class VistaSeguridad
{
    private static $semTxt = array('ok' => 'Adecuada', 'warn' => 'Observación', 'bad' => 'Crítica');

    private static function sem($s)
    {
        $t = isset(self::$semTxt[$s]) ? self::$semTxt[$s] : $s;
        return '<span class="sem sem-' . $s . '">' . $t . '</span>';
    }
    private static function dt($v) { return $v ? date('d/m/Y H:i', strtotime($v)) : '-'; }

    public static function html(array $d, array $opts = array())
    {
        $logo = isset($opts['logo']) ? $opts['logo'] : '../logocompleto.png';
        $fin = $d['periodo']['fin'];
        $codigo = 'GMB/GIR/SIT-' . date('m-Y', strtotime($fin));
        $t = $d['traza']; $cob = $t['cobertura']; $acc = $d['accesos']; $seg = $d['segregacion'];

        ob_start();
        ?>
<div class="rc-doc">

  <div class="rc-cab">
    <img class="rc-logo" src="<?php echo htmlspecialchars($logo); ?>" alt="Gambarte" onerror="this.style.display='none'">
    <div class="rc-tit">
      <h1>Seguridad de la Información y Trazabilidad</h1>
      <div class="rc-sub">Del <?php echo Fmt::fechaLarga($d['periodo']['ini']); ?> al <?php echo Fmt::fechaLarga($fin); ?></div>
      <div class="rc-cod"><?php echo $codigo; ?></div>
    </div>
  </div>

  <h2>1. Objetivo</h2>
  <p>Garantizar la trazabilidad completa de las operaciones y la seguridad de los accesos al sistema
  de Gambarte S.R.L., verificando que toda acción quede registrada (quién, qué, cuándo y desde dónde),
  que los accesos sean seguros y que la asignación de usuarios y perfiles respete la segregación de
  funciones. Es un pilar del control interno y del cumplimiento normativo.</p>

  <p style="margin-top:8px;"><strong>Estado del período:</strong> <?php echo self::sem($d['semaforo']); ?></p>

  <table class="rc-kpis"><tr>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Eventos en bitácora</div><div class="k-val"><?php echo Fmt::n($cob['eventos'], 0); ?></div><div class="k-sub">registrados</div></td>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Operaciones trazadas</div><div class="k-val"><?php echo Fmt::n($cob['operaciones_logueadas'], 0); ?></div><div class="k-sub">con usuario e IP</div></td>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Intentos fallidos</div><div class="k-val" style="color:<?php echo $acc['fallidos']>=10?'#c0392b':'#1f3a63'; ?>"><?php echo $acc['fallidos']; ?></div><div class="k-sub">de <?php echo $acc['exitosos']; ?> accesos</div></td>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Claves vencidas</div><div class="k-val" style="color:<?php echo $d['claves']['n']>0?'#8a6d1a':'#1f3a63'; ?>"><?php echo $d['claves']['n']; ?></div><div class="k-sub">usuarios activos</div></td>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Conflictos SoD</div><div class="k-val" style="color:<?php echo $seg['conflictos_n']>0?'#c0392b':'#1e7e34'; ?>"><?php echo $seg['conflictos_n']; ?></div><div class="k-sub">segregación</div></td>
  </tr></table>

  <h2>2. Trazabilidad de operaciones</h2>
  <p>Toda operación queda registrada en múltiples capas: la <strong>bitácora</strong> asienta cada
  registro con usuario, fecha e IP; <strong>reporte_cambios_auditoria</strong> conserva la traza
  histórica de cada cambio (<?php echo Fmt::n($cob['cambios_auditados'], 0); ?> en el período); y
  <strong>co_partidas_audit</strong> guarda las modificaciones contables con su valor anterior y
  posterior. En el período se registraron <strong><?php echo Fmt::n($cob['operaciones_logueadas'], 0); ?></strong>
  operaciones y <strong><?php echo $cob['modificaciones']; ?></strong> modificaciones contables.</p>

  <h3>2.1 Traza de operaciones registradas</h3>
  <?php if (!empty($t['operaciones'])): ?>
  <table class="rc-t">
    <thead><tr><th>Usuario</th><th>Operación registrada</th><th class="c">Fecha y hora</th><th class="c">IP</th></tr></thead>
    <tbody>
      <?php foreach ($t['operaciones'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['login_usu']); ?></td>
          <td><?php echo htmlspecialchars($r['evento']); ?></td>
          <td class="c"><?php echo self::dt($r['fecha']); ?></td>
          <td class="c"><?php echo htmlspecialchars($r['ip']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="rc-nota" style="background:#eef2f8;border-color:#1f3a63;">Se muestran las operaciones más recientes del período. La traza es reconstruible por operación, usuario, fecha o agencia.</p>
  <?php else: ?><p><em>Sin operaciones registradas en la bitácora del período.</em></p><?php endif; ?>

  <h3>2.2 Actividad por usuario</h3>
  <?php if (!empty($t['actividad'])): ?>
  <table class="rc-t">
    <thead><tr><th>Usuario</th><th class="r">Eventos</th><th class="r">Sesiones</th><th class="r">Operaciones</th><th class="c">Última actividad</th></tr></thead>
    <tbody>
      <?php foreach ($t['actividad'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['login_usu']); ?></td>
          <td class="r"><?php echo (int)$r['eventos']; ?></td>
          <td class="r"><?php echo (int)$r['sesiones']; ?></td>
          <td class="r"><?php echo (int)$r['operaciones']; ?></td>
          <td class="c"><?php echo self::dt($r['ultimo']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h3>2.3 Registro de modificaciones contables (antes/después)</h3>
  <?php if (!empty($t['modificaciones'])): ?>
  <table class="rc-t">
    <thead><tr><th>Partida</th><th>Subcuenta</th><th>Concepto</th><th>Usuario mod.</th><th class="c">Fecha mod.</th></tr></thead>
    <tbody>
      <?php foreach ($t['modificaciones'] as $r): ?>
      <tr><td><?php echo $r['idpartida']; ?></td>
          <td><?php echo htmlspecialchars($r['codsubcuenta']); ?></td>
          <td><?php echo htmlspecialchars(mb_substr($r['concepto'], 0, 40)); ?></td>
          <td><?php echo htmlspecialchars($r['usuario_modificacion']); ?></td>
          <td class="c"><?php echo self::dt($r['fecha_modificacion']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin modificaciones contables registradas en el período (todo asiento se mantuvo sin alteración).</em></p><?php endif; ?>

  <h2>3. Seguridad de accesos</h2>
  <p>Accesos exitosos: <strong><?php echo $acc['exitosos']; ?></strong> · intentos fallidos por
     credenciales: <strong><?php echo $acc['fallidos']; ?></strong> · IPs distintas:
     <strong><?php echo $acc['ips']; ?></strong> · accesos fuera de horario (00:00–06:00):
     <strong><?php echo $acc['fuera_horario']; ?></strong>.
     <?php if (!empty($acc['tecnicos'])): ?><br><span style="font-size:10.5px;color:#778;">(Se excluyen <?php echo $acc['tecnicos']; ?> errores técnicos transitorios de autenticación —captcha/carga— que no son fallos de credenciales.)</span><?php endif; ?></p>
  <?php if (!empty($acc['por_usuario'])): ?>
  <h3>3.1 Intentos de autenticación fallidos por usuario</h3>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Usuario</th><th class="r">Intentos</th><th class="c">Último</th></tr></thead>
    <tbody>
      <?php foreach ($acc['por_usuario'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['usuario']); ?></td>
          <td class="r"><?php echo (int)$r['intentos']; ?></td>
          <td class="c"><?php echo self::dt($r['ultimo']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h3>3.2 Higiene de cuentas</h3>
  <p>Cuentas activas: <strong><?php echo $d['cuentas']['activas']; ?></strong> · inactivas:
     <strong><?php echo $d['cuentas']['inactivas']; ?></strong>. Contraseñas vencidas (política
     <?php echo $d['dias_clave']; ?> días): <strong><?php echo $d['claves']['n']; ?></strong>.</p>
  <?php if (!empty($d['claves']['lista'])): ?>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Usuario</th><th class="c">Último cambio de clave</th><th class="r">Días</th></tr></thead>
    <tbody>
      <?php foreach ($d['claves']['lista'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['login_usu']); ?></td>
          <td class="c"><?php echo $r['fec_ult_cambio_clave'] ? Fmt::fechaCorta($r['fec_ult_cambio_clave']) : 'nunca'; ?></td>
          <td class="r"><?php echo $r['dias'] === null ? '—' : (int)$r['dias']; ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h2>4. Usuarios, perfiles y segregación de funciones</h2>
  <?php if (!empty($d['usuarios_por_rol'])): ?>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Rol / perfil</th><th class="r">Usuarios activos</th></tr></thead>
    <tbody>
      <?php foreach ($d['usuarios_por_rol'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['rol']); ?></td><td class="r"><?php echo (int)$r['n']; ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h3>4.1 Segregación de funciones (SoD)</h3>
  <?php if ($seg['conflictos_n'] > 0): ?>
  <p>Se detectaron <strong style="color:#c0392b;"><?php echo $seg['conflictos_n']; ?></strong> usuarios con roles incompatibles:</p>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Usuario</th><th>Roles en conflicto</th></tr></thead>
    <tbody>
      <?php foreach ($seg['conflictos'] as $r): ?>
      <tr><td style="color:#c0392b;font-weight:bold;"><?php echo htmlspecialchars($r['login']); ?></td>
          <td><?php echo htmlspecialchars($r['roles']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php elseif ($seg['multi_n'] > 0): ?>
  <p><?php echo $seg['multi_n']; ?> usuario(s) tienen más de un rol, pero sin combinaciones marcadas como incompatibles.</p>
  <?php else: ?>
  <p><span class="sem sem-ok">Correcto</span> Ningún usuario activo tiene más de un rol: la segregación de funciones se respeta.</p>
  <?php endif; ?>

  <h2>5. Conclusión</h2>
  <p>La actividad del sistema queda trazada de forma íntegra (bitácora + auditoría de cambios y de
  asientos contables). El estado general de seguridad de la información se clasifica como
  <strong><?php echo strtolower(self::$semTxt[$d['semaforo']]); ?></strong>, debiendo atenderse los
  intentos fallidos reiterados, las contraseñas vencidas y cualquier conflicto de segregación
  señalados en este informe.</p>

  <p class="rc-nota"><strong>Nota metodológica:</strong> los pares de roles incompatibles y los
  umbrales (política de contraseña <?php echo $d['dias_clave']; ?> días, intentos fallidos) son
  configurables (constantes en la clase Seguridad). La cobertura de auditoría con antes/después está
  garantizada en contabilidad y en operaciones de cambio; su extensión al 100% de las tablas (giros,
  remesas, cajas) queda como verificación posterior.</p>

  <div class="rc-pie">
    Generado automáticamente el <?php echo date('d/m/Y H:i'); ?> · Sistema CGR · Gambarte S.R.L.
  </div>
</div>
        <?php
        return ob_get_clean();
    }
}
