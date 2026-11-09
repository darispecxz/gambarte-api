<?php

namespace App\Modules\Auditoria\Services;

use App\Modules\Auditoria\Xlsx\Xlsx;
use App\Modules\Auditoria\Xlsx\Zip;
use App\Modules\Shared\Database\RawConnection;

class ReportesService
{
    /* ── Catálogo ─────────────────────────────────────────────────── */

    public function catalog(): array
    {
        $out = [];
        foreach (self::catalogFull() as $c) {
            $out[] = ['code' => $c['code'], 'title' => $c['title'], 'db' => $c['db']];
        }
        return $out;
    }

    public function codes(): array
    {
        return array_keys(self::catalogFull());
    }

    private static function catalogFull(): array
    {
        return array(
            '00' => array('code'=>'00', 'file'=>'00_REPORTE_INTENTOS_LOGIN',        'db'=>'cgr',
                          'title'=>'Intentos / Inicio de Sesión (Resumen)'),
            '01' => array('code'=>'01', 'file'=>'01_REPORTE_PISTAS_AUDITORIA_CAJERO','db'=>'cgr',
                          'title'=>'Pistas de Auditoría — Cajero'),
            '02' => array('code'=>'02', 'file'=>'02_REPORTE_LOG_ADMIN_Y_OPERADOR',  'db'=>'cgr',
                          'title'=>'Log Administrador y Operador'),
            '03' => array('code'=>'03', 'file'=>'03_REPORTE_LOG_OTROS_PERFILES',    'db'=>'cgr',
                          'title'=>'Log Otros Perfiles'),
            '04' => array('code'=>'04', 'file'=>'04_REPORTE_LOG_DETALLADO',         'db'=>'cgr',
                          'title'=>'Log Detallado (bitácora interna)'),
            '05' => array('code'=>'05', 'file'=>'05_REPORTE_PISTAS_CONTA',          'db'=>'conta',
                          'title'=>'Pistas de Auditoría — Contabilidad'),
            '06' => array('code'=>'06', 'file'=>'06_REP_LOGS_SESSION_CONTA',        'db'=>'conta',
                          'title'=>'Logs de Sesión — Contabilidad'),
            '07' => array('code'=>'07', 'file'=>'07_RESUMEN_EJECUTIVO',             'db'=>'cgr+conta',
                          'title'=>'Resumen Ejecutivo (todos los logs)'),
        );
    }

    /* ── Período ─────────────────────────────────────────────────── */

    private static function period(string $periodo): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $periodo, $m)) {
            throw new \InvalidArgumentException('Período inválido. Use el formato AAAA-MM (ej: 2026-01).');
        }
        $y  = (int)$m[1];
        $mo = (int)$m[2];
        if ($mo < 1 || $mo > 12) {
            throw new \InvalidArgumentException('Mes fuera de rango en el período.');
        }

        $ini = sprintf('%04d-%02d-01 00:00:00', $y, $mo);
        $ny  = $mo == 12 ? $y + 1 : $y;
        $nm  = $mo == 12 ? 1 : $mo + 1;
        $fin = sprintf('%04d-%02d-01 00:00:00', $ny, $nm);

        $meses = array(1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
                       'Agosto','Septiembre','Octubre','Noviembre','Diciembre');
        return array(
            'ini'    => $ini,
            'fin'    => $fin,
            'anio'   => $y,
            'mes'    => $mo,
            'mesTxt' => $meses[$mo],
            'label'  => $meses[$mo] . ' ' . $y,
            'folder' => $y . ' - ' . $meses[$mo],
        );
    }

    /* ── Nombres de archivo ──────────────────────────────────────── */

    public function excelFilename(string $code, string $periodo): string
    {
        $cat = self::catalogFull();
        $p   = self::period($periodo);
        return $cat[$code]['file'] . '_' . $p['anio'] . '-' . sprintf('%02d', $p['mes']) . '.xlsx';
    }

    public function zipFilename(string $periodo): string
    {
        return 'REPORTES_AUDITORIA_' . str_replace('-', '_', $periodo) . '.zip';
    }

    /* ── Datos (JSON) ────────────────────────────────────────────── */

    public function data(string $code, string $periodo): array
    {
        $p   = self::period($periodo);
        $cat = self::catalogFull();
        if (!isset($cat[$code])) {
            throw new \InvalidArgumentException("Reporte desconocido: $code");
        }

        switch ($code) {
            case '00': $sheets = $this->d00($p); break;
            case '01': $sheets = $this->d01($p); break;
            case '02': $sheets = $this->d02($p); break;
            case '03': $sheets = $this->d03($p); break;
            case '04': $sheets = $this->d04($p); break;
            case '05': $sheets = $this->d05($p); break;
            case '06': $sheets = $this->d06($p); break;
            case '07': $sheets = $this->d07($p); break;
            default:   throw new \InvalidArgumentException("Reporte desconocido: $code");
        }
        return array(
            'report'  => $code,
            'title'   => $cat[$code]['title'],
            'db'      => $cat[$code]['db'],
            'periodo' => $periodo,
            'label'   => $p['label'],
            'sheets'  => $sheets,
        );
    }

    /* ── Excel (.xlsx) ───────────────────────────────────────────── */

    public function excel(string $code, string $periodo): string
    {
        $d    = $this->data($code, $periodo);
        $xlsx = $this->buildXlsx($d['sheets']);
        return $xlsx->toString();
    }

    public function zip(string $periodo): string
    {
        $files = [];
        foreach (self::catalogFull() as $key => $meta) {
            $d    = $this->data($key, $periodo);
            $xlsx = $this->buildXlsx($d['sheets']);
            $files[$this->excelFilename($key, $periodo)] = $xlsx->toString();
        }
        return Zip::build($files);
    }

    private function buildXlsx(array $sheets): Xlsx
    {
        $xlsx = new Xlsx();
        foreach ($sheets as $s) {
            $sh = $xlsx->addSheet($s['name']);
            $widths = array(); $labels = array(); $formats = array();
            foreach ($s['columns'] as $c) {
                $widths[]  = $c['width'];
                $labels[]  = $c['label'];
                $formats[] = $c['type'];
            }
            $sh->setCols($widths);
            if (!empty($s['titles'])) {
                $first = true;
                foreach ($s['titles'] as $t) {
                    $sh->addTitle($t, count($labels), $first ? Xlsx::S_TITLE : Xlsx::S_SUBTITLE);
                    $first = false;
                }
                if (!empty($s['blank'])) { $sh->addBlank(); }
            }
            $sh->addHeader($labels);
            foreach ($s['rows'] as $r) {
                $sh->addRow($r, $formats);
            }
        }
        return $xlsx;
    }

    /* ── Helpers ──────────────────────────────────────────────────── */

    private static function col(string $label, string $type, int $width): array
    {
        return array('label' => $label, 'type' => $type, 'width' => $width);
    }

    private static function tipoEvento(string $evento): string
    {
        $t = preg_replace('/CREDENCIALES:\s*\S+/u', 'CREDENCIALES', $evento);
        $t = preg_replace('/#\d+/', '#', $t);
        return trim($t);
    }

    private static function limpiaDetalle($d): string
    {
        if ($d === null) return '';
        $d = html_entity_decode($d, ENT_QUOTES, 'UTF-8');
        $d = str_replace(array('&lt;','&gt;','&amp;'), array('<','>','&'), $d);
        $d = strip_tags($d);
        $d = preg_replace('/\s+/u', ' ', $d);
        $d = trim($d);
        if (mb_strlen($d) > 300) { $d = mb_substr($d, 0, 300) . '…'; }
        return $d;
    }

    /* ═══════════════════════════════════════════════════════════════
       00 — INTENTOS / INICIO DE SESIÓN
       ═══════════════════════════════════════════════════════════════ */
    private function d00(array $p): array
    {
        $rows = RawConnection::select("
            SELECT
              CONCAT(t.nom_trab,' ',t.ape_pat_trab,' ',t.ape_mat_trab) AS NOMBRE,
              r.descripcion AS ROL,
              u.login_usu   AS USUARIO,
              (SELECT b2.ip FROM bitacora b2
                 WHERE b2.login_usu = u.login_usu
                   AND b2.fec_crea_bitacora >= :ini AND b2.fec_crea_bitacora < :fin
                 ORDER BY b2.fec_crea_bitacora DESC LIMIT 1) AS ULTIMO_IP,
              COUNT(b.login_usu) AS CANTIDAD
            FROM bitacora b
            INNER JOIN usuario u      ON u.login_usu   = b.login_usu
            INNER JOIN trabajador t   ON t.cod_trab    = u.cod_trab
            INNER JOIN rol_usuario ru ON ru.cod_usuario= u.cod_usuario
            INNER JOIN rol r          ON r.id_rol      = ru.id_rol
            WHERE b.fec_crea_bitacora >= :ini AND b.fec_crea_bitacora < :fin
              AND b.login_usu <> ''
            GROUP BY NOMBRE, ROL, USUARIO
            ORDER BY NOMBRE
        ", array(':ini' => $p['ini'], ':fin' => $p['fin']), 'cgr');

        $data = array();
        foreach ($rows as $r) {
            $data[] = array($r['NOMBRE'], $r['ROL'], $r['USUARIO'], $r['ULTIMO_IP'], (int)$r['CANTIDAD']);
        }
        return array(array(
            'name'    => 'Hoja1',
            'titles'  => array('INICIO DE SESIÓN — RESUMEN DE ACTIVIDAD', 'Correspondiente ' . $p['label']),
            'blank'   => true,
            'columns' => array(
                self::col('NOMBRE','text',34), self::col('ROL','text',26),
                self::col('USUARIO','text',16), self::col('ULTIMO_IP','text',40),
                self::col('CANTIDAD','int',12),
            ),
            'rows' => $data,
        ));
    }

    /* ═══════════════════════════════════════════════════════════════
       01 — PISTAS DE AUDITORÍA CAJERO (3 hojas)
       ═══════════════════════════════════════════════════════════════ */
    private function d01(array $p): array
    {
        $args = array(':ini' => $p['ini'], ':fin' => $p['fin']);

        $rowsOp = array();
        foreach (RawConnection::select("
            SELECT
                a.descripcion AS Agencia,
                CONCAT(t.nom_trab,' ',t.ape_pat_trab,' ',t.ape_mat_trab) AS Nombre,
                u.login_usu AS Usuario,
                COALESCE(c.cant,0) AS cambios,
                COALESCE(g.cant,0) AS giros,
                COALESCE(r.cant,0) AS remesas,
                COALESCE(pp.cant,0) AS pagos,
                COALESCE(c.cant,0)+COALESCE(g.cant,0)+COALESCE(r.cant,0)+COALESCE(pp.cant,0) AS total
            FROM usuario u
            INNER JOIN trabajador t ON t.cod_trab = u.cod_trab
            INNER JOIN (SELECT cod_usuario, MAX(id_caja) AS id_caja FROM caja GROUP BY cod_usuario) cx
                    ON cx.cod_usuario = u.cod_usuario
            INNER JOIN agencia a ON a.id_agencia =
                    (SELECT c2.id_agencia FROM caja c2 WHERE c2.id_caja = cx.id_caja LIMIT 1)
            LEFT JOIN (SELECT cod_usuario, COUNT(*) AS cant FROM cambio
                       WHERE facturado=1 AND fec_crea_cambio>=:ini AND fec_crea_cambio<:fin
                       GROUP BY cod_usuario) c  ON c.cod_usuario  = u.cod_usuario
            LEFT JOIN (SELECT cod_usuario, COUNT(*) AS cant FROM giro
                       WHERE facturado=1 AND fec_crea_giro>=:ini AND fec_crea_giro<:fin
                       GROUP BY cod_usuario) g  ON g.cod_usuario  = u.cod_usuario
            LEFT JOIN (SELECT cod_usuario, COUNT(*) AS cant FROM remesa
                       WHERE facturado=1 AND fec_crea_remesa>=:ini AND fec_crea_remesa<:fin
                       GROUP BY cod_usuario) r  ON r.cod_usuario  = u.cod_usuario
            LEFT JOIN (SELECT cod_usuario, COUNT(*) AS cant FROM pago
                       WHERE fec_crea_pago>=:ini AND fec_crea_pago<:fin
                       GROUP BY cod_usuario) pp ON pp.cod_usuario = u.cod_usuario
            WHERE (COALESCE(c.cant,0)+COALESCE(g.cant,0)+COALESCE(r.cant,0)+COALESCE(pp.cant,0)) > 0
            ORDER BY Agencia, Nombre
        ", $args, 'cgr') as $r) {
            $rowsOp[] = array($r['Agencia'], $r['Nombre'], $r['Usuario'],
                              (int)$r['cambios'], (int)$r['giros'], (int)$r['remesas'],
                              (int)$r['pagos'], (int)$r['total']);
        }

        $rowsCaj = array();
        foreach (RawConnection::select("
            SELECT login_usu, fec_crea_bitacora, evento, ip FROM bitacora
            WHERE fec_crea_bitacora>=:ini AND fec_crea_bitacora<:fin AND evento LIKE '%REGISTRADO%'
            ORDER BY fec_crea_bitacora
        ", $args, 'cgr') as $r) {
            $rowsCaj[] = array($r['login_usu'], $r['fec_crea_bitacora'], $r['evento'], $r['ip']);
        }

        $rowsCajs = array();
        foreach (RawConnection::select("
            SELECT u.login_usu,
                    CONCAT(t.nom_trab,' ',t.ape_pat_trab,' ',t.ape_mat_trab) AS NOMBRE,
                    r.descripcion AS CARGO
             FROM bitacora b
             INNER JOIN usuario u      ON u.login_usu    = b.login_usu
             INNER JOIN trabajador t   ON t.cod_trab     = u.cod_trab
             INNER JOIN rol_usuario ru ON ru.cod_usuario = u.cod_usuario
             INNER JOIN rol r          ON r.id_rol       = ru.id_rol
             WHERE b.fec_crea_bitacora>=:ini AND b.fec_crea_bitacora<:fin
               AND b.evento LIKE '%REGISTRADO%' AND b.login_usu <> ''
             GROUP BY u.login_usu, NOMBRE, CARGO
             ORDER BY NOMBRE
        ", $args, 'cgr') as $r) {
            $rowsCajs[] = array($r['login_usu'], $r['NOMBRE'], $r['CARGO']);
        }

        return array(
            array(
                'name'    => 'Op X Caj',
                'titles'  => array('Resumen por Cajero y por Tipo de Operaciones', 'Correspondiente ' . $p['label']),
                'columns' => array(
                    self::col('Agencia','text',26), self::col('Nombre','text',30),
                    self::col('Usuario','text',16), self::col('Nro. Op. por Cambio','int',14),
                    self::col('Nro. Op. por Giro','int',12), self::col('Nro. Op. por Remesas','int',16),
                    self::col('Nro. Op. por Pago','int',14), self::col('Total General','int',14),
                ),
                'rows' => $rowsOp,
            ),
            array(
                'name'    => 'Cajero',
                'columns' => array(
                    self::col('USUARIO','text',16), self::col('FECHA/HORA','datetime',20),
                    self::col('EVENTO','text',52), self::col('IP','text',40),
                ),
                'rows' => $rowsCaj,
            ),
            array(
                'name'    => 'Cajeros',
                'columns' => array(
                    self::col('USUARIO','text',16), self::col('NOMBRE','text',34),
                    self::col('CARGO','text',24),
                ),
                'rows' => $rowsCajs,
            ),
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       02 — LOG ADMIN Y OPERADOR
       ═══════════════════════════════════════════════════════════════ */
    private function d02(array $p): array
    {
        $rows = array();
        foreach (RawConnection::select("
            SELECT DISTINCT b.login_usu, b.fec_crea_bitacora, b.evento, b.desde, b.ip
            FROM bitacora b
            INNER JOIN usuario us     ON us.login_usu   = b.login_usu
            INNER JOIN rol_usuario ru ON ru.cod_usuario = us.cod_usuario
            INNER JOIN rol ro         ON ro.id_rol      = ru.id_rol
            WHERE b.fec_crea_bitacora>=:ini AND b.fec_crea_bitacora<:fin
              AND ro.id_rol IN (1,2)
            ORDER BY b.fec_crea_bitacora
        ", array(':ini' => $p['ini'], ':fin' => $p['fin']), 'cgr') as $r) {
            $rows[] = array($r['login_usu'], $r['fec_crea_bitacora'], $r['evento'], $r['desde'], $r['ip']);
        }
        return array(array(
            'name'    => 'Admin Operador',
            'columns' => array(
                self::col('USUARIO','text',16), self::col('FECHA/HORA','datetime',20),
                self::col('EVENTO','text',60), self::col('DESDE','text',16), self::col('IP','text',40),
            ),
            'rows' => $rows,
        ));
    }

    /* ═══════════════════════════════════════════════════════════════
       03 — LOG OTROS PERFILES (2 hojas)
       ═══════════════════════════════════════════════════════════════ */
    private function d03(array $p): array
    {
        $args = array(':ini' => $p['ini'], ':fin' => $p['fin']);

        $rows = array();
        foreach (RawConnection::select("
            SELECT DISTINCT TRIM(b.login_usu) AS login_usu, b.fec_crea_bitacora,
                    b.evento, b.desde, b.ip
            FROM bitacora b
            INNER JOIN usuario us     ON us.login_usu   = b.login_usu
            INNER JOIN rol_usuario ru ON ru.cod_usuario = us.cod_usuario
            INNER JOIN rol ro         ON ro.id_rol      = ru.id_rol
            WHERE b.fec_crea_bitacora>=:ini AND b.fec_crea_bitacora<:fin
              AND ro.id_rol NOT IN (1,2) AND b.evento NOT LIKE '%REGISTRADO%'
            ORDER BY b.fec_crea_bitacora
        ", $args, 'cgr') as $r) {
            $rows[] = array($r['login_usu'], $r['fec_crea_bitacora'], $r['evento'],
                            $r['desde'], $r['ip'], self::tipoEvento($r['evento']));
        }

        $errs = array();
        foreach (RawConnection::select("
            SELECT login_usu, fec_crea_bitacora, evento, desde, ip FROM bitacora
            WHERE fec_crea_bitacora>=:ini AND fec_crea_bitacora<:fin AND evento LIKE '%FALLIDO%'
            ORDER BY fec_crea_bitacora
        ", $args, 'cgr') as $r) {
            $errs[] = array($r['login_usu'], $r['fec_crea_bitacora'], $r['evento'], $r['desde'], $r['ip']);
        }

        return array(
            array(
                'name'    => 'Otros Perfiles',
                'columns' => array(
                    self::col('USUARIO','text',16), self::col('FECHA/HORA','datetime',20),
                    self::col('EVENTO','text',60), self::col('DESDE','text',16),
                    self::col('IP','text',40), self::col('TIPO_EVENTO','text',44),
                ),
                'rows' => $rows,
            ),
            array(
                'name'    => 'Intentos Errados',
                'columns' => array(
                    self::col('login_usu','text',16), self::col('fec_crea_bitacora','datetime',20),
                    self::col('evento','text',60), self::col('desde','text',16), self::col('ip','text',40),
                ),
                'rows' => $errs,
            ),
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       04 — LOG DETALLADO (bitácora interna)
       ═══════════════════════════════════════════════════════════════ */
    private function d04(array $p): array
    {
        $rows = array();
        foreach (RawConnection::select("
            SELECT b.id, u.login_usu, b.fecha, b.old AS valor_anterior, b.new AS valor_nuevo,
                   b.tabla, b.accion, b.ip_remoto
            FROM bitacora_interna b
            INNER JOIN usuario u ON b.cod_usuario = u.cod_usuario
            WHERE b.fecha>=:ini AND b.fecha<:fin
            ORDER BY b.id
        ", array(':ini' => $p['ini'], ':fin' => $p['fin']), 'cgr') as $r) {
            $rows[] = array((int)$r['id'], $r['login_usu'], $r['fecha'], $r['valor_anterior'],
                            $r['valor_nuevo'], $r['tabla'], $r['accion'], $r['ip_remoto']);
        }
        return array(array(
            'name'    => 'DETALLADO',
            'columns' => array(
                self::col('id','int',9), self::col('login_usu','text',16),
                self::col('fecha','datetime',20), self::col('valor_anterior','wrap',44),
                self::col('valor_nuevo','wrap',44), self::col('tabla','text',26),
                self::col('accion','text',24), self::col('IP_REMOTO','text',40),
            ),
            'rows' => $rows,
        ));
    }

    /* ═══════════════════════════════════════════════════════════════
       05 — PISTAS CONTABILIDAD (fs_logs)
       ═══════════════════════════════════════════════════════════════ */
    private function d05(array $p): array
    {
        $rows = array();
        foreach (RawConnection::select("
            SELECT usuario, fecha, detalle, ip FROM fs_logs
            WHERE fecha>=:ini AND fecha<:fin AND usuario IS NOT NULL
            ORDER BY fecha ASC
        ", array(':ini' => $p['ini'], ':fin' => $p['fin']), 'conta') as $r) {
            $rows[] = array($r['usuario'], $r['fecha'], self::limpiaDetalle($r['detalle']), $r['ip']);
        }
        return array(array(
            'name'    => 'Pistas Conta',
            'columns' => array(
                self::col('usuario','text',16), self::col('fecha','datetime',20),
                self::col('detalle','wrap',90), self::col('ip','text',40),
            ),
            'rows' => $rows,
        ));
    }

    /* ═══════════════════════════════════════════════════════════════
       06 — LOGS DE SESIÓN CONTABILIDAD (varias hojas)
       ═══════════════════════════════════════════════════════════════ */
    private function d06(array $p): array
    {
        $args   = array(':ini' => $p['ini'], ':fin' => $p['fin']);
        $sheets = array();

        /* Mensajes */
        $leyenda = array(
            array('a','Descuadre importe 0,01 positivo'),
            array('b','Descuadre importe -0,01 negativo'),
            array('c','Asiento descuadrado Positivo > 0,01'),
            array('d','Asiento descuadrado negativo > -0,01'),
            array('e','Importe del asiento incorrecto'),
            array('f','El ejercicio esta descuadrado a nivel de'),
            array('g','Asiento no encontrado'),
            array('h','Asiento eliminado'),
            array('i','Este asiento es un posible duplicado de'),
            array('j','Fecha no válida'),
            array('k','La fecha 31-12-2024 está fuera del rango'),
            array('m','Se ha cambiado la contraseña'),
            array('n','Login correcto'),
            array('o','Las contraseñas no coinciden'),
            array('p','Varios'),
            array('x','Petición duplicada. Has hecho doble clic'),
        );
        $sheets[] = array(
            'name'    => 'Mensajes',
            'columns' => array(self::col('codigo','text',10), self::col('descripcion','text',46)),
            'rows'    => $leyenda,
        );

        /* Resumen */
        $rowsRes = array();
        foreach (RawConnection::select("
            SELECT usuario, COUNT(*) AS n FROM fs_logs
            WHERE fecha>=:ini AND fecha<:fin AND usuario IS NOT NULL
            GROUP BY usuario ORDER BY n DESC
        ", $args, 'conta') as $r) {
            $rowsRes[] = array($r['usuario'], (int)$r['n']);
        }
        $sheets[] = array(
            'name'    => 'Resumen',
            'columns' => array(self::col('Usuario','text',20), self::col('Nro Transacciones','int',20)),
            'rows'    => $rowsRes,
        );

        /* Res X TE */
        $cats = "
            SUM(detalle LIKE '%descuadrado%' AND detalle LIKE '%-0,01%') c2,
            SUM(detalle LIKE '%descuadrado%' AND detalle LIKE '%0,01%' AND detalle NOT LIKE '%-0,01%') c3,
            SUM(detalle LIKE '%Asiento eliminado%') c4,
            SUM(detalle LIKE '%Asiento no encontrado%') c5,
            SUM(detalle LIKE '%Descuadre%' AND detalle LIKE '%-0,01%') c6,
            SUM(detalle LIKE '%Descuadre%' AND detalle LIKE '%0,01%' AND detalle NOT LIKE '%-0,01%') c7,
            SUM(detalle LIKE '%descuadrado a nivel de%') c8,
            SUM(detalle LIKE '%posible duplicado de%') c9,
            SUM(detalle LIKE '%Fecha no v%lida%') c10,
            SUM(detalle LIKE '%Importe del asiento incorrecto%') c11,
            SUM(detalle LIKE '%fuera del rango%') c12,
            SUM(detalle LIKE '%contrase%as no coinciden%') c13,
            SUM(detalle LIKE '%Login correcto%') c14,
            SUM(detalle LIKE '%Petici%n duplicada%') c15,
            SUM(detalle LIKE '%cambiado la contrase%a%') c16,
            SUM(detalle LIKE '%Varios%') c17";
        $sqlTE = "SELECT usuario, $cats, COUNT(*) total FROM fs_logs
                  WHERE fecha>=:ini AND fecha<:fin
                    AND detalle NOT LIKE '%Ningún ejercicio encontrado%'
                    AND detalle NOT LIKE '%Imposible guardar el asiento%'
                    AND detalle NOT LIKE '%Concepto del asiento demasiado largo%'
                    AND detalle NOT LIKE '%Imposible desbloquear el asiento%'
                    AND usuario IS NOT NULL
                  GROUP BY usuario ORDER BY usuario";
        $colsTE = array('c2','c3','c4','c5','c6','c7','c8','c9','c10','c11','c12','c13','c14','c15','c16','c17','total');
        $rowsTE = array();
        $tot = array_fill(0, 17, 0);
        foreach (RawConnection::select($sqlTE, $args, 'conta') as $r) {
            $row = array($r['usuario']);
            $i = 0;
            foreach ($colsTE as $ck) { $row[] = (int)$r[$ck]; $tot[$i] += (int)$r[$ck]; $i++; }
            $rowsTE[] = $row;
        }
        $rowsTE[] = array_merge(array('Total general'), $tot);
        $sheets[] = array(
            'name'    => 'Res X TE',
            'columns' => array(
                self::col('Usuarios','text',14),
                self::col('Asiento descuadrado negativo > -0,01','int',14),
                self::col('Asiento descuadrado Positivo > 0,01','int',14),
                self::col('Asiento eliminado','int',12), self::col('Asiento no encontrado','int',12),
                self::col('Descuadre importe = -0,01 negativo','int',14),
                self::col('Descuadre importe = 0,01 positivo','int',14),
                self::col('El ejercicio esta descuadrado a nivel de','int',14),
                self::col('Este asiento es un posible duplicado de','int',14),
                self::col('Fecha no valida','int',12), self::col('Importe del asiento incorrecto','int',14),
                self::col('La fecha 31-12-2024 fuera del rango','int',14),
                self::col('Las contraseñas no coinciden','int',14), self::col('Login correcto','int',14),
                self::col('Petición duplicada. Has hecho doble clic','int',12),
                self::col('Se ha cambiado la contraseña','int',14), self::col('Varios','int',10),
                self::col('Total general','int',12),
            ),
            'rows' => $rowsTE,
        );

        /* Sesion Conta */
        $rowsSes = array();
        foreach (RawConnection::select("
            SELECT alerta, controlador, detalle, fecha, id, ip, tipo, usuario FROM fs_logs
            WHERE fecha>=:ini AND fecha<:fin AND detalle LIKE '%Log%' AND usuario IS NOT NULL
            ORDER BY fecha
        ", $args, 'conta') as $r) {
            $rowsSes[] = array((int)$r['alerta'], $r['controlador'], self::limpiaDetalle($r['detalle']),
                               $r['fecha'], (int)$r['id'], $r['ip'], $r['tipo'], $r['usuario']);
        }
        $sheets[] = array(
            'name'    => 'Sesion Conta',
            'columns' => array(
                self::col('alerta','int',8), self::col('controlador','text',24),
                self::col('detalle','wrap',70), self::col('fecha','datetime',20),
                self::col('id','int',9), self::col('ip','text',40),
                self::col('tipo','text',10), self::col('usuario','text',16),
            ),
            'rows' => $rowsSes,
        );

        /* RES IP */
        $rowsRip = array();
        foreach (RawConnection::select("
            SELECT usuario, COUNT(DISTINCT ip) AS c FROM fs_logs
            WHERE fecha>=:ini AND fecha<:fin AND usuario IS NOT NULL
            GROUP BY usuario WITH ROLLUP
        ", $args, 'conta') as $r) {
            $u = ($r['usuario'] === null) ? 'Total general' : $r['usuario'];
            $rowsRip[] = array($u, (int)$r['c']);
        }
        $sheets[] = array(
            'name'    => 'RES IP',
            'columns' => array(self::col('usuario','text',20), self::col('Cuenta de IP','int',16)),
            'rows'    => $rowsRip,
        );

        /* IP */
        $rowsIp = array();
        foreach (RawConnection::select("
            SELECT usuario, COUNT(DISTINCT ip) AS c,
                    GROUP_CONCAT(DISTINCT ip ORDER BY ip SEPARATOR ', ') AS ips FROM fs_logs
            WHERE fecha>=:ini AND fecha<:fin AND usuario IS NOT NULL
            GROUP BY usuario ORDER BY usuario
        ", $args, 'conta') as $r) {
            $rowsIp[] = array($r['usuario'], (int)$r['c'], $r['ips']);
        }
        $sheets[] = array(
            'name'    => 'IP',
            'columns' => array(
                self::col('USUARIO','text',18), self::col('CANTIDAD','int',12),
                self::col("IP's Registradas",'wrap',90),
            ),
            'rows' => $rowsIp,
        );

        return $sheets;
    }

    /* ═══════════════════════════════════════════════════════════════
       07 — RESUMEN EJECUTIVO
       ═══════════════════════════════════════════════════════════════ */
    private function d07(array $p): array
    {
        $args = array(':ini' => $p['ini'], ':fin' => $p['fin']);

        /* CGR bitácora */
        $cgr = RawConnection::selectOne("
            SELECT
               COUNT(*)                              AS total,
               COUNT(DISTINCT NULLIF(login_usu,'')) AS usuarios,
               SUM(evento LIKE '%FALLIDO%')          AS fallidos,
               SUM(evento LIKE '%CAPTCHA%')          AS captcha,
               SUM(evento LIKE '%REGISTRADO%')       AS registrados,
               COUNT(DISTINCT ip)                    AS ips
            FROM bitacora
            WHERE fec_crea_bitacora>=:ini AND fec_crea_bitacora<:fin
        ", $args, 'cgr');

        $adminOp = RawConnection::scalar("
            SELECT COUNT(*) FROM (
               SELECT DISTINCT b.login_usu,b.fec_crea_bitacora,b.evento,b.desde,b.ip
               FROM bitacora b
               INNER JOIN usuario us     ON us.login_usu   = b.login_usu
               INNER JOIN rol_usuario ru ON ru.cod_usuario = us.cod_usuario
               INNER JOIN rol ro         ON ro.id_rol      = ru.id_rol
               WHERE b.fec_crea_bitacora>=:ini AND b.fec_crea_bitacora<:fin
                 AND ro.id_rol IN (1,2)) t
        ", $args, 'cgr');

        $cajerosAct = RawConnection::scalar("
            SELECT COUNT(DISTINCT login_usu) FROM bitacora
            WHERE fec_crea_bitacora>=:ini AND fec_crea_bitacora<:fin
              AND evento LIKE '%REGISTRADO%' AND login_usu <> ''
        ", $args, 'cgr');

        $detCambios = RawConnection::scalar("
            SELECT COUNT(*) FROM bitacora_interna
            WHERE fecha>=:ini AND fecha<:fin
        ", $args, 'cgr');

        /* Operaciones de caja */
        $cntQuery = function ($tabla, $fecha, $facturado) use ($args) {
            $w = "$fecha>=:ini AND $fecha<:fin" . ($facturado ? " AND facturado=1" : "");
            return (int)RawConnection::scalar("SELECT COUNT(*) FROM $tabla WHERE $w", $args, 'cgr');
        };
        $opCambios = $cntQuery('cambio', 'fec_crea_cambio', true);
        $opGiros   = $cntQuery('giro',   'fec_crea_giro',   true);
        $opRemesas = $cntQuery('remesa', 'fec_crea_remesa', true);
        $opPagos   = $cntQuery('pago',   'fec_crea_pago',   false);
        $opTotal   = $opCambios + $opGiros + $opRemesas + $opPagos;

        /* Contabilidad fs_logs */
        $con = RawConnection::selectOne("
            SELECT
               COUNT(*)                                                   AS total,
               COUNT(DISTINCT usuario)                                    AS usuarios,
               SUM(detalle LIKE '%Login correcto%')                       AS logins,
               SUM(detalle LIKE '%descuadrado%' OR detalle LIKE '%Descuadre%') AS descuadres,
               SUM(detalle LIKE '%Fecha no v%lida%')                      AS fechas_inv,
               SUM(detalle LIKE '%Petici%n duplicada%')                   AS duplicadas,
               SUM(detalle LIKE '%cambiado la contrase%a%')               AS pass,
               COUNT(DISTINCT ip)                                         AS ips
            FROM fs_logs
            WHERE fecha>=:ini AND fecha<:fin AND usuario IS NOT NULL
        ", $args, 'conta');

        /* Hoja 1: Resumen Ejecutivo */
        $R   = array();
        $add = function ($area, $ind, $val, $com = '') use (&$R) {
            $R[] = array($area, $ind, (int)$val, $com);
        };
        $add('Seguridad y accesos', 'Eventos de bitácora (CGR)',            $cgr['total']);
        $add('Seguridad y accesos', 'Usuarios activos (CGR)',              $cgr['usuarios']);
        $add('Seguridad y accesos', 'Direcciones IP distintas (CGR)',      $cgr['ips']);
        $add('Seguridad y accesos', 'Intentos de autenticación fallidos',  $cgr['fallidos'],
             $cgr['fallidos'] > 0 ? 'Incluye errores de captcha y credenciales' : '');
        $add('Seguridad y accesos', '— de ellos, error de captcha',        $cgr['captcha']);

        $add('Operaciones de caja', 'Operaciones registradas (bitácora)',  $cgr['registrados']);
        $add('Operaciones de caja', 'Cajeros activos',                     $cajerosAct);
        $add('Operaciones de caja', 'Cambios facturados',                  $opCambios);
        $add('Operaciones de caja', 'Giros facturados',                    $opGiros);
        $add('Operaciones de caja', 'Remesas facturadas',                  $opRemesas);
        $add('Operaciones de caja', 'Pagos',                               $opPagos);
        $add('Operaciones de caja', 'Total de operaciones',                $opTotal);

        $add('Administración',      'Eventos Administrador / Operador',     $adminOp,
             'Perfiles con rol 1 y 2');
        $add('Administración',      'Cambios en bitácora interna (antes/después)', $detCambios);

        $add('Contabilidad',        'Transacciones (fs_logs)',             $con['total']);
        $add('Contabilidad',        'Usuarios activos (contabilidad)',     $con['usuarios']);
        $add('Contabilidad',        'IP distintas (contabilidad)',         $con['ips']);
        $add('Contabilidad',        'Inicios de sesión correctos',         $con['logins']);
        $add('Contabilidad',        'Asientos descuadrados detectados',    $con['descuadres'],
             $con['descuadres'] > 0 ? 'Revisar hoja Res X TE del reporte 06' : '');
        $add('Contabilidad',        'Fechas no válidas',                   $con['fechas_inv']);
        $add('Contabilidad',        'Peticiones duplicadas (doble clic)',  $con['duplicadas']);
        $add('Contabilidad',        'Cambios de contraseña',               $con['pass']);

        $sheetResumen = array(
            'name'    => 'Resumen Ejecutivo',
            'titles'  => array(
                'RESUMEN EJECUTIVO DE AUDITORÍA — ' . $p['label'],
                'Generado el ' . date('d/m/Y H:i') . ' · Bases CGR + Contabilidad · Solo lectura',
            ),
            'blank'   => true,
            'columns' => array(
                self::col('Área','text',24), self::col('Indicador','text',46),
                self::col('Valor','int',14), self::col('Comentario','text',44),
            ),
            'rows' => $R,
        );

        /* Hoja 2: Top usuarios */
        $top = array();
        foreach (RawConnection::select("
            SELECT usuario, COUNT(*) AS n FROM fs_logs
            WHERE fecha>=:ini AND fecha<:fin AND usuario IS NOT NULL
            GROUP BY usuario ORDER BY n DESC LIMIT 10
        ", $args, 'conta') as $r) {
            $top[] = array('Contabilidad (transacciones)', $r['usuario'], (int)$r['n']);
        }
        foreach (RawConnection::select("
            SELECT u.login_usu AS usuario,
                    COALESCE(c.cant,0)+COALESCE(g.cant,0)+COALESCE(r.cant,0)+COALESCE(pp.cant,0) AS total
             FROM usuario u
             LEFT JOIN (SELECT cod_usuario,COUNT(*) cant FROM cambio
                        WHERE facturado=1 AND fec_crea_cambio>=:ini AND fec_crea_cambio<:fin
                        GROUP BY cod_usuario) c  ON c.cod_usuario  = u.cod_usuario
             LEFT JOIN (SELECT cod_usuario,COUNT(*) cant FROM giro
                        WHERE facturado=1 AND fec_crea_giro>=:ini AND fec_crea_giro<:fin
                        GROUP BY cod_usuario) g  ON g.cod_usuario  = u.cod_usuario
             LEFT JOIN (SELECT cod_usuario,COUNT(*) cant FROM remesa
                        WHERE facturado=1 AND fec_crea_remesa>=:ini AND fec_crea_remesa<:fin
                        GROUP BY cod_usuario) r  ON r.cod_usuario  = u.cod_usuario
             LEFT JOIN (SELECT cod_usuario,COUNT(*) cant FROM pago
                        WHERE fec_crea_pago>=:ini AND fec_crea_pago<:fin
                        GROUP BY cod_usuario) pp ON pp.cod_usuario = u.cod_usuario
             HAVING total > 0 ORDER BY total DESC LIMIT 10
        ", $args, 'cgr') as $r) {
            $top[] = array('Caja (operaciones)', $r['usuario'], (int)$r['total']);
        }
        $sheetTop = array(
            'name'    => 'Top Usuarios',
            'columns' => array(
                self::col('Ámbito','text',30), self::col('Usuario','text',20),
                self::col('Cantidad','int',14),
            ),
            'rows' => $top,
        );

        /* Hoja 3: Alertas de seguridad */
        $al = array();
        foreach (RawConnection::select("
            SELECT login_usu, COUNT(*) AS n FROM bitacora
            WHERE fec_crea_bitacora>=:ini AND fec_crea_bitacora<:fin
              AND evento LIKE '%FALLIDO%' AND login_usu <> ''
            GROUP BY login_usu HAVING n >= 3 ORDER BY n DESC LIMIT 15
        ", $args, 'cgr') as $r) {
            $al[] = array('Intentos fallidos reiterados', $r['login_usu'], (int)$r['n'],
                          'Usuario con 3+ intentos fallidos en el mes');
        }
        foreach (RawConnection::select("
            SELECT usuario, COUNT(DISTINCT ip) AS c FROM fs_logs
            WHERE fecha>=:ini AND fecha<:fin AND usuario IS NOT NULL
            GROUP BY usuario HAVING c > 1 ORDER BY c DESC LIMIT 15
        ", $args, 'conta') as $r) {
            $al[] = array('Acceso desde varias IP (contabilidad)', $r['usuario'], (int)$r['c'],
                          'Revisar si corresponde a teletrabajo/movilidad');
        }
        if (!$al) {
            $al[] = array('Sin alertas', '—', 0, 'No se detectaron patrones a resaltar');
        }
        $sheetAlertas = array(
            'name'    => 'Alertas Seguridad',
            'columns' => array(
                self::col('Tipo','text',38), self::col('Usuario','text',20),
                self::col('Valor','int',12), self::col('Comentario','text',46),
            ),
            'rows' => $al,
        );

        return array($sheetResumen, $sheetTop, $sheetAlertas);
    }
}
