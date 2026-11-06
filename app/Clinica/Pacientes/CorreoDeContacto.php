<?php

namespace App\Clinica\Pacientes;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **El correo al que se le escribe a una historia** (O-66): el del paciente o, en pediatría, el del representante.
 *
 * Es el hermano de `TelefonoDeContacto` para el otro canal: el correo del adulto es `representante.email` (lo carga la ficha,
 * WEB-3.4, y lo completa `RepresentanteDesdeLaFamilia` desde `familia_bebe.email` cuando llega vacío), mientras que el del niño
 * **no se usa nunca** para avisarle. Si el representante no tiene correo, `paraElAviso()` devuelve `null` y el envío no sale; no se
 * cae al del niño.
 *
 * Fuera de pediatría no hay respaldo: el correo del aviso es el del paciente, como siempre (a diferencia del teléfono, el legado no
 * guarda un «correo del representante» que se haya usado como sustituto).
 *
 * Es lectura pura: no escribe nada, y el tenant es `reg_medico`.
 */
final class CorreoDeContacto
{
    /**
     * **El correo al que se le escribe a una historia**: el del representante en pediatría (`$esNino`, que lo dice
     * `FamiliaDelPaciente::esUnNino()`) y el del paciente fuera de ella.
     */
    public function paraElAviso(string $regMedico, ?string $numhistoria, ?string $propio, bool $esNino): ?string
    {
        return $esNino
            ? $this->delRepresentante($regMedico, $numhistoria)
            : CamposDePaciente::texto($propio);
    }

    /** El correo del representante de la historia, o `null` si no lo tiene. */
    public function delRepresentante(string $regMedico, ?string $numhistoria): ?string
    {
        $historia = CamposDePaciente::historiaNumerica($numhistoria);

        return $historia === null ? null : ($this->deLasHistorias($regMedico, [$historia])[$historia] ?? null);
    }

    /**
     * Varios representantes de una vez (los cumpleaños del día y la agenda resuelven por lote).
     *
     * @param  iterable<int|string>  $numhistorias
     * @return array<int,string> `numhistoria` entero => correo; sólo los que tienen alguno
     */
    public function deLasHistorias(string $regMedico, iterable $numhistorias): array
    {
        $historias = Collection::make($numhistorias)
            ->map(fn ($n) => CamposDePaciente::historiaNumerica((string) $n))
            ->filter(fn ($n) => $n !== null)
            ->unique()
            ->values();

        if ($historias->isEmpty()) {
            return [];
        }

        $correos = [];

        // Igual que el teléfono (`TelefonoDeContacto::deLasHistorias`): si hubiera más de una fila por historia, manda la más vieja.
        $representantes = DB::table('representante')
            ->where('reg_medico', $regMedico)
            ->whereIn('numhistoria', $historias->all())
            ->orderByDesc('id')
            ->get(['numhistoria', 'email']);

        foreach ($representantes as $representante) {
            $correo = CamposDePaciente::texto($representante->email);

            if ($correo !== null) {
                $correos[(int) $representante->numhistoria] = $correo;
            }
        }

        return $correos;
    }
}
