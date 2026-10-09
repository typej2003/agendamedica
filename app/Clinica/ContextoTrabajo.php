<?php

namespace App\Clinica;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Contexto de trabajo de la web clínica: **con qué datos** está operando el usuario.
 *
 * Un usuario puede tener acceso a varios `reg_medico` (el suyo, los que le asignaron desde el panel, o
 * los de la secretaría que lo atiende) y cada médico puede tener más de una especialidad y varias sedes.
 * Todo eso se elige una vez, se guarda en la sesión y **se revalida en cada request**: si le quitaron el
 * acceso, el contexto deja de ser válido y la web lo manda a elegirlo de nuevo.
 *
 * La especialidad es lo que decide qué módulos se ven (PLAN-WEB.md, R1/R2): no hay `if` por especialidad.
 */
class ContextoTrabajo
{
    private const SESION = 'clinica.contexto';

    /** Roles del usuario, tal como los usa el catálogo de módulos. */
    public function roles(User $user): array
    {
        return $user->getRoleNames()->all();
    }

    /**
     * Todo lo que este usuario puede elegir: `reg_medico` => [medico, especialidades, sedes].
     *
     * @return array<string, array{reg_medico: string, medico: Medico, especialidades: Collection, sedes: Collection}>
     */
    public function disponibles(User $user): array
    {
        $medicos = $this->medicosDelUsuario($user);

        $registros = MedicoRegistro::query()
            ->where(function ($consulta) use ($user, $medicos) {
                $consulta->where('user_id', $user->id)
                    ->orWhereIn('medico_id', $medicos->pluck('id')->all());
            })
            ->pluck('reg_medico')
            ->filter()
            ->all();

        // El `reg_medico` propio de la ficha, por si todavía no hay fila en el pivote.
        foreach ($medicos as $medico) {
            if ($medico->reg_medico) {
                $registros[] = $medico->reg_medico;
            }
        }

        $salida = [];
        foreach (array_values(array_unique($registros)) as $registro) {
            $medico = $medicos->firstWhere('reg_medico', $registro) ?? Medico::where('reg_medico', $registro)->first();
            if (! $medico) {
                continue; // datos sin ficha de médico: no hay nombre ni especialidad que mostrar
            }

            $sedes = Office::where('activo', true)
                ->where(function ($consulta) use ($registro, $medico) {
                    $consulta->where('reg_medico', $registro)->orWhere('medico_id', $medico->id);
                })
                ->orderBy('id')
                ->get();

            $salida[$registro] = [
                'reg_medico'     => $registro,
                'medico'         => $medico,
                'especialidades' => $medico->specialties->where('activo', true)->values(),
                'sedes'          => $sedes,
            ];
        }

        return $salida;
    }

    /** Contexto vigente, o `null` si no hay ninguno elegido (o el elegido ya no es accesible). */
    public function actual(User $user): ?array
    {
        $guardado = session(self::SESION);
        if (! is_array($guardado) || empty($guardado['reg_medico'])) {
            return null;
        }

        $disponibles = $this->disponibles($user);
        $registro = (string) $guardado['reg_medico'];
        if (! isset($disponibles[$registro])) {
            return null;
        }

        $acceso = $disponibles[$registro];
        $specialty = $acceso['especialidades']->firstWhere('id', (int) ($guardado['specialty_id'] ?? 0))
            ?? $acceso['especialidades']->first();
        $office = ! empty($guardado['office_id'])
            ? $acceso['sedes']->firstWhere('id', (int) $guardado['office_id'])
            : null;

        return [
            'reg_medico'     => $registro,
            'medico'         => $acceso['medico'],
            'especialidades' => $acceso['especialidades'],
            'specialty'      => $specialty,
            'sedes'          => $acceso['sedes'],
            'office'         => $office,
        ];
    }

    /**
     * Fija el contexto. Valida contra los accesos reales: no se puede elegir un `reg_medico` ajeno ni
     * una sede de otro médico.
     */
    public function fijar(User $user, string $regMedico, ?int $specialtyId = null, ?int $officeId = null): array
    {
        $disponibles = $this->disponibles($user);
        if (! isset($disponibles[$regMedico])) {
            throw ValidationException::withMessages(['reg_medico' => 'No tenés acceso a los datos de ese médico.']);
        }

        $acceso = $disponibles[$regMedico];
        $specialty = $acceso['especialidades']->firstWhere('id', $specialtyId) ?? $acceso['especialidades']->first();
        $office = $officeId ? $acceso['sedes']->firstWhere('id', $officeId) : null;

        session([self::SESION => [
            'reg_medico'   => $regMedico,
            'specialty_id' => $specialty?->id,
            'office_id'    => $office?->id,
        ]]);

        return $this->actual($user);
    }

    /** Cambiar de especialidad o de sede sin volver a elegir el médico. */
    public function cambiar(User $user, ?int $specialtyId = null, ?int $officeId = null): ?array
    {
        $actual = $this->actual($user);
        if (! $actual) {
            return null;
        }

        return $this->fijar($user, $actual['reg_medico'], $specialtyId, $officeId);
    }

    public function limpiar(): void
    {
        session()->forget(self::SESION);
    }

    /** @return Collection<int, Medico> */
    private function medicosDelUsuario(User $user): Collection
    {
        return Medico::where('user_id', $user->id)->get();
    }
}
