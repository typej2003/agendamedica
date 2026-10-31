<?php

namespace App\Referidos;

use App\Latidos\Latidos;
use App\Models\CodigoDeReferido;
use App\Models\ConfiguracionLatidos;
use App\Models\Medico;
use App\Models\MovimientoDeLatidos;
use App\Models\Referido;
use App\Models\SolicitudMedico;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * **Acreditar el premio de un referido** cuando se aprueba el registro (fase 4, 2026-10-31).
 *
 * Reglas:
 *  - se acredita **una sola vez por `reg_medico` referido** (la única de `referidos` lo garantiza);
 *  - no hay autorreferencia por consultorio ni por correo;
 *  - el referente tiene que tener una cuenta activa;
 *  - si el referido **ya tenía un servicio** antes de la aprobación, se considera cliente existente y se
 *    guarda `rechazado`, sin Latidos;
 *  - el tope mensual es configurable (0 = sin tope).
 *
 * La fila `rechazado` también se guarda: deja la traza y evita que el mismo consultorio se intente referir
 * dos veces. Los Latidos se acreditan en la misma transacción: si una de las dos acreditaciones falla, no
 * queda nada a medias.
 */
final class AcreditarReferido
{
    public function __construct(private readonly Latidos $latidos)
    {
    }

    public function ejecutar(
        SolicitudMedico $solicitud,
        string $regMedicoReferido,
        Medico $medico,
        bool $teniaServicioAntes,
    ): ?Referido {
        $config = ConfiguracionLatidos::actual();
        if (! $config->referidosActivos()) {
            return null;
        }

        $referente = trim((string) $solicitud->referente_reg_medico);
        $referido = trim($regMedicoReferido);

        if ($referente === '' || $referido === '') {
            return null;
        }

        $existente = Referido::where('referido_reg_medico', $referido)->first();
        if ($existente) {
            return $existente;
        }

        $codigo = trim((string) $solicitud->codigo_referido);
        $codigoModel = $codigo === '' ? null : CodigoDeReferido::where('codigo', $codigo)->first();
        $base = [
            'codigo_id' => $codigoModel?->id,
            'codigo' => $codigo,
            'referente_reg_medico' => $referente,
            'referido_reg_medico' => $referido,
            'solicitud_id' => $solicitud->id,
            'medico_id' => $medico->id,
        ];

        if ($referente === $referido) {
            return $this->rechazar($base, 'Autorreferencia: el referente y el referido son el mismo consultorio.');
        }

        $referenteMedico = Medico::where('reg_medico', $referente)->first();
        if (! $referenteMedico || ! $referenteMedico->is_active) {
            return $this->rechazar($base, 'El referente no tiene una ficha activa.');
        }
        if (! $referenteMedico->user_id || ! User::whereKey($referenteMedico->user_id)->where('is_active', true)->exists()) {
            return $this->rechazar($base, 'El referente no tiene una cuenta activa.');
        }
        if (mb_strtolower(trim((string) $referenteMedico->email)) === mb_strtolower(trim((string) $solicitud->email))) {
            return $this->rechazar($base, 'El referente y el referido comparten el mismo correo.');
        }
        $mppsReferente = mb_strtolower(trim((string) $referenteMedico->license_number));
        $mppsSolicitud = mb_strtolower(trim((string) $solicitud->mpps));
        if ($mppsReferente !== '' && $mppsReferente === $mppsSolicitud) {
            return $this->rechazar($base, 'El referente y el referido comparten el mismo MPPS.');
        }
        if ($teniaServicioAntes) {
            return $this->rechazar($base, 'El referido ya era cliente: tenía un servicio antes de la aprobación.');
        }

        $tope = (int) $config->tope_mensual_por_referente;
        if ($tope > 0) {
            $delMes = Referido::where('referente_reg_medico', $referente)
                ->where('estado', Referido::ACREDITADO)
                ->whereBetween('acreditado_el', [now()->startOfMonth(), now()->endOfMonth()])
                ->count();

            if ($delMes >= $tope) {
                return $this->rechazar($base, 'El referente alcanzó el tope mensual de referidos.');
            }
        }

        return DB::transaction(function () use ($base, $config, $referente, $referido) {
            $fila = Referido::create($base + [
                'estado' => Referido::ACREDITADO,
                'latidos_referente' => (int) $config->latidos_referente,
                'latidos_referido' => (int) $config->latidos_referido,
                'acreditado_el' => now(),
            ]);

            if ((int) $config->latidos_referente > 0) {
                $this->latidos->acreditar(
                    $referente,
                    (int) $config->latidos_referente,
                    MovimientoDeLatidos::REFERIDO,
                    'referido:' . $fila->id . ':referente',
                    [
                        'referido_id' => $fila->id,
                        'concepto' => 'Latidos por referir a un colega',
                        'nota' => 'Referido #' . $fila->id,
                    ],
                );
            }

            if ((int) $config->latidos_referido > 0) {
                $this->latidos->acreditar(
                    $referido,
                    (int) $config->latidos_referido,
                    MovimientoDeLatidos::REFERIDO,
                    'referido:' . $fila->id . ':referido',
                    [
                        'referido_id' => $fila->id,
                        'concepto' => 'Latidos por haber llegado con un colega',
                        'nota' => 'Referido #' . $fila->id,
                    ],
                );
            }

            return $fila->refresh();
        });
    }

    /** @param array<string,mixed> $base */
    private function rechazar(array $base, string $motivo): Referido
    {
        return Referido::create($base + [
            'estado' => Referido::RECHAZADO,
            'latidos_referente' => 0,
            'latidos_referido' => 0,
            'motivo' => $motivo,
        ]);
    }
}
