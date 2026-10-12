<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **El catálogo de motivos de cita con su monto** (WEB-8.1c): `/clinica/catalogos/motivos-cita`.
 *
 * Lo que estos casos fijan: el listado paginado con buscador sin tildes, el alta (código correlativo **por médico**, la razón
 * en mayúsculas, el **precio**, la sede y la marca de varias citas), que **no se duplica dentro de la misma sede** pero el
 * mismo nombre sí puede estar en otra, la edición de sólo lo que cambia con el rastro `source = web`, y **quitar = borrar si
 * no tiene uso; si lo tiene, decir cuántas citas y no borrar**. Además, el circuito con la cita: el formulario ofrece los
 * motivos de la sede (más los de todas), un monto vacío toma el precio vigente del motivo y editar el precio no cambia las
 * citas que ya existen. La marca de WEB-2.17 sigue en `MotivosDeCitaWebTest`.
 */
class MotivosDeCitaCatalogoWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lunes = Carbon::parse('2026-10-05')->startOfWeek(Carbon::MONDAY);
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
        $regMedico = 'mcita-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'mcita-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Motivos', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaDe(Medico $medico): User
    {
        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'mcita-s-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    private function centro(string $nombre): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'), 'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'), 'name' => $nombre, 'address' => 'Dirección de prueba',
        ]);
    }

    private function sede(Medico $medico, string $centro, string $numero = 'C1'): Office
    {
        return Office::create([
            'medical_center_id' => $this->centro($centro)->id, 'medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico,
            'office_number' => $numero, 'modalidad' => Office::MODALIDAD_HORA, 'duracion_cita' => 30, 'activo' => true,
        ]);
    }

    private function bloque(Office $sede): void
    {
        OfficeSchedule::create([
            'office_id' => $sede->id, 'reg_medico' => $sede->reg_medico, 'dia_semana' => 1,
            'hora_inicio' => '08:00', 'hora_fin' => '12:00', 'cupo' => null,
        ]);
    }

    private function motivo(Medico $medico, string $codigo, string $razon, array $extra = []): MotivoCita
    {
        return MotivoCita::create(array_merge([
            'reg_medico' => $medico->reg_medico, 'codigo' => $codigo, 'tipo_atencion' => $razon,
        ], $extra));
    }

    private function cita(Medico $medico, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $medico->reg_medico, 'fecha' => $this->lunes->toDateString(), 'hora_ini' => '09:00:00',
            'numorden' => 1, 'atendido' => 0, 'estado' => Cola::ESTADO_NO_CONFIRMADA,
        ], $extra));
    }

    private function pacienteConHistoria(Medico $medico, int $numhistoria): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('medico_pacientes')->insert([
            'medico_id' => $medico->id, 'paciente_id' => $id, 'numhistoria' => $numhistoria,
            'reg_medico' => $medico->reg_medico, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function trazas(array $ids)
    {
        return SyncChange::where('table_name', 'motivo_cita')->whereIn('record_id', $ids ?: [0])->orderBy('id')->get();
    }

    private function url(string $sufijo = ''): string
    {
        return route('clinica.catalogos.motivos-cita') . $sufijo;
    }

    // ----------------------------------------------------------------------------------------- Listado

    public function test_el_listado_pagina_busca_sin_tildes_y_muestra_precio_sede_y_repetidos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $sede = $this->sede($medico, 'San José');

        foreach (range(1, 28) as $n) {
            $this->motivo($medico, str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'MOTIVO ' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }
        $this->motivo($medico, '29', 'ECOGRAFÍA PÉLVICA', ['precio' => 120.5]);
        // El mismo código en una sede y sin sede (así lo deja la sincronización) y un duplicado dentro de la misma sede.
        $this->motivo($medico, 'PRIM', 'Primera consulta', ['precio' => 150.0]);
        $this->motivo($medico, 'PRIM', 'Primera consulta', ['precio' => 80.0, 'office_id' => $sede->id]);
        $this->motivo($medico, 'CTRL', 'Control');
        $this->motivo($medico, 'CTRL', 'CONTROL');
        $this->motivo($otro, '01', 'DE OTRO MEDICO');

        $this->conContexto($user, $medico, $gineco)->get($this->url())
            ->assertOk()
            ->assertSee('Mostrando 1–25 de 33')
            ->assertDontSee('DE OTRO MEDICO');

        $this->conContexto($user, $medico, $gineco)->get($this->url('?page=2'))
            ->assertOk()->assertSee('Mostrando 26–33 de 33');

        $busqueda = $this->conContexto($user, $medico, $gineco)->get($this->url('?buscar=ecografia+pelvica'));
        $busqueda->assertOk()->assertSee('ECOGRAFÍA PÉLVICA')->assertSee('120,50')->assertSee('Todas las sedes');

        $porSede = $this->conContexto($user, $medico, $gineco)->get($this->url('?buscar=san+jose'));
        $porSede->assertOk()->assertSee('Primera consulta')->assertSee('80,00')->assertSee('San José — C1')
            ->assertSee('Código en 2 sedes');

        $this->conContexto($user, $medico, $gineco)->get($this->url('?buscar=control'))
            ->assertOk()->assertSee('Hay 2 con este nombre en esta sede');

        $this->conContexto($user, $medico, $gineco)->get($this->url('?buscar=zzzz'))
            ->assertOk()->assertSee('Ningún motivo coincide');
    }

    public function test_sin_motivos_el_listado_ofrece_crear_el_primero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get($this->url())
            ->assertOk()->assertSee('Todavía no tienes motivos de cita')->assertSee('Crear el primer motivo');
    }

    public function test_el_listado_ordena_los_codigos_numericos_como_numero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->motivo($medico, '10', 'DIEZ');
        $this->motivo($medico, '2', 'DOS');
        $this->motivo($medico, 'ECO4D', 'ECOGRAFIA CUATRO D');
        $this->motivo($medico, 'ECO', 'ECOGRAFIA SIMPLE');

        $html = $this->conContexto($user, $medico, $gineco)->get($this->url())->assertOk()->getContent();

        $this->assertTrue(strpos($html, 'DOS') < strpos($html, 'DIEZ'));
        $this->assertTrue(strpos($html, 'DIEZ') < strpos($html, 'ECOGRAFIA SIMPLE'));
        // `ECO` va antes que `ECO4D`, aunque el código más largo se creara primero.
        $this->assertTrue(strpos($html, 'ECOGRAFIA SIMPLE') < strpos($html, 'ECOGRAFIA CUATRO D'));
    }

    // -------------------------------------------------------------------------------------------- Alta

    public function test_crear_un_motivo_asigna_el_codigo_que_sigue_por_medico_guarda_el_precio_y_deja_el_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $sede = $this->sede($medico, 'San José');

        $this->motivo($medico, '01', 'PRIMERA CONSULTA');
        $this->motivo($medico, '02', 'CONTROL');
        // Los códigos que no son números (los del móvil) no cuentan; los de otro médico, tampoco.
        $this->motivo($medico, 'ECO4D', 'ECO 4D');
        $this->motivo($otro, '41', 'DEL OTRO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->post($this->url(), [
            'razon' => '  ecografía   obstétrica ', 'precio' => '120.5', 'office_id' => (string) $sede->id, 'admite_varias' => '1',
        ]);

        $respuesta->assertRedirect(route('clinica.catalogos.motivos-cita', ['buscar' => 'ECOGRAFÍA OBSTÉTRICA']));
        $respuesta->assertSessionHas('estado', fn ($texto) => str_contains($texto, 'código 03'));

        $nuevo = MotivoCita::where('reg_medico', $medico->reg_medico)->where('tipo_atencion', 'ECOGRAFÍA OBSTÉTRICA')->firstOrFail();
        $this->assertSame('03', $nuevo->codigo);
        $this->assertSame(120.5, $nuevo->precio);
        $this->assertSame($sede->id, $nuevo->office_id);
        $this->assertTrue($nuevo->admite_varias_citas_pendientes);

        $trazas = $this->trazas([$nuevo->id]);
        $this->assertCount(1, $trazas);
        $this->assertSame('created', $trazas[0]->operation);
        $this->assertSame('web', $trazas[0]->source);
        $this->assertSame($medico->reg_medico, $trazas[0]->reg_medico);

        // El catálogo de otro médico arranca en 01: el correlativo es por médico.
        [$user2, $medico2, $gineco2] = $this->medicoConAcceso();
        $this->conContexto($user2, $medico2, $gineco2)->post($this->url(), ['razon' => 'Consulta']);
        $this->assertSame('01', MotivoCita::where('reg_medico', $medico2->reg_medico)->value('codigo'));
        $this->assertNull(MotivoCita::where('reg_medico', $medico2->reg_medico)->value('precio'));
        $this->assertNull(MotivoCita::where('reg_medico', $medico2->reg_medico)->value('office_id'));
    }

    public function test_dos_altas_seguidas_toman_codigos_distintos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        foreach (['PRIMERA', 'SEGUNDA', 'TERCERA'] as $razon) {
            $this->conContexto($user, $medico, $gineco)->post($this->url(), ['razon' => $razon])->assertSessionDoesntHaveErrors();
        }

        $this->assertSame(['01', '02', '03'], MotivoCita::where('reg_medico', $medico->reg_medico)->orderBy('id')->pluck('codigo')->all());
    }

    public function test_el_codigo_pasa_de_99_sin_chocar_con_el_primero(): void
    {
        // `right("0"+n,2)` del escritorio daba `00` en el 100 y chocaba con el primero: la web escribe 100.
        [, $medico] = $this->medicoConAcceso();
        $this->motivo($medico, '99', 'NOVENTA Y NUEVE');

        $creado = app(\App\Actions\Catalogos\CrearMotivoDeCita::class)->ejecutar($medico, $medico->reg_medico, ['razon' => 'Cien']);

        $this->assertSame('100', $creado->codigo);
    }

    public function test_no_se_duplica_dentro_de_la_misma_sede_pero_el_mismo_nombre_puede_estar_en_otra(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $a = $this->sede($medico, 'San José', 'C1');
        $b = $this->sede($medico, 'Metropolitana', 'C2');
        $this->motivo($medico, '01', 'CONSULTA', ['office_id' => $a->id]);
        $this->motivo($medico, '02', 'CONTROL');

        // Mismo nombre (sin tildes ni mayúsculas) en la misma sede: se rechaza y dice cuál es.
        $r = $this->conContexto($user, $medico, $gineco)->post($this->url(), ['razon' => 'consúlta', 'office_id' => (string) $a->id]);
        $r->assertSessionHasErrors('razon');
        $this->assertStringContainsString('código 01', session('errors')->first('razon'));
        $this->assertStringContainsString('en esa sede', session('errors')->first('razon'));

        // Sin sede contra sin sede.
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['razon' => 'Control'])->assertSessionHasErrors('razon');
        $this->assertStringContainsString('para todas las sedes', session('errors')->first('razon'));

        // En otra sede, o sin sede cuando el existente tiene sede, sí.
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['razon' => 'Consulta', 'office_id' => (string) $b->id])->assertSessionDoesntHaveErrors();
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['razon' => 'Consulta'])->assertSessionDoesntHaveErrors();

        $this->assertSame(4, MotivoCita::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_las_validaciones_del_alta_estan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajena = $this->sede($otro, 'De otro', 'X1');

        $malas = [
            [['razon' => ''], 'razon', 'no tiene nombre'],
            [['razon' => str_repeat('A', 101)], 'razon', 'no puede pasar de 100'],
            [['razon' => str_repeat('A', 301)], 'razon', 'demasiado largo'],
            [['razon' => 'X', 'precio' => '-5'], 'precio', 'no puede ser negativo'],
            [['razon' => 'X', 'precio' => 'abc'], 'precio', 'debe ser un número'],
            [['razon' => 'X', 'office_id' => (string) $ajena->id], 'office_id', 'sede de la lista'],
            [['razon' => 'X', 'office_id' => 'zzz'], 'office_id', 'sede de la lista'],
        ];

        foreach ($malas as [$datos, $campo, $texto]) {
            $this->conContexto($user, $medico, $gineco)->post($this->url(), $datos)->assertSessionHasErrors($campo);
            $this->assertStringContainsString($texto, session('errors')->first($campo));
        }

        $this->assertSame(0, MotivoCita::where('reg_medico', $medico->reg_medico)->count());
    }

    // ----------------------------------------------------------------------------------------- Edición

    public function test_editar_el_precio_escribe_solo_esa_columna_no_renombra_y_no_toca_el_codigo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        // Un motivo con el nombre «bonito» que dejó el móvil: editarle el precio no lo pasa a mayúsculas.
        $motivo = $this->motivo($medico, 'ECO4D', 'Ecografía 4D', ['precio' => 100.0]);

        $this->conContexto($user, $medico, $gineco)->post($this->url(), [
            'id' => $motivo->id, 'razon' => 'Ecografía 4D', 'precio' => '200', 'office_id' => '', 'admite_varias' => '0', 'codigo' => 'HACK',
        ])->assertRedirect($this->url());

        $motivo->refresh();
        $this->assertSame(200.0, $motivo->precio);
        $this->assertSame('Ecografía 4D', $motivo->tipo_atencion);
        $this->assertSame('ECO4D', $motivo->codigo);

        $trazas = $this->trazas([$motivo->id]);
        $this->assertCount(1, $trazas);
        $this->assertSame('updated', $trazas[0]->operation);
        $this->assertSame('precio', $trazas[0]->column_name);
        $this->assertSame('web', $trazas[0]->source);

        // Sin cambios: ni rastro nuevo ni aviso de cambios.
        $r = $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $motivo->id, 'razon' => 'ecografía 4d', 'precio' => '200.00', 'admite_varias' => '0']);
        $r->assertSessionHas('estado', fn ($texto) => str_contains($texto, 'no tenía cambios'));
        $this->assertCount(1, $this->trazas([$motivo->id]));
    }

    public function test_editar_la_razon_la_sede_y_la_marca_y_quitar_el_precio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, 'San José');
        $motivo = $this->motivo($medico, '01', 'CONSULTA', ['precio' => 50.0]);

        $this->conContexto($user, $medico, $gineco)->post($this->url(), [
            'id' => $motivo->id, 'razon' => 'consulta general', 'precio' => '', 'office_id' => (string) $sede->id, 'admite_varias' => '1',
        ])->assertSessionDoesntHaveErrors();

        $motivo->refresh();
        $this->assertSame('CONSULTA GENERAL', $motivo->tipo_atencion);
        $this->assertNull($motivo->precio);
        $this->assertSame($sede->id, $motivo->office_id);
        $this->assertTrue($motivo->admite_varias_citas_pendientes);
        $this->assertEqualsCanonicalizing(['tipo_atencion', 'precio', 'office_id', 'admite_varias_citas_pendientes'], $this->trazas([$motivo->id])->pluck('column_name')->all());

        // Y volver a «todas las sedes» y desmarcar deja `null`.
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $motivo->id, 'razon' => 'CONSULTA GENERAL', 'office_id' => '', 'admite_varias' => '0']);
        $motivo->refresh();
        $this->assertNull($motivo->office_id);
        $this->assertNull($motivo->admite_varias_citas_pendientes);
    }

    public function test_editar_rechaza_el_nombre_de_otro_en_la_misma_sede_pero_deja_editar_un_duplicado_historico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $a = $this->motivo($medico, '01', 'CONSULTA');
        $b = $this->motivo($medico, '02', 'CONTROL');
        $c = $this->motivo($medico, '03', 'CONTROL'); // duplicado histórico (lo trae la sincronización)

        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $b->id, 'razon' => 'consulta'])->assertSessionHasErrors('razon');
        $this->assertSame('CONTROL', $b->refresh()->tipo_atencion);

        // El precio del duplicado se puede editar sin que se le cambie la identidad.
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $c->id, 'razon' => 'CONTROL', 'precio' => '30'])->assertSessionDoesntHaveErrors();
        $this->assertSame(30.0, $c->refresh()->precio);

        // Pasarlo a una sede donde ya está el nombre sí se rechaza.
        $sede = $this->sede($medico, 'San José');
        $this->motivo($medico, '04', 'CONTROL', ['office_id' => $sede->id]);
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $c->id, 'razon' => 'CONTROL', 'office_id' => (string) $sede->id])->assertSessionHasErrors('razon');
        $this->assertNull($c->refresh()->office_id);
    }

    public function test_editar_conserva_una_sede_vieja_que_el_medico_ya_no_tiene(): void
    {
        // Una fila puede apuntar a una sede que ya no está entre las del médico: editar su precio no se la cambia ni la rechaza.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajena = $this->sede($otro, 'De otro', 'X1');
        $motivo = $this->motivo($medico, '01', 'CONSULTA', ['office_id' => $ajena->id]);

        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $motivo->id, 'razon' => 'CONSULTA', 'precio' => '10', 'office_id' => (string) $ajena->id])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($ajena->id, $motivo->refresh()->office_id);
        $this->assertSame(10.0, $motivo->precio);
    }

    public function test_una_edicion_mas_vieja_que_otra_ya_registrada_no_la_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $motivo = $this->motivo($medico, '01', 'CONSULTA', ['precio' => 10.0]);
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));

        // Alguien (el móvil, otra pestaña) editó el precio «en el futuro»: el last-write-wins de la columna lo respeta.
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'motivo_cita', 'record_id' => $motivo->id, 'operation' => 'updated',
            'column_name' => 'precio', 'value' => '99', 'occurred_at' => Carbon::parse('2026-10-07 10:00:00'), 'source' => 'mobile',
        ]);

        $r = $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $motivo->id, 'razon' => 'CONSULTA', 'precio' => '55']);

        $r->assertSessionHas('error', fn ($texto) => str_contains($texto, 'Otro equipo editó'));
        $this->assertSame(10.0, $motivo->refresh()->precio);
    }

    // ------------------------------------------------------------------------------------- La marca

    public function test_el_interruptor_de_la_marca_usa_la_accion_de_edicion_y_deja_su_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $motivo = $this->motivo($medico, '01', 'MASAJE');

        $this->conContexto($user, $medico, $gineco)->patch($this->url('/' . $motivo->id . '/alternar'))
            ->assertRedirect($this->url())
            ->assertSessionHas('estado', fn ($texto) => str_contains($texto, 'varias citas pendientes'));
        $this->assertTrue($motivo->refresh()->admite_varias_citas_pendientes);

        $trazas = $this->trazas([$motivo->id]);
        $this->assertSame('admite_varias_citas_pendientes', $trazas[0]->column_name);
        $this->assertSame('web', $trazas[0]->source);

        $this->conContexto($user, $medico, $gineco)->patch($this->url('/' . $motivo->id . '/alternar'));
        $this->assertNull($motivo->refresh()->admite_varias_citas_pendientes);
        // La marca no toca ninguna otra columna.
        $this->assertSame('MASAJE', $motivo->tipo_atencion);
    }

    public function test_la_pantalla_vieja_de_motivos_redirige_a_la_nueva(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->get('/clinica/agenda/motivos')->assertRedirect(route('login'));
        $this->conContexto($user, $medico, $gineco)->get('/clinica/agenda/motivos')
            ->assertStatus(301)->assertRedirect(route('clinica.catalogos.motivos-cita'));
        $this->assertSame('/clinica/agenda/motivos', route('clinica.agenda.motivos', [], false));
    }

    // ------------------------------------------------------------------------------------------ Quitar

    public function test_quitar_un_motivo_sin_citas_lo_borra_y_deja_el_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $motivo = $this->motivo($medico, '01', 'CONSULTA');
        // Una cita con OTRO código no cuenta.
        $this->cita($medico, ['tipo' => '02']);

        $this->conContexto($user, $medico, $gineco)->delete($this->url('/' . $motivo->id))
            ->assertRedirect($this->url())
            ->assertSessionHas('estado', fn ($texto) => str_contains($texto, 'Quitaste «CONSULTA»'));

        $this->assertNull(MotivoCita::find($motivo->id));
        $baja = $this->trazas([$motivo->id])->last();
        $this->assertSame('deleted', $baja->operation);
        $this->assertSame('web', $baja->source);
    }

    public function test_quitar_un_motivo_con_citas_no_lo_borra_y_dice_cuantas_y_desde_cuando(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $motivo = $this->motivo($medico, '01', 'CONSULTA');
        // Por `tipo` (escritorio y web), por `motivo` (móvil), de otra fecha pasada y de una futura.
        $this->cita($medico, ['tipo' => '01', 'fecha' => '2026-03-02']);
        $this->cita($medico, ['tipo' => '01', 'fecha' => '2026-10-05']);
        $this->cita($medico, ['motivo' => '01', 'fecha' => '2027-01-11']);
        // Una cita de otro médico con el mismo código no cuenta.
        [, $otro] = $this->medicoConAcceso();
        $this->cita($otro, ['tipo' => '01']);

        $this->conContexto($user, $medico, $gineco)->delete($this->url('/' . $motivo->id))->assertRedirect($this->url());

        $this->assertNotNull(MotivoCita::find($motivo->id));
        $error = session('error');
        $this->assertStringContainsString('No se quitó «CONSULTA»', $error);
        $this->assertStringContainsString('3 citas', $error);
        $this->assertStringContainsString('02/03/2026', $error);
        $this->assertSame(0, $this->trazas([$motivo->id])->where('operation', 'deleted')->count());
    }

    public function test_un_codigo_repetido_se_puede_quitar_mientras_otra_fila_lo_conserve(): void
    {
        // Así se limpian los repetidos por sede de la sincronización: las citas siguen resolviendo el nombre por el código.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, 'San José');
        $general = $this->motivo($medico, 'PRIM', 'Primera consulta');
        $deSede = $this->motivo($medico, 'PRIM', 'Primera consulta', ['office_id' => $sede->id]);
        $this->cita($medico, ['tipo' => 'PRIM']);

        $this->conContexto($user, $medico, $gineco)->delete($this->url('/' . $deSede->id))->assertSessionHas('estado');
        $this->assertNull(MotivoCita::find($deSede->id));

        // Pero la última fila con ese código sí está en uso.
        $this->conContexto($user, $medico, $gineco)->delete($this->url('/' . $general->id))->assertSessionHas('error');
        $this->assertNotNull(MotivoCita::find($general->id));
    }

    public function test_el_movil_baja_lo_editado_por_delta_y_se_entera_de_las_bajas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $editado = $this->motivo($medico, '01', 'CONSULTA', ['precio' => 10.0]);
        $quitado = $this->motivo($medico, '02', 'CONTROL');
        $desde = Carbon::now()->addMinutes(5)->toIso8601String();

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $editado->id, 'razon' => 'CONSULTA', 'precio' => '25']);
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['razon' => 'NUEVO', 'precio' => '5']);
        $this->conContexto($user, $medico, $gineco)->delete($this->url('/' . $quitado->id));

        Sanctum::actingAs($user, ['*'], 'api');
        $pull = $this->postJson('/api/app/sync-app-data', ['changes' => [], 'since' => $desde])->assertOk();

        $motivos = collect($pull->json('motivos'));
        $this->assertEquals(25.0, $motivos->firstWhere('id', $editado->id)['precio']);
        $this->assertNotNull($motivos->firstWhere('tipo_atencion', 'NUEVO'));
        // La baja viaja con el nombre de la tabla local del teléfono, que se llama igual que la del servidor.
        $this->assertContains('motivo_cita:' . $quitado->id, collect($pull->json('eliminados'))->map(fn ($e) => $e['table_name'] . ':' . $e['record_id'])->all());
    }

    // ------------------------------------------------------------------------------------- Permisos

    public function test_la_secretaria_recibe_403_el_medico_ajeno_404_y_el_invitado_va_al_login(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $secretaria = $this->secretariaDe($medico);
        $ajeno = $this->motivo($otro, '01', 'AJENO');
        $propio = $this->motivo($medico, '01', 'PROPIO');

        // El invitado primero: `actingAs` se queda en el test (trampa 47).
        $this->get($this->url())->assertRedirect(route('login'));
        $this->post($this->url(), ['razon' => 'X'])->assertRedirect(route('login'));
        $this->delete($this->url('/' . $propio->id))->assertRedirect(route('login'));

        $this->conContexto($secretaria, $medico, $gineco)->get($this->url())->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->post($this->url(), ['razon' => 'X'])->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->patch($this->url('/' . $propio->id . '/alternar'))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->delete($this->url('/' . $propio->id))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->get('/clinica/agenda/motivos')->assertForbidden();

        $this->conContexto($user, $medico, $gineco)->get($this->url('?editar=' . $ajeno->id))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $ajeno->id, 'razon' => 'X'])->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->patch($this->url('/' . $ajeno->id . '/alternar'))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->delete($this->url('/' . $ajeno->id))->assertNotFound();

        $this->assertNotNull(MotivoCita::find($ajeno->id));
        $this->assertSame('AJENO', $ajeno->refresh()->tipo_atencion);
        $this->assertNotNull(MotivoCita::find($propio->id));
    }

    public function test_el_menu_lateral_y_el_mas_de_la_agenda_ofrecen_motivos_de_cita_solo_al_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaDe($medico);

        $agenda = route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->lunes->toDateString(), 'sede' => 'todas']);

        $this->conContexto($user, $medico, $gineco)->get($agenda)->assertOk()
            ->assertSee(route('clinica.catalogos.motivos-cita'), false)->assertSee('Motivos de cita');

        $html = $this->conContexto($secretaria, $medico, $gineco)->get($agenda)->assertOk()->getContent();
        $this->assertStringNotContainsString('/catalogos/', $html);
        $this->assertStringNotContainsString('Motivos de cita', $html);
    }

    // ------------------------------------------------------------------- El circuito con la cita

    public function test_el_formulario_ofrece_los_motivos_de_la_sede_y_los_de_todas_pero_no_los_de_otra_sede(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $a = $this->sede($medico, 'San José', 'C1');
        $b = $this->sede($medico, 'Metropolitana', 'C2');
        $this->bloque($a);

        $this->motivo($medico, '01', 'GENERAL', ['precio' => 10.0]);
        $this->motivo($medico, '02', 'SOLO EN A', ['office_id' => $a->id, 'precio' => 20.0]);
        $this->motivo($medico, '03', 'SOLO EN B', ['office_id' => $b->id, 'precio' => 30.0]);

        $html = $this->conContexto($user, $medico, $gineco)->get('/clinica/agenda/nueva?sede=' . $a->id . '&fecha=' . $this->lunes->toDateString())
            ->assertOk()->getContent();

        $this->assertStringContainsString('>GENERAL</option>', $html);
        $this->assertStringContainsString('>SOLO EN A</option>', $html);
        $this->assertStringNotContainsString('SOLO EN B', $html);
        // El precio vigente viaja en cada opción para la ayuda del monto.
        $this->assertStringContainsString('data-precio="20.00"', $html);
        $this->assertStringContainsString('data-precio-texto="20,00"', $html);
        $this->assertStringContainsString('Vacío = el precio del motivo.', $html);
    }

    public function test_si_la_razon_se_repite_entre_la_sede_y_todas_se_ofrece_la_de_la_sede(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $a = $this->sede($medico, 'San José', 'C1');
        $this->bloque($a);
        $this->motivo($medico, '01', 'CONSULTA', ['precio' => 10.0]);
        $this->motivo($medico, '05', 'Consulta', ['office_id' => $a->id, 'precio' => 99.0]);

        $html = $this->conContexto($user, $medico, $gineco)->get('/clinica/agenda/nueva?sede=' . $a->id . '&fecha=' . $this->lunes->toDateString())
            ->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<option value="(01|05)"/', $html));
        $this->assertStringContainsString('<option value="05"', $html);
        $this->assertStringContainsString('data-precio="99.00"', $html);
    }

    public function test_el_formulario_enlaza_al_catalogo_solo_para_el_medico_y_el_estado_vacio_dice_que_hacer(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaDe($medico);
        $a = $this->sede($medico, 'San José', 'C1');
        $this->bloque($a);
        $nueva = '/clinica/agenda/nueva?sede=' . $a->id . '&fecha=' . $this->lunes->toDateString();

        // Sin motivos: el médico puede crear el primero; la secretaría tiene que pedírselo.
        $this->conContexto($user, $medico, $gineco)->get($nueva)->assertOk()
            ->assertSee('Esta sede todavía no tiene motivos de cita')->assertSee('Crea el primero')
            ->assertSee(route('clinica.catalogos.motivos-cita', ['nuevo' => 1]), false);
        $this->conContexto($secretaria, $medico, $gineco)->get($nueva)->assertOk()
            ->assertSee('Esta sede todavía no tiene motivos de cita')->assertSee('Pídele al médico que los cargue')
            ->assertDontSee('Crea el primero');

        // Con motivos: «Administrar motivos» sólo para el médico.
        $this->motivo($medico, '01', 'GENERAL');
        $this->conContexto($user, $medico, $gineco)->get($nueva)->assertOk()->assertSee('Administrar motivos');
        $html = $this->conContexto($secretaria, $medico, $gineco)->get($nueva)->assertOk()->getContent();
        $this->assertStringNotContainsString('Administrar motivos', $html);
        $this->assertStringNotContainsString('/catalogos/', $html);
    }

    public function test_el_monto_vacio_toma_el_precio_vigente_del_motivo_y_el_escrito_manda_y_editar_el_precio_no_toca_citas_hechas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, 'San José');
        $this->bloque($sede);
        $paciente1 = $this->pacienteConHistoria($medico, 2001);
        $paciente2 = $this->pacienteConHistoria($medico, 2002);
        $motivo = $this->motivo($medico, '01', 'CONSULTA', ['precio' => 100.0]);

        $agendar = fn (int $paciente, string $hora, array $extra = []) => $this->conContexto($user, $medico, $gineco)->post('/clinica/agenda', array_merge([
            'sede' => $sede->id, 'fecha' => $this->lunes->toDateString(), 'hora' => $hora, 'paciente_id' => $paciente, 'motivo' => '01',
        ], $extra))->assertSessionDoesntHaveErrors();

        $agendar($paciente1, '09:00');
        $primera = Cola::where('reg_medico', $medico->reg_medico)->where('numhistoria', 2001)->firstOrFail();
        $this->assertSame(100.0, (float) $primera->monto);
        $this->assertSame('01', $primera->tipo);

        // El precio se edita en el catálogo: la cita ya agendada conserva su monto…
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $motivo->id, 'razon' => 'CONSULTA', 'precio' => '250']);
        $this->assertSame(100.0, (float) $primera->refresh()->monto);

        // …y la cita siguiente, con el monto vacío, toma el precio nuevo; con un monto escrito, el escrito.
        $agendar($paciente2, '10:00');
        $this->assertSame(250.0, (float) Cola::where('reg_medico', $medico->reg_medico)->where('numhistoria', 2002)->value('monto'));

        Cola::where('reg_medico', $medico->reg_medico)->where('numhistoria', 2002)->delete();
        $agendar($paciente2, '10:00', ['monto' => '40']);
        $this->assertSame(40.0, (float) Cola::where('reg_medico', $medico->reg_medico)->where('numhistoria', 2002)->value('monto'));

        // Sin precio en el motivo y sin monto escrito, queda sin monto (como siempre).
        $this->conContexto($user, $medico, $gineco)->post($this->url(), ['id' => $motivo->id, 'razon' => 'CONSULTA', 'precio' => '']);
        Cola::where('reg_medico', $medico->reg_medico)->where('numhistoria', 2002)->delete();
        $agendar($paciente2, '10:00');
        $this->assertNull(Cola::where('reg_medico', $medico->reg_medico)->where('numhistoria', 2002)->value('monto'));
    }

    public function test_la_secretaria_agenda_eligiendo_entre_los_motivos_del_medico(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaDe($medico);
        $sede = $this->sede($medico, 'San José');
        $this->bloque($sede);
        $paciente = $this->pacienteConHistoria($medico, 2001);
        $this->motivo($medico, '01', 'CONSULTA', ['precio' => 75.0]);

        $this->conContexto($secretaria, $medico, $gineco)->get('/clinica/agenda/nueva?sede=' . $sede->id . '&fecha=' . $this->lunes->toDateString())
            ->assertOk()->assertSee('>CONSULTA</option>', false);

        $this->conContexto($secretaria, $medico, $gineco)->post('/clinica/agenda', [
            'sede' => $sede->id, 'fecha' => $this->lunes->toDateString(), 'hora' => '09:00', 'paciente_id' => $paciente, 'motivo' => '01',
        ])->assertSessionDoesntHaveErrors();

        $cita = Cola::where('reg_medico', $medico->reg_medico)->where('numhistoria', 2001)->firstOrFail();
        $this->assertSame('01', $cita->tipo);
        $this->assertSame(75.0, (float) $cita->monto);
    }

    public function test_editar_una_cita_con_un_motivo_de_otra_sede_no_lo_pierde(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $a = $this->sede($medico, 'San José', 'C1');
        $b = $this->sede($medico, 'Metropolitana', 'C2');
        $this->bloque($a);
        $this->motivo($medico, '03', 'SOLO EN B', ['office_id' => $b->id]);
        $cita = $this->cita($medico, ['tipo' => '03', 'medical_center_id' => $a->medical_center_id]);

        $html = $this->conContexto($user, $medico, $gineco)->get('/clinica/agenda/' . $cita->id . '/editar')->assertOk()->getContent();

        $this->assertStringContainsString('<option value="03"', $html);
        $this->assertStringContainsString('no está en el catálogo de esta sede', $html);
    }
}
