<?php

namespace App\Reservas;

use App\Models\Medico;
use App\Models\Paciente;
use App\Notificaciones\Telefono;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * El primer paso de la reserva pública: comprobar el horario, verificar que el teléfono sea plausible y
 * **enviar el código**. No escribe nada en la base: la ficha del paciente y la cita nacen recién cuando el
 * código se confirma (`ConfirmarReservaPublica`).
 *
 * Si el paciente ya existía con un teléfono distinto, se corta acá: el código prueba que controla el
 * teléfono, así que dejar que lo cambie convertiría la verificación en un trámite vacío y permitiría
 * reservar con la cédula de otra persona. Si la ficha no tiene teléfono, no hay nada que comparar y se
 * sigue: es el caso de las fichas viejas del legado.
 */
final class SolicitarCodigoDeReserva
{
    private const MINUTOS_DE_VALIDEZ = 10;
    private const SEGUNDOS_ENTRE_ENVIOS = 60;

    public function __construct(
        private ReservaPendiente $pendiente,
        private CodigoDeVerificacion $codigos,
        private DisponibilidadPublica $disponibilidad,
    ) {
    }

    /**
     * @param  array<string,mixed>  $datos  cédula, nombres, apellidos, teléfono, email, fecha, hora, office_id
     * @return array{reenviado: bool, canal: string}
     *
     * @throws ValidationException
     */
    public function ejecutar(Medico $medico, array $datos, bool $forzarReenvio = false): array
    {
        $opcion = $this->disponibilidad->opcionEn($medico, (string) $datos['fecha'], (string) $datos['hora'], (int) $datos['office_id']);

        if ($opcion === null) {
            throw ValidationException::withMessages([
                'fecha' => 'Ese horario ya no está disponible. Elige otro de los que se muestran.',
            ]);
        }

        $cedula = preg_replace('/\D/', '', (string) $datos['cedula']);
        if ($cedula === '') {
            throw ValidationException::withMessages(['cedula' => 'Escribe tu cédula (solo los números).']);
        }

        $telefono = Telefono::internacional((string) $datos['telefono']);
        if ($telefono === null) {
            throw ValidationException::withMessages([
                'telefono' => 'Escribe un teléfono venezolano válido, por ejemplo 0412-1234567.',
            ]);
        }

        $paciente = Paciente::where('cedula', $cedula)->first();
        if ($paciente !== null) {
            $enArchivo = Telefono::internacional($paciente->telefono);

            if ($enArchivo !== null && $enArchivo !== $telefono) {
                throw ValidationException::withMessages([
                    'telefono' => 'No pudimos verificar tus datos con ese teléfono. Si cambiaste de número, comunícate con el consultorio.',
                ]);
            }
        }

        $anterior = $this->pendiente->para($medico);
        $mismoTramite = $anterior !== null
            && ($anterior['cedula'] ?? null) === $cedula
            && ($anterior['telefono'] ?? null) === $telefono
            && ($anterior['expira'] ?? 0) > now()->timestamp
            && (int) ($anterior['intentos'] ?? 0) < 5;

        if ($mismoTramite && ! $forzarReenvio) {
            // Cambió el horario (o volvió atrás) pero es la misma persona y el código sigue vigente: se
            // reaprovecha, sin gastar otro mensaje ni hacerla esperar.
            $this->pendiente->guardar($this->datosDelTramite($medico, $datos, $cedula, $telefono, $opcion, $anterior));

            return ['reenviado' => false, 'canal' => (string) ($anterior['canal'] ?? '')];
        }

        if ($mismoTramite && $forzarReenvio) {
            $espera = self::SEGUNDOS_ENTRE_ENVIOS - (now()->timestamp - (int) ($anterior['enviado_en'] ?? 0));

            if ($espera > 0) {
                throw ValidationException::withMessages([
                    'codigo' => 'Espera ' . $espera . ' segundo(s) antes de pedir otro código.',
                ]);
            }
        }

        $codigo = $this->codigos->generar();
        $envio = $this->codigos->enviar($telefono, $codigo);

        if (! $envio['ok']) {
            throw ValidationException::withMessages([
                'telefono' => $envio['error'] ?? 'No pudimos enviar el código. Intenta de nuevo en un momento.',
            ]);
        }

        $this->pendiente->guardar($this->datosDelTramite($medico, $datos, $cedula, $telefono, $opcion, [
            'codigo_hash' => Hash::make($codigo),
            'codigo' => app()->environment(['local', 'testing']) ? $codigo : null,
            'expira' => now()->addMinutes(self::MINUTOS_DE_VALIDEZ)->timestamp,
            'intentos' => 0,
            'enviado_en' => now()->timestamp,
            'canal' => $envio['canal'],
        ]));

        return ['reenviado' => true, 'canal' => $envio['canal']];
    }

    /**
     * @param  array<string,mixed>  $datos
     * @param  array<string,mixed>  $codigo  el hash y sus metadatos, o los del trámite anterior
     * @return array<string,mixed>
     */
    private function datosDelTramite(Medico $medico, array $datos, string $cedula, string $telefono, OpcionDeReserva $opcion, array $codigo): array
    {
        return array_merge($codigo, [
            'medico_id' => (int) $medico->id,
            'office_id' => $opcion->officeId,
            'centro_id' => $opcion->centroId,
            'fecha' => $opcion->fecha,
            'hora' => $opcion->hora,
            'numorden' => $opcion->numorden,
            'sede' => $opcion->sede,
            'direccion' => $opcion->direccion,
            'cedula' => $cedula,
            'nombres' => trim((string) $datos['nombres']),
            'apellidos' => trim((string) $datos['apellidos']),
            'telefono' => $telefono,
            'email' => trim((string) ($datos['email'] ?? '')) ?: null,
        ]);
    }
}
