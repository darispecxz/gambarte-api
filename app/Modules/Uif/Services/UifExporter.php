<?php

namespace App\Modules\Uif\Services;

use App\Modules\Shared\Database\RawConnection;
use Dompdf\Dompdf;
use Dompdf\Options;

class UifExporter
{
    // ===================================================================
    //  PCC-06 PDF
    // ===================================================================

    public function pcc06Pdf(int $id): array
    {
        $cambio  = RawConnection::selectOne("SELECT * FROM cambio WHERE id_cambio = :id", [':id' => $id]);
        if (!$cambio) {
            abort(404, "Cambio #{$id} no encontrado en tabla cambio.");
        }

        $pcc = RawConnection::selectOne("SELECT * FROM v_pcc06 WHERE id_cambio = :id", [':id' => $id]);
        if (!$pcc) {
            abort(404, "PCC-06 #{$id} no encontrado en vista v_pcc06.");
        }

        $empresa = RawConnection::selectOne("SELECT * FROM empresa");

        $factura = RawConnection::selectOne(
            "SELECT * FROM facturado WHERE id_operacion IN(1,2) AND id_agencia = :agencia AND transaccion = :id",
            [':agencia' => $cambio['id_agencia'], ':id' => $id]
        );

        // Beneficiary data
        $beneficiario = null;
        if (isset($cambio['select_per']) && $cambio['select_per'] == 1) {
            $beneficiario = RawConnection::selectOne(
                "SELECT * FROM beneficiario_final WHERE id = :id",
                [':id' => $cambio['persona_factura_id'] ?? 0]
            );
        } else {
            $beneficiario = RawConnection::selectOne(
                "SELECT * FROM personas_juridicas WHERE id = :id",
                [':id' => $cambio['persona_factura_id'] ?? 0]
            );
        }

        // Helper lookups
        $agenciaDesc    = $this->descripcion('agencia', 'id_agencia', $cambio['id_agencia']);
        $deptDesc       = $this->descripcion('departamento', 'id_departamento', $pcc['id_departamento'] ?? 0);
        $ciudadDesc     = $this->descripcion('ciudad_pcc', 'id_ciudad_pcc', $pcc['id_ciudad_pcc'] ?? 0);
        $actEconDesc    = $this->descActividad($pcc['id_actividad_economica'] ?? 0);
        $tipoDocAbrev   = $this->abreviatura('tipo_doc_identidad', 'id_tipo_doc_identidad', $pcc['id_tipo_doc_identidad'] ?? 0);
        $expedicionAbr  = $this->abreviatura('expedicion', 'id_expedicion', $pcc['id_expedicion'] ?? 0);
        $operacionDesc  = $this->descripcion('operacion', 'id_operacion', $cambio['id_operacion'] ?? 0);
        $monRecAbrev    = $this->abreviatura('moneda', 'id_moneda', $cambio['mon_rec'] ?? 0);
        $monEntAbrev    = $this->abreviatura('moneda', 'id_moneda', $cambio['mon_ent'] ?? 0);
        $paisDesc       = $this->descripcion('pais', 'id_pais', $pcc['id_pais'] ?? 0);

        // Agency details
        $agenciaData = RawConnection::selectOne(
            "SELECT a.*, d.descripcion as departamento_desc FROM agencia a
             LEFT JOIN departamento d ON a.id_departamento = d.id_departamento
             WHERE a.id_agencia = :id",
            [':id' => $cambio['id_agencia']]
        );

        $html = $this->buildPcc06Html($pcc, $empresa, $cambio, $factura, $beneficiario, $agenciaDesc,
            $deptDesc, $ciudadDesc, $actEconDesc, $tipoDocAbrev, $expedicionAbr,
            $operacionDesc, $monRecAbrev, $monEntAbrev, $paisDesc, $agenciaData);

        $pdf = $this->renderPdf($html, 'PCC-06');
        $code = str_pad($id, 6, '0', STR_PAD_LEFT);

        return ['binary' => $pdf, 'filename' => "PCC-06_{$code}.pdf"];
    }

    // ===================================================================
    //  PCC-08 PDF
    // ===================================================================

    public function pcc08Pdf(int $operacion, int $id): array
    {
        $pcc = RawConnection::selectOne(
            "SELECT * FROM v_pcc08 WHERE id_operacion = :operacion AND id_transaccion = :id",
            [':operacion' => $operacion, ':id' => $id]
        );
        $empresa = RawConnection::selectOne("SELECT * FROM empresa");

        if (!$pcc) {
            abort(404, 'Registro PCC-08 no encontrado.');
        }

        if ($operacion == 3) {
            $transaccion = RawConnection::selectOne("SELECT * FROM giro WHERE id_giro = :id", [':id' => $id]);
            $prefix      = 'G-';
            $paisDestino  = 'BOL';
        } else {
            $transaccion = RawConnection::selectOne("SELECT * FROM remesa WHERE id_remesa = :id", [':id' => $id]);
            $prefix      = 'R-';
            $paisDestino  = $pcc['pais_destino'] ?? '';
        }

        if (!$transaccion) {
            abort(404, 'Transaccion no encontrada.');
        }

        $agenciaId     = $transaccion['id_agencia'] ?? $transaccion['id_agencia_origen'] ?? 0;
        $agenciaDesc   = $this->descripcion('agencia', 'id_agencia', $agenciaId);
        $operacionDesc = $this->descripcion('operacion', 'id_operacion', $operacion);
        $monRecAbrev   = $this->abreviatura('moneda', 'id_moneda', $transaccion['mon_rec'] ?? 0);
        $monEntAbrev   = $this->abreviatura('moneda', 'id_moneda', $transaccion['mon_ent'] ?? 0);
        $tipoDocAbrev  = $this->abreviatura('tipo_doc_identidad', 'id_tipo_doc_identidad', $pcc['id_tipo_doc_identidad'] ?? 0);
        $expedicionAbr = $this->abreviatura('expedicion', 'id_expedicion', $pcc['id_expedicion'] ?? 0);
        $actEconDesc   = $this->descActividad($pcc['id_actividad_economica'] ?? 0);
        $paisDesc      = $this->descripcion('pais', 'id_pais', $pcc['id_pais'] ?? 0);

        $agenciaData = RawConnection::selectOne(
            "SELECT a.*, d.descripcion as departamento_desc FROM agencia a
             LEFT JOIN departamento d ON a.id_departamento = d.id_departamento
             WHERE a.id_agencia = :id",
            [':id' => $agenciaId]
        );

        $factura = RawConnection::selectOne(
            "SELECT * FROM facturado WHERE id_operacion = :op AND id_agencia = :agencia AND transaccion = :id",
            [':op' => $operacion, ':agencia' => $agenciaId, ':id' => $id]
        );

        $beneficiario = null;
        $selectPer = $transaccion['select_per'] ?? 0;
        if ($selectPer == 1) {
            $beneficiario = RawConnection::selectOne(
                "SELECT * FROM beneficiario_final WHERE id = :id",
                [':id' => $transaccion['persona_factura_id'] ?? 0]
            );
        } elseif (($transaccion['persona_factura_id'] ?? 0) > 0) {
            $beneficiario = RawConnection::selectOne(
                "SELECT * FROM personas_juridicas WHERE id = :id",
                [':id' => $transaccion['persona_factura_id'] ?? 0]
            );
        }

        $html = $this->buildPcc08Html($pcc, $empresa, $transaccion, $factura, $beneficiario,
            $agenciaDesc, $operacionDesc, $monRecAbrev, $monEntAbrev, $tipoDocAbrev,
            $expedicionAbr, $actEconDesc, $paisDesc, $agenciaData, $prefix, $paisDestino, $operacion);

        $pdf  = $this->renderPdf($html, 'PCC-08');
        $code = str_pad($id, 6, '0', STR_PAD_LEFT);

        return ['binary' => $pdf, 'filename' => "PCC-08_{$prefix}{$code}.pdf"];
    }

    // ===================================================================
    //  Blank PCC Form
    // ===================================================================

    public function pccBlank(int $type, int $idAgencia = 0): array
    {
        $empresa = RawConnection::selectOne("SELECT * FROM empresa");
        $html = $this->buildBlankHtml($type, $empresa, $idAgencia);
        $pdf = $this->renderPdf($html, "PCC-0{$type}");
        return ['binary' => $pdf, 'filename' => "PCC-0{$type}_BLANCO.pdf"];
    }

    private function buildBlankHtml(int $type, $empresa, int $idAgencia): string
    {
        $numPcc  = (string) $type;
        $fecha   = date('d/m/Y');
        $uifImg  = $this->imgBase64('uif.png');
        $logoImg = $this->imgBase64('gmbsrl.png');
        $subtit  = $type === 6 ? '(Empresas Remesadora)' : '(Empresas Remesadoras_AA)';

        $agenciaDesc = '';
        $agenciaDirec = '';
        $agenciaCiudad = '';
        $agenciaZona = '';
        $agenciaTel = '';
        if ($idAgencia > 0) {
            $ag = RawConnection::selectOne(
                "SELECT * FROM agencia WHERE id_agencia = :id",
                [':id' => $idAgencia]
            );
            if ($ag) {
                $agenciaDesc   = $ag['descripcion'] ?? '';
                $agenciaDirec  = $ag['direccion'] ?? '';
                $agenciaCiudad = $ag['ciudad'] ?? '';
                $agenciaZona   = $ag['zona'] ?? '';
                $agenciaTel    = $ag['telefono'] ?? '';
            }
        }

        $html = '<div>';
        $html .= '<table align="center" border="0" cellpadding="2" cellspacing="2" width="100%"><tr>';
        $html .= '<td align="center" width="15%"><img src="' . $uifImg . '" width="80" /></td>';
        $html .= '<td align="center">';
        $html .= '<h2 align="center">Informaci&oacute;n sobre Operaciones Financieras</h2>';
        $html .= '<h3 align="center">Formulario PCC-0' . $numPcc . '<br />' . $subtit . '</h3>';
        $html .= '</td>';
        $html .= '<td align="center" width="25%"><img src="' . $logoImg . '" width="140" /><br /><span style="font-size:x-small">Pol&iacute;tica Conozca a su Cliente<br />Art. 26 D.S. 24771</span></td>';
        $html .= '</tr><tr><td align="right" colspan="2"> </td><td align="center"><b>Fecha:</b> ' . $fecha . '</td></tr>';
        $html .= '</tr></table>';

        $html .= '<table align="center" border="1" cellpadding="3" cellspacing="0" width="100%">';
        $html .= '<tr><th colspan="3"><b>INFORMACI&Oacute;N DEL SUJETO OBLIGADO</b></th></tr>';
        $html .= '<tr><th width="33%"><label>Raz&oacute;n Social</label></th>';
        $html .= '<th width="33%"><label>NIT</label></th>';
        $html .= '<th width="33%"><label>Origen del PCC</label></th></tr>';
        $html .= '<tr><td align="center" width="33%">' . $this->v($empresa, 'razon_social') . '</td>';
        $html .= '<td align="center">' . $this->v($empresa, 'nit') . '</td>';
        $html .= '<td align="center">' . htmlspecialchars($agenciaDesc) . '</td></tr>';
        $html .= '<tr><th colspan="3"><b>Direccion donde se realiza el PC:</b>' . htmlspecialchars($agenciaDirec) . '</th></tr>';
        $html .= '<tr><th width="33%"><label>Ciudad</label></th>';
        $html .= '<th width="33%"><label>Zona</label></th>';
        $html .= '<th width="33%"><label>Tel&eacute;fono</label></th></tr>';
        $html .= '<tr><td align="center" width="33%">' . htmlspecialchars($agenciaCiudad) . '</td>';
        $html .= '<td align="center">' . htmlspecialchars($agenciaZona) . '</td>';
        $html .= '<td align="center">' . htmlspecialchars($agenciaTel) . '</td></tr>';

        $html .= '<tr><th colspan="3"><b>DATOS DE USUARIO FINANCIERO</b></th></tr>';
        $html .= '<tr><th><label>Nombres</label></th><th><label>Apellido Paterno</label></th><th><label>Apellido Materno</label></th></tr>';
        $html .= '<tr><td align="center">&nbsp;</td><td align="center">&nbsp;</td><td align="center">&nbsp;</td></tr>';
        $html .= '<tr><th><label>Tipo de documento</label></th><th><label>N&uacute;mero documento</label></th><th><label>Lugar de emisi&oacute;n</label></th></tr>';
        $html .= '<tr><td align="center">&nbsp;</td><td align="center">&nbsp;</td><td align="center">&nbsp;</td></tr>';
        $html .= '<tr><th><label>Nacionalidad</label></th><th><label>Pa&iacute;s de Residencia</label></th><th><label>Profesi&oacute;n</label></th></tr>';
        $html .= '<tr><td align="center">&nbsp;</td><td align="center">&nbsp;</td><td align="center">&nbsp;</td></tr>';
        $html .= '<tr><th colspan="3"><label>Actividad Econ&oacute;mica</label></th></tr>';
        $html .= '<tr><td align="center" colspan="3">&nbsp;</td></tr>';

        $html .= '<tr><th colspan="3"><b>DIRECCI&Oacute;N USUARIO FINANCIERO</b></th></tr>';
        $html .= '<tr><th><label>Ciudad</label></th><th><label>Zona</label></th><th><label>Tel&eacute;fono</label></th></tr>';
        $html .= '<tr><td align="center">&nbsp;</td><td align="center">&nbsp;</td><td align="center">&nbsp;</td></tr>';
        $html .= '<tr><th colspan="3"><label>Direcci&oacute;n (Domicilio)</label></th></tr>';
        $html .= '<tr><td align="center" colspan="3">&nbsp;</td></tr>';

        $html .= '<tr><th colspan="3"><b>DATOS PERSONALES BENEFICIARIO ECON&Oacute;MICO</b></th></tr>';
        $html .= '<tr><th><label>Nombres</label></th><th><label>Apellido Paterno</label></th><th><label>Apellido Materno</label></th></tr>';
        $html .= '<tr><td align="center">&nbsp;</td><td align="center">&nbsp;</td><td align="center">&nbsp;</td></tr>';
        $html .= '<tr><th><label>Ciudad</label></th><th><label>Zona</label></th><th><label>Tel&eacute;fono</label></th></tr>';
        $html .= '<tr><td align="center">&nbsp;</td><td align="center">&nbsp;</td><td align="center">&nbsp;</td></tr>';
        $html .= '<tr><th colspan="3"><label>Direcci&oacute;n (Domicilio)</label></th></tr>';
        $html .= '<tr><td align="center" colspan="3">&nbsp;</td></tr>';

        $html .= '<tr><th colspan="3"><b>DATOS DE LA OPERACI&Oacute;N QUE GENERA EL PCC</b></th></tr>';

        if ($type === 6) {
            $html .= '<tr><th><label>Operaci&oacute;n</label></th><th><label>Moneda Recibida</label></th><th><label>Moneda Cambio</label></th></tr>';
            $html .= '<tr><td align="center">&nbsp;</td><td align="center">&nbsp;</td><td align="center">&nbsp;</td></tr>';
            $html .= '<tr><th><label>Monto</label></th><th colspan="2"><label>Literal</label></th></tr>';
            $html .= '<tr><td align="center">&nbsp;</td><td align="center" colspan="2">&nbsp;</td></tr>';
        } else {
            $html .= '<tr><th><label>Operaci&oacute;n</label></th><th><label>Moneda</label></th><th><label>Monto</label></th></tr>';
            $html .= '<tr><td align="center">&nbsp;</td><td align="center">&nbsp;</td><td align="center">&nbsp;</td></tr>';
            $html .= '<tr><th>Pa&iacute;s Origen</th><th colspan="2">Pa&iacute;s Destino</th></tr>';
            $html .= '<tr><td align="center">&nbsp;</td><td align="center" colspan="2">&nbsp;</td></tr>';
        }

        $html .= '<tr><th colspan="3"><label>Origen de los Recursos</label></th></tr>';
        $html .= '<tr><td align="center" colspan="3">&nbsp;</td></tr>';
        $html .= '<tr><th colspan="3"><label>Destino de los Recursos</label></th></tr>';
        $html .= '<tr><td align="center" colspan="3">&nbsp;</td></tr>';
        $html .= '</table>';

        $html .= '<table align="center" border="0" cellpadding="1" width="100%" style="border-collapse: separate; border-spacing: 30px;">';
        $html .= '<tr><td align="center" valign="top" style="height:80px;">&nbsp;</td></tr>';
        $html .= '<tr>';
        $html .= '<td align="center" valign="top" style="border-top:0.5pt solid #000; padding:2px 0 0 0; height:100px;"><b>FIRMA FUNCIONARIO RECEPTOR</b></td>';
        $html .= '<td align="center" valign="top" style="border-top:0.5pt solid #000; padding:2px 0 0 0; height:100px;"><b>FIRMA DECLARANTE</b></td>';
        $html .= '<td align="center" valign="top" style="border-top:0.5pt solid #000; padding:2px 0 0 0; height:100px;"><b>FIRMA SUPERVISOR</b></td>';
        $html .= '</tr></table>';
        $html .= '</div>';

        return $html;
    }

    // ===================================================================
    //  UIF Report PDF
    // ===================================================================

    public function reportPdf(int $id): array
    {
        $row = RawConnection::selectOne(
            "SELECT * FROM reporte_uif r, usuario_financiero u
             WHERE u.id_tipo_doc_identidad = r.id_tipo_doc_identidad
                 AND u.num_doc_identidad = r.num_doc_identidad
                 AND u.id_expedicion = r.id_expedicion
                 AND r.id_reporte_uif = :id",
            [':id' => $id]
        );

        if (!$row) {
            abort(404, 'Reporte UIF no encontrado.');
        }

        $html = $this->buildReportHtml($row);
        $pdf  = $this->renderReportPdf($html);
        $code = str_pad($id, 6, '0', STR_PAD_LEFT);

        return ['binary' => $pdf, 'filename' => "Reporte_UIF_{$code}.pdf"];
    }

    // ===================================================================
    //  Helper: lookup descripcion / abreviatura
    // ===================================================================

    private function descripcion(string $table, string $pk, $id): string
    {
        if (!$id) return '';
        return (string) RawConnection::scalar(
            "SELECT descripcion FROM {$table} WHERE {$pk} = :id",
            [':id' => $id]
        );
    }

    private function abreviatura(string $table, string $pk, $id): string
    {
        if (!$id) return '';
        return (string) RawConnection::scalar(
            "SELECT abreviatura FROM {$table} WHERE {$pk} = :id",
            [':id' => $id]
        );
    }

    private function descActividad($id): string
    {
        if (!$id) return '';
        return (string) RawConnection::scalar(
            "SELECT descripcion FROM actividad_economica WHERE id_actividad_economica = :id",
            [':id' => $id]
        );
    }

    private function imgBase64(string $filename): string
    {
        $path = public_path("images/{$filename}");
        if (!file_exists($path)) return '';
        $data = base64_encode(file_get_contents($path));
        $mime = mime_content_type($path) ?: 'image/png';
        return "data:{$mime};base64,{$data}";
    }

    // ===================================================================
    //  PDF Rendering
    // ===================================================================

    private function renderPdf(string $html, string $title): string
    {
        $page = $this->wrapHtml($html, $title, '8pt');
        return $this->dompdfRender($page);
    }

    private function renderReportPdf(string $html): string
    {
        $page = $this->wrapHtml($html, 'Reporte UIF', '6.5pt');
        return $this->dompdfRender($page);
    }

    private function wrapHtml(string $body, string $title, string $fontSize): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">
            <title>' . htmlspecialchars($title) . '</title>
            <style>
                @page { size: letter portrait; margin: 8mm 10mm; }
                body { font-family: "Courier New", Courier, monospace; font-size: ' . $fontSize . '; margin: 0; padding: 0; }
                table { border-collapse: collapse; }
                table[border="1"] td, table[border="1"] th { border: 0.5pt solid #000; }
                th { font-weight: bold; text-align: center; }
                td { text-align: center; }
                th, td { padding: 3px; vertical-align: top; }
                h1 { font-size: 14pt; margin: 2px 0; }
                h2 { font-size: 11pt; margin: 2px 0; }
                h3 { font-size: 9pt; margin: 2px 0; }
                img { max-width: 100%; }
            </style>
        </head><body>' . $body . '</body></html>';
    }

    private function dompdfRender(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Courier');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    // ===================================================================
    //  HTML helpers
    // ===================================================================

    private function v($arr, string $key, string $default = ''): string
    {
        return htmlspecialchars((string) ($arr[$key] ?? $default), ENT_QUOTES, 'UTF-8');
    }

    private function n($arr, string $key, int $decimals = 2): string
    {
        $val = $arr[$key] ?? 0;
        return number_format((float) $val, $decimals, '.', ',');
    }

    // ===================================================================
    //  PCC-06 HTML builder
    // ===================================================================

    private function buildPcc06Html(
        $pcc, $empresa, $cambio, $factura, $beneficiario,
        string $agenciaDesc, string $deptDesc, string $ciudadDesc,
        string $actEconDesc, string $tipoDocAbrev, string $expedicionAbr,
        string $operacionDesc, string $monRecAbrev, string $monEntAbrev,
        string $paisDesc, $agenciaData, bool $blank = false
    ): string {
        $uifImg  = $this->imgBase64('uif.png');
        $logoImg = $this->imgBase64('gmbsrl.png');
        $dineroSino = $pcc['dinero_sino'] ?? 0;
        $facturaNum = $factura ? str_pad($factura['numero'] ?? '', 7, '0', STR_PAD_LEFT) : '';

        $html = '<div>';
        $html .= '<br><br><br><table align="center" border="0" cellpadding="2" cellspacing="2" width="100%"><tr>';
        $html .= '<td align="center" width="15%"><img src="' . $uifImg . '" width="80" /></td>';
        $html .= '<td align="center">';
        $html .= '<h2 align="center">Informaci&oacute;n sobre Operaciones Financieras</h2>';
        $html .= '<h3 align="center">Formulario PCC-06<br />(Empresas Remesadoras)</h3>';
        $html .= '</td>';
        $html .= '<td align="center" width="25%"><img src="' . $logoImg . '" width="140" />';
        if (!$blank && $facturaNum) {
            $html .= '<br /><span style="font-size:x-small">Pol&iacute;tica Conozca a su Cliente<br />Art. 26 D.S. 24771<br />F-' . $facturaNum . '</span>';
        } else {
            $html .= '<br /><span style="font-size:x-small">Pol&iacute;tica Conozca a su Cliente<br />Art. 26 D.S. 24771</span>';
        }
        $html .= '</td></tr>';
        $html .= '<tr><td align="center" colspan="3" nowrap><b>Fecha:</b> ' . $this->v($pcc, 'dia', date('d/m/Y')) . '</td></tr>';
        $html .= '</table>';

        $html .= '<table align="center" cellspacing="0" width="100%" style="table-layout: fixed;margin: 0 auto;">';
        $html .= '<col width="300px"><col width="220px"><col width="85px"><col width="85px"><col width="85px"><col>';
        $html .= '<tr><td colspan="6"><hr></td></tr>';

        $html .= '<tr style="border-top: 3px solid black;"><th colspan="6" style="text-align: left;">INFORMACI&Oacute;N DEL SUJETO OBLIGADO</th></tr>';
        $html .= '<tr><th colspan="1" style="text-align: left;">Raz&oacute;n Social: </th>';
        $html .= '<td colspan="3" style="text-align: left; white-space: nowrap;">' . $this->v($empresa, 'razon_social') . '</td>';
        $html .= '<th style="width: 70px; text-align: left;">NIT:</th>';
        $html .= '<td style="text-align: left;">' . $this->v($empresa, 'nit') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">ORIGEN DEL PCC: </th>';
        $html .= '<td style="text-align: left;">' . htmlspecialchars($agenciaDesc) . '</td>';
        $html .= '<th style="width: 70px; text-align: left;">Tel&eacute;fono:</th>';
        $html .= '<td style="text-align: left;">' . $this->v($agenciaData ?? [], 'telefono') . '</td>';
        $html .= '<th style="width: 100px; text-align: left;">Ciudad:</th>';
        $html .= '<td style="text-align: left;">' . htmlspecialchars($agenciaData['departamento_desc'] ?? '') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Zona: </th>';
        $html .= '<td colspan="1" style="text-align: left;">' . $this->v($agenciaData ?? [], 'zona') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Direccion donde se realiza el PCC:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($agenciaData ?? [], 'direccion') . '</td></tr>';
        $html .= '<tr><td colspan="6"><hr></td></tr>';

        $html .= '<tr><th colspan="6" style="text-align: left;"><b>DATOS DE USUARIO FINANCIERO(Persona Natural)</b></th></tr>';
        $html .= '<tr><th style="text-align: left;">Nombres</th><td style="text-align: left;">' . $this->v($pcc, 'nom_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Profesi&oacute;n:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'profesion') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Apellido:</th><td style="text-align: left;">' . $this->v($pcc, 'pat_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Pa&iacute;s de residencia:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'pais_residencia') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Apellido Materno o Casada:</th><td style="text-align: left;">' . $this->v($pcc, 'mat_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Nacionalidad:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'pais_usu') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Tipo Documento:</th><td style="text-align: left;">' . $this->v($pcc, 'tipo_doc_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Empresa de Trabajo:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'lugar_trabajo') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">No. Identidad:</th><td style="text-align: left;">' . $this->v($pcc, 'doc_usu') . $this->v($pcc, 'ext_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Cargo:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'cargo') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Tel&eacute;fono:</th><td style="text-align: left;">' . $this->v($pcc, 'tel_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Direccion de Trabajo:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'dir_trab') . '</td></tr>';

        $html .= '<tr><th colspan="6" style="text-align: left;">Direccion Usuario Financiero: </th></tr>';
        $html .= '<tr><th style="text-align: left;">Ciudad:</th><td style="text-align: left;">' . $this->v($pcc, 'ciudad_usu') . '</td>';
        $html .= '<th style="text-align: left;">Zona:</th><td style="text-align: left;" colspan="3">' . $this->v($pcc, 'zona_usu') . '</td></tr>';
        $html .= '<tr><th colspan="1" style="text-align: left;">Direcci&oacute;n: </th>';
        $html .= '<td colspan="5" style="text-align: left;">' . $this->v($pcc, 'dir_usu') . '</td></tr>';
        $html .= '<tr><th colspan="1" style="text-align: left;">Actividad Econ&oacute;mica: </th>';
        $html .= '<td colspan="5" style="text-align: left;">' . $this->v($pcc, 'actividad_economica') . '</td></tr>';

        if (!$blank && $dineroSino == 2) {
            $selectPer = $cambio['select_per'] ?? 0;
            $pers     = $selectPer == 1 ? '[PERSONA NATURAL]' : '[PERSONA JURIDICA]';
            $perN     = $selectPer == 1 ? 'Nombres y Apellidos:' : 'Razon Social:';
            $docLabel = $selectPer == 1 ? 'No documento:' : 'No NIT:';

            $benNombre = '';
            $benDoc    = '';
            $benDepto  = '';
            $benTel    = '';
            $benCiudad = '';
            $benZona   = '';
            $benDir    = '';
            $benAct    = '';

            if ($beneficiario) {
                if ($selectPer == 1) {
                    $benNombre = ($beneficiario['nombres'] ?? '') . ' ' . ($beneficiario['apellido_paterno'] ?? '') . ' ' . ($beneficiario['apellido_materno'] ?? '');
                    $benDoc    = ($beneficiario['nit'] ?? 0) != 0 ? ($beneficiario['nit'] ?? '') : ($beneficiario['numero_documento'] ?? '');
                } else {
                    $benNombre = $beneficiario['razon_social'] ?? '';
                    $benDoc    = $beneficiario['nit'] ?? '';
                }
                $benDepto  = $this->descripcion('departamento', 'id_departamento', $beneficiario['id_departamento'] ?? 0);
                $benTel    = $beneficiario['telefono'] ?? $beneficiario['telefono_principal'] ?? '';
                $benCiudad = $this->descripcion('ciudad_pcc', 'id_ciudad_pcc', $beneficiario['id_ciudad_pcc'] ?? 0);
                $benZona   = $beneficiario['zona'] ?? '';
                $benDir    = $beneficiario['domicilio'] ?? $beneficiario['domicilio_principal'] ?? '';
                $benAct    = $this->descActividad($beneficiario['id_actividad_economica'] ?? 0);
            }

            $html .= '<tr><th colspan="6" style="text-align: left;"><b>DATOS BENEFICIARIO ECON&Oacute;MICO</b></th></tr>';
            $html .= '<tr><th style="text-align: left;">El dinero es de su propiedad</th>';
            $html .= '<td style="text-align: left;">S&iacute; <span style="font-size: 20px;">&#x2714;</span> No</td>';
            $html .= '<td colspan="3" style="text-align: left;">' . htmlspecialchars($pers) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">' . htmlspecialchars($perN) . ' </th>';
            $html .= '<td colspan="1" style="text-align: left;">' . htmlspecialchars($benNombre) . '</td>';
            $html .= '<th colspan="1" style="text-align: left;">' . htmlspecialchars($docLabel) . ' </th>';
            $html .= '<td colspan="2" style="text-align: left;">' . htmlspecialchars($benDoc) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">Departamento: </th><td colspan="1" style="text-align: left;">' . htmlspecialchars($benDepto) . '</td>';
            $html .= '<th colspan="1" style="text-align: left;">Telefono: </th><td colspan="3" style="text-align: left;">' . htmlspecialchars($benTel) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">Ciudad: </th><td colspan="1" style="text-align: left;">' . htmlspecialchars($benCiudad) . '</td>';
            $html .= '<th colspan="1" style="text-align: left;">Zona: </th><td colspan="3" style="text-align: left;">' . htmlspecialchars($benZona) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">Direcci&oacute;n: </th><td colspan="5" style="text-align: left;">' . htmlspecialchars($benDir) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">Actividad Economica: </th><td colspan="5" style="text-align: left;">' . htmlspecialchars($benAct) . '</td></tr>';
        }

        $html .= '<tr><th colspan="6" style="text-align: left;"><b>DATOS DE LA OPERACI&Oacute;N QUE GENERA EL PCC</b></th></tr>';
        $html .= '<tr><th style="text-align: left;">Tipo: </th><td>UNICA</td>';
        $html .= '<th style="text-align: left;">Monto:</th><td align="center">' . $this->n($pcc, 'monto') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Operaci&oacute;n: </th><td style="text-align: left;">' . $this->v($pcc, 'operacion') . '</td>';
        $html .= '<th style="text-align: left;">Moneda Recibida:</th><td style="text-align: left;">' . $this->v($pcc, 'moneda_recibida') . '</td>';
        $html .= '<th style="text-align: left;">Moneda Cambio: </th><td style="text-align: left;">' . $this->v($pcc, 'moneda_cambio') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;" colspan="1">Origen de los Recursos</th>';
        $html .= '<td style="text-align: left;" colspan="5">' . $this->v($pcc, 'origen_dinero') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;" colspan="1">Destino de los Recursos</th>';
        $html .= '<td style="text-align: left;" colspan="5">' . $this->v($pcc, 'destino_dinero') . '</td></tr>';
        $html .= '</table>';

        $html .= '<table align="center" border="0" cellpadding="2" cellspacing="2" width="100%">';
        $html .= '<tr><td align="justify" style="font-size:xx-small">JURO QUE TODOS LOS DATOS DECLARADOS EN EL PRESENTE DOCUMENTO, A LA FECHA DEL FORMULARIO QUE FIGURAN, CORRESPONDEN A LA VERDAD. DE COMPROBARSE LA FALSEDAD DE ALGUNO DE ELLOS, SER&Eacute; SUJETO A LA SANCI&Oacute;N QUE ESTABLECE LA LEY. ME COMPROMETO EN CASO DE SER REQUERIDO, A PRESENTAR LA DOCUMENTACI&Oacute;N QUE SUSTENTE LO DECLARADO A EFECTO DE VERIFICACI&Oacute;N. EL CONTENIDO DE LA PRESENTE DECLARACI&Oacute;N ES DE EXCLUSIVA RESPONSABILIDAD DEL DECLARANTE.</td></tr>';
        $html .= '</table>';

        $html .= '<table align="center" border="0" cellpadding="1" cellspacing="30" width="100%">';
        $html .= '<tr><td align="center" valign="top" style="height:40px;">&nbsp;</td></tr>';
        $html .= '<tr>';
        $html .= '<td align="center" valign="top" style="border-top:0.25pt solid #000; margin:0; padding:0; height:100px;"><b>FIRMA FUNCIONARIO RECEPTOR</b><br />' . $this->v($pcc, 'funcionario') . '</td>';
        $html .= '<td align="center" valign="top" style="border-top:0.25pt solid #000; margin:0; padding:0; height:100px;"><b>FIRMA DECLARANTE</b><br />' . $this->v($pcc, 'nom_usu') . ' ' . $this->v($pcc, 'pat_usu') . ' ' . $this->v($pcc, 'mat_usu') . '</td>';
        $html .= '<td align="center" valign="top" style="border-top:0.25pt solid #000; margin:0; padding:0; height:100px;"><b>FIRMA ENCARGADO AGENCIA</b></td>';
        $html .= '</tr></table>';
        $html .= '</div>';

        return $html;
    }

    // ===================================================================
    //  PCC-08 HTML builder
    // ===================================================================

    private function buildPcc08Html(
        $pcc, $empresa, $transaccion, $factura, $beneficiario,
        string $agenciaDesc, string $operacionDesc, string $monRecAbrev,
        string $monEntAbrev, string $tipoDocAbrev, string $expedicionAbr,
        string $actEconDesc, string $paisDesc, $agenciaData,
        string $prefix, string $paisDestino, int $operacion, bool $blank = false
    ): string {
        $uifImg  = $this->imgBase64('uif.png');
        $logoImg = $this->imgBase64('gmbsrl.png');
        $dineroSino = $pcc['dinero_sino'] ?? 0;
        $facturaNum = $factura ? str_pad($factura['numero'] ?? '', 7, '0', STR_PAD_LEFT) : '';

        $html = '<div>';
        $html .= '<br><br><br><table align="center" border="0" cellpadding="2" cellspacing="2" width="100%"><tr>';
        $html .= '<td align="center" width="15%"><img src="' . $uifImg . '" width="80" /></td>';
        $html .= '<td align="center">';
        $html .= '<h2 align="center">Informaci&oacute;n sobre Operaciones Financieras</h2>';
        $html .= '<h3 align="center">Formulario PCC-08<br />(Empresas Remesadoras)</h3>';
        $html .= '</td>';
        $html .= '<td align="center" width="25%"><img src="' . $logoImg . '" width="140" />';
        if (!$blank && $facturaNum) {
            $html .= '<br /><span style="font-size:x-small">Pol&iacute;tica Conozca a su Cliente<br />Art. 26 D.S. 24771<br />F-' . $facturaNum . '</span>';
        } else {
            $html .= '<br /><span style="font-size:x-small">Pol&iacute;tica Conozca a su Cliente<br />Art. 26 D.S. 24771</span>';
        }
        $html .= '</td></tr>';
        $html .= '<tr><td align="center" colspan="3" nowrap><b>Fecha:</b> ' . $this->v($pcc, 'dia', date('d/m/Y')) . '</td></tr>';
        $html .= '</table>';

        $html .= '<table align="center" cellspacing="0" width="100%" style="table-layout: fixed;margin: 0 auto;">';
        $html .= '<col width="300px"><col width="220px"><col width="85px"><col width="85px"><col width="85px"><col>';
        $html .= '<tr><td colspan="6"><hr></td></tr>';

        $html .= '<tr style="border-top: 3px solid black;"><th colspan="6" style="text-align: left;">INFORMACI&Oacute;N DEL SUJETO OBLIGADO</th></tr>';
        $html .= '<tr><th colspan="1" style="text-align: left;">Raz&oacute;n Social: </th>';
        $html .= '<td colspan="3" style="text-align: left; white-space: nowrap;">' . $this->v($empresa, 'razon_social') . '</td>';
        $html .= '<th style="width: 70px; text-align: left;">NIT:</th>';
        $html .= '<td style="text-align: left;">' . $this->v($empresa, 'nit') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">ORIGEN DEL PCC: </th>';
        $html .= '<td style="text-align: left;">' . htmlspecialchars($agenciaDesc) . '</td>';
        $html .= '<th style="width: 70px; text-align: left;">Tel&eacute;fono:</th>';
        $html .= '<td style="text-align: left;">' . $this->v($agenciaData ?? [], 'telefono') . '</td>';
        $html .= '<th style="width: 100px; text-align: left;">Ciudad:</th>';
        $html .= '<td style="text-align: left;">' . htmlspecialchars($agenciaData['departamento_desc'] ?? '') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Zona: </th>';
        $html .= '<td colspan="1" style="text-align: left;">' . $this->v($agenciaData ?? [], 'zona') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Direccion donde se realiza el PCC:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($agenciaData ?? [], 'direccion') . '</td></tr>';
        $html .= '<tr><td colspan="6"><hr></td></tr>';

        $html .= '<tr><th colspan="6" style="text-align: left;"><b>DATOS DE USUARIO FINANCIERO(Persona Natural)</b></th></tr>';
        $html .= '<tr><th style="text-align: left;">Nombres</th><td style="text-align: left;">' . $this->v($pcc, 'nom_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Profesi&oacute;n:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'profesion') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Apellido:</th><td style="text-align: left;">' . $this->v($pcc, 'pat_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Pa&iacute;s de residencia:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'pais_residencia') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Apellido Materno o Casada:</th><td style="text-align: left;">' . $this->v($pcc, 'mat_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Nacionalidad:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'pais_usu') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Tipo Documento:</th><td style="text-align: left;">' . $this->v($pcc, 'tipo_doc_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Empresa de Trabajo:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'lugar_trabajo') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">No. Identidad:</th><td style="text-align: left;">' . $this->v($pcc, 'doc_usu') . $this->v($pcc, 'ext_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Cargo:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'cargo') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Tel&eacute;fono:</th><td style="text-align: left;">' . $this->v($pcc, 'tel_usu') . '</td>';
        $html .= '<th colspan="2" style="text-align: left;">Direccion de Trabajo:</th>';
        $html .= '<td colspan="2" style="text-align: left;">' . $this->v($pcc, 'dir_trab') . '</td></tr>';

        $html .= '<tr><th colspan="6" style="text-align: left;">Direccion Usuario Financiero: </th></tr>';
        $html .= '<tr><th style="text-align: left;">Ciudad:</th><td style="text-align: left;">' . $this->v($pcc, 'ciudad_usu') . '</td>';
        $html .= '<th style="text-align: left;">Zona:</th><td style="text-align: left;" colspan="3">' . $this->v($pcc, 'zona_usu') . '</td></tr>';
        $html .= '<tr><th colspan="1" style="text-align: left;">Direcci&oacute;n: </th>';
        $html .= '<td colspan="5" style="text-align: left;">' . $this->v($pcc, 'dir_usu') . '</td></tr>';
        $html .= '<tr><th colspan="1" style="text-align: left;">Actividad Econ&oacute;mica: </th>';
        $html .= '<td colspan="5" style="text-align: left;">' . $this->v($pcc, 'actividad_economica') . '</td></tr>';

        if (!$blank && $dineroSino == 2) {
            $selectPer = $transaccion['select_per'] ?? 0;
            $pers     = $selectPer == 1 ? '[PERSONA NATURAL]' : '[PERSONA JURIDICA]';
            $perN     = $selectPer == 1 ? 'Nombres y Apellidos:' : 'Razon Social:';
            $docLabel = $selectPer == 1 ? 'No documento:' : 'No NIT:';

            $benNombre = '';
            $benDoc    = '';
            $benDepto  = '';
            $benTel    = '';
            $benCiudad = '';
            $benZona   = '';
            $benDir    = '';
            $benAct    = '';

            if ($beneficiario) {
                if ($selectPer == 1) {
                    $benNombre = ($beneficiario['nombres'] ?? '') . ' ' . ($beneficiario['apellido_paterno'] ?? '') . ' ' . ($beneficiario['apellido_materno'] ?? '');
                    $benDoc    = ($beneficiario['nit'] ?? 0) != 0 ? ($beneficiario['nit'] ?? '') : ($beneficiario['numero_documento'] ?? '');
                } else {
                    $benNombre = $beneficiario['razon_social'] ?? '';
                    $benDoc    = $beneficiario['nit'] ?? '';
                }
                $benDepto  = $this->descripcion('departamento', 'id_departamento', $beneficiario['id_departamento'] ?? 0);
                $benTel    = $beneficiario['telefono'] ?? $beneficiario['telefono_principal'] ?? '';
                $benCiudad = $this->descripcion('ciudad_pcc', 'id_ciudad_pcc', $beneficiario['id_ciudad_pcc'] ?? 0);
                $benZona   = $beneficiario['zona'] ?? '';
                $benDir    = $beneficiario['domicilio'] ?? $beneficiario['domicilio_principal'] ?? '';
                $benAct    = $this->descActividad($beneficiario['id_actividad_economica'] ?? 0);
            }

            $html .= '<tr><th colspan="6" style="text-align: left;"><b>DATOS BENEFICIARIO ECON&Oacute;MICO</b></th></tr>';
            $html .= '<tr><th style="text-align: left;">El dinero es de su propiedad</th>';
            $html .= '<td style="text-align: left;">S&iacute; <span style="font-size: 20px;">&#x2714;</span> No</td>';
            $html .= '<td colspan="3" style="text-align: left;">' . htmlspecialchars($pers) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">' . htmlspecialchars($perN) . ' </th>';
            $html .= '<td colspan="1" style="text-align: left;">' . htmlspecialchars($benNombre) . '</td>';
            $html .= '<th colspan="1" style="text-align: left;">' . htmlspecialchars($docLabel) . ' </th>';
            $html .= '<td colspan="2" style="text-align: left;">' . htmlspecialchars($benDoc) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">Departamento: </th><td colspan="1" style="text-align: left;">' . htmlspecialchars($benDepto) . '</td>';
            $html .= '<th colspan="1" style="text-align: left;">Telefono: </th><td colspan="3" style="text-align: left;">' . htmlspecialchars($benTel) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">Ciudad: </th><td colspan="1" style="text-align: left;">' . htmlspecialchars($benCiudad) . '</td>';
            $html .= '<th colspan="1" style="text-align: left;">Zona: </th><td colspan="3" style="text-align: left;">' . htmlspecialchars($benZona) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">Direcci&oacute;n: </th><td colspan="5" style="text-align: left;">' . htmlspecialchars($benDir) . '</td></tr>';
            $html .= '<tr><th colspan="1" style="text-align: left;">Actividad Economica: </th><td colspan="5" style="text-align: left;">' . htmlspecialchars($benAct) . '</td></tr>';
        }

        $html .= '<tr><th colspan="6" style="text-align: left;"><b>DATOS DE LA OPERACI&Oacute;N QUE GENERA EL PCC</b></th></tr>';

        $codigoTrans = $prefix . ($transaccion['codigo'] ?? '');
        $fechaField  = $operacion == 3 ? 'fec_crea_giro' : 'fec_crea_remesa';

        $html .= '<tr><th style="text-align: left;">Tipo: </th><td>UNICA</td>';
        $html .= '<th style="text-align: left;">Codigo:</th><td align="center">' . htmlspecialchars($codigoTrans) . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Operaci&oacute;n: </th><td style="text-align: left;">' . $this->v($pcc, 'operacion') . '</td>';
        $html .= '<th style="text-align: left;">Pais Origen:</th><td style="text-align: left;">' . ($pcc['pais_origen'] ?? 'BOL') . '</td>';
        $html .= '<th style="text-align: left;">Pais Destino: </th><td style="text-align: left;">' . htmlspecialchars($paisDestino) . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Moneda Recibida: </th><td style="text-align: left;">' . $this->v($pcc, 'moneda_recibida') . '</td>';
        $html .= '<th style="text-align: left;">Monto:</th><td style="text-align: left;">' . $this->n($transaccion, 'monto') . '</td>';
        $html .= '<th style="text-align: left;">Fecha: </th><td style="text-align: left;">' . $this->v($transaccion, $fechaField) . '</td></tr>';
        $html .= '<tr><th style="text-align: left;">Moneda Entregada: </th><td style="text-align: left;">' . $this->v($pcc, 'moneda_cambio') . '</td>';
        $html .= '<th style="text-align: left;">Total BOB:</th><td style="text-align: left;">' . $this->n($transaccion, 'total_cambio') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;" colspan="1">Origen de los Recursos</th>';
        $html .= '<td style="text-align: left;" colspan="5">' . $this->v($pcc, 'origen_dinero') . '</td></tr>';
        $html .= '<tr><th style="text-align: left;" colspan="1">Destino de los Recursos</th>';
        $html .= '<td style="text-align: left;" colspan="5">' . $this->v($pcc, 'destino_dinero') . '</td></tr>';
        $html .= '</table>';

        $html .= '<table align="center" border="0" cellpadding="2" cellspacing="2" width="100%">';
        $html .= '<tr><td align="justify" style="font-size:xx-small">JURO QUE TODOS LOS DATOS DECLARADOS EN EL PRESENTE DOCUMENTO, A LA FECHA DEL FORMULARIO QUE FIGURAN, CORRESPONDEN A LA VERDAD. DE COMPROBARSE LA FALSEDAD DE ALGUNO DE ELLOS, SER&Eacute; SUJETO A LA SANCI&Oacute;N QUE ESTABLECE LA LEY. ME COMPROMETO EN CASO DE SER REQUERIDO, A PRESENTAR LA DOCUMENTACI&Oacute;N QUE SUSTENTE LO DECLARADO A EFECTO DE VERIFICACI&Oacute;N. EL CONTENIDO DE LA PRESENTE DECLARACI&Oacute;N ES DE EXCLUSIVA RESPONSABILIDAD DEL DECLARANTE.</td></tr>';
        $html .= '</table>';

        $html .= '<table align="center" border="0" cellpadding="1" cellspacing="30" width="100%">';
        $html .= '<tr><td align="center" valign="top" style="height:40px;">&nbsp;</td></tr>';
        $html .= '<tr>';
        $html .= '<td align="center" valign="top" style="border-top:0.25pt solid #000; margin:0; padding:0; height:100px;"><b>FIRMA FUNCIONARIO RECEPTOR</b><br />' . $this->v($pcc, 'funcionario') . '</td>';
        $html .= '<td align="center" valign="top" style="border-top:0.25pt solid #000; margin:0; padding:0; height:100px;"><b>FIRMA DECLARANTE</b><br />' . $this->v($pcc, 'nom_usu') . ' ' . $this->v($pcc, 'pat_usu') . ' ' . $this->v($pcc, 'mat_usu') . '</td>';
        $html .= '<td align="center" valign="top" style="border-top:0.25pt solid #000; margin:0; padding:0; height:100px;"><b>FIRMA ENCARGADO AGENCIA</b></td>';
        $html .= '</tr></table>';
        $html .= '</div>';

        return $html;
    }

    // ===================================================================
    //  UIF Report HTML builder
    // ===================================================================

    private function buildReportHtml(array $dato): string
    {
        $numPcc = ($dato['id_operacion'] == 1 || $dato['id_operacion'] == 2) ? '6' : '8';

        $dias  = ['Domingo', 'Lunes', 'Martes', 'Mi&eacute;rcoles', 'Jueves', 'Viernes', 'S&aacute;bado'];
        $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $fecha    = strtotime($dato['fec_crea_reporte_uif'] ?? '');
        $fechaStr = $fecha
            ? $dias[date('w', $fecha)] . ', ' . date('d', $fecha) . ' de ' . $meses[date('n', $fecha) - 1] . ' de ' . date('Y', $fecha)
            : '';

        $uifImg  = $this->imgBase64('uif.png');
        $logoImg = $this->imgBase64('gmbsrl.png');
        $agenciaDesc = $this->descripcion('agencia', 'id_agencia', $dato['id_agencia'] ?? 0);

        $tipoDocDesc  = $this->descripcion('tipo_doc_identidad', 'id_tipo_doc_identidad', $dato['id_tipo_doc_identidad'] ?? 0);
        $expedDesc    = $this->descripcion('expedicion', 'id_expedicion', $dato['id_expedicion'] ?? 0);
        $sexoDesc     = $this->descripcion('sexo', 'id_sexo', $dato['id_sexo'] ?? 0);
        $edoCivilDesc = $this->descripcion('estado_civil', 'id_estado_civil', $dato['id_estado_civil'] ?? 0);
        $paisDesc     = $this->descripcion('pais', 'id_pais', $dato['id_pais'] ?? 0);
        $paisBenDesc  = $this->descripcion('pais', 'id_pais', $dato['id_pais_beneficiario'] ?? 0);
        $operDesc     = $this->descripcion('operacion', 'id_operacion', $dato['id_operacion'] ?? 0);
        $monRecDesc   = $this->descripcion('moneda', 'id_moneda', $dato['mon_rec'] ?? 0);
        $monEntDesc   = $this->descripcion('moneda', 'id_moneda', $dato['mon_ent'] ?? 0);

        $html = '<div>';
        $html .= '<table align="center" border="0" cellpadding="5" cellspacing="5"><tr>';
        $html .= '<td width="80" valign="top"><img src="' . $uifImg . '" width="80" /></td>';
        $html .= '<td align="center" nowrap>';
        $html .= '<h1 align="center">Informaci&oacute;n sobre Operaciones Financieras</h1>';
        $html .= '<h2 align="center">Formulario PCC-0' . $numPcc . '<br />(Empresas Remesadoras)</h2>';
        $html .= '</td>';
        $html .= '<td width="100" valign="top"><img src="' . $logoImg . '" width="150" /></td>';
        $html .= '</tr></table>';
        $html .= '<p align="right" style="margin-right:10pt;"><b>' . htmlspecialchars($agenciaDesc) . '</b> - ' . $fechaStr . '</p>';

        $html .= '<table align="center" border="1" cellpadding="3" cellspacing="0">';
        $html .= '<tr><th colspan="3"><b>1. DATOS DE USUARIO FINANCIERO</b></th></tr>';
        $html .= '<tr><th width="180"><label>Tipo de documento</label></th>';
        $html .= '<th width="180"><label>N&uacute;mero documento</label></th>';
        $html .= '<th width="180"><label>Lugar de emisi&oacute;n</label></th></tr>';
        $html .= '<tr><td align="center">' . htmlspecialchars($tipoDocDesc) . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'num_doc_identidad') . '</td>';
        $html .= '<td align="center">' . htmlspecialchars($expedDesc) . '</td></tr>';

        $html .= '<tr><th><label>Nombres</label></th>';
        $html .= '<th><label>Apellido Paterno</label></th>';
        $html .= '<th><label>Apellido Materno</label></th></tr>';
        $html .= '<tr><td align="center">' . $this->v($dato, 'nombres') . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'ape_pat') . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'ape_mat') . '</td></tr>';

        $html .= '<tr><th><label>G&eacute;nero</label></th>';
        $html .= '<th><label>Estado Civil</label></th>';
        $html .= '<th><label>Ocupaci&oacute;n</label></th></tr>';
        $html .= '<tr><td align="center">' . htmlspecialchars($sexoDesc) . '</td>';
        $html .= '<td align="center">' . htmlspecialchars($edoCivilDesc) . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'ocupacion') . '</td></tr>';

        $html .= '<tr><th><label>Pa&iacute;s</label></th>';
        $html .= '<th><label>Lugar de Nacimiento</label></th>';
        $html .= '<th><label>Fecha de Nacimiento</label></th></tr>';
        $html .= '<tr><td align="center">' . htmlspecialchars($paisDesc) . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'lugar_nacimiento') . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'fecha_nacimiento') . '</td></tr>';

        $html .= '<tr><th><label>Tel&eacute;fono (Domicilio)</label></th>';
        $html .= '<th><label>Tel&eacute;fono (Trabajo)</label></th>';
        $html .= '<th><label>Celular</label></th></tr>';
        $html .= '<tr><td align="center">' . $this->v($dato, 'tel_dom') . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'tel_trab') . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'celular') . '</td></tr>';

        $html .= '<tr><th colspan="3"><label>Direcci&oacute;n (Domicilio)</label></th></tr>';
        $html .= '<tr><td align="center" colspan="3">' . $this->v($dato, 'domicilio') . '</td></tr>';
        $html .= '</table>';

        $html .= '<br />';

        $html .= '<table align="center" border="1" cellpadding="3" cellspacing="0" width="100%">';
        $html .= '<tr><th colspan="2"><b>2. BENEFICIARIO ECON&Oacute;MICO</b></th></tr>';
        $html .= '<tr><th colspan="2"><label>Nombres Completo / Raz&oacute;n Social</label></th></tr>';
        $html .= '<tr><td align="center" colspan="2">' . $this->v($dato, 'beneficiario') . '</td></tr>';
        $html .= '<tr><th><label>Pa&iacute;s</label></th>';
        $html .= '<th><label>Ciudad</label></th></tr>';
        $html .= '<tr><td align="center">' . htmlspecialchars($paisBenDesc) . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'ciudad_beneficiario') . '</td></tr>';
        $html .= '<tr><th colspan="2"><label>Direcci&oacute;n (Domicilio)</label></th></tr>';
        $html .= '<tr><td align="center" colspan="2">' . $this->v($dato, 'direccion_beneficiario') . '</td></tr>';
        $html .= '</table>';

        $html .= '<br />';

        $html .= '<table align="center" border="1" cellpadding="3" cellspacing="0" width="100%">';
        $html .= '<tr><th colspan="3"><b>3. DATOS DE OPERACI&Oacute;N</b></th></tr>';
        $html .= '<tr><th><label>Operaci&oacute;n</label></th>';
        $html .= '<th><label>Moneda Recibida</label></th>';
        $html .= '<th><label>Moneda Entregada</label></th></tr>';
        $html .= '<tr><td align="center">' . htmlspecialchars($operDesc) . '</td>';
        $html .= '<td align="center">' . htmlspecialchars($monRecDesc) . '</td>';
        $html .= '<td align="center">' . htmlspecialchars($monEntDesc) . '</td></tr>';

        $html .= '<tr><th><label>Tipo de Cambio</label></th>';
        $html .= '<th><label>Monto</label></th>';
        $html .= '<th><label>Total [BOB]</label></th></tr>';
        $html .= '<tr><td align="center">' . $this->v($dato, 'tipo_cambio') . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'monto') . '</td>';
        $html .= '<td align="center">' . $this->v($dato, 'total') . '</td></tr>';

        $html .= '<tr><th colspan="3"><label>Motivo de la transacci&oacute;n</label></th></tr>';
        $html .= '<tr><td align="center" colspan="3">' . $this->v($dato, 'motivo') . '</td></tr>';
        $html .= '</table>';

        $html .= '<br />';

        $html .= '<table align="center" cellpadding="5" cellspacing="5" width="30%">';
        $html .= '<tr><td style="border-bottom:0.25pt solid #000; margin:0; padding:0; height:100px;"> </td></tr>';
        $html .= '<tr><td align="center"><b>FIRMA DECLARANTE</b></td></tr>';
        $html .= '</table>';

        $html .= '</div>';

        return $html;
    }
}
