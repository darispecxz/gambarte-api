<?php

namespace App\Modules\Auditoria\Xlsx;

class Sheet
{
    public $name;
    public $rows       = array();
    public $cols       = array();
    public $freezeRows = 0;
    public $autoFilter = '';
    public $merges     = array();
    public $_filterFirstRow = 0;
    public $_filterLastCol  = 'A';
    public $lastRow = 0;
    private $r = 0;

    public function __construct($name)
    {
        $name = preg_replace('/[:\\\\\/?*\[\]]/', ' ', $name);
        $this->name = mb_substr($name, 0, 31);
    }

    public function setCols($widths)
    {
        $this->cols = $widths;
        return $this;
    }

    public function addTitle($text, $span = 1, $style = Xlsx::S_TITLE)
    {
        $this->r++;
        $cells = Xlsx::cell('A' . $this->r, $text, $style);
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
        $this->autoFilter = 'A' . $this->r . ':' . $last . $this->r;
        $this->_filterFirstRow = $this->r;
        $this->_filterLastCol  = $last;
        return $this;
    }

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

    public function rowCount() { return $this->r; }
}
