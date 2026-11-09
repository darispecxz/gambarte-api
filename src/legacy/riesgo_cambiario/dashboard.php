<?php
/**
 * dashboard.php — Tablero ejecutivo de Gestión Integral de Riesgos.
 *
 * Vista consolidada de una sola pantalla para dirección / comité de riesgos:
 * mapa de riesgos (semáforos + indicadores clave) y alertas prioritarias, con
 * enlace al informe de detalle de cada dominio.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(180);

require_once __DIR__ . '/lib/Dashboard.php';
use RiesgoCambiario\Dashboard;
use RiesgoCambiario\Panel;

\RiesgoCambiario\Db::config();

function valid_date($s) { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s); }

$hoy = date('Y-m-d');
$preset = isset($_GET['preset']) ? $_GET['preset'] : 'mes';
if ($preset === 'dia') {
    $fin = isset($_GET['fecha']) && valid_date($_GET['fecha']) ? $_GET['fecha'] : $hoy; $ini = $fin;
} elseif ($preset === 'rango') {
    $ini = isset($_GET['ini']) && valid_date($_GET['ini']) ? $_GET['ini'] : date('Y-m-01');
    $fin = isset($_GET['fin']) && valid_date($_GET['fin']) ? $_GET['fin'] : $hoy;
    if ($ini > $fin) { $t = $ini; $ini = $fin; $fin = $t; }
} else {
    $fin = isset($_GET['fin']) && valid_date($_GET['fin']) ? $_GET['fin'] : $hoy;
    $ini = date('Y-m-01', strtotime($fin));
}

$forzar = isset($_GET['recalcular']);
$error = null; $R = null;
try { $R = Dashboard::resumenCache($ini, $fin, $forzar); }
catch (Exception $e) { $error = $e->getMessage(); }
catch (Error $e) { $error = $e->getMessage(); }

$semTxt = array('ok' => 'Normal', 'warn' => 'Requiere atención', 'bad' => 'Crítico', 'info' => 'Monitoreado');
function fecha_larga($ymd) {
    $m = array(1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre');
    $t = strtotime($ymd); return (int)date('j',$t).' de '.$m[(int)date('n',$t)].' de '.date('Y',$t);
}
$qs = http_build_query(array('preset' => $preset, 'ini' => $ini, 'fin' => $fin, 'fecha' => $ini));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tablero de Gestión Integral de Riesgos — Dirección</title>
<style>
<?php echo Panel::pageCss(); ?>
.hero{background:#fff;border:1px solid #dde3ea;border-radius:12px;padding:16px 20px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.hero .est{font-size:12px;color:#667;}
.big-sem{font-size:14px;font-weight:800;padding:6px 16px;border-radius:20px;}
.s-ok{background:#e6f4ea;color:#1e7e34}.s-warn{background:#fff3cd;color:#856404}.s-bad{background:#f8d7da;color:#a12a2a}.s-info{background:#e7edf6;color:#1f3a63}
.dgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin-bottom:16px;}
.dcard{background:#fff;border:1px solid #dde3ea;border-radius:12px;padding:14px 16px;border-left:5px solid #ccc;box-shadow:0 1px 2px rgba(0,0,0,.04);}
.dcard.ok{border-left-color:#1e7e34}.dcard.warn{border-left-color:#c99a1a}.dcard.bad{border-left-color:#c0392b}.dcard.info{border-left-color:#1f3a63}
.dcard h3{margin:0 0 2px;font-size:15px;}
.dcard .badge2{font-size:10px;font-weight:700;padding:2px 8px;border-radius:9px;}
.dcard .mrow{display:flex;justify-content:space-between;gap:8px;font-size:12px;padding:5px 0;border-top:1px dashed #eef0f3;}
.dcard .mrow .k{color:#667}.dcard .mrow .v{font-weight:700;text-align:right}
.dcard a.det{display:inline-block;margin-top:8px;font-size:12px;color:#e8721c;text-decoration:none;font-weight:600;}
.alerts{background:#fff;border:1px solid #dde3ea;border-radius:12px;padding:14px 18px;}
.alerts h2{font-size:15px;margin:0 0 10px;color:#1f3a63;}
.alert{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-top:1px solid #f0f2f5;font-size:13px;}
.alert:first-of-type{border-top:none;}
.pill{font-size:10px;font-weight:800;padding:2px 8px;border-radius:9px;white-space:nowrap;}
.p-bad{background:#f8d7da;color:#a12a2a}.p-warn{background:#fff3cd;color:#856404}.p-ok{background:#e6f4ea;color:#1e7e34}
.alert .dom{font-weight:700;color:#334;}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <img src="../logocompleto.png" alt="Gambarte" onerror="this.style.display='none'">
    <div>
      <h1>Tablero de Gestión Integral de Riesgos</h1>
      <div class="sub"><span class="badge">Vista de dirección</span> · consolidado de los 6 informes</div>
    </div>
  </div>

  <div class="panel-c">
    <form class="controls" method="get" action="dashboard.php">
      <div class="field">
        <label for="preset">Período</label>
        <select name="preset" id="preset" onchange="rcToggle()">
          <option value="mes"   <?php echo $preset==='mes'?'selected':''; ?>>Mes a la fecha</option>
          <option value="dia"   <?php echo $preset==='dia'?'selected':''; ?>>Un día</option>
          <option value="rango" <?php echo $preset==='rango'?'selected':''; ?>>Rango</option>
        </select>
      </div>
      <div class="field" id="f-fecha" style="display:none;"><label for="fecha">Fecha</label><input type="date" name="fecha" id="fecha" value="<?php echo htmlspecialchars($ini); ?>"></div>
      <div class="field" id="f-ini" style="display:none;"><label for="ini">Desde</label><input type="date" name="ini" id="ini" value="<?php echo htmlspecialchars($ini); ?>"></div>
      <div class="field" id="f-fin"><label for="fin">Hasta (corte)</label><input type="date" name="fin" id="fin" value="<?php echo htmlspecialchars($fin); ?>"></div>
      <button type="submit" class="btn btn-primary">Ver</button>
      <a class="btn" href="dashboard.php?<?php echo htmlspecialchars($qs); ?>&recalcular=1" style="background:#f1f3f6;border:1px solid #c4ccd6;color:#333;">↻ Recalcular</a>
      <a class="btn btn-pdf" href="index.php?<?php echo htmlspecialchars($qs); ?>" style="background:#1f3a63;">Ir a los informes →</a>
    </form>
    <div class="note">
      <?php if (!empty($R['generado'])): ?>
        Última actualización: <strong><?php echo htmlspecialchars($R['generado']); ?></strong>
        <?php echo !empty($R['from_cache']) ? '(desde caché)' : '(recién calculado)'; ?> ·
      <?php endif; ?>
      El tablero se calcula bajo demanda; use «Recalcular» para refrescar con los datos más recientes.
    </div>
  </div>

  <?php if ($error): ?>
    <div class="err"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div>
  <?php else: ?>

    <div class="hero">
      <div>
        <div style="font-size:16px;font-weight:800;">Estado integral del período</div>
        <div class="est">Del <?php echo fecha_larga($ini); ?> al <?php echo fecha_larga($fin); ?></div>
      </div>
      <div class="big-sem s-<?php echo $R['semaforo']; ?>"><?php echo $semTxt[$R['semaforo']]; ?></div>
    </div>

    <div class="dgrid">
      <?php foreach ($R['dominios'] as $d): ?>
      <div class="dcard <?php echo $d['sem']; ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <h3><?php echo $d['icon'] . ' ' . htmlspecialchars($d['title']); ?></h3>
          <span class="badge2 s-<?php echo $d['sem']; ?>"><?php echo $semTxt[$d['sem']]; ?></span>
        </div>
        <?php foreach ($d['metrics'] as $m): ?>
        <div class="mrow"><span class="k"><?php echo htmlspecialchars($m[0]); ?></span><span class="v"><?php echo htmlspecialchars($m[1]); ?></span></div>
        <?php endforeach; ?>
        <a class="det" href="index.php?riesgo=<?php echo $d['slug']; ?>&<?php echo htmlspecialchars($qs); ?>">Ver informe →</a>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="alerts">
      <h2>⚠ Alertas prioritarias</h2>
      <?php foreach ($R['alertas'] as $a): ?>
      <div class="alert">
        <span class="pill p-<?php echo $a['sev']; ?>"><?php echo $a['sev']==='bad'?'CRÍTICO':($a['sev']==='warn'?'ATENCIÓN':'OK'); ?></span>
        <div><span class="dom"><?php echo htmlspecialchars($a['dom']); ?>:</span> <?php echo htmlspecialchars($a['txt']); ?></div>
      </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>
<script>
function rcToggle(){var p=document.getElementById('preset').value;
  document.getElementById('f-fecha').style.display=p==='dia'?'':'none';
  document.getElementById('f-ini').style.display=p==='rango'?'':'none';
  document.getElementById('f-fin').style.display=p==='dia'?'none':'';}
rcToggle();
</script>
</body>
</html>
