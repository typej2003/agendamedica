<?php

/**
 * Limpia las cachés compiladas **antes** de que Composer regenere el autoload y corra
 * `package:discover`.
 *
 * Por qué: el despliegue hace `git pull` + `composer install` sobre una copia que puede traer
 * `bootstrap/cache/*.php` y `storage/framework/views/*.php` de la versión anterior. Con un salto
 * de versión mayor esas cachés referencian providers que ya no existen o directivas de Livewire 2
 * (`Livewire::mount(...)->html()`), y el arranque falla antes de que nadie pueda correr
 * `optimize:clear`. Este script corre antes de `package:discover`, **no bootea la aplicación** y
 * sólo borra archivos regenerables (respeta los `.gitignore` de cada carpeta).
 */
$base = dirname(__DIR__);

$borrados = 0;

foreach ([
    $base.'/bootstrap/cache/*.php',
    $base.'/storage/framework/views/*.php',
] as $patron) {
    foreach (glob($patron) ?: [] as $archivo) {
        if (@unlink($archivo)) {
            $borrados++;
        }
    }
}

fwrite(STDOUT, 'Cachés compiladas limpiadas: '.$borrados.PHP_EOL);
