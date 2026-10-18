<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\RecipePareja;
use App\Models\User;
use App\Sync\Escritorio\TablasLegado;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **A5a de PLAN-MOVIL.md §9: que el récipe de la pareja baje al teléfono.**
 *
 * `recipes_pareja` es la tabla legada del récipe que el médico le hace a la pareja del paciente
 * (WEB-5.1b). No tiene cabecera ni columna `recipe`: **una por consulta**, una fila por medicamento, y la
 * cédula y el nombre de la pareja se **repiten en todas** las filas.
 *
 * Lo que fija este test, por los cuatro caminos del sync:
 *
 *  1. **Delta** (`since`).
 *  2. **Volcado completo** (sin `since`).
 *  3. **Volcado paginado** (con la ventana de 60 días, medida por la cita).
 *  4. **Pedido puntual de una ficha** (`volcado_paciente`), que es la red de seguridad de la ventana.
 *
 * Y dos cosas que no son obvias:
 *
 * ⚠️ **La columna del número de historia es `nrohistoria`**, no `numhistoria`. `TablasLegado` devuelve
 * `numhistoria` por defecto, así que sin su entrada explícita el filtro pregunta por una columna que esta
 * tabla no tiene y **no baja nada** (es la trampa 122 de `TRAMPAS.md`, la de los 49.955 récipes).
 *
 * ⚠️ **No baja la pareja de otro `reg_medico`** con el mismo número de historia: el número es por médico.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncRecipeParejaTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-pareja-' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'pareja-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);
        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
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

    /** Un paciente del médico con su historia y una consulta, con un número libre (no el sembrado). */
    private function consulta(Medico $medico, ?int $numHistoria = null): Consulta
    {
        $numHistoria = $numHistoria ?? random_int(900000000, 999999999);

        $paciente = Paciente::create([
            'cedula' => (string) random_int(1000000, 99999999),
            'nombres' => 'Paciente',
            'apellidos' => 'De Prueba',
        ]);
        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => (string) $numHistoria,
        ]);
        Historia::create([
            'numhistoria' => (string) $numHistoria,
            'reg_medico' => $medico->reg_medico,
            'paciente_id' => $paciente->id,
            'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numHistoria,
            'nroconsulta' => 1,
        ]);
    }

    /**
     * La pareja de la consulta: dos medicamentos, con la cédula y el nombre **repetidos en las dos filas**
     * —así las escribe la Action de la web— y el `orden` desde 1.
     *
     * @return array<int,RecipePareja>
     */
    private function pareja(Medico $medico, Consulta $consulta, string $cedula = '12345678', string $nombre = 'Pareja De Prueba'): array
    {
        $comun = [
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'cedula' => $cedula,
            'nombre' => $nombre,
        ];

        return [
            RecipePareja::create($comun + [
                'codemedicina' => 'MED001',
                'indicaciones' => 'Una tableta cada 12 horas',
                'cantidad' => 10,
                'descripcion' => 'MEDICAMENTO UNO',
                'orden' => 1,
            ]),
            RecipePareja::create($comun + [
                'codemedicina' => 'MED002',
                'indicaciones' => 'Una tableta diaria',
                'cantidad' => 5,
                'descripcion' => 'MEDICAMENTO DOS',
                'orden' => 2,
            ]),
        ];
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    public function test_la_pareja_baja_por_delta_con_sus_dos_medicamentos(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // El delta se calcula por `updated_at`, así que el `since` tiene que ser anterior a las filas.
        $desde = now()->subMinute()->toIso8601String();
        $filas = $this->pareja($medico, $consulta);

        $r = $this->sync(['since' => $desde]);

        $this->assertCount(2, $r->json('recipes_pareja'), 'la pareja viaja con sus dos medicamentos');
        $this->assertSame(
            array_column(array_map(fn ($f) => $f->toArray(), $filas), 'id'),
            array_column($r->json('recipes_pareja'), 'id'),
        );

        // Los datos de la pareja, repetidos en las dos filas, y la clave del legado.
        foreach ($r->json('recipes_pareja') as $fila) {
            $this->assertSame('12345678', $fila['cedula']);
            $this->assertSame('Pareja De Prueba', $fila['nombre']);
            $this->assertSame($consulta->numhistoria, $fila['nrohistoria']);
            $this->assertSame($consulta->nroconsulta, $fila['nroconsulta']);
        }

        // Y lo propio de cada medicamento.
        $porCodigo = collect($r->json('recipes_pareja'))->keyBy('codemedicina');
        $this->assertSame('Una tableta cada 12 horas', $porCodigo['MED001']['indicaciones']);
        $this->assertSame(10, $porCodigo['MED001']['cantidad']);
        $this->assertSame(5, $porCodigo['MED002']['cantidad']);
        $this->assertSame(2, $porCodigo['MED002']['orden']);
    }

    public function test_no_baja_la_pareja_de_otro_medico_con_el_mismo_numero_de_historia(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $propias = $this->pareja($medico, $consulta);

        // El número de historia es **por médico**: esta fila tiene el mismo número y el mismo número de
        // consulta, y aun así no puede bajar a este teléfono.
        RecipePareja::create([
            'reg_medico' => 'otro-registro' . substr(uniqid(), -6),
            'nrohistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'codemedicina' => 'AJENO',
            'cedula' => '99999999',
            'nombre' => 'LA DEL OTRO MEDICO',
            'orden' => 1,
        ]);

        $r = $this->sync();

        $this->assertCount(2, $r->json('recipes_pareja'), 'sólo las filas propias');
        $this->assertSame(array_column(array_map(fn ($f) => $f->toArray(), $propias), 'id'), array_column($r->json('recipes_pareja'), 'id'));
        $this->assertNotContains(
            'LA DEL OTRO MEDICO',
            array_column($r->json('recipes_pareja'), 'nombre'),
            'la pareja del otro médico no se cuela con el mismo número de historia',
        );
    }

    public function test_el_volcado_completo_tambien_la_trae(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->pareja($medico, $consulta);

        // Sin `since` es la primera sincronización (o la descarga completa del app nuevo).
        $r = $this->sync();

        $this->assertCount(2, $r->json('recipes_pareja'), 'la pareja baja en el volcado completo');
        $this->assertSame('12345678', $r->json('recipes_pareja.0.cedula'));
        $this->assertSame('Pareja De Prueba', $r->json('recipes_pareja.0.nombre'));
    }

    /**
     * **El volcado paginado** aplica la ventana (`DIAS_DE_VENTANA`): lo clínico sólo viaja para los
     * pacientes que se atienden en esos días, y "se atiende" se mide por la cita (`cola.fecha`).
     */
    public function test_el_volcado_paginado_la_incluye_con_la_ventana_aplicada(): void
    {
        $medico = $this->medico();

        // Paciente de la ventana: tiene una cita de ayer.
        $enLaVentana = $this->consulta($medico);
        $this->pareja($medico, $enLaVentana);
        DB::table('cola')->insert([
            'reg_medico' => $medico->reg_medico,
            'fecha' => now()->subDay()->toDateString(),
            'hora_ini' => '08:00',
            'numhistoria' => $enLaVentana->numhistoria,
            'atendido' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Paciente de afuera: tiene su pareja pero ninguna cita en la ventana.
        $fuera = $this->consulta($medico);
        $this->pareja($medico, $fuera, '87654321', 'LA DE AFUERA');

        $vistos = [];
        $cursor = 'inicio';
        $paginas = 0;
        while ($cursor !== null && $paginas < 200) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 1])->json();
            // La clave tiene que venir siempre, aunque en este tramo no toque: el app recorre una lista
            // fija de tablas y no puede distinguir "vacía" de "no implementada".
            $this->assertArrayHasKey('recipes_pareja', $cuerpo);
            $vistos = array_merge($vistos, $cuerpo['recipes_pareja']);
            $cursor = $cuerpo['volcado_siguiente'];
            $paginas++;
        }

        $this->assertLessThan(200, $paginas, 'el volcado tiene que terminar');
        $this->assertCount(2, $vistos, 'sólo la pareja del paciente de la ventana');
        $this->assertSame(['12345678'], array_values(array_unique(array_column($vistos, 'cedula'))));
    }

    /**
     * **El pedido puntual de una ficha** (`volcado_paciente`) es la red de seguridad de la ventana: cuando
     * el médico abre un paciente que hace meses que no se atiende, llega también su récipe de la pareja.
     */
    public function test_el_pedido_de_una_ficha_fuera_de_la_ventana_trae_la_pareja(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $this->pareja($medico, $consulta);

        // La última cita fue hace 200 días: el volcado paginado no trajo nada de este paciente.
        DB::table('cola')->insert([
            'reg_medico' => $medico->reg_medico,
            'fecha' => now()->subDays(200)->toDateString(),
            'hora_ini' => '08:00',
            'numhistoria' => $consulta->numhistoria,
            'atendido' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cuerpo = $this->sync(['volcado_paciente' => $consulta->numhistoria])->json();

        $this->assertCount(2, $cuerpo['recipes_pareja'], 'la ficha fuera de la ventana trae su pareja');
        $this->assertSame('Pareja De Prueba', $cuerpo['recipes_pareja'][0]['nombre']);
    }

    /**
     * **La trampa del paso, fijada**: la columna del número de historia es `nrohistoria`.
     *
     * Se comprueba en el mapa de `TablasLegado` (la fuente única que consulta el filtro) y en el esquema
     * real: si alguien la borra del mapa, el default `numhistoria` haría que el filtro pregunte por una
     * columna que no existe y la pareja no bajaría nunca.
     */
    public function test_la_columna_de_historia_es_nrohistoria(): void
    {
        $this->assertSame('nrohistoria', TablasLegado::columnaHistoria('recipes_pareja'));
        $this->assertTrue(Schema::hasColumn('recipes_pareja', 'nrohistoria'));
        $this->assertTrue(Schema::hasColumn('recipes_pareja', 'nroconsulta'));
        $this->assertFalse(Schema::hasColumn('recipes_pareja', 'numhistoria'), 'la tabla no tiene `numhistoria`');
        // Y la de la consulta: el número dentro de la historia, no el id.
        $this->assertFalse(Schema::hasColumn('recipes_pareja', 'consulta'), 'la tabla no tiene `consulta`');
        $this->assertFalse(Schema::hasColumn('recipes_pareja', 'recipe'), 'la pareja no lleva número de récipe');
    }
}
