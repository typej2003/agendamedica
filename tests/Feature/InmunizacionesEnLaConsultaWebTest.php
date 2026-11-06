<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Las vacunas dentro de la consulta** (O-63, Alexander: «que no se salga de la consulta para ver las vacunas»).
 *
 * El ítem *Inmunizaciones* del árbol de pediatría ya no redirige al módulo del paciente: abre el **mismo calendario**
 * (`clinica.vacunas._calendario`, armado por `CalendarioDelNino`) como sección de la consulta, con su barra y su menú.
 * Se fija que: la sección se ve sin salir de la consulta; registrar una dosis vuelve **a la sección**; el carnet sigue
 * ofreciéndose; las demás especialidades no tienen la sección; y la pantalla del módulo sigue funcionando igual.
 */
class InmunizacionesEnLaConsultaWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00'));
        DB::table('vacunas')->whereNull('reg_medico')->delete();
        DB::table('vacunas_dosis')->whereNull('reg_medico')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'inmu-cons-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'inmu-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Inmunizaciones', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($esp->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $esp];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    /** Un niño de 4 meses (nació el 2026-06-17) con su consulta de hoy. @return array{0:int, 1:Consulta} */
    private function ninoConConsulta(Medico $medico): array
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Inmunizacion', 'sexo' => 'M', 'fnacimiento' => '2026-06-17',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => '9701']);
        Historia::create(['numhistoria' => '9701', 'reg_medico' => $medico->reg_medico, 'paciente_id' => $id, 'medico_id' => $medico->id]);

        return [$id, Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 9701, 'nroconsulta' => 1, 'fecha' => '2026-10-17'])];
    }

    /** Una vacuna del catálogo del médico con su primera dosis a los 2 meses (a este niño ya le tocaba). */
    private function vacunaConDosis(Medico $medico, string $codigo = 'POLI', string $nombre = 'POLIO', int $meses = 2): void
    {
        DB::table('vacunas')->insert([
            'reg_medico' => $medico->reg_medico, 'codi_vacuna' => $codigo, 'vacuna' => $nombre, 'edad_inicial_meses' => $meses,
            'fija' => 'O', 'sexo' => 'A', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('vacunas_dosis')->insert([
            'reg_medico' => $medico->reg_medico, 'codi_vacuna' => $codigo, 'dosis' => 1, 'dosis_descri' => 'Primera', 'meses' => $meses,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function urlDeLaSeccion(Consulta $consulta, array $query = []): string
    {
        return route('clinica.consulta', array_merge([$consulta->id, 'seccion' => 'inmunizaciones'], $query));
    }

    /** (a) La consulta de pediatría con `?seccion=inmunizaciones` renderiza el calendario, sin redirect. */
    public function test_la_seccion_inmunizaciones_muestra_el_calendario_dentro_de_la_consulta(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [$id, $consulta] = $this->ninoConConsulta($medico);
        $this->vacunaConDosis($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->urlDeLaSeccion($consulta))
            ->assertOk()
            // Es la sección de la consulta, con la barra y el menú de la consulta: no se salió a la ficha del niño.
            ->assertSee('id="consulta-inmunizaciones"', false)
            ->assertSee('data-consulta-barra', false)
            ->assertSee('Inmunizaciones')
            // El calendario, con la dosis que le toca y su botón de registrar.
            ->assertSee('id="vacunas-calendario"', false)
            ->assertSee('POLIO')
            ->assertSee('data-registrar-dosis', false)
            // El carnet imprimible sigue disponible desde la sección.
            ->assertSee('Imprimir el carnet')
            ->assertSee('data-visualizar="' . route('clinica.pacientes.vacunas.carnet', $id) . '"', false);
    }

    /** El filtro «Pendientes» de la sección se queda en la sección (no vuelve al módulo). */
    public function test_el_filtro_del_calendario_se_queda_en_la_seccion(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->ninoConConsulta($medico);
        $this->vacunaConDosis($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->urlDeLaSeccion($consulta))
            ->assertOk()
            ->assertSee(e($this->urlDeLaSeccion($consulta, ['ver' => 'pendientes'])), false);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->urlDeLaSeccion($consulta, ['ver' => 'pendientes']))
            ->assertOk()
            ->assertSee('id="consulta-inmunizaciones"', false)
            ->assertSee('POLIO');
    }

    /** (b) Registrar una dosis desde la sección devuelve a la misma sección de la consulta. */
    public function test_registrar_una_dosis_desde_la_consulta_vuelve_a_la_seccion(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->ninoConConsulta($medico);
        $this->vacunaConDosis($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.pacientes.vacunas.registrar', $this->pacienteDeLaConsulta($medico)), [
                'codi_vacuna' => 'POLI',
                'dosis_numero' => 1,
                'rotulo' => 'Primera',
                'fecha_ejecutada' => '2026-10-16',
                'lote' => 'L-63',
                // La consulta viaja en el formulario: es lo que hace volver a la sección y no al módulo.
                'consulta' => $consulta->id,
            ])
            ->assertRedirect($this->urlDeLaSeccion($consulta))
            ->assertSessionHas('estado');

        $fila = DB::table('vacuna_paciente')->where('reg_medico', $medico->reg_medico)->where('historia', 9701)->first();
        $this->assertSame('POLI', trim((string) $fila->codi_vacuna));
        $this->assertSame('2026-10-16', substr((string) $fila->fecha_ejecutada, 0, 10));

        // Y la sección, al volver, ya muestra la dosis aplicada.
        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->urlDeLaSeccion($consulta))
            ->assertOk()
            ->assertSee('id="consulta-inmunizaciones"', false)
            ->assertSee('Lote L-63');
    }

    /** El `consulta` del formulario no elige el destino: una consulta de otro niño no se acepta y se vuelve al módulo. */
    public function test_una_consulta_ajena_no_cambia_el_regreso(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->ninoConConsulta($medico);
        $this->vacunaConDosis($medico);

        // Otra historia del mismo médico.
        $otro = DB::table('pacientes')->insertGetId([
            'nombres' => 'Otro', 'apellidos' => 'Niño', 'sexo' => 'F', 'fnacimiento' => '2026-05-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $otro, 'reg_medico' => $medico->reg_medico, 'numhistoria' => '9702']);
        $ajena = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 9702, 'nroconsulta' => 1, 'fecha' => '2026-10-17']);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.pacientes.vacunas.registrar', $this->pacienteDeLaConsulta($medico)), [
                'codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'rotulo' => 'Primera', 'fecha_ejecutada' => '2026-10-16',
                'consulta' => $ajena->id,
            ])
            ->assertRedirect(route('clinica.pacientes.vacunas', $this->pacienteDeLaConsulta($medico)));

        $this->assertSame(1, DB::table('vacuna_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    /** El aviso de vacunas pendientes de la consulta lleva a la sección, no al módulo (O-63). */
    public function test_el_aviso_de_vacunas_pendientes_lleva_a_la_seccion(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->ninoConConsulta($medico);
        $this->vacunaConDosis($medico);
        DB::table('vacunas')->where('reg_medico', $medico->reg_medico)->where('codi_vacuna', 'POLI')->update(['edad_inicial_meses' => 0]);
        DB::table('vacunas_dosis')->where('reg_medico', $medico->reg_medico)->update(['meses' => 0]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()
            ->assertSee('id="consulta-aviso-vacunas"', false)
            ->assertSee($this->urlDeLaSeccion($consulta), false);
    }

    /** (c) Fuera de pediatría la sección no existe: ni en el menú ni abriendo su URL. */
    public function test_ginecologia_no_ofrece_la_seccion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        [, $consulta] = $this->ninoConConsulta($medico);

        $html = $this->conContexto($medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('Inmunizaciones', $html);
        $this->assertStringNotContainsString('seccion=inmunizaciones', $html);

        // Y pedirla a mano cae en el estado vacío del shell, como cualquier sección que el catálogo no conoce.
        $this->conContexto($medico, $gineco)->actingAs($user)->get($this->urlDeLaSeccion($consulta))
            ->assertOk()
            ->assertSee('Elige una sección en el menú')
            ->assertDontSee('id="consulta-inmunizaciones"', false);
    }

    /** (d) El módulo del paciente sigue funcionando igual: su calendario, su carnet y su registro vuelven ahí. */
    public function test_el_modulo_del_paciente_sigue_igual(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [$id] = $this->ninoConConsulta($medico);
        $this->vacunaConDosis($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.vacunas', $id))
            ->assertOk()
            ->assertSee('id="vacunas-calendario"', false)
            ->assertSee('POLIO')
            ->assertSee('Imprimir el carnet')
            // El formulario del módulo **no** lleva la consulta: el registro vuelve a esta pantalla.
            ->assertDontSee('name="consulta"', false);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.pacientes.vacunas.registrar', $id), [
                'codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'rotulo' => 'Primera', 'fecha_ejecutada' => '2026-10-16',
            ])
            ->assertRedirect(route('clinica.pacientes.vacunas', $id));

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.vacunas', $id))
            ->assertOk()->assertSee('16/10/2026');
    }

    /** El id del paciente de la consulta de prueba (la historia 9701 de este médico). */
    private function pacienteDeLaConsulta(Medico $medico): int
    {
        return (int) DB::table('medico_pacientes')
            ->where('reg_medico', $medico->reg_medico)->where('numhistoria', '9701')->value('paciente_id');
    }
}
