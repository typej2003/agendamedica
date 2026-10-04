<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Muestra el dashboard de la aplicación según el rol asignado al usuario.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        $user = auth()->user();
        
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