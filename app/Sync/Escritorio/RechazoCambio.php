<?php

namespace App\Sync\Escritorio;

use RuntimeException;

/**
 * Un cambio del escritorio que no se puede aplicar TODAVÍA (p. ej. una consulta de una historia que
 * aún no subió). No se confirma: el escritorio lo conserva y lo reintenta en la próxima vuelta.
 */
class RechazoCambio extends RuntimeException
{
}
