<?php
/**
 * index.php — UI del módulo de Auditoría.
 * Selecciona un período (AAAA-MM) y descarga cada reporte .xlsx o el paquete .zip.
 */
require_once __DIR__ . '/lib/Reportes.php';
use Auditoria\Reportes;

$catalog = Reportes::catalog();
$defecto = date('Y-m', strtotime('first day of last month')); // mes anterior por defecto
$periodo = isset($_GET['periodo']) && preg_match('/^\d{4}-\d{2}$/', $_GET['periodo'])
         ? $_GET['periodo'] : $defecto;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reportes de Auditoría — CGR</title>
<link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="wrap">

  <div class="top">
    <img src="../logocompleto.png" alt="CGR" onerror="this.style.display='none'">
    <div>
      <h1>Reportes de Auditoría</h1>
      <div class="sub"><span class="badge-live">Conexión de solo lectura</span> · Bases CGR y Contabilidad</div>
    </div>
  </div>

  <div class="panel">
    <div class="controls">
      <div class="field">
        <label for="periodo">Período (mes)</label>
        <input type="month" id="periodo" value="<?php echo htmlspecialchars($periodo); ?>">
      </div>
      <a class="btn btn-primary" id="btnTodos" href="descargar.php?reporte=todos&periodo=<?php echo $periodo; ?>">
        ⬇ Descargar todos (.zip)
      </a>
    </div>
    <div class="note">
      Los reportes se generan al momento consultando la base de datos. El paquete completo
      puede tardar algunos segundos. Las fechas usan el rango exacto del mes seleccionado.
    </div>
  </div>

  <div class="grid" id="grid">
    <?php foreach ($catalog as $key => $r):
        $dbClass = $r['db'] === 'conta' ? 'db-conta' : 'db-cgr';
        $dbLabel = $r['db'] === 'conta' ? 'CONTA' : 'CGR';
    ?>
    <div class="card">
      <div class="row">
        <span class="code"><?php echo $r['code']; ?></span>
        <span class="db <?php echo $dbClass; ?>"><?php echo $dbLabel; ?></span>
      </div>
      <h3><?php echo htmlspecialchars($r['title']); ?></h3>
      <p><?php echo htmlspecialchars($r['desc']); ?></p>
      <div class="row">
        <button class="btn btn-ghost btn-sm view" type="button" data-reporte="<?php echo $key; ?>">
          👁 Ver / filtrar
        </button>
        <a class="btn btn-ghost btn-sm dl" data-reporte="<?php echo $key; ?>"
           href="descargar.php?reporte=<?php echo $key; ?>&periodo=<?php echo $periodo; ?>">
          ⬇ .xlsx
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ═══ Visor con filtros ═══ -->
  <div class="panel viewer" id="viewerPanel" hidden>
    <div class="vhead">
      <div>
        <strong id="vTitle">—</strong>
        <div class="vmeta" id="vMeta"></div>
      </div>
      <button class="btn btn-ghost btn-sm" type="button" id="vClose">✕ Cerrar</button>
    </div>

    <div class="vtabs" id="vTabs"></div>

    <div class="vtools">
      <input type="text" id="vSearch" placeholder="🔎 Buscar en toda la hoja…">
      <label class="vsize">Filas por página
        <select id="vPageSize">
          <option value="50">50</option>
          <option value="100" selected>100</option>
          <option value="250">250</option>
          <option value="500">500</option>
        </select>
      </label>
      <span class="vcount" id="vCount"></span>
      <a class="btn btn-ghost btn-sm" id="vDl" href="#">⬇ Descargar esta hoja (.xlsx)</a>
    </div>

    <div class="vtable-wrap">
      <table class="vtable" id="vTable">
        <thead id="vThead"></thead>
        <tbody id="vTbody"></tbody>
      </table>
    </div>

    <div class="vpager" id="vPager"></div>
  </div>

  <div class="foot">
    Módulo independiente · credenciales y estilos separados del resto del proyecto ·
    también disponible por consola: <code>php generar.php --periodo <?php echo $periodo; ?></code>
  </div>

</div>

<script>
// Actualiza todos los enlaces de descarga al cambiar el período.
(function(){
  var input = document.getElementById('periodo');
  function upd(){
    var p = input.value; // formato AAAA-MM
    if(!/^\d{4}-\d{2}$/.test(p)) return;
    document.getElementById('btnTodos').href = 'descargar.php?reporte=todos&periodo=' + p;
    document.querySelectorAll('a.dl').forEach(function(a){
      a.href = 'descargar.php?reporte=' + a.dataset.reporte + '&periodo=' + p;
    });
  }
  input.addEventListener('change', upd);
  input.addEventListener('input', upd);
})();
</script>
<script src="viewer.js"></script>
</body>
</html>
