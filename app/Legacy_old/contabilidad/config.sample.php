<?php
/**
 * Configuración del módulo de Contabilidad — Arqueos de Caja.
 *
 * Copiar este archivo a config.php y completar las credenciales.
 * config.php está en .gitignore para no versionar las contraseñas.
 */
return array(

    // Zona horaria para las fechas de los reportes
    'timezone' => 'America/La_Paz',

    // Base de datos CGR (arqueos, cajas, bóveda)
    'cgr' => array(
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cgr_db',
        'user' => 'root',
        'pass' => '',
    ),

    // Base de datos Contabilidad (FacturaScripts / co_partidas)
    'conta' => array(
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'conta_db',
        'user' => 'root',
        'pass' => '',
    ),
);
