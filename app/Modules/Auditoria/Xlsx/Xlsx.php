<?php

namespace App\Modules\Auditoria\Xlsx;

class Xlsx
{
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

    public static function esc($s)
    {
        return str_replace(
            array('&', '<', '>', '"', "'"),
            array('&amp;', '&lt;', '&gt;', '&quot;', '&apos;'),
            $s
        );
    }

    public static function colLetter($idx)
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

    public static function cell($ref, $value, $style = 0)
    {
        $s = $style ? ' s="' . $style . '"' : '';
        if ($value === null || $value === '') {
            return '<c r="' . $ref . '"' . $s . '/>';
        }
        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
             . self::esc($value) . '</t></is></c>';
    }

    public static function typedCell($ref, $v, $fmt)
    {
        if ($v === null || $v === '') {
            return '<c r="' . $ref . '"/>';
        }
        switch ($fmt) {
            case 'int':
                return '<c r="' . $ref . '" s="' . self::S_INT . '"><v>' . (int)$v . '</v></c>';
            case 'num':
                return '<c r="' . $ref . '"><v>' . (0 + $v) . '</v></c>';
            case 'money':
                return '<c r="' . $ref . '" s="' . self::S_MONEY . '"><v>' . (0 + $v) . '</v></c>';
            case 'datetime':
                $serial = self::dateSerial($v, true);
                if ($serial === null) { return self::cell($ref, $v); }
                return '<c r="' . $ref . '" s="' . self::S_DATETIME . '"><v>' . $serial . '</v></c>';
            case 'date':
                $serial = self::dateSerial($v, false);
                if ($serial === null) { return self::cell($ref, $v); }
                return '<c r="' . $ref . '" s="' . self::S_DATE . '"><v>' . $serial . '</v></c>';
            case 'wrap':
                return '<c r="' . $ref . '" s="' . self::S_WRAP . '" t="inlineStr"><is><t xml:space="preserve">'
                     . self::esc($v) . '</t></is></c>';
            case 'text':
            default:
                return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                     . self::esc($v) . '</t></is></c>';
        }
    }

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
        $base = date_create('1899-12-30 00:00:00');
        $days = (int) $dt->diff($base)->format('%a');
        $days = ($dt >= $base) ? $days : -$days;
        if (!$withTime) {
            return $days;
        }
        $secs = (int)$dt->format('H') * 3600 + (int)$dt->format('i') * 60 + (int)$dt->format('s');
        $frac = $secs / 86400;
        return $days + $frac;
    }

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
        $numFmts =
            '<numFmts count="3">'
          . '<numFmt numFmtId="164" formatCode="yyyy\\-mm\\-dd\\ hh:mm:ss"/>'
          . '<numFmt numFmtId="165" formatCode="yyyy\\-mm\\-dd"/>'
          . '<numFmt numFmtId="166" formatCode="#,##0.00"/>'
          . '</numFmts>';

        $fonts =
            '<fonts count="4">'
          . '<font><sz val="11"/><name val="Calibri"/></font>'
          . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
          . '<font><b/><sz val="14"/><color rgb="FF1A2540"/><name val="Calibri"/></font>'
          . '<font><sz val="11"/><color rgb="FF5A6A88"/><name val="Calibri"/></font>'
          . '</fonts>';

        $fills =
            '<fills count="3">'
          . '<fill><patternFill patternType="none"/></fill>'
          . '<fill><patternFill patternType="gray125"/></fill>'
          . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill>'
          . '</fills>';

        $borders =
            '<borders count="2">'
          . '<border><left/><right/><top/><bottom/><diagonal/></border>'
          . '<border>'
          . '<left style="thin"><color rgb="FFDDE3EE"/></left>'
          . '<right style="thin"><color rgb="FFDDE3EE"/></right>'
          . '<top style="thin"><color rgb="FFDDE3EE"/></top>'
          . '<bottom style="thin"><color rgb="FFDDE3EE"/></bottom><diagonal/></border>'
          . '</borders>';

        $xfs =
            '<cellXfs count="9">'
          . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
          . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
          . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
          . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
          . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
          . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
          . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
          . '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
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

        $views = '<sheetViews><sheetView workbookViewId="0"';
        if ($s->freezeRows > 0) {
            $views .= '><pane ySplit="' . $s->freezeRows . '" topLeftCell="A'
                    . ($s->freezeRows + 1) . '" activePane="bottomLeft" state="frozen"/>'
                    . '<selection pane="bottomLeft" activeCell="A' . ($s->freezeRows + 1)
                    . '" sqref="A' . ($s->freezeRows + 1) . '"/></sheetView></sheetViews>';
        } else {
            $views .= '/></sheetViews>';
        }

        $filter = '';
        if ($s->_filterFirstRow > 0 && $last >= $s->_filterFirstRow) {
            $filter = '<autoFilter ref="A' . $s->_filterFirstRow . ':'
                    . $s->_filterLastCol . $last . '"/>';
        }

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
