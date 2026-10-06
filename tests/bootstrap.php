<?php

/**
 * Arranque de la suite (ver `phpunit.xml`).
 *
 * Antes de que corra el primer test se arma la base de tests (`database/database.testing.sqlite`) desde
 * cero y con los seeders. Va aca -y no en `tests/TestCase.php`- porque los tests abren su transaccion
 * dentro de `setUp()`: una migracion hecha ahi adentro la revierte el rollback del teardown y la clase
 * siguiente se queda sin tablas.
 *
 * PHPUnit ya aplico el bloque `<php>` de `phpunit.xml` cuando carga este archivo, asi que DB_DATABASE
 * apunta a la base de tests. Igual se comprueba antes de migrar: si no apunta ahi, no se toca nada.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$conexion = $app['config']->get('database.default');
$ruta = (string) $app['config']->get('database.connections.'.$conexion.'.database');

if (! str_ends_with($ruta, 'database.testing.sqlite')) {
    // Configuracion inesperada (¿editaron el bloque <php> de phpunit.xml?): se avisa fuerte y se evita
    // que `RefreshDatabase` haga `migrate:fresh` sobre una base ajena, que es como se pierden los datos
    // de desarrollo.
    fwrite(STDERR, "ATENCION: los tests no estan apuntando a la base de tests sino a {$ruta}.".PHP_EOL
        .'Revisa el bloque <php> de phpunit.xml: no se migra nada, pero es una base ajena.'.PHP_EOL);

    RefreshDatabaseState::$migrated = true;

    return;
}

// Absoluta: asi la suite no depende del directorio desde donde se la invoque.
if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $ruta) !== 1) {
    $ruta = dirname(__DIR__).DIRECTORY_SEPARATOR.$ruta;
}

$ruta = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta);

if (! is_file($ruta)) {
    // Laravel se niega a conectarse a un sqlite que no existe (`SQLiteConnector::connect`).
    touch($ruta);
}

$_SERVER['DB_DATABASE'] = $ruta;
putenv('DB_DATABASE='.$ruta);

ob_start();
$codigo = $kernel->call('migrate:fresh', ['--seed' => true, '--force' => true]);
$salida = (string) ob_get_clean();

if ($codigo !== 0) {
    throw new RuntimeException("No se pudo armar la base de tests ({$ruta}):\n{$salida}");
}

fwrite(STDOUT, "Base de tests lista: {$ruta}".PHP_EOL);

// Ya quedo migrada y sembrada: que `RefreshDatabase` no la vuelva a tirar a mitad de la corrida, asi
// todos los tests -los de `DatabaseTransactions` y los de `RefreshDatabase`- corren contra lo mismo y
// con rollback.
RefreshDatabaseState::$migrated = true;
