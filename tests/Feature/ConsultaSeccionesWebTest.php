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
use App\Models\Office;
use App\Models\SyncChange;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El motivo de consulta y la enfermedad actual** (WEB-4.2, ítems 1 y 2 del panel del legado).
 *
 * Es la primera tarea que **carga contenido** dentro del shell de la consulta (WEB-4.1):
 *
 *  - los **motivos** de la consulta: verlos, agregar uno del catálogo, **crear el que falta** desde el
 *    buscador (online, PLAN-WEB.md §3.15) y quitarlo —sin tocar el catálogo, como el legado—;
 *  - la **enfermedad actual**: editable (=`consultas.enfermedadactual`), con guardado explícito y con su
 *    rastro en `sync_changes` (`source = web`, por columna) para que el teléfono no pise el cambio.
 *
 * Y el recorte de roles de WEB-4.1: **la secretaría no entra** (403) a ninguna de las dos.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaSeccionesWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-05 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'consulta42-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'consulta42-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Consulta',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'consulta42-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /**
     * Un paciente con historia y una consulta del día: el caso normal de la pantalla.
     *
     * @return array{0:int, 1:Consulta}
     */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1): array
    {
        $pacienteId = $this->paciente($medico, $numhistoria);

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    private function paciente(Medico $medico, int $numhistoria): int
    {
        $pacienteId = \Illuminate\Support\Facades\DB::table('pacientes')->insertGetId([
            'nombres'     => 'Ana',
            'apellidos'   => 'Alvarez',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $pacienteId,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $pacienteId,
            'medico_id'   => $medico->id,
        ]);

        return $pacienteId;
    }

    private function motivo(Medico $medico, string $codigo, string $descripcion): MotivoConsulta
    {
        return MotivoConsulta::create([
            'reg_medico'  => $medico->reg_medico,
            'codemotivo'  => $codigo,
            'descripcion' => $descripcion,
        ]);
    }

    private function urlSeccion(Consulta $consulta, string $seccion): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => $seccion]);
    }

    // ------------------------------------------------------------------------------- Los motivos

    public function test_la_seccion_de_motivos_muestra_los_cargados_y_el_catalogo_buscable(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->motivo($medico, '0001', 'DOLOR PELVICO');
        $this->motivo($medico, '0002', 'CONTROL');
        MotivoConsultaPaciente::create([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => '0002',
            'nrohistoria' => 1001, 'nroconsulta' => 1,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS));

        $respuesta->assertOk();
        $respuesta->assertSee('DOLOR PELVICO', false);
        // El cargado se muestra marcado y el que ya está no ofrece agregarlo.
        $respuesta->assertSee('CONTROL', false);
        $respuesta->assertSee('ya está en la consulta', false);
        // Y el aviso de conexión del alta, que es lo único que no funciona sin red.
        $respuesta->assertSee('necesita conexión', false);
    }

    public function test_el_catalogo_de_motivos_se_ve_completo_y_por_paginas(): void
    {
        // WEB-11.3: la lista de la derecha no se corta en 30; se pagina y la página 2 trae el resto.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        foreach (range(1, 15) as $n) {
            $this->motivo($medico, sprintf('%04d', $n), sprintf('MOTIVO %02d', $n));
        }
        $url = $this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS);

        $uno = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($url);
        $uno->assertOk()->assertSee('MOTIVO 01', false)->assertSee('MOTIVO 12', false)->assertDontSee('MOTIVO 13', false);
        $uno->assertSee('pagina_catalogo=2', false)->assertSee('data-pasar', false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($url . '&pagina_catalogo=2')
            ->assertOk()->assertSee('MOTIVO 13', false)->assertSee('MOTIVO 15', false)->assertDontSee('MOTIVO 01', false);
    }

    public function test_la_barra_ofrece_el_navegador_entre_las_consultas_del_paciente(): void
    {
        // A-11: con dos consultas en la historia aparecen los chips 1 y 2 (la actual marcada) en la **fila única** del
        // encabezado, junto a Guardar y «Terminar la consulta». «Guardar y salir» y «Salir» ya no existen como botones.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1);
        $segunda = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => $this->hoy->toDateString(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($segunda, ConsultaShellVista::SECCION_MOTIVOS));

        $respuesta->assertOk()
            ->assertSee('data-consulta-barra', false)
            ->assertSee('Guardar')
            ->assertDontSee('Guardar y salir')
            ->assertDontSee('data-barra-guardar-salir', false)
            ->assertSee('Consultas de este paciente')
            ->assertSee(route('clinica.consulta', $primera->id) . '?seccion=motivos', false);
        $this->assertSame(1, substr_count($respuesta->getContent(), 'chip-opcion activo" href="' . route('clinica.consulta', $segunda->id) . '?seccion=motivos"'));
    }

    public function test_con_una_sola_consulta_no_se_pinta_el_navegador(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS))
            ->assertOk()->assertSee('data-consulta-barra', false)->assertDontSee('Consultas de este paciente');
    }

    public function test_agregar_un_motivo_del_catalogo_lo_guarda_en_la_consulta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->motivo($medico, '0007', 'SANGRADO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.motivos.agregar', $consulta->id),
            ['codemotivo' => '0007'],
        );

        $respuesta->assertRedirect($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'SANGRADO'));

        $fila = MotivoConsultaPaciente::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame('0007', $fila->codemotivo);
        $this->assertSame(1001, (int) $fila->nrohistoria);
        $this->assertSame(1, (int) $fila->nroconsulta);

        // El rastro para el sync: la web también escribe `motivo_consulta_paciente`.
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'motivo_consulta_paciente',
            'record_id'  => $fila->id,
            'operation'  => 'created',
            'source'     => 'web',
        ]);
    }

    public function test_agregar_dos_veces_el_mismo_motivo_no_duplica_la_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->motivo($medico, '0007', 'SANGRADO');

        $url = route('clinica.consulta.motivos.agregar', $consulta->id);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($url, ['codemotivo' => '0007']);
        $segunda = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($url, ['codemotivo' => '0007']);

        $segunda->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'ya estaba'));
        $this->assertSame(1, MotivoConsultaPaciente::where('reg_medico', $medico->reg_medico)->count());
        // Y no deja un segundo rastro: la fila no cambió.
        $this->assertSame(1, SyncChange::where('table_name', 'motivo_consulta_paciente')
            ->where('operation', 'created')->count());
    }

    public function test_quitar_un_motivo_lo_saca_de_la_consulta_y_no_del_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $catalogo = $this->motivo($medico, '0007', 'SANGRADO');
        $fila = MotivoConsultaPaciente::create([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => '0007',
            'nrohistoria' => 1001, 'nroconsulta' => 1,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->delete(
            route('clinica.consulta.motivos.quitar', [$consulta->id, $fila->id]),
        );

        $respuesta->assertRedirect($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS));

        $this->assertNull(MotivoConsultaPaciente::find($fila->id));
        $this->assertNotNull(MotivoConsulta::find($catalogo->id), 'el catálogo no se toca');

        // El rastro de la baja, que es lo que hace que los demás equipos lo suelten.
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'motivo_consulta_paciente',
            'record_id'  => $fila->id,
            'operation'  => 'deleted',
            'source'     => 'web',
        ]);
    }

    public function test_un_motivo_que_no_esta_en_el_catalogo_no_se_puede_agregar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.motivos.agregar', $consulta->id), ['codemotivo' => '9999'])
            ->assertNotFound();

        $this->assertSame(0, MotivoConsultaPaciente::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_catalogo_de_otro_medico_no_sirve_para_agregar(): void
    {
        [, $otro] = $this->medicoConAcceso();
        $this->motivo($otro, '0001', 'AJENO');

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.motivos.agregar', $consulta->id), ['codemotivo' => '0001'])
            ->assertNotFound();
    }

    public function test_la_consulta_de_otro_medico_da_404_al_agregar_y_al_quitar(): void
    {
        [, $otro] = $this->medicoConAcceso();
        [$pacienteAjeno, $consultaAjena] = $this->pacienteYConsulta($otro, 1001);
        MotivoConsultaPaciente::create([
            'reg_medico' => $otro->reg_medico, 'codemotivo' => '0001',
            'nrohistoria' => 1001, 'nroconsulta' => 1,
        ]);
        $filaAjena = MotivoConsultaPaciente::where('reg_medico', $otro->reg_medico)->firstOrFail();

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->motivo($medico, '0001', 'PROPIO');

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $contexto->post(route('clinica.consulta.motivos.agregar', $consultaAjena->id), ['codemotivo' => '0001'])
            ->assertNotFound();
        $contexto->delete(route('clinica.consulta.motivos.quitar', [$consultaAjena->id, $filaAjena->id]))
            ->assertNotFound();

        $this->assertNotNull(MotivoConsultaPaciente::find($filaAjena->id));
        $this->assertNotNull(\App\Models\Paciente::find($pacienteAjeno));
    }

    // ------------------------------------------------------------------- Crear el motivo que falta

    public function test_crear_un_motivo_nuevo_desde_el_buscador_lo_agrega_a_la_consulta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->motivo($medico, '0001', 'DOLOR PELVICO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.motivos.crear', $consulta->id),
            ['descripcion' => '  dolor   de cabeza '],
        );

        $respuesta->assertRedirect($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'DOLOR DE CABEZA'));

        // El código lo da el servidor (el siguiente del catálogo) y el texto va en mayúsculas.
        $nuevo = MotivoConsulta::where('descripcion', 'DOLOR DE CABEZA')->firstOrFail();
        $this->assertSame('0002', $nuevo->codemotivo);
        $this->assertSame($medico->reg_medico, $nuevo->reg_medico);

        // Y queda agregado a la consulta en el mismo paso.
        $this->assertDatabaseHas('motivo_consulta_paciente', [
            'reg_medico'  => $medico->reg_medico,
            'codemotivo'  => '0002',
            'nrohistoria' => 1001,
            'nroconsulta' => 1,
        ]);

        $fila = MotivoConsultaPaciente::where('codemotivo', '0002')->firstOrFail();
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'motivo_consulta_paciente',
            'record_id'  => $fila->id,
            'operation'  => 'created',
            'source'     => 'web',
        ]);
    }

    public function test_crear_un_motivo_que_ya_existe_usa_el_existente_y_no_duplica(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $existente = $this->motivo($medico, '0003', 'DOLOR PÉLVICO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.motivos.crear', $consulta->id),
            ['descripcion' => 'dolor pélvico'],
        );

        $respuesta->assertRedirect($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'catálogo'));

        // No se duplica el catálogo y el existente queda agregado a la consulta (que es lo que el médico
        // quiso al escribir el motivo).
        $this->assertSame(1, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertDatabaseHas('motivo_consulta_paciente', [
            'reg_medico' => $medico->reg_medico,
            'codemotivo' => $existente->codemotivo,
        ]);
    }

    public function test_un_motivo_nuevo_sin_nombre_no_se_crea_y_no_borra_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.motivos.crear', $consulta->id),
            ['descripcion' => '   '],
        );

        $respuesta->assertRedirect($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS));
        $respuesta->assertSessionHasErrors('descripcion');
        // El diálogo se vuelve a abrir para poder corregir.
        $respuesta->assertSessionHas('crear_motivo');
        $this->assertSame(0, MotivoConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_buscador_no_distingue_tildes_ni_mayusculas_y_marca_los_que_ya_estan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->motivo($medico, '0001', 'DOLOR PÉLVICO');
        $this->motivo($medico, '0002', 'CONTROL');
        MotivoConsultaPaciente::create([
            'reg_medico' => $medico->reg_medico, 'codemotivo' => '0002',
            'nrohistoria' => 1001, 'nroconsulta' => 1,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson(route('clinica.consulta.motivos.buscar', $consulta->id) . '?q=pelvico');

        $respuesta->assertOk();
        $respuesta->assertJsonPath('existe', false);
        $respuesta->assertJsonCount(1, 'motivos');
        $respuesta->assertJsonPath('motivos.0.codemotivo', '0001');
        $respuesta->assertJsonPath('motivos.0.descripcion', 'DOLOR PÉLVICO');
        $respuesta->assertJsonPath('motivos.0.yaEsta', false);

        // Y con un motivo de la consulta: aparece marcado como "ya está".
        $conCargado = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson(route('clinica.consulta.motivos.buscar', $consulta->id) . '?q=CONTROL');

        $conCargado->assertJsonPath('motivos.0.yaEsta', true);

        // Lo escrito que ya es un motivo del catálogo (aunque cambien mayúsculas y tildes) dice `existe`:
        // es lo que apaga el "crear el que falta" del buscador.
        $exacto = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson(route('clinica.consulta.motivos.buscar', $consulta->id) . '?q=dolor pelvico');

        $exacto->assertJsonPath('existe', true);
    }

    public function test_el_catalogo_trae_un_motivo_por_nombre_aunque_el_legado_tenga_repetidos(): void
    {
        // El catálogo real trae el mismo nombre con dos códigos: se ofrece uno solo (el de código más bajo),
        // como el selector del móvil.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->motivo($medico, '0009', 'DOLOR PELVICO');
        $this->motivo($medico, '0003', 'DOLOR PELVICO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson(route('clinica.consulta.motivos.buscar', $consulta->id) . '?q=DOLOR');

        $respuesta->assertJsonCount(1, 'motivos');
        $respuesta->assertJsonPath('motivos.0.codemotivo', '0003');
    }

    // ------------------------------------------------------------------------ La enfermedad actual

    public function test_la_enfermedad_actual_se_edita_y_se_guarda_con_el_boton(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Lo que escribió el escritorio se ve tal cual en el formulario.
        $consulta->forceFill(['enfermedadactual' => 'Dolor de 3 días.'])->save();

        $pantalla = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, ConsultaShellVista::SECCION_ENFERMEDAD));

        $pantalla->assertOk();
        $pantalla->assertSee('Dolor de 3 días.', false);
        $pantalla->assertSee('Guardar la enfermedad actual', false);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.enfermedad.guardar', $consulta->id),
            ['enfermedadactual' => "Dolor pélvico de 3 días.\nNiega fiebre."],
        );

        $respuesta->assertRedirect($this->urlSeccion($consulta, ConsultaShellVista::SECCION_ENFERMEDAD));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'Enfermedad actual'));

        $this->assertSame(
            "Dolor pélvico de 3 días.\nNiega fiebre.",
            $consulta->fresh()->enfermedadactual,
        );
    }

    public function test_guardar_la_enfermedad_actual_deja_el_rastro_para_el_sync(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.enfermedad.guardar', $consulta->id),
            ['enfermedadactual' => 'Dolor de 3 días.'],
        );

        // Una fila por columna, con el valor nuevo y `source = web`: es lo que evita que el teléfono
        // pise el cambio con una edición vieja (PLAN-WEB.md, R3).
        $cambio = SyncChange::where('table_name', 'consultas')
            ->where('record_id', $consulta->id)
            ->where('column_name', 'enfermedadactual')
            ->firstOrFail();

        $this->assertSame('updated', $cambio->operation);
        $this->assertSame('Dolor de 3 días.', $cambio->value);
        $this->assertSame('web', $cambio->source);
        $this->assertSame($medico->reg_medico, $cambio->reg_medico);
    }

    public function test_una_edicion_mas_nueva_del_sync_no_se_pisa(): void
    {
        // El guard es el last-write-wins por columna del sync: si hay un cambio **más nuevo** sobre
        // `enfermedadactual` (de otro equipo), la web no lo pisa y lo dice, en vez de mentir con un
        // "Guardado".
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->forceFill(['enfermedadactual' => 'Lo del otro equipo.'])->save();

        SyncChange::create([
            'reg_medico' => $medico->reg_medico,
            'table_name' => 'consultas',
            'record_id'  => $consulta->id,
            'operation'  => 'updated',
            'column_name' => 'enfermedadactual',
            'value'      => 'Lo del otro equipo.',
            'occurred_at' => $this->hoy->copy()->addMinute(),
            'source'     => 'mobile',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.enfermedad.guardar', $consulta->id),
            ['enfermedadactual' => 'Lo mío, más viejo.'],
        );

        $respuesta->assertSessionHas('error');
        $this->assertSame('Lo del otro equipo.', $consulta->fresh()->enfermedadactual);
    }

    public function test_guardar_vacio_borra_el_texto_y_tambien_deja_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->forceFill(['enfermedadactual' => 'Algo.'])->save();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.enfermedad.guardar', $consulta->id),
            ['enfermedadactual' => ''],
        );

        $this->assertNull($consulta->fresh()->enfermedadactual);
        $this->assertDatabaseHas('sync_changes', [
            'table_name'  => 'consultas',
            'record_id'   => $consulta->id,
            'column_name' => 'enfermedadactual',
            'operation'   => 'updated',
            'source'      => 'web',
        ]);
    }

    // ------------------------------------------------------------------------------------ Los roles

    public function test_la_secretaria_no_entra_a_ninguna_de_las_dos_secciones_ni_puede_escribir(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->motivo($medico, '0001', 'DOLOR PELVICO');

        $contexto = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria);

        $contexto->get($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS))->assertForbidden();
        $contexto->get($this->urlSeccion($consulta, ConsultaShellVista::SECCION_ENFERMEDAD))->assertForbidden();
        $contexto->post(route('clinica.consulta.motivos.agregar', $consulta->id), ['codemotivo' => '0001'])->assertForbidden();
        $contexto->post(route('clinica.consulta.motivos.crear', $consulta->id), ['descripcion' => 'NUEVO'])->assertForbidden();
        $contexto->post(route('clinica.consulta.enfermedad.guardar', $consulta->id), ['enfermedadactual' => 'x'])->assertForbidden();

        $this->assertSame(0, MotivoConsultaPaciente::where('reg_medico', $medico->reg_medico)->count());
        $this->assertNull($consulta->fresh()->enfermedadactual);
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->get($this->urlSeccion($consulta, ConsultaShellVista::SECCION_MOTIVOS))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------------- El shell

    public function test_el_menu_muestra_el_grupo_consulta_con_sus_items_y_abre_sus_secciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1001);
        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();

        // El grupo «Consulta» del menú lateral (revisión de Alexander) reemplazó al panel de la pantalla.
        $respuesta->assertSee('data-menu="consulta"', false);
        $respuesta->assertDontSee('Secciones de la consulta', false);
        $respuesta->assertDontSee('13 de 14 disponibles', false);

        // Trae los 14 ítems: 13 enlazan a su sección dentro de esta consulta y la pre-factura va deshabilitada.
        $respuesta->assertSee('Pre-factura');
        $respuesta->assertSee('aria-disabled="true"', false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'motivos']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'enfermedad']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'evolucion']), false);
        // Los exámenes (WEB-4.7) también: desde esta tarea el ítem 7 abre su sección dentro del shell.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'examenes']), false);
        // Y los récipes (WEB-5.1): el ítem 10 abre su sección dentro del shell.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes']), false);
        // Y las solicitudes (WEB-5.3a): el ítem 11 abre su sección dentro del shell.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes']), false);
        // Y los datos personales (WEB-11.25): el ítem 13 es una sección más, dentro de la misma consulta.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'datos']), false);
        // Y el estudio ecográfico y el control de embarazo, que ya tienen pantalla.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'estudio']), false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'embarazo']), false);
    }

    public function test_el_item_activo_del_menu_se_marca_por_la_seccion_abierta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, ConsultaShellVista::SECCION_RECIPES))
            ->assertOk()
            ->getContent();

        // El grupo se abre solo y el hijo activo es Récipes (una sola marca de página actual en el menú).
        preg_match('/data-menu="consulta".*?<\/details>/s', $html, $bloque);
        $this->assertNotSame('', $bloque[0] ?? '', 'falta el grupo «Consulta» del menú');
        $this->assertMatchesRegularExpression('/nav-link active[^>]*title="Récipes"/', $bloque[0]);
        $this->assertSame(1, substr_count($bloque[0], 'aria-current="page"'));
    }

    public function test_fuera_de_una_consulta_el_grupo_no_aparece(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // La agenda (y cualquier pantalla que no sea la consulta) no ofrece el grupo.
        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.agenda'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-menu="consulta"', $html);
        $this->assertStringNotContainsString('Pre-factura', $html);
    }

    // ------------------------- La consulta abre en Motivos de consulta (O-06, 2026-10-17)

    public function test_sin_seccion_la_consulta_abre_en_motivos_y_el_menu_lo_marca(): void
    {
        // O-06: sin `?seccion=` la consulta abre en **Motivos de consulta** (el ítem 1), sin redirect —la URL
        // queda limpia— y con el ítem marcado en el grupo «Consulta» del menú. Antes pintaba el estado vacío
        // «Elige una sección en el menú».
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id))->assertOk()->getContent();

        // Llegó a Motivos, y no al vacío.
        $this->assertStringContainsString('id="consulta-motivos"', $html);
        $this->assertStringContainsString('Motivos de esta consulta', $html);
        $this->assertStringNotContainsString('Elige una sección en el menú', $html);

        // El menú marca Motivos de consulta (y sólo ese ítem lleva la marca de página actual del grupo).
        preg_match('/data-menu="consulta".*?<\/details>/s', $html, $bloque);
        $this->assertNotSame('', $bloque[0] ?? '', 'falta el grupo «Consulta» del menú');
        $this->assertMatchesRegularExpression('/nav-link active[^>]*title="Motivos de consulta"/', $bloque[0]);
        $this->assertSame(1, substr_count($bloque[0], 'aria-current="page"'));
    }

    public function test_una_seccion_valida_sigue_cargando_la_suya(): void
    {
        // El default de O-06 es sólo del punto de entrada: `?seccion=enfermedad` sigue abriendo lo suyo.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'enfermedad']));

        $respuesta->assertOk();
        $respuesta->assertSee('id="consulta-enfermedad"', false);
        $respuesta->assertDontSee('id="consulta-motivos"', false);
        $respuesta->assertDontSee('Elige una sección en el menú', false);
    }

    public function test_una_seccion_inventada_en_la_url_no_rompe_la_pantalla(): void
    {
        // El estado vacío no se borra (O-06): sigue siendo el destino de una sección que no existe.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'prefactura']));

        $respuesta->assertOk();
        $respuesta->assertSee('Elige una sección en el menú', false);
        $respuesta->assertDontSee('id="consulta-motivos"', false);
    }
}
