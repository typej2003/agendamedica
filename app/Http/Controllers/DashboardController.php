<?php

namespace App\Http\Controllers;

/**
 * Panel de administración (`/dashboard`).
 *
 * Desde el 2026-10-08 el panel es de administración: el médico y la secretaría trabajan en `/clinica`
 * (a donde además los manda el login) y si llegan acá se los redirige a su consultorio. El paciente
 * sigue entrando mientras no exista `/consultorio`.
 */
class DashboardController extends Controller
{
    /**
     * Muestra el dashboard de la aplicación según el rol asignado al usuario.
     *
     * @return \Illuminate\Contracts\Support\Renderable|\Illuminate\Http\RedirectResponse
     */
    public function index()
    {
        $user = auth()->user();

        // Quien trabaja en el consultorio no tiene nada que hacer en el panel. La secretaría entra en
        // esta redirección porque su componente de dashboard (`dashboard.secretaria-dashboard`) nunca
        // existió: mandarla a `/clinica` es preferible a mostrarle un error.
        if ($user->rutaDeInicio() !== 'dashboard') {
            return redirect()->route($user->rutaDeInicio());
        }

        // Evaluamos los roles utilizando el helper de Spatie
        if ($user->hasRole('Root')) {
            $dashboardComponent = 'dashboard.root-dashboard';
        } elseif ($user->hasRole('Administrador')) {
            $dashboardComponent = 'dashboard.admin-dashboard';
        } elseif ($user->hasRole('Medico')) {
            $dashboardComponent = 'dashboard.medico-dashboard';
        } elseif ($user->hasRole('Secretaria')) {
            $dashboardComponent = 'dashboard.secretaria-dashboard';
        } elseif ($user->hasRole('Paciente')) {
            $dashboardComponent = 'dashboard.paciente-dashboard';
        } elseif ($user->hasRole('Representante')) {
            $dashboardComponent = 'dashboard.representante-dashboard';
        } else {
            $dashboardComponent = 'dashboard.default-dashboard';
        }

        return view('dashboard-loader', ['component' => $dashboardComponent]);
    }
}
