<?php

/**
 * Arranque de la suite (ver `phpunit.xml` y `phpunit.mariadb.xml`).
 *
 * Antes de que corra el primer test se arma la base de tests desde cero y con los seeders. Va aca -y no en
 * `tests/TestCase.php`- porque los tests abren su transaccion dentro de `setUp()`: una migracion hecha ahi
 * adentro la revierte el rollback del teardown y la clase siguiente se queda sin tablas.
 *
 * Dos motores, un solo arranque:
 *
 *  - **SQLite** (`phpunit.xml`, el de todos los dias): `database/database.testing.sqlite`, rapido y sin
 *    servidor. Ojo: SQLite **no mira el largo de las columnas**, asi que no ve los `1406 Data too long` del
 *    MySQL/MariaDB real (trampa 140).
 *  - **MariaDB/MySQL** (`phpunit.mariadb.xml`): una base cuyo nombre termina en `_testing`
 *    (`medicapi_testing`). El host, el puerto, el usuario y la clave salen del bloque `mysql` del `.env`.
 *
 * PHPUnit ya aplico el bloque `<php>` de su XML cuando carga este archivo. Igual se comprueba antes de
 * migrar: si la base no es la de tests, no se toca nada.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

require __DIR__.'/../vendor/autoload.php';

// En paralelo (`php artisan test --parallel`) cada proceso compila las vistas Blade en su propia carpeta: en
// Windows dos procesos que reescriben la misma vista compilada se pisan ("Permission denied" al leerla).
// La carpeta persiste entre corridas, asi que solo la primera compila.
if (($token = getenv('TEST_TOKEN')) !== false && $token !== '') {
    $vistas = dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'
        .DIRECTORY_SEPARATOR.'views-test-'.$token;
    if (! is_dir($vistas)) {
        mkdir($vistas, 0777, true);
    }
    $_SERVER['VIEW_COMPILED_PATH'] = $_ENV['VIEW_COMPILED_PATH'] = $vistas;
    putenv('VIEW_COMPILED_PATH='.$vistas);
}

$app = require __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$conexion = (string) $app['config']->get('database.default');
$base = (string) $app['config']->get('database.connections.'.$conexion.'.database');
$motivo = null;

if ($conexion === 'sqlite') {
    // La unica base sqlite que esta suite puede vaciar es la de tests, por nombre terminante.
    if ($base !== ':memory:' && ! str_ends_with($base, 'database.testing.sqlite')) {
        $motivo = 'la base sqlite no es database.testing.sqlite sino '.$base;
    }
} elseif ($conexion === 'mysql') {
    // Vale para MySQL y MariaDB (mismo driver). El nombre tiene que terminar en `_testing`: la suite hace
    // `migrate:fresh` y vacia la base entera, asi que nunca se apunta a una base de desarrollo o produccion.
    if (! str_ends_with($base, '_testing')) {
        $motivo = 'la base mysql no termina en _testing sino en '.$base;
    }
} else {
    $motivo = 'la conexion '.$conexion.' no esta soportada por la suite';
}

if ($motivo !== null) {
    // Configuracion inesperada (¿editaron el bloque <php> del XML?): se avisa fuerte y se evita que
    // `RefreshDatabase` haga `migrate:fresh` sobre una base ajena, que es como se pierden los datos.
    fwrite(STDERR, "ATENCION: los tests no estan apuntando a la base de tests: {$motivo}.".PHP_EOL
        .'Revisa el bloque <php> de phpunit.xml / phpunit.mariadb.xml: no se migra nada.'.PHP_EOL);

    RefreshDatabaseState::$migrated = true;

    return;
}

if ($conexion === 'sqlite' && $base !== ':memory:') {
    // Absoluta: asi la suite no depende del directorio desde donde se la invoque.
    if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $base) !== 1) {
        $base = dirname(__DIR__).DIRECTORY_SEPARATOR.$base;
    }

    $base = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base);

    if (! is_file($base)) {
        // Laravel se niega a conectarse a un sqlite que no existe (`SQLiteConnector::connect`).
        touch($base);
    }

    $_SERVER['DB_DATABASE'] = $base;
    putenv('DB_DATABASE='.$base);
}

$donde = $conexion === 'sqlite'
    ? $base
    : $conexion.'://'.$app['config']->get('database.connections.'.$conexion.'.host')
        .':'.$app['config']->get('database.connections.'.$conexion.'.port').'/'.$base;

ob_start();
$codigo = $kernel->call('migrate:fresh', ['--seed' => true, '--force' => true]);
$salida = (string) ob_get_clean();

if ($codigo !== 0) {
    throw new RuntimeException("No se pudo armar la base de tests ({$donde}):\n{$salida}");
}

if ($base === ':memory:') {
    // Memoria: se migro una vez en esta conexion; las aplicaciones de cada test reusan su PDO.
    \Tests\PdoCompartido::$pdo = $app['db']->connection()->getPdo();
}

fwrite(STDOUT, "Base de tests lista: {$donde}".PHP_EOL);

// Ya quedo migrada y sembrada: que `RefreshDatabase` no la vuelva a tirar a mitad de la corrida, asi
// todos los tests -los de `DatabaseTransactions` y los de `RefreshDatabase`- corren contra lo mismo y
// con rollback.
RefreshDatabaseState::$migrated = true;
