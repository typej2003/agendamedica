<?php

namespace App\Http\Controllers\Api;

use App\Actions\Configuracion\GuardarMembreteDelMedico;
use App\Actions\Configuracion\LimitesDelMembrete;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConfiguracionMedicoResource;
use App\Models\Medico;
use Illuminate\Http\Request;

/**
 * Datos de reporte del médico (especialidad, logo, pie de récipe/informe — ver ROADMAP.md Paso 17):
 * la pantalla "Diseño de reportes" de Configuración.
 *
 * **Online-only, igual que `NotificacionCitaController`**: no pasa por la cola de `sync-app-data`
 * a propósito. Esa cola está pensada para tablas de muchas filas con id propio (`pacientes`,
 * `cola`); `evolucion` es una fila única por médico, y encolarla ahí obligaría a inventar un id
 * local para algo que no lo necesita. El costo es que guardar pide conexión — aceptable para una
 * pantalla de configuración de baja frecuencia, no para el flujo clínico.
 *
 * Desde WEB-8.2 la escritura es `GuardarMembreteDelMedico` y los largos máximos son los de `LimitesDelMembrete`, los
 * mismos que usa la pantalla "Formato de impresión" de la web clínica; este controlador sólo traduce HTTP y su JSON no
 * cambió.
 */
class ConfiguracionMedicoController extends Controller
{
    public function actualizar(Request $request, GuardarMembreteDelMedico $guardar)
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
            'especialidad' => 'sometimes|' . LimitesDelMembrete::regla('especialidad'),
            'reg_medico' => 'sometimes|nullable|string|max:20',
            'ciudad' => 'sometimes|' . LimitesDelMembrete::regla('ciudad'),
            'rif' => 'sometimes|' . LimitesDelMembrete::regla('rif'),
            'pie_recipe.direccion' => 'sometimes|' . LimitesDelMembrete::regla('linea_1'),
            'pie_recipe.telefono' => 'sometimes|' . LimitesDelMembrete::regla('linea_2'),
            'pie_recipe.correo' => 'sometimes|' . LimitesDelMembrete::regla('linea_3'),
            'pie_informe.direccion' => 'sometimes|' . LimitesDelMembrete::regla('lineag_1'),
            'pie_informe.consultorio' => 'sometimes|' . LimitesDelMembrete::regla('lineag_2'),
            'logo' => 'sometimes|nullable|image|max:2048',
            // Plantillas de mensaje (Paso 23): "Plantilla de citas" y "Plantilla de cumpleaños".
            'plantilla_cita' => 'sometimes|' . LimitesDelMembrete::regla('plantilla_cita'),
            'plantilla_cumple' => 'sometimes|' . LimitesDelMembrete::regla('plantilla_cumple'),
        ]);

        // `evolucion` no tiene `medico_id`: la clave de negocio es `reg_medico`, igual que en el
        // resto del backend (ver MedicAPI/AGENTS.md). Un médico con varios registros (`MedicoRegistro`)
        // guarda su reporte bajo el registro "principal" (ver `Medico::regMedicoPrincipal`).
        if (!$medico->regMedicoPrincipal()) {
            return response()->json([
                'message' => 'Esta cuenta no tiene un número de registro médico principal configurado.',
            ], 422);
        }

        // Actualización parcial: sólo viaja a la Action lo que la petición trae.
        $campos = [];
        foreach (['especialidad', 'ciudad', 'rif', 'reg_medico', 'plantilla_cita', 'plantilla_cumple'] as $campo) {
            if ($request->has($campo)) {
                $campos[$campo] = $datos[$campo] ?? null;
            }
        }
        foreach ([
            'pie_recipe.direccion' => 'linea_1',
            'pie_recipe.telefono' => 'linea_2',
            'pie_recipe.correo' => 'linea_3',
            'pie_informe.direccion' => 'lineag_1',
            'pie_informe.consultorio' => 'lineag_2',
        ] as $clave => $columna) {
            if ($request->has($clave)) {
                $campos[$columna] = data_get($datos, $clave);
            }
        }

        $resultado = $guardar->ejecutar(
            $medico,
            $campos,
            $request->hasFile('logo') ? $request->file('logo') : null,
        );

        return new ConfiguracionMedicoResource($resultado->evolucion);
    }
}
