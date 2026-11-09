<?php
/**
 * RisksController — informes de riesgo (reutiliza Panel / Dashboard del PHP).
 */

namespace CgrApi\Controllers;

use CgrApi\Backend;
use CgrApi\Validator;
use CgrApi\Http\ApiResponse;

class RisksController
{
    private $backend;
    public function __construct(Backend $backend) { $this->backend = $backend; }

    /** GET /api/risks — catálogo de informes. */
    public function index($params, $q)
    {
        ApiResponse::ok($this->backend->risksCatalog());
    }

    /** GET /api/risks/{slug} — datos estructurados del informe. */
    public function show($params, $q)
    {
        $slug = Validator::inList($params['slug'], $this->backend->riskSlugs(), 'slug');
        list($ini, $fin) = Validator::resolvePeriodo($q);
        $data = $this->backend->riskData($slug, $ini, $fin);
        ApiResponse::ok(array('slug' => $slug, 'ini' => $ini, 'fin' => $fin, 'informe' => $data));
    }

    /** GET /api/risks/{slug}/pdf — PDF para visualizar o descargar. */
    public function pdf($params, $q)
    {
        $slug = Validator::inList($params['slug'], $this->backend->riskSlugs(), 'slug');
        list($ini, $fin) = Validator::resolvePeriodo($q);
        $bin  = $this->backend->riskPdf($slug, $ini, $fin);
        $name = $this->backend->riskPdfName($slug, $ini, $fin);
        $disp = (isset($q['disposition']) && $q['disposition'] === 'inline') ? 'inline' : 'attachment';
        ApiResponse::binary($bin, 'application/pdf', $name, $disp);
    }

    /** GET /api/risks/dashboard — resumen ejecutivo consolidado. */
    public function dashboard($params, $q)
    {
        list($ini, $fin) = Validator::resolvePeriodo($q);
        $forzar = isset($q['recalcular']);
        ApiResponse::ok($this->backend->dashboard($ini, $fin, $forzar));
    }
}
