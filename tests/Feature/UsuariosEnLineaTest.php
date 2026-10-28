<?php

namespace Tests\Feature;

use App\Clinica\FechaDelConsultorio;
use App\Http\Controllers\Api\UsuariosEnLineaController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * **`GET /api/estado/usuarios-en-linea`** (2026-10-28): quiénes están conectados ahora, para el servidor MCP
 * propio antes de desplegar a producción.
 *
 * Lo que fijan estos casos:
 *
 *  1. La puerta: sin token **401**, con token de un médico **403** y sólo el **Root** ve la lista.
 *  2. La señal: son los usuarios con `user-is-online-<id>` en caché —la misma marca de
 *     `UserOnlineMiddleware` que cuenta el panel de Root—, ni más ni menos.
 *  3. El contrato: `generado_en` (hora del consultorio), `ventana_minutos`, `total` y `usuarios` con `id`,
 *     `name`, `email` y `roles`, ordenados por nombre; nada de `password`, `remember_token` ni relaciones.
 *
 * `DatabaseTransactions` como el resto de los tests de API. La caché de la suite es `array` (ver
 * `phpunit.xml`) y no la revierte el rollback, así que cada test la vacía en `setUp()`.
 */
class UsuariosEnLineaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // La caché `array` vive lo que vive el proceso: sin esto, una marca de un test anterior seguiría
        // contando en el siguiente (los tests de un archivo comparten proceso).
        Cache::flush();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** Una cuenta con un rol (opcional) del guard `web`, que es donde viven los roles del proyecto. */
    private function usuario(string $nombre, ?string $rol = null): User
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'en-linea-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        if ($rol !== null) {
            // El guard va **explícito**: `Sanctum::actingAs()` deja el guard por defecto en `api` y
            // `assignRole('Root')` buscaría el rol en ese guard, donde no existe (trampa 149).
            $user->assignRole(Role::findByName($rol, 'web'));
        }

        return $user;
    }

    /** La marca que deja `UserOnlineMiddleware` en cada petición web autenticada: 5 minutos de vida. */
    private function marcarEnLinea(User $user): void
    {
        Cache::put('user-is-online-' . $user->id, true, now()->addMinutes(5));
    }

    // --------------------------------------------------------------------------------------- Tests

    public function test_sin_token_responde_401(): void
    {
        $this->getJson('/api/estado/usuarios-en-linea')->assertUnauthorized();
    }

    public function test_un_medico_recibe_403(): void
    {
        $medico = $this->usuario('Médico de prueba', 'Medico');
        $this->marcarEnLinea($medico);

        Sanctum::actingAs($medico, ['*'], 'api');

        $this->getJson('/api/estado/usuarios-en-linea')
            ->assertForbidden()
            ->assertJson(['message' => 'Sólo el administrador puede ver quién está conectado.']);
    }

    public function test_un_root_recibe_la_lista_de_los_conectados(): void
    {
        $root = $this->usuario('Ana Root', 'Root');
        $medico = $this->usuario('Beto Médico', 'Medico');
        $this->marcarEnLinea($root);
        $this->marcarEnLinea($medico);

        Sanctum::actingAs($root, ['*'], 'api');

        $response = $this->getJson('/api/estado/usuarios-en-linea')->assertOk();

        $response->assertJsonStructure([
            'generado_en',
            'ventana_minutos',
            'total',
            'usuarios' => [['id', 'name', 'email', 'roles']],
        ]);

        $json = $response->json();

        // La ventana es la misma constante del controlador y el valor que comparte el middleware.
        $this->assertSame(UsuariosEnLineaController::VENTANA_MINUTOS, $json['ventana_minutos']);
        $this->assertSame(5, $json['ventana_minutos']);

        // Los dos marcados, en el orden por nombre del contrato, con sus roles como texto.
        $this->assertSame(2, $json['total']);
        $this->assertSame([$root->id, $medico->id], array_column($json['usuarios'], 'id'));
        $this->assertSame(['Ana Root', 'Beto Médico'], array_column($json['usuarios'], 'name'));
        $this->assertSame([$root->email, $medico->email], array_column($json['usuarios'], 'email'));
        $this->assertSame([['Root'], ['Medico']], array_column($json['usuarios'], 'roles'));

        // `generado_en` es ISO 8601 con la hora del consultorio (el ejemplo del contrato trae -04:00).
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $json['generado_en']
        );
        // Carbon 3 identifica el offset (`-04:00`) y no el nombre de la zona: se compara el desplazamiento.
        $this->assertSame(
            FechaDelConsultorio::ahora()->format('P'),
            Carbon::parse($json['generado_en'])->format('P')
        );
    }

    public function test_solo_aparecen_los_usuarios_con_la_marca_en_cache(): void
    {
        $root = $this->usuario('Ana Root', 'Root');
        $medico = $this->usuario('Beto Médico', 'Medico');
        $secretaria = $this->usuario('Carla Secretaria', 'Secretaria');

        // La secretaria queda afuera a propósito: no tiene la marca.
        $this->marcarEnLinea($root);
        $this->marcarEnLinea($medico);

        Sanctum::actingAs($root, ['*'], 'api');

        $json = $this->getJson('/api/estado/usuarios-en-linea')->assertOk()->json();

        $this->assertSame(2, $json['total']);
        $this->assertSame([$root->id, $medico->id], array_column($json['usuarios'], 'id'));
        $this->assertNotContains($secretaria->id, array_column($json['usuarios'], 'id'));
    }

    public function test_el_json_no_incluye_datos_de_mas(): void
    {
        $root = $this->usuario('Ana Root', 'Root');
        $this->marcarEnLinea($root);

        Sanctum::actingAs($root, ['*'], 'api');

        $response = $this->getJson('/api/estado/usuarios-en-linea')->assertOk();
        $json = $response->json();

        // Sólo las claves del contrato, ni una más.
        $this->assertSame(['generado_en', 'ventana_minutos', 'total', 'usuarios'], array_keys($json));
        $this->assertSame(['id', 'name', 'email', 'roles'], array_keys($json['usuarios'][0]));
        $this->assertSame(['Root'], $json['usuarios'][0]['roles']);

        $contenido = $response->getContent();
        $this->assertStringNotContainsString('password', $contenido);
        $this->assertStringNotContainsString('remember_token', $contenido);
        $this->assertStringNotContainsString('email_verified_at', $contenido);
        $this->assertStringNotContainsString('created_at', $contenido);
    }
}
