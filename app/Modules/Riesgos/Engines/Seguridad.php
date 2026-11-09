<?php

namespace App\Modules\Riesgos\Engines;

use App\Modules\Shared\Database\RawConnection;

class Seguridad
{
    const DIAS_CLAVE = 91;
    const FALLIDOS_WARN = 10;
    const LIMITE_LISTA = 40;

    public static function paresIncompatibles()
    {
        return array(
            array(10, 6), array(10, 1), array(10, 4), array(10, 7), array(10, 8), array(10, 12),
            array(10, 5), array(10, 13),
            array(5, 6),  array(5, 1),
            array(6, 12), array(6, 4),
            array(1, 3),  array(1, 12),
        );
    }

    private $ini, $fin, $iniDt, $finDt;

    public function __construct($ini, $fin)
    {
        $this->ini = $ini; $this->fin = $fin;
        $this->iniDt = $ini . ' 00:00:00';
        $this->finDt = $fin . ' 23:59:59';
    }

    private function r() { return array(':ini' => $this->iniDt, ':fin' => $this->finDt); }

    public function trazaCobertura()
    {
        $bit = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin", $this->r(), 'cgr');
        $ops = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE 'SE HA REGISTRADO%'", $this->r(), 'cgr');
        $camb = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM reporte_cambios_auditoria WHERE fec_crea_cambio BETWEEN :ini AND :fin", $this->r(), 'cgr');
        $mods = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM co_partidas_audit WHERE fecha_modificacion BETWEEN :ini AND :fin", $this->r(), 'conta');
        return array('eventos' => $bit, 'operaciones_logueadas' => $ops, 'cambios_auditados' => $camb, 'modificaciones' => $mods);
    }

    public function trazaOperaciones()
    {
        return RawConnection::select(
            "SELECT login_usu, evento, fec_crea_bitacora fecha, ip
             FROM bitacora
             WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE 'SE HA REGISTRADO%'
             ORDER BY fec_crea_bitacora DESC LIMIT " . self::LIMITE_LISTA,
            $this->r(), 'cgr'
        );
    }

    public function actividadUsuarios()
    {
        return RawConnection::select(
            "SELECT login_usu,
                    COUNT(*) eventos,
                    SUM(evento LIKE 'INICIO DE SESIÓN%') sesiones,
                    SUM(evento LIKE 'SE HA REGISTRADO%') operaciones,
                    MAX(fec_crea_bitacora) ultimo
             FROM bitacora
             WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND login_usu <> ''
             GROUP BY login_usu ORDER BY eventos DESC LIMIT " . self::LIMITE_LISTA,
            $this->r(), 'cgr'
        );
    }

    public function modificaciones()
    {
        return RawConnection::select(
            "SELECT idpartida, codsubcuenta, concepto, debe, haber,
                    cod_usuario, usuario_modificacion, fecha_modificacion
             FROM co_partidas_audit
             WHERE fecha_modificacion BETWEEN :ini AND :fin
             ORDER BY fecha_modificacion DESC LIMIT " . self::LIMITE_LISTA,
            $this->r(), 'conta'
        );
    }

    public function accesos()
    {
        $exitosos = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE 'INICIO DE SESIÓN%'", $this->r(), 'cgr');
        $fallidos = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE '%FALLIDO BAJO CREDENCIALES%'", $this->r(), 'cgr');
        $tecnicos = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE '%FALLID%' AND evento NOT LIKE '%BAJO CREDENCIALES%'", $this->r(), 'cgr');
        $ips = (int) RawConnection::scalar(
            "SELECT COUNT(DISTINCT ip) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin", $this->r(), 'cgr');
        $fuera = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND HOUR(fec_crea_bitacora) BETWEEN 0 AND 5", $this->r(), 'cgr');
        $porUsuario = RawConnection::select(
            "SELECT TRIM(SUBSTRING_INDEX(evento, ':', -1)) usuario, COUNT(*) intentos, MAX(fec_crea_bitacora) ultimo
             FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE '%FALLIDO BAJO CREDENCIALES%'
             GROUP BY usuario ORDER BY intentos DESC LIMIT " . self::LIMITE_LISTA,
            $this->r(), 'cgr'
        );
        return array('exitosos' => $exitosos, 'fallidos' => $fallidos, 'tecnicos' => $tecnicos, 'ips' => $ips,
                     'fuera_horario' => $fuera, 'por_usuario' => $porUsuario);
    }

    public function claves()
    {
        $lista = RawConnection::select(
            "SELECT login_usu, fec_ult_cambio_clave,
                    DATEDIFF(:fin, fec_ult_cambio_clave) dias
             FROM usuario
             WHERE estado = 'A'
               AND (fec_ult_cambio_clave IS NULL OR fec_ult_cambio_clave < DATE_SUB(:fin2, INTERVAL " . self::DIAS_CLAVE . " DAY))
             ORDER BY fec_ult_cambio_clave ASC LIMIT " . self::LIMITE_LISTA,
            array(':fin' => $this->fin, ':fin2' => $this->fin), 'cgr'
        );
        return array('n' => count($lista), 'lista' => $lista);
    }

    public function cuentas()
    {
        $rows = RawConnection::select(
            "SELECT estado, COUNT(*) n FROM usuario GROUP BY estado",
            array(), 'cgr'
        );
        $act = 0; $ina = 0;
        foreach ($rows as $r) {
            if ($r['estado'] === 'A') $act = (int) $r['n'];
            elseif ($r['estado'] === 'I') $ina = (int) $r['n'];
        }
        return array('activas' => $act, 'inactivas' => $ina);
    }

    public function usuariosPorRol()
    {
        return RawConnection::select(
            "SELECT r.descripcion rol, COUNT(*) n
             FROM rol_usuario ru
             JOIN rol r      ON r.id_rol = ru.id_rol
             JOIN usuario u  ON u.cod_usuario = ru.cod_usuario
             WHERE u.estado = 'A' AND ru.est_rol_usu = 'A'
             GROUP BY r.id_rol ORDER BY n DESC",
            array(), 'cgr'
        );
    }

    public function segregacion()
    {
        $multi = RawConnection::select(
            "SELECT ru.cod_usuario, u.login_usu,
                    GROUP_CONCAT(ru.id_rol ORDER BY ru.id_rol) ids,
                    GROUP_CONCAT(r.descripcion SEPARATOR ' + ') roles
             FROM rol_usuario ru
             JOIN rol r     ON r.id_rol = ru.id_rol
             JOIN usuario u ON u.cod_usuario = ru.cod_usuario
             WHERE u.estado = 'A' AND ru.est_rol_usu = 'A'
             GROUP BY ru.cod_usuario HAVING COUNT(*) > 1
             ORDER BY COUNT(*) DESC",
            array(), 'cgr'
        );

        $pares = self::paresIncompatibles();
        $conflictos = array();
        foreach ($multi as $m) {
            $ids = array_map('intval', explode(',', $m['ids']));
            foreach ($pares as $p) {
                if (in_array($p[0], $ids, true) && in_array($p[1], $ids, true)) {
                    $conflictos[] = array('login' => $m['login_usu'], 'roles' => $m['roles']);
                    break;
                }
            }
        }
        return array('multi' => $multi, 'multi_n' => count($multi), 'conflictos' => $conflictos, 'conflictos_n' => count($conflictos));
    }

    public function informe()
    {
        $cob   = $this->trazaCobertura();
        $acc   = $this->accesos();
        $clv   = $this->claves();
        $ctas  = $this->cuentas();
        $seg   = $this->segregacion();

        if ($seg['conflictos_n'] > 0)                                     $sem = 'bad';
        elseif ($acc['fallidos'] >= self::FALLIDOS_WARN || $clv['n'] > 0) $sem = 'warn';
        else                                                              $sem = 'ok';

        return array(
            'periodo'    => array('ini' => $this->ini, 'fin' => $this->fin),
            'traza'      => array(
                'cobertura'      => $cob,
                'operaciones'    => $this->trazaOperaciones(),
                'actividad'      => $this->actividadUsuarios(),
                'modificaciones' => $this->modificaciones(),
            ),
            'accesos'    => $acc,
            'claves'     => $clv,
            'cuentas'    => $ctas,
            'usuarios_por_rol' => $this->usuariosPorRol(),
            'segregacion'=> $seg,
            'dias_clave' => self::DIAS_CLAVE,
            'semaforo'   => $sem,
        );
    }
}
