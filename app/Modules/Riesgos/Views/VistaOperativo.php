<?php

namespace App\Modules\Riesgos\Views;

use App\Modules\Riesgos\Services\Fmt;

class VistaOperativo
{
    private static $semTxt = array('ok' => 'Normal', 'warn' => 'Observación', 'bad' => 'Atención');

    private static function sem($s)
    {
        $t = isset(self::$semTxt[$s]) ? self::$semTxt[$s] : $s;
        return '<span class="sem sem-' . $s . '">' . $t . '</span>';
    }

    public static function html(array $d, array $opts = array())
    {
        $logo = isset($opts['logo']) ? $opts['logo'] : '../logocompleto.png';
        $ini = $d['periodo']['ini'];
        $fin = $d['periodo']['fin'];
        $codigo = 'GMB/GIR/RO-' . date('m-Y', strtotime($fin));

        $f = $d['fallas']; $b = $d['billetes']; $a = $d['anulaciones'];

        ob_start();
        ?>
<div class="rc-doc">

  <div class="rc-cab">
    <img class="rc-logo" src="<?php echo htmlspecialchars($logo); ?>" alt="Gambarte" onerror="this.style.display='none'">
    <div class="rc-tit">
      <h1>Informe de Riesgo Operativo</h1>
      <div class="rc-sub">Del <?php echo Fmt::fechaLarga($ini); ?> al <?php echo Fmt::fechaLarga($fin); ?></div>
      <div class="rc-cod"><?php echo $codigo; ?></div>
    </div>
  </div>

  <h2>1. Objetivo</h2>
  <p>Monitorear los eventos de riesgo operativo de Gambarte S.R.L. durante el período —fallas de
  caja, detección de billetes falsos y anulaciones de operaciones— como parte de la gestión integral
  de riesgos exigida por la normativa (Ley 393). El seguimiento continuo de estos eventos permite
  identificar debilidades en los controles internos y adoptar medidas correctivas oportunas.</p>

  <p style="margin-top:8px;"><strong>Nivel de riesgo operativo del período:</strong> <?php echo self::sem($d['semaforo_global']); ?></p>

  <table class="rc-kpis"><tr>
    <td class="rc-kpi" width="33%">
      <div class="k-lbl">Fallas de caja</div>
      <div class="k-val"><?php echo $f['n']; ?></div>
      <div class="k-sub">Faltante Bs <?php echo Fmt::n($f['faltante']); ?> · Sobrante Bs <?php echo Fmt::n($f['sobrante']); ?></div>
    </td>
    <td class="rc-kpi" width="33%">
      <div class="k-lbl">Billetes falsos</div>
      <div class="k-val"><?php echo $b['eventos']; ?></div>
      <div class="k-sub">eventos detectados en el período</div>
    </td>
    <td class="rc-kpi" width="33%">
      <div class="k-lbl">Anulaciones ejecutadas</div>
      <div class="k-val"><?php echo $a['ejecutadas']; ?></div>
      <div class="k-sub"><?php echo Fmt::n($a['tasa_mil'], 2); ?> ‰ de <?php echo Fmt::n($a['total_operaciones'], 0); ?> operaciones</div>
    </td>
  </tr></table>

  <h2>2. Fallas de caja <?php echo self::sem($f['semaforo']); ?></h2>
  <p>Diferencias detectadas en los arqueos de caja durante el período. Un faltante representa dinero
  no justificado; un sobrante, excedente sobre el saldo esperado.</p>
  <?php if (!empty($f['por_agencia'])): ?>
  <table class="rc-t">
    <thead><tr><th>Agencia</th><th class="r"># eventos</th><th class="r">Faltante (Bs)</th><th class="r">Sobrante (Bs)</th><th class="r">Neto (Bs)</th></tr></thead>
    <tbody>
      <?php foreach ($f['por_agencia'] as $r): $neto = (float)$r['sobrante'] - (float)$r['faltante']; ?>
      <tr><td><?php echo htmlspecialchars($r['agencia']); ?></td>
          <td class="r"><?php echo $r['n']; ?></td>
          <td class="r"><?php echo Fmt::nd($r['faltante']); ?></td>
          <td class="r"><?php echo Fmt::nd($r['sobrante']); ?></td>
          <td class="r"><span class="<?php echo $neto < 0 ? 'neg-val' : 'pos-val'; ?>"><?php echo Fmt::n($neto); ?></span></td></tr>
      <?php endforeach; ?>
      <tr class="tot"><td>TOTAL</td><td class="r"><?php echo $f['n']; ?></td>
          <td class="r"><?php echo Fmt::n($f['faltante']); ?></td>
          <td class="r"><?php echo Fmt::n($f['sobrante']); ?></td>
          <td class="r"><?php echo Fmt::n($f['neto']); ?></td></tr>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin fallas de caja registradas en el período.</em></p><?php endif; ?>

  <h2>3. Billetes falsos <?php echo self::sem($b['semaforo']); ?></h2>
  <p>Billetes falsos detectados y retenidos en agencias durante el período. Es un indicador de
  exposición a fraude y de la eficacia de los controles de autenticación de efectivo.</p>
  <?php if ($b['eventos'] > 0): ?>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Moneda</th><th class="r"># billetes</th><th class="r">Monto detectado</th></tr></thead>
    <tbody>
      <?php foreach ($b['por_moneda'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['abreviatura'] ? $r['abreviatura'] : 'N/D'); ?></td>
          <td class="r"><?php echo (int)$r['billetes']; ?></td>
          <td class="r"><?php echo Fmt::n($r['monto']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <table class="rc-t">
    <thead><tr><th>Agencia</th><th class="r"># eventos</th><th class="r"># billetes</th><th class="r">Monto</th></tr></thead>
    <tbody>
      <?php foreach ($b['por_agencia'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['agencia']); ?></td>
          <td class="r"><?php echo (int)$r['eventos']; ?></td>
          <td class="r"><?php echo (int)$r['billetes']; ?></td>
          <td class="r"><?php echo Fmt::n($r['monto']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin billetes falsos detectados en el período.</em></p><?php endif; ?>

  <h2>4. Anulaciones de operaciones <?php echo self::sem($a['semaforo']); ?></h2>
  <p>Solicitudes de anulación de operaciones registradas en el período. Una tasa elevada de
  anulaciones puede señalar errores de registro, debilidades de control o intentos de manipulación.
  Tiempo promedio de resolución de las ejecutadas: <strong><?php echo Fmt::n($a['tiempo_prom_min'], 0); ?> min</strong>.</p>
  <?php if ($a['total'] > 0): ?>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Estado</th><th class="r"># solicitudes</th></tr></thead>
    <tbody>
      <?php foreach ($a['por_estado'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['estado']); ?></td><td class="r"><?php echo (int)$r['n']; ?></td></tr>
      <?php endforeach; ?>
      <tr class="tot"><td>TOTAL</td><td class="r"><?php echo $a['total']; ?></td></tr>
    </tbody>
  </table>
  <p>Tasa de anulación (ejecutadas sobre operaciones de cambio del período):
     <strong><?php echo Fmt::n($a['tasa_mil'], 2); ?> ‰</strong> (<?php echo $a['ejecutadas']; ?> de <?php echo Fmt::n($a['total_operaciones'], 0); ?>).</p>
  <?php else: ?><p><em>Sin solicitudes de anulación en el período.</em></p><?php endif; ?>

  <h2>5. Conclusión</h2>
  <p>El monitoreo continuo de los eventos operativos permite a Gambarte S.R.L. detectar de forma
  temprana desviaciones en los controles internos. Los indicadores de este período se clasifican con
  un nivel de riesgo operativo <strong><?php echo strtolower(self::$semTxt[$d['semaforo_global']]); ?></strong>,
  debiendo priorizarse la atención sobre las agencias y eventos destacados en las secciones anteriores.</p>

  <p class="rc-nota"><strong>Nota metodológica:</strong> los semáforos usan umbrales configurables
  (faltantes de caja, cantidad de billetes falsos y tasa de anulación por mil operaciones). El monto
  de billetes falsos se calcula como cantidad × denominación (tabla de cortes).</p>

  <div class="rc-pie">
    Generado automáticamente el <?php echo date('d/m/Y H:i'); ?> · Sistema CGR · Gambarte S.R.L.
  </div>
</div>
        <?php
        return ob_get_clean();
    }
}
