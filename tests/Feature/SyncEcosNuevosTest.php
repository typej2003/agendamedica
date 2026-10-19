<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\EcoCardiogramaFetal;
use App\Models\EcoDoppler;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\UltraAbdominal;
use App\Models\UltraMama;
use App\Models\User;
use App\Sync\Escritorio\TablasLegado;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **A6b de PLAN-MOVIL.md §10: que los otros cuatro estudios ecográficos bajen al teléfono.**
 *
 * Son el **Doppler perinatal**, el **mamario**, el **abdominal** y el **ecocardiograma fetal**, con sus
 * tablas legadas:
 *
 * | estudio              | tabla                 |
 * |----------------------|-----------------------|
 * | Doppler perinatal    | `eco_doppler`         |
 * | mamario              | `ultra_mama`          |
 * | abdominal            | `ultra_abdominal`     |
 * | ecocardiograma fetal | `ecocadiograma_fetal` |
 *
 * ⚠️ Las cuatro se llavean por **`historia`** + `consulta` (como los obstétricos, no como el pélvico), así
 * que sin su entrada en `TablasLegado::COLUMNA_HISTORIA` el filtro pregunta por `numhistoria` —que no
 * existe— y no baja nada (la trampa 122).
 *
 * ⚠️ La tabla del ecocardiograma se llama **`ecocadiograma_fetal`**, con el typo del legado: es la que usa
 * la web y la que sube el escritorio. Escribirla «bien» deja el estudio sin bajar.
 *
 * Se prueban los cuatro caminos del sync (delta, volcado, paginado con la ventana y pedido de una ficha) y
 * el aislamiento entre médicos, igual que `SyncEcosTest` con los cuatro gineco-obstétricos.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncEcosNuevosTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-ecos2-' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'ecos2-' . substr(uniqid(), -6) . '@example.com',
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
     * Una fila de cada uno de los cuatro estudios, con su clave del legado y una columna propia por tabla
     * (`texto` del Doppler, `conclusion` del mamario, `conclusiones` del abdominal y `texto_inicio` del
     * ecocardiograma): así el test comprueba que viaja la fila de **esa** tabla y no otra.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Model>
     */
    private function ecos(Medico $medico, Consulta $consulta): array
    {
        $comun = [
            'reg_medico' => $medico->reg_medico,
            'historia' => $consulta->numhistoria,
            'consulta' => $consulta->nroconsulta,
            'fecha' => now()->toDateString(),
        ];

        return [
            'eco_doppler' => EcoDoppler::create($comun + ['texto' => 'DOPPLER NORMAL']),
            'ultra_mama' => UltraMama::create($comun + ['conclusion' => 'MAMAS SIN LESIONES']),
            'ultra_abdominal' => UltraAbdominal::create($comun + ['conclusiones' => 'ABDOMEN NORMAL']),
            'ecocadiograma_fetal' => EcoCardiogramaFetal::create($comun + ['texto_inicio' => 'CORAZON FETAL NORMAL']),
        ];
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    public function test_los_cuatro_ecos_nuevos_bajan_por_delta_con_sus_columnas(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);

        // El delta se calcula por `updated_at`, así que el `since` tiene que ser anterior a las filas.
        $desde = now()->subMinute()->toIso8601String();
        $ecos = $this->ecos($medico, $consulta);

        $r = $this->sync(['since' => $desde]);

        foreach ($ecos as $clave => $eco) {
            $this->assertCount(1, $r->json($clave), "el estudio {$clave} viaja por delta");
            $this->assertSame($eco->id, $r->json("{$clave}.0.id"), "viaja la fila de {$clave}");
            // La clave del legado: `historia` + `consulta` (el número de la consulta, no su id).
            $this->assertSame($consulta->numhistoria, $r->json("{$clave}.0.historia"));
            $this->assertSame($consulta->nroconsulta, $r->json("{$clave}.0.consulta"));
            $this->assertNotSame($consulta->id, $r->json("{$clave}.0.consulta"));
        }

        // Y el valor clínico propio de cada tabla.
        $this->assertSame('DOPPLER NORMAL', $r->json('eco_doppler.0.texto'));
        $this->assertSame('MAMAS SIN LESIONES', $r->json('ultra_mama.0.conclusion'));
        $this->assertSame('ABDOMEN NORMAL', $r->json('ultra_abdominal.0.conclusiones'));
        $this->assertSame('CORAZON FETAL NORMAL', $r->json('ecocadiograma_fetal.0.texto_inicio'));
    }

    public function test_no_bajan_los_ecos_de_otro_medico_con_el_mismo_numero_de_historia(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $propios = $this->ecos($medico, $consulta);

        $ajeno = 'otro-registro' . substr(uniqid(), -6);
        $comun = [
            'reg_medico' => $ajeno,
            'historia' => $consulta->numhistoria,
            'consulta' => $consulta->nroconsulta,
            'fecha' => now()->toDateString(),
        ];
        EcoDoppler::create($comun + ['texto' => 'DEL OTRO MEDICO']);
        UltraMama::create($comun + ['conclusion' => 'DEL OTRO MEDICO']);
        UltraAbdominal::create($comun + ['conclusiones' => 'DEL OTRO MEDICO']);
        EcoCardiogramaFetal::create($comun + ['texto_inicio' => 'DEL OTRO MEDICO']);

        $r = $this->sync();

        foreach ($propios as $clave => $eco) {
            $this->assertCount(1, $r->json($clave), "sólo el estudio propio de {$clave}");
            $this->assertSame($eco->id, $r->json("{$clave}.0.id"));
        }
        $this->assertNotContains('DEL OTRO MEDICO', array_column($r->json('eco_doppler'), 'texto'));
        $this->assertNotContains('DEL OTRO MEDICO', array_column($r->json('ultra_mama'), 'conclusion'));
        $this->assertNotContains('DEL OTRO MEDICO', array_column($r->json('ultra_abdominal'), 'conclusiones'));
        $this->assertNotContains('DEL OTRO MEDICO', array_column($r->json('ecocadiograma_fetal'), 'texto_inicio'));
    }

    public function test_el_volcado_completo_tambien_los_trae(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $ecos = $this->ecos($medico, $consulta);

        // Sin `since` es la primera sincronización (o la descarga completa del app nuevo).
        $r = $this->sync();

        foreach ($ecos as $clave => $eco) {
            $this->assertCount(1, $r->json($clave), "el estudio {$clave} baja en el volcado completo");
            $this->assertSame($eco->id, $r->json("{$clave}.0.id"));
        }
    }

    public function test_el_volcado_paginado_los_incluye_con_la_ventana_aplicada(): void
    {
        $medico = $this->medico();

        // Paciente de la ventana: tiene una cita de ayer.
        $enLaVentana = $this->consulta($medico);
        $ecos = $this->ecos($medico, $enLaVentana);
        DB::table('cola')->insert([
            'reg_medico' => $medico->reg_medico,
            'fecha' => now()->subDay()->toDateString(),
            'hora_ini' => '08:00',
            'numhistoria' => $enLaVentana->numhistoria,
            'atendido' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Paciente de afuera: tiene sus estudios pero ninguna cita en la ventana.
        $fuera = $this->consulta($medico);
        $ecosFuera = $this->ecos($medico, $fuera);

        $vistos = ['eco_doppler' => [], 'ultra_mama' => [], 'ultra_abdominal' => [], 'ecocadiograma_fetal' => []];
        $cursor = 'inicio';
        $paginas = 0;
        while ($cursor !== null && $paginas < 200) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 1])->json();

            // La clave tiene que venir siempre, aunque en este tramo no toque: el app recorre una lista
            // fija de tablas y no puede distinguir «vacía» de «no implementada».
            foreach (array_keys($vistos) as $clave) {
                $this->assertArrayHasKey($clave, $cuerpo);
                foreach ($cuerpo[$clave] as $fila) {
                    $vistos[$clave][] = $fila;
                }
            }
            $cursor = $cuerpo['volcado_siguiente'];
            $paginas++;
        }

        $this->assertLessThan(200, $paginas, 'el volcado tiene que terminar');
        foreach ($ecos as $clave => $eco) {
            $this->assertCount(1, $vistos[$clave], "el estudio {$clave} entra en uno de los tramos");
            $this->assertSame($eco->id, $vistos[$clave][0]['id']);
        }
        foreach ($ecosFuera as $clave => $eco) {
            $this->assertNotContains($eco->id, array_column($vistos[$clave], 'id'), "{$clave} del paciente fuera de la ventana no baja");
        }
    }

    public function test_el_pedido_de_una_ficha_fuera_de_la_ventana_trae_sus_ecos(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $ecos = $this->ecos($medico, $consulta);

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

        foreach ($ecos as $clave => $eco) {
            $this->assertCount(1, $cuerpo[$clave], "la ficha fuera de la ventana trae su {$clave}");
            $this->assertSame($eco->id, $cuerpo[$clave][0]['id']);
        }
    }

    /**
     * **La trampa del paso, fijada**: las cuatro tablas se filtran por `historia` (no `numhistoria`) y la
     * del ecocardiograma se llama con el typo del legado.
     */
    public function test_las_tablas_y_sus_columnas_de_historia_son_las_del_legado(): void
    {
        $tablas = [
            'eco_doppler' => 'eco_doppler',
            'ultra_mama' => 'ultra_mama',
            'ultra_abdominal' => 'ultra_abdominal',
            'ecocadiograma_fetal' => 'ecocadiograma_fetal',
        ];

        foreach ($tablas as $tabla) {
            $this->assertSame('historia', TablasLegado::columnaHistoria($tabla), $tabla);
            $this->assertTrue(Schema::hasTable($tabla), "la tabla {$tabla} existe");
            $this->assertTrue(Schema::hasColumn($tabla, 'historia'), "{$tabla}.historia existe");
            $this->assertTrue(Schema::hasColumn($tabla, 'consulta'), "{$tabla}.consulta existe");
            $this->assertTrue(Schema::hasColumn($tabla, 'fecha'), "{$tabla}.fecha existe");
            // La trampa: `numhistoria` NO existe en estas tablas, así que un filtro que la use no
            // devuelve nada.
            $this->assertFalse(Schema::hasColumn($tabla, 'numhistoria'), "{$tabla} no tiene `numhistoria`");
        }

        // El typo del legado, comprobado en las dos direcciones: la tabla real existe y la «bien escrita» no.
        $this->assertTrue(Schema::hasTable('ecocadiograma_fetal'));
        $this->assertFalse(Schema::hasTable('ecocardiograma_fetal'), 'la tabla NO se llama así en el legado');
    }
}
