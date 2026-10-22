<?php

namespace App\Reservas;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\User;
use App\Notificaciones\Telefono;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **Límites preventivos de reserva**: la regla única para quien agenda por su cuenta (portal «Mis citas»,
 * reserva pública y chatbot de WhatsApp). La secretaría y el médico agendan desde la web clínica sin pasar por acá.
 *
 *  - **Citas pendientes** (`identidad.max_citas_pendientes`): futuras, sin atender. Una cita cancelada se borra
 *    (`EliminarCita`), así que no cuenta; las pasadas y las atendidas tampoco. La persona se identifica por su cuenta
 *    (la ficha vinculada) y por el teléfono **validado** de la solicitud (todas las fichas con ese teléfono); nunca por
 *    el correo ni por una cédula tecleada. Se cuenta con una sola consulta a `cola`.
 *  - **Códigos por hora** (`identidad.max_codigos_por_hora`): los códigos no se guardan en la base (viven en la sesión),
 *    así que el conteo por teléfono vive en el cache, en una ventana móvil de una hora.
 */
final class LimitesDeReserva
{
    private const SEGUNDOS_DE_VENTANA = 3600;

    public static function mensajeDeCitas(): string
    {
        return 'Ya tienes ' . self::maxCitasPendientes() . ' citas pendientes. Atiéndelas o cancélalas antes de reservar otra.';
    }

    public static function mensajeDeCodigos(): string
    {
        return 'Pediste demasiados códigos. Intenta de nuevo en una hora.';
    }

    public static function maxCitasPendientes(): int
    {
        return (int) config('identidad.max_citas_pendientes', 3);
    }

    public static function maxCodigosPorHora(): int
    {
        return (int) config('identidad.max_codigos_por_hora', 5);
    }

    // ---- Citas pendientes ------------------------------------------------------------------------------------

    /** Citas pendientes de la persona: su cuenta y/o las fichas con ese teléfono (validado). */
    public function citasPendientes(?User $user = null, ?string $telefonoValidado = null): int
    {
        $ids = [];

        if ($user !== null) {
            $ficha = Paciente::where('user_id', $user->id)->value('id');
            if ($ficha !== null) {
                $ids[] = (int) $ficha;
            }
        }

        $ultimos = $this->ultimosDiez($telefonoValidado);
        if ($ultimos !== null) {
            $ids = array_merge($ids, Paciente::where('telefono', 'like', "%{$ultimos}%")->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return 0;
        }

        return Cola::query()
            ->whereDate('fecha', '>=', FechaDelConsultorio::hoy()->toDateString())
            ->where(fn ($q) => $q->whereNull('atendido')->orWhere('atendido', 0))
            ->where(fn ($q) => $q->whereNull('movida_escritorio')->orWhere('movida_escritorio', 0))
            ->where(function ($q) use ($ids) {
                $q->whereIn('paciente_sinhistoria_id', $ids)
                    ->orWhereExists(function ($sub) use ($ids) {
                        $sub->select(DB::raw(1))
                            ->from('medico_pacientes')
                            ->whereIn('medico_pacientes.paciente_id', $ids)
                            ->whereColumn('medico_pacientes.reg_medico', 'cola.reg_medico')
                            ->whereColumn('medico_pacientes.numhistoria', 'cola.numhistoria');
                    });
            })
            ->count();
    }

    public function tieneCupoDeCitas(?User $user = null, ?string $telefonoValidado = null): bool
    {
        return $this->citasPendientes($user, $telefonoValidado) < self::maxCitasPendientes();
    }

    /** @throws ValidationException con el aviso al paciente */
    public function exigirCupoDeCitas(?User $user = null, ?string $telefonoValidado = null, string $campo = 'fecha'): void
    {
        if (! $this->tieneCupoDeCitas($user, $telefonoValidado)) {
            throw ValidationException::withMessages([$campo => self::mensajeDeCitas()]);
        }
    }

    // ---- Códigos por hora ------------------------------------------------------------------------------------

    public function codigosDisponibles(string $telefono): bool
    {
        return count($this->envios($telefono)) < self::maxCodigosPorHora();
    }

    /** Anota un envío; devuelve false (y no anota) si el teléfono ya llegó al tope de la hora. */
    public function registrarCodigo(string $telefono): bool
    {
        $envios = $this->envios($telefono);

        if (count($envios) >= self::maxCodigosPorHora()) {
            return false;
        }

        $envios[] = now()->timestamp;
        Cache::put($this->llave($telefono), $envios, self::SEGUNDOS_DE_VENTANA);

        return true;
    }

    /** @return list<int> marcas de tiempo de los envíos de la última hora */
    private function envios(string $telefono): array
    {
        $desde = now()->timestamp - self::SEGUNDOS_DE_VENTANA;

        return array_values(array_filter((array) Cache::get($this->llave($telefono), []), fn ($t) => (int) $t > $desde));
    }

    private function llave(string $telefono): string
    {
        return 'limite-codigos:' . (Telefono::internacional($telefono) ?? preg_replace('/\D/', '', $telefono));
    }

    private function ultimosDiez(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono);

        return strlen($digitos) >= 10 ? substr($digitos, -10) : null;
    }
}
