<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\User;
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
            // Intenta obtener el usuario autenticado por token
            $user = $request->user();

            // Si el guard no retorna usuario, intenta buscar por el parámetro user_id si fue enviado
            if (!$user && $request->has('user_id')) {
                $user = User::find($request->input('user_id'));
            }

            if (!$user) {
                return response()->json(['message' => 'Usuario no autenticado o no encontrado.'], 401);
            }

            // Determinación del tipo de usuario y modelo de médico asociado
            $userType = 'Root';
            $medicoModel = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();

            if (method_exists($user, 'hasRole')) {
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

            $historiasMedicas = collect([]);

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

                // Consultar historias médicas cargando relaciones (incluyendo centro médico y médico)
                $historiasMedicas = Historia::with(['medicalCenter.country', 'medicalCenter.estado', 'medicalCenter.city', 'medicalCenter.offices', 'paciente', 'medico'])
                    ->where(function ($query) use ($medicoModel, $registrosMedicos, $historias) {
                        $query->where('medico_id', $medicoModel->id);
                        if (!empty($registrosMedicos)) {
                            $query->orWhereIn('reg_medico', $registrosMedicos);
                        }
                        if (!empty($historias)) {
                            $query->orWhereIn('numhistoria', $historias);
                        }
                    })
                    ->get();

                // Mapa de historias para asociar rápidamente medical_center_id y centro médico por numhistoria o combinación (numhistoria + reg_medico)
                $historiaByNumMap = $historiasMedicas->keyBy('numhistoria');
                $historiaByKeyMap = $historiasMedicas->keyBy(function ($item) {
                    return $item->numhistoria . '_' . $item->reg_medico;
                });

                // Mapear el número de historia y centro médico a los pacientes desde el modelo Historia / Pivote
                $pacientesRaw->each(function ($p) use ($historiasMap, $historiaByNumMap) {
                    $numHist = $historiasMap[$p->id] ?? $p->numhistoria ?? '';
                    $p->numhistoria_pivote = $numHist;
                    
                    $historiaObj = $historiaByNumMap->get($numHist);
                    $p->medical_center_id = $historiaObj ? $historiaObj->medical_center_id : null;
                    $p->medical_center = $historiaObj ? $historiaObj->medicalCenter : null;
                });

                // Consultar la tabla `consultas`
                $consultas = Consulta::whereIn('numhistoria', $historias)
                    ->whereBetween('fecha', [$inicioMes, $finMes])
                    ->get();

                // Consultar la tabla `cola` por reg_medico o numhistoria
                $colasQuery = Cola::query();

                if (!empty($registrosMedicos)) {
                    $colasQuery->whereIn('reg_medico', $registrosMedicos);
                } elseif (!empty($historias)) {
                    $colasQuery->whereIn('numhistoria', $historias);
                }

                $colasRaw = $colasQuery->whereBetween('fecha', [$inicioMes, $finMes])->get();

                // Mapear colas para adjuntar datos de historia y centro médico según numhistoria y reg_medico
                $colas = $colasRaw->map(function ($cola) use ($historiaByKeyMap, $historiaByNumMap) {
                    $key = $cola->numhistoria . '_' . $cola->reg_medico;
                    $historiaObj = $historiaByKeyMap->get($key) ?? $historiaByNumMap->get($cola->numhistoria);

                    $colaArray = $cola->toArray();
                    $colaArray['medical_center_id'] = $historiaObj ? $historiaObj->medical_center_id : null;
                    $colaArray['medical_center'] = $historiaObj ? $historiaObj->medicalCenter : null;
                    $colaArray['historia'] = $historiaObj ?? null;

                    return $colaArray;
                });

                // Obtener centros médicos asociados
                $medicalCenterIdsFromPivote = MedicoMedicalCenter::where('medico_id', $medicoModel->id)
                    ->pluck('medical_center_id')
                    ->filter()
                    ->toArray();

                $medicalCenterIdsFromHistorias = $historiasMedicas->pluck('medical_center_id')->filter()->toArray();

                $allMedicalCenterIds = array_values(array_unique(array_merge($medicalCenterIdsFromPivote, $medicalCenterIdsFromHistorias)));

                $centrosMedicos = MedicalCenter::with(['country', 'estado', 'city', 'offices'])
                    ->whereIn('id', $allMedicalCenterIds)
                    ->get();

            } elseif ($userType === 'Paciente') {
                $pacienteModel = Paciente::where('user_id', $user->id)->orWhere('email', $user->email)->first();
                $pacientesRaw = $pacienteModel ? collect([$pacienteModel]) : collect([]);

                $numHistoriaPac = $pacienteModel ? ($pacienteModel->numhistoria ?? '') : '';

                // Obtener historias del paciente
                $historiasMedicas = $pacienteModel 
                    ? Historia::with(['medicalCenter.country', 'medicalCenter.estado', 'medicalCenter.city', 'medicalCenter.offices', 'paciente', 'medico'])
                        ->where('paciente_id', $pacienteModel->id)
                        ->orWhere('numhistoria', $numHistoriaPac)
                        ->get()
                    : collect([]);

                $historiaByNumMap = $historiasMedicas->keyBy('numhistoria');
                $historiaByKeyMap = $historiasMedicas->keyBy(function ($item) {
                    return $item->numhistoria . '_' . $item->reg_medico;
                });

                if ($pacienteModel) {
                    $historiaObj = $historiaByNumMap->get($numHistoriaPac);
                    $pacienteModel->numhistoria_pivote = $numHistoriaPac;
                    $pacienteModel->medical_center_id = $historiaObj ? $historiaObj->medical_center_id : null;
                    $pacienteModel->medical_center = $historiaObj ? $historiaObj->medicalCenter : null;
                }

                $consultas = !empty($numHistoriaPac)
                    ? Consulta::where('numhistoria', $numHistoriaPac)->whereBetween('fecha', [$inicioMes, $finMes])->get() 
                    : collect([]);

                $colasRaw = !empty($numHistoriaPac)
                    ? Cola::where('numhistoria', $numHistoriaPac)->whereBetween('fecha', [$inicioMes, $finMes])->get()
                    : collect([]);

                $colas = $colasRaw->map(function ($cola) use ($historiaByKeyMap, $historiaByNumMap) {
                    $key = $cola->numhistoria . '_' . $cola->reg_medico;
                    $historiaObj = $historiaByKeyMap->get($key) ?? $historiaByNumMap->get($cola->numhistoria);

                    $colaArray = $cola->toArray();
                    $colaArray['medical_center_id'] = $historiaObj ? $historiaObj->medical_center_id : null;
                    $colaArray['medical_center'] = $historiaObj ? $historiaObj->medicalCenter : null;
                    $colaArray['historia'] = $historiaObj ?? null;

                    return $colaArray;
                });

                // Obtener todos los centros médicos disponibles
                $centrosMedicos = MedicalCenter::with(['country', 'estado', 'city', 'offices'])->get();

            } else {
                // Caso Root sin modelo médico específico
                $pacientesRaw = Paciente::all();
                $consultas = Consulta::whereBetween('fecha', [$inicioMes, $finMes])->get();
                $colasRaw = Cola::whereBetween('fecha', [$inicioMes, $finMes])->get();

                $centrosMedicos = MedicalCenter::with(['country', 'estado', 'city', 'offices'])->get();
                $historiasMedicas = Historia::with(['medicalCenter.country', 'medicalCenter.estado', 'medicalCenter.city', 'medicalCenter.offices', 'paciente', 'medico'])->get();

                $historiaByNumMap = $historiasMedicas->keyBy('numhistoria');
                $historiaByKeyMap = $historiasMedicas->keyBy(function ($item) {
                    return $item->numhistoria . '_' . $item->reg_medico;
                });

                $pacientesRaw->each(function ($p) use ($historiaByNumMap) {
                    $historiaObj = $historiaByNumMap->get($p->numhistoria);
                    $p->medical_center_id = $historiaObj ? $historiaObj->medical_center_id : null;
                    $p->medical_center = $historiaObj ? $historiaObj->medicalCenter : null;
                });

                $colas = $colasRaw->map(function ($cola) use ($historiaByKeyMap, $historiaByNumMap) {
                    $key = $cola->numhistoria . '_' . $cola->reg_medico;
                    $historiaObj = $historiaByKeyMap->get($key) ?? $historiaByNumMap->get($cola->numhistoria);

                    $colaArray = $cola->toArray();
                    $colaArray['medical_center_id'] = $historiaObj ? $historiaObj->medical_center_id : null;
                    $colaArray['medical_center'] = $historiaObj ? $historiaObj->medicalCenter : null;
                    $colaArray['historia'] = $historiaObj ?? null;

                    return $colaArray;
                });
            }

            // Mapear los pacientes para la respuesta JSON agregando relaciones de centro médico e historia
            $pacientes = $pacientesRaw->map(function ($p) {
                $rawName = trim($p->nombres ?? $p->name ?? '');
                $rawLastname = trim($p->apellidos ?? $p->lastname ?? '');

                $partsName = preg_split('/\s+/', $rawName);
                $partsLastname = preg_split('/\s+/', $rawLastname);

                $firstName = !empty($partsName[0]) ? $partsName[0] : '';
                $firstLastName = !empty($partsLastname[0]) ? $partsLastname[0] : '';

                return [
                    'id'                => $p->id,
                    'nac'               => $p->nac ?? $p->nacionalidad ?? 'V',
                    'cedula'            => $p->cedula,
                    'email'             => $p->email,
                    'name'              => $firstName,
                    'lastname'          => $firstLastName,
                    'cellphone'         => $p->telefono ?? '',
                    'numhistoria'       => $p->numhistoria_pivote ?? $p->numhistoria ?? '',
                    'medical_center_id' => $p->medical_center_id ?? null,
                    'medical_center'    => $p->medical_center ?? null,
                ];
            });

            // Mapear las citas/consultas asociando el paciente y centro médico relacionado mediante la historia
            $citas = $consultas->map(function ($consulta) use ($pacientes, $historiasMedicas) {
                $numHistoriaConsulta = $consulta->numhistoria ?? null;
                $pacienteEncontrado = $pacientes->firstWhere('numhistoria', $numHistoriaConsulta);

                $historiaObj = $historiasMedicas->firstWhere('numhistoria', $numHistoriaConsulta);

                $consultaArray = $consulta->toArray();
                $consultaArray['paciente'] = $pacienteEncontrado ?? null;
                $consultaArray['medical_center_id'] = $historiaObj ? $historiaObj->medical_center_id : null;
                $consultaArray['medical_center'] = $historiaObj ? $historiaObj->medicalCenter : null;

                return $consultaArray;
            });

            // Obtención de la tabla de motivos de cita
            $motivos = MotivoCita::all();

            return response()->json([
                'citas'                   => $citas,
                'colas'                   => $colas,
                'pacientes'               => $pacientes->values(),
                'motivos'                 => $motivos,
                'centros_medicos'         => $centrosMedicos,
                'historias'               => $historiasMedicas,
                'capacidad_diaria_maxima' => 8
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error en el servidor: ' . $e->getMessage()], 500);
        }
    }
}