<?php
/**
 * VistaPLAFT.php — Renderizador del Informe de Monitoreo PLA/FT/FP.
 */

namespace RiesgoCambiario;

require_once __DIR__ . '/Fmt.php';

class VistaPLAFT
{
    private static $semTxt = array('ok' => 'Sin alertas críticas', 'warn' => 'Requiere revisión', 'bad' => 'Alerta crítica');

    private static function sem($s)
    {
        $t = isset(self::$semTxt[$s]) ? self::$semTxt[$s] : $s;
        return '<span class="sem sem-' . $s . '">' . $t . '</span>';
    }

    private static function op($id) { return (int) $id === 1 ? 'Compra' : ((int) $id === 2 ? 'Venta' : $id); }

    public static function html(array $d, array $opts = array())
    {
        $logo = isset($opts['logo']) ? $opts['logo'] : '../logocompleto.png';
        $fin = $d['periodo']['fin'];
        $codigo = 'GMB/GIR/LGI-' . date('m-Y', strtotime($fin));
        $p = $d['parametros'];

        ob_start();
        ?>
<div class="rc-doc">

  <div class="rc-cab">
    <img class="rc-logo" src="<?php echo htmlspecialchars($logo); ?>" alt="Gambarte" onerror="this.style.display='none'">
    <div class="rc-tit">
      <h1>Monitoreo PLA/FT/FP</h1>
      <div class="rc-sub">Del <?php echo Fmt::fechaLarga($d['periodo']['ini']); ?> al <?php echo Fmt::fechaLarga($fin); ?></div>
      <div class="rc-cod"><?php echo $codigo; ?></div>
    </div>
  </div>

  <h2>1. Objetivo</h2>
  <p>Monitorear las operaciones de cambio de Gambarte S.R.L. para la prevención de la legitimación de
  ganancias ilícitas, el financiamiento del terrorismo y de la proliferación de armas de destrucción
  masiva (PLA/FT/FP), conforme a la normativa UIF. Se generan alertas por operaciones sobre umbral,
  fraccionamiento, alta frecuencia y coincidencias con la base de PEP y listas de riesgo.</p>

  <p style="margin-top:8px;"><strong>Estado del período:</strong> <?php echo self::sem($d['semaforo']); ?>
     · Parámetros: umbral <strong>Bs <?php echo Fmt::n($p['umbral'], 0); ?></strong>,
     fraccionamiento ≥ <?php echo $p['fracc_minops']; ?> ops/día, alta frecuencia ≥ <?php echo $p['freq_ops']; ?> ops.</p>

  <!-- KPIs -->
  <table class="rc-kpis"><tr>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Operaciones</div><div class="k-val"><?php echo Fmt::n($d['total_operaciones'], 0); ?></div><div class="k-sub">Bs <?php echo Fmt::n($d['monto_operaciones']); ?></div></td>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Sobre umbral</div><div class="k-val"><?php echo $d['umbral']['n']; ?></div><div class="k-sub">operaciones</div></td>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Fraccionamiento</div><div class="k-val"><?php echo $d['fraccionamiento']['n']; ?></div><div class="k-sub">casos cliente-día</div></td>
    <td class="rc-kpi" width="20%"><div class="k-lbl">PEP</div><div class="k-val"><?php echo $d['pep']['clientes']; ?></div><div class="k-sub"><?php echo $d['pep']['ops']; ?> operaciones</div></td>
    <td class="rc-kpi" width="20%"><div class="k-lbl">Listas de riesgo</div><div class="k-val" style="color:<?php echo $d['listas']['ops']>0?'#c0392b':'#1f3a63'; ?>"><?php echo $d['listas']['clientes']; ?></div><div class="k-sub"><?php echo $d['listas']['ops']; ?> operaciones</div></td>
  </tr></table>

  <h2>2. Operaciones sobre umbral</h2>
  <p>Operaciones cuyo monto iguala o supera Bs <?php echo Fmt::n($p['umbral'], 0); ?>. Total:
     <strong><?php echo $d['umbral']['n']; ?></strong> por Bs <?php echo Fmt::n($d['umbral']['monto']); ?>.</p>
  <?php if (!empty($d['umbral']['lista'])): ?>
  <table class="rc-t">
    <thead><tr><th>Documento</th><th>Agencia</th><th class="c">Operación</th><th class="r">Monto (Bs)</th><th class="c">Fecha</th></tr></thead>
    <tbody>
      <?php foreach ($d['umbral']['lista'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['num_doc_identidad']); ?></td>
          <td><?php echo htmlspecialchars($r['agencia']); ?></td>
          <td class="c"><?php echo self::op($r['id_operacion']); ?></td>
          <td class="r"><?php echo Fmt::n($r['monto']); ?></td>
          <td class="c"><?php echo Fmt::fechaCorta($r['fecha']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin operaciones sobre umbral en el período.</em></p><?php endif; ?>

  <h2>3. Posible fraccionamiento</h2>
  <p>Clientes con <?php echo $p['fracc_minops']; ?> o más operaciones en un mismo día, cada una por
  debajo del umbral, que en conjunto igualan o superan Bs <?php echo Fmt::n($p['umbral'], 0); ?>
  (patrón típico de <em>structuring</em>). Casos: <strong><?php echo $d['fraccionamiento']['n']; ?></strong>.</p>
  <?php if (!empty($d['fraccionamiento']['lista'])): ?>
  <table class="rc-t">
    <thead><tr><th>Documento</th><th class="c">Día</th><th class="r"># ops</th><th class="r">Suma (Bs)</th><th class="r">Mayor op (Bs)</th></tr></thead>
    <tbody>
      <?php foreach ($d['fraccionamiento']['lista'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['num_doc_identidad']); ?></td>
          <td class="c"><?php echo Fmt::fechaCorta($r['dia']); ?></td>
          <td class="r"><?php echo (int)$r['ops']; ?></td>
          <td class="r"><?php echo Fmt::n($r['suma']); ?></td>
          <td class="r"><?php echo Fmt::n($r['maxop']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin patrones de fraccionamiento detectados.</em></p><?php endif; ?>

  <h2>4. Clientes de alta frecuencia</h2>
  <p>Clientes con <?php echo $p['freq_ops']; ?> o más operaciones en el período:
     <strong><?php echo $d['frecuencia']['n']; ?></strong>.</p>
  <?php if (!empty($d['frecuencia']['lista'])): ?>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Documento</th><th class="r"># operaciones</th><th class="r">Monto acumulado (Bs)</th></tr></thead>
    <tbody>
      <?php foreach ($d['frecuencia']['lista'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['num_doc_identidad']); ?></td>
          <td class="r"><?php echo (int)$r['ops']; ?></td>
          <td class="r"><?php echo Fmt::n($r['suma']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin clientes de alta frecuencia en el período.</em></p><?php endif; ?>

  <h2>5. Coincidencias con base PEP</h2>
  <p>Operaciones de clientes cuyo documento coincide con la base de Personas Expuestas Políticamente.
     Requieren debida diligencia ampliada. Clientes: <strong><?php echo $d['pep']['clientes']; ?></strong>
     (<?php echo $d['pep']['ops']; ?> operaciones).</p>
  <?php if (!empty($d['pep']['lista'])): ?>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Documento</th><th class="r"># ops</th><th class="r">Monto (Bs)</th></tr></thead>
    <tbody>
      <?php foreach ($d['pep']['lista'] as $r): ?>
      <tr><td><?php echo htmlspecialchars($r['num_doc_identidad']); ?></td>
          <td class="r"><?php echo (int)$r['ops']; ?></td><td class="r"><?php echo Fmt::n($r['suma']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin coincidencias PEP en el período.</em></p><?php endif; ?>

  <h2>6. Coincidencias con listas de riesgo</h2>
  <p>Operaciones de clientes cuyo documento coincide con listas de riesgo (sanciones / observados).
     <strong>Coincidencias de máxima prioridad.</strong> Clientes:
     <strong><?php echo $d['listas']['clientes']; ?></strong> (<?php echo $d['listas']['ops']; ?> operaciones).</p>
  <?php if (!empty($d['listas']['lista'])): ?>
  <table class="rc-t rc-narrow">
    <thead><tr><th>Documento</th><th class="r"># ops</th><th class="r">Monto (Bs)</th></tr></thead>
    <tbody>
      <?php foreach ($d['listas']['lista'] as $r): ?>
      <tr><td style="color:#c0392b;font-weight:bold;"><?php echo htmlspecialchars($r['num_doc_identidad']); ?></td>
          <td class="r"><?php echo (int)$r['ops']; ?></td><td class="r"><?php echo Fmt::n($r['suma']); ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p><em>Sin coincidencias con listas de riesgo en el período.</em></p><?php endif; ?>

  <h2>7. Conclusión</h2>
  <p>Las alertas de este período deben ser analizadas por el Oficial de Cumplimiento para determinar
  si corresponde solicitar información adicional, aplicar debida diligencia ampliada o, en su caso,
  elevar un Reporte de Operación Sospechosa (ROS) a la UIF.</p>

  <p class="rc-nota"><strong>Nota metodológica:</strong> los umbrales y parámetros son
  <strong>provisionales y configurables</strong> (constantes en la clase RiesgoPLAFT) y deben
  ajustarse a la normativa UIF vigente y al manual de prevención de la entidad. El cruce con PEP y
  listas se realiza por número de documento y es <strong>preliminar</strong>: puede arrojar homónimos
  o falsos positivos y requiere confirmación manual. Esta versión analiza las operaciones de cambio;
  la incorporación de remesas y giros queda como siguiente paso. Este informe es una herramienta de
  apoyo al monitoreo y no constituye por sí mismo un reporte regulatorio.</p>

  <div class="rc-pie">
    Generado automáticamente el <?php echo date('d/m/Y H:i'); ?> · Sistema CGR · Gambarte S.R.L.
  </div>
</div>
        <?php
        return ob_get_clean();
    }
}
