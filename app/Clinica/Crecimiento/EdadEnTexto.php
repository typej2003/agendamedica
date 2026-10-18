<?php

namespace App\Clinica\Crecimiento;

use Carbon\Carbon;

/**
 * La edad entre dos fechas como la escribe el escritorio de pediatría: «2 años 4 meses 3 días» (WEB-7.4.3). Sin base de datos.
 */
final class EdadEnTexto
{
    /** `null` si falta alguna fecha, no se entiende, o la segunda es anterior al nacimiento. */
    public static function entre(?string $nacimiento, ?string $fecha): ?string
    {
        if (! $nacimiento || ! $fecha) {
            return null;
        }

        try {
            $desde = Carbon::parse($nacimiento)->startOfDay();
            $hasta = Carbon::parse($fecha)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($hasta->lt($desde)) {
            return null;
        }

        $d = $desde->diff($hasta);
        $partes = [];

        if ($d->y > 0) {
            $partes[] = $d->y . ($d->y === 1 ? ' año' : ' años');
        }
        if ($d->m > 0) {
            $partes[] = $d->m . ($d->m === 1 ? ' mes' : ' meses');
        }
        if ($d->d > 0 || $partes === []) {
            $partes[] = $d->d . ($d->d === 1 ? ' día' : ' días');
        }

        return implode(' ', $partes);
    }
}
