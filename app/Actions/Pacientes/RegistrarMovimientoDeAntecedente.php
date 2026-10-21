<?php

namespace App\Actions\Pacientes;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Models\AntecedenteMovimiento;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

/**
 * **Anota un movimiento de los antecedentes del paciente** en `antecedente_movimientos` (decisión del
 * usuario, 2026-10-20): el registro con el que el médico demuestra qué se sabía en cada consulta.
 *
 * Es **la única puerta** de esa tabla y la llaman todos los caminos que escriben `antece_paciente`:
 *
 *  - la web: `GuardarAntecedentes` (con la consulta desde la que se hizo);
 *  - el móvil: `AgregarAntecedenteAlPaciente` (alta) y `SyncAppDataController` (edición y baja por el camino
 *    genérico, con Eloquent);
 *  - el escritorio: `CambiosEscritorio::guardarGenerico()` / `borrarGenerico()` (Query Builder, sin modelo).
 *
 * ⚠️ No es un observer del modelo `AntecePaciente` a propósito: la web y el escritorio escriben con
 * `DB::table`, que no dispara eventos, y un observer daría la falsa idea de que todo pasa por ahí. La carga
 * inicial **no** anota (las filas sin movimientos se leen «sin fecha»).
 *
 * Recibe la fila **cruda** de `antece_paciente` (objeto o arreglo con `reg_medico`, `numhistoria`,
 * `codeantecedente`, `descripcion`, `tipo`, `detalles`, `tipo2`): en el alta y la edición, la fila **tal
 * como quedó**; en la baja, **la que se borró** (leída antes del `DELETE`).
 */
final class RegistrarMovimientoDeAntecedente
{
    /** Las columnas cuyo cambio es un movimiento `editado` (las que el médico ve). */
    private const COLUMNAS_QUE_CUENTAN = ['detalles', 'tipo2', 'descripcion', 'tipo'];

    public static function agregado($fila, string $origen, ?int $consultaId = null, ?CarbonInterface $cuando = null): void
    {
        self::anotar(AntecedenteMovimiento::AGREGADO, $fila, $origen, $consultaId, $cuando);
    }

    public static function quitado($fila, string $origen, ?int $consultaId = null, ?CarbonInterface $cuando = null): void
    {
        self::anotar(AntecedenteMovimiento::QUITADO, $fila, $origen, $consultaId, $cuando);
    }

    /**
     * Anota la edición **sólo si cambió** algo que el médico ve (`detalles`, alerta, nombre o tipo): el
     * escritorio vuelve a subir la fila entera aunque no haya cambiado nada y eso no es un movimiento.
     *
     * @return bool  si se anotó
     */
    public static function editado($antes, $despues, string $origen, ?int $consultaId = null, ?CarbonInterface $cuando = null): bool
    {
        $antes = (array) $antes;
        $despues = (array) $despues;

        foreach (self::COLUMNAS_QUE_CUENTAN as $columna) {
            if (self::comparable($antes[$columna] ?? null) !== self::comparable($despues[$columna] ?? null)) {
                self::anotar(AntecedenteMovimiento::EDITADO, $despues, $origen, $consultaId, $cuando);

                return true;
            }
        }

        return false;
    }

    /** El origen tal como lo nombra `sync_changes` (`mobile`) traducido al del registro (`movil`). */
    public static function origen(string $origen): string
    {
        return match ($origen) {
            'mobile', 'api' => AntecedenteMovimiento::ORIGEN_MOVIL,
            default => $origen,
        };
    }

    private static function anotar(string $operacion, $fila, string $origen, ?int $consultaId, ?CarbonInterface $cuando): void
    {
        $fila = (array) $fila;
        $codigo = trim((string) ($fila['codeantecedente'] ?? ''));

        if ($codigo === '' || ! isset($fila['numhistoria'])) {
            return;
        }

        $detalle = trim((string) ($fila['detalles'] ?? ''));

        AntecedenteMovimiento::create([
            'reg_medico' => (string) ($fila['reg_medico'] ?? ''),
            'numhistoria' => (int) $fila['numhistoria'],
            'codeantecedente' => $codigo,
            'descripcion' => self::texto($fila['descripcion'] ?? null, 50),
            'tipo' => self::texto($fila['tipo'] ?? null, 2),
            'operacion' => $operacion,
            'detalles' => $detalle === '' ? null : $detalle,
            'alerta' => strtoupper(trim((string) ($fila['tipo2'] ?? ''))) === AntecedentesDelPaciente::TIPO2_ALERTA,
            'consulta_id' => $consultaId,
            'origen' => self::origen($origen),
            // El escritorio entra con la clave del sync (sin sesión): queda `null`.
            'user_id' => Auth::id(),
            'ocurrido_en' => $cuando ? Carbon::instance($cuando) : Carbon::now(),
        ]);
    }

    private static function texto($valor, int $largo): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $largo);
    }

    private static function comparable($valor): ?string
    {
        $texto = $valor === null ? null : trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
