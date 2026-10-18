<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\EcoObstetrico;
use App\Models\EcoObstetricoSegundo;
use App\Models\EcoObstetricoTercer;
use App\Models\EcoPelvico;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\User;
use App\Sync\Escritorio\TablasLegado;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **EC-A2 de PLAN-MOVIL.md (§7 bis): que los cuatro estudios ecográficos bajen al teléfono.**
 *
 * El paso EC-A1 ya exportó los mapas de los cuatro ecos para el app; acá se hace que el **servidor** se los
 * mande por los cuatro caminos del sync:
 *
 *  1. **Delta** (`since`), con sus columnas clínicas.
 *  2. **Volcado completo** (sin `since`).
 *  3. **Volcado paginado** (con la ventana: sólo la clínica de los pacientes que se atienden en los
 *     últimos 60 días).
 *  4. **Pedido puntual de una ficha** (`volcado_paciente`), que es la red de seguridad de la ventana: un
 *     paciente que hace meses que no se atiende igual llega con sus ecos cuando el médico abre su ficha.
 *
 * Y el filtro de siempre: **no** baja la fila de otro `reg_medico` con el mismo número de historia (el
 * número es por médico, trampa del Paso 18.B).
 *
 * ⚠️ **La trampa de este paso son los nombres de las columnas**, y por eso los tests los nombran a mano:
 *
 * | tabla                     | historia     | consulta      |
 * |---------------------------|--------------|---------------|
 * | `eco_pelvico`             | `numhistoria`| `nroconsulta` |
 * | `eco_obstetrico`          | `historia`   | `consulta`    |
 * | `eco_obstetrico_tercer_2` | `historia`   | `consulta`    |
 * | `eco_obstetrico_tercer`   | `historia`   | `consulta`    |
 *
 * `TablasLegado::columnaHistoria()` es la fuente única de esa diferencia: sin la entrada en su mapa, el
 * filtro de las obstétricas preguntaría por `numhistoria` (que esas tablas no tienen) y no bajaría nada.
 *
 * `DatabaseTransactions`: los tests no limpian la base (ver MedicAPI/AGENTS.md).
 */
class SyncEcosTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(bool $autenticar = true): Medico
    {
        $regMedico = 'test-ecos-' . uniqid();
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'ecos-' . uniqid() . '@example.com',
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

    /**
     * Un paciente del médico con su historia y una consulta. El número es único (se elige uno libre) para
     * no cruzarse con los datos sembrados por `FakeClinicalDataSeeder`.
     */
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
     * Una fila de cada uno de los cuatro estudios, con la clave del legado y una columna clínica distinta
     * por tabla (`vagina`, `eg`, `bio_peso_fetal`, `fun_liquido_amniotico`): así el test comprueba que lo
     * que viaja es la fila de esa tabla y no otra.
     *
     * ⚠️ El pélvico se llavea por `numhistoria`/`nroconsulta` y los obstétricos por `historia`/`consulta`.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Model>
     */
    private function ecos(Medico $medico, Consulta $consulta): array
    {
        $comun = ['reg_medico' => $medico->reg_medico];
        $fecha = now()->toDateString();

        return [
            'eco_pelvico' => EcoPelvico::create($comun + [
                'numhistoria' => $consulta->numhistoria,
                'nroconsulta' => $consulta->nroconsulta,
                'fecha' => $fecha,
                'vagina' => 'NORMAL',
            ]),
            'eco_obstetrico' => EcoObstetrico::create($comun + [
                'historia' => $consulta->numhistoria,
                'consulta' => $consulta->nroconsulta,
                'fecha' => $fecha,
                'eg' => '10 SEMANAS',
            ]),
            'eco_obstetrico_tercer_2' => EcoObstetricoSegundo::create($comun + [
                'historia' => $consulta->numhistoria,
                'consulta' => $consulta->nroconsulta,
                'fecha' => $fecha,
                'bio_peso_fetal' => 1200,
                'fun_liquido_amniotico' => 'N',
            ]),
            'eco_obstetrico_tercer' => EcoObstetricoTercer::create($comun + [
                'historia' => $consulta->numhistoria,
                'consulta' => $consulta->nroconsulta,
                'fecha' => $fecha,
                'bio_peso_fetal' => 3400,
                'fun_liquido_amniotico' => 'D',
            ]),
        ];
    }

    private function sync(array $cuerpo = [])
    {
        return $this->postJson('/api/app/sync-app-data', $cuerpo)->assertOk();
    }

    public function test_los_cuatro_ecos_bajan_por_delta_con_sus_columnas(): void
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
        }

        // Los valores clínicos de cada estudio, por su columna.
        $this->assertSame('NORMAL', $r->json('eco_pelvico.0.vagina'));
        $this->assertSame('10 SEMANAS', $r->json('eco_obstetrico.0.eg'));
        $this->assertSame(1200, $r->json('eco_obstetrico_tercer_2.0.bio_peso_fetal'));
        $this->assertSame('N', $r->json('eco_obstetrico_tercer_2.0.fun_liquido_amniotico'));
        $this->assertSame(3400, $r->json('eco_obstetrico_tercer.0.bio_peso_fetal'));
        $this->assertSame('D', $r->json('eco_obstetrico_tercer.0.fun_liquido_amniotico'));

        // Y cada fila viaja con la clave del legado de **su** tabla: el pélvico con `numhistoria` y
        // `nroconsulta`; los obstétricos con `historia` y `consulta` (el número de la consulta, no su id).
        $this->assertSame($consulta->numhistoria, $r->json('eco_pelvico.0.numhistoria'));
        $this->assertSame($consulta->nroconsulta, $r->json('eco_pelvico.0.nroconsulta'));
        foreach (['eco_obstetrico', 'eco_obstetrico_tercer_2', 'eco_obstetrico_tercer'] as $clave) {
            $this->assertSame($consulta->numhistoria, $r->json("{$clave}.0.historia"));
            $this->assertSame($consulta->nroconsulta, $r->json("{$clave}.0.consulta"));
            $this->assertNotSame($consulta->id, $r->json("{$clave}.0.consulta"));
        }
    }

    public function test_no_bajan_los_ecos_de_otro_medico_con_el_mismo_numero_de_historia(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $propios = $this->ecos($medico, $consulta);

        // El número de historia es **por médico**: estas filas tienen el mismo número y el mismo número de
        // consulta, y aun así no pueden bajar a este teléfono.
        $ajeno = 'otro-registro-' . uniqid();
        EcoPelvico::create([
            'reg_medico' => $ajeno,
            'numhistoria' => $consulta->numhistoria,
            'nroconsulta' => $consulta->nroconsulta,
            'fecha' => now()->toDateString(),
            'vagina' => 'DEL OTRO MEDICO',
        ]);
        foreach ([EcoObstetrico::class, EcoObstetricoSegundo::class, EcoObstetricoTercer::class] as $modelo) {
            $modelo::create([
                'reg_medico' => $ajeno,
                'historia' => $consulta->numhistoria,
                'consulta' => $consulta->nroconsulta,
                'fecha' => now()->toDateString(),
                'eg' => 'DEL OTRO MEDICO',
            ]);
        }

        $r = $this->sync();

        foreach ($propios as $clave => $eco) {
            $this->assertCount(1, $r->json($clave), "sólo el estudio propio de {$clave}");
            $this->assertSame($eco->id, $r->json("{$clave}.0.id"));
        }
        $this->assertNotContains(
            'DEL OTRO MEDICO',
            array_column($r->json('eco_obstetrico'), 'eg'),
            'el eco del otro médico no se cuela con el mismo número de historia',
        );
    }

    public function test_el_volcado_completo_tambien_los_trae(): void
    {
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $ecos = $this->ecos($medico, $consulta);

        // Sin `since` es la primera sincronización (o la descarga completa de un app viejo).
        $r = $this->sync();

        foreach ($ecos as $clave => $eco) {
            $this->assertCount(1, $r->json($clave), "el estudio {$clave} baja en el volcado completo");
            $this->assertSame($eco->id, $r->json("{$clave}.0.id"));
        }
        $this->assertSame('NORMAL', $r->json('eco_pelvico.0.vagina'));
        $this->assertSame(3400, $r->json('eco_obstetrico_tercer.0.bio_peso_fetal'));
    }

    /**
     * **El volcado paginado** aplica la ventana (`DIAS_DE_VENTANA`): lo clínico sólo viaja para los
     * pacientes que se atienden en esos días, y "se atiende" se mide por la cita (`cola.fecha`).
     *
     * Por eso acá hace falta una cita reciente: **sin cita reciente el paciente queda fuera de la ventana**
     * y su estudio no baja (que es lo correcto, y es la razón de que el test monte la cita). El paciente
     * que no se atiende se recupera con `volcado_paciente`, que es el test de más abajo.
     */
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

        // Paciente de afuera: tiene sus ecos pero ninguna cita en la ventana.
        $fuera = $this->consulta($medico);
        $ecosFuera = $this->ecos($medico, $fuera);

        $vistos = ['eco_pelvico' => [], 'eco_obstetrico' => [], 'eco_obstetrico_tercer_2' => [], 'eco_obstetrico_tercer' => []];
        $cursor = 'inicio';
        $paginas = 0;
        while ($cursor !== null && $paginas < 200) {
            $cuerpo = $this->sync(['volcado' => $cursor, 'volcado_limite' => 1])->json();

            // La clave tiene que venir siempre, aunque en este tramo no toque (el app recorre una lista
            // fija de tablas y no puede distinguir "vacía" de "no implementada").
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
        // Y el paciente sin cita reciente quedó afuera: es la ventana, no un olvido.
        foreach ($ecosFuera as $clave => $eco) {
            $this->assertNotContains($eco->id, array_column($vistos[$clave], 'id'), "{$clave} del paciente fuera de la ventana no baja");
        }
    }

    /**
     * **El pedido puntual de una ficha** (`volcado_paciente`) es la red de seguridad de la ventana: cuando
     * el médico abre el historial de un paciente que hace meses que no se atiende, el app pide su historia
     * completa y llegan también sus ecos, sin importar la fecha de la última cita.
     */
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
     * **La trampa del paso, fijada**: el eco pélvico se filtra por `numhistoria`/`nroconsulta` y los tres
     * obstétricos por `historia`/`consulta`.
     *
     * Se comprueba de dos formas: el mapa de `TablasLegado` (la fuente única del nombre de la columna, y lo
     * que consulta el filtro del sync) y el esquema real de las tablas. Si alguien "arregla" el mapa
     * poniendo `numhistoria` en las obstétricas, el filtro del volcado explota contra una columna que no
     * existe y este test lo dice antes.
     */
    public function test_el_eco_pelvico_va_por_numhistoria_y_los_obstetricos_por_historia(): void
    {
        $this->assertSame('numhistoria', TablasLegado::columnaHistoria('eco_pelvico'));
        $this->assertTrue(Schema::hasColumn('eco_pelvico', 'numhistoria'));
        $this->assertTrue(Schema::hasColumn('eco_pelvico', 'nroconsulta'));
        $this->assertFalse(Schema::hasColumn('eco_pelvico', 'historia'), 'el pélvico no tiene `historia`');

        foreach (['eco_obstetrico', 'eco_obstetrico_tercer_2', 'eco_obstetrico_tercer'] as $tabla) {
            $this->assertSame('historia', TablasLegado::columnaHistoria($tabla));
            $this->assertTrue(Schema::hasColumn($tabla, 'historia'), "{$tabla}.historia existe");
            $this->assertTrue(Schema::hasColumn($tabla, 'consulta'), "{$tabla}.consulta existe");
            // La trampa: `numhistoria` NO existe en las obstétricas, así que un filtro que la use no
            // devuelve nada (o rompe la consulta).
            $this->assertFalse(Schema::hasColumn($tabla, 'numhistoria'), "{$tabla} no tiene `numhistoria`");
        }

        // Y el filtro de verdad: una fila de cada estudio del paciente baja, y una obstétrica con **otro**
        // número de historia (puesto en `historia`, la columna que el filtro mira) no baja.
        $medico = $this->medico();
        $consulta = $this->consulta($medico);
        $ecos = $this->ecos($medico, $consulta);
        $ajena = EcoObstetricoTercer::create([
            'reg_medico' => $medico->reg_medico,
            'historia' => $consulta->numhistoria + 1,
            'consulta' => 1,
            'fecha' => now()->toDateString(),
            'bio_peso_fetal' => 9999,
        ]);

        $r = $this->sync();

        foreach ($ecos as $clave => $eco) {
            $this->assertSame($eco->id, $r->json("{$clave}.0.id"));
        }
        $this->assertNotContains($ajena->id, array_column($r->json('eco_obstetrico_tercer'), 'id'));
        $this->assertNotContains(9999, array_column($r->json('eco_obstetrico_tercer'), 'bio_peso_fetal'));
    }
}
