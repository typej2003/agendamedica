<?php

namespace App\Actions\Agenda;

use App\Models\Cola;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;

/**
 * Edición **in-place** de una cita: reagendar es mover la misma fila, no cancelar y crear otra
 * (así conserva lo cobrado y su historial de cambios; wiki `02-modulo-agenda.md` §4).
 *
 * Es la hermana de `ConfirmarCita`/`AtenderCita`/`CobrarCita` para los campos del formulario
 * (fecha, hora, sede, motivo, monto) y la portada del móvil
 * (`AgendaBloc._reagendar`): cuando la cita cambia de jornada va **al final de la nueva**, y lo que
 * valía para la fecha vieja se reinicia — la confirmación y la constancia del recordatorio
 * (`sms_text`), que el paciente ya recibió para otro día. Eso lo decide quien llama, que es el que
 * conoce la jornada; acá se aplica.
 *
 * Cada columna pasa por el **last-write-wins del sync**: si el teléfono la editó más tarde, esta
 * edición no la pisa y la columna no se cuenta entre las aplicadas (misma regla que las otras
 * Actions de agenda).
 *
 * `numhistoria` no se toca: cambiar de paciente es otra acción, con reglas propias todavía sin
 * definir (tampoco está en `SyncAppDataRequest::WRITABLE_COLUMNS['cola']`).
 */
final class EditarCita
{
    /**
     * @param  array<string,mixed>  $cambios  Solo las columnas que se quieren escribir.
     * @return list<string>  Las columnas que se aplicaron de verdad.
     */
    public function ejecutar(Cola $cita, array $cambios, Carbon $cuando, string $origen = 'web'): array
    {
        $aplicadas = [];

        foreach ($cambios as $columna => $valor) {
            if ($this->mismoValor($cita->{$columna}, $valor)) {
                continue;
            }

            if (! RegistroDeCambios::permite('cola', $cita->id, $columna, $cuando)) {
                continue;
            }

            $cita->{$columna} = $valor;
            $aplicadas[] = $columna;
        }

        if ($aplicadas === []) {
            return [];
        }

        $cita->save();

        foreach ($aplicadas as $columna) {
            RegistroDeCambios::registrar('cola', $cita->id, $cita->reg_medico, $columna, $cita->{$columna}, $cuando, $origen);
        }

        return $aplicadas;
    }

    /** Compara como se guarda: `fecha` viene casteada a fecha y los montos como número. */
    private function mismoValor(mixed $actual, mixed $nuevo): bool
    {
        if ($actual instanceof \DateTimeInterface) {
            $actual = $actual->format('Y-m-d');
        }
        if ($nuevo instanceof \DateTimeInterface) {
            $nuevo = $nuevo->format('Y-m-d');
        }

        if (is_numeric($actual) && is_numeric($nuevo)) {
            return (float) $actual === (float) $nuevo;
        }

        return (string) ($actual ?? '') === (string) ($nuevo ?? '');
    }
}
