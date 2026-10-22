<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Base SQLite en memoria: todas las aplicaciones del proceso comparten el PDO que armo `bootstrap.php`.
        if (PdoCompartido::$pdo !== null) {
            $pdo = PdoCompartido::$pdo;
            $conexion = $app['db']->connection();
            $conexion->setPdo($pdo)->setReadPdo($pdo);
            $conexion->setReconnector(function ($c) use ($pdo) {
                $c->setPdo($pdo)->setReadPdo($pdo);
            });
        }

        return $app;
    }
}
