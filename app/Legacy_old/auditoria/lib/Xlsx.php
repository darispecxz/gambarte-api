<?php
/**
 * Xlsx.php  —  Generador de archivos .xlsx SIN dependencias.
 *
 * No requiere PhpSpreadsheet, ni la extensión ZipArchive (el módulo de
 * Apache/PHP 5.6 de este servidor no la tiene habilitada). El ZIP se
 * construye a mano con deflate + crc32 (funciones del core de PHP).
 *
 * Compatible con PHP 5.6+ (sin sintaxis de 7.x).
 *
 * Módulo de Auditoría — independiente del resto del proyecto.
 */

namespace Auditoria;

/* ─────────────────────────────────────────────────────────────────────────
   ZIP mínimo (método deflate) — suficiente para el contenedor .xlsx
   ───────────────────────────────────────────────────────────────────────── */
class Zip
{
    /** @param array $files  ['ruta/dentro/del/zip' => 'contenido binario', ...] */
    public static function build($files)
    {
        $local   = '';   // registros locales + datos
        $central = '';   // directorio central
        $offset  = 0;

        foreach ($files as $name => $data) {
            $crc      = crc32($data);
            $unclen   = strlen($data);
            $comp     = gzdeflate($data, 6);
            if ($comp === false) {          // fallback: almacenar sin comprimir
                $comp   = $data;
                $method = 0;                // STORE
            } else {
                $method = 8;                // DEFLATE
            }
            $clen = strlen($comp);
            $name = str_replace('\\', '/', $name);

            // Encabezado local
            $lf  = "\x50\x4b\x03\x04";                 // firma
            $lf .= pack('v', 20);                       // versión necesaria
            $lf .= pack('v', 0);                        // flags
            $lf .= pack('v', $method);                  // método
            $lf .= pack('v', 0);                        // hora
            $lf .= pack('v', 0);                        // fecha
            $lf .= pack('V', $crc);                     // crc32
            $lf .= pack('V', $clen);                    // tamaño comprimido
            $lf .= pack('V', $unclen);                  // tamaño original
            $lf .= pack('v', strlen($name));            // long. nombre
            $lf .= pack('v', 0);                        // long. extra
            $lf .= $name;

            $local .= $lf . $comp;

            // Entrada del directorio central
            $cf  = "\x50\x4b\x01\x02";
            $cf .= pack('v', 20);                        // versión creador
            $cf .= pack('v', 20);                        // versión necesaria
            $cf .= pack('v', 0);                         // flags
            $cf .= pack('v', $method);
            $cf .= pack('v', 0);                         // hora
            $cf .= pack('v', 0);                         // fecha
            $cf .= pack('V', $crc);
            $cf .= pack('V', $clen);
            $cf .= pack('V', $unclen);
            $cf .= pack('v', strlen($name));
            $cf .= pack('v', 0);                         // extra
            $cf .= pack('v', 0);                         // comentario
            $cf .= pack('v', 0);                         // disco
            $cf .= pack('v', 0);                         // atrib. internos
            $cf .= pack('V', 0);                         // atrib. externos
            $cf .= pack('V', $offset);                   // desplazamiento local
            $cf .= $name;

            $central .= $cf;
            $offset  += strlen($lf) + $clen;
        }

        // Fin del directorio central
        $eocd  = "\x50\x4b\x05\x06";
        $eocd .= pack('v', 0);
        $eocd .= pack('v', 0);
        $eocd .= pack('v', count($files));
        $eocd .= pack('v', count($files));
        $eocd .= pack('V', strlen($central));
        $eocd .= pack('V', strlen($local));
        $eocd .= pack('v', 0);

        return $local . $central . $eocd;
    }
}

/* ─────────────────────────────────────────────────────────────────────────
   Una hoja del libro
   ───────────────────────────────────────────────────────────────────────── */
class Sheet
{
    public $name;
    public $rows       = array();   // filas ya renderizadas a XML de celdas
    public $cols       = array();   // anchos de columna
    public $freezeRows = 0;         // filas congeladas (encabezado fijo)
    public $autoFilter = '';        // rango p.ej. "A4:E120"
    public $merges     = array();   // rangos combinados
    private $r         = 0;         // contador de filas

    public function __construct($name)
    {
        // Excel limita el nombre de hoja a 31 chars y prohíbe : \ / ? * [ ]
        $name = preg_replace('/[:\\\\\/?*\[\]]/', ' ', $name);
        $this->name = mb_substr($name, 0, 31);
    }

    public function setCols($widths)
    {
        $this->cols = $widths;
        return $this;
    }

    /** Título/subtítulo que ocupa varias columnas. */
    public function addTitle($text, $span = 1, $style = Xlsx::S_TITLE)
    {
        $this->r++;
        $cells = Xlsx::cell('A' . $this->r, $text, $style);
        // Celdas vacías para que la combinación pinte bien
        for ($i = 1; $i < $span; $i++) {
            $cells .= Xlsx::cell(Xlsx::colLetter($i) . $this->r, null, $style);
        }
        $this->rows[] = '<row r="' . $this->r . '">' . $cells . '</row>';
        if ($span > 1) {
            $this->merges[] = 'A' . $this->r . ':' . Xlsx::colLetter($span - 1) . $this->r;
        }
        return $this;
    }

    public function addBlank()
    {
        $this->r++;
        $this->rows[] = '<row r="' . $this->r . '"/>';
        return $this;
    }

    /** Fila de encabezados (negrita/fondo). */
    public function addHeader($headers)
    {
        $this->r++;
        $xml = '';
        foreach (array_values($headers) as $i => $h) {
            $xml .= Xlsx::cell(Xlsx::colLetter($i) . $this->r, $h, Xlsx::S_HEADER);
        }
        $this->rows[] = '<row r="' . $this->r . '">' . $xml . '</row>';
        $this->freezeRows = $this->r;
        $last = Xlsx::colLetter(count($headers) - 1);
        $this->autoFilter = 'A' . $this->r . ':' . $last . $this->r; // se extiende al guardar
        $this->_filterFirstRow = $this->r;
        $this->_filterLastCol  = $last;
        return $this;
    }

    /**
     * Fila de datos.
     * @param array $values   valores en orden de columna
     * @param array $formats  tipo por columna: text|int|num|money|datetime|date|wrap
     */
    public function addRow($values, $formats = array())
    {
        $this->r++;
        $xml = '';
        $i = 0;
        foreach (array_values($values) as $v) {
            $fmt = isset($formats[$i]) ? $formats[$i] : 'text';
            $ref = Xlsx::colLetter($i) . $this->r;
            $xml .= Xlsx::typedCell($ref, $v, $fmt);
            $i++;
        }
        $this->rows[] = '<row r="' . $this->r . '">' . $xml . '</row>';
        return $this;
    }

    public $_filterFirstRow = 0;
    public $_filterLastCol  = 'A';
    public $lastRow = 0;

    public function rowCount() { return $this->r; }
}

/* ─────────────────────────────────────────────────────────────────────────
   Libro
   ───────────────────────────────────────────────────────────────────────── */
class Xlsx
{
    // Índices de estilo (deben coincidir con styles.xml de abajo)
    const S_NORMAL   = 0;
    const S_HEADER   = 1;
    const S_TITLE    = 2;
    const S_SUBTITLE = 3;
    const S_DATETIME = 4;
    const S_DATE     = 5;
    const S_INT      = 6;
    const S_MONEY    = 7;
    const S_WRAP     = 8;

    /** @var Sheet[] */
    private $sheets = array();

    public function addSheet($name)
    {
        $s = new Sheet($name);
        $this->sheets[] = $s;
        return $s;
    }

    /* ---------- helpers de celdas (estáticos) ---------- */

    public static function esc($s)
    {
        return str_replace(
            array('&', '<', '>', '"', "'"),
            array('&amp;', '&lt;', '&gt;', '&quot;', '&apos;'),
            $s
        );
    }

    public static function colLetter($idx) // 0 => A
    {
        $s = '';
        $idx++;
        while ($idx > 0) {
            $m = ($idx - 1) % 26;
            $s = chr(65 + $m) . $s;
            $idx = intval(($idx - $m) / 26);
        }
        return $s;
    }

    /** Celda de texto con estilo explícito. */
    public static function cell($ref, $value, $style = 0)
    {
        $s = $style ? ' s="' . $style . '"' : '';
        if ($value === null || $value === '') {
            return '<c r="' . $ref . '"' . $s . '/>';
        }
        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
             . self::esc($value) . '</t></is></c>';
    }

    /** Celda tipada según formato de columna. */
    public static function typedCell($ref, $v, $fmt)
    {
        if ($v === null || $v === '') {
            // Mantener estilo de fecha/num si corresponde para bordes uniformes
            return '<c r="' . $ref . '"/>';
        }

        switch ($fmt) {
            case 'int':
                return '<c r="' . $ref . '" s="' . self::S_INT . '"><v>'
                     . (int)$v . '</v></c>';
            case 'num':
                return '<c r="' . $ref . '"><v>' . (0 + $v) . '</v></c>';
            case 'money':
                return '<c r="' . $ref . '" s="' . self::S_MONEY . '"><v>'
                     . (0 + $v) . '</v></c>';
            case 'datetime':
                $serial = self::dateSerial($v, true);
                if ($serial === null) { return self::cell($ref, $v); }
                return '<c r="' . $ref . '" s="' . self::S_DATETIME . '"><v>'
                     . $serial . '</v></c>';
            case 'date':
                $serial = self::dateSerial($v, false);
                if ($serial === null) { return self::cell($ref, $v); }
                return '<c r="' . $ref . '" s="' . self::S_DATE . '"><v>'
                     . $serial . '</v></c>';
            case 'wrap':
                return '<c r="' . $ref . '" s="' . self::S_WRAP . '" t="inlineStr"><is><t xml:space="preserve">'
                     . self::esc($v) . '</t></is></c>';
            case 'text':
            default:
                return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                     . self::esc($v) . '</t></is></c>';
        }
    }

    /** Convierte 'Y-m-d H:i:s' (o Y-m-d) a número de serie Excel. */
    public static function dateSerial($value, $withTime)
    {
        if ($value instanceof \DateTime) {
            $dt = $value;
        } else {
            $value = trim((string)$value);
            if ($value === '' || $value === '0000-00-00' || substr($value, 0, 10) === '0000-00-00') {
                return null;
            }
            $dt = date_create($value);
            if ($dt === false) { return null; }
        }
        // Excel: días desde 1899-12-30
        $base  = date_create('1899-12-30 00:00:00');
        $days  = (int) $dt->diff($base)->format('%a');
        $days  = ($dt >= $base) ? $days : -$days;
        if (!$withTime) {
            return $days;
        }
        $secs  = (int)$dt->format('H') * 3600 + (int)$dt->format('i') * 60 + (int)$dt->format('s');
        $frac  = $secs / 86400;
        return $days + $frac;
    }

    /* ---------- guardado ---------- */

    public function toString()
    {
        $files = array();
        $files['[Content_Types].xml'] = $this->contentTypes();
        $files['_rels/.rels']         = $this->rootRels();
        $files['xl/workbook.xml']     = $this->workbook();
        $files['xl/_rels/workbook.xml.rels'] = $this->workbookRels();
        $files['xl/styles.xml']       = $this->styles();

        $n = 1;
        foreach ($this->sheets as $sheet) {
            $files['xl/worksheets/sheet' . $n . '.xml'] = $this->sheetXml($sheet);
            $n++;
        }
        return Zip::build($files);
    }

    public function save($path)
    {
        return file_put_contents($path, $this->toString());
    }

    /* ---------- partes XML ---------- */

    private function contentTypes()
    {
        $over = '';
        for ($i = 1; $i <= count($this->sheets); $i++) {
            $over .= '<Override PartName="/xl/worksheets/sheet' . $i
                   . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $over
            . '</Types>';
    }

    private function rootRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook()
    {
        $tabs = '';
        $n = 1;
        foreach ($this->sheets as $s) {
            $tabs .= '<sheet name="' . self::esc($s->name) . '" sheetId="' . $n
                   . '" r:id="rId' . $n . '"/>';
            $n++;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $tabs . '</sheets></workbook>';
    }

    private function workbookRels()
    {
        $rels = '';
        $n = 1;
        foreach ($this->sheets as $s) {
            $rels .= '<Relationship Id="rId' . $n
                   . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $n++;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    private function styles()
    {
        // numFmts personalizados
        $numFmts =
            '<numFmts count="3">'
          . '<numFmt numFmtId="164" formatCode="yyyy\\-mm\\-dd\\ hh:mm:ss"/>'
          . '<numFmt numFmtId="165" formatCode="yyyy\\-mm\\-dd"/>'
          . '<numFmt numFmtId="166" formatCode="#,##0.00"/>'
          . '</numFmts>';

        $fonts =
            '<fonts count="4">'
          . '<font><sz val="11"/><name val="Calibri"/></font>'                                   // 0 normal
          . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'        // 1 header (blanco)
          . '<font><b/><sz val="14"/><color rgb="FF1A2540"/><name val="Calibri"/></font>'        // 2 título
          . '<font><sz val="11"/><color rgb="FF5A6A88"/><name val="Calibri"/></font>'            // 3 subtítulo
          . '</fonts>';

        $fills =
            '<fills count="3">'
          . '<fill><patternFill patternType="none"/></fill>'                                       // 0
          . '<fill><patternFill patternType="gray125"/></fill>'                                    // 1 (requerido)
          . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill>' // 2 header azul
          . '</fills>';

        $borders =
            '<borders count="2">'
          . '<border><left/><right/><top/><bottom/><diagonal/></border>'                           // 0
          . '<border>'                                                                             // 1 caja gris
          . '<left style="thin"><color rgb="FFDDE3EE"/></left>'
          . '<right style="thin"><color rgb="FFDDE3EE"/></right>'
          . '<top style="thin"><color rgb="FFDDE3EE"/></top>'
          . '<bottom style="thin"><color rgb="FFDDE3EE"/></bottom><diagonal/></border>'
          . '</borders>';

        // cellXfs — el orden define las constantes S_*
        $xfs =
            '<cellXfs count="9">'
          // 0 NORMAL
          . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
          // 1 HEADER
          . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
          // 2 TITLE
          . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
          // 3 SUBTITLE
          . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
          // 4 DATETIME
          . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
          // 5 DATE
          . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
          // 6 INT
          . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
          // 7 MONEY
          . '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
          // 8 WRAP
          . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
          . '</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $numFmts . $fonts . $fills . $borders
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . $xfs
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function sheetXml(Sheet $s)
    {
        $last = $s->rowCount();

        // <cols>
        $cols = '';
        if (!empty($s->cols)) {
            $cols = '<cols>';
            $i = 1;
            foreach ($s->cols as $w) {
                $cols .= '<col min="' . $i . '" max="' . $i . '" width="' . $w . '" customWidth="1"/>';
                $i++;
            }
            $cols .= '</cols>';
        }

        // panel congelado
        $views = '<sheetViews><sheetView workbookViewId="0"';
        if ($s->freezeRows > 0) {
            $views .= '><pane ySplit="' . $s->freezeRows . '" topLeftCell="A'
                    . ($s->freezeRows + 1) . '" activePane="bottomLeft" state="frozen"/>'
                    . '<selection pane="bottomLeft" activeCell="A' . ($s->freezeRows + 1)
                    . '" sqref="A' . ($s->freezeRows + 1) . '"/></sheetView></sheetViews>';
        } else {
            $views .= '/></sheetViews>';
        }

        // autofiltro (encabezado -> última fila)
        $filter = '';
        if ($s->_filterFirstRow > 0 && $last >= $s->_filterFirstRow) {
            $filter = '<autoFilter ref="A' . $s->_filterFirstRow . ':'
                    . $s->_filterLastCol . $last . '"/>';
        }

        // combinaciones
        $merge = '';
        if (!empty($s->merges)) {
            $merge = '<mergeCells count="' . count($s->merges) . '">';
            foreach ($s->merges as $m) {
                $merge .= '<mergeCell ref="' . $m . '"/>';
            }
            $merge .= '</mergeCells>';
        }

        $dim = 'A1:' . ($last > 0 ? 'A' . $last : 'A1');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . $dim . '"/>'
            . $views
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . implode('', $s->rows) . '</sheetData>'
            . $filter
            . $merge
            . '</worksheet>';
    }
}
