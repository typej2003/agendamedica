<?php

namespace App\Referidos;

use App\Latidos\Latidos;
use App\Models\MovimientoDeLatidos;
use App\Models\Referido;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **Anular un referido ya acreditado** (sólo Root o Administrador, con motivo).
 *
 * Devuelve los Latidos a los dos lados con un débito `reverso`. Si alguno ya gastó el premio, **no se
 * fuerza el negativo**: la transacción se revierte y se muestra el error para que se resuelva con un ajuste
 * manual. Después de anulado, el referido no se puede volver a acreditar.
 */
final class AnularReferido
{
    public function __construct(private readonly Latidos $latidos)
    {
    }

    public function ejecutar(Referido $referido, string $motivo, User $autor): Referido
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo de la anulación.']);
        }

        if (! $referido->estaAcreditado()) {
            throw ValidationException::withMessages(['referido' => 'Sólo se puede anular un referido acreditado.']);
        }

        return DB::transaction(function () use ($referido, $motivo, $autor) {
            $referido = Referido::whereKey($referido->id)->lockForUpdate()->firstOrFail();

            if (! $referido->estaAcreditado()) {
                throw ValidationException::withMessages(['referido' => 'Este referido ya fue anulado.']);
            }

            if ((int) $referido->latidos_referente > 0) {
                $this->latidos->descontar(
                    $referido->referente_reg_medico,
                    (int) $referido->latidos_referente,
                    MovimientoDeLatidos::REVERSO,
                    'referido:' . $referido->id . ':anular-referente',
                    [
                        'referido_id' => $referido->id,
                        'autor_id' => $autor->id,
                        'concepto' => 'Reverso por anulación de referido',
                        'nota' => $motivo,
                    ],
                );
            }

            if ((int) $referido->latidos_referido > 0) {
                $this->latidos->descontar(
                    $referido->referido_reg_medico,
                    (int) $referido->latidos_referido,
                    MovimientoDeLatidos::REVERSO,
                    'referido:' . $referido->id . ':anular-referido',
                    [
                        'referido_id' => $referido->id,
                        'autor_id' => $autor->id,
                        'concepto' => 'Reverso por anulación de referido',
                        'nota' => $motivo,
                    ],
                );
            }

            $referido->update([
                'estado' => Referido::ANULADO,
                'anulado_el' => now(),
                'anulado_por' => $autor->id,
                'motivo' => $motivo,
            ]);

            return $referido->refresh();
        });
    }
}
