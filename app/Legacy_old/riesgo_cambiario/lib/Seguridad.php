<?php
/**
 * Seguridad.php — Motor del Informe de Seguridad de la Información y Trazabilidad.
 *
 * Cubre tres frentes (con énfasis en la TRAZABILIDAD):
 *   22 · Trazabilidad: bitácora de eventos, traza de operaciones registradas,
 *        actividad por usuario y registro de modificaciones (antes/después).
 *   21 · Seguridad de accesos: intentos fallidos, IPs, accesos fuera de horario,
 *        antigüedad de contraseñas, cuentas activas/inactivas.
 *   28 · Usuarios y segregación de funciones: usuarios por rol y conflictos SoD.
 *
 * Todo es de solo lectura sobre datos existentes (no requiere captura manual).
 */

namespace RiesgoCambiario;

require_once __DIR__ . '/Db.php';

class Seguridad
{
    /** Política de vencimiento de contraseña (días). Ver MAX_TIEMPO_PASSWORD en Config.php. */
    const DIAS_CLAVE = 91;
    /** Umbral de intentos fallidos para observación. */
    const FALLIDOS_WARN = 10;
    const LIMITE_LISTA = 40;

    /**
     * Pares de roles incompatibles (segregación de funciones). PROVISIONAL/configurable.
     * id_rol: 1 OPERADOR, 2 ADMIN, 3 SUPERV.NAC, 4 RESP.AGENCIA, 5 RESP.CUMPLIMIENTO,
     *         6 CAJERO, 7 CONTADOR, 8 AUX.CONTABLE, 10 AUDITOR INTERNO, 11 AUX.CUMPLIMIENTO,
     *         12 SUPERV.AGENCIA, 13 RESP.RIESGO, 14 RESP.GOB.CORP.
     */
    public static function paresIncompatibles()
    {
        return array(
            array(10, 6), array(10, 1), array(10, 4), array(10, 7), array(10, 8), array(10, 12), // Auditor vs operativos/contables
            array(10, 5), array(10, 13),                                                         // Auditor vs Cumplimiento/Riesgo
            array(5, 6),  array(5, 1),                                                            // Cumplimiento vs Cajero/Operador
            array(6, 12), array(6, 4),                                                            // Cajero vs Supervisor/Responsable
            array(1, 3),  array(1, 12),                                                           // Operador vs Supervisores
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

    // ---------------------------------------------------------------- //
    //  22 · TRAZABILIDAD                                                //
    // ---------------------------------------------------------------- //

    /** Cobertura y volumen de la traza en el período. */
    public function trazaCobertura()
    {
        $bit = (int) Db::val('cgr', "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin", $this->r());
        $ops = (int) Db::val('cgr', "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE 'SE HA REGISTRADO%'", $this->r());
        $camb = (int) Db::val('cgr', "SELECT COUNT(*) FROM reporte_cambios_auditoria WHERE fec_crea_cambio BETWEEN :ini AND :fin", $this->r());
        $mods = (int) Db::val('conta', "SELECT COUNT(*) FROM co_partidas_audit WHERE fecha_modificacion BETWEEN :ini AND :fin", $this->r());
        return array('eventos' => $bit, 'operaciones_logueadas' => $ops, 'cambios_auditados' => $camb, 'modificaciones' => $mods);
    }

    /** Traza de operaciones registradas (quién registró qué, cuándo, desde dónde). */
    public function trazaOperaciones()
    {
        return Db::q('cgr',
            "SELECT login_usu, evento, fec_crea_bitacora fecha, ip
             FROM bitacora
             WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE 'SE HA REGISTRADO%'
             ORDER BY fec_crea_bitacora DESC LIMIT " . self::LIMITE_LISTA, $this->r());
    }

    /** Actividad por usuario: eventos, sesiones y operaciones registradas. */
    public function actividadUsuarios()
    {
        return Db::q('cgr',
            "SELECT login_usu,
                    COUNT(*) eventos,
                    SUM(evento LIKE 'INICIO DE SESIÓN%') sesiones,
                    SUM(evento LIKE 'SE HA REGISTRADO%') operaciones,
                    MAX(fec_crea_bitacora) ultimo
             FROM bitacora
             WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND login_usu <> ''
             GROUP BY login_usu ORDER BY eventos DESC LIMIT " . self::LIMITE_LISTA, $this->r());
    }

    /** Registro de modificaciones contables (antes/después) en el período. */
    public function modificaciones()
    {
        return Db::q('conta',
            "SELECT idpartida, codsubcuenta, concepto, debe, haber,
                    cod_usuario, usuario_modificacion, fecha_modificacion
             FROM co_partidas_audit
             WHERE fecha_modificacion BETWEEN :ini AND :fin
             ORDER BY fecha_modificacion DESC LIMIT " . self::LIMITE_LISTA, $this->r());
    }

    // ---------------------------------------------------------------- //
    //  21 · SEGURIDAD DE ACCESOS                                        //
    // ---------------------------------------------------------------- //

    public function accesos()
    {
        // NOTA: en la bitácora, "INTENTO DE AUTENTICACIÓN FALLIDO: ERROR DE CARGA/CAPTCHA"
        // son errores técnicos transitorios que ocurren incluso en logins exitosos.
        // El fallo REAL de credenciales es "FALLIDO BAJO CREDENCIALES".
        $exitosos = (int) Db::val('cgr', "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE 'INICIO DE SESIÓN%'", $this->r());
        $fallidos = (int) Db::val('cgr', "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE '%FALLIDO BAJO CREDENCIALES%'", $this->r());
        $tecnicos = (int) Db::val('cgr', "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE '%FALLID%' AND evento NOT LIKE '%BAJO CREDENCIALES%'", $this->r());
        $ips      = (int) Db::val('cgr', "SELECT COUNT(DISTINCT ip) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin", $this->r());
        $fuera    = (int) Db::val('cgr', "SELECT COUNT(*) FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND HOUR(fec_crea_bitacora) BETWEEN 0 AND 5", $this->r());
        $porUsuario = Db::q('cgr',
            "SELECT TRIM(SUBSTRING_INDEX(evento, ':', -1)) usuario, COUNT(*) intentos, MAX(fec_crea_bitacora) ultimo
             FROM bitacora WHERE fec_crea_bitacora BETWEEN :ini AND :fin AND evento LIKE '%FALLIDO BAJO CREDENCIALES%'
             GROUP BY usuario ORDER BY intentos DESC LIMIT " . self::LIMITE_LISTA, $this->r());
        return array('exitosos' => $exitosos, 'fallidos' => $fallidos, 'tecnicos' => $tecnicos, 'ips' => $ips,
                     'fuera_horario' => $fuera, 'por_usuario' => $porUsuario);
    }

    /** Contraseñas vencidas (usuarios activos con clave más antigua que la política). */
    public function claves()
    {
        $lista = Db::q('cgr',
            "SELECT login_usu, fec_ult_cambio_clave,
                    DATEDIFF(:fin, fec_ult_cambio_clave) dias
             FROM usuario
             WHERE estado = 'A'
               AND (fec_ult_cambio_clave IS NULL OR fec_ult_cambio_clave < DATE_SUB(:fin, INTERVAL " . self::DIAS_CLAVE . " DAY))
             ORDER BY fec_ult_cambio_clave ASC LIMIT " . self::LIMITE_LISTA,
            array(':fin' => $this->fin));
        return array('n' => count($lista), 'lista' => $lista);
    }

    public function cuentas()
    {
        $rows = Db::q('cgr', "SELECT estado, COUNT(*) n FROM usuario GROUP BY estado");
        $act = 0; $ina = 0;
        foreach ($rows as $r) {
            if ($r['estado'] === 'A') $act = (int) $r['n'];
            elseif ($r['estado'] === 'I') $ina = (int) $r['n'];
        }
        return array('activas' => $act, 'inactivas' => $ina);
    }

    // ---------------------------------------------------------------- //
    //  28 · USUARIOS Y SEGREGACIÓN DE FUNCIONES                         //
    // ---------------------------------------------------------------- //

    public function usuariosPorRol()
    {
        return Db::q('cgr',
            "SELECT r.descripcion rol, COUNT(*) n
             FROM rol_usuario ru
             JOIN rol r      ON r.id_rol = ru.id_rol
             JOIN usuario u  ON u.cod_usuario = ru.cod_usuario
             WHERE u.estado = 'A' AND ru.est_rol_usu = 'A'
             GROUP BY r.id_rol ORDER BY n DESC");
    }

    /** Segregación de funciones: usuarios activos con más de un rol y conflictos. */
    public function segregacion()
    {
        $multi = Db::q('cgr',
            "SELECT ru.cod_usuario, u.login_usu,
                    GROUP_CONCAT(ru.id_rol ORDER BY ru.id_rol) ids,
                    GROUP_CONCAT(r.descripcion SEPARATOR ' + ') roles
             FROM rol_usuario ru
             JOIN rol r     ON r.id_rol = ru.id_rol
             JOIN usuario u ON u.cod_usuario = ru.cod_usuario
             WHERE u.estado = 'A' AND ru.est_rol_usu = 'A'
             GROUP BY ru.cod_usuario HAVING COUNT(*) > 1
             ORDER BY COUNT(*) DESC");

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

    // ---------------------------------------------------------------- //

    public function informe()
    {
        $cob   = $this->trazaCobertura();
        $acc   = $this->accesos();
        $clv   = $this->claves();
        $ctas  = $this->cuentas();
        $seg   = $this->segregacion();

        // Semáforo: conflictos SoD = crítico; fallidos altos o claves vencidas = observación.
        if ($seg['conflictos_n'] > 0)                                   $sem = 'bad';
        elseif ($acc['fallidos'] >= self::FALLIDOS_WARN || $clv['n'] > 0) $sem = 'warn';
        else                                                            $sem = 'ok';

        return array(
            'periodo'    => array('ini' => $this->ini, 'fin' => $this->fin),
            'traza'      => array(
                'cobertura'   => $cob,
                'operaciones' => $this->trazaOperaciones(),
                'actividad'   => $this->actividadUsuarios(),
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
