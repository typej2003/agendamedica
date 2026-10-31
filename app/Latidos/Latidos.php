<?php

namespace App\Latidos;

use App\Models\Latido;
use App\Models\MovimientoDeLatidos;
use App\Models\Pago;
use App\Models\RetencionDeLatidos;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * **El motor de Latidos** (fase 1, 2026-10-31).
 *
 * Los Latidos son una unidad de descuento promocional, no dinero. Este servicio es el único que escribe
 * `latidos.disponibles` y `movimientos_de_latidos`: cada operación bloquea la fila, deja el movimiento y
 * actualiza la caché en la misma transacción. La verdad sigue siendo la suma del libro; `verificar()`
 * comprueba que la caché no se haya desviado.
 *
 * Tres ideas que hay que respetar desde acá:
 *  - **Retener no descuenta.** Un pago pendiente reserva Latidos (`retenciones_de_latidos`) para que otra
 *    pestaña no los use; el descuento real ocurre al aprobar (`consumir`).
 *  - **Todo es idempotente.** Cada movimiento lleva una `clave_idempotencia` única; repetir el webhook, el
 *    OTP o el comando no duplica nada.
 *  - **Nunca quedan negativos** en una operación normal. Sólo un ajuste manual de Root con
 *    `permitirNegativo` puede dejar un faltante, y queda auditado.
 */
final class Latidos
{
    /** La fila de Latidos del consultorio; la crea si no existe (con la renovación automática encendida). */
    public function para(string $regMedico): Latido
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            throw new RuntimeException('Falta el consultorio del médico.');
        }

        try {
            return Latido::firstOrCreate(
                ['reg_medico' => $regMedico],
                ['disponibles' => 0, 'renovacion_automatica' => true],
            );
        } catch (QueryException $e) {
            // Dos peticiones a la vez intentaron crear la misma fila: la única de `reg_medico` decide.
            return Latido::where('reg_medico', $regMedico)->firstOrFail();
        }
    }

    /** Los Latidos confirmados (sin descontar retenciones). */
    public function disponibles(string $regMedico): int
    {
        return (int) (Latido::where('reg_medico', trim($regMedico))->value('disponibles') ?? 0);
    }

    /** Los que se pueden usar ahora: confirmados menos lo retenido por pagos pendientes. */
    public function disponiblesParaUsar(string $regMedico): int
    {
        $latido = Latido::where('reg_medico', trim($regMedico))->first();
        if (! $latido) {
            return 0;
        }

        // Si por una inconsistencia lo retenido supera lo disponible, para la pantalla son 0, no un negativo.
        return max(0, (int) $latido->disponibles - $this->retenido($latido));
    }

    /**
     * Reserva Latidos para un pago pendiente. No los descuenta. Es idempotente por `pago_id`: si el pago
     * ya tiene una retención activa con el mismo monto, la devuelve.
     */
    public function retener(Pago $pago, int $latidos): RetencionDeLatidos
    {
        if ($latidos <= 0) {
            throw new RuntimeException('La retención de Latidos tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($pago, $latidos) {
            $latido = $this->bloquear((string) $pago->reg_medico);

            $existente = RetencionDeLatidos::where('pago_id', $pago->id)->lockForUpdate()->first();
            if ($existente) {
                if ((int) $existente->latidos !== $latidos) {
                    throw new RuntimeException('Ese pago ya tiene una retención de Latidos con otro monto.');
                }

                return $existente;
            }

            $disponible = (int) $latido->disponibles - $this->retenido($latido);
            if ($disponible < $latidos) {
                throw new RuntimeException(
                    'No hay Latidos suficientes: hay ' . Conversion::formatear(max(0, $disponible))
                    . ' disponibles y se necesitan ' . Conversion::formatear($latidos) . '.',
                );
            }

            return RetencionDeLatidos::create([
                'latido_id' => $latido->id,
                'pago_id' => $pago->id,
                'latidos' => $latidos,
                'estado' => RetencionDeLatidos::ACTIVA,
                'expira_el' => $pago->expira_el,
            ]);
        });
    }

    /**
     * Convierte la retención en descuento real. Si ya se consumió, no hace nada (idempotente). Si la
     * retención no existe o se liberó, falla: el pago no se puede aprobar sin sus Latidos.
     */
    public function consumir(Pago $pago): void
    {
        DB::transaction(function () use ($pago) {
            $retencion = RetencionDeLatidos::where('pago_id', $pago->id)->lockForUpdate()->first();
            if (! $retencion) {
                throw new RuntimeException('El pago no tiene una retención de Latidos.');
            }
            if ($retencion->estado === RetencionDeLatidos::CONSUMIDA) {
                return;
            }
            if ($retencion->estado !== RetencionDeLatidos::ACTIVA) {
                throw new RuntimeException('La retención de Latidos ya se liberó.');
            }

            $latido = Latido::whereKey($retencion->latido_id)->lockForUpdate()->firstOrFail();
            $antes = (int) $latido->disponibles;
            $despues = $antes - (int) $retencion->latidos;
            if ($despues < 0) {
                throw new RuntimeException('Los Latidos no alcanzan para consumir la retención.');
            }

            $this->registrar(
                $latido,
                MovimientoDeLatidos::DESCUENTO_PLAN,
                -(int) $retencion->latidos,
                'plan:' . $pago->id,
                [
                    'pago_id' => $pago->id,
                    'concepto' => 'Descuento en el plan',
                    'nota' => 'Pago #' . $pago->id,
                ],
            );

            $retencion->update(['estado' => RetencionDeLatidos::CONSUMIDA]);
        });
    }

    /** Suelta la retención sin tocar los disponibles (pago rechazado, vencido o cancelado). */
    public function liberar(Pago $pago): void
    {
        DB::transaction(function () use ($pago) {
            $retencion = RetencionDeLatidos::where('pago_id', $pago->id)->lockForUpdate()->first();
            if (! $retencion || $retencion->estado === RetencionDeLatidos::LIBERADA) {
                return;
            }
            if ($retencion->estado === RetencionDeLatidos::CONSUMIDA) {
                throw new RuntimeException('La retención de Latidos ya se consumió.');
            }

            $retencion->update(['estado' => RetencionDeLatidos::LIBERADA]);
        });
    }

    /**
     * Acredita Latidos. `$clave` es la clave de idempotencia (por ejemplo `compra:{pago_id}` o
     * `referido:{id}:referente`): si ya existe un movimiento con esa clave, devuelve ese y no suma otra vez.
     *
     * @param  array{pago_id?:?int, referido_id?:?int, autor_id?:?int, concepto?:?string, nota?:?string}  $extra
     */
    public function acreditar(string $regMedico, int $latidos, string $tipo, string $clave, array $extra = []): MovimientoDeLatidos
    {
        if ($latidos <= 0) {
            throw new RuntimeException('La acreditación de Latidos tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($regMedico, $latidos, $tipo, $clave, $extra) {
            $latido = $this->bloquear($regMedico);

            $existente = MovimientoDeLatidos::where('clave_idempotencia', $clave)->first();
            if ($existente) {
                return $existente;
            }

            return $this->registrar($latido, $tipo, $latidos, $clave, $extra);
        });
    }

    /**
     * Descuenta Latidos sin pasar por una retención (un ajuste operativo). Falla si no alcanzan.
     *
     * @param  array{pago_id?:?int, referido_id?:?int, autor_id?:?int, concepto?:?string, nota?:?string}  $extra
     */
    public function descontar(string $regMedico, int $latidos, string $tipo, string $clave, array $extra = []): MovimientoDeLatidos
    {
        if ($latidos <= 0) {
            throw new RuntimeException('El descuento de Latidos tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($regMedico, $latidos, $tipo, $clave, $extra) {
            $latido = $this->bloquear($regMedico);

            $existente = MovimientoDeLatidos::where('clave_idempotencia', $clave)->first();
            if ($existente) {
                return $existente;
            }

            if ((int) $latido->disponibles < $latidos) {
                throw new RuntimeException(
                    'No hay Latidos suficientes: hay ' . Conversion::formatear(max(0, (int) $latido->disponibles))
                    . ' y se necesitan ' . Conversion::formatear($latidos) . '.',
                );
            }

            return $this->registrar($latido, $tipo, -$latidos, $clave, $extra);
        });
    }

    /**
     * Ajuste manual de Root. `$latidos` puede ser positivo (regala) o negativo (quita). Un negativo que
     * deje el disponible bajo cero exige `$permitirNegativo`.
     */
    public function ajustar(string $regMedico, int $latidos, string $motivo, User $autor, bool $permitirNegativo = false): MovimientoDeLatidos
    {
        if ($latidos === 0) {
            throw new RuntimeException('El ajuste de Latidos no puede ser cero.');
        }

        return DB::transaction(function () use ($regMedico, $latidos, $motivo, $autor, $permitirNegativo) {
            $latido = $this->bloquear($regMedico);
            $despues = (int) $latido->disponibles + $latidos;

            if (! $permitirNegativo && $despues < 0) {
                throw new RuntimeException('El ajuste dejaría los Latidos en negativo. Confírmalo si es intencional.');
            }

            return $this->registrar(
                $latido,
                MovimientoDeLatidos::AJUSTE,
                $latidos,
                'ajuste:' . Str::uuid(),
                [
                    'autor_id' => $autor->id,
                    'concepto' => 'Ajuste de Latidos',
                    'nota' => trim($motivo),
                ],
            );
        });
    }

    /**
     * Revisa una fila: la suma del libro contra la caché, las retenciones huérfanas y los negativos.
     *
     * @return array{ok:bool, reg_medico:string, disponibles:int, suma:int, retenido:int, diferencias:list<string>}
     */
    public function verificar(string $regMedico): array
    {
        $regMedico = trim($regMedico);
        $latido = Latido::where('reg_medico', $regMedico)->first();

        if (! $latido) {
            return [
                'ok' => true, 'reg_medico' => $regMedico, 'disponibles' => 0, 'suma' => 0,
                'retenido' => 0, 'diferencias' => [],
            ];
        }

        $suma = (int) MovimientoDeLatidos::where('latido_id', $latido->id)->sum('latidos');
        $retenido = $this->retenido($latido);
        $diferencias = [];

        if ($suma !== (int) $latido->disponibles) {
            $diferencias[] = 'La caché dice ' . $latido->disponibles . ' y el libro suma ' . $suma . '.';
        }
        if ((int) $latido->disponibles < 0) {
            $diferencias[] = 'Los Latidos disponibles están en negativo (' . $latido->disponibles . ').';
        }
        if ($retenido > (int) $latido->disponibles) {
            $diferencias[] = 'Lo retenido (' . $retenido . ') supera los disponibles (' . $latido->disponibles . ').';
        }

        return [
            'ok' => $diferencias === [],
            'reg_medico' => $regMedico,
            'disponibles' => (int) $latido->disponibles,
            'suma' => $suma,
            'retenido' => $retenido,
            'diferencias' => $diferencias,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Interno                                                             */
    /* ------------------------------------------------------------------ */

    /** Bloquea la fila (la crea si hace falta) dentro de una transacción. */
    private function bloquear(string $regMedico): Latido
    {
        $latido = $this->para($regMedico);

        return Latido::whereKey($latido->id)->lockForUpdate()->firstOrFail();
    }

    /** Lo retenido por retenciones activas de esa fila. */
    private function retenido(Latido $latido): int
    {
        return (int) RetencionDeLatidos::where('latido_id', $latido->id)
            ->where('estado', RetencionDeLatidos::ACTIVA)
            ->sum('latidos');
    }

    /**
     * Actualiza la caché e inserta el movimiento en el mismo paso. El llamador ya bloqueó la fila.
     *
     * @param  array{pago_id?:?int, referido_id?:?int, autor_id?:?int, concepto?:?string, nota?:?string}  $extra
     */
    private function registrar(Latido $latido, string $tipo, int $delta, string $clave, array $extra = []): MovimientoDeLatidos
    {
        $antes = (int) $latido->disponibles;
        $despues = $antes + $delta;

        $latido->update(['disponibles' => $despues]);

        return MovimientoDeLatidos::create([
            'latido_id' => $latido->id,
            'tipo' => $tipo,
            'latidos' => $delta,
            'disponibles_antes' => $antes,
            'disponibles_despues' => $despues,
            'clave_idempotencia' => $clave,
            'pago_id' => $extra['pago_id'] ?? null,
            'referido_id' => $extra['referido_id'] ?? null,
            'autor_id' => $extra['autor_id'] ?? null,
            'concepto' => $extra['concepto'] ?? $this->conceptoPorTipo($tipo),
            'nota' => $extra['nota'] ?? null,
        ]);
    }

    private function conceptoPorTipo(string $tipo): string
    {
        return match ($tipo) {
            MovimientoDeLatidos::COMPRA => 'Compra de Latidos',
            MovimientoDeLatidos::REFERIDO => 'Latidos por referido',
            MovimientoDeLatidos::PROMOCION => 'Promoción de Latidos',
            MovimientoDeLatidos::DESCUENTO_PLAN => 'Descuento en el plan',
            MovimientoDeLatidos::AJUSTE => 'Ajuste de Latidos',
            MovimientoDeLatidos::REVERSO => 'Reverso de Latidos',
            default => 'Movimiento de Latidos',
        };
    }
}
