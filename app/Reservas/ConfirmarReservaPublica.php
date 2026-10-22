<?php

namespace App\Reservas;

use App\Clinica\Agenda\AgendamientoDeCitas;
use App\Models\Cola;
use App\Models\Medico;
use App\Models\Paciente;
use App\Paciente\BloqueoDePacientes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * El último paso de la reserva pública: comprobar el código y **crear la cita**.
 *
 * La cita sale por `AgendamientoDeCitas`, la misma regla de la web clínica, con dos opciones que el portal
 * necesita: sin numerar la historia (el número lo asigna el consultorio cuando el paciente llega) y con
 * `estado = confirmada por el paciente`. Antes de escribir se vuelve a comprobar que el horario siga
 * disponible; el candado por médico y fecha evita que dos personas que confirmaron a la vez se lleven el
 * mismo lugar.
 *
 * Si el paciente ya tiene una cita pendiente con ese médico, no se crea otra: se le avisa con la fecha de
 * la que tiene. Es la regla de Alexander, la misma de la agenda y del chatbot.
 */
final class ConfirmarReservaPublica
{
    private const INTENTOS_MAXIMOS = 5;

    public function __construct(
        private ReservaPendiente $pendiente,
        private DisponibilidadPublica $disponibilidad,
        private AgendamientoDeCitas $agendamiento,
    ) {
    }

    /**
     * @return array{ok: bool, mensaje: string, cita?: Cola, opcion?: OpcionDeReserva, paciente?: ?Paciente, pendiente?: ?array}
     *
     * @throws ValidationException
     */
    public function ejecutar(Medico $medico, string $codigo): array
    {
        $datos = $this->pendiente->para($medico);

        if ($datos === null) {
            throw ValidationException::withMessages([
                'codigo' => 'La reserva venció o la sesión cambió. Vuelve a elegir el horario.',
            ]);
        }

        if (now()->timestamp > (int) ($datos['expira'] ?? 0)) {
            $this->pendiente->olvidar();

            throw ValidationException::withMessages([
                'codigo' => 'El código venció. Vuelve a elegir el horario para recibir uno nuevo.',
            ]);
        }

        if ((int) ($datos['intentos'] ?? 0) >= self::INTENTOS_MAXIMOS) {
            $this->pendiente->olvidar();

            throw ValidationException::withMessages([
                'codigo' => 'Demasiados intentos con ese código. Vuelve a elegir el horario.',
            ]);
        }

        if (! Hash::check($codigo, (string) ($datos['codigo_hash'] ?? ''))) {
            $datos['intentos'] = (int) ($datos['intentos'] ?? 0) + 1;
            $this->pendiente->guardar($datos);

            throw ValidationException::withMessages([
                'codigo' => 'El código no es correcto. Revisa el mensaje y vuelve a intentarlo.',
            ]);
        }

        $llave = 'reserva:' . $medico->id . ':' . $datos['fecha'];

        try {
            $resultado = Cache::lock($llave, 10)->block(5, function () use ($medico, $datos) {
                return $this->crear($medico, $datos);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return ['ok' => false, 'mensaje' => 'Justo se está confirmando otra reserva. Intenta de nuevo en unos segundos.'];
        }

        if ($resultado !== null && ($resultado['ok'] || ($resultado['limpiar'] ?? false))) {
            $this->pendiente->olvidar();
        }

        return $resultado;
    }

    /** @param array<string,mixed> $datos */
    private function crear(Medico $medico, array $datos): array
    {
        // Puede haberse bloqueado entre el código y la confirmación.
        if (app(BloqueoDePacientes::class)->cuentaBloqueada($datos['cedula'] ?? null) !== null) {
            return ['ok' => false, 'limpiar' => true, 'mensaje' => BloqueoDePacientes::AVISO];
        }

        $opcion = $this->disponibilidad->opcionEn($medico, (string) $datos['fecha'], (string) $datos['hora'], (int) $datos['office_id']);

        if ($opcion === null) {
            return [
                'ok' => false,
                'mensaje' => 'Ese horario se acaba de ocupar. Vuelve a elegir uno de los que se muestran.',
            ];
        }

        $regMedico = $medico->regMedicoPrincipal();

        if ($regMedico === null) {
            return ['ok' => false, 'limpiar' => true, 'mensaje' => 'Ese médico todavía no tiene una agenda publicada.'];
        }

        $registros = $medico->registros()->pluck('reg_medico')
            ->push($medico->reg_medico)
            ->push($regMedico)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $resultado = $this->agendamiento->agendar(
            ['reg_medico' => $regMedico, 'medico' => $medico],
            [
                'sede' => $opcion->officeId,
                'fecha' => $opcion->fecha,
                'hora' => $opcion->hora,
                'nuevo_cedula' => $datos['cedula'],
                'nuevo_nombres' => $datos['nombres'],
                'nuevo_apellidos' => $datos['apellidos'],
                'nuevo_telefono' => $datos['telefono'],
                'nuevo_email' => $datos['email'] ?: null,
            ],
            $registros,
            [
                'numerar_historia' => false,
                'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            ],
        );

        if ($resultado->esPregunta()) {
            $pendiente = $resultado->pendiente;

            return [
                'ok' => false,
                'limpiar' => true,
                'mensaje' => 'Ya tienes una cita pendiente con este médico'
                    . ($pendiente !== null ? ' para el ' . $this->fechaEnPalabras((string) $pendiente['cita']->fecha) : '')
                    . '. Si necesitas cambiarla, comunícate con el consultorio.',
                'pendiente' => $pendiente,
            ];
        }

        $cita = $resultado->cita;

        if ($cita === null) {
            return ['ok' => false, 'mensaje' => 'No se pudo crear la cita. Intenta de nuevo.'];
        }

        $paciente = $resultado->objetivo['paciente'] ?? null;

        if ($paciente === null && $cita->paciente_sinhistoria_id !== null) {
            $paciente = Paciente::find($cita->paciente_sinhistoria_id);
        }

        return [
            'ok' => true,
            'mensaje' => 'Cita reservada.',
            'cita' => $cita,
            'opcion' => $opcion,
            'paciente' => $paciente,
            // Para ofrecer la cuenta de paciente en el comprobante, con los datos ya escritos.
            'cedula' => $datos['cedula'],
            'telefono' => $datos['telefono'],
        ];
    }

    private function fechaEnPalabras(string $fecha): string
    {
        return \Carbon\Carbon::parse($fecha)->locale('es')->isoFormat('dddd D [de] MMMM');
    }
}
