<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **Las especialidades del médico viajan al app** (2026-10-29).
 *
 * El teléfono decide con ellas qué módulos de la consulta ofrece: sin esto mostraba los ecos y todo lo
 * ginecológico a cualquier médico, incluido un pediatra. Los `slug` son los mismos del pivote
 * `medico_specialty` que usa el contexto de la web, así que no hay una segunda verdad que se pueda
 * desincronizar.
 */
class SyncEspecialidadesDelAppTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-especialidad-' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctor de prueba',
            'email' => 'especialidad-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctor',
            'lastname' => 'De Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    private function especialidad(string $slug, string $nombre): Specialty
    {
        return Specialty::firstOrCreate(
            ['slug' => $slug],
            ['name' => $nombre, 'activo' => true],
        );
    }

    /** El sync de siempre, sin cambios pendientes: acá sólo importa lo que devuelve. */
    private function sincronizar(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/app/sync-app-data', ['changes' => []])->assertOk();
    }

    public function test_el_sync_manda_la_especialidad_del_medico(): void
    {
        $medico = $this->medico();
        $ginecologia = $this->especialidad('ginecologia-y-obstetricia', 'Ginecología y obstetricia');
        $medico->specialties()->sync([$ginecologia->id]);

        $this->sincronizar()->assertJsonPath('especialidades', ['ginecologia-y-obstetricia']);
    }

    public function test_un_medico_con_dos_especialidades_las_manda_las_dos(): void
    {
        $medico = $this->medico();
        $ginecologia = $this->especialidad('ginecologia-y-obstetricia', 'Ginecología y obstetricia');
        $pediatria = $this->especialidad('pediatria', 'Pediatría');
        $medico->specialties()->sync([$ginecologia->id, $pediatria->id]);

        $respuesta = $this->sincronizar()->assertOk();

        $especialidades = $respuesta->json('especialidades');
        sort($especialidades);
        $this->assertSame(['ginecologia-y-obstetricia', 'pediatria'], $especialidades);
    }

    public function test_un_medico_sin_especialidad_no_deduce_ninguna(): void
    {
        $this->medico();

        // Vacío, no ginecología: el teléfono muestra el núcleo general y no los ecos de otra especialidad.
        $this->sincronizar()->assertJsonPath('especialidades', []);
    }
}
