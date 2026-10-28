<?php

namespace App\Http\Controllers\Api;

use App\Clinica\FechaDelConsultorio;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * **Quiénes están conectados ahora**, para una herramienta externa (el servidor MCP propio) que necesita
 * saberlo antes de desplegar a producción: `GET /api/estado/usuarios-en-linea`.
 *
 * No inventa una señal nueva: lee **la misma** que el panel de Root
 * (`App\Http\Livewire\Dashboard\RootDashboard`) — las claves `user-is-online-<id>` que
 * `App\Http\Middleware\UserOnlineMiddleware` deja en caché con 5 minutos de vida en cada petición web
 * autenticada. Por eso el endpoint **no escribe nada** (consultarlo no marca a nadie como conectado) y no
 * agrega consultas nuevas ni tablas: lee los usuarios y filtra con esa caché, en el mismo orden (por
 * nombre) que devuelve el contrato.
 *
 * Sólo el **Root** puede verlo: quién está conectado es información de administración. La comprobación va
 * acá y no en el middleware `role:` porque en las rutas del API el guard por defecto es el que fija
 * `auth:api` (`api`), mientras que los roles del proyecto viven en el guard `web`; así el guard se pasa
 * **explícito** y no depende de cuál sea el guard por defecto en cada request.
 */
class UsuariosEnLineaController extends Controller
{
    /**
     * La ventana de inactividad, en minutos: **la misma** de `UserOnlineMiddleware`
     * (`now()->addMinutes(5)` en `app/Http/Middleware/UserOnlineMiddleware.php:15`). Si cambia una, tiene
     * que cambiar la otra: es el número con el que la herramienta externa interpreta «en línea», y una
     * ventana que no coincida con la caché diría que alguien está conectado cuando su marca ya venció (o al
     * revés). No se lee de la caché porque es una política, no un dato de cada usuario.
     */
    public const VENTANA_MINUTOS = 5;

    /** La lista de los conectados, con sus roles y la ventana usada. */
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasRole('Root', 'web'),
            403,
            'Sólo el administrador puede ver quién está conectado.'
        );

        // Los usuarios se leen una vez y se filtran en memoria con la caché, igual que el panel. `with('roles')`
        // evita una consulta por usuario al armar el JSON; no hay ninguna consulta nueva sobre otra tabla.
        $usuarios = User::query()
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => Cache::has('user-is-online-' . $user->id))
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                // Sólo el nombre de cada rol: ni ids ni el pivote de Spatie.
                'roles' => $user->getRoleNames()->sort()->values()->all(),
            ])
            ->values();

        return response()->json([
            // La hora del consultorio (UTC-4), no la del servidor: `config('app.timezone')` es UTC y no se toca.
            'generado_en' => FechaDelConsultorio::ahora()->toIso8601String(),
            'ventana_minutos' => self::VENTANA_MINUTOS,
            'total' => $usuarios->count(),
            'usuarios' => $usuarios->all(),
        ]);
    }
}
