<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MotivoConsulta;
use App\Models\MotivoConsultaPaciente;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La casilla «Anterior»** de la consulta (WEB-11.4; casilla por pedido de Alexander, 2026-10-20): trae lo de la consulta anterior de
 * la misma historia en motivos, diagnósticos, récipes, enfermedad actual y tratamiento. Marcada = lo de la anterior está en ésta;
 * marcarla lo trae sin repetir y desmarcarla saca lo que coincide con la anterior.
 */
class ConsultaAnteriorWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-20 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'anterior-t' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'anterior-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Anterior', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    /** Otro médico (otro tenant) sin acceso: sólo para dejar datos que no se deben mezclar. */
    private function otroMedico(): Medico
    {
        return Medico::create([
            'name' => 'Dr. ajeno', 'lastname' => 'Otro', 'email' => 'otro-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'reg_medico' => 'anterior-o' . substr(uniqid(), -6),
        ]);
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    private function paciente(Medico $medico, int $numhistoria): void
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId, 'reg_medico' => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);
        Historia::create([
            'numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);
    }

    /** @param  array<string,mixed>  $extra */
    private function consulta(Medico $medico, int $numhistoria, int $nroconsulta, string $fecha, array $extra = []): Consulta
    {
        return Consulta::create(array_merge([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria, 'nroconsulta' => $nroconsulta, 'fecha' => $fecha,
        ], $extra));
    }

    /**
     * La historia 1001 del médico con dos consultas: la 1 (10/10/2026) y la 2 (hoy, la que se atiende).
     *
     * @return array{0:Consulta, 1:Consulta}
     */
    private function dosConsultas(Medico $medico, array $primera = [], array $segunda = []): array
    {
        $this->paciente($medico, 1001);

        return [
            $this->consulta($medico, 1001, 1, '2026-10-10', $primera),
            $this->consulta($medico, 1001, 2, $this->hoy->toDateString(), $segunda),
        ];
    }

    private function motivo(Medico $medico, string $codigo, string $descripcion): void
    {
        MotivoConsulta::create(['reg_medico' => $medico->reg_medico, 'codemotivo' => $codigo, 'descripcion' => $descripcion]);
    }

    private function motivoEn(Medico $medico, int $numhistoria, int $nroconsulta, string $codigo): void
    {
        MotivoConsultaPaciente::create([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => $codigo, 'nrohistoria' => $numhistoria, 'nroconsulta' => $nroconsulta,
        ]);
    }

    /** @return array<int,string> los códigos de motivo de una consulta, ordenados */
    private function motivosDe(Medico $medico, int $numhistoria, int $nroconsulta): array
    {
        return MotivoConsultaPaciente::where('reg_medico', $medico->reg_medico)->where('nrohistoria', $numhistoria)
            ->where('nroconsulta', $nroconsulta)->orderBy('codemotivo')->pluck('codemotivo')->map(fn ($c) => trim($c))->all();
    }

    private function diagnosticoDelCatalogo(Medico $medico, string $codigo, string $descripcion): void
    {
        DB::table('diagnosticos')->insert([
            'reg_medico' => $medico->reg_medico, 'codediagnostico' => $codigo, 'descripcion' => $descripcion,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function diagnosticoEn(Medico $medico, int $numhistoria, int $nroconsulta, string $codigo, ?string $detalle = null): void
    {
        DB::table('diagnostico_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => $numhistoria, 'nroconsulta' => $nroconsulta,
            'codediagnostico' => $codigo, 'detalle_diagnostco' => $detalle, 'orden' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int,string> */
    private function diagnosticosDe(Medico $medico, int $numhistoria, int $nroconsulta): array
    {
        return DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)->where('nrohistoria', $numhistoria)
            ->where('nroconsulta', $nroconsulta)->orderBy('codediagnostico')->pluck('codediagnostico')->map(fn ($c) => trim($c))->all();
    }

    private function medicamento(Medico $medico, string $codigo, string $comercial): void
    {
        DB::table('vademecum')->insert([
            'reg_medico' => $medico->reg_medico, 'codemedicina' => $codigo, 'nombrecomercial' => $comercial,
            'nombregenerico' => null, 'presentacion' => null, 'uso' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function recipeEn(Medico $medico, int $numhistoria, int $nroconsulta, ?int $recipe, string $codigo, int $cantidad, string $indicaciones): void
    {
        DB::table('recipes')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => $numhistoria, 'nroconsulta' => $nroconsulta, 'codemedicina' => $codigo,
            'cantidad' => $cantidad, 'indicaciones' => $indicaciones, 'orden' => null, 'descripcion' => null, 'fecha' => null,
            'recipe' => $recipe, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function url(Consulta $consulta, string $seccion): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => $seccion]);
    }

    private function urlAnterior(Consulta $consulta, string $que): string
    {
        return route('clinica.consulta.anterior', [$consulta->id, $que]);
    }

    /** El `<input>` de la casilla, tal como se pintó (o `null` si no está). */
    private function casilla(string $html, string $que): ?string
    {
        return preg_match('/<input[^>]*id="anterior-' . $que . '"[^>]*>/s', $html, $coincide) ? $coincide[0] : null;
    }

    // -------------------------------------------------------------------------- Sin consulta anterior

    public function test_en_la_primera_consulta_no_aparece_en_ninguna_seccion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1001);
        $consulta = $this->consulta($medico, 1001, 1, $this->hoy->toDateString());
        $this->motivo($medico, '0001', 'CONTROL');

        foreach ([
            ConsultaShellVista::SECCION_MOTIVOS => 'motivos', ConsultaShellVista::SECCION_DIAGNOSTICO => 'diagnosticos',
            ConsultaShellVista::SECCION_RECIPES => 'recipes', ConsultaShellVista::SECCION_ENFERMEDAD => 'enfermedad',
            ConsultaShellVista::SECCION_TRATAMIENTO => 'tratamiento',
        ] as $seccion => $que) {
            $html = $this->conContexto($user, $medico, $gineco)->get($this->url($consulta, $seccion))->assertOk()->getContent();
            $this->assertNull($this->casilla($html, $que), "La sección {$seccion} no debe ofrecer «Anterior» en la primera consulta.");
        }

        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($consulta, 'motivos'), ['activar' => '1'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame([], $this->motivosDe($medico, 1001, 1));
    }

    public function test_los_recipes_y_los_textos_ya_no_se_copian_por_post(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $actual] = $this->dosConsultas($medico);

        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'recipes'), ['activar' => '1'])->assertNotFound();
    }

    // ----------------------------------------------------------------------------------------- Motivos

    public function test_motivos_marcar_trae_sin_repetir_y_desmarcar_saca_lo_de_la_anterior(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $actual] = $this->dosConsultas($medico);
        $this->motivo($medico, '0001', 'DOLOR PELVICO');
        $this->motivo($medico, '0002', 'CONTROL');
        $this->motivo($medico, '0003', 'RECIPE');
        $this->motivoEn($medico, 1001, 1, '0001');
        $this->motivoEn($medico, 1001, 1, '0002');
        $this->motivoEn($medico, 1001, 1, '9999'); // ya no está en el catálogo: no se trae
        $this->motivoEn($medico, 1001, 2, '0002'); // ya estaba en ésta
        $this->motivoEn($medico, 1001, 2, '0003'); // propio de ésta

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_MOTIVOS))->getContent();
        $casilla = $this->casilla($html, 'motivos');
        $this->assertNotNull($casilla);
        $this->assertStringNotContainsString('checked', $casilla);
        $this->assertStringNotContainsString('disabled', $casilla);
        $this->assertStringContainsString('Traer de la consulta del 10/10/2026', $html);

        // Marcar: trae el 0001, no repite el 0002.
        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'motivos'), ['activar' => '1'])
            ->assertRedirect($this->url($actual, ConsultaShellVista::SECCION_MOTIVOS))->assertSessionHas('estado');
        $this->assertSame(['0001', '0002', '0003'], $this->motivosDe($medico, 1001, 2));

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_MOTIVOS))->getContent();
        $this->assertStringContainsString('checked', $this->casilla($html, 'motivos'));

        // Idempotente.
        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'motivos'), ['activar' => '1']);
        $this->assertSame(['0001', '0002', '0003'], $this->motivosDe($medico, 1001, 2));

        // Desmarcar (la casilla no viaja): saca lo que coincide con la anterior y deja lo propio. La anterior no se toca.
        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'motivos'), [])->assertSessionHas('estado');
        $this->assertSame(['0003'], $this->motivosDe($medico, 1001, 2));
        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'motivos'), []);
        $this->assertSame(['0003'], $this->motivosDe($medico, 1001, 2));
        $this->assertSame(['0001', '0002', '9999'], $this->motivosDe($medico, 1001, 1));
    }

    public function test_motivos_una_consulta_intermedia_vacia_no_corta_la_cadena(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1001);
        $this->consulta($medico, 1001, 1, '2026-10-01');
        $this->consulta($medico, 1001, 2, '2026-10-10');
        $actual = $this->consulta($medico, 1001, 3, $this->hoy->toDateString());
        $this->motivo($medico, '0001', 'CONTROL');
        $this->motivoEn($medico, 1001, 1, '0001');

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_MOTIVOS))->getContent();
        $this->assertStringContainsString('Traer de la consulta del 01/10/2026', $html);

        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'motivos'), ['activar' => '1']);
        $this->assertSame(['0001'], $this->motivosDe($medico, 1001, 3));
    }

    public function test_no_mezcla_pacientes_ni_tenants(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $actual] = $this->dosConsultas($medico, ['enfermedadactual' => '']);
        $this->motivo($medico, '0001', 'CONTROL');
        $this->diagnosticoDelCatalogo($medico, 'D001', 'MIOMA');

        // Otro paciente del mismo médico (historia 1002) con su consulta 1 cargada.
        $this->paciente($medico, 1002);
        $this->consulta($medico, 1002, 1, '2026-10-10', ['enfermedadactual' => 'De otro paciente']);
        $this->motivoEn($medico, 1002, 1, '0001');
        $this->diagnosticoEn($medico, 1002, 1, 'D001');

        // Otro médico (otro tenant) con la misma historia 1001 y su consulta 1 cargada.
        $otro = $this->otroMedico();
        $this->motivo($otro, '0001', 'CONTROL');
        $this->consulta($otro, 1001, 1, '2026-10-10', ['enfermedadactual' => 'De otro consultorio']);
        $this->motivoEn($otro, 1001, 1, '0001');
        $this->diagnosticoEn($otro, 1001, 1, 'D001');

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_MOTIVOS))->getContent();
        $this->assertStringContainsString('disabled', $this->casilla($html, 'motivos'));
        $this->assertStringContainsString('Ninguna consulta anterior tiene motivos', $html);

        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'motivos'), ['activar' => '1'])->assertSessionHas('error');
        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'diagnosticos'), ['activar' => '1'])->assertSessionHas('error');
        $this->assertSame([], $this->motivosDe($medico, 1001, 2));
        $this->assertSame([], $this->diagnosticosDe($medico, 1001, 2));

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_ENFERMEDAD))->getContent();
        $this->assertStringNotContainsString('De otro', $html);
        $this->assertStringContainsString('disabled', $this->casilla($html, 'enfermedad'));
    }

    // ------------------------------------------------------------------------------------ Diagnósticos

    public function test_diagnosticos_marcar_trae_sin_el_detalle_y_desmarcar_los_saca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $actual] = $this->dosConsultas($medico);
        $this->diagnosticoDelCatalogo($medico, 'D001', 'MIOMA UTERINO');
        $this->diagnosticoDelCatalogo($medico, 'D002', 'QUISTE DE OVARIO');
        $this->diagnosticoDelCatalogo($medico, 'D003', 'VAGINOSIS');
        $this->diagnosticoEn($medico, 1001, 1, 'D001', 'de 3 cm');
        $this->diagnosticoEn($medico, 1001, 1, 'D002', 'derecho');
        $this->diagnosticoEn($medico, 1001, 2, 'D002', 'derecho, más chico');
        $this->diagnosticoEn($medico, 1001, 2, 'D003');

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_DIAGNOSTICO))->getContent();
        $this->assertStringNotContainsString('checked', $this->casilla($html, 'diagnosticos'));

        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'diagnosticos'), ['activar' => '1'])
            ->assertRedirect($this->url($actual, ConsultaShellVista::SECCION_DIAGNOSTICO));
        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'diagnosticos'), ['activar' => '1']);
        $this->assertSame(['D001', 'D002', 'D003'], $this->diagnosticosDe($medico, 1001, 2));
        // El de esta consulta conserva su detalle; el traído no trae el de aquella atención.
        $this->assertSame('derecho, más chico', DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)
            ->where('nroconsulta', 2)->where('codediagnostico', 'D002')->value('detalle_diagnostco'));
        $this->assertNotSame('de 3 cm', DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)
            ->where('nroconsulta', 2)->where('codediagnostico', 'D001')->value('detalle_diagnostco'));

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_DIAGNOSTICO))->getContent();
        $this->assertStringContainsString('checked', $this->casilla($html, 'diagnosticos'));

        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'diagnosticos'), []);
        $this->conContexto($user, $medico, $gineco)->post($this->urlAnterior($actual, 'diagnosticos'), []);
        $this->assertSame(['D003'], $this->diagnosticosDe($medico, 1001, 2));
        $this->assertSame(['D001', 'D002'], $this->diagnosticosDe($medico, 1001, 1));
    }

    // ------------------------------------------------------------------------------------------ Récipes

    public function test_recipes_el_editor_recibe_el_ultimo_recipe_de_la_anterior(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $actual] = $this->dosConsultas($medico);
        $this->medicamento($medico, '0001', 'KEFORAL');
        $this->medicamento($medico, '0002', 'DIFLUCAN');
        $this->medicamento($medico, '0003', 'ACIDO FOLICO');
        $this->recipeEn($medico, 1001, 1, null, '0001', 1, 'Una cada 6 horas');
        $this->recipeEn($medico, 1001, 1, 2, '0002', 2, 'Una semanal');
        $this->recipeEn($medico, 1001, 1, 2, '0003', 3, 'Una diaria');

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_RECIPES))->assertOk()->getContent();
        $casilla = $this->casilla($html, 'recipes');
        $this->assertNotNull($casilla);
        $this->assertStringContainsString('data-anterior-recipe', $casilla);
        $this->assertMatchesRegularExpression('/data-items="([^"]*)"/', $casilla);
        preg_match('/data-items="([^"]*)"/', $casilla, $coincide);
        $items = json_decode(html_entity_decode($coincide[1], ENT_QUOTES), true);

        $this->assertSame(['0002', '0003'], array_column($items, 'codigo'));
        $this->assertSame([2, 3], array_column($items, 'cantidad'));
        $this->assertSame(['Una semanal', 'Una diaria'], array_column($items, 'indicaciones'));
        $this->assertStringContainsString('Traer de la consulta del 10/10/2026', $html);

        // Abrir el editor no escribe nada: se guarda con «Guardar el récipe».
        $this->assertSame(0, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->where('nroconsulta', 2)->count());
    }

    public function test_recipes_sin_recipes_anteriores_la_casilla_esta_deshabilitada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $actual] = $this->dosConsultas($medico);

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_RECIPES))->getContent();
        $this->assertStringContainsString('disabled', $this->casilla($html, 'recipes'));
        $this->assertStringContainsString('Ninguna consulta anterior tiene récipes', $html);
    }

    // ---------------------------------------------------------------------- Enfermedad actual y tratamiento

    public function test_enfermedad_actual_el_campo_recibe_el_texto_de_la_anterior_que_lo_tenga(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1001);
        $this->consulta($medico, 1001, 1, '2026-10-01', ['enfermedadactual' => 'Dolor pélvico de 3 días.']);
        $this->consulta($medico, 1001, 2, '2026-10-10', ['enfermedadactual' => '   ']);
        $actual = $this->consulta($medico, 1001, 3, $this->hoy->toDateString());

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_ENFERMEDAD))->getContent();
        $casilla = $this->casilla($html, 'enfermedad');
        $this->assertStringContainsString('data-anterior-texto', $casilla);
        $this->assertStringContainsString('data-destino="#enfermedadactual"', $casilla);
        $this->assertStringContainsString('data-texto="Dolor pélvico de 3 días."', $casilla);
        $this->assertStringContainsString('Traer de la consulta del 01/10/2026', $html);
        $this->assertNull(Consulta::find($actual->id)->enfermedadactual);
    }

    public function test_tratamiento_el_campo_recibe_el_texto_de_la_anterior(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $actual] = $this->dosConsultas($medico, ['laringe' => 'Control en un mes.']);

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url($actual, ConsultaShellVista::SECCION_TRATAMIENTO))->getContent();
        $casilla = $this->casilla($html, 'tratamiento');
        $this->assertStringContainsString('data-destino="#tratamiento"', $casilla);
        $this->assertStringContainsString('data-texto="Control en un mes."', $casilla);
    }
}
