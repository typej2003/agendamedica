<?php

namespace App\Http\Controllers\Clinica;

use App\Http\Controllers\Controller;
use App\Models\Medico;
use App\Models\Specialty;
use App\Services\CuentaService;
use Illuminate\Http\Request;

/**
 * **El médico elige sus propias subespecialidades** (2026-10-30).
 *
 * Hasta hoy sólo el administrador podía activarlas (`/admin/especialidades` →
 * `CuentaService::asignarSubespecialidades()`). Los postgrados son lo que enciende los módulos y las
 * secciones de la consulta —neumología pediátrica, gastroenterología pediátrica, cirugía pediátrica—, así
 * que un médico que los tiene no podía trabajar con ellos sin pedirle el cambio a un administrador.
 *
 * La decisión del usuario (2026-10-30) es que el médico pueda activarlos por su cuenta. La **especialidad
 * principal** la sigue asignando el administrador: es el modo base de la cuenta y cambiarlo desde acá
 * abriría el resto. Sólo se ofrecen los postgrados de las especialidades que el médico **ya tiene**, y la
 * regla de escritura sigue siendo la del servicio (un postgrado sin su especialidad no se guarda).
 *
 * Queda en la bitácora de la cuenta? No: no hay bitácora de esta pantalla. Se avisa el cambio en pantalla
 * y el resultado real lo devuelve el servicio (`asignadas`/`quitadas`).
 */
class MisSubespecialidadesController extends Controller
{
    public function index()
    {
        $medicos = $this->misMedicos();

        return view('clinica.mis-subespecialidades', [
            'medicos' => $medicos->map(fn (Medico $medico) => [
                'medico'         => $medico,
                'especialidades' => $medico->specialties->map(fn (Specialty $especialidad) => [
                    'especialidad'  => $especialidad,
                    'postgrados'    => $especialidad->subespecialidades->where('activo', true)->sortBy('orden')->values(),
                    'activas'       => $medico->subespecialidadesDe($especialidad)->pluck('id')->all(),
                ])->filter(fn (array $fila) => $fila['postgrados']->isNotEmpty())->values(),
            ])->filter(fn (array $fila) => $fila['especialidades']->isNotEmpty())->values(),
        ]);
    }

    public function guardar(Request $request, CuentaService $cuentas)
    {
        $datos = $request->validate([
            'medico_id'         => ['required', 'integer'],
            'subespecialidades' => ['nullable', 'array'],
            'subespecialidades.*' => ['integer'],
        ]);

        $medico = $this->misMedicos()->firstWhere('id', (int) $datos['medico_id']);

        if ($medico === null) {
            abort(404);
        }

        $resultado = $cuentas->asignarSubespecialidades($medico, $datos['subespecialidades'] ?? []);

        return redirect()
            ->route('clinica.mis-subespecialidades')
            ->with('estado', $this->texto($resultado));
    }

    /**
     * Los `reg_medico` que son del usuario. Igual que `ContextoTrabajo::medicosDelUsuario()`: la ficha
     * puede estar enlazada por `medicos.user_id` o por las filas de `medico_registros`. Se usa la misma
     * vara para no ofrecer acá una ficha que el contexto no le reconoce.
     */
    private function misMedicos()
    {
        $user = auth()->user();

        return Medico::where('user_id', $user->id)
            ->orWhereIn('id', \App\Models\MedicoRegistro::where('user_id', $user->id)->whereNotNull('medico_id')->pluck('medico_id'))
            ->with(['specialties.subespecialidades'])
            ->orderBy('id')
            ->get();
    }

    /** @param  array{asignadas:int, quitadas:int}  $resultado */
    private function texto(array $resultado): string
    {
        if ($resultado['asignadas'] === 0 && $resultado['quitadas'] === 0) {
            return 'No hubo cambios en tus subespecialidades.';
        }

        $partes = [];

        if ($resultado['asignadas'] > 0) {
            $partes[] = 'activadas: ' . $resultado['asignadas'];
        }
        if ($resultado['quitadas'] > 0) {
            $partes[] = 'desactivadas: ' . $resultado['quitadas'];
        }

        return 'Tus subespecialidades quedaron al día (' . implode(', ', $partes) . ').';
    }
}
