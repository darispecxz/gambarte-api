<?php

namespace App\Modules\Riesgos\Views;

use App\Modules\Riesgos\Services\Fmt;

class VistaLiquidez
{
    private static $semTxt = array('ok' => 'Adecuada', 'warn' => 'Ajustada', 'bad' => 'Crítica');

    private static function sem($s)
    {
        $t = isset(self::$semTxt[$s]) ? self::$semTxt[$s] : $s;
        return '<span class="sem sem-' . $s . '">' . $t . '</span>';
    }

    private static function ratio($v)
    {
        return $v === null ? '—' : Fmt::n($v, 2) . '×';
    }

    public static function html(array $d, array $opts = array())
    {
        $logo = isset($opts['logo']) ? $opts['logo'] : '../logocompleto.png';
        $fin = $d['periodo']['fin'];
        $codigo = 'GMB/GIR/RL-' . date('m-Y', strtotime($fin));

        ob_start();
        ?>
<div class="rc-doc">

  <div class="rc-cab">
    <img class="rc-logo" src="<?php echo htmlspecialchars($logo); ?>" alt="Gambarte" onerror="this.style.display='none'">
    <div class="rc-tit">
      <h1>Informe de Riesgo de Liquidez</h1>
      <div class="rc-sub">Posición al <?php echo Fmt::fechaLarga($fin); ?></div>
      <div class="rc-cod"><?php echo $codigo; ?></div>
    </div>
  </div>

  <h2>1. Objetivo</h2>
  <p>Evaluar la capacidad de Gambarte S.R.L. de cubrir sus obligaciones operativas exigibles con sus
  activos líquidos disponibles, a la fecha de corte, como parte de la gestión integral de riesgos
  (Ley 393). Se analiza la composición de la liquidez, el calce por moneda y la concentración de los
  fondos en entidades bancarias.</p>

  <p style="margin-top:8px;"><strong>Nivel de liquidez:</strong> <?php echo self::sem($d['semaforo']); ?></p>

  <table class="rc-kpis"><tr>
    <td class="rc-kpi" width="25%">
      <div class="k-lbl">Disponibilidad</div>
      <div class="k-val">Bs <?php echo Fmt::n($d['tot_disponibilidad']); ?></div>
      <div class="k-sub">activos líquidos</div>
    </td>
    <td class="rc-kpi" width="25%">
      <div class="k-lbl">Obligaciones exigibles</div>
      <div class="k-val">Bs <?php echo Fmt::n($d['tot_obligaciones']); ?></div>
      <div class="k-sub">giros / remesas por pagar</div>
    </td>
    <td class="rc-kpi" width="25%">
      <div class="k-lbl">Cobertura</div>
      <div class="k-val"><?php echo self::ratio($d['cobertura']); ?></div>
      <div class="k-sub">disponible ÷ exigible</div>
    </td>
    <td class="rc-kpi" width="25%">
      <div class="k-lbl">Liquidez inmediata</div>
      <div class="k-val"><?php echo Fmt::pct($d['pct_efectivo']); ?></div>
      <div class="k-sub">en efectivo (caja)</div>
    </td>
  </tr></table>

  <h2>2. Disponibilidad de activos líquidos</h2>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Rubro</th><th class="c">Moneda</th><th class="r">Saldo (Bs)</th></tr></thead>
    <tbody>
      <?php foreach ($d['disponibilidad'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['label']); ?></td>
          <td class="c"><?php echo $r['moneda']; ?></td>
          <td class="r"><?php echo Fmt::nd($r['saldo']); ?></td></tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="2">TOTAL DISPONIBLE</td><td class="r"><?php echo Fmt::n($d['tot_disponibilidad']); ?></td></tr>
    </tbody>
  </table>
  <p>Composición: <strong>efectivo</strong> Bs <?php echo Fmt::n($d['efectivo']); ?>
     (<?php echo Fmt::pct($d['pct_efectivo']); ?>) · <strong>bancos</strong> Bs <?php echo Fmt::n($d['bancos']); ?>.
     Cuanto mayor la proporción en efectivo, mayor la liquidez inmediata.</p>

  <h2>3. Calce por moneda</h2>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Moneda</th><th class="r">Disponible</th><th class="r">Exigible</th><th class="r">Brecha</th><th class="r">Cobertura</th></tr></thead>
    <tbody>
      <tr><td>Moneda extranjera (M/E)</td>
          <td class="r"><?php echo Fmt::n($d['tot_me']); ?></td>
          <td class="r"><?php echo Fmt::n($d['tot_obligaciones_me']); ?></td>
          <td class="r"><span class="<?php echo $d['brecha_me'] < 0 ? 'neg-val' : 'pos-val'; ?>"><?php echo Fmt::n($d['brecha_me']); ?></span></td>
          <td class="r"><?php echo self::ratio($d['cobertura_me']); ?></td></tr>
      <tr><td>Moneda nacional (M/N)</td>
          <td class="r"><?php echo Fmt::n($d['tot_mn']); ?></td>
          <td class="r">-</td><td class="r">-</td><td class="r">-</td></tr>
      <tr class="tot"><td>TOTAL</td>
          <td class="r"><?php echo Fmt::n($d['tot_disponibilidad']); ?></td>
          <td class="r"><?php echo Fmt::n($d['tot_obligaciones']); ?></td>
          <td class="r"><span class="<?php echo $d['brecha'] < 0 ? 'neg-val' : 'pos-val'; ?>"><?php echo Fmt::n($d['brecha']); ?></span></td>
          <td class="r"><?php echo self::ratio($d['cobertura']); ?></td></tr>
    </tbody>
  </table>
  <p>La brecha de liquidez es la diferencia entre activos disponibles y obligaciones exigibles. Una
  brecha positiva indica holgura; una negativa, necesidad de fondeo.</p>

  <h2>4. Obligaciones operativas exigibles</h2>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Concepto</th><th class="c">Moneda</th><th class="r">Saldo (Bs)</th></tr></thead>
    <tbody>
      <?php foreach ($d['obligaciones'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['label']); ?></td>
          <td class="c"><?php echo $r['moneda']; ?></td>
          <td class="r"><?php echo Fmt::nd($r['saldo']); ?></td></tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="2">TOTAL EXIGIBLE</td><td class="r"><?php echo Fmt::n($d['tot_obligaciones']); ?></td></tr>
    </tbody>
  </table>

  <h2>5. Concentración bancaria</h2>
  <p>Distribución de los fondos en bancos por cuenta contable. Una concentración elevada aumenta la
  exposición a la contraparte bancaria. Índice de concentración (HHI):
     <strong><?php echo Fmt::n($d['hhi'] * 10000, 0); ?></strong>
     <?php $h = $d['hhi'] * 10000; echo self::sem($h >= 2500 ? 'bad' : ($h >= 1500 ? 'warn' : 'ok')); ?>.</p>
  <?php if (!empty($d['concentracion'])): ?>
  <table class="rc-t">
    <thead><tr><th>Cuenta</th><th>Banco / descripción</th><th class="r">Saldo (Bs)</th><th class="r">%</th></tr></thead>
    <tbody>
      <?php $i = 0; foreach ($d['concentracion'] as $c): if (++$i > 10) break; ?>
      <tr><td><?php echo $c['cuenta']; ?></td>
          <td><?php echo htmlspecialchars($c['descripcion']); ?></td>
          <td class="r"><?php echo Fmt::n($c['saldo']); ?></td>
          <td class="r"><?php echo Fmt::pct($c['part']); ?></td></tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="2">TOTAL BANCOS</td><td class="r"><?php echo Fmt::n($d['tot_bancos']); ?></td><td class="r">100,00%</td></tr>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin saldos bancarios al corte.</em></p><?php endif; ?>

  <h2>6. Conclusión</h2>
  <p>Con corte al <?php echo Fmt::fechaLarga($fin); ?>, Gambarte S.R.L. presenta una disponibilidad de
  Bs <?php echo Fmt::n($d['tot_disponibilidad']); ?> frente a obligaciones operativas exigibles de
  Bs <?php echo Fmt::n($d['tot_obligaciones']); ?>, con una cobertura de <?php echo self::ratio($d['cobertura']); ?>
  y un nivel de liquidez <strong><?php echo strtolower(self::$semTxt[$d['semaforo']]); ?></strong>.</p>

  <p class="rc-nota"><strong>Nota metodológica:</strong> las obligaciones exigibles consideran los
  compromisos operativos con clientes (giros y remesas por pagar); se excluyen pasivos administrativos
  no inmediatos (alquileres, sueldos, impuestos, provisiones). El detalle de cuentas es configurable y
  debe validarse con el área contable / financiera.</p>

  <div class="rc-pie">
    Generado automáticamente el <?php echo date('d/m/Y H:i'); ?> · Sistema CGR · Gambarte S.R.L.
  </div>
</div>
        <?php
        return ob_get_clean();
    }
}
