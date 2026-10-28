<?php

namespace Tests\Feature;

use App\Actions\Pacientes\SembrarTiposDeAntecedente;
use App\Clinica\Consulta\TiposDeAntecedente;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Los siete tipos de antecedente con el nombre de cada especialidad** (2026-10-28, seguimiento del reporte de la doctora Olivia).
 *
 * Los códigos `01`–`07` son los mismos para todas las especialidades y lo que cambia es el nombre. Antes el único mapa era el de pediatría y sólo se sembraba
 * para pediatría, así que una ginecóloga sin filas propias veía los nombres pediátricos por el respaldo «de cualquier otro médico» de
 * `AntecedentesDelPaciente`. Estos casos fijan que:
 *
 *  - la ginecóloga nueva recibe los siete nombres de ginecología al abrir su consulta (el mismo punto en que pediatría los recibe desde WEB-12.3);
 *  - el pediatra sigue con los de pediatría aunque en la misma base haya filas de ginecología;
 *  - el sembrado es idempotente y **no pisa** el nombre que el médico ya tenía;
 *  - la consulta muestra el rótulo correcto para un antecedente del tipo `06` (ACTIVIDAD SEXUAL, no ALIMENTACIÓN);
 *  - una especialidad sin nombres en la fuente no siembra nada (no se le presta el mapa de otra).
 */
class TiposDeAntecedentePorEspecialidadTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EspecialidadesYModulosSeeder::class);
    }

    // ------------------------------------------------------------------------------------------ Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medico(string $especialidad): array
    {
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'tipo-ant-' . substr(uniqid(), -8);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => $reg . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Tipos', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($esp->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $esp];
    }

    /** Le deja a un médico un catálogo de tipos como el que sube el escritorio. */
    private function sembrarTipos(Medico $medico, array $tipos, array $letras = []): void
    {
        foreach ($tipos as $codigo => $nombre) {
            DB::table('tipo_antecedente')->insert([
                'reg_medico' => $medico->reg_medico, 'codetipo' => $codigo, 'descripcion' => $nombre,
                'tipoantecedente' => $letras[$codigo] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 9701): array
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Tipos', 'sexo' => 'F', 'fnacimiento' => '1990-05-04',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $numhistoria, 'paciente_id' => $pacienteId]);
        Historia::create(['numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico, 'paciente_id' => $pacienteId, 'medico_id' => $medico->id]);

        return [$pacienteId, Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria, 'nroconsulta' => 1, 'fecha' => '2026-10-28'])];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]])->actingAs($user);
    }

    private function url(Consulta $consulta, array $extra = []): string
    {
        return route('clinica.consulta', array_merge([$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES], $extra));
    }

    /** Los chips de tipo de la sección, leídos del HTML: nombre y si es el elegido. */
    private function chips(string $html): array
    {
        preg_match_all('/<a class="chip-filtro([^"]*)"[^>]*>([^<]*)<\/a>/', $html, $filas, PREG_SET_ORDER);

        return array_map(fn ($fila) => [
            'nombre' => trim(html_entity_decode($fila[2])),
            'activo' => str_contains($fila[1], 'activo'),
        ], $filas);
    }

    /** @return array<string,string> `codetipo => descripcion` del médico, en el orden del catálogo */
    private function tiposDelMedico(Medico $medico): array
    {
        return DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)->orderBy('codetipo')
            ->pluck('descripcion', 'codetipo')->map(fn ($nombre) => trim((string) $nombre))->all();
    }

    // ------------------------------------------------------------------------------------------ Los casos

    public function test_una_ginecologa_nueva_recibe_los_siete_nombres_de_ginecologia(): void
    {
        // La base ya tiene el catálogo de pediatría de otro médico: es el que antes se filtraba como respaldo.
        [, $pediatra] = $this->medico('pediatria');
        $this->sembrarTipos($pediatra, TiposDeAntecedente::PEDIATRIA);

        [$user, $medico, $gineco] = $this->medico('ginecologia-y-obstetricia');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->get($this->url($consulta))->assertOk();

        $this->assertSame(array_values(TiposDeAntecedente::GINECOLOGIA), array_column($this->chips($respuesta->getContent()), 'nombre'));
        $this->assertSame(TiposDeAntecedente::GINECOLOGIA, $this->tiposDelMedico($medico));
        $this->assertSame(TiposDeAntecedente::LETRAS_DE_GINECOLOGIA, DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)
            ->orderBy('codetipo')->pluck('tipoantecedente', 'codetipo')->all());
        $respuesta->assertDontSee('PATOLÓGICOS');
        $respuesta->assertDontSee('ALIMENTACIÓN');
    }

    public function test_el_pediatra_sigue_con_los_siete_nombres_de_pediatria_aunque_haya_ginecologia_en_la_base(): void
    {
        [, $ginecologa] = $this->medico('ginecologia-y-obstetricia');
        $this->sembrarTipos($ginecologa, TiposDeAntecedente::GINECOLOGIA, TiposDeAntecedente::LETRAS_DE_GINECOLOGIA);

        [$user, $medico, $pediatria] = $this->medico('pediatria');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))->assertOk();

        $this->assertSame(array_values(TiposDeAntecedente::PEDIATRIA), array_column($this->chips($respuesta->getContent()), 'nombre'));
        $this->assertSame(TiposDeAntecedente::PEDIATRIA, $this->tiposDelMedico($medico));
        $respuesta->assertDontSee('ACTIVIDAD SEXUAL');
    }

    public function test_el_sembrado_es_idempotente_y_no_pisa_el_nombre_que_el_medico_ya_tenia(): void
    {
        [$user, $medico, $gineco] = $this->medico('ginecologia-y-obstetricia');
        // El médico renombró el tipo 06: lo suyo manda, aunque el nombre no sea el de la fuente.
        $this->sembrarTipos($medico, ['06' => 'N. PAREJAS']);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->get($this->url($consulta))->assertOk();
        $respuesta = $this->conContexto($user, $medico, $gineco)->get($this->url($consulta))->assertOk();

        $this->assertSame(7, DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame('N. PAREJAS', $this->tiposDelMedico($medico)['06']);
        // Los que el sembrado agrega quedan en el orden de las solapas, no al final por tener `id` mayor; el 06 es el
        // nombre que el médico ya tenía.
        $esperados = array_values(TiposDeAntecedente::GINECOLOGIA);
        $esperados[5] = 'N. PAREJAS';
        $this->assertSame($esperados, array_column($this->chips($respuesta->getContent()), 'nombre'));
        // Y una corrida más no agrega nada.
        $this->assertSame(0, app(SembrarTiposDeAntecedente::class)->ejecutar($medico->reg_medico, 'ginecologia-y-obstetricia'));
    }

    public function test_la_consulta_muestra_el_tipo_06_bajo_actividad_sexual_y_no_bajo_alimentacion(): void
    {
        // El catálogo pediátrico ajeno es el que antes le daba el nombre al tipo 06.
        [, $pediatra] = $this->medico('pediatria');
        $this->sembrarTipos($pediatra, TiposDeAntecedente::PEDIATRIA);

        [$user, $medico, $gineco] = $this->medico('ginecologia-y-obstetricia');
        [, $consulta] = $this->pacienteYConsulta($medico);
        DB::table('antecedentes')->insert([
            'reg_medico' => $medico->reg_medico, 'codantecedente' => '0001', 'descripcion' => 'N. PAREJAS', 'codtipo' => '06',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('antece_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 9701, 'codeantecedente' => '0001', 'descripcion' => 'N. PAREJAS',
            'tipo' => '06', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->get($this->url($consulta, ['tipo' => '06']))->assertOk();

        $respuesta->assertSee('ACTIVIDAD SEXUAL')->assertSee('N. PAREJAS')->assertDontSee('ALIMENTACIÓN');
        $this->assertSame(['ACTIVIDAD SEXUAL'], array_column(array_filter($this->chips($respuesta->getContent()), fn ($chip) => $chip['activo']), 'nombre'));
    }

    public function test_una_especialidad_sin_nombres_en_la_fuente_no_siembra_nada(): void
    {
        [$user, $medico, $cardiologia] = $this->medico('cardiologia');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $cardiologia)->get($this->url($consulta))->assertOk();

        $this->assertSame(0, DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)->count());
    }
}
