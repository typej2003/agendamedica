<?php

namespace Tests\Feature;

use App\Especialidades\RegistroEspecialidades;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sync del escritorio de **medicina interna** (MedicReport, `miconsultac`; ola MI-1 de
 * `Docs/Web/relevamiento-medicina-interna.md`).
 *
 * Lo que se fija acá:
 *  - el manifiesto no contradice a los otros (las tablas de la evaluación pre-operatoria y el EKG son compartidas);
 *  - al escritorio del internista se le ofrecen sus tablas, y a ginecología no las que son sólo de medicina interna;
 *  - las columnas propias del internista existen (sin ellas el sync las ignoraría y el dato se perdería en silencio);
 *  - la carga inicial guarda el EKG, el análisis evolutivo y las columnas nuevas de la consulta.
 */
class MedicinaInternaSyncTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';
    private const REG_MI = 'medint-t-001';
    private const REG_GIN = 'gineco-t-002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('especialidades:sembrar')->assertSuccessful();
        $this->medicoConEspecialidad(self::REG_MI, 'medicina-interna');
        $this->medicoConEspecialidad(self::REG_GIN, 'ginecologia-y-obstetricia');
    }

    private function medicoConEspecialidad(string $reg, string $slug): Medico
    {
        $email = $reg . '-' . substr(uniqid(), -6) . '@example.com';
        $user = User::create(['name' => 'Médico ' . $reg, 'email' => $email, 'password' => Hash::make('x')]);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Médico', 'lastname' => $reg,
            'email' => $email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);
        $medico->specialties()->attach(Specialty::where('slug', $slug)->firstOrFail()->id);

        return $medico->fresh();
    }

    private function post_(string $ruta, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$ruta}", $cuerpo, ['X-API-KEY' => self::API_KEY]);
    }

    public function test_el_manifiesto_carga_y_no_contradice_a_los_otros(): void
    {
        $registro = new RegistroEspecialidades();
        $registro->validar();

        $manifiesto = $registro->porSlug('medicina-interna');
        $this->assertNotNull($manifiesto);
        $this->assertSame('Medicina Interna', $manifiesto->nombre);
        $this->assertContains('datos_grafica', $manifiesto->propias);
        $this->assertContains('ekg', $manifiesto->compartidas);
        $this->assertContains('ortopedia', $manifiesto->locales, 'la solapa no está en los menús del escritorio: no se migra');
    }

    public function test_el_escritorio_del_internista_recibe_sus_tablas_y_el_de_ginecologia_no(): void
    {
        $tablasMi = explode(',', (string) $this->post_('cambios/estado', ['reg_medico' => self::REG_MI])
            ->assertOk()->json('tablas'));
        foreach (['ekg', 'datos_grafica', 'pre_aneste', 'pre_trombo', 'pre_infec', 'pre_espiro', 'pre_espec',
            'riesgo', 'riesgo_paciente', 'riesgo_paciente_opera'] as $tabla) {
            $this->assertContains($tabla, $tablasMi, "medicina interna tiene que vigilar {$tabla}");
        }
        $this->assertSame('pacientes', $tablasMi[0]);
        $this->assertNotContains('ortopedia', $tablasMi);

        $tablasGin = explode(',', (string) $this->post_('cambios/estado', ['reg_medico' => self::REG_GIN])
            ->assertOk()->json('tablas'));
        $this->assertNotContains('datos_grafica', $tablasGin, 'el análisis evolutivo es sólo de medicina interna');
    }

    public function test_existen_las_columnas_propias_del_internista(): void
    {
        foreach (['tem_otica', 'tem_oral', 'tem_axilar', 'funcionae', 'test'] as $columna) {
            $this->assertTrue(Schema::hasColumn('consultas', $columna), "consultas.{$columna}");
        }
        foreach (['piel_mucosas', 'orl', 'cuello_tiroides', 'vascular', 'neurologico', 'otro', 'mucosas', 'genera',
            'ojo_de', 'boca_de'] as $columna) {
            $this->assertTrue(Schema::hasColumn('examen_fisico', $columna), "examen_fisico.{$columna}");
        }
        foreach (['dependencia_tra', 'cargo', 'tipo_emp'] as $columna) {
            $this->assertTrue(Schema::hasColumn('pacientes', $columna), "pacientes.{$columna}");
        }
    }

    public function test_la_carga_inicial_guarda_el_ekg_el_analisis_evolutivo_y_la_consulta(): void
    {
        $carga = $this->post_('carga-inicial/iniciar', ['reg_medico' => self::REG_MI, 'tablas' => [
            'pacientes' => 1, 'consultas' => 1, 'ekg' => 1, 'datos_grafica' => 1, 'pre_trombo' => 1,
        ]])->assertOk();
        $id = $carga->json('carga_id');

        $lote = fn (string $tabla, array $fila) => $this->post_('carga-inicial/lote', [
            'carga_id' => $id, 'tabla' => $tabla, 'desde' => 0, 'filas' => [$fila],
        ])->assertOk();

        $lote('pacientes', [
            'numhistoria' => 1, 'cedula' => 'MI-1', 'apellidos' => 'PEREZ', 'nombres' => 'ANA', 'nac' => 'V', 'sexo' => 'F',
            'cargo' => 'ANALISTA',
        ]);
        $lote('consultas', [
            'numhistoria' => 1, 'nroconsulta' => 1, 'fecha' => '2026-10-01', 'tem_oral' => 36.8, 'funcionae' => 'CONSERVADO',
        ]);
        $lote('ekg', [
            'numhistoria' => 1, 'numconsulta' => 1, 'ritmo' => 'SINUSAL', 'fc' => '72', 'conclusiones' => 'EKG NORMAL',
        ]);
        $lote('datos_grafica', [
            'historia' => 1, 'consulta' => 1, 'indice_masa' => 24.5, 'otro_8' => 0.9, 'otro_13' => 180,
        ]);
        $lote('pre_trombo', ['historia' => 1, 'consulta' => 1, 'tipo' => '2', 'resultado' => 'RIESGO MODERADO']);

        $this->post_('carga-inicial/finalizar', ['carga_id' => $id])->assertOk();

        $this->assertSame('EKG NORMAL', DB::table('ekg')->where('reg_medico', self::REG_MI)->value('conclusiones'));
        $this->assertEqualsWithDelta(0.9, (float) DB::table('datos_grafica')->where('reg_medico', self::REG_MI)->value('otro_8'), 0.0001);
        $this->assertSame('RIESGO MODERADO', DB::table('pre_trombo')->where('reg_medico', self::REG_MI)->value('resultado'));

        $consulta = DB::table('consultas')->where('reg_medico', self::REG_MI)->first();
        $this->assertEqualsWithDelta(36.8, (float) $consulta->tem_oral, 0.0001);
        $this->assertSame('CONSERVADO', $consulta->funcionae);
    }
}
