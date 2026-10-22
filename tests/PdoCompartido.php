<?php

namespace Tests;

use PDO;

/**
 * El PDO de la base SQLite en memoria de la suite (`DB_DATABASE=:memory:`, ver `tests/bootstrap.php`).
 *
 * Una base `:memory:` vive y muere con su conexion PDO, y cada test arma una aplicacion nueva (y con ella una
 * conexion nueva). Por eso el arranque migra y siembra una sola vez, guarda aqui el PDO y
 * `CreatesApplication` se lo entrega a cada aplicacion: asi la base se arma una vez por proceso y cada test
 * sigue envolviendose en su transaccion (`DatabaseTransactions`/`RefreshDatabase`).
 */
final class PdoCompartido
{
    public static ?PDO $pdo = null;
}
