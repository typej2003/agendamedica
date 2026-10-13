<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
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
 * **Los catálogos clínicos del médico** (WEB-8.1b): diagnósticos, motivos de consulta, exámenes, tipos de examen y médicos
 * referidos, en `/clinica/catalogos/...`.
 *
 * Lo que estos casos fijan, por catálogo: el listado paginado con buscador sin tildes (y los repetidos del legado a la vista),
 * el alta (que **no duplica**), la edición de sólo lo que cambia con el rastro `source = web`, y **quitar = borrar si no
 * tiene uso; si lo tiene, avisar dónde y no borrar**. Además: la cédula del médico referido no se edita, el 404 del médico
 * ajeno, el 403 de la secretaría, el invitado al login y que las altas rápidas de la consulta siguen igual.
 */
class CatalogosClinicosWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));
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
        $regMedico = 'cat-clin-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'catclin-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Catalogos', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    private function fila(string $tabla, Medico $medico, array $datos): int
    {
        return (int) DB::table($tabla)->insertGetId(array_merge([
            'reg_medico' => $medico->reg_medico, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $datos));
    }

    private function diagnostico(Medico $m, string $codigo, string $descripcion): int
    {
        return $this->fila('diagnosticos', $m, ['codediagnostico' => $codigo, 'descripcion' => $descripcion]);
    }

    private function motivo(Medico $m, string $codigo, string $descripcion): int
    {
        return $this->fila('motivos_consulta', $m, ['codemotivo' => $codigo, 'descripcion' => $descripcion]);
    }

    private function tipo(Medico $m, string $codigo, string $tipo): int
    {
        return $this->fila('tipos_examenes', $m, ['codetipo' => $codigo, 'tipo' => $tipo]);
    }

    private function examen(Medico $m, string $codigo, string $examen, ?string $codetipo = null): int
    {
        return $this->fila('examenes', $m, ['codeexamen' => $codigo, 'examen' => $examen, 'codetipo' => $codetipo]);
    }

    private function especialidad(Medico $m, string $codigo = '002', string $nombre = 'CARDIOLOGIA'): void
    {
        $this->fila('especial', $m, ['codeespecial' => $codigo, 'especialidad' => $nombre]);
    }

    private function doctor(Medico $m, int $cedula, string $apellidos, string $nombres, string $especialidad = '002', array $extra = []): int
    {
        return $this->fila('doctores', $m, array_merge([
            'cedula' => $cedula, 'apellidos' => $apellidos, 'nombres' => $nombres, 'codeespecial' => $especialidad,
        ], $extra));
    }

    private function trazas(string $tabla, array $ids)
    {
        return SyncChange::where('table_name', $tabla)->whereIn('record_id', $ids ?: [0])->orderBy('id')->get();
    }

    private function usoClinico(string $tabla, Medico $m, array $datos): void
    {
        $this->fila($tabla, $m, array_merge(['nrohistoria' => 1001, 'nroconsulta' => 1], $datos));
    }

    // ------------------------------------------------------------------------ Diagnósticos

    public function test_el_listado_de_diagnosticos_pagina_busca_sin_tildes_y_avisa_los_repetidos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        foreach (range(1, 30) as $n) {
            $this->diagnostico($medico, str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'DIAGNOSTICO ' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }
        $this->diagnostico($medico, '0100', 'ANEMIA FERROPÉNICA');
        $this->diagnostico($medico, '0101', 'ANEMIA FERROPENICA');
        $this->diagnostico($otro, '0001', 'DE OTRO MEDICO');

        $pagina1 = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.diagnosticos'));
        $pagina1->assertOk()->assertSee('Mostrando 1–25 de 32')->assertDontSee('DE OTRO MEDICO');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.diagnosticos', ['page' => 2]))
            ->assertOk()->assertSee('Mostrando 26–32 de 32');

        // Sin tildes ni mayúsculas, y se ven los dos repetidos con su aviso.
        $busqueda = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.diagnosticos', ['buscar' => 'ferropenica']));
        $busqueda->assertOk()->assertSee('ANEMIA FERROPÉNICA')->assertSee('ANEMIA FERROPENICA')->assertSee('Hay 2 con este nombre');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.diagnosticos', ['buscar' => 'zzzz']))
            ->assertOk()->assertSee('Ningún diagnóstico coincide');
    }

    public function test_crear_un_diagnostico_usa_la_regla_del_alta_rapida_no_duplica_y_deja_el_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->diagnostico($medico, '0007', 'DOLOR PELVICO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['descripcion' => '  quiste   ovárico ']);
        $respuesta->assertRedirect(route('clinica.catalogos.diagnosticos', ['buscar' => 'QUISTE OVÁRICO']));

        $nuevo = DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)->where('descripcion', 'QUISTE OVÁRICO')->first();
        $this->assertNotNull($nuevo);
        $this->assertSame('0008', $nuevo->codediagnostico);

        $traza = $this->trazas('diagnosticos', [$nuevo->id])->first();
        $this->assertSame('created', $traza->operation);
        $this->assertSame('web', $traza->source);

        // Un duplicado (sin tildes ni mayúsculas) se rechaza con el código del que ya existe.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['descripcion' => 'dolor pélvico'])
            ->assertSessionHasErrors('descripcion');
        $this->assertSame(1, DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)->where('descripcion', 'DOLOR PELVICO')->count());

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['descripcion' => ''])
            ->assertSessionHasErrors('descripcion');
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['descripcion' => str_repeat('A', 51)])
            ->assertSessionHasErrors('descripcion');
    }

    public function test_editar_un_diagnostico_escribe_solo_lo_que_cambia_no_toca_el_codigo_y_rechaza_el_nombre_de_otro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->diagnostico($medico, '0001', 'MIOMA');
        $this->diagnostico($medico, '0002', 'QUISTE');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['id' => $id, 'descripcion' => 'mioma uterino'])
            ->assertRedirect(route('clinica.catalogos.diagnosticos'));

        $fila = DB::table('diagnosticos')->find($id);
        $this->assertSame('MIOMA UTERINO', $fila->descripcion);
        $this->assertSame('0001', $fila->codediagnostico);
        $this->assertNotSame('2026-01-01 00:00:00', $fila->updated_at);

        $trazas = $this->trazas('diagnosticos', [$id]);
        $this->assertCount(1, $trazas);
        $this->assertSame('updated', $trazas[0]->operation);
        $this->assertSame('descripcion', $trazas[0]->column_name);
        $this->assertSame('web', $trazas[0]->source);

        // Sin cambios: ni rastro nuevo.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['id' => $id, 'descripcion' => 'MIOMA UTERINO']);
        $this->assertCount(1, $this->trazas('diagnosticos', [$id]));

        // El nombre de otro se rechaza; el código que llegue en el formulario no se usa.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['id' => $id, 'descripcion' => 'quiste', 'codediagnostico' => '9999'])
            ->assertSessionHasErrors('descripcion');
        $this->assertSame('0001', DB::table('diagnosticos')->find($id)->codediagnostico);
    }

    public function test_un_duplicado_historico_se_puede_editar_en_otro_campo_y_quitar_si_no_tiene_uso(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $a = $this->diagnostico($medico, '0001', 'ANEMIA');
        $b = $this->diagnostico($medico, '0002', 'ANEMIA');
        $this->usoClinico('diagnostico_paciente', $medico, ['codediagnostico' => '0001']);

        // Guardar el mismo nombre en el repetido no es un duplicado nuevo.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['id' => $b, 'descripcion' => 'ANEMIA'])
            ->assertSessionDoesntHaveErrors();

        // El que está en uso no se quita; el otro sí.
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.diagnosticos.quitar', $a))
            ->assertSessionHas('error');
        $this->assertNotNull(DB::table('diagnosticos')->find($a));

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.diagnosticos.quitar', $b))
            ->assertSessionHas('estado');
        $this->assertNull(DB::table('diagnosticos')->find($b));
        $baja = $this->trazas('diagnosticos', [$b])->last();
        $this->assertSame('deleted', $baja->operation);
        $this->assertSame('web', $baja->source);
    }

    public function test_quitar_un_diagnostico_en_uso_dice_en_cuantas_consultas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->diagnostico($medico, '0001', 'MIOMA');
        $this->usoClinico('diagnostico_paciente', $medico, ['codediagnostico' => '0001']);
        $this->usoClinico('diagnostico_paciente', $medico, ['codediagnostico' => '0001', 'nroconsulta' => 2]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.diagnosticos.quitar', $id));

        $this->assertNotNull(DB::table('diagnosticos')->find($id));
        $this->assertStringContainsString('está en 2 consultas', session('error'));
        $this->assertSame([], $this->trazas('diagnosticos', [$id])->where('operation', 'deleted')->all());
    }

    public function test_renombrar_un_diagnostico_se_ve_renombrado_en_la_consulta_que_ya_lo_tenia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->diagnostico($medico, '0001', 'MIOMA');
        $this->usoClinico('diagnostico_paciente', $medico, ['codediagnostico' => '0001']);

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['id' => $id, 'descripcion' => 'MIOMA UTERINO']);

        $consultas = new \App\Clinica\Consulta\DiagnosticosDeLaConsulta();
        $this->assertSame('MIOMA UTERINO', $consultas->deLaConsulta($medico->reg_medico, '1001', 1)->first()->nombre);
        // Y el creado en el ABM se ofrece en el buscador de la consulta.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['descripcion' => 'nuevo del abm']);
        $this->assertTrue($consultas->buscar($medico->reg_medico, 'nuevo')->isNotEmpty());
    }

    // ------------------------------------------------------------------------ Motivos de consulta

    public function test_motivos_de_consulta_listar_crear_editar_y_quitar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $a = $this->motivo($medico, '0001', 'CONTROL');
        $uso = $this->motivo($medico, '0002', 'DOLOR PÉLVICO');
        $this->usoClinico('motivo_consulta_paciente', $medico, ['codemotivo' => '0002']);

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.motivos-consulta', ['buscar' => 'pelvico']))
            ->assertOk()->assertSee('DOLOR PÉLVICO')->assertDontSee('CONTROL');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.motivos-consulta.guardar'), ['descripcion' => 'dolor pelvico'])
            ->assertSessionHasErrors('descripcion');
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.motivos-consulta.guardar'), ['descripcion' => 'sangrado'])
            ->assertSessionDoesntHaveErrors();
        $nuevo = DB::table('motivos_consulta')->where('reg_medico', $medico->reg_medico)->where('descripcion', 'SANGRADO')->first();
        $this->assertSame('0003', $nuevo->codemotivo);
        $this->assertSame('created', $this->trazas('motivos_consulta', [$nuevo->id])->first()->operation);

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.motivos-consulta.guardar'), ['id' => $a, 'descripcion' => 'control anual']);
        $this->assertSame('CONTROL ANUAL', DB::table('motivos_consulta')->find($a)->descripcion);
        $this->assertSame('updated', $this->trazas('motivos_consulta', [$a])->first()->operation);

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.motivos-consulta.quitar', $uso))->assertSessionHas('error');
        $this->assertNotNull(DB::table('motivos_consulta')->find($uso));
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.motivos-consulta.quitar', $a))->assertSessionHas('estado');
        $this->assertNull(DB::table('motivos_consulta')->find($a));
        $this->assertSame('deleted', $this->trazas('motivos_consulta', [$a])->last()->operation);
    }

    public function test_los_motivos_de_cita_no_se_tocan_y_el_alta_rapida_de_la_consulta_sigue_igual(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->motivo($medico, '0005', 'EXISTENTE');

        [$m, $nuevo] = app(\App\Actions\Consulta\AgregarMotivoAlCatalogo::class)->ejecutar($medico, [$medico->reg_medico], 'otro motivo');
        $this->assertTrue($nuevo);
        $this->assertSame('0006', $m->codemotivo);
        // El alta rápida no escribe rastro (no cambió).
        $this->assertSame(0, SyncChange::where('table_name', 'motivos_consulta')->where('record_id', $m->id)->count());

        [$d, $creado] = app(\App\Actions\Consulta\AgregarDiagnosticoAlCatalogo::class)->ejecutar($medico, [$medico->reg_medico], 'dx rapido');
        $this->assertTrue($creado);
        $this->assertSame('0001', $d->codediagnostico);
    }

    // ------------------------------------------------------------------------ Tipos de examen y exámenes

    public function test_tipos_de_examen_se_numeran_en_tres_digitos_y_no_se_quitan_si_cuelga_un_examen(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $lab = $this->tipo($medico, '001', 'LABORATORIO');
        $vacio = $this->tipo($medico, '002', 'ESTUDIO DE IMAGENES');
        $this->examen($medico, '001', 'GLICEMIA', '001');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tipos-examen.guardar'), ['descripcion' => 'preoperatorios'])->assertSessionDoesntHaveErrors();
        $nuevo = DB::table('tipos_examenes')->where('reg_medico', $medico->reg_medico)->where('tipo', 'PREOPERATORIOS')->first();
        $this->assertSame('003', $nuevo->codetipo);

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tipos-examen.guardar'), ['descripcion' => 'laboratorio'])->assertSessionHasErrors('descripcion');
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tipos-examen.guardar'), ['id' => $vacio, 'descripcion' => str_repeat('A', 41)])->assertSessionHasErrors('descripcion');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tipos-examen.guardar'), ['id' => $vacio, 'descripcion' => 'imagenes y ecografias']);
        $this->assertSame('IMAGENES Y ECOGRAFIAS', DB::table('tipos_examenes')->find($vacio)->tipo);

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.tipos-examen.quitar', $lab))->assertSessionHas('error');
        $this->assertStringContainsString('1 examen («GLICEMIA»)', session('error'));
        $this->assertNotNull(DB::table('tipos_examenes')->find($lab));

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.tipos-examen.quitar', $vacio))->assertSessionHas('estado');
        $this->assertNull(DB::table('tipos_examenes')->find($vacio));
    }

    public function test_examenes_listado_filtro_por_tipo_alta_edicion_y_quitar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipo($medico, '001', 'LABORATORIO');
        $this->tipo($medico, '002', 'ESTUDIO DE IMAGENES');
        $glicemia = $this->examen($medico, '001', 'GLICEMIA', '001');
        $this->examen($medico, '002', 'ECOGRAFÍA PÉLVICA', '002');
        $suelto = $this->examen($medico, '003', 'SIN TIPO', null);
        $this->usoClinico('examen_paciente', $medico, ['codeexamen' => '001']);

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.examenes', ['buscar' => 'ecografia']))
            ->assertOk()->assertSee('ECOGRAFÍA PÉLVICA')->assertDontSee('GLICEMIA');
        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.examenes', ['tipo' => '001']))
            ->assertOk()->assertSee('GLICEMIA')->assertDontSee('ECOGRAFÍA PÉLVICA');
        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.examenes', ['tipo' => 'sin-tipo']))
            ->assertOk()->assertSee('SIN TIPO')->assertDontSee('GLICEMIA');

        // Alta: código de tres dígitos, tipo del catálogo; un tipo ajeno se rechaza; mismo nombre y tipo no duplica.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.examenes.guardar'), ['examen' => 'urea', 'codetipo' => '001'])->assertSessionDoesntHaveErrors();
        $urea = DB::table('examenes')->where('reg_medico', $medico->reg_medico)->where('examen', 'UREA')->first();
        $this->assertSame('004', $urea->codeexamen);
        $this->assertSame('001', $urea->codetipo);
        $this->assertSame('created', $this->trazas('examenes', [$urea->id])->first()->operation);

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.examenes.guardar'), ['examen' => 'UREA', 'codetipo' => '001'])->assertSessionHasErrors('examen');
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.examenes.guardar'), ['examen' => 'UREA', 'codetipo' => '002'])->assertSessionDoesntHaveErrors();
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.examenes.guardar'), ['examen' => 'OTRO', 'codetipo' => '099'])->assertSessionHasErrors('codetipo');

        // Edición: sólo cambia lo que cambió (el tipo), con una traza por columna.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.examenes.guardar'), ['id' => $suelto, 'examen' => 'SIN TIPO', 'codetipo' => '002']);
        $trazas = $this->trazas('examenes', [$suelto]);
        $this->assertCount(1, $trazas);
        $this->assertSame('codetipo', $trazas[0]->column_name);
        $this->assertSame('003', DB::table('examenes')->find($suelto)->codeexamen);

        // Quitar: en uso no; sin uso sí.
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.examenes.quitar', $glicemia))->assertSessionHas('error');
        $this->assertNotNull(DB::table('examenes')->find($glicemia));
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.examenes.quitar', $suelto))->assertSessionHas('estado');
        $this->assertNull(DB::table('examenes')->find($suelto));
    }

    // ------------------------------------------------------------------------ Médicos referidos

    public function test_medicos_referidos_alta_con_nota_edicion_sin_tocar_la_cedula_y_quitar_si_no_hay_referencias(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->especialidad($medico, '002', 'CARDIOLOGIA');
        $this->especialidad($medico, '003', 'UROLOGIA');
        $existente = $this->doctor($medico, 7, 'PEREZ', 'LUIS');
        $usado = $this->doctor($medico, 8, 'GOMEZ', 'ANA');
        $this->usoClinico('referencia', $medico, ['ceduladoctor' => 8, 'referencia' => 'x']);

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.medicos-referidos', ['buscar' => 'cardiologia']))
            ->assertOk()->assertSee('LUIS PEREZ')->assertSee('CARDIOLOGIA');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicos-referidos.guardar'), [
            'apellidos' => 'ríos', 'nombres' => 'marta', 'codeespecial' => '003', 'clinica' => 'Clínica Norte', 'ciudad' => 'Caracas', 'nota' => 'Buena letra',
        ])->assertSessionDoesntHaveErrors();
        $nuevo = DB::table('doctores')->where('reg_medico', $medico->reg_medico)->where('apellidos', 'RÍOS')->first();
        $this->assertSame(9, (int) $nuevo->cedula);
        $this->assertSame('MARTA', $nuevo->nombres);
        $this->assertSame('Buena letra', $nuevo->nota);
        $this->assertSame('created', $this->trazas('doctores', [$nuevo->id])->first()->operation);

        // Duplicado (mismo nombre y especialidad) y especialidad inválida.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicos-referidos.guardar'), ['apellidos' => 'perez', 'nombres' => 'luis', 'codeespecial' => '002'])->assertSessionHasErrors('apellidos');
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicos-referidos.guardar'), ['apellidos' => 'NUEVO', 'codeespecial' => '999'])->assertSessionHasErrors('codeespecial');
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicos-referidos.guardar'), ['codeespecial' => '002'])->assertSessionHasErrors();

        // Edición: la cédula que llegue no se usa y sólo se escribe lo que cambió.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicos-referidos.guardar'), [
            'id' => $existente, 'cedula' => 555, 'apellidos' => 'PEREZ', 'nombres' => 'LUIS', 'codeespecial' => '002', 'telefono' => '0414-1', 'nota' => '',
        ]);
        $fila = DB::table('doctores')->find($existente);
        $this->assertSame(7, (int) $fila->cedula);
        $this->assertSame('0414-1', $fila->telefono);
        $trazas = $this->trazas('doctores', [$existente]);
        $this->assertCount(1, $trazas);
        $this->assertSame('telefono', $trazas[0]->column_name);

        // Quitar: con referencias no; sin ellas sí.
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.medicos-referidos.quitar', $usado))->assertSessionHas('error');
        $this->assertStringContainsString('1 referencia', session('error'));
        $this->assertNotNull(DB::table('doctores')->find($usado));
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.medicos-referidos.quitar', $existente))->assertSessionHas('estado');
        $this->assertNull(DB::table('doctores')->find($existente));
        $this->assertSame('deleted', $this->trazas('doctores', [$existente])->last()->operation);
    }

    public function test_el_alta_rapida_del_colega_en_la_consulta_sigue_igual(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->especialidad($medico);

        [$doctor, $creado] = app(\App\Actions\Consulta\AgregarDoctorAlCatalogo::class)->ejecutar($medico->reg_medico, [$medico->reg_medico], 'Suárez', 'Pedro', '002', 'Clínica X');

        $this->assertTrue($creado);
        $this->assertSame('SUÁREZ', $doctor->apellidos);
        $this->assertNull($doctor->nota);
        $this->assertSame(1, (int) $doctor->cedula);
    }

    // ------------------------------------------------------------------------ Permisos

    public function test_la_secretaria_recibe_403_el_medico_ajeno_404_y_el_invitado_va_al_login(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $secretaria = User::create(['name' => 'Secretaria', 'email' => 'catclin-s-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $ajenos = [
            'diagnosticos' => $this->diagnostico($otro, '0001', 'AJENO'),
            'motivos-consulta' => $this->motivo($otro, '0001', 'AJENO'),
            'examenes' => $this->examen($otro, '001', 'AJENO'),
            'tipos-examen' => $this->tipo($otro, '001', 'AJENO'),
            'medicos-referidos' => $this->doctor($otro, 1, 'AJENO', 'X'),
        ];

        // El invitado primero: `actingAs` se queda en el test (trampa 47).
        foreach (array_keys($ajenos) as $slug) {
            $this->get(route('clinica.catalogos.' . $slug))->assertRedirect(route('login'));
        }

        foreach ($ajenos as $slug => $id) {
            $ruta = 'clinica.catalogos.' . $slug;

            $this->conContexto($secretaria, $medico, $gineco)->get(route($ruta))->assertForbidden();
            $this->conContexto($secretaria, $medico, $gineco)->post(route($ruta . '.guardar'), ['descripcion' => 'X'])->assertForbidden();
            $this->conContexto($secretaria, $medico, $gineco)->delete(route($ruta . '.quitar', $id))->assertForbidden();

            $this->conContexto($user, $medico, $gineco)->get(route($ruta, ['editar' => $id]))->assertNotFound();
            $this->conContexto($user, $medico, $gineco)->post(route($ruta . '.guardar'), ['id' => $id, 'descripcion' => 'X', 'examen' => 'X', 'apellidos' => 'X', 'codeespecial' => '002'])->assertNotFound();
            $this->conContexto($user, $medico, $gineco)->delete(route($ruta . '.quitar', $id))->assertNotFound();
        }
    }

    public function test_el_menu_lateral_ofrece_los_ocho_catalogos_como_submenus_y_los_errores_estan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.diagnosticos'))->assertOk()->getContent();
        $submenu = $this->submenuDeCatalogos($html);
        $this->assertSame(
            ['Medicamentos', 'Tratamientos', 'Diagnósticos', 'Motivos de consulta', 'Motivos de cita', 'Exámenes', 'Tipos de examen', 'Médicos referidos', 'Plantillas de texto'],
            $submenu['textos']
        );
        $this->assertSame(['Diagnósticos'], $submenu['activos']);
        $this->assertStringContainsString('<details class="nav-grupo" open>', $html);

        // Fuera de los catálogos el grupo está plegado y ninguno se marca.
        $fuera = $this->conContexto($user, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()->getContent();
        $this->assertStringContainsString('<details class="nav-grupo" >', $fuera);
        $this->assertSame([], $this->submenuDeCatalogos($fuera)['activos']);

        // Y el encabezado ya no trae el índice de pestañas ni formularios: el buscador va en su barra de herramientas.
        $this->assertStringNotContainsString('catalogos-indice', $html);
        $this->assertStringContainsString('barra-herramientas', $html);
        $this->assertSame(0, $this->formulariosEnElEncabezado($html));

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['id' => 'abc', 'descripcion' => str_repeat('A', 400), 'page' => 0])
            ->assertSessionHasErrors(['id', 'descripcion', 'page']);
        $errores = session('errors')->all();
        foreach ($errores as $mensaje) {
            $this->assertDoesNotMatchRegularExpression('/\b(must|field|The)\b/', $mensaje);
        }
    }

    /**
     * Los submenús de «Catálogos» del menú lateral: sus textos y cuáles están marcados como activos.
     *
     * @return array{textos:array<int,string>, activos:array<int,string>}
     */
    private function submenuDeCatalogos(string $html): array
    {
        preg_match('/<ul class="nav flex-column nav-submenu">(.*?)<\/ul>/s', $html, $bloque);
        preg_match_all('/<a class="nav-link (active)?" href="[^"]*"[^>]*>\s*<i[^>]*><\/i>\s*<span class="link-text">([^<]+)<\/span>/s', $bloque[1] ?? '', $enlaces, PREG_SET_ORDER);

        return [
            'textos' => array_map(fn ($e) => $e[2], $enlaces),
            'activos' => array_values(array_map(fn ($e) => $e[2], array_filter($enlaces, fn ($e) => $e[1] === 'active'))),
        ];
    }

    /** Cuántos `<form>` o `<select>` hay dentro del slot de acciones del `page-head` (no debería haber ninguno). */
    private function formulariosEnElEncabezado(string $html): int
    {
        preg_match('/<div class="page-head-acciones">(.*?)<\/div>\s*<\/div>/s', $html, $acciones);

        return preg_match_all('/<(form|select|input)/', $acciones[1] ?? '');
    }

    public function test_la_secretaria_no_ve_el_grupo_de_catalogos_en_el_menu(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = User::create(['name' => 'Secretaria', 'email' => 'catclin-m-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $html = $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()->getContent();
        $this->assertStringNotContainsString('nav-grupo', $html);
        $this->assertStringNotContainsString('/catalogos/', $html);
    }

    public function test_el_movil_baja_lo_editado_por_delta_y_se_entera_de_las_bajas_con_el_nombre_de_su_tabla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->especialidad($medico);
        $diag = $this->diagnostico($medico, '0001', 'MIOMA');
        $sinMotivo = $this->motivo($medico, '0001', 'CONTROL');
        $doctor = $this->doctor($medico, 3, 'PEREZ', 'LUIS');
        $sinDoctor = $this->doctor($medico, 4, 'RUIZ', 'ANA');
        $desde = Carbon::now()->addMinutes(5)->toIso8601String();

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.diagnosticos.guardar'), ['id' => $diag, 'descripcion' => 'MIOMA UTERINO']);
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicos-referidos.guardar'), ['id' => $doctor, 'apellidos' => 'PEREZ', 'nombres' => 'LUIS', 'codeespecial' => '002', 'ciudad' => 'Maracay']);
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.motivos-consulta.quitar', $sinMotivo));
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.medicos-referidos.quitar', $sinDoctor));

        Sanctum::actingAs($user, ['*'], 'api');
        $pull = $this->postJson('/api/app/sync-app-data', ['changes' => [], 'since' => $desde])->assertOk();

        $this->assertSame('MIOMA UTERINO', collect($pull->json('diagnosticos'))->firstWhere('id', $diag)['descripcion']);
        $this->assertSame('Maracay', collect($pull->json('doctores'))->firstWhere('id', $doctor)['ciudad']);

        // Las bajas viajan con el nombre de la tabla local del teléfono, que se llama igual que la del servidor.
        $eliminados = collect($pull->json('eliminados'))->map(fn ($e) => $e['table_name'] . ':' . $e['record_id'])->all();
        $this->assertContains('motivos_consulta:' . $sinMotivo, $eliminados);
        $this->assertContains('doctores:' . $sinDoctor, $eliminados);
    }
}
