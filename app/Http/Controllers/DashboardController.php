<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Muestra el dashboard de la aplicación.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        // 1. Obtenemos el nombre del primer rol del usuario.
        $roleName = auth()->user()->getRoleNames()->first();
        $dashboardComponent = '';

        // 2. Decidimos qué componente cargar según el nombre del rol.
        switch ($roleName) {
            case 'Root': // Este es el rol que equivale a 'root' en tu sistema
                $dashboardComponent = 'dashboard.root-dashboard';
                break;
            case 'Administrador': // Este es el rol que equivale a 'root' en tu sistema
                $dashboardComponent = 'dashboard.admin-dashboard';
                break;
            // case 'Ventas':
            //     $dashboardComponent = 'dashboard.sales-dashboard';
            //     break;
            default:
                $dashboardComponent = 'dashboard.default-dashboard'; // Un dashboard para roles sin vista específica.
        }

        return view('dashboard-loader', ['component' => $dashboardComponent]);
    }
}