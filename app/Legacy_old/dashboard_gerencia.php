<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Gerencia — CGR</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ═══════════════════════════════════════════════════════
   TOKENS
═══════════════════════════════════════════════════════ */
:root {
  --bg:       #EEF2F8;
  --white:    #FFFFFF;
  --border:   #DDE3EE;
  --ink-1:    #1A2540;
  --ink-2:    #5A6A88;
  --ink-3:    #9AAABB;
  --blue:     #1D4ED8;
  --blue-lt:  #EFF6FF;
  --green:    #047857;
  --green-lt: #ECFDF5;
  --amber:    #B45309;
  --amber-lt: #FFFBEB;
  --red:      #B91C1C;
  --red-lt:   #FEF2F2;
  --live:     #059669;
  --shadow:   0 1px 4px rgba(26,37,64,.08);
  --r:        10px;
}
*  { box-sizing:border-box; margin:0; padding:0; }
body {
  font-family: system-ui, -apple-system, sans-serif;
  background: var(--bg);
  color: var(--ink-1);
  font-size: 14px;
  min-height: 100vh;
  -webkit-text-size-adjust: 100%;
}

/* ═══════════════════════════════════════════════════════
   TOP BAR — mobile base
═══════════════════════════════════════════════════════ */
.top {
  background: var(--white);
  border-bottom: 1px solid var(--border);
  padding: 10px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.logo { display:flex; align-items:center; }
.logo img { height:32px; width:auto; display:block; }

.controls {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 7px;
  align-items: center;
}
.controls label { font-size:11px; color:var(--ink-2); display:none; } /* oculto en mobile */
.controls input[type=date] {
  width:100%; border:1px solid var(--border); border-radius:8px;
  padding:8px 10px; font-size:13px; color:var(--ink-1); background:var(--white);
  min-height:40px;
}
.controls input[type=date]:focus { outline:2px solid var(--blue); border-color:transparent; }
.btn-update {
  background:var(--blue); color:#fff; border:none;
  border-radius:8px; padding:0 16px; font-size:13px; font-weight:600;
  cursor:pointer; min-height:40px; width:100%;
  grid-column: 1 / -1;
}
.btn-update:active { background:#1e40af; }
.btn-sample {
  background:none; border:1px solid var(--border);
  border-radius:8px; padding:0 14px; font-size:12px;
  color:var(--ink-2); cursor:pointer; min-height:40px;
  grid-column: 1 / -1;
}
#status { font-size:11px; grid-column:1/-1; text-align:center; }

/* ═══════════════════════════════════════════════════════
   TABS — scroll horizontal en mobile
═══════════════════════════════════════════════════════ */
.tabs {
  background: var(--white);
  border-bottom: 1px solid var(--border);
  display: flex;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
  padding: 0 4px;
}
.tabs::-webkit-scrollbar { display:none; }
.tab {
  padding: 12px 14px;
  font-size: 12px; font-weight: 500; color: var(--ink-2);
  background: none; border: none;
  border-bottom: 2.5px solid transparent;
  cursor: pointer; display: flex; align-items: center; gap: 5px;
  white-space: nowrap; flex-shrink: 0;
  transition: color .13s, border-color .13s;
}
.tab:active { color:var(--ink-1); }
.tab.on { color:var(--blue); border-bottom-color:var(--blue); font-weight:700; }
.tab-badge {
  background:var(--bg); border:1px solid var(--border);
  color:var(--ink-3); font-size:10px; font-weight:600;
  padding:1px 6px; border-radius:8px;
  font-variant-numeric:tabular-nums;
}
.tab.on .tab-badge { background:var(--blue-lt); color:var(--blue); border-color:#BFDBFE; }

/* ═══════════════════════════════════════════════════════
   PAGE / PANEL
═══════════════════════════════════════════════════════ */
.page  { padding: 14px 12px; }
.panel { display:none; }
.panel.on { display:block; animation:up .16s ease; }
@keyframes up { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:none} }

/* ═══════════════════════════════════════════════════════
   HERO — apilado en mobile
═══════════════════════════════════════════════════════ */
.hero {
  border-radius: var(--r); padding: 16px;
  margin-bottom: 14px;
  display: flex; align-items: flex-start; gap: 12px;
}
.hero.blue   { background:var(--blue-lt);  border:1px solid #BFDBFE; }
.hero.green  { background:var(--green-lt); border:1px solid #A7F3D0; }
.hero.amber  { background:var(--amber-lt); border:1px solid #FDE68A; }
.hero.live-h { background:var(--green-lt); border:1px solid #A7F3D0; }
.hero-icon { font-size:30px; flex-shrink:0; }
.hero-text { flex:1; min-width:0; }
.hero-text h2  { font-size:12px; font-weight:600; color:var(--ink-2); margin-bottom:3px; }
.hero-text .big {
  font-size:26px; font-weight:800; color:var(--ink-1);
  letter-spacing:-.02em; line-height:1.1;
  font-variant-numeric:tabular-nums;
  word-break: break-word;
}
.hero-text .big.green-v { color:var(--green); }
.hero-text .sub { font-size:12px; color:var(--ink-2); margin-top:5px; line-height:1.5; }
.hero-text .sub b { color:var(--ink-1); font-weight:600; }

/* ═══════════════════════════════════════════════════════
   KPI GRID — 2 cols en mobile
═══════════════════════════════════════════════════════ */
.kgrid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px; margin-bottom: 16px;
}
.kcard {
  background:var(--white); border:1px solid var(--border);
  border-radius:var(--r); padding:14px 14px;
  box-shadow:var(--shadow); position:relative; overflow:hidden;
}
.kcard::after {
  content:''; position:absolute; bottom:0; left:0; right:0;
  height:3px; border-radius:0 0 var(--r) var(--r);
}
.kcard.blue::after   { background:var(--blue); }
.kcard.green::after  { background:var(--green); }
.kcard.amber::after  { background:var(--amber); }
.kcard.red::after    { background:var(--red); }
.kcard-lbl { font-size:10px; color:var(--ink-3); text-transform:uppercase; letter-spacing:.07em; font-weight:600; margin-bottom:6px; }
.kcard-val { font-size:22px; font-weight:800; color:var(--ink-1); letter-spacing:-.02em; font-variant-numeric:tabular-nums; line-height:1; }
.kcard-sub { font-size:11px; color:var(--ink-2); margin-top:4px; }

/* ═══════════════════════════════════════════════════════
   SECTION TITLE
═══════════════════════════════════════════════════════ */
.stitle {
  font-size:11px; font-weight:700; color:var(--ink-2);
  text-transform:uppercase; letter-spacing:.06em;
  margin-bottom:12px; display:flex; align-items:center; gap:8px;
}
.stitle::after { content:''; flex:1; height:1px; background:var(--border); }

/* ═══════════════════════════════════════════════════════
   RANKING BARS
═══════════════════════════════════════════════════════ */
.rank-list { display:flex; flex-direction:column; gap:8px; }
.rank-row {
  background:var(--white); border:1px solid var(--border);
  border-radius:var(--r); padding:12px 14px;
  display:flex; align-items:center; gap:10px;
  box-shadow:var(--shadow);
}
.rank-pos { font-size:18px; width:28px; text-align:center; flex-shrink:0; }
.rank-info { flex:1; min-width:0; }
.rank-name { font-size:13px; font-weight:700; color:var(--ink-1); }
.rank-detail { font-size:11px; color:var(--ink-2); margin-top:2px; }
.rank-amount { font-size:14px; font-weight:800; color:var(--ink-1); font-variant-numeric:tabular-nums; white-space:nowrap; flex-shrink:0; }
.rank-bar-wrap { margin-top:7px; background:var(--bg); border-radius:4px; height:6px; overflow:hidden; }
.rank-bar { height:6px; border-radius:4px; transition:width .5s ease; }
.rank-row.winner { border-color:#A7F3D0; background:var(--green-lt); }
.rank-row.winner .rank-amount { color:var(--green); }
.rank-row.loser  { border-color:#FECACA; background:var(--red-lt); }
.rank-row.loser  .rank-amount { color:var(--red); }

/* ═══════════════════════════════════════════════════════
   AGENCIA GRID — 1 col en mobile
═══════════════════════════════════════════════════════ */
.ag-grid { display:grid; grid-template-columns:1fr; gap:10px; }
.ag-card {
  background:var(--white); border:1px solid var(--border);
  border-radius:var(--r); padding:14px 16px;
  box-shadow:var(--shadow);
}
.ag-card.top { border-color:#A7F3D0; background:var(--green-lt); }
.ag-name { font-size:13px; font-weight:700; color:var(--ink-1); margin-bottom:2px; }
.ag-sub  { font-size:11px; color:var(--ink-2); margin-bottom:8px; }
.ag-stat { display:flex; justify-content:space-between; margin-bottom:5px; }
.ag-stat-lbl { font-size:12px; color:var(--ink-2); }
.ag-stat-val { font-size:12px; font-weight:700; color:var(--ink-1); font-variant-numeric:tabular-nums; }
.ag-bar-wrap { background:var(--bg); border-radius:3px; height:5px; margin-top:7px; }
.ag-bar { height:5px; border-radius:3px; background:var(--blue); }
.ag-top-tag {
  display:inline-block; background:var(--green); color:#fff;
  font-size:10px; font-weight:700; padding:2px 8px; border-radius:8px;
  margin-bottom:6px; letter-spacing:.04em;
}

/* ═══════════════════════════════════════════════════════
   LIVE / HOY
═══════════════════════════════════════════════════════ */
.live-list { display:flex; flex-direction:column; gap:8px; }
.live-row {
  background:var(--white); border:1px solid var(--border);
  border-radius:var(--r); padding:12px 14px;
  display:flex; align-items:center; gap:12px;
  box-shadow:var(--shadow);
}
.live-flag { font-size:24px; flex-shrink:0; }
.live-name { font-size:13px; font-weight:700; color:var(--ink-1); }
.live-ops  { font-size:11px; color:var(--ink-2); margin-top:2px; }
.live-right { margin-left:auto; text-align:right; }
.live-total { font-size:16px; font-weight:800; color:var(--ink-1); font-variant-numeric:tabular-nums; }
.live-bar-wrap { margin-top:7px; background:var(--bg); border-radius:4px; height:5px; }
.live-bar { height:5px; border-radius:4px; }

/* ═══════════════════════════════════════════════════════
   DOT LIVE
═══════════════════════════════════════════════════════ */
.dot {
  width:7px; height:7px; border-radius:50%;
  background:var(--live); display:inline-block;
  animation:blink 1.4s infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.25} }
.live-tag {
  display:inline-flex; align-items:center; gap:5px;
  background:var(--green-lt); color:var(--green);
  font-size:11px; font-weight:700; padding:2px 8px; border-radius:8px;
  border:1px solid #A7F3D0;
}

/* ═══════════════════════════════════════════════════════
   CAJEROS
═══════════════════════════════════════════════════════ */
.toolbar-caj {
  display: flex; flex-direction: column;
  gap: 8px; margin-bottom: 12px;
}
.toolbar-caj input,
.toolbar-caj select {
  font-size:13px; padding:8px 10px;
  border:1px solid var(--border); border-radius:8px;
  background:var(--white); color:var(--ink-1);
  width:100%; min-height:40px;
}
.toolbar-caj input:focus,
.toolbar-caj select:focus { outline:2px solid var(--blue); border-color:transparent; }
.caj-cnt { font-size:11px; color:var(--ink-3); }

/* Tabla cajeros: scroll horizontal con cols fijas */
.caj-tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:var(--r); border:1px solid var(--border); }
.caj-tbl { width:100%; border-collapse:collapse; font-size:12px; min-width:540px; }
.caj-tbl th {
  background:var(--bg); padding:8px 10px;
  font-size:10px; font-weight:700; color:var(--ink-3);
  text-transform:uppercase; letter-spacing:.05em;
  border-bottom:1px solid var(--border); text-align:left;
  cursor:pointer; user-select:none; white-space:nowrap;
}
.caj-tbl th:hover { color:var(--blue); }
.caj-tbl th.num { text-align:right; }
.caj-tbl td { padding:8px 10px; border-bottom:.5px solid var(--border); color:var(--ink-1); vertical-align:middle; }
.caj-tbl td.num { text-align:right; font-variant-numeric:tabular-nums; }
.caj-tbl tr:last-child td { border-bottom:none; }
.caj-tbl tr:hover td { background:var(--bg); }

.pill-c { display:inline-block; background:var(--blue-lt); color:var(--blue); border-radius:4px; padding:1px 7px; font-size:11px; font-weight:600; }
.pill-g { display:inline-block; background:var(--amber-lt); color:var(--amber); border-radius:4px; padding:1px 7px; font-size:11px; font-weight:600; }
.pill-r { display:inline-block; background:var(--green-lt); color:var(--green); border-radius:4px; padding:1px 7px; font-size:11px; font-weight:600; }
.caj-code  { font-family:monospace; font-size:11px; background:var(--bg); padding:2px 5px; border-radius:4px; color:#7C3AED; }
.caj-badge { background:var(--bg); border:1px solid var(--border); color:var(--ink-2); font-size:10px; padding:1px 6px; border-radius:4px; white-space:nowrap; }
.micro-bar-c { display:inline-block; height:5px; background:var(--blue);  border-radius:2px; vertical-align:middle; }
.micro-bar-g { display:inline-block; height:5px; background:var(--amber); border-radius:2px; vertical-align:middle; }
.micro-bar-r { display:inline-block; height:5px; background:var(--green); border-radius:2px; vertical-align:middle; }

/* columnas no esenciales: ocultas en mobile */
.col-usuario, .col-distr { display:none; }

/* ═══════════════════════════════════════════════════════
   PRECIOS / COTIZACIONES
═══════════════════════════════════════════════════════ */
.pcard { background:var(--white); border:1px solid var(--border); border-radius:var(--r); padding:14px 16px; margin-bottom:14px; box-shadow:var(--shadow); }
.ptable-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.ptable { width:100%; border-collapse:collapse; font-size:12px; min-width:460px; }
.ptable th { background:var(--bg); padding:8px 10px; text-align:left; font-size:10px; font-weight:700; color:var(--ink-3); text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid var(--border); white-space:nowrap; }
.ptable th.num { text-align:right; }
.ptable td { padding:8px 10px; border-bottom:.5px solid var(--border); color:var(--ink-1); vertical-align:middle; }
.ptable td.num { text-align:right; font-variant-numeric:tabular-nums; }
.ptable tr:last-child td { border-bottom:none; }
.ptable tr:hover td { background:var(--bg); }
.ptable .cv  { color:var(--blue);  font-weight:700; }
.ptable .vv  { color:var(--amber); font-weight:700; }
.ptable .sub { color:var(--ink-3); font-size:11px; }
.ptable .spread { color:var(--green); font-size:11px; }

/* columnas de precios no esenciales: ocultas en mobile */
.col-prom-c, .col-prom-v, .col-min-c, .col-min-v { display:none; }

.cot-card { background:var(--white); border:1px solid var(--border); border-radius:var(--r); padding:14px 16px; margin-bottom:14px; box-shadow:var(--shadow); }
.cot-filters { display:flex; flex-direction:column; gap:8px; margin-bottom:12px; }
.cot-filters select {
  font-size:13px; padding:8px 10px;
  border:1px solid var(--border); border-radius:8px;
  background:var(--white); color:var(--ink-1);
  width:100%; min-height:40px;
}
.cot-filters select:focus { outline:2px solid var(--blue); border-color:transparent; }
.cot-canvas-wrap { position:relative; height:240px; }

/* ═══════════════════════════════════════════════════════
   TABLET  ≥ 600px
═══════════════════════════════════════════════════════ */
@media (min-width: 600px) {
  body { font-size:15px; }

  .top { flex-direction:row; align-items:center; padding:12px 20px; gap:12px; }
  .controls { display:flex; flex-wrap:wrap; gap:8px; margin-left:auto; }
  .controls label { display:inline; }
  .controls input[type=date] { width:auto; min-height:36px; }
  .btn-update { width:auto; grid-column:auto; min-height:36px; }
  .btn-sample { width:auto; grid-column:auto; min-height:36px; }
  #status { grid-column:auto; text-align:left; }

  .tabs { padding: 0 12px; }
  .tab  { padding:13px 18px; font-size:13px; }

  .page { padding:20px 18px; }
  .hero { padding:20px 22px; gap:16px; }
  .hero-icon { font-size:36px; }
  .hero-text .big { font-size:30px; }

  .kgrid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
  .kcard-val { font-size:24px; }

  .ag-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }

  .toolbar-caj { flex-direction:row; align-items:center; }
  .toolbar-caj input  { width:210px; min-height:36px; }
  .toolbar-caj select { width:auto; min-height:36px; }
  .caj-cnt { margin-left:auto; }

  .cot-filters { flex-direction:row; flex-wrap:wrap; }
  .cot-filters select { width:auto; min-height:36px; }
  .cot-canvas-wrap { height:280px; }

  .col-usuario { display:table-cell; }
}

/* ═══════════════════════════════════════════════════════
   DESKTOP  ≥ 900px
═══════════════════════════════════════════════════════ */
@media (min-width: 900px) {
  .logo img { height:40px; }
  .top  { padding:14px 28px; }
  .tabs { padding:0 28px; }
  .tab  { padding:14px 22px; }
  .page { padding:28px; max-width:1100px; margin:0 auto; }

  .hero { padding:24px 28px; }
  .hero-icon { font-size:40px; }
  .hero-text .big { font-size:34px; }

  .kgrid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
  .kcard-val { font-size:26px; }

  .col-usuario, .col-distr  { display:table-cell; }
  .col-prom-c, .col-prom-v  { display:table-cell; }
  .col-min-c,  .col-min-v   { display:table-cell; }
  .cot-canvas-wrap { height:320px; }
  .ptable { min-width:unset; }
  .caj-tbl { min-width:unset; }
  .hora-canvas-wrap { height:300px; }
}

/* ═══════════════════════════════════════════════════════
   SALDOS — TARJETAS
═══════════════════════════════════════════════════════ */
.saldo-grid { display:grid; grid-template-columns:1fr; gap:10px; margin-bottom:20px; }
.saldo-card { background:var(--white); border:1px solid var(--border); border-radius:var(--r); padding:14px 16px; box-shadow:var(--shadow); }
.saldo-ag-name { font-size:14px; font-weight:700; color:var(--ink-1); }
.saldo-ag-usd  { font-size:11px; color:var(--ink-3); margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid var(--border); }
.saldo-row { display:flex; align-items:center; gap:8px; padding:5px 0; border-bottom:.5px solid var(--border); }
.saldo-row:last-child { border-bottom:none; }
.saldo-flag { font-size:15px; width:20px; flex-shrink:0; }
.saldo-mon  { font-size:11px; font-weight:600; color:var(--ink-2); width:32px; flex-shrink:0; }
.saldo-val  { font-size:13px; font-weight:700; margin-left:auto; font-variant-numeric:tabular-nums; }
@media (min-width:600px) { .saldo-grid { grid-template-columns: repeat(auto-fill, minmax(230px,1fr)); } }
@media (min-width:900px) { .saldo-grid { grid-template-columns: repeat(auto-fill, minmax(250px,1fr)); } }

/* ═══════════════════════════════════════════════════════
   SALDOS — DISTRIBUCIÓN
═══════════════════════════════════════════════════════ */
.dist-list { display:flex; flex-direction:column; gap:14px; }
.dist-head { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:5px; }
.dist-label { font-size:12px; font-weight:700; color:var(--ink-1); }
.dist-total { font-size:11px; color:var(--ink-3); font-variant-numeric:tabular-nums; }
.dist-bar-wrap { height:22px; border-radius:5px; overflow:hidden; display:flex; background:var(--bg); }
.dist-seg { height:100%; transition:width .5s ease; }
.dist-legend { display:flex; flex-wrap:wrap; gap:3px 10px; margin-top:5px; }
.dist-legend-item { font-size:10px; color:var(--ink-2); display:flex; align-items:center; gap:3px; }
.dist-legend-dot { width:8px; height:8px; border-radius:2px; flex-shrink:0; }

/* ═══════════════════════════════════════════════════════
   DELTA BADGE (comparativo período anterior)
═══════════════════════════════════════════════════════ */
.delta { display:inline-block; font-size:10px; font-weight:700; padding:2px 6px; border-radius:5px; margin-left:6px; vertical-align:middle; }
.delta-up { background:var(--green-lt); color:var(--green); }
.delta-dn  { background:var(--red-lt);   color:var(--red); }

/* ═══════════════════════════════════════════════════════
   OPERACIONES POR HORA
═══════════════════════════════════════════════════════ */
.hora-wrap { background:var(--white); border:1px solid var(--border); border-radius:var(--r); padding:14px 16px; margin-bottom:14px; box-shadow:var(--shadow); }
.hora-filters { display:flex; flex-direction:column; gap:8px; margin-bottom:10px; }
.hora-filters select { font-size:13px; padding:8px 10px; border:1px solid var(--border); border-radius:8px; background:var(--white); color:var(--ink-1); width:100%; min-height:40px; }
.hora-filters select:focus { outline:2px solid var(--blue); border-color:transparent; }
.hora-canvas-wrap { position:relative; height:220px; }
@media (min-width:600px) {
  .hora-filters { flex-direction:row; }
  .hora-filters select { width:auto; min-height:36px; }
  .hora-canvas-wrap { height:260px; }
}

/* ═══════════════════════════════════════════════════════
   MARGEN POR MONEDA
═══════════════════════════════════════════════════════ */
.mcard { background:var(--white); border:1px solid var(--border); border-radius:var(--r); padding:14px 16px; margin-bottom:14px; box-shadow:var(--shadow); }
.mtable-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.mtable { width:100%; border-collapse:collapse; font-size:12px; min-width:500px; }
.mtable th { background:var(--bg); padding:8px 10px; text-align:left; font-size:10px; font-weight:700; color:var(--ink-3); text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid var(--border); white-space:nowrap; }
.mtable th.num { text-align:right; }
.mtable td { padding:8px 10px; border-bottom:.5px solid var(--border); color:var(--ink-1); vertical-align:middle; }
.mtable td.num { text-align:right; font-variant-numeric:tabular-nums; }
.mtable tr:last-child td { border-bottom:none; }
.mtable tr:hover td { background:var(--bg); }
@media (min-width:900px) { .mtable { min-width:unset; } }

/* ═══════════════════════════════════════════════════════
   EXPORT / PRINT
═══════════════════════════════════════════════════════ */
.btn-export-csv, .btn-print {
  background:none; border:1px solid var(--border);
  border-radius:8px; padding:0 13px; font-size:12px;
  color:var(--ink-2); cursor:pointer; min-height:40px;
}
.btn-export-csv:hover { border-color:var(--blue); color:var(--blue); }
.btn-print:hover { border-color:var(--green); color:var(--green); }
@media (min-width:600px) {
  .btn-export-csv, .btn-print { min-height:36px; }
}

/* ═══════════════════════════════════════════════════════
   PRINT
═══════════════════════════════════════════════════════ */
.print-header { display:none; }
/* ─── PLAZAS ─────────────────────────────────────────── */
.plazas-hero { display:flex; gap:12px; flex-direction:column; }
.plazas-winner { background:linear-gradient(135deg,#065F46,#047857); color:#fff; border-radius:10px; padding:16px 20px; display:flex; align-items:center; gap:16px; }
.plazas-winner .pw-icon { font-size:2rem; }
.plazas-winner .pw-text h3 { margin:0 0 4px; font-size:1rem; opacity:.8; font-weight:500; }
.plazas-winner .pw-text .pw-name { font-size:1.5rem; font-weight:700; }
.plazas-winner .pw-text .pw-sub { font-size:.8rem; opacity:.75; margin-top:4px; }
.plazas-cards { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.plaza-card { border-radius:10px; padding:16px; border:2px solid var(--border); }
.plaza-card .pc-flag { font-size:1.8rem; }
.plaza-card .pc-name { font-weight:700; font-size:1rem; margin:4px 0 12px; }
.plaza-card .pc-rows { display:flex; flex-direction:column; gap:6px; }
.plaza-card .pc-row { display:flex; justify-content:space-between; align-items:baseline; gap:8px; }
.plaza-card .pc-label { font-size:.75rem; color:var(--muted); }
.plaza-card .pc-val { font-size:.9rem; font-weight:600; }
.plaza-card .pc-val.win { color:#047857; }
.plaza-card.bo { border-color:#1D4ED8; }
.plaza-card.cl { border-color:#DC2626; }
.plazas-table { width:100%; border-collapse:collapse; margin-top:12px; font-size:.85rem; }
.plazas-table th { text-align:left; padding:6px 8px; border-bottom:2px solid var(--border); color:var(--muted); font-weight:600; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; }
.plazas-table td { padding:7px 8px; border-bottom:1px solid var(--border); }
.plazas-table .winner-cell    { color:#047857; font-weight:700; }
.plazas-table .winner-cell-cl { color:#DC2626; font-weight:700; }
.plazas-table .loser-cell     { color:#9CA3AF; }
/* ── calculadora arbitraje ── */
.arb-top-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; margin-bottom:14px; }
.arb-card { border:2px solid var(--border); border-radius:10px; padding:10px 12px; }
.arb-card .ac-mon { font-size:1.1rem; font-weight:800; }
.arb-card .ac-dir { font-size:.72rem; color:var(--muted); margin:2px 0 6px; }
.arb-card .ac-gan { font-size:1rem; font-weight:700; color:#047857; }
.arb-card .ac-pct { font-size:.72rem; color:var(--muted); }
.arb-card.negative .ac-gan { color:#DC2626; }
.arb-calc-wrap { background:var(--surface2,rgba(0,0,0,.04)); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
.arb-calc-wrap .calc-title { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-bottom:10px; }
.arb-calc-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
.arb-calc-row label { font-size:.75rem; color:var(--muted); display:block; margin-bottom:3px; }
.arb-calc-row input, .arb-calc-row select { padding:6px 8px; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text); font-size:.88rem; height:34px; }
.arb-calc-row input { width:110px; }
.arb-calc-row select { min-width:100px; }
.arb-calc-btn { height:34px; padding:0 14px; border:none; border-radius:6px; background:#1D4ED8; color:#fff; font-size:.85rem; font-weight:600; cursor:pointer; }
.arb-calc-btn:hover { background:#1e40af; }
.arb-result { margin-top:10px; display:none; }
.arb-result-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:8px; }
.arb-result-box { border-radius:8px; padding:8px 10px; background:var(--bg); border:1px solid var(--border); }
.arb-result-box .rb-label { font-size:.68rem; color:var(--muted); margin-bottom:2px; }
.arb-result-box .rb-val { font-size:.95rem; font-weight:700; }
.arb-result-box.rb-gain .rb-val { color:#047857; }
.arb-result-box.rb-loss .rb-val { color:#DC2626; }
.plazas-note { font-size:.75rem; color:var(--muted); margin-top:10px; line-height:1.5; }
@media(min-width:600px){
  .plazas-hero { flex-direction:row; align-items:flex-start; }
  .plazas-winner { flex:0 0 260px; flex-direction:column; align-items:flex-start; }
  .plazas-cards { flex:1; }
}
@media(max-width:480px){
  .plazas-cards { grid-template-columns:1fr; }
}

@media print {
  .controls, .tabs, .toolbar-caj, .cot-filters, .hora-filters,
  .btn-update, .btn-export-csv, .btn-print { display:none !important; }
  .print-header { display:flex; align-items:center; gap:16px; padding:10px 0 14px; border-bottom:2px solid #000; margin-bottom:18px; }
  .print-header img { height:32px; }
  .print-header span { font-size:13px; color:#444; }
  .top { border-bottom:none; padding:10px 16px; }
  .panel { display:none !important; }
  .panel.on { display:block !important; }
  .page { padding:0 16px; max-width:100%; }
  body { background:white; font-size:12px; }
  .col-usuario, .col-distr, .col-prom-c, .col-prom-v, .col-min-c, .col-min-v { display:table-cell !important; }
  .caj-tbl, .ptable, .mtable { min-width:unset; }
  .hora-canvas-wrap { height:200px; }
}
</style>
</head>
<body>

<!-- CABECERA IMPRESIÓN (visible solo con @media print) -->
<div class="print-header">
  <img src="logocompleto.png" alt="Gambarte">
  <span id="print-periodo">Dashboard Gerencia</span>
</div>

<!-- TOP BAR -->
<div class="top">
  <div class="logo">
    <img src="logocompleto.png" alt="Gambarte" style="height:36px;width:auto;display:block">
  </div>
  <div class="controls">
    <label>Desde</label>
    <input type="date" id="fDesde">
    <label>Hasta</label>
    <input type="date" id="fHasta">
    <button class="btn-update" onclick="reloadAll()">↻ Actualizar</button>
    <button class="btn-export-csv" onclick="exportCurrent()" title="Exportar tabla activa a CSV">↓ CSV</button>
    <button class="btn-print" onclick="exportPrint()" title="Imprimir / Guardar como PDF">🖨 PDF</button>
    <span id="status"></span>
  </div>
</div>

<!-- PESTAÑAS -->
<div class="tabs">
  <button class="tab on"  onclick="irA('hoy',this)">         <span class="dot"></span> Hoy          <span class="tab-badge" id="b-hoy">—</span></button>
  <button class="tab"     onclick="irA('saldos',this)">      🏦 Saldos       <span class="tab-badge" id="b-saldos">—</span></button>
  <button class="tab"     onclick="irA('resumen',this)">     📋 Resumen      <span class="tab-badge" id="b-resumen">—</span></button>
  <button class="tab"     onclick="irA('ganancias',this)">   💰 Ganancias    <span class="tab-badge" id="b-ganancias">—</span></button>
  <button class="tab"     onclick="irA('agencias',this)">    🏙️ Agencias     <span class="tab-badge" id="b-agencias">—</span></button>
  <button class="tab"     onclick="irA('cajeros',this)">     👥 Cajeros      <span class="tab-badge" id="b-cajeros">—</span></button>
  <button class="tab"     onclick="irA('cotizaciones',this)"> 📈 Cotizaciones <span class="tab-badge" id="b-cotizaciones">—</span></button>
  <button class="tab"     onclick="irA('plazas',this)">      🌎 Plazas       <span class="tab-badge" id="b-plazas">—</span></button>
  <button class="tab"     onclick="irA('tendencias',this)">  📊 Tendencias   <span class="tab-badge" id="b-tendencias">—</span></button>
</div>

<div class="page">

<!-- ═══ RESUMEN ═══════════════════════════════════════ -->
<div class="panel" id="panel-resumen">
  <div class="hero blue" id="hero-resumen">
    <div class="hero-icon">📋</div>
    <div class="hero-text">
      <h2>Total de operaciones en el período</h2>
      <div class="big" id="h-total-ops">—</div>
      <div class="sub" id="h-resumen-sub">Actualizando...</div>
    </div>
  </div>
  <div class="kgrid" id="kgrid-resumen"></div>
  <div class="stitle">Monedas más movidas en el período</div>
  <div class="rank-list" id="rank-resumen"></div>
</div>

<!-- ═══ GANANCIAS ══════════════════════════════════════ -->
<div class="panel" id="panel-ganancias">
  <div class="hero green" id="hero-ganancias">
    <div class="hero-icon">🏆</div>
    <div class="hero-text">
      <h2>Moneda que más ganancia generó</h2>
      <div class="big green-v" id="h-top-moneda">—</div>
      <div class="sub" id="h-ganancias-sub">Actualizando...</div>
    </div>
  </div>
  <div class="kgrid" id="kgrid-ganancias"></div>
  <div class="stitle">Ganancia por moneda — de mayor a menor</div>
  <div class="rank-list" id="rank-ganancias"></div>

  <div class="stitle" style="margin-top:20px">Margen promedio por moneda</div>
  <div class="mcard">
    <div class="mtable-wrap">
      <table class="mtable">
        <thead><tr>
          <th>Moneda</th>
          <th class="num" style="border-left:2px solid var(--blue-lt)">Ops compra</th>
          <th class="num">Margen unit.</th>
          <th class="num">Margen %</th>
          <th class="num" style="border-left:2px solid var(--amber-lt)">Ops venta</th>
          <th class="num">Margen unit.</th>
          <th class="num">Margen %</th>
        </tr></thead>
        <tbody id="bodyMargen">
          <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--ink-3)">Cargando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ AGENCIAS ═══════════════════════════════════════ -->
<div class="panel" id="panel-agencias">
  <div class="hero amber" id="hero-agencias">
    <div class="hero-icon">🏆</div>
    <div class="hero-text">
      <h2>Agencia con mayor actividad</h2>
      <div class="big" id="h-top-agencia">—</div>
      <div class="sub" id="h-agencias-sub">Actualizando...</div>
    </div>
  </div>
  <div class="stitle">Actividad por agencia</div>
  <div class="ag-grid" id="ag-grid"></div>
</div>

<!-- ═══ HOY ════════════════════════════════════════════ -->
<div class="panel on" id="panel-hoy">
  <div class="hero live-h" id="hero-hoy">
    <div class="hero-icon">⚡</div>
    <div class="hero-text">
      <h2>Operaciones realizadas hoy</h2>
      <div class="big" id="h-hoy-total">—</div>
      <div class="sub" id="h-hoy-sub">Actualizando...</div>
    </div>
  </div>
  <div class="kgrid" id="kgrid-hoy"></div>
  <div class="stitle">Operaciones por hora del día</div>
  <div class="hora-wrap">
    <div class="hora-filters">
      <select id="horasFilAg" onchange="buildHorasChart()"><option value="">Todas las agencias</option></select>
    </div>
    <div class="hora-canvas-wrap"><canvas id="horasCanvas"></canvas></div>
  </div>
  <div class="stitle">Lo que más se está operando en el período</div>
  <div class="live-list" id="live-list"></div>
</div>

<!-- ═══ SALDOS ══════════════════════════════════════════ -->
<div class="panel" id="panel-saldos">
  <div class="hero blue" id="hero-saldos">
    <div class="hero-icon">💰</div>
    <div class="hero-text">
      <h2>Saldos de caja por agencia — hoy</h2>
      <div class="big" id="h-saldos-title">—</div>
      <div class="sub" id="h-saldos-sub">Actualizando...</div>
    </div>
  </div>
  <div class="stitle">Saldo por agencia</div>
  <div class="saldo-grid" id="saldo-grid"></div>

  <div class="stitle">Distribución del stock por moneda</div>
  <div class="pcard">
    <div class="dist-list" id="dist-list"></div>
  </div>
</div>

<!-- ═══ CAJEROS ════════════════════════════════════════ -->
<div class="panel" id="panel-cajeros">
  <div class="hero blue" id="hero-cajeros">
    <div class="hero-icon">👥</div>
    <div class="hero-text">
      <h2>Cajero con más operaciones en el período</h2>
      <div class="big" id="h-top-cajero">—</div>
      <div class="sub" id="h-cajeros-sub">Actualizando...</div>
    </div>
  </div>
  <div class="kgrid" id="kgrid-cajeros"></div>
  <div class="stitle">Operaciones por cajero</div>
  <div class="toolbar-caj">
    <div style="position:relative">
      <svg style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9AAABB;width:13px;height:13px;pointer-events:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" id="cajSrch" placeholder="Buscar cajero o agencia…" oninput="renderCajeros()" style="padding-left:28px;width:220px">
    </div>
    <select id="cajFilAg" onchange="renderCajeros()"><option value="">Todas las agencias</option></select>
    <span class="caj-cnt" id="cajCnt"></span>
  </div>
  <div class="caj-tbl-wrap">
    <table class="caj-tbl">
      <thead><tr>
        <th style="width:28px">#</th>
        <th onclick="cajSort('agencia',this)">Agencia <span style="opacity:.4;font-size:10px">↕</span></th>
        <th class="col-usuario" onclick="cajSort('login',this)">Usuario <span style="opacity:.4;font-size:10px">↕</span></th>
        <th onclick="cajSort('nombre',this)">Nombre <span style="opacity:.4;font-size:10px">↕</span></th>
        <th class="num" onclick="cajSort('cambio',this)">Cambio <span style="opacity:.4;font-size:10px">↕</span></th>
        <th class="num" onclick="cajSort('giro',this)">Giro <span style="opacity:.4;font-size:10px">↕</span></th>
        <th class="num" onclick="cajSort('remesa',this)">Remesa <span style="opacity:.4;font-size:10px">↕</span></th>
        <th class="num" onclick="cajSort('total',this)">Total <span style="opacity:.4;font-size:10px">↕</span></th>
        <th class="col-distr">Distribución</th>
      </tr></thead>
      <tbody id="bodyCajeros"></tbody>
    </table>
  </div>
</div>

<!-- ═══ COTIZACIONES ═══════════════════════════════════ -->
<!-- ═══ PLAZAS ════════════════════════════════════════ -->
<div class="panel" id="panel-plazas">
  <div id="plazas-content"><p style="color:var(--muted);padding:2rem">Cargando datos de Bolivia y Chile...</p></div>
</div>

<!-- ═══ TENDENCIAS ════════════════════════════════════════ -->
<div class="panel" id="panel-tendencias">
  <div id="tendencias-content"><p style="color:var(--muted);padding:2rem">Cargando tendencias...</p></div>
</div>

<div class="panel" id="panel-cotizaciones">
  <div class="hero amber" id="hero-cotizaciones">
    <div class="hero-icon">📊</div>
    <div class="hero-text">
      <h2>Precios de compra y venta por moneda</h2>
      <div class="big" id="h-cot-moneda">—</div>
      <div class="sub" id="h-cot-sub">Actualizando...</div>
    </div>
  </div>

  <!-- Tabla de precios -->
  <div class="stitle">Mayores precios de compra y venta en el período</div>
  <div class="pcard">
    <div class="ptable-wrap">
      <table class="ptable">
        <thead><tr>
          <th>Moneda</th>
          <th class="num">Máx. Compra</th>
          <th class="num col-prom-c">Prom. Compra</th>
          <th class="num col-min-c">Mín. Compra</th>
          <th class="num col-min-v">Mín. Venta</th>
          <th class="num col-prom-v">Prom. Venta</th>
          <th class="num">Máx. Venta</th>
          <th class="num">Spread</th>
          <th class="num">Días</th>
        </tr></thead>
        <tbody id="bodyPrecios"></tbody>
      </table>
    </div>
  </div>

  <!-- Historial gráfico -->
  <div class="stitle">Historial de cotizaciones base</div>
  <div class="cot-card">
    <div class="cot-filters">
      <select id="cotFilAg"  onchange="buildCotChart()"><option value="">— Agencia —</option></select>
      <select id="cotFilMon" onchange="buildCotChart()"><option value="">— Moneda —</option></select>
      <select id="cotFilTipo" onchange="buildCotChart()">
        <option value="AMBOS">COMPRA + VENTA</option>
        <option value="COMPRA">Solo COMPRA</option>
        <option value="VENTA">Solo VENTA</option>
      </select>
      <select id="cotFilMet" onchange="buildCotChart()">
        <option value="promedio">Promedio diario</option>
        <option value="maximo">Máximo diario</option>
        <option value="minimo">Mínimo diario</option>
      </select>
    </div>
    <div class="cot-canvas-wrap"><canvas id="cotChart"></canvas></div>
  </div>
</div>

</div><!-- /page -->

<script>
// ─── DATOS ────────────────────────────────────────────
var D = { op:[], util:[], ciudad:[], live:[], cajeros:[], precios:[], cotizaciones:[], horas:[], margen:[], comparativo:null, saldos:null, plazas:null };
var cotChart = null;
var horasChart = null;
var cajSortState = { field:'total', dir:'desc' };
var cotLoaded = false;

// ─── MONEDAS ──────────────────────────────────────────
var MON = {
  USD: { nombre:'Dólar estadounidense',   flag:'🇺🇸', color:'#1D4ED8' },
  EUR: { nombre:'Euro',                   flag:'🇪🇺', color:'#059669' },
  CLP: { nombre:'Peso chileno',           flag:'🇨🇱', color:'#7C3AED' },
  BRL: { nombre:'Real brasileño',         flag:'🇧🇷', color:'#DC2626' },
  ARS: { nombre:'Peso argentino',         flag:'🇦🇷', color:'#D97706' },
  PEN: { nombre:'Sol peruano',            flag:'🇵🇪', color:'#0891B2' },
  GBP: { nombre:'Libra esterlina',        flag:'🇬🇧', color:'#B45309' },
  CHF: { nombre:'Franco suizo',           flag:'🇨🇭', color:'#6B7280' },
  CAD: { nombre:'Dólar canadiense',       flag:'🇨🇦', color:'#B45309' },
  MXN: { nombre:'Peso mexicano',          flag:'🇲🇽', color:'#065F46' },
  BOB: { nombre:'Boliviano',              flag:'🇧🇴', color:'#1A2540' },
  AUD: { nombre:'Dólar australiano',      flag:'🇦🇺', color:'#0369A1' },
  JPY: { nombre:'Yen japonés',            flag:'🇯🇵', color:'#BE123C' },
  CNH: { nombre:'Yuan chino',             flag:'🇨🇳', color:'#B91C1C' },
  CHF: { nombre:'Franco suizo',           flag:'🇨🇭', color:'#6B7280' },
  NZD: { nombre:'Dólar neozelandés',      flag:'🇳🇿', color:'#0F766E' },
  COP: { nombre:'Peso colombiano',        flag:'🇨🇴', color:'#A16207' },
  PYG: { nombre:'Guaraní paraguayo',      flag:'🇵🇾', color:'#1D4ED8' },
  SEK: { nombre:'Corona sueca',           flag:'🇸🇪', color:'#1D4ED8' },
  NOK: { nombre:'Corona noruega',         flag:'🇳🇴', color:'#DC2626' },
  DKK: { nombre:'Corona danesa',          flag:'🇩🇰', color:'#DC2626' }
};
function mon(code){ return MON[code] || { nombre:code, flag:'💱', color:'#6B7280' }; }

// ─── TABS ─────────────────────────────────────────────
function irA(id, btn){
  document.querySelectorAll('.panel').forEach(function(p){ p.classList.remove('on'); });
  document.querySelectorAll('.tab').forEach(function(t){ t.classList.remove('on'); });
  document.getElementById('panel-'+id).classList.add('on');
  btn.classList.add('on');
  // carga lazy de cotizaciones (puede ser pesado)
  if(id==='cotizaciones' && !cotLoaded){
    cotLoaded=true;
    cargarSeccion('cotizaciones');
    cargarSeccion('precios');
  }
}

// ─── UTILIDADES ───────────────────────────────────────
function fmtBs(n){ return 'Bs. '+Math.abs(Number(n||0)).toLocaleString('es-BO',{minimumFractionDigits:0,maximumFractionDigits:0}); }
function fmtN(n){ return Number(n||0).toLocaleString('es-BO'); }
function fmtDec(n){ return Number(n||0).toLocaleString('es-BO',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function signo(n){ return n>=0?'+':'−'; }

function setStatus(msg,color){
  var el=document.getElementById('status');
  if(el) el.innerHTML='<span style="color:'+(color||'#9AAABB')+'">'+msg+'</span>';
}
function badge(id,txt){
  var el=document.getElementById(id);
  if(el) el.textContent=txt;
}
function medal(i){ return ['🥇','🥈','🥉'][i]||'<span style="color:#9AAABB;font-size:13px;font-weight:700">'+(i+1)+'</span>'; }

// ─── COMPARATIVO PERÍODO ANTERIOR ────────────────────
function periodoAnterior(){
  var desde=document.getElementById('fDesde').value;
  var hasta=document.getElementById('fHasta').value;
  var d1=new Date(desde), d2=new Date(hasta);
  var dias=Math.round((d2-d1)/(1000*86400))+1;
  var antH=new Date(d1); antH.setDate(antH.getDate()-1);
  var antD=new Date(antH); antD.setDate(antD.getDate()-(dias-1));
  var fmt=function(d){ var y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),dd=String(d.getDate()).padStart(2,'0'); return y+'-'+m+'-'+dd; };
  return { desde:fmt(antD), hasta:fmt(antH) };
}

function deltaBadge(act,ant){
  act=Number(act||0); ant=Number(ant||0);
  if(!ant) return '';
  var p=Math.round((act-ant)/ant*100);
  var up=p>=0;
  return '<span class="delta '+(up?'delta-up':'delta-dn')+'">'+(up?'↑':'↓')+Math.abs(p)+'%</span>';
}

function _patchDeltaResumen(){
  if(!D.comparativo||!D.op.length) return;
  var ant=D.comparativo.anterior;
  var totC=D.op.reduce(function(s,r){return s+(r.ops_compra||0);},0);
  var totV=D.op.reduce(function(s,r){return s+(r.ops_venta||0);},0);
  var totOps=totC+totV;
  var el=document.getElementById('h-total-ops');
  if(el) el.innerHTML=fmtN(totOps)+' operaciones'+deltaBadge(totOps,ant.total_ops);
  var ksub=document.getElementById('h-resumen-sub');
  if(ksub) ksub.innerHTML='Compras: <b>'+fmtN(totC)+'</b>'+deltaBadge(totC,ant.compras)+' &nbsp;·&nbsp; Ventas: <b>'+fmtN(totV)+'</b>'+deltaBadge(totV,ant.ventas)+' &nbsp;·&nbsp; '+D.op.length+' monedas operadas';
}

function renderComparativo(data){
  D.comparativo=data;
  _patchDeltaResumen();
}

// ─── RENDER RESUMEN ───────────────────────────────────
function renderResumen(rows){
  var totC=0,totV=0,totOps=0;
  rows.forEach(function(r){ totC+=(r.ops_compra||0); totV+=(r.ops_venta||0); });
  totOps=totC+totV;
  badge('b-resumen', fmtN(totOps)+' ops');
  document.getElementById('h-total-ops').textContent=fmtN(totOps)+' operaciones';
  document.getElementById('h-resumen-sub').innerHTML=
    'Compras: <b>'+fmtN(totC)+'</b> &nbsp;·&nbsp; Ventas: <b>'+fmtN(totV)+'</b> &nbsp;·&nbsp; '+rows.length+' monedas operadas';
  // patch deltas if comparativo already arrived
  if(D.comparativo) _patchDeltaResumen();

  document.getElementById('kgrid-resumen').innerHTML=
    '<div class="kcard blue"><div class="kcard-lbl">Total operaciones</div><div class="kcard-val">'+fmtN(totOps)+'</div><div class="kcard-sub">en el período</div></div>'+
    '<div class="kcard green"><div class="kcard-lbl">Compras realizadas</div><div class="kcard-val">'+fmtN(totC)+'</div><div class="kcard-sub">moneda extranjera comprada</div></div>'+
    '<div class="kcard amber"><div class="kcard-lbl">Ventas realizadas</div><div class="kcard-val">'+fmtN(totV)+'</div><div class="kcard-sub">moneda extranjera vendida</div></div>';

  // ranking por ops totales
  var sorted=rows.slice().sort(function(a,b){
    return ((b.ops_compra||0)+(b.ops_venta||0))-((a.ops_compra||0)+(a.ops_venta||0));
  });
  var maxOps=(sorted[0]?(sorted[0].ops_compra||0)+(sorted[0].ops_venta||0):1);
  document.getElementById('rank-resumen').innerHTML=sorted.map(function(r,i){
    var ops=(r.ops_compra||0)+(r.ops_venta||0);
    var pct=maxOps>0?Math.round(ops/maxOps*100):0;
    var m=mon(r.moneda);
    return '<div class="rank-row">'+
      '<div class="rank-pos">'+medal(i)+'</div>'+
      '<div class="rank-info">'+
        '<div class="rank-name">'+m.flag+' '+m.nombre+'</div>'+
        '<div class="rank-detail">'+fmtN(r.ops_compra||0)+' compras &nbsp;·&nbsp; '+fmtN(r.ops_venta||0)+' ventas</div>'+
        '<div class="rank-bar-wrap"><div class="rank-bar" style="width:'+pct+'%;background:'+m.color+'"></div></div>'+
      '</div>'+
      '<div class="rank-amount">'+fmtN(ops)+' ops</div>'+
    '</div>';
  }).join('');
}

// ─── RENDER GANANCIAS ─────────────────────────────────
function renderGanancias(rows){
  var sorted=rows.slice().sort(function(a,b){ return (b.util_total||0)-(a.util_total||0); });
  var ganancia=sorted.filter(function(r){return (r.util_total||0)>0;});
  var perdida=sorted.filter(function(r){return (r.util_total||0)<0;});
  var totGan=sorted.reduce(function(s,r){return s+(r.util_total||0);},0);
  var best=sorted[0]||{};
  var bm=mon(best.moneda||'');

  badge('b-ganancias', fmtBs(totGan));
  document.getElementById('h-top-moneda').textContent=bm.flag+' '+bm.nombre;
  document.getElementById('h-ganancias-sub').innerHTML=
    'Generó <b>'+fmtBs(best.util_total)+'</b> en el período &nbsp;·&nbsp; Ganancia total: <b>'+fmtBs(totGan)+'</b>';

  document.getElementById('kgrid-ganancias').innerHTML=
    '<div class="kcard green"><div class="kcard-lbl">Ganancia total</div><div class="kcard-val" style="color:var(--green)">'+fmtBs(totGan)+'</div><div class="kcard-sub">todas las monedas</div></div>'+
    '<div class="kcard green"><div class="kcard-lbl">Monedas con ganancia</div><div class="kcard-val">'+ganancia.length+'</div><div class="kcard-sub">generaron utilidad positiva</div></div>'+
    (perdida.length?'<div class="kcard red"><div class="kcard-lbl">Monedas con pérdida</div><div class="kcard-val" style="color:var(--red)">'+perdida.length+'</div><div class="kcard-sub">requieren revisión de tipo de cambio</div></div>':'');

  var maxAbs=Math.max.apply(null,sorted.map(function(r){return Math.abs(r.util_total||0);}));
  maxAbs=maxAbs||1;
  document.getElementById('rank-ganancias').innerHTML=sorted.map(function(r,i){
    var v=r.util_total||0;
    var m=mon(r.moneda);
    var pct=Math.round(Math.abs(v)/maxAbs*100);
    var isWin=v>0, isLos=v<0;
    return '<div class="rank-row'+(isWin&&i===0?' winner':isLos?' loser':'')+'">'+
      '<div class="rank-pos">'+medal(i)+'</div>'+
      '<div class="rank-info">'+
        '<div class="rank-name">'+m.flag+' '+m.nombre+'</div>'+
        '<div class="rank-detail">'+
          'Compra: '+fmtBs(r.util_compra||0)+' &nbsp;·&nbsp; Venta: '+fmtBs(r.util_venta||0)+
        '</div>'+
        '<div class="rank-bar-wrap"><div class="rank-bar" style="width:'+pct+'%;background:'+(isLos?'var(--red)':m.color)+'"></div></div>'+
      '</div>'+
      '<div class="rank-amount">'+(isLos?'−':'')+fmtBs(Math.abs(v))+'</div>'+
    '</div>';
  }).join('');
}

// ─── RENDER AGENCIAS ──────────────────────────────────
function renderAgencias(rows){
  var agMap={};
  rows.forEach(function(r){
    var a=r.agencia||'Sin nombre';
    if(!agMap[a]) agMap[a]={agencia:a,ops:0,vol:0,ganancia:0,monedas:{}};
    agMap[a].ops+=(r.ops_compra||0)+(r.ops_venta||0);
    agMap[a].vol+=(r.vol_compra||0)+(r.vol_venta||0);
    agMap[a].ganancia+=(r.util_compra||0)+(r.util_venta||0);
    agMap[a].monedas[r.moneda]=true;
  });
  var agList=Object.values(agMap).sort(function(a,b){return b.ops-a.ops;});
  var maxOps=agList.length?agList[0].ops:1;
  var best=agList[0]||{};
  var bestShort=(best.agencia||'').replace(/^AGENCIA\s*/i,'');

  badge('b-agencias', agList.length+' ag.');
  document.getElementById('h-top-agencia').textContent=bestShort;
  document.getElementById('h-agencias-sub').innerHTML=
    '<b>'+fmtN(best.ops||0)+' operaciones</b> en el período &nbsp;·&nbsp; '+Object.keys(best.monedas||{}).length+' monedas diferentes';

  document.getElementById('ag-grid').innerHTML=agList.map(function(ag,i){
    var pct=maxOps>0?Math.round(ag.ops/maxOps*100):0;
    var short=ag.agencia.replace(/^AGENCIA\s*/i,'');
    var isTop=i===0;
    var monList=Object.keys(ag.monedas).map(function(c){return mon(c).flag;}).join(' ');
    var ganPos=ag.ganancia>=0;
    var hasGan=ag.ganancia!==0;
    return '<div class="ag-card'+(isTop?' top':'')+'">'+
      (isTop?'<div class="ag-top-tag">★ La más activa</div>':'')+
      '<div class="ag-name">'+short+'</div>'+
      '<div class="ag-sub">'+monList+' &nbsp;·&nbsp; '+Object.keys(ag.monedas).length+' monedas</div>'+
      '<div class="ag-stat">'+
        '<span class="ag-stat-lbl">Operaciones</span>'+
        '<span class="ag-stat-val">'+fmtN(ag.ops)+'</span>'+
      '</div>'+
      (hasGan?'<div class="ag-stat">'+
        '<span class="ag-stat-lbl">Ganancia</span>'+
        '<span class="ag-stat-val" style="color:'+(ganPos?'var(--green)':'var(--red)')+'">'+
          (ganPos?'':'−')+fmtBs(Math.abs(ag.ganancia))+
        '</span></div>':'')+
      '<div class="ag-bar-wrap"><div class="ag-bar" style="width:'+pct+'%"></div></div>'+
    '</div>';
  }).join('');
}

// ─── RENDER HOY ───────────────────────────────────────
function renderHoy(rows){
  var sorted=rows.slice().sort(function(a,b){
    return ((b.ventas_hoy||0)+(b.compras_hoy||0))-((a.ventas_hoy||0)+(a.compras_hoy||0));
  });
  var totOps=sorted.reduce(function(s,r){return s+(r.compras_hoy||0)+(r.ventas_hoy||0);},0);
  var totVol=sorted.reduce(function(s,r){return s+(r.vol_venta_hoy||0)+(r.vol_compra_hoy||0);},0);
  var top=sorted[0]||{};
  var tm=mon(top.moneda||'');

  badge('b-hoy', fmtN(totOps)+' ops');
  document.getElementById('h-hoy-total').textContent=fmtN(totOps)+' operaciones';
  document.getElementById('h-hoy-sub').innerHTML=
    'Lo más vendido: <b>'+tm.flag+' '+tm.nombre+'</b> &nbsp;·&nbsp; Volumen total: <b>'+fmtN(Math.round(totVol))+'</b>';

  var hora=new Date().toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'});
  document.getElementById('kgrid-hoy').innerHTML=
    '<div class="kcard blue"><div class="kcard-lbl">Compras de hoy</div><div class="kcard-val">'+fmtN(sorted.reduce(function(s,r){return s+(r.compras_hoy||0);},0))+'</div><div class="kcard-sub">operaciones</div></div>'+
    '<div class="kcard amber"><div class="kcard-lbl">Ventas de hoy</div><div class="kcard-val">'+fmtN(sorted.reduce(function(s,r){return s+(r.ventas_hoy||0);},0))+'</div><div class="kcard-sub">operaciones</div></div>'+
    '<div class="kcard green"><div class="kcard-lbl">Última actualización</div><div class="kcard-val" style="font-size:20px">'+hora+'</div><div class="kcard-sub">presione Actualizar para refrescar</div></div>';

  var maxOps=(sorted[0]?(sorted[0].ventas_hoy||0)+(sorted[0].compras_hoy||0):1);
  document.getElementById('live-list').innerHTML=sorted.map(function(r,i){
    var ops=(r.ventas_hoy||0)+(r.compras_hoy||0);
    var pct=maxOps>0?Math.round(ops/maxOps*100):0;
    var m=mon(r.moneda);
    return '<div class="live-row">'+
      '<div class="live-flag">'+m.flag+'</div>'+
      '<div style="flex:1;min-width:0">'+
        '<div class="live-name">'+m.nombre+'</div>'+
        '<div class="live-ops">'+fmtN(r.compras_hoy||0)+' compras &nbsp;·&nbsp; '+fmtN(r.ventas_hoy||0)+' ventas</div>'+
        '<div class="live-bar-wrap"><div class="live-bar" style="width:'+pct+'%;background:'+m.color+'"></div></div>'+
      '</div>'+
      '<div class="live-right">'+
        (i===0?'<div class="live-tag"><span class="dot"></span> Top</div><br>':'')+
        '<div class="live-total">'+fmtN(ops)+'</div>'+
        '<div style="font-size:11px;color:var(--ink-3)">operaciones</div>'+
      '</div>'+
    '</div>';
  }).join('');
}

// ─── RENDER CAJEROS ──────────────────────────────────
function populateCajFiltros(){
  var ags=[]; D.cajeros.forEach(function(r){ if(r.agencia && ags.indexOf(r.agencia)===-1) ags.push(r.agencia); });
  ags.sort();
  var sel=document.getElementById('cajFilAg');
  sel.innerHTML='<option value="">Todas las agencias</option>'+ags.map(function(a){ return '<option value="'+a+'">'+a+'</option>'; }).join('');
}

function cajSort(field, th){
  cajSortState.dir = (cajSortState.field===field && cajSortState.dir==='desc') ? 'asc' : 'desc';
  cajSortState.field = field;
  document.querySelectorAll('.caj-tbl th span').forEach(function(s){ s.style.opacity='0.4'; s.textContent='↕'; });
  var sa=th.querySelector('span'); if(sa){ sa.style.opacity='1'; sa.textContent=cajSortState.dir==='asc'?'↑':'↓'; }
  renderCajeros();
}

function renderCajeros(){
  if(!D.cajeros.length){ document.getElementById('bodyCajeros').innerHTML='<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--ink-3)">Sin datos — presione Actualizar</td></tr>'; return; }
  var q=(document.getElementById('cajSrch').value||'').toLowerCase();
  var ag=document.getElementById('cajFilAg').value;
  var rows=D.cajeros.filter(function(r){
    return (!q||((r.agencia||'')+(r.login||'')+(r.nombre||'')).toLowerCase().indexOf(q)>-1)&&(!ag||r.agencia===ag);
  });
  rows=rows.slice().sort(function(a,b){
    var f=cajSortState.field, va=a[f]||0, vb=b[f]||0;
    var cmp=typeof va==='number'?(va-vb):String(va).localeCompare(String(vb));
    return cajSortState.dir==='asc'?cmp:-cmp;
  });

  document.getElementById('cajCnt').textContent=rows.length+' cajero(s)';
  var maxTotal=Math.max.apply(null,rows.map(function(r){return r.total||0;}));
  maxTotal=maxTotal||1;

  document.getElementById('bodyCajeros').innerHTML=rows.length ? rows.map(function(r,i){
    var pctC=Math.round((r.cambio||0)/maxTotal*80);
    var pctG=Math.round((r.giro||0)/maxTotal*80);
    var pctR=Math.round((r.remesa||0)/maxTotal*80);
    var short=(r.agencia||'').replace(/^AGENCIA\s*/i,'');
    return '<tr>'+
      '<td style="color:var(--ink-3);font-size:11px;font-weight:700;text-align:center">'+(i+1)+'</td>'+
      '<td><span class="caj-badge">'+short+'</span></td>'+
      '<td class="col-usuario"><span class="caj-code">'+r.login+'</span></td>'+
      '<td style="font-weight:600;font-size:12px">'+r.nombre+'</td>'+
      '<td class="num"><span class="pill-c">'+fmtN(r.cambio||0)+'</span></td>'+
      '<td class="num"><span class="pill-g">'+fmtN(r.giro||0)+'</span></td>'+
      '<td class="num"><span class="pill-r">'+fmtN(r.remesa||0)+'</span></td>'+
      '<td class="num"><strong>'+fmtN(r.total||0)+'</strong></td>'+
      '<td class="col-distr" style="white-space:nowrap">'+
        '<span class="micro-bar-c" style="width:'+pctC+'px" title="Cambio: '+r.cambio+'"></span>'+
        (pctG?'<span class="micro-bar-g" style="width:'+pctG+'px;margin-left:2px" title="Giro: '+r.giro+'"></span>':'')+
        (pctR?'<span class="micro-bar-r" style="width:'+pctR+'px;margin-left:2px" title="Remesa: '+r.remesa+'"></span>':'')+
      '</td>'+
    '</tr>';
  }).join('') : '<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--ink-3)">Sin resultados</td></tr>';
}

function renderCajerosFull(rows){
  D.cajeros=rows;
  var totOps=rows.reduce(function(s,r){return s+(r.total||0);},0);
  var totC=rows.reduce(function(s,r){return s+(r.cambio||0);},0);
  var totG=rows.reduce(function(s,r){return s+(r.giro||0);},0);
  var totR=rows.reduce(function(s,r){return s+(r.remesa||0);},0);
  var top=rows[0]||{};

  badge('b-cajeros', rows.length+' caj.');
  document.getElementById('h-top-cajero').textContent=top.nombre||'—';
  document.getElementById('h-cajeros-sub').innerHTML=
    'Realizó <b>'+fmtN(top.total||0)+' operaciones</b> en el período &nbsp;·&nbsp; '+rows.length+' cajeros activos';

  document.getElementById('kgrid-cajeros').innerHTML=
    '<div class="kcard blue"><div class="kcard-lbl">Cajeros activos</div><div class="kcard-val">'+rows.length+'</div><div class="kcard-sub">en el período</div></div>'+
    '<div class="kcard blue"><div class="kcard-lbl">Op. cambio</div><div class="kcard-val">'+fmtN(totC)+'</div><div class="kcard-sub">total</div></div>'+
    (totG?'<div class="kcard amber"><div class="kcard-lbl">Op. giro</div><div class="kcard-val">'+fmtN(totG)+'</div><div class="kcard-sub">total</div></div>':'')+
    (totR?'<div class="kcard green"><div class="kcard-lbl">Op. remesa</div><div class="kcard-val">'+fmtN(totR)+'</div><div class="kcard-sub">total</div></div>':'')+
    '<div class="kcard green"><div class="kcard-lbl">Total operaciones</div><div class="kcard-val">'+fmtN(totOps)+'</div><div class="kcard-sub">todos los tipos</div></div>';

  populateCajFiltros();
  renderCajeros();
}

// ─── RENDER PRECIOS ──────────────────────────────────
function renderPrecios(rows){
  D.precios=rows;
  if(!rows.length){ document.getElementById('bodyPrecios').innerHTML='<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--ink-3)">Sin datos</td></tr>'; return; }

  var best=rows[0]||{};
  var bm=mon(best.moneda||'');
  badge('b-cotizaciones', rows.length+' mon.');
  document.getElementById('h-cot-moneda').textContent=bm.flag+' '+bm.nombre;
  document.getElementById('h-cot-sub').innerHTML=
    'Compra máx: <b>'+fmtDec(best.max_compra)+'</b> &nbsp;·&nbsp; Venta máx: <b>'+fmtDec(best.max_venta)+'</b> &nbsp;·&nbsp; '+fmtN(best.dias_datos)+' días con datos';

  function fmt6(n){ return n!=null?Number(n).toLocaleString('es-BO',{minimumFractionDigits:2,maximumFractionDigits:6}):'—'; }
  document.getElementById('bodyPrecios').innerHTML=rows.map(function(r){
    var m=mon(r.moneda);
    var spread=r.prom_venta&&r.prom_compra ? (Number(r.prom_venta)-Number(r.prom_compra)) : null;
    return '<tr>'+
      '<td><span style="font-size:16px">'+m.flag+'</span> <strong>'+r.moneda+'</strong> <span style="font-size:11px;color:var(--ink-3)">'+m.nombre+'</span></td>'+
      '<td class="num cv">'+fmt6(r.max_compra)+'</td>'+
      '<td class="num col-prom-c" style="color:var(--ink-2)">'+fmt6(r.prom_compra)+'</td>'+
      '<td class="num col-min-c sub">'+fmt6(r.min_compra)+'</td>'+
      '<td class="num col-min-v sub">'+fmt6(r.min_venta)+'</td>'+
      '<td class="num col-prom-v" style="color:var(--ink-2)">'+fmt6(r.prom_venta)+'</td>'+
      '<td class="num vv">'+fmt6(r.max_venta)+'</td>'+
      '<td class="num spread">'+(spread!==null?'+'+Number(spread).toLocaleString('es-BO',{minimumFractionDigits:4,maximumFractionDigits:6}):'—')+'</td>'+
      '<td class="num" style="color:var(--ink-3)">'+r.dias_datos+'</td>'+
    '</tr>';
  }).join('');
}

// ─── RENDER COTIZACIONES CHART ────────────────────────
function populateCotFiltros(){
  var ags=[], mons=[];
  D.cotizaciones.forEach(function(r){
    if(r.agencia && ags.indexOf(r.agencia)===-1) ags.push(r.agencia);
    if(r.moneda  && mons.indexOf(r.moneda)===-1)  mons.push(r.moneda);
  });
  ags.sort(); mons.sort();
  var sa=document.getElementById('cotFilAg');
  sa.innerHTML=ags.map(function(a){return '<option value="'+a+'">'+a+'</option>';}).join('');
  var sm=document.getElementById('cotFilMon');
  var defMon=mons.indexOf('USD')>-1?'USD':mons[0];
  sm.innerHTML=mons.map(function(m){return '<option value="'+m+'"'+(m===defMon?' selected':'')+'>'+m+'</option>';}).join('');
}

function buildCotChart(){
  if(!D.cotizaciones.length) return;
  var ag  =document.getElementById('cotFilAg').value;
  var mon2=document.getElementById('cotFilMon').value;
  var tipo=document.getElementById('cotFilTipo').value;
  var met =document.getElementById('cotFilMet').value;
  var tipos=tipo==='AMBOS'?['COMPRA','VENTA']:[tipo];
  var COLORS={COMPRA:'#1D4ED8', VENTA:'#B45309'};

  var datasets=tipos.map(function(t){
    var pts=D.cotizaciones.filter(function(r){ return r.agencia===ag && r.moneda===mon2 && r.tipo===t; }).sort(function(a,b){ return a.fecha.localeCompare(b.fecha); });
    return {
      label:t,
      data:pts.map(function(r){ return {x:r.fecha, y:parseFloat(r[met])||null}; }),
      borderColor:COLORS[t],
      backgroundColor:COLORS[t]+'18',
      borderWidth:2.5,
      pointRadius:pts.length<=40?3:1,
      pointHoverRadius:5,
      tension:0.3,
      spanGaps:true,
      fill:tipo==='AMBOS'
    };
  });

  if(cotChart) cotChart.destroy();
  var ctx=document.getElementById('cotChart').getContext('2d');
  cotChart=new Chart(ctx,{
    type:'line',
    data:{datasets:datasets},
    options:{
      responsive:true, maintainAspectRatio:false,
      interaction:{mode:'index',intersect:false},
      plugins:{
        legend:{position:'top',labels:{boxWidth:12,font:{size:12}}},
        tooltip:{callbacks:{label:function(c){ return c.dataset.label+': '+(c.parsed.y!=null?c.parsed.y.toFixed(6):'N/A'); }}}
      },
      scales:{
        x:{type:'category',ticks:{font:{size:11},maxTicksLimit:14,maxRotation:40},grid:{display:false}},
        y:{ticks:{font:{size:11},callback:function(v){return v.toFixed(4);}},grid:{color:'rgba(0,0,0,0.05)'}}
      }
    }
  });
}

function renderCotizaciones(rows){
  D.cotizaciones=rows;
  populateCotFiltros();
  buildCotChart();
}

// ─── RENDER SALDOS ───────────────────────────────────
var AG_COLORS = ['#1D4ED8','#B45309','#047857','#7C3AED','#DC2626','#0891B2','#D97706','#065F46','#BE185D','#0E7490'];

function renderSaldos(data){
  D.saldos = data;
  var allMonedas = data.monedas || [];
  var rows       = data.rows    || [];
  var tc         = data.tc      || {};

  // Solo monedas con al menos un saldo != 0
  var monedas = allMonedas.filter(function(m){
    return rows.some(function(r){ return r[m] && r[m] !== 0; });
  });

  badge('b-saldos', rows.length + ' ag.');

  // Calcular total USD de cada agencia para el hero
  var tcUSD = tc['USD'] || 6.96;
  function toUSD(m, v){
    if(!v) return 0;
    if(m === 'USD') return v;
    if(m === 'BOB') return v / tcUSD;
    return v * (tc[m] || 0) / tcUSD;
  }
  var agTotUSD = rows.map(function(r){
    return monedas.reduce(function(s,m){ return s + toUSD(m, r[m]||0); }, 0);
  });
  var maxUSD = Math.max.apply(null, agTotUSD) || 1;
  var bestIdx = agTotUSD.indexOf(Math.max.apply(null, agTotUSD));
  var bestAg = (rows[bestIdx]||{}).agencia || '';
  var bestShort = bestAg.replace(/^AGENCIA\s*/i,'');
  var totUSD = agTotUSD.reduce(function(s,v){return s+v;},0);

  document.getElementById('h-saldos-title').textContent = bestShort || (rows.length+' agencias');
  document.getElementById('h-saldos-sub').innerHTML =
    rows.length+' agencias &nbsp;·&nbsp; '+monedas.length+' monedas con saldo &nbsp;·&nbsp; Stock total ≈ <b>USD '+Math.round(totUSD).toLocaleString('es-BO')+'</b>';

  function fmtCompacto(v){
    var abs = Math.abs(v);
    if(abs >= 1000000) return (v/1000000).toLocaleString('es-BO',{minimumFractionDigits:1,maximumFractionDigits:1})+'M';
    if(abs >= 10000)   return Math.round(v).toLocaleString('es-BO');
    return Number(v).toLocaleString('es-BO',{minimumFractionDigits:2,maximumFractionDigits:2});
  }

  // ── TARJETAS ───────────────────────────────────────────
  var grid = document.getElementById('saldo-grid');
  if(!rows.length){
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:32px;color:var(--ink-3)">Sin saldos registrados hoy</div>';
    document.getElementById('dist-list').innerHTML = '';
    return;
  }

  grid.innerHTML = rows.map(function(r, i){
    var sh = r.agencia.replace(/^AGENCIA\s*/i,'');
    var usd = agTotUSD[i];
    var pct = maxUSD > 0 ? Math.round(usd/maxUSD*100) : 0;
    var isTop = i === bestIdx;
    var filas = monedas.filter(function(m){ return r[m] && r[m] !== 0; }).map(function(m){
      var v = r[m];
      var mo = mon(m);
      var color = v > 0 ? 'var(--green)' : 'var(--red)';
      return '<div class="saldo-row">'+
        '<span class="saldo-flag">'+mo.flag+'</span>'+
        '<span class="saldo-mon">'+m+'</span>'+
        '<span class="saldo-val" style="color:'+color+'">'+fmtCompacto(v)+'</span>'+
      '</div>';
    }).join('');
    return '<div class="saldo-card"'+(isTop?' style="border-color:#A7F3D0;background:var(--green-lt)"':'')+'>'+
      (isTop?'<div style="font-size:10px;font-weight:700;color:var(--green);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">★ Mayor stock</div>':'')+
      '<div class="saldo-ag-name">'+sh+'</div>'+
      '<div class="saldo-ag-usd">≈ USD '+Math.round(usd).toLocaleString('es-BO')+
        ' &nbsp;<span style="display:inline-block;width:'+pct+'%;max-width:80px;height:4px;background:var(--blue);border-radius:2px;vertical-align:middle"></span></div>'+
      filas+
    '</div>';
  }).join('');

  // ── BARRAS DE DISTRIBUCIÓN ────────────────────────────
  // Totales por moneda
  var totales = {};
  monedas.forEach(function(m){
    totales[m] = rows.reduce(function(s,r){ return s+(r[m]||0); }, 0);
  });
  // Solo monedas con total > 0
  var monDist = monedas.filter(function(m){ return totales[m] > 0; });

  document.getElementById('dist-list').innerHTML = monDist.map(function(m){
    var tot = totales[m];
    var mo = mon(m);
    var segs = rows.map(function(r, i){
      var v = r[m] || 0;
      if(v <= 0) return '';
      var pct = Math.round(v/tot*100);
      if(!pct) return '';
      var sh = r.agencia.replace(/^AGENCIA\s*/i,'');
      return '<div class="dist-seg" style="width:'+pct+'%;background:'+AG_COLORS[i%AG_COLORS.length]+'" title="'+sh+': '+fmtCompacto(v)+'"></div>';
    }).join('');

    var legend = rows.map(function(r, i){
      var v = r[m] || 0;
      if(v <= 0) return '';
      var sh = r.agencia.replace(/^AGENCIA\s*/i,'');
      return '<div class="dist-legend-item">'+
        '<span class="dist-legend-dot" style="background:'+AG_COLORS[i%AG_COLORS.length]+'"></span>'+
        sh+' '+fmtCompacto(v)+
      '</div>';
    }).join('');

    return '<div>'+
      '<div class="dist-head">'+
        '<span class="dist-label">'+mo.flag+' '+m+'</span>'+
        '<span class="dist-total">Total: '+fmtCompacto(tot)+'</span>'+
      '</div>'+
      '<div class="dist-bar-wrap">'+segs+'</div>'+
      '<div class="dist-legend">'+legend+'</div>'+
    '</div>';
  }).join('');
}

// ─── RENDER HORAS ────────────────────────────────────
function populateHorasAgencias(){
  var ags=[];
  D.horas.forEach(function(r){ if(r.agencia && ags.indexOf(r.agencia)===-1) ags.push(r.agencia); });
  ags.sort();
  var sel=document.getElementById('horasFilAg');
  sel.innerHTML='<option value="">Todas las agencias</option>'+ags.map(function(a){ return '<option value="'+a+'">'+a+'</option>'; }).join('');
}

function buildHorasChart(){
  if(!D.horas.length) return;
  var ag=document.getElementById('horasFilAg').value;
  var compras=[], ventas=[];
  for(var i=0;i<24;i++){ compras.push(0); ventas.push(0); }
  D.horas.forEach(function(r){
    if(ag && r.agencia!==ag) return;
    var h=parseInt(r.hora||0);
    compras[h]+=parseInt(r.compras||0);
    ventas[h]+=parseInt(r.ventas||0);
  });
  var labels=[]; for(var h=0;h<24;h++) labels.push(h+'h');
  if(horasChart) horasChart.destroy();
  var ctx=document.getElementById('horasCanvas').getContext('2d');
  horasChart=new Chart(ctx,{
    type:'bar',
    data:{
      labels:labels,
      datasets:[
        {label:'Compras',data:compras,backgroundColor:'#1D4ED8',borderRadius:3,stack:'s'},
        {label:'Ventas', data:ventas, backgroundColor:'#B45309',borderRadius:3,stack:'s'}
      ]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{
        legend:{position:'top',labels:{boxWidth:12,font:{size:12}}},
        tooltip:{callbacks:{label:function(c){ return c.dataset.label+': '+c.parsed.y; }}}
      },
      scales:{
        x:{stacked:true,ticks:{font:{size:10}},grid:{display:false}},
        y:{stacked:true,beginAtZero:true,ticks:{font:{size:11},stepSize:1},grid:{color:'rgba(0,0,0,0.05)'}}
      }
    }
  });
}

function renderHoras(rows){
  D.horas=rows;
  populateHorasAgencias();
  buildHorasChart();
}

// ─── RENDER MARGEN ────────────────────────────────────
function renderMargen(rows){
  D.margen=rows;
  var body=document.getElementById('bodyMargen');
  if(!body) return;
  if(!rows.length){
    body.innerHTML='<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--ink-3)">Sin datos de margen para este período</td></tr>';
    return;
  }
  function fmt4(n){ return n!=null&&n!==''&&!isNaN(n)?Number(n).toLocaleString('es-BO',{minimumFractionDigits:2,maximumFractionDigits:4}):'—'; }
  function fmtPct(n){ return n!=null&&n!==''&&!isNaN(n)?Number(n).toFixed(2)+'%':'—'; }
  body.innerHTML=rows.map(function(r){
    var m=mon(r.moneda);
    return '<tr>'+
      '<td><span style="font-size:16px">'+m.flag+'</span> <strong>'+r.moneda+'</strong></td>'+
      '<td class="num" style="color:var(--ink-3)">'+fmtN(r.n_compra||0)+'</td>'+
      '<td class="num" style="color:var(--blue)">'+fmt4(r.margen_compra_unit)+'</td>'+
      '<td class="num" style="color:var(--green);font-weight:700">'+fmtPct(r.margen_compra_pct)+'</td>'+
      '<td class="num" style="color:var(--ink-3)">'+fmtN(r.n_venta||0)+'</td>'+
      '<td class="num" style="color:var(--amber)">'+fmt4(r.margen_venta_unit)+'</td>'+
      '<td class="num" style="color:var(--green);font-weight:700">'+fmtPct(r.margen_venta_pct)+'</td>'+
    '</tr>';
  }).join('');
}

// ─── EXPORTAR ─────────────────────────────────────────
function exportCSV(headers, rowsData, filename){
  var lines=[headers.map(function(h){return '"'+h+'"';}).join(',')];
  rowsData.forEach(function(r){
    lines.push(r.map(function(c){ return '"'+(c===null||c===undefined?'':String(c).replace(/"/g,'""'))+'"'; }).join(','));
  });
  var blob=new Blob(['﻿'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
  var a=document.createElement('a');
  a.href=URL.createObjectURL(blob);
  a.download=filename+'_'+new Date().toISOString().slice(0,10)+'.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(a.href);
}

function exportCurrent(){
  var tab=document.querySelector('.tab.on');
  var txt=tab?tab.textContent.toLowerCase():'';
  if(txt.indexOf('resumen')>-1){
    exportCSV(
      ['Moneda','Nombre','Compras','Ventas','Total ops'],
      D.op.map(function(r){ var m=mon(r.moneda); return [r.moneda,m.nombre,r.ops_compra||0,r.ops_venta||0,(r.ops_compra||0)+(r.ops_venta||0)]; }),
      'resumen'
    );
  } else if(txt.indexOf('ganancias')>-1){
    exportCSV(
      ['Moneda','Util. compra (Bs)','Util. venta (Bs)','Total (Bs)'],
      D.util.map(function(r){ return [r.moneda,r.util_compra||0,r.util_venta||0,r.util_total||0]; }),
      'ganancias'
    );
  } else if(txt.indexOf('agencia')>-1){
    exportCSV(
      ['Agencia','Moneda','Ops compra','Vol compra','Ops venta','Vol venta','Util compra','Util venta'],
      D.ciudad.map(function(r){ return [r.agencia,r.moneda,r.ops_compra||0,r.vol_compra||0,r.ops_venta||0,r.vol_venta||0,r.util_compra||0,r.util_venta||0]; }),
      'agencias'
    );
  } else if(txt.indexOf('hoy')>-1){
    exportCSV(
      ['Moneda','Compras','Vol compra','Ventas','Vol venta'],
      D.live.map(function(r){ return [r.moneda,r.compras_hoy||0,r.vol_compra_hoy||0,r.ventas_hoy||0,r.vol_venta_hoy||0]; }),
      'hoy'
    );
  } else if(txt.indexOf('cajero')>-1){
    exportCSV(
      ['Agencia','Login','Nombre','Cambio','Giro','Remesa','Total'],
      D.cajeros.map(function(r){ return [r.agencia,r.login,r.nombre,r.cambio||0,r.giro||0,r.remesa||0,r.total||0]; }),
      'cajeros'
    );
  } else if(txt.indexOf('cotizaciones')>-1){
    exportCSV(
      ['Moneda','Máx compra','Prom compra','Mín compra','Mín venta','Prom venta','Máx venta','Días'],
      D.precios.map(function(r){ return [r.moneda,r.max_compra,r.prom_compra,r.min_compra,r.min_venta,r.prom_venta,r.max_venta,r.dias_datos]; }),
      'precios'
    );
  }
}

function exportPrint(){
  var desde=document.getElementById('fDesde').value;
  var hasta=document.getElementById('fHasta').value;
  var el=document.getElementById('print-periodo');
  if(el) el.textContent='Dashboard Gerencia — Período: '+desde+' al '+hasta+' — '+new Date().toLocaleDateString('es-BO');
  window.print();
}

// ─── API ──────────────────────────────────────────────
var API='api_dashboard.php';

function numRows(rows){
  return rows.map(function(r){
    var o={};
    for(var k in r) o[k]=(r[k]===''||isNaN(r[k]))?r[k]:Number(r[k]);
    return o;
  });
}

function cargarSeccion(accion, extraParams){
  var desde=document.getElementById('fDesde').value;
  var hasta=document.getElementById('fHasta').value+' 23:59:59';
  var url=API+'?accion='+accion+'&desde='+encodeURIComponent(desde)+'&hasta='+encodeURIComponent(hasta);
  if(extraParams) url+=extraParams;
  return fetch(url,{credentials:'include'}).then(function(res){
    if(!res.ok) throw new Error('HTTP '+res.status);
    return res.json();
  }).then(function(raw){
    var data=Array.isArray(raw)?numRows(raw):raw;
    if(accion==='operativo')     { D.op=data;     renderResumen(data); }
    if(accion==='utilidades')    { D.util=data;   renderGanancias(data); }
    if(accion==='agencias')      { D.ciudad=data; renderAgencias(data); }
    if(accion==='live')          { D.live=data;   renderHoy(data); }
    if(accion==='cajeros')       { renderCajerosFull(data); }
    if(accion==='precios')       { renderPrecios(data); }
    if(accion==='cotizaciones')  { renderCotizaciones(data); }
    if(accion==='horas')         { renderHoras(data); }
    if(accion==='margen')        { renderMargen(data); }
    if(accion==='comparativo')   { renderComparativo(raw); }
    if(accion==='saldos')        { renderSaldos(raw); }
    if(accion==='plazas')        { renderPlazas(raw); }
    if(accion==='tendencias')    { renderTendencias(raw); }
  }).catch(function(e){ console.error(accion,e); });
}

function reloadAll(){
  var hora=new Date().toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'});
  setStatus('⏳ Cargando... '+hora,'#D97706');
  cotLoaded=false;
  D.comparativo=null;
  var ant=periodoAnterior();
  cargarSeccion('comparativo','&desde_ant='+encodeURIComponent(ant.desde)+'&hasta_ant='+encodeURIComponent(ant.hasta));
  Promise.all(['operativo','utilidades','agencias','live','cajeros','horas','margen','saldos','plazas','tendencias'].map(function(a){ return cargarSeccion(a); }))
    .then(function(){ setStatus('✓ Actualizado '+new Date().toLocaleTimeString('es-BO',{hour:'2-digit',minute:'2-digit'}),'#047857'); });
}

// ─── PLAZAS / ARBITRAJE ───────────────────────────────
function renderPlazas(data){
  D.plazas = data;
  var wrap = document.getElementById('plazas-content');
  if(!data || (!data.monedas && !data.cl_error)){
    wrap.innerHTML='<p style="color:var(--muted);padding:2rem">Sin datos.</p>'; return;
  }

  var rows  = data.monedas || [];
  var TC_BO = Number(data.tc_usd_bob || 6.96);
  var TC_CL = Number(data.tc_usd_clp || 930);
  var clErr = data.cl_error
    ? '<div style="color:#DC2626;font-size:.8rem;margin-bottom:10px">⚠ Chile no disponible: '+data.cl_error+'</div>' : '';

  function fmt(v,d){ return v==null?'—':Number(v).toLocaleString('es-BO',{minimumFractionDigits:d,maximumFractionDigits:d}); }
  function fmtPct(v){ return v==null?'—':(v>=0?'+':'')+Number(v).toFixed(2)+'%'; }
  function fmtU(v){
    if(v==null) return '—';
    var s='$'+Math.abs(v).toFixed(2);
    return v<0?'<span style="color:#DC2626">−'+s+'</span>':'<span style="color:#047857">+'+s+'</span>';
  }

  // ── Tabla 1: spread propio
  var bo_wins=0, cl_wins=0;
  rows.forEach(function(r){ if(r.mejor==='bolivia') bo_wins++; else if(r.mejor==='chile') cl_wins++; });

  var spreadRows = rows.map(function(r){
    var bo=r.bolivia||{}, cl=r.chile||{};
    var bw=r.mejor==='bolivia', cw=r.mejor==='chile';
    var bo_sub=bo.usd_x1000!=null?'<br><small style="opacity:.65">≈$'+Number(bo.usd_x1000).toFixed(2)+' x1000</small>':'';
    var cl_sub=cl.usd_x1000!=null?'<br><small style="opacity:.65">≈$'+Number(cl.usd_x1000).toFixed(2)+' x1000</small>':'';
    return '<tr>'+
      '<td><strong>'+r.moneda+'</strong></td>'+
      '<td>'+(bo.compra!=null?fmt(bo.compra,3)+' BOB':'—')+'</td>'+
      '<td>'+(bo.venta!=null ?fmt(bo.venta, 3)+' BOB':'—')+'</td>'+
      '<td class="'+(bw?'winner-cell':'')+'">'+(bo.spread_pct!=null?fmtPct(bo.spread_pct):'—')+bo_sub+'</td>'+
      '<td>'+(cl.compra!=null?fmt(cl.compra,0)+' CLP':'—')+'</td>'+
      '<td>'+(cl.venta!=null ?fmt(cl.venta, 0)+' CLP':'—')+'</td>'+
      '<td class="'+(cw?'winner-cell-cl':'')+'">'+(cl.spread_pct!=null?fmtPct(cl.spread_pct):'—')+cl_sub+'</td>'+
      '<td class="'+(bw?'winner-cell':cw?'winner-cell-cl':'')+'">'+(bw?'🇧🇴 Bolivia':cw?'🇨🇱 Chile':'—')+'</td>'+
    '</tr>';
  }).join('');

  // ── Tabla 2: arbitraje cross-plaza
  var arbRows = [];
  rows.forEach(function(r){
    var bo=r.bolivia||{}, cl=r.chile||{};
    if(!bo.compra||!bo.venta||!cl.compra||!cl.venta) return;
    var costo_bo=bo.compra/TC_BO, ingreso_cl=cl.venta/TC_CL;
    var ganA=ingreso_cl-costo_bo, margenA=costo_bo>0?ganA/costo_bo*100:null;
    var costo_cl=cl.compra/TC_CL, ingreso_bo=bo.venta/TC_BO;
    var ganB=ingreso_bo-costo_cl, margenB=costo_cl>0?ganB/costo_cl*100:null;
    var winA=ganA>0&&ganA>=ganB, winB=ganB>0&&ganB>ganA;
    arbRows.push('<tr>'+
      '<td><strong>'+r.moneda+'</strong></td>'+
      '<td style="font-size:.8rem">'+fmt(bo.compra,3)+' BOB<br><span style="opacity:.6">÷'+TC_BO.toFixed(2)+'= $'+costo_bo.toFixed(4)+'</span></td>'+
      '<td style="font-size:.8rem">'+fmt(cl.venta,0)+' CLP<br><span style="opacity:.6">÷'+TC_CL.toFixed(0)+'= $'+ingreso_cl.toFixed(4)+'</span></td>'+
      '<td class="'+(winA?'winner-cell':ganA<0?'loser-cell':'')+'" style="font-size:.85rem">'+fmtU(ganA*1000)+'<br><small style="opacity:.7">'+(margenA!=null?fmtPct(margenA):'—')+'</small></td>'+
      '<td style="font-size:.8rem">'+fmt(cl.compra,0)+' CLP<br><span style="opacity:.6">÷'+TC_CL.toFixed(0)+'= $'+costo_cl.toFixed(4)+'</span></td>'+
      '<td style="font-size:.8rem">'+fmt(bo.venta,3)+' BOB<br><span style="opacity:.6">÷'+TC_BO.toFixed(2)+'= $'+ingreso_bo.toFixed(4)+'</span></td>'+
      '<td class="'+(winB?'winner-cell':ganB<0?'loser-cell':'')+'" style="font-size:.85rem">'+fmtU(ganB*1000)+'<br><small style="opacity:.7">'+(margenB!=null?fmtPct(margenB):'—')+'</small></td>'+
      '<td style="font-weight:700">'+
        (ganA>0&&ganA>=ganB?'<span style="color:#047857">🇧🇴→🇨🇱</span>':
         ganB>0&&ganB>ganA ?'<span style="color:#047857">🇨🇱→🇧🇴</span>':
         '<span style="color:#DC2626">No aplica</span>')+
      '</td>'+
    '</tr>');
  });

  // ── Pre-calcular arbitraje para todas las monedas con datos en ambas plazas
  var arbData = {};   // keyed by moneda
  rows.forEach(function(r){
    var bo=r.bolivia||{}, cl=r.chile||{};
    if(!bo.compra||!bo.venta||!cl.compra||!cl.venta) return;
    var cBO=bo.compra/TC_BO, iCL=cl.venta/TC_CL;
    var cCL=cl.compra/TC_CL, iBO=bo.venta/TC_BO;
    arbData[r.moneda]={
      ganA:(iCL-cBO), pctA:cBO>0?(iCL-cBO)/cBO*100:0,
      ganB:(iBO-cCL), pctB:cCL>0?(iBO-cCL)/cCL*100:0,
      _cBO:cBO, _cCL:cCL,   // costo unitario en USD por cada ruta
      volBO:(r.bolivia.n_compra||0)+(r.bolivia.n_venta||0),
      volCL:(r.chile.n_compra||0)+(r.chile.n_venta||0)
    };
  });

  // ── Cards: monedas más operadas (por volumen total de operaciones)
  var cardMons = Object.keys(arbData).sort(function(a,b){
    return (arbData[b].volBO+arbData[b].volCL)-(arbData[a].volBO+arbData[a].volCL);
  }).slice(0,6);

  var cardsHtml = cardMons.map(function(mon){
    var d=arbData[mon];
    var useA = d.ganA >= d.ganB;
    var gan  = useA ? d.ganA : d.ganB;
    var pct  = useA ? d.pctA : d.pctB;
    var dir  = useA ? '🇧🇴 BO → CL 🇨🇱' : '🇨🇱 CL → BO 🇧🇴';
    var neg  = gan < 0;
    return '<div class="arb-card'+(neg?' negative':'')+'">'+
      '<div class="ac-mon">'+mon+'</div>'+
      '<div class="ac-dir">Mejor ruta: '+dir+'</div>'+
      '<div class="ac-gan">'+(neg?'−':'+')+' $'+Math.abs(gan*1000).toFixed(2)+' USD</div>'+
      '<div class="ac-pct">por cada 1.000 unidades &nbsp;|&nbsp; '+(pct>=0?'+':'')+pct.toFixed(1)+'%</div>'+
    '</div>';
  }).join('');

  // ── Calculadora: dropdown con todas las monedas con datos en ambas plazas
  var calcOpts = Object.keys(arbData).sort().map(function(m){
    return '<option value="'+m+'">'+m+'</option>';
  }).join('');

  // Datos para la calculadora (inyectados como JSON en el DOM)
  var arbJSON = JSON.stringify(arbData).replace(/</g,'\\u003c');

  var calcHtml =
    '<div class="arb-calc-wrap">'+
    '<div class="calc-title">🧮 Calculadora de arbitraje</div>'+
    '<div class="arb-calc-row">'+
      '<div><label>Moneda</label><select id="calc-mon">'+calcOpts+'</select></div>'+
      '<div><label>Ruta</label><select id="calc-dir">'+
        '<option value="A">🇧🇴 Comprar BO → Vender CL 🇨🇱</option>'+
        '<option value="B">🇨🇱 Comprar CL → Vender BO 🇧🇴</option>'+
      '</select></div>'+
      '<div><label>Cantidad (unidades)</label><input id="calc-qty" type="number" value="1000" min="1"></div>'+
      '<div><label>&nbsp;</label><button class="arb-calc-btn" onclick="calcArbitraje()">Calcular</button></div>'+
    '</div>'+
    '<div class="arb-result" id="arb-result">'+
      '<div class="arb-result-grid">'+
        '<div class="arb-result-box"><div class="rb-label">Costo (USD)</div><div class="rb-val" id="rb-costo">—</div></div>'+
        '<div class="arb-result-box"><div class="rb-label">Ingreso (USD)</div><div class="rb-val" id="rb-ingreso">—</div></div>'+
        '<div class="arb-result-box rb-gain" id="rb-gan-box"><div class="rb-label">Ganancia (USD)</div><div class="rb-val" id="rb-gan">—</div></div>'+
        '<div class="arb-result-box"><div class="rb-label">Margen %</div><div class="rb-val" id="rb-pct">—</div></div>'+
      '</div>'+
      '<p id="rb-concl" style="font-size:.82rem;margin:8px 0 0;line-height:1.5"></p>'+
    '</div>'+
    '</div>';

  // Exponer datos y función globalmente (innerHTML no ejecuta <script>)
  window._arbData = arbData;
  window._TC_BO   = TC_BO;
  window._TC_CL   = TC_CL;
  window.calcArbitraje = function() {
    var mon = document.getElementById('calc-mon').value;
    var dir = document.getElementById('calc-dir').value;
    var qty = parseFloat(document.getElementById('calc-qty').value) || 1000;
    var d = window._arbData[mon]; if (!d) return;
    var gan = dir === 'A' ? d.ganA : d.ganB;
    var pct = dir === 'A' ? d.pctA : d.pctB;
    var ganTotal    = gan * qty;
    var costoTotal  = (dir === 'A' ? d._cBO : d._cCL) * qty;
    var ingTotal    = costoTotal + ganTotal;
    document.getElementById('rb-costo').textContent   = '$' + costoTotal.toFixed(2);
    document.getElementById('rb-ingreso').textContent = '$' + ingTotal.toFixed(2);
    document.getElementById('rb-gan').textContent     = (ganTotal >= 0 ? '+' : '') + ganTotal.toFixed(2) + ' USD';
    document.getElementById('rb-pct').textContent     = (pct >= 0 ? '+' : '') + pct.toFixed(2) + '%';
    var box = document.getElementById('rb-gan-box');
    box.className = 'arb-result-box ' + (ganTotal >= 0 ? 'rb-gain' : 'rb-loss');
    var ruta = dir === 'A' ? 'comprar en Bolivia y vender en Chile' : 'comprar en Chile y vender en Bolivia';
    var concl = ganTotal >= 0
      ? '✅ <strong>Conviene.</strong> Llevando <strong>' + qty.toLocaleString('es-BO') + ' ' + mon + '</strong> (' + ruta + ') se estima ganar <strong>$' + ganTotal.toFixed(2) + ' USD</strong> (' + pct.toFixed(2) + '% de margen).'
      : '❌ <strong>No conviene.</strong> Esa ruta genera pérdida de $' + Math.abs(ganTotal).toFixed(2) + ' USD con los precios actuales.';
    document.getElementById('rb-concl').innerHTML = concl;
    document.getElementById('arb-result').style.display = 'block';
  };

  var html =
    '<div class="pcard" style="padding:14px 16px 18px">'+
    clErr+
    (cardMons.length
      ? '<div class="stitle" style="margin-bottom:8px">💰 Ganancia estimada por 1.000 unidades — monedas más operadas</div>'+
        '<div class="arb-top-cards">'+cardsHtml+'</div>'
      : '')+
    calcHtml+
    '<hr style="border:none;border-top:1px solid var(--border);margin:4px 0 14px">'+
    '<div class="stitle" style="margin-bottom:8px">📊 Spread por plaza (operar en el mismo país)</div>'+
    '<p style="font-size:.78rem;color:var(--muted);margin:0 0 8px">¿Si compro y vendo en la misma plaza, cuánto gano por cada 1.000 unidades?</p>'+
    '<div style="overflow-x:auto"><table class="plazas-table">'+
    '<thead><tr>'+
      '<th rowspan="2">Moneda</th>'+
      '<th colspan="3" style="text-align:center;border-bottom:1px solid var(--border)">🇧🇴 Bolivia (BOB) — tasas vigentes</th>'+
      '<th colspan="3" style="text-align:center;border-bottom:1px solid var(--border)">🇨🇱 Chile (CLP) — tasas vigentes</th>'+
      '<th rowspan="2">Mejor<br>plaza</th>'+
    '</tr><tr>'+
      '<th>Compra</th><th>Venta</th><th>Spread%<br><small>USD x1000</small></th>'+
      '<th>Compra</th><th>Venta</th><th>Spread%<br><small>USD x1000</small></th>'+
    '</tr></thead>'+
    '<tbody>'+spreadRows+'</tbody>'+
    '</table></div>'+
    (arbRows.length>0
      ? '<div class="stitle" style="margin:16px 0 8px">🔄 Arbitraje entre plazas (comprar en un país, vender en el otro)</div>'+
        '<p style="font-size:.78rem;color:var(--muted);margin:0 0 8px">Costo y precio en USD usando TC vigente (1 USD = '+TC_BO.toFixed(2)+' BOB | '+TC_CL.toFixed(0)+' CLP). Ganancia por llevar 1.000 unidades de un país al otro.</p>'+
        '<div style="overflow-x:auto"><table class="plazas-table">'+
        '<thead><tr>'+
          '<th rowspan="2">Moneda</th>'+
          '<th colspan="3" style="text-align:center;border-bottom:1px solid var(--border)">🇧🇴 Comprar BO → Vender CL 🇨🇱</th>'+
          '<th colspan="3" style="text-align:center;border-bottom:1px solid var(--border)">🇨🇱 Comprar CL → Vender BO 🇧🇴</th>'+
          '<th rowspan="2">Mejor<br>ruta</th>'+
        '</tr><tr>'+
          '<th>Costo (USD)</th><th>Ingreso (USD)</th><th>Ganancia x1000<br><small>Margen%</small></th>'+
          '<th>Costo (USD)</th><th>Ingreso (USD)</th><th>Ganancia x1000<br><small>Margen%</small></th>'+
        '</tr></thead>'+
        '<tbody>'+arbRows.join('')+'</tbody>'+
        '</table></div>'
      : '')+
    '<p class="plazas-note"><strong>Spread%</strong>: ganancia operando compra y venta en la misma plaza. <strong>Arbitraje</strong>: comprar en un país y vender en el otro. Negativo = pérdida. No incluye costos de traslado.</p>'+
    '</div>';

  wrap.innerHTML = html;
  badge('b-plazas', bo_wins>cl_wins?'🇧🇴 '+bo_wins:cl_wins>bo_wins?'🇨🇱 '+cl_wins:'⚖');
}

// ─── TENDENCIAS ───────────────────────────────────────
var _tendData = null; // caché del semáforo

function renderTendencias(data){
  _tendData = data;
  var wrap = document.getElementById('tendencias-content');
  if(!data || !data.length){
    wrap.innerHTML='<p style="color:var(--muted);padding:2rem">Sin datos de tendencias.</p>'; return;
  }

  var señalColor = {
    'COMPRAR':   {bg:'#dcfce7',color:'#15803d',border:'#16a34a'},
    'ACUMULAR':  {bg:'#d1fae5',color:'#065f46',border:'#059669'},
    'MANTENER':  {bg:'#fef9c3',color:'#a16207',border:'#ca8a04'},
    'PRECAUCION':{bg:'#fee2e2',color:'#b91c1c',border:'#ef4444'},
    'REDUCIR':   {bg:'#fce7f3',color:'#9d174d',border:'#ec4899'}
  };
  var señalIcon = {
    'COMPRAR':'🟢','ACUMULAR':'🟩','MANTENER':'🟡','PRECAUCION':'🟠','REDUCIR':'🔴'
  };

  function scoreBar(score){
    // score va de aprox -6 a +6
    var pct = Math.round((score + 6) / 12 * 100);
    if(pct<0) pct=0; if(pct>100) pct=100;
    var c = score>=3?'#16a34a':score>=1?'#65a30d':score<=-3?'#dc2626':score<=-1?'#ea580c':'#ca8a04';
    return '<div style="background:var(--border,#e2e8f0);border-radius:4px;height:8px;width:100%;margin-top:2px">'
      +'<div style="width:'+pct+'%;height:8px;border-radius:4px;background:'+c+'"></div></div>';
  }

  // Filas del semáforo
  // Ordenar por volumen de operaciones (mayor primero)
  data.sort(function(a,b){ return b.ops_30d - a.ops_30d; });

  var rows = '';
  for(var i=0;i<data.length;i++){
    var r = data[i];
    var sc = r.señal || 'MANTENER';
    var col = señalColor[sc] || señalColor['MANTENER'];
    var icon = señalIcon[sc] || '⚪';
    var tendArrow = r.tend_pct > 0.1 ? '▲' : r.tend_pct < -0.1 ? '▼' : '─';
    var tendCol   = r.tend_pct > 0.1 ? '#15803d' : r.tend_pct < -0.1 ? '#dc2626' : '#64748b';
    var mInfo = mon(r.moneda);
    rows += '<tr onclick="cargarGraficoTend(\''+r.moneda+'\')" style="cursor:pointer" class="tend-row">'
      +'<td style="font-weight:700;font-size:.9rem">'+mInfo.flag+' '+r.moneda+'</td>'
      +'<td>'+r.venta_actual.toFixed(4)+'</td>'
      +'<td>'+r.sma7.toFixed(4)+'</td>'
      +'<td>'+r.sma30.toFixed(4)+'</td>'
      +'<td style="color:'+tendCol+';font-weight:700">'+tendArrow+' '+(r.tend_pct>0?'+':'')+r.tend_pct.toFixed(2)+'%</td>'
      +'<td>'+(r.volatilidad>3?'<span style="color:#dc2626">⚡ </span>':'')+r.volatilidad.toFixed(2)+'%</td>'
      +'<td>'+r.ops_30d+'</td>'
      +'<td style="font-size:.72rem;color:#64748b">'+r.score+'<br>'+scoreBar(r.score)+'</td>'
      +'<td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;'
        +'background:'+col.bg+';color:'+col.color+';border:1px solid '+col.border+'">'+icon+' '+sc+'</span></td>'
      +'</tr>';
  }

  var tableHtml = '<div style="overflow-x:auto">'
    +'<table style="width:100%;border-collapse:collapse;font-size:.8rem">'
    +'<thead><tr style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted,#64748b);border-bottom:2px solid var(--border,#e2e8f0)">'
    +'<th style="padding:8px 6px;text-align:left">Moneda</th>'
    +'<th style="padding:8px 6px;text-align:right">Venta actual</th>'
    +'<th style="padding:8px 6px;text-align:right">SMA 7d</th>'
    +'<th style="padding:8px 6px;text-align:right">SMA 30d</th>'
    +'<th style="padding:8px 6px;text-align:right">Tendencia</th>'
    +'<th style="padding:8px 6px;text-align:right">Volatilidad</th>'
    +'<th style="padding:8px 6px;text-align:right">Ops 30d</th>'
    +'<th style="padding:8px 6px;text-align:center;min-width:80px">Score</th>'
    +'<th style="padding:8px 6px;text-align:center">Señal</th>'
    +'</tr></thead>'
    +'<tbody id="tend-tbody">'+rows+'</tbody>'
    +'</table></div>';

  // Resumen top 3 señales de compra
  var compras = data.filter(function(r){ return r.señal==='COMPRAR' || r.señal==='ACUMULAR'; });
  var alertHtml = '';
  if(compras.length){
    alertHtml = '<div style="background:#f0fdf4;border:1px solid #16a34a;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:.8rem;color:#15803d">'
      +'<strong>🟢 Oportunidades de acumulación:</strong> '
      +compras.slice(0,4).map(function(r){ return '<strong>'+r.moneda+'</strong> (score '+r.score+')'; }).join(' · ')
      +'</div>';
  }
  var reducir = data.filter(function(r){ return r.señal==='REDUCIR' || r.señal==='PRECAUCION'; });
  if(reducir.length){
    alertHtml += '<div style="background:#fff1f2;border:1px solid #ef4444;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:.8rem;color:#b91c1c">'
      +'<strong>🔴 Señal de precaución:</strong> '
      +reducir.slice(0,4).map(function(r){ return '<strong>'+r.moneda+'</strong> (score '+r.score+')'; }).join(' · ')
      +'</div>';
  }

  wrap.innerHTML = '<div class="pcard" style="padding:14px 16px 18px">'
    +'<div class="stitle" style="margin-bottom:10px">📊 Semáforo de señales — Bolivia (agencia 10)</div>'
    +'<p style="font-size:.72rem;color:var(--muted);margin:0 0 10px">Basado en SMA 7d vs 30d, pendiente de precio, volatilidad y demanda operativa. Clic en una moneda para ver el gráfico histórico.</p>'
    +alertHtml
    +tableHtml
    +'<p style="font-size:.68rem;color:var(--muted);margin-top:10px">Score: tendencia×2 + pendiente + demanda − volatilidad. COMPRAR ≥ 3 · ACUMULAR ≥ 1 · MANTENER 0 · PRECAUCION ≤ −1 · REDUCIR ≤ −3</p>'
    +'</div>'
    +'<div class="pcard" style="padding:14px 16px 18px;margin-top:10px" id="tend-chart-wrap">'
    +'<p style="color:var(--muted);font-size:.82rem">← Clic en una moneda para ver su historial</p>'
    +'</div>';

  badge('b-tendencias', compras.length > 0 ? '🟢 '+compras.length : '🟡');

  // Hover highlight
  var rows_el = document.querySelectorAll('.tend-row');
  for(var j=0;j<rows_el.length;j++){
    rows_el[j].addEventListener('mouseenter', function(){ this.style.background='var(--surface2,rgba(0,0,0,.04))'; });
    rows_el[j].addEventListener('mouseleave', function(){ this.style.background=''; });
  }
}

window.cargarGraficoTend = function(moneda){
  var dias = 90;
  var sel = document.getElementById('tend-dias-sel');
  if(sel) dias = parseInt(sel.value)||90;
  var wrap = document.getElementById('tend-chart-wrap');
  if(!wrap) return;
  wrap.innerHTML = '<p style="color:var(--muted);padding:1rem;font-size:.82rem">⏳ Cargando '+moneda+'...</p>';
  // Guardar selección actual
  wrap.dataset.mon = moneda;

  fetch('api_dashboard.php?accion=tendencias&moneda='+encodeURIComponent(moneda)+'&dias='+dias)
    .then(function(r){ return r.json(); })
    .then(function(d){ dibujarGrafico(d, wrap); })
    .catch(function(e){ wrap.innerHTML='<p style="color:#dc2626">Error: '+e.message+'</p>'; });
};

function dibujarGrafico(data, wrap){
  if(!data || !data.serie || !data.serie.length){
    wrap.innerHTML='<p style="color:var(--muted);padding:1rem">Sin datos históricos para '+data.moneda+'.</p>'; return;
  }
  var serie   = data.serie;
  var monCode = data.moneda;
  var n       = serie.length;

  // Calcular SMA en JS
  function sma(arr, w){
    return arr.map(function(_, i){
      if(i < w-1) return null;
      var sum=0; for(var k=i-w+1;k<=i;k++) sum+=arr[k]; return sum/w;
    });
  }
  var ventas  = serie.map(function(r){ return r.venta; });
  var compras = serie.map(function(r){ return r.compra; });
  var sma7    = sma(ventas, 7);
  var sma30   = sma(ventas, 30);

  var all_vals = ventas.concat(compras).filter(function(v){ return v>0; });
  var minV = Math.min.apply(null, all_vals);
  var maxV = Math.max.apply(null, all_vals);
  var pad  = (maxV - minV) * 0.08 || maxV * 0.01;
  minV -= pad; maxV += pad;

  var W=700, H=220, PL=52, PR=12, PT=12, PB=32;
  var iW=W-PL-PR, iH=H-PT-PB;

  function xp(i){ return PL + i/(n-1)*iW; }
  function yp(v){ return PT + (1-(v-minV)/(maxV-minV))*iH; }

  // Grid lines Y (5 niveles)
  var gridLines='';
  for(var g=0;g<=4;g++){
    var yv = minV + g/4*(maxV-minV);
    var gy = yp(yv);
    gridLines += '<line x1="'+PL+'" y1="'+gy+'" x2="'+(W-PR)+'" y2="'+gy+'" stroke="var(--border,#e2e8f0)" stroke-width="1"/>';
    gridLines += '<text x="'+(PL-4)+'" y="'+(gy+4)+'" text-anchor="end" fill="var(--muted,#94a3b8)" font-size="9">'+yv.toFixed(yv>100?2:4)+'</text>';
  }
  // Grid X (mostrar fechas cada ~20 puntos)
  var step = Math.max(1, Math.round(n/5));
  for(var xi=0;xi<n;xi+=step){
    var xv = xp(xi);
    var lbl = serie[xi].fecha.slice(5); // MM-DD
    gridLines += '<text x="'+xv+'" y="'+(H-6)+'" text-anchor="middle" fill="var(--muted,#94a3b8)" font-size="9">'+lbl+'</text>';
  }

  function polyline(arr, stroke, sw, dash){
    var pts = arr.map(function(v,i){ return v===null?null:(xp(i)+','+yp(v)); }).filter(Boolean).join(' ');
    return '<polyline points="'+pts+'" fill="none" stroke="'+stroke+'" stroke-width="'+sw+'"'+(dash?' stroke-dasharray="'+dash+'"':'')+' stroke-linejoin="round" stroke-linecap="round"/>';
  }

  // Area bajo venta (semitransparente)
  var areaPath = 'M'+PL+','+PT+' ';
  for(var ai=0;ai<n;ai++) areaPath += (ai===0?'M':'L')+xp(ai)+','+yp(ventas[ai])+' ';
  areaPath += 'L'+xp(n-1)+','+(PT+iH)+' L'+PL+','+(PT+iH)+' Z';

  // Tooltip invisible rects
  var tooltips='';
  for(var ti=0;ti<n;ti++){
    var tx=xp(ti)-iW/(n*2), tw=iW/n;
    var vs=serie[ti];
    var tip=vs.fecha+' | V: '+vs.venta.toFixed(4)+' | C: '+vs.compra.toFixed(4)+(vs.ops?' | Ops: '+vs.ops:'');
    tooltips+='<rect x="'+tx+'" y="'+PT+'" width="'+tw+'" height="'+iH+'" fill="transparent" class="tend-tip">'
      +'<title>'+tip+'</title></rect>';
  }

  // Señal actual
  var cur = _tendData ? _tendData.filter(function(r){ return r.moneda===monCode; })[0] : null;
  var señalBadge = '';
  if(cur){
    var sc = cur.señal||'MANTENER';
    var scColors={'COMPRAR':'#15803d','ACUMULAR':'#065f46','MANTENER':'#a16207','PRECAUCION':'#b91c1c','REDUCIR':'#9d174d'};
    señalBadge = '<span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:700;background:'+scColors[sc]+';color:#fff;margin-left:8px">'+sc+'</span>';
  }

  // Selector de días
  var sel_html = '<select id="tend-dias-sel" onchange="cargarGraficoTend(\''+monCode+'\')" style="font-size:.75rem;padding:2px 6px;border:1px solid var(--border,#e2e8f0);border-radius:4px;background:var(--bg,#fff);color:inherit">'
    +'<option value="30">30 días</option>'
    +'<option value="60">60 días</option>'
    +'<option value="90" selected>90 días</option>'
    +'<option value="180">180 días</option>'
    +'<option value="365">1 año</option>'
    +'</select>';

  var mInfo2 = mon(monCode);
  wrap.innerHTML =
    '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:8px">'
    +'<div><span style="font-weight:700;font-size:.95rem">'+mInfo2.flag+' '+monCode+'</span>'+señalBadge+'</div>'
    +'<div style="display:flex;align-items:center;gap:8px;font-size:.75rem">'
    +'<span style="color:#2563eb">— Venta</span>'
    +'<span style="color:#16a34a">-- Compra</span>'
    +'<span style="color:#f59e0b">·· SMA7</span>'
    +'<span style="color:#7c3aed">·· SMA30</span>'
    +'<label>Período: '+sel_html+'</label>'
    +'</div></div>'
    +'<div style="overflow-x:auto">'
    +'<svg viewBox="0 0 '+W+' '+H+'" style="width:100%;max-width:'+W+'px;display:block">'
    +gridLines
    +'<path d="'+areaPath+'" fill="#2563eb" fill-opacity="0.07"/>'
    +polyline(compras,'#16a34a',1.5,'4 3')
    +polyline(ventas,'#2563eb',2)
    +polyline(sma7,'#f59e0b',1.5,'3 2')
    +polyline(sma30,'#7c3aed',1.5,'5 3')
    +tooltips
    +'</svg></div>';

  // Restaurar días seleccionados
  var sel2 = document.getElementById('tend-dias-sel');
  if(sel2) sel2.value = String(serie.length>180?365:serie.length>90?180:serie.length>60?90:serie.length>30?60:30);
}

// ─── EJEMPLO ──────────────────────────────────────────
function parseCSV(text){
  var lines=text.trim().split('\n').filter(function(l){return l.trim();});
  if(lines.length<2) return [];
  var h=lines[0].split(',').map(function(s){return s.trim();});
  return lines.slice(1).map(function(line){
    var v=line.split(',').map(function(s){return s.trim();});
    var o={};
    h.forEach(function(k,i){var val=v[i]||''; o[k]=val===''?0:(isNaN(val)?val:Number(val));});
    return o;
  });
}

function cargarEjemplo(){
  var op='moneda,ops_compra,vol_compra,ops_venta,vol_venta\nCLP,641,229286143.82,559,2700621.29\nUSD,253,94379.00,206,1394654.05\nBRL,37,38543.00,19,62731.10\nEUR,42,5910.00,33,159589.90\nPEN,9,1280.00,11,5765.00\nMXN,1,500.00,0,0.00\nCAD,1,50.00,0,0.00\nGBP,0,0.00,1,2620.00\nCHF,0,0.00,1,2460.00';
  var util='moneda,util_compra,util_venta,util_total,vol_total\nEUR,1750.37,34191.86,35942.23,165499.90\nCLP,9999.80,94.88,10094.68,19570968.60\nBRL,4549.10,3109.20,7658.30,101274.10\nUSD,-14774.94,20413.25,5638.31,234757.00\nGBP,0.00,4689.80,4689.80,2620.00\nCHF,0.00,4403.40,4403.40,2460.00\nPEN,129.68,447.16,576.84,7045.00\nCAD,102.54,0.00,102.54,50.00\nMXN,18.52,0.00,18.52,500.00';
  var ciudad='agencia,moneda,ops_compra,vol_compra,ops_venta,vol_venta,utilidad\nAGENCIA 6 DE MARZO,BRL,3,5000.00,0,0.00,689.83\nAGENCIA 6 DE MARZO,PEN,1,50.00,1,155.00,18.78\nAGENCIA 6 DE MARZO,CLP,22,11256752.79,19,130027.40,-1526.17\nAGENCIA 6 DE MARZO,USD,8,4953.00,8,69597.33,-30837.66\nAGENCIA COCHABAMBA CENTRAL,EUR,2,800.00,1,2354.00,198.24\nAGENCIA COCHABAMBA CENTRAL,PEN,1,210.00,2,1234.80,131.93\nAGENCIA COCHABAMBA CENTRAL,BRL,3,3700.00,1,410.00,-94.48\nAGENCIA COCHABAMBA CENTRAL,CLP,14,3207742.44,13,36112.33,-338.97\nAGENCIA COCHABAMBA CENTRAL,USD,9,1500.00,4,19685.55,-5181.49\nAGENCIA COLON,EUR,15,2390.00,29,145743.40,25537.32\nAGENCIA COLON,GBP,0,0.00,1,2620.00,4689.80\nAGENCIA COLON,CHF,0,0.00,1,2460.00,4403.40\nAGENCIA COLON,BRL,9,6010.00,14,39615.10,2533.28\nAGENCIA COLON,PEN,5,620.00,7,4077.20,342.68\nAGENCIA COLON,MXN,1,500.00,0,0.00,18.52\nAGENCIA COLON,CLP,302,132781476.58,317,1624376.37,-27316.55\nAGENCIA COLON,USD,137,57716.00,147,872167.61,-259454.48\nAGENCIA ORURO CENTRAL,EUR,5,450.00,2,8505.00,8327.70\nAGENCIA ORURO CENTRAL,CLP,35,7287443.11,27,88292.77,201.15\nAGENCIA ORURO CENTRAL,USD,13,3000.00,8,36879.31,-6351.36\nAGENCIA SUCRE CENTRAL,BRL,4,17100.00,3,20706.00,2419.33\nAGENCIA SUCRE CENTRAL,EUR,2,250.00,1,2987.50,239.97\nAGENCIA SUCRE CENTRAL,CAD,1,50.00,0,0.00,102.54\nAGENCIA SUCRE CENTRAL,CLP,36,27117620.51,35,310740.49,-4634.83\nAGENCIA SUCRE CENTRAL,USD,18,6850.00,16,234737.64,-39734.84\nAGENCIA UYUNI,BRL,9,5650.00,1,2000.00,1639.91\nAGENCIA UYUNI,EUR,8,1030.00,0,0.00,501.16\nAGENCIA UYUNI,CLP,175,42751630.53,142,500565.86,213.37\nAGENCIA UYUNI,PEN,2,400.00,1,298.00,83.45\nAGENCIA UYUNI,USD,64,19860.00,22,157384.97,-28303.16\nAGENCIA YAPACANI,CLP,57,4883477.86,6,10506.07,3813.18\nAGENCIA YAPACANI,EUR,10,990.00,0,0.00,1137.84\nAGENCIA YAPACANI,BRL,9,1083.00,0,0.00,470.43\nAGENCIA YAPACANI,USD,4,500.00,1,4201.64,103.16';
  var live='moneda,compras_hoy,vol_compra_hoy,ventas_hoy,vol_venta_hoy\nCLP,43,16496059.41,43,217537.63\nUSD,18,5850.00,16,123177.83\nBRL,3,6500.00,2,10560.00\nEUR,1,50.00,2,17574.00\nPEN,0,0.00,1,311.00\nCAD,1,50.00,0,0.00';
  D.op=parseCSV(op);       renderResumen(D.op);
  D.util=parseCSV(util);   renderGanancias(D.util);
  D.ciudad=parseCSV(ciudad); renderAgencias(D.ciudad);
  D.live=parseCSV(live);   renderHoy(D.live);
  badge('b-cajeros','—'); badge('b-cotizaciones','—');
  setStatus('Ejemplo cargado (julio 2026)','#D97706');
}

// ─── FECHAS ───────────────────────────────────────────
(function(){
  var now=new Date();
  var y=now.getFullYear(),m=String(now.getMonth()+1).padStart(2,'0'),d=String(now.getDate()).padStart(2,'0');
  document.getElementById('fDesde').value=y+'-'+m+'-'+d;
  document.getElementById('fHasta').value=y+'-'+m+'-'+d;
})();

// ─── INICIO ───────────────────────────────────────────
reloadAll();
</script>
</body>
</html>
