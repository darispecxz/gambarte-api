<?php

namespace App\Modules\Riesgos\Views;

use App\Modules\Riesgos\Services\Fmt;

class VistaCambiario
{
    public static function html(array $d, array $opts = array())
    {
        $logo = isset($opts['logo']) ? $opts['logo'] : '../logocompleto.png';

        $ini = $d['periodo']['ini'];
        $fin = $d['periodo']['fin'];
        $codigo = 'GMB/GIR/RC-' . date('m-Y', strtotime($fin));

        $po = $d['posicion'];
        $posCamb = $po['posicion_cambiaria'];
        $largaLabel = 'Dólares Norteamericanos (USD)';

        ob_start();
        ?>
<div class="rc-doc">

  <div class="rc-cab">
    <img class="rc-logo" src="<?php echo htmlspecialchars($logo); ?>" alt="Gambarte" onerror="this.style.display='none'">
    <div class="rc-tit">
      <h1>Informe de Riesgo Cambiario</h1>
      <div class="rc-sub">Del <?php echo Fmt::fechaLarga($ini); ?> al <?php echo Fmt::fechaLarga($fin); ?></div>
      <div class="rc-cod"><?php echo $codigo; ?></div>
    </div>
  </div>

  <h2>1. Antecedentes</h2>
  <p>Conforme con la ley 393 dentro de su artículo 451 dispone «Las entidades financieras están
  obligadas a estructurar sistemas de gestión de los riesgos de mercado, con el fin de evitar
  pérdidas derivadas de movimientos adversos en los factores de mercado como la tasa de interés,
  el tipo de cambio y los precios de instrumentos en los que la entidad ha tomado una posición
  dentro o fuera de balance.»</p>
  <p>La empresa Giros y Remesas de Dinero Gambarte Bolivia S.R.L., «Gambarte S.R.L.», se sitúa en una
  exposición de riesgo cambiario, consecuentemente por sus operaciones. El presente informe
  evaluará la posición cambiaria de los activos y pasivos en moneda extranjera, logrando determinar
  el Valor en Riesgo (VaR), con fecha del <?php echo Fmt::fechaLarga($ini); ?> al <?php echo Fmt::fechaLarga($fin); ?>.</p>

  <h2>2. Objetivo</h2>
  <p>Evaluar la posición cambiaria de Gambarte S.R.L. durante el período, calculando el Valor en
  Riesgo (VaR) asociado a las operaciones cambiarias que la empresa lleva a cabo. Este cálculo
  permite cuantificar la exposición al riesgo derivado de las fluctuaciones en los tipos de cambio y
  sirve como fundamento para la toma de decisiones orientadas a mitigar el impacto financiero de la
  volatilidad cambiaria.</p>

  <h2>3. Identificación del riesgo cambiario</h2>
  <p>Gambarte S.R.L. maneja monedas extranjeras como Dólares Norteamericanos (USD), Euros (EUR),
  Reales (BRL), Nuevos Soles (PEN), Pesos Argentinos (ARS), Pesos Chilenos (CLP), Pesos Mexicanos
  (MXN), Dólares Canadienses (CAD), Francos Suizos (CHF), Dólares Australianos (AUD), Libras
  Esterlinas (GBP) y Dólares Neozelandeses (NZD), entre otras. El riesgo cambiario proviene de las
  fluctuaciones en el valor de estas monedas respecto al Boliviano (Bs), impactando en la liquidez
  de Gambarte S.R.L.</p>

  <table class="rc-t rc-narrow">
    <thead>
      <tr><th>Número de Cuenta</th><th>Moneda</th><th class="r">Monto Actual (Expresado en Bs)</th></tr>
    </thead>
    <tbody>
      <?php foreach ($d['saldos'] as $s): if (abs($s['monto']) < 0.005) continue; ?>
      <tr><td><?php echo $s['cuenta']; ?></td><td><?php echo htmlspecialchars($s['moneda']); ?></td>
          <td class="r"><?php echo Fmt::n($s['monto']); ?></td></tr>
      <?php endforeach; ?>
      <tr class="tot"><td></td><td class="r">Total</td><td class="r"><?php echo Fmt::n($d['total_saldos']); ?></td></tr>
    </tbody>
  </table>

  <p>La posición cambiaria al <?php echo Fmt::fechaLarga($fin); ?> es de Bs. <?php echo Fmt::n($posCamb); ?>,
  lo que indica que nuestros activos <?php echo $posCamb < 0 ? 'son menores al' : 'superan al'; ?> pasivo en
  una posición larga en <?php echo $largaLabel; ?>.</p>

  <div class="rc-pos">
    <div class="rc-pos-tit">POSICIÓN CAMBIARIA AL <?php echo strtoupper(Fmt::fechaLarga($fin)); ?><br><span>Expresado en bolivianos</span></div>
    <table class="rc-t rc-t2">
      <tr class="grp"><td>ACTIVO</td><td class="r">SALDO</td></tr>
      <?php foreach ($po['activo'] as $a): ?>
      <tr><td><?php echo htmlspecialchars($a['label']); echo !empty($a['verificar']) ? ' <span class="rc-chk">(*)</span>' : ''; ?></td>
          <td class="r"><?php echo Fmt::nd($a['saldo']); ?></td></tr>
      <?php endforeach; ?>
      <tr class="sub"><td>TOTAL ACTIVO</td><td class="r"><?php echo Fmt::n($po['total_activo']); ?></td></tr>
      <tr class="grp"><td>PASIVO</td><td></td></tr>
      <?php foreach ($po['pasivo'] as $a): ?>
      <tr><td><?php echo htmlspecialchars($a['label']); echo !empty($a['verificar']) ? ' <span class="rc-chk">(*)</span>' : ''; ?></td>
          <td class="r"><?php echo Fmt::nd($a['saldo']); ?></td></tr>
      <?php endforeach; ?>
      <tr class="sub"><td>TOTAL PASIVO</td><td class="r"><?php echo Fmt::n($po['total_pasivo']); ?></td></tr>
      <tr class="pos"><td>Posición cambiaria</td><td class="r"><?php echo Fmt::n($posCamb); ?></td></tr>
    </table>
    <table class="rc-t rc-eco" align="right">
      <tr><td>posible ganancia económica</td><td class="r ok">Bs<?php echo Fmt::n($po['posible_ganancia']); ?></td></tr>
      <tr><td>posible pérdida económica</td><td class="r bad">Bs<?php echo Fmt::n($po['posible_perdida']); ?></td></tr>
    </table>
    <div style="clear:both;"></div>
  </div>

  <h2>4. Medición del riesgo</h2>
  <p>La medición del riesgo consideró la posición de cada moneda, la volatilidad diaria y su Valor en
  Riesgo (VaR) al 99%, indicando una pérdida potencial en las condiciones del mercado, con corte al
  <?php echo Fmt::fechaLarga($fin); ?>.</p>

  <table class="rc-t">
    <thead>
      <tr><th>Cuenta Contable</th><th>Moneda</th><th class="r">Posición</th><th class="r">Volatilidad Diaria</th><th class="r">Valor en Riesgo (VaR)</th></tr>
    </thead>
    <tbody>
      <?php foreach ($d['riesgo'] as $r): if (abs($r['posicion']) < 0.005) continue; ?>
      <tr><td><?php echo $r['cuenta']; ?></td><td><?php echo htmlspecialchars($r['moneda']); ?></td>
          <td class="r"><?php echo Fmt::n($r['posicion']); ?></td>
          <td class="r"><?php echo $r['volatilidad'] > 0 ? Fmt::pct($r['volatilidad']) : ''; ?></td>
          <td class="r"><?php echo Fmt::n($r['var']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>5. Monitoreo del riesgo</h2>
  <p>La posición cambiaria y las variaciones en el tipo de cambio se monitorean de manera diaria, lo
  que permite realizar ajustes oportunos en las estrategias de compra y venta de monedas
  extranjeras. Según el informe con corte al <?php echo Fmt::fechaLarga($fin); ?>, se detalla a
  continuación la ganancia contable y real derivada de las fluctuaciones cambiarias.</p>

  <table class="rc-t">
    <thead>
      <tr>
        <th>Moneda</th>
        <th class="r">Saldo de corte en moneda extranjera</th>
        <th class="r">T/C BCB</th>
        <th class="r">Prom. comercial COMPRA</th>
        <th class="r">Prom. comercial VENTA</th>
        <th class="r">Ganancia Real (Bs)</th>
        <th class="r">Ganancia Contable (Bs)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($d['ganancia'] as $g): if (abs($g['saldo_me']) < 0.005 && abs($g['gan_contable']) < 0.005) continue; ?>
      <tr><td><?php echo htmlspecialchars($g['abreviatura']); ?></td>
          <td class="r"><?php echo Fmt::n($g['saldo_me']); ?></td>
          <td class="r"><?php echo Fmt::n($g['bcb'], 4); ?></td>
          <td class="r"><?php echo Fmt::n($g['compra'], 4); ?></td>
          <td class="r"><?php echo Fmt::n($g['venta'], 4); ?></td>
          <td class="r"><?php echo Fmt::n($g['gan_real']); ?></td>
          <td class="r"><?php echo Fmt::n($g['gan_contable']); ?></td></tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="5" class="r">TOTAL</td>
          <td class="r"><?php echo Fmt::n($d['total_gan_real']); ?></td>
          <td class="r"><?php echo Fmt::n($d['total_gan_contable']); ?></td></tr>
    </tbody>
  </table>

  <h2>6. Mitigación del riesgo y conclusión</h2>
  <p>Gambarte S.R.L. ha implementado medidas para reducir el riesgo cambiario, principalmente
  mediante la diversificación en el uso de distintas monedas gracias a su alcance internacional. Esto
  limita el impacto de las fluctuaciones del tipo de cambio y permite identificar las variaciones de
  cada moneda durante el período, así como el efecto que podrían tener en escenarios poco favorables
  para la empresa.</p>

  <?php if (self::hayVerificar($d)): ?>
  <p class="rc-nota"><strong>Nota:</strong> las líneas marcadas con (*) (Inversiones Temporarias y Remesas
  en Tránsito) requieren confirmación del área contable: no se reconcilian automáticamente desde
  co_partidas y en el informe original provienen de un ajuste manual.</p>
  <?php endif; ?>

  <div class="rc-pie">
    Generado automáticamente el <?php echo date('d/m/Y H:i'); ?> · Sistema CGR · Gambarte S.R.L.
  </div>
</div>
        <?php
        return ob_get_clean();
    }

    private static function hayVerificar($d)
    {
        foreach (array_merge($d['posicion']['activo'], $d['posicion']['pasivo']) as $l) {
            if (!empty($l['verificar'])) return true;
        }
        return false;
    }
}
