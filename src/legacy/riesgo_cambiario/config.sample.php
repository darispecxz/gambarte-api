<?php
/**
 * Configuración del módulo de Riesgo Cambiario (INDEPENDIENTE del resto del proyecto).
 *
 * Copiar este archivo a  config.php  y completar las credenciales.
 * config.php está en .gitignore para no versionar las contraseñas.
 */
return array(

    // Zona horaria para las fechas de los reportes
    'timezone' => 'America/La_Paz',

    // Base de datos CGR (back office cambios / cotizaciones)
    'cgr' => array(
        'host' => '193.203.166.212',
        'port' => 3306,
        'name' => 'u752765750_cgr',
        'user' => 'u752765750_cgr',
        'pass' => 'CAMBIAR_ESTA_CLAVE',
    ),

    // Base de datos Contabilidad (FacturaScripts / co_partidas)
    'conta' => array(
        'host' => '193.203.166.212',
        'port' => 3306,
        'name' => 'u752765750_conta',
        'user' => 'u752765750_conta',
        'pass' => 'CAMBIAR_ESTA_CLAVE',
    ),
);
