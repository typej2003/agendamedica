<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\Consulta;
use App\Models\Cola;
use App\Models\MotivoCita;
use App\Models\MedicoPaciente;
use App\Models\MedicalCenter;
use App\Models\MedicoMedicalCenter;
use App\Models\Historia;

class RefreshAppController extends Controller
{
    /**
     * Obtención y refresco de datos pesados de la agenda médica.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refreshData(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return \response()->json(['message' => 'Usuario no autenticado.'], 401);
            }

            // Determinación del tipo de usuario y modelo de médico asociado
            $userType = 'Root';
            $medicoModel = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();

            if (\method_exists($user, 'hasRole')) {
                if ($user->hasRole('Medico')) {
                    $userType = 'Medico';
                } elseif ($user->hasRole('Paciente')) {
                    $userType = 'Paciente';
                }
            } else {
                if ($medicoModel) {
                    $userType = 'Medico';
                } else {
                    $pacienteCheck = Paciente::where('user_id', $user->id)->orWhere('email', $user->email)->first();
                    if ($pacienteCheck) {
                        $userType = 'Paciente';
                    }
                }
            }

            // Determinar el rango de fechas dinámicamente o por defecto el mes actual
            $mes = $request->input('mes');
            $anio = $request->input('anio', Carbon::now()->year);

            if ($mes) {
                $fechaBase = Carbon::createFromDate($anio, $mes, 1);
                $inicioMes = $fechaBase->copy()->startOfMonth()->format('Y-m-d');
                $finMes = $fechaBase->copy()->endOfMonth()->format('Y-m-d');
            } else {
                $inicioMes = Carbon::now()->startOfMonth()->format('Y-m-d');
                $finMes = Carbon::now()->endOfMonth()->format('Y-m-d');
            }

            $historiasMedicas = \collect([]);

            // Obtención de datos según el tipo de usuario
            if ($userType === 'Medico' || ($userType === 'Root' && $medicoModel)) {

                // Recopilar reg_medico desde MedicoRegistro y el modelo Medico
                $registrosMedicos = MedicoRegistro::where('medico_id', $medicoModel->id)
                    ->pluck('reg_medico')
                    ->filter()
                    ->toArray();

                if (!empty($medicoModel->reg_medico)) {
                    $registrosMedicos[] = $medicoModel->reg_medico;
                }

                $registrosMedicos = array_values(array_unique($registrosMedicos));

                // Buscar las relaciones pivote asociadas al medico_id
                $relaciones = MedicoPaciente::where('medico_id', $medicoModel->id)->get();

                $pacienteIds = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();
                $historias = $relaciones->pluck('numhistoria')->filter()->unique()->toArray();
                $historiasMap = $relaciones->pluck('numhistoria', 'paciente_id')->toArray();

                // Consultar los pacientes usando los IDs obtenidos
                $pacientesRaw = Paciente::whereIn('id', $pacienteIds)->get();

                // Mapear el número de historia proveniente de la tabla pivote
                $pacientesRaw->each(function ($p) use ($historiasMap) {
                    $p->numhistoria_pivote = $historiasMap[$p->id] ?? $p->numhistoria ?? '';
                });

                // Consultar la tabla `consultas`
                $consultas = Consulta::whereIn('numhistoria', $historias)
                    ->whereBetween('fecha', [$inicioMes, $finMes])
                    ->get();

                // Consultar la tabla `cola` por reg_medico filtrando por rango de fechas
                $colasQuery = Cola::query();

                if (!empty($registrosMedicos)) {
                    $colasQuery->whereIn('reg_medico', $registrosMedicos);
                } elseif (!empty($historias)) {
                    $colasQuery->whereIn('numhistoria', $historias);
                }

                $colas = $colasQuery->whereBetween('fecha', [$inicioMes, $finMes])->get();

                // Obtener centros médicos asociados específicamente a este médico
                $medicalCenterIds = MedicoMedicalCenter::where('medico_id', $medicoModel->id)
                    ->pluck('medical_center_id')
                    ->filter()
                    ->unique()
                    ->toArray();

                $centrosMedicos = MedicalCenter::with(['country', 'estado', 'city', 'offices'])
                    ->whereIn('id', $medicalCenterIds)
                    ->get();

                // Obtener historias asociadas al médico por medico_id o por reg_medico
                $historiasQuery = Historia::query()->with(['medicalCenter', 'paciente', 'medico']);

                $historiasQuery->where(function ($query) use ($medicoModel, $registrosMedicos) {
                    $query->where('medico_id', $medicoModel->id);
                    if (!empty($registrosMedicos)) {
                        $query->orWhereIn('reg_medico', $registrosMedicos);
                    }
                });

                $historiasMedicas = $historiasQuery->get();

            } elseif ($userType === 'Paciente') {
                $pacienteModel = Paciente::where('user_id', $user->id)->orWhere('email', $user->email)->first();
                $pacientesRaw = $pacienteModel ? \collect([$pacienteModel]) : \collect([]);

                $numHistoriaPac = $pacienteModel ? ($pacienteModel->numhistoria ?? '') : '';

                $consultas = !empty($numHistoriaPac)
                    ? Consulta::where('numhistoria', $numHistoriaPac)->whereBetween('fecha', [$inicioMes, $finMes])->get() 
                    : \collect([]);

                $colas = !empty($numHistoriaPac)
                    ? Cola::where('numhistoria', $numHistoriaPac)->whereBetween('fecha', [$inicioMes, $finMes])->get()
                    : \collect([]);

                // Obtener todos los centros médicos disponibles
                $centrosMedicos = MedicalCenter::with(['country', 'estado', 'city', 'offices'])->get();

                // Historias médicas correspondientes al paciente
                $historiasMedicas = $pacienteModel 
                    ? Historia::with(['medicalCenter', 'paciente', 'medico'])->where('paciente_id', $pacienteModel->id)->get()
                    : \collect([]);

            } else {
                // Caso Root sin modelo médico específico
                $pacientesRaw = Paciente::all();
                $consultas = Consulta::whereBetween('fecha', [$inicioMes, $finMes])->get();
                $colas = Cola::whereBetween('fecha', [$inicioMes, $finMes])->get();

                // Obtener todos los centros médicos y todas las historias
                $centrosMedicos = MedicalCenter::with(['country', 'estado', 'city', 'offices'])->get();
                $historiasMedicas = Historia::with(['medicalCenter', 'paciente', 'medico'])->get();
            }

            // Mapear los pacientes para la respuesta JSON
            $pacientes = $pacientesRaw->map(function ($p) {
                $rawName = \trim($p->nombres ?? $p->name ?? '');
                $rawLastname = \trim($p->apellidos ?? $p->lastname ?? '');

                $partsName = \preg_split('/\s+/', $rawName);
                $partsLastname = \preg_split('/\s+/', $rawLastname);

                $firstName = !empty($partsName[0]) ? $partsName[0] : '';
                $firstLastName = !empty($partsLastname[0]) ? $partsLastname[0] : '';

                return [
                    'id'          => $p->id,
                    'nac'         => $p->nac ?? $p->nacionalidad ?? 'V',
                    'cedula'      => $p->cedula,
                    'email'       => $p->email,
                    'name'        => $firstName,
                    'lastname'    => $firstLastName,
                    'cellphone'   => $p->telefono ?? '',
                    'numhistoria' => $p->numhistoria_pivote ?? $p->numhistoria ?? '',
                ];
            });

            // Mapear las citas/consultas asociando el paciente mediante numhistoria
            $citas = $consultas->map(function ($consulta) use ($pacientes) {
                $numHistoriaConsulta = $consulta->numhistoria ?? null;
                $pacienteEncontrado = $pacientes->firstWhere('numhistoria', $numHistoriaConsulta);

                $consultaArray = $consulta->toArray();
                $consultaArray['paciente'] = $pacienteEncontrado ?? null;

                return $consultaArray;
            });

            // Obtención de la tabla de motivos de cita
            $motivos = MotivoCita::all();

            return \response()->json([
                'citas'                   => $citas,
                'colas'                   => $colas,
                'pacientes'               => $pacientes->values(),
                'motivos'                 => $motivos,
                'centros_medicos'         => $centrosMedicos,
                'historias'               => $historiasMedicas,
                'capacidad_diaria_maxima' => 8
            ], 200);

        } catch (\Exception $e) {
            return \response()->json(['message' => 'Error en el servidor: ' . $e->getMessage()], 500);
        }
    }
}