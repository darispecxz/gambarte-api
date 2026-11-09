<?php
/**
 * VistaMercado.php — Informe consolidado de Riesgo de Mercado.
 *
 * Reutiliza el motor RiesgoCambiario. El riesgo de mercado (Ley 393: tasa de
 * interés, tipo de cambio y precios) para Gambarte S.R.L. se concentra en el
 * tipo de cambio; tasa de interés y precios no son materiales.
 */

namespace RiesgoCambiario;

require_once __DIR__ . '/Fmt.php';

class VistaMercado
{
    public static function html(array $d, array $opts = array())
    {
        $logo = isset($opts['logo']) ? $opts['logo'] : '../logocompleto.png';
        $fin = $d['periodo']['fin'];
        $codigo = 'GMB/GIR/RM-' . date('m-Y', strtotime($fin));

        $totVar = 0.0;
        foreach ($d['riesgo'] as $r) $totVar += (float) $r['var'];
        $posCamb = $d['posicion']['posicion_cambiaria'];

        ob_start();
        ?>
<div class="rc-doc">

  <div class="rc-cab">
    <img class="rc-logo" src="<?php echo htmlspecialchars($logo); ?>" alt="Gambarte" onerror="this.style.display='none'">
    <div class="rc-tit">
      <h1>Informe de Riesgo de Mercado</h1>
      <div class="rc-sub">Del <?php echo Fmt::fechaLarga($d['periodo']['ini']); ?> al <?php echo Fmt::fechaLarga($fin); ?></div>
      <div class="rc-cod"><?php echo $codigo; ?></div>
    </div>
  </div>

  <h2>1. Objetivo</h2>
  <p>Consolidar la exposición de Gambarte S.R.L. a los factores de riesgo de mercado que la Ley 393
  (art. 451) identifica —tasa de interés, tipo de cambio y precios—. Por la naturaleza del negocio
  (giros, remesas y cambio de divisas), el factor material es el <strong>tipo de cambio</strong>; la
  exposición a tasa de interés y a precios de instrumentos no es significativa.</p>

  <!-- KPIs -->
  <table class="rc-kpis"><tr>
    <td class="rc-kpi" width="50%">
      <div class="k-lbl">VaR total (99%)</div>
      <div class="k-val">Bs <?php echo Fmt::n($totVar); ?></div>
      <div class="k-sub">pérdida potencial por tipo de cambio</div>
    </td>
    <td class="rc-kpi" width="50%">
      <div class="k-lbl">Posición cambiaria</div>
      <div class="k-val">Bs <?php echo Fmt::n($posCamb); ?></div>
      <div class="k-sub">activo − pasivo en M/E (USD)</div>
    </td>
  </tr></table>

  <h2>2. Riesgo de tipo de cambio</h2>
  <p>Valor en Riesgo (VaR) al 99% por moneda, calculado como posición × volatilidad diaria × 2,326.
  El detalle completo (posición contable, ganancia contable y real) se encuentra en el informe de
  <em>Riesgo Cambiario</em>.</p>
  <table class="rc-t">
    <thead><tr><th>Cuenta</th><th>Moneda</th><th class="r">Posición (Bs)</th><th class="r">Volatilidad</th><th class="r">VaR (Bs)</th></tr></thead>
    <tbody>
      <?php foreach ($d['riesgo'] as $r): if (abs($r['posicion']) < 0.005 || $r['var'] <= 0) continue; ?>
      <tr><td><?php echo $r['cuenta']; ?></td><td><?php echo htmlspecialchars($r['moneda']); ?></td>
          <td class="r"><?php echo Fmt::n($r['posicion']); ?></td>
          <td class="r"><?php echo Fmt::pct($r['volatilidad']); ?></td>
          <td class="r"><?php echo Fmt::n($r['var']); ?></td></tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="4" class="r">VaR TOTAL</td><td class="r"><?php echo Fmt::n($totVar); ?></td></tr>
    </tbody>
  </table>

  <h2>3. Riesgo de tasa de interés</h2>
  <p><strong>No material.</strong> Gambarte S.R.L. no mantiene posiciones significativas que devenguen
  interés (depósitos a plazo, inversiones con rendimiento), por lo que la exposición a variaciones de
  tasa de interés no representa un riesgo relevante en el período.</p>

  <h2>4. Riesgo de precio</h2>
  <p><strong>No aplica.</strong> La empresa no mantiene posiciones en instrumentos financieros sujetos
  a riesgo de precio de mercado.</p>

  <h2>5. Conclusión</h2>
  <p>El riesgo de mercado de Gambarte S.R.L. se explica esencialmente por el tipo de cambio, con un
  VaR total al 99% de Bs <?php echo Fmt::n($totVar); ?> al corte del <?php echo Fmt::fechaLarga($fin); ?>.
  La gestión de este factor se desarrolla en detalle en el informe de Riesgo Cambiario.</p>

  <div class="rc-pie">
    Generado automáticamente el <?php echo date('d/m/Y H:i'); ?> · Sistema CGR · Gambarte S.R.L.
  </div>
</div>
        <?php
        return ob_get_clean();
    }
}
