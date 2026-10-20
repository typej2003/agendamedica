<?php

namespace Tests\Feature;

use App\Actions\Agenda\CobrarCita;
use App\Actions\Agenda\ConfirmarCita;
use App\Actions\Agenda\CrearCita;
use App\Actions\Consulta\GuardarEnfermedadActual;
use App\Models\Cola;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Bitácora** (WEB-8.5): `/clinica/bitacora`, qué cambió en el consultorio y quién lo hizo.
 *
 * Lo que estos casos fijan:
 *
 *  - **Quién entra**: sólo el médico del contexto (la secretaría recibe 403 y no ve la entrada del menú; el invitado va al login).
 *  - **Qué muestra**: una línea por acción (usuario + tabla + registro + segundo), la más reciente primero, con etiquetas legibles y
 *    paginada de a 50; lo que no está en el diccionario sale con un nombre genérico, nunca con la tabla cruda.
 *  - **Los filtros**: rango de fechas (en la hora del consultorio), usuario, tipo de dato y paciente (de mejor esfuerzo).
 *  - **Aislamiento y privacidad**: nunca se ve un cambio de otro médico y nunca el contenido clínico (sólo el monto y la hora de una cita).
 */
class BitacoraWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fijarHoy();   // 2026-10-07 09:00 UTC = 05:00 en Caracas
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();

        parent::tearDown();
    }

    private function como(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user);
    }

    private function rastro(Medico $medico, string $tabla, int $id, array $extra = []): SyncChange
    {
        return SyncChange::create($extra + [
            'reg_medico' => $medico->reg_medico, 'table_name' => $tabla, 'record_id' => $id, 'operation' => 'updated',
            'column_name' => 'estado', 'value' => '1', 'occurred_at' => Carbon::now(), 'source' => 'web',
        ]);
    }

    private function cita(Medico $medico, int $historia, string $fecha = '2026-10-07', string $hora = '09:00:00'): Cola
    {
        return Cola::create([
            'reg_medico' => $medico->reg_medico, 'fecha' => $fecha, 'hora_ini' => $hora, 'numhistoria' => $historia, 'atendido' => 0, 'estado' => 0,
        ]);
    }

    /** @return array<int,string> los textos de las filas de la tabla, en el orden en que salen */
    private function filas($respuesta): array
    {
        preg_match_all('#<tr>\s*<td class="celda-principal" data-etiqueta="Cuándo">(.*?)</tr>#s', $respuesta->getContent(), $m);

        return array_map(fn ($html) => trim(preg_replace('/\s+/u', ' ', strip_tags($html))), $m[0]);
    }

    // -------------------------------------------------------------------------------------- Quién entra

    public function test_el_medico_ve_la_bitacora_vacia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora'));

        $respuesta->assertOk();
        $respuesta->assertSee('Bitácora');
        $respuesta->assertSee('Todavía no hay cambios registrados');
        $respuesta->assertSee('no muestra el contenido de las consultas');
        $respuesta->assertSee('href="' . route('clinica.bitacora') . '"', false);
    }

    public function test_la_secretaria_recibe_403_y_el_invitado_va_al_login(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);

        $this->como($secretaria, $medico, $gineco)->get(route('clinica.bitacora'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get(route('clinica.bitacora'))->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------------------------- Qué muestra

    public function test_muestra_quien_hizo_cada_cosa_en_lenguaje_claro_y_agrupa_una_accion_en_una_linea(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $secretaria->forceFill(['name' => 'María Gómez'])->save();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        // La secretaria agenda (10:00), confirma y cobra en el mismo segundo (una acción del mostrador)...
        $this->actingAs($secretaria);
        $cita = app(CrearCita::class)->ejecutar($medico->reg_medico, [
            'numhistoria' => 1001, 'fecha' => '2026-10-07', 'hora_ini' => '10:00:00', 'atendido' => 0, 'estado' => 0,
        ], Carbon::now());
        Carbon::setTestNow(Carbon::now()->addMinutes(3));
        app(ConfirmarCita::class)->ejecutar($cita, Cola::ESTADOS[0], Carbon::now());
        app(CobrarCita::class)->fijarMonto($cita->fresh(), 40.0, Carbon::now());

        // ... y el médico, más tarde, guarda la enfermedad actual.
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->actingAs($user);
        app(GuardarEnfermedadActual::class)->ejecutar($consulta, 'Dolor abdominal severo de tres dias', Carbon::now(), 'web');

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora'));
        $respuesta->assertOk();
        $filas = $this->filas($respuesta);

        $this->assertCount(3, $filas, 'La creación, el mostrador (estado y monto en el mismo segundo) y la consulta: una línea por acción.');
        $this->assertStringContainsString('Hoy 5:13 AM', $filas[0]);
        $this->assertStringContainsString($user->name, $filas[0]);
        $this->assertStringContainsString('médico', $filas[0]);
        $this->assertStringContainsString('Editó una consulta', $filas[0]);
        $this->assertStringContainsString('Cambió: Enfermedad actual', $filas[0]);
        $this->assertStringContainsString('Hoy 5:03 AM', $filas[1]);
        $this->assertStringContainsString('María Gómez', $filas[1]);
        $this->assertStringContainsString('secretaría', $filas[1]);
        $this->assertStringContainsString('Editó una cita', $filas[1]);
        $this->assertStringContainsString('Estado', $filas[1]);
        $this->assertStringContainsString('Monto: 40,00', $filas[1]);
        $this->assertStringContainsString('Hoy 5:00 AM', $filas[2]);
        $this->assertStringContainsString('Creó una cita', $filas[2]);
        $this->assertStringContainsString('Alvarez, Ana', $filas[2], 'La línea dice de qué paciente es.');
    }

    public function test_no_muestra_el_contenido_clinico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->actingAs($user);
        app(GuardarEnfermedadActual::class)->ejecutar($consulta, 'Dolor abdominal severo de tres dias', Carbon::now(), 'web');
        $this->rastro($medico, 'consultas', $consulta->id, ['column_name' => 'evolucion', 'value' => 'Secreto clinico de la evolucion', 'user_id' => $user->id]);
        $this->rastro($medico, 'diagnostico_paciente', 5, ['operation' => 'created', 'column_name' => null, 'value' => null, 'user_id' => $user->id]);
        $this->rastro($medico, 'cola', 9, ['column_name' => 'monto', 'value' => '55', 'user_id' => $user->id]);
        $this->rastro($medico, 'cola', 9, ['column_name' => 'sms_text', 'value' => 'Mensaje privado al paciente', 'user_id' => $user->id]);

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora'));

        $respuesta->assertOk();
        $respuesta->assertDontSee('Dolor abdominal');
        $respuesta->assertDontSee('Secreto clinico');
        $respuesta->assertDontSee('Mensaje privado');
        $respuesta->assertSee('Enfermedad actual');
        $respuesta->assertSee('Evolución');
        $respuesta->assertSee('Monto: 55,00', false);
        $respuesta->assertSee('Creó un diagnóstico');
    }

    public function test_lo_que_el_diccionario_no_conoce_sale_con_un_nombre_generico_no_con_la_tabla_cruda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->rastro($medico, 'tabla_inventada_del_legado', 3, ['column_name' => 'fe_vence', 'user_id' => $user->id]);

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora'));

        $respuesta->assertSee('Editó un otro dato del consultorio');
        $respuesta->assertSee('Cambió: Vigencia');
        $respuesta->assertDontSee('tabla_inventada_del_legado');
    }

    public function test_cada_origen_tiene_su_nombre_y_lo_que_no_es_un_cambio_no_se_lista(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->rastro($medico, 'cola', 1, ['source' => 'mobile']);
        $this->rastro($medico, 'cola', 2, ['source' => 'escritorio']);
        $this->rastro($medico, 'cola', 3, ['source' => 'web']);                                  // anterior a la bitácora
        $this->rastro($medico, 'imagen_consulta', 4, ['source' => 'migracion']);                // la migración de archivos de la PC
        $this->rastro($medico, 'tratamientos', 5, ['operation' => 'deleted', 'column_name' => null, 'source' => 'web']);   // el alias del teléfono

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora'));
        $filas = implode("\n", $this->filas($respuesta));

        $this->assertStringContainsString('App del móvil', $filas);
        $this->assertStringContainsString('Sistema anterior', $filas);
        $this->assertStringContainsString('Sin usuario', $filas);
        $this->assertStringContainsString('anterior a la bitácora', $filas);
        $this->assertCount(3, $this->filas($respuesta), 'Ni la migración de archivos ni el alias del teléfono aparecen.');
    }

    public function test_la_mas_reciente_va_primero_y_la_hora_es_la_del_consultorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->rastro($medico, 'cola', 1, ['occurred_at' => '2026-10-07 01:30:00', 'user_id' => $user->id]);   // 21:30 del día anterior en Caracas
        $this->rastro($medico, 'cola', 2, ['occurred_at' => '2026-10-07 08:30:00', 'user_id' => $user->id]);   // 04:30 de hoy
        $this->rastro($medico, 'cola', 3, ['occurred_at' => '2026-10-05 15:00:00', 'user_id' => $user->id]);   // 05/10 11:00

        $filas = $this->filas($this->como($user, $medico, $gineco)->get(route('clinica.bitacora')));

        $this->assertStringContainsString('Hoy 4:30 AM', $filas[0]);
        $this->assertStringContainsString('Ayer 9:30 PM', $filas[1]);
        $this->assertStringContainsString('05/10/2026 11:00 AM', $filas[2]);
    }

    public function test_se_pagina_de_a_cincuenta_en_el_servidor(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        for ($i = 1; $i <= 60; $i++) {
            $this->rastro($medico, 'cola', $i, ['occurred_at' => Carbon::now()->subMinutes($i)->format('Y-m-d H:i:s'), 'user_id' => $user->id]);
        }

        $primera = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora'));
        $this->assertCount(50, $this->filas($primera));
        $primera->assertSee('Mostrando 1–50 (hay más)');
        $primera->assertSee('Más antiguas');
        $primera->assertDontSee('Más recientes');

        $segunda = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora', ['page' => 2]));
        $this->assertCount(10, $this->filas($segunda));
        $segunda->assertSee('Mostrando 51–60');
        $segunda->assertSee('Más recientes');
        $segunda->assertDontSee('Más antiguas');
    }

    public function test_pocas_consultas_por_pagina_aunque_haya_muchas_filas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->pacienteYConsulta($medico, 1001, 1);
        foreach (['cola', 'consultas', 'recipes', 'diagnostico_paciente', 'antece_paciente', 'informe'] as $tabla) {
            for ($i = 1; $i <= 12; $i++) {
                $this->rastro($medico, $tabla, $i, ['occurred_at' => Carbon::now()->subMinutes($i)->format('Y-m-d H:i:s'), 'user_id' => $user->id]);
            }
        }

        $como = $this->como($user, $medico, $gineco);
        DB::enableQueryLog();
        $respuesta = $como->get(route('clinica.bitacora'));
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $respuesta->assertOk();
        $this->assertCount(50, $this->filas($respuesta));
        $this->assertLessThan(40, $consultas, "La página hizo {$consultas} consultas: tiene que ser un número acotado, no uno por línea.");
    }

    // --------------------------------------------------------------------------------------- Filtros

    public function test_filtra_por_usuario_y_por_sin_usuario(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->rastro($medico, 'cola', 1, ['user_id' => $secretaria->id]);
        $this->rastro($medico, 'consultas', 2, ['user_id' => $user->id]);
        $this->rastro($medico, 'cola', 3, ['source' => 'mobile']);

        $como = $this->como($user, $medico, $gineco);

        $deLaSecretaria = $this->filas($como->get(route('clinica.bitacora', ['usuario' => $secretaria->id])));
        $this->assertCount(1, $deLaSecretaria);
        $this->assertStringContainsString('Editó una cita', $deLaSecretaria[0]);

        $sinUsuario = $this->filas($como->get(route('clinica.bitacora', ['usuario' => 'sin'])));
        $this->assertCount(1, $sinUsuario);
        $this->assertStringContainsString('App del móvil', $sinUsuario[0]);

        // Las opciones del filtro: todos, sin usuario, el médico y la secretaria (aunque no hubiera hecho nada).
        $opciones = $como->get(route('clinica.bitacora'));
        $opciones->assertSee('>Sin usuario (móvil, sistema anterior o previo a la bitácora)<', false);
        $opciones->assertSee('(secretaría)');
        $opciones->assertSee('(médico)');
    }

    public function test_filtra_por_rango_de_fechas_en_la_hora_del_consultorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->rastro($medico, 'cola', 1, ['occurred_at' => '2026-10-05 03:00:00', 'user_id' => $user->id]);   // 04/10 23:00 en Caracas
        $this->rastro($medico, 'cola', 2, ['occurred_at' => '2026-10-05 05:00:00', 'user_id' => $user->id]);   // 05/10 01:00
        $this->rastro($medico, 'cola', 3, ['occurred_at' => '2026-10-06 03:59:00', 'user_id' => $user->id]);   // 05/10 23:59
        $this->rastro($medico, 'cola', 4, ['occurred_at' => '2026-10-06 04:00:00', 'user_id' => $user->id]);   // 06/10 00:00

        $como = $this->como($user, $medico, $gineco);

        $dia5 = $this->filas($como->get(route('clinica.bitacora', ['desde' => '2026-10-05', 'hasta' => '2026-10-05'])));
        $this->assertCount(2, $dia5, 'El día 5 del consultorio, de 00:00 a 23:59, no el día 5 en UTC.');
        $this->assertStringContainsString('05/10/2026 11:59 PM', $dia5[0]);
        $this->assertStringContainsString('05/10/2026 1:00 AM', $dia5[1]);

        $this->assertCount(3, $this->filas($como->get(route('clinica.bitacora', ['desde' => '2026-10-05']))));
        $this->assertCount(3, $this->filas($como->get(route('clinica.bitacora', ['hasta' => '2026-10-05']))), 'Hasta el día 5 incluido.');
        $this->assertCount(4, $this->filas($como->get(route('clinica.bitacora', ['desde' => 'ayer', 'hasta' => '2026-02-31']))), 'Una fecha mal escrita no filtra.');
    }

    public function test_filtra_por_tipo_de_dato(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->rastro($medico, 'cola', 1, ['user_id' => $user->id]);
        $this->rastro($medico, 'recipes', 2, ['user_id' => $user->id]);
        $this->rastro($medico, 'recipe_detalle', 3, ['user_id' => $user->id]);
        $this->rastro($medico, 'vademecum', 4, ['user_id' => $user->id]);

        $como = $this->como($user, $medico, $gineco);

        $this->assertCount(1, $this->filas($como->get(route('clinica.bitacora', ['tipo' => 'cita']))));
        $this->assertCount(2, $this->filas($como->get(route('clinica.bitacora', ['tipo' => 'recipe']))), 'La cabecera y los medicamentos de un récipe son el mismo tipo.');
        $this->assertCount(1, $this->filas($como->get(route('clinica.bitacora', ['tipo' => 'medicamento']))));
        $this->assertCount(4, $this->filas($como->get(route('clinica.bitacora', ['tipo' => 'no-existe']))), 'Un tipo desconocido no filtra.');

        $como->get(route('clinica.bitacora'))->assertSee('<optgroup label="Récipes">', false);
    }

    public function test_filtra_por_paciente_solo_con_lo_que_se_puede_relacionar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consultaAna] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->pacienteYConsulta($medico, 1002, 1, ['cedula' => '20111222', 'nombres' => 'Beatriz', 'apellidos' => 'Zambrano']);
        $citaAna = $this->cita($medico, 1001);
        $citaBea = $this->cita($medico, 1002, '2026-10-07', '10:00:00');
        $this->rastro($medico, 'cola', $citaAna->id, ['user_id' => $user->id]);
        $this->rastro($medico, 'cola', $citaBea->id, ['user_id' => $user->id]);
        $this->rastro($medico, 'consultas', $consultaAna->id, ['user_id' => $user->id, 'column_name' => 'evolucion']);
        $this->rastro($medico, 'vademecum', 77, ['user_id' => $user->id]);      // un catálogo no es de ningún paciente
        $this->rastro($medico, 'cola', 987654, ['user_id' => $user->id, 'operation' => 'deleted', 'column_name' => null]);   // una baja: la fila ya no existe

        $como = $this->como($user, $medico, $gineco);
        $this->assertCount(5, $this->filas($como->get(route('clinica.bitacora'))));

        $ana = $como->get(route('clinica.bitacora', ['paciente' => 'alvarez']));
        $this->assertCount(2, $this->filas($ana), 'La cita y la consulta de Ana; ni Beatriz, ni el catálogo, ni la baja.');
        $ana->assertSee('Alvarez, Ana');
        $ana->assertDontSee('Zambrano');
        $ana->assertSee('Por nombre, cédula o historia');

        $this->assertCount(1, $this->filas($como->get(route('clinica.bitacora', ['paciente' => 'beatriz']))));
        $this->assertCount(1, $this->filas($como->get(route('clinica.bitacora', ['paciente' => '20111222']))), 'Por cédula.');
        $this->assertCount(2, $this->filas($como->get(route('clinica.bitacora', ['paciente' => '1001']))), 'Por número de historia.');

        $ninguno = $como->get(route('clinica.bitacora', ['paciente' => 'inexistente']));
        $ninguno->assertSee('No encontramos pacientes con «inexistente»');
        $this->assertCount(0, $this->filas($ninguno));
    }

    public function test_el_diccionario_apunta_a_tablas_y_columnas_que_existen(): void
    {
        foreach (\App\Clinica\Bitacora\DiccionarioDeLaBitacora::tablasConPaciente() as $tabla) {
            [$como, $columna] = \App\Clinica\Bitacora\DiccionarioDeLaBitacora::pacientePor($tabla);

            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($tabla, $columna), "{$tabla}.{$columna} no existe.");
            if ($como !== 'paciente') {
                $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($tabla, 'reg_medico'), "{$tabla} no tiene reg_medico.");
            }
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('cola', 'paciente_sinhistoria_id'));
    }

    public function test_el_filtro_por_paciente_corre_sobre_todas_las_tablas_sin_romperse(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->pacienteYConsulta($medico, 1001, 1);

        // Una fila de rastro por cada tabla relacionable: el SQL del filtro tiene que valer para todas (columnas, reg_medico).
        foreach (\App\Clinica\Bitacora\DiccionarioDeLaBitacora::tablasConPaciente() as $i => $tabla) {
            $this->rastro($medico, $tabla, 900000 + $i, ['user_id' => $user->id]);
        }

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora', ['paciente' => 'alvarez']));

        $respuesta->assertOk();
        $this->assertCount(0, $this->filas($respuesta), 'Filas de rastro sin registro real no pertenecen a ningún paciente.');
    }

    public function test_los_filtros_se_combinan_y_la_paginacion_los_conserva(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        for ($i = 1; $i <= 60; $i++) {
            $this->rastro($medico, 'cola', $i, ['occurred_at' => Carbon::now()->subMinutes($i)->format('Y-m-d H:i:s'), 'user_id' => $user->id]);
        }
        $this->rastro($medico, 'consultas', 1, ['user_id' => $user->id]);

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora', ['tipo' => 'cita', 'usuario' => $user->id]));

        $this->assertCount(50, $this->filas($respuesta));
        $respuesta->assertSee('usuario=' . $user->id . '&amp;tipo=cita&amp;page=2', false);
        $respuesta->assertSee('Limpiar');
    }

    // ----------------------------------------------------------------------------- Aislamiento y enlaces

    public function test_nunca_ve_un_cambio_de_otro_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$otroUser, $otro] = $this->medicoConAcceso();
        $this->rastro($medico, 'cola', 1, ['user_id' => $user->id]);
        $this->rastro($otro, 'cola', 2, ['user_id' => $otroUser->id]);
        $this->rastro($otro, 'consultas', 3, ['user_id' => $otroUser->id]);

        $como = $this->como($user, $medico, $gineco);

        $this->assertCount(1, $this->filas($como->get(route('clinica.bitacora'))));
        $this->assertCount(0, $this->filas($como->get(route('clinica.bitacora', ['usuario' => $otroUser->id]))), 'Ni siquiera filtrando por el usuario del otro médico.');
        $this->assertCount(0, $this->filas($como->get(route('clinica.bitacora', ['tipo' => 'consulta']))));

        $opciones = $como->get(route('clinica.bitacora'));
        $opciones->assertDontSee('<option value="' . $otroUser->id . '"', false);
    }

    public function test_enlaza_al_paciente_y_a_la_consulta_cuando_existen(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $this->rastro($medico, 'consultas', $consulta->id, ['user_id' => $user->id, 'column_name' => 'evolucion']);

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.bitacora'));

        $respuesta->assertSee('href="' . route('clinica.pacientes.ver', $pacienteId) . '"', false);
        $respuesta->assertSee('href="' . route('clinica.consulta', $consulta->id) . '"', false);
        $respuesta->assertSee('Abrir la consulta');
    }

    public function test_una_secretaria_con_acceso_a_otro_medico_no_mezcla_las_bitacoras(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->rastro($otro, 'cola', 5, ['user_id' => $secretaria->id]);

        $this->assertCount(0, $this->filas($this->como($user, $medico, $gineco)->get(route('clinica.bitacora', ['usuario' => $secretaria->id]))));
    }
}
