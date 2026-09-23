<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConfiguracionMedicoResource;
use App\Models\Evolucion;
use App\Models\Medico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de reporte del médico (especialidad, logo, pie de récipe/informe — ver ROADMAP.md Paso 17):
 * la pantalla "Diseño de reportes" de Configuración.
 *
 * **Online-only, igual que `NotificacionCitaController`**: no pasa por la cola de `sync-app-data`
 * a propósito. Esa cola está pensada para tablas de muchas filas con id propio (`pacientes`,
 * `cola`); `evolucion` es una fila única por médico, y encolarla ahí obligaría a inventar un id
 * local para algo que no lo necesita. El costo es que guardar pide conexión — aceptable para una
 * pantalla de configuración de baja frecuencia, no para el flujo clínico.
 */
class ConfiguracionMedicoController extends Controller
{
    public function actualizar(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medico = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (!$medico) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        $datos = $request->validate([
            'especialidad' => 'sometimes|nullable|string|max:200',
            'reg_medico' => 'sometimes|nullable|string|max:20',
            'ciudad' => 'sometimes|nullable|string|max:20',
            'rif' => 'sometimes|nullable|string|max:40',
            'pie_recipe.direccion' => 'sometimes|nullable|string|max:90',
            'pie_recipe.telefono' => 'sometimes|nullable|string|max:90',
            'pie_recipe.correo' => 'sometimes|nullable|string|max:90',
            'pie_informe.direccion' => 'sometimes|nullable|string|max:115',
            'pie_informe.consultorio' => 'sometimes|nullable|string|max:115',
            'logo' => 'sometimes|nullable|image|max:2048',
        ]);

        // `evolucion` no tiene `medico_id`: la clave de negocio es `reg_medico`, igual que en el
        // resto del backend (ver MedicAPI/AGENTS.md). Un médico con varios registros (`MedicoRegistro`)
        // guarda su reporte bajo el registro "principal" (ver `Medico::regMedicoPrincipal`).
        $regMedicoClave = $medico->regMedicoPrincipal();
        if (!$regMedicoClave) {
            return response()->json([
                'message' => 'Esta cuenta no tiene un número de registro médico principal configurado.',
            ], 422);
        }

        $evolucion = Evolucion::firstOrNew(['reg_medico' => $regMedicoClave]);

        foreach (['especialidad', 'ciudad', 'rif'] as $campo) {
            if ($request->has($campo)) {
                $evolucion->$campo = $datos[$campo] ?? null;
            }
        }
        if ($request->has('reg_medico')) {
            // Permite corregir el número de registro médico que se imprime, sin cambiar la
            // clave `reg_medico` con la que esta fila se busca (esa sigue siendo la del médico).
            $evolucion->reg_medico = $datos['reg_medico'] ?? $regMedicoClave;
        } elseif (!$evolucion->exists) {
            $evolucion->reg_medico = $regMedicoClave;
        }

        if ($request->has('pie_recipe.direccion')) $evolucion->linea_1 = $datos['pie_recipe']['direccion'] ?? null;
        if ($request->has('pie_recipe.telefono')) $evolucion->linea_2 = $datos['pie_recipe']['telefono'] ?? null;
        if ($request->has('pie_recipe.correo')) $evolucion->linea_3 = $datos['pie_recipe']['correo'] ?? null;
        if ($request->has('pie_informe.direccion')) $evolucion->lineag_1 = $datos['pie_informe']['direccion'] ?? null;
        if ($request->has('pie_informe.consultorio')) $evolucion->lineag_2 = $datos['pie_informe']['consultorio'] ?? null;

        if ($request->hasFile('logo')) {
            // Se borra la anterior para no acumular archivos huérfanos: acá no hay historial de
            // logos, cada guardado reemplaza al único vigente.
            if ($evolucion->logo) {
                Storage::disk('public')->delete($evolucion->logo);
            }
            $evolucion->logo = $request->file('logo')->store('logos', 'public');
        }

        // `clave` es NOT NULL en el esquema legado sin default — una fila nueva sin ese dato de
        // PowerBuilder (contraseña de acceso al reporte legado, no usado acá) rompería el insert.
        if (!$evolucion->exists && $evolucion->clave === null) {
            $evolucion->clave = 0;
        }

        $evolucion->save();

        return new ConfiguracionMedicoResource($evolucion);
    }
}
