<?php

namespace App\Actions\Vacunas;

use App\Models\VacunaPaciente;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lo que comparten las tres Actions de una dosis de vacuna del niño (WEB-7.4.4b y O-68/O-69): **registrar** una aplicada
 * (`RegistrarDosisDeVacuna`), **programar** una que todavía no se aplicó (`ProgramarDosisDeVacuna`) y **quitarla** de la
 * lista (`QuitarDosisDeVacuna`).
 *
 * Está escrito una vez y no tres porque las reglas son las mismas y si se separan se corrigen en un lado y no en el otro:
 *
 *  - **La vacuna tiene que ser del catálogo del médico** (`vacunas` con su `reg_medico`): una desconocida se rechaza, y la
 *    descripción, la prioridad y el tipo se copian de ahí (el escritorio también las copia: `w_escoger_razon_vacuna_consu.srw:1222-1230`).
 *  - **«La misma dosis»** se reconoce por `dosis_numero` y, si falta, por el rótulo escrito en `dosis` sin distinguir
 *    mayúsculas — las dos formas conviven en las filas del legado.
 *  - Las fechas se leen como `AAAA-MM-DD` y un valor ilegible es un error con mensaje humano, no una excepción técnica.
 *
 * No escribe nada: sólo valida y normaliza. Escribir (y el rastro en `sync_changes`) lo hace `EscribeConRastro`.
 */
trait DosisDeVacuna
{
    /** La vacuna del catálogo del médico o un error: una vacuna de otro consultorio no se puede usar. */
    private static function vacunaDelCatalogo(string $regMedico, string $codigo): object
    {
        $vacuna = DB::table('vacunas')->where('reg_medico', $regMedico)->where('codi_vacuna', $codigo)->orderBy('id')->first();

        if ($vacuna === null) {
            throw new InvalidArgumentException('Esa vacuna no está en el catálogo de este consultorio.');
        }

        return $vacuna;
    }

    /**
     * Las tres columnas que la fila copia del catálogo (el escritorio las copia al agregar: `w_escoger_razon_vacuna_consu.srw:1219-1230`).
     *
     * @return array{descripcion:?string, prioridad:?string, tipo_vacu:?string}
     */
    private static function copiadoDelCatalogo(object $vacuna): array
    {
        return [
            'descripcion' => self::texto($vacuna->vacuna ?? null, 100),
            'prioridad' => self::texto($vacuna->prioridad ?? null, 1),
            'tipo_vacu' => self::texto($vacuna->tipo_vacu ?? null, 10),
        ];
    }

    /** ¿La fila es de esa dosis? Por su número y, si alguno falta, por su rótulo (sin distinguir mayúsculas). */
    private static function esLaMisma(VacunaPaciente $fila, ?int $numero, ?string $rotulo): bool
    {
        if ($numero !== null && $fila->dosis_numero !== null) {
            return (int) $fila->dosis_numero === $numero;
        }

        $propio = mb_strtolower(trim((string) $fila->dosis));

        return $rotulo !== null && $propio !== '' && $propio === mb_strtolower($rotulo);
    }

    private static function fecha(mixed $valor, string $que): ?Carbon
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("{$que} no es una fecha válida.");
        }
    }

    private static function texto(mixed $valor, int $tope): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $tope);
    }
}
