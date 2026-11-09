<?php
/**
 * index.php — Panel de Gestión Integral de Riesgos.
 *
 * Parámetros (GET):
 *   riesgo = cambiario | liquidez | operativo   (default: cambiario)
 *   preset = mes | dia | rango                   (default: mes = del 1° del mes a la fecha)
 *   ini, fin = AAAA-MM-DD                         (rango explícito)
 *   fecha    = AAAA-MM-DD                         (para preset=dia)
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(120);

require_once __DIR__ . '/lib/Panel.php';

use RiesgoCambiario\Panel;

\RiesgoCambiario\Db::config(); // fija timezone

function valid_date($s) { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s); }

$risks  = Panel::risks();
$riesgo = isset($_GET['riesgo']) && isset($risks[$_GET['riesgo']]) ? $_GET['riesgo'] : 'cambiario';

list($ini, $fin, $preset) = resolver_periodo();

function resolver_periodo()
{
    $preset = isset($_GET['preset']) ? $_GET['preset'] : 'mes';
    $hoy = date('Y-m-d');
    if ($preset === 'dia') {
        $f = isset($_GET['fecha']) && valid_date($_GET['fecha']) ? $_GET['fecha'] : $hoy;
        return array($f, $f, 'dia');
    }
    if ($preset === 'rango') {
        $i = isset($_GET['ini']) && valid_date($_GET['ini']) ? $_GET['ini'] : date('Y-m-01');
        $f = isset($_GET['fin']) && valid_date($_GET['fin']) ? $_GET['fin'] : $hoy;
        if ($i > $f) { $t = $i; $i = $f; $f = $t; }
        return array($i, $f, 'rango');
    }
    $f = isset($_GET['fin']) && valid_date($_GET['fin']) ? $_GET['fin'] : $hoy;
    $i = date('Y-m-01', strtotime($f));
    return array($i, $f, 'mes');
}

$error = null; $contenido = '';
try {
    $res = Panel::generar($riesgo, $ini, $fin, array('logo' => '../logocompleto.png'));
    $contenido = $res['html'];
} catch (Exception $e) {
    $error = $e->getMessage();
}

// query string común para conservar período al cambiar de pestaña / descargar
function qs_periodo($riesgo, $preset, $ini, $fin) {
    return http_build_query(array('riesgo' => $riesgo, 'preset' => $preset, 'ini' => $ini, 'fin' => $fin, 'fecha' => $ini));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel de Gestión Integral de Riesgos — CGR</title>
<style>
<?php echo Panel::pageCss(); ?>
<?php echo Panel::reportCss(); ?>
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <img src="../logocompleto.png" alt="Gambarte" onerror="this.style.display='none'">
    <div>
      <h1>Panel de Gestión Integral de Riesgos</h1>
      <div class="sub"><span class="badge">Solo lectura</span> · Bases CGR y Contabilidad</div>
    </div>
  </div>

  <div class="tabs">
    <?php foreach ($risks as $slug => $r): ?>
    <a class="tab <?php echo $slug === $riesgo ? 'active' : ''; ?>"
       href="index.php?<?php echo htmlspecialchars(qs_periodo($slug, $preset, $ini, $fin)); ?>">
       <?php echo $r['icon'] . ' ' . htmlspecialchars($r['title']); ?>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="panel-c">
    <form class="controls" method="get" action="index.php">
      <input type="hidden" name="riesgo" value="<?php echo htmlspecialchars($riesgo); ?>">
      <div class="field">
        <label for="preset">Período</label>
        <select name="preset" id="preset" onchange="rcToggle()">
          <option value="mes"   <?php echo $preset==='mes'?'selected':''; ?>>Mes a la fecha</option>
          <option value="dia"   <?php echo $preset==='dia'?'selected':''; ?>>Un día</option>
          <option value="rango" <?php echo $preset==='rango'?'selected':''; ?>>Rango</option>
        </select>
      </div>
      <div class="field" id="f-fecha" style="display:none;">
        <label for="fecha">Fecha</label>
        <input type="date" name="fecha" id="fecha" value="<?php echo htmlspecialchars($ini); ?>">
      </div>
      <div class="field" id="f-ini" style="display:none;">
        <label for="ini">Desde</label>
        <input type="date" name="ini" id="ini" value="<?php echo htmlspecialchars($ini); ?>">
      </div>
      <div class="field" id="f-fin">
        <label for="fin">Hasta (corte)</label>
        <input type="date" name="fin" id="fin" value="<?php echo htmlspecialchars($fin); ?>">
      </div>
      <button type="submit" class="btn btn-primary">Ver informe</button>
      <a class="btn btn-pdf" href="descargar.php?<?php echo htmlspecialchars(qs_periodo($riesgo, $preset, $ini, $fin)); ?>">⬇ Descargar PDF</a>
    </form>
    <div class="note">Cada informe se calcula al momento consultando contabilidad (co_partidas) y el CGR. El corte es la fecha «Hasta».</div>
  </div>

  <?php if ($error): ?>
    <div class="err"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div>
  <?php else: ?>
    <div class="rc-render"><?php echo $contenido; ?></div>
  <?php endif; ?>

</div>
<script>
function rcToggle(){
  var p=document.getElementById('preset').value;
  document.getElementById('f-fecha').style.display = p==='dia'   ? '' : 'none';
  document.getElementById('f-ini').style.display   = p==='rango' ? '' : 'none';
  document.getElementById('f-fin').style.display   = p==='dia'   ? 'none' : '';
}
rcToggle();
</script>
</body>
</html>
