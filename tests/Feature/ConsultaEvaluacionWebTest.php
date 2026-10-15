<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\ExamenFisico;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La evaluación física de la consulta** (WEB-4.3, ítem 4 del panel del legado): el examen general
 * —antropometría, signos y examen por aparatos— y el ginecológico, dentro del shell de la consulta.
 *
 * Lo que estos casos fijan:
 *
 *  - **Dónde vive cada campo**: la antropometría en `consultas` (`peso`, `talla`, `fc`, `pp` y las tres
 *    circunferencias) y el examen por aparatos, el ginecológico y la tensión arterial en
 *    `examen_fisico_nuevo`, que es la tabla del escritorio (el mapa, con su fuente, está en
 *    `App\Clinica\Consulta\ExamenFisicoDeLaConsulta`).
 *  - El **rastro por columna en `sync_changes`** (`source = web`) de las dos tablas y el guard
 *    last-write-wins del sync.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *  - El IMC **derivado** (no se guarda) y los **resúmenes del escritorio**, que se muestran y no se
 *    regeneran.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaEvaluacionWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-06 09:00:00');
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
        $regMedico = 'evaluacion-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'evaluacion-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Evaluación',
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
            'email'                => 'evaluacion-secre-' . uniqid() . '@example.com',
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
     * Un paciente con historia y su consulta.
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
        $pacienteId = DB::table('pacientes')->insertGetId([
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

    /**
     * La fila del examen como la escribe el escritorio.
     *
     * ⚠️ `examen_fisico_nuevo.consulta` es el **número** de la consulta (`nroconsulta`), no su id: la clave
     * del escritorio es `(historia, consulta)` con el número de historia y el de la consulta.
     */
    private function examen(Medico $medico, Consulta $consulta, array $extra = []): ExamenFisico
    {
        return ExamenFisico::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia'   => (int) $consulta->numhistoria,
            'consulta'   => (int) $consulta->nroconsulta,
        ], $extra));
    }

    private function urlSeccion(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION]);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.evaluacion.guardar', $consulta->id);
    }

    // ------------------------------------------------------------------------------------- La lectura

    public function test_la_seccion_muestra_lo_que_el_escritorio_dejo_en_las_dos_tablas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La antropometría vive en `consultas`…
        $consulta->forceFill([
            'peso' => 70.5, 'talla' => 1.62, 'fc' => 78, 'pp' => 18,
            'circabdominal' => 88, 'circtoraxica' => 95, 'circcefalica' => 55,
        ])->save();

        // …y el examen, en `examen_fisico_nuevo`, que es la tabla del escritorio.
        $this->examen($medico, $consulta, [
            'tension' => '120/80',
            'general' => 'Paciente en buen estado general.',
            'abdomen' => 'Blando, depresivo, indoloro.',
            'genitales_externos' => 'Normales.',
            'total_f' => 'PESO: 70.5 kg.  TALLA: 1,62 m.',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Evaluación física', false);
        $respuesta->assertSee('Guardar la evaluación física', false);

        // La antropometría y la tensión, tal cual están guardadas.
        $respuesta->assertSee('value="70.5"', false);
        $respuesta->assertSee('value="1.62"', false);
        $respuesta->assertSee('value="120/80"', false);

        // El examen por aparatos y el ginecológico, en sus áreas de texto.
        $respuesta->assertSee('Paciente en buen estado general.', false);
        $respuesta->assertSee('Blando, depresivo, indoloro.', false);
        $respuesta->assertSee('Normales.', false);

        // El IMC derivado (70,5 / 1,62² = 26,86) y el recuento de campos con dato: las siete columnas de
        // `consultas`, la tensión y los tres hallazgos del examen.
        $respuesta->assertSee('26,86', false);
        $respuesta->assertSee('11 de 20 campos con dato', false);

        // El resumen del escritorio se muestra tal cual (no se regenera).
        $respuesta->assertSee('PESO: 70.5 kg.  TALLA: 1,62 m.', false);
        $respuesta->assertSee('Resúmenes del escritorio', false);
    }

    public function test_el_examen_se_lee_por_el_numero_de_la_consulta_y_no_por_su_id(): void
    {
        // Trampa del esquema real: en `examen_fisico_nuevo`, `consulta` guarda el **número** de la consulta
        // (`nroconsulta`), no el id de la fila de `consultas`. Se verifica con dos consultas de la misma
        // historia donde el número **no** coincide con el id: la primera (nroconsulta 5) tiene su examen y
        // la segunda (nroconsulta 1) es la que se abre. Con la clave equivocada, la pantalla mostraría el
        // examen de la otra consulta (o crearía una fila nueva y dejaría huérfana la del escritorio).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$pacienteId] = $this->pacienteYConsulta($medico, 1001, 5);
        $consulta = Consulta::where('reg_medico', $medico->reg_medico)->where('nroconsulta', 5)->firstOrFail();

        $otra = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $this->examen($medico, $consulta, ['general' => 'El examen de la consulta 5.']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($otra));

        $respuesta->assertOk();
        $respuesta->assertDontSee('El examen de la consulta 5.', false);
        $respuesta->assertSee('0 de 20 campos con dato', false);

        // Y al guardar, la fila que se escribe es la de **esta** consulta, no la de la otra.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($otra), ['general' => 'El examen de la consulta 1.']);

        // El examen de la primera consulta no se tocó…
        $this->assertSame(
            'El examen de la consulta 5.',
            ExamenFisico::where('consulta', $consulta->nroconsulta)->firstOrFail()->general,
        );
        // …y el de esta consulta sí, cada uno en su fila.
        $this->assertSame('El examen de la consulta 1.', ExamenFisico::where('consulta', $otra->nroconsulta)->firstOrFail()->general);
        $this->assertSame(2, ExamenFisico::where('reg_medico', $medico->reg_medico)->count());
        $this->assertNotNull(\App\Models\Paciente::find($pacienteId));
    }

    public function test_una_consulta_sin_examen_muestra_los_campos_vacios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        // El estado de la sección: vacía.
        $respuesta->assertSee('Sin cargar', false);
        $respuesta->assertSee('0 de 20 campos con dato', false);
        // El IMC sin peso ni talla: una raya, no un cero.
        $respuesta->assertSee('data-evaluacion-imc', false);
        $respuesta->assertSee('—', false);
        // Y no hay tarjeta de resúmenes.
        $respuesta->assertDontSee('Resúmenes del escritorio', false);
    }

    public function test_el_literal_none_del_escritorio_no_se_muestra_como_hallazgo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // `total_general` quedó con el marcador `none` del DataWindow en 23.971 filas de la base real.
        $this->examen($medico, $consulta, ['general' => 'none', 'total_general' => 'none']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertDontSee('>none<', false);
        $respuesta->assertSee('0 de 20 campos con dato', false);
    }

    // ------------------------------------------------------------------------------------- El guardado

    public function test_guardar_escribe_las_dos_tablas_y_deja_el_rastro_por_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            // Antropometría (`consultas`).
            'peso' => '68.4',
            'talla' => '1.6',
            'tension' => '110/70',
            'fc' => '76',
            'pp' => '16',
            'circabdominal' => '86',
            'circtoraxica' => '94',
            'circcefalica' => '55',
            // Examen por aparatos y ginecológico (`examen_fisico_nuevo`).
            'general' => 'Buen estado general.',
            'cardiopulmonar' => 'Ruidos cardiacos rítmicos.',
            'tiroides' => 'Sin nódulos.',
            'mamas' => 'Sin lesiones.',
            'mama_derecha' => 'Sin lesiones.',
            'abdomen' => 'Blando, indoloro.',
            'extremidades' => 'Sin edemas.',
            'neurologico' => 'Consciente y orientada.',
            'genitales_externos' => 'Normales.',
            'especulos' => 'Cérvix de aspecto sano.',
            'tacto_vaginal' => 'Útero en anteversoflexión.',
            'tacto_rectal' => 'Sin hallazgos.',
        ]);

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'Evaluación física'));

        // La antropometría quedó en `consultas`, como número y no como texto.
        $consulta->refresh();
        $this->assertSame(68.4, $consulta->peso);
        $this->assertSame(1.6, $consulta->talla);
        $this->assertSame(76.0, $consulta->fc);
        $this->assertSame(16.0, $consulta->pp);
        $this->assertSame(86.0, $consulta->circabdominal);
        $this->assertSame(94.0, $consulta->circtoraxica);
        $this->assertSame(55.0, $consulta->circcefalica);

        // Y el examen en `examen_fisico_nuevo`, en la fila que crea la web porque el escritorio no la tenía.
        // La clave real de la tabla es `historia` (el número de historia) + `consulta` (el de la consulta).
        $fila = ExamenFisico::where('reg_medico', $medico->reg_medico)
            ->where('historia', 1001)
            ->where('consulta', $consulta->nroconsulta)
            ->firstOrFail();
        $this->assertSame('110/70', $fila->tension);
        $this->assertSame('Buen estado general.', $fila->general);
        $this->assertSame('Sin nódulos.', $fila->tiroides);
        $this->assertSame('Sin lesiones.', $fila->mamas);
        $this->assertSame('Sin lesiones.', $fila->mama_derecha);
        $this->assertSame('Cérvix de aspecto sano.', $fila->especulos);
        $this->assertSame('Útero en anteversoflexión.', $fila->tacto_vaginal);
        $this->assertSame('Sin hallazgos.', $fila->tacto_rectal);

        // El rastro: una fila por columna escrita, en la tabla que corresponde y con `source = web`.
        foreach (['peso', 'talla', 'fc', 'pp', 'circabdominal', 'circtoraxica', 'circcefalica'] as $columna) {
            $this->assertDatabaseHas('sync_changes', [
                'table_name'  => 'consultas',
                'record_id'   => $consulta->id,
                'operation'   => 'updated',
                'column_name' => $columna,
                'source'      => 'web',
            ]);
        }

        foreach (['tension', 'general', 'cardiopulmonar', 'tiroides', 'mamas', 'mama_derecha', 'abdomen',
            'extremidades', 'neurologico', 'genitales_externos', 'especulos', 'tacto_vaginal',
            'tacto_rectal'] as $columna) {
            $this->assertDatabaseHas('sync_changes', [
                'table_name'  => 'examen_fisico_nuevo',
                'record_id'   => $fila->id,
                'operation'   => 'updated',
                'column_name' => $columna,
                'source'      => 'web',
            ]);
        }

        // Y la colposcopia —que es de F6— no se tocó.
        $this->assertNull($fila->colposcopia);
        $this->assertSame(0, SyncChange::where('table_name', 'examen_fisico_nuevo')->where('column_name', 'colposcopia')->count());
    }

    public function test_guardar_dos_veces_sin_cambios_no_deja_rastro_de_mas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $contexto->post($this->urlGuardar($consulta), ['peso' => '70', 'abdomen' => 'Blando']);
        $segundo = $contexto->post($this->urlGuardar($consulta), ['peso' => '70', 'abdomen' => 'Blando']);

        // Nada cambió: se dice, y no se escriben filas nuevas en el rastro.
        $segundo->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'no tenía cambios'));
        $this->assertSame(1, SyncChange::where('table_name', 'consultas')->where('column_name', 'peso')->count());
        $this->assertSame(1, SyncChange::where('table_name', 'examen_fisico_nuevo')->where('column_name', 'abdomen')->count());
    }

    public function test_guardar_con_los_campos_vacios_los_deja_vacios_y_tambien_deja_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->forceFill(['peso' => 70.0, 'fc' => 80.0])->save();
        $this->examen($medico, $consulta, ['abdomen' => 'Algo.']);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'peso' => '',
            'fc' => '80',
            'abdomen' => '',
        ]);

        $this->assertNull($consulta->fresh()->peso);
        $this->assertSame(80.0, $consulta->fresh()->fc);

        $this->assertDatabaseHas('sync_changes', [
            'table_name'  => 'consultas',
            'record_id'   => $consulta->id,
            'column_name' => 'peso',
            'source'      => 'web',
        ]);
        // El abdomen se vació en la fila del examen de **esta** consulta (y sigue habiendo una sola).
        $fila = ExamenFisico::where('reg_medico', $medico->reg_medico)
            ->where('historia', 1001)
            ->where('consulta', $consulta->nroconsulta)
            ->firstOrFail();
        $this->assertNull($fila->abdomen);
        $this->assertSame(1, ExamenFisico::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_los_resumenes_del_escritorio_no_se_pisan_al_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->examen($medico, $consulta, [
            'total_f' => 'PESO: 70 kg.  TALLA: 1,62 m.',
            'total_g' => 'GENITALES EXTERNOS: normales.  COLPOSCOPÍA: sin lesiones.',
            'total_general' => 'none',
            'colposcopia' => 'Zona de transformación visible.',
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'peso' => '71',
            'genitales_externos' => 'Normales.',
        ]);

        // Los resúmenes y la colposcopia son del escritorio (y de F6): la web no los toca.
        $fila = ExamenFisico::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame('PESO: 70 kg.  TALLA: 1,62 m.', $fila->total_f);
        $this->assertStringContainsString('COLPOSCOPÍA', $fila->total_g);
        $this->assertSame('none', $fila->total_general);
        $this->assertSame('Zona de transformación visible.', $fila->colposcopia);

        // Y no quedó rastro de esas columnas por la web.
        foreach (['total_f', 'total_g', 'total_general', 'colposcopia'] as $columna) {
            $this->assertSame(0, SyncChange::where('table_name', 'examen_fisico_nuevo')
                ->where('column_name', $columna)->count(), $columna);
        }
    }

    public function test_una_edicion_mas_nueva_del_sync_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->forceFill(['peso' => 70.0])->save();

        // Otro equipo (el escritorio o el teléfono) editó `peso` un minuto después: gana esa.
        SyncChange::create([
            'reg_medico' => $medico->reg_medico,
            'table_name' => 'consultas',
            'record_id'  => $consulta->id,
            'operation'  => 'updated',
            'column_name' => 'peso',
            'value'      => '70',
            'occurred_at' => $this->hoy->copy()->addMinute(),
            'source'     => 'mobile',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'peso' => '72',
        ]);

        // No se pisó y la pantalla lo dice, en vez de mentir con un "Guardado".
        $respuesta->assertSessionHas('error');
        $this->assertSame(70.0, $consulta->fresh()->peso);
    }

    public function test_una_columna_con_edicion_mas_nueva_no_bloquea_a_las_demas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->forceFill(['peso' => 70.0])->save();

        SyncChange::create([
            'reg_medico' => $medico->reg_medico,
            'table_name' => 'consultas',
            'record_id'  => $consulta->id,
            'operation'  => 'updated',
            'column_name' => 'peso',
            'value'      => '70',
            'occurred_at' => $this->hoy->copy()->addMinute(),
            'source'     => 'mobile',
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'peso' => '72',
            'talla' => '1.65',
        ]);

        // El peso no se pisó, pero la talla (sin edición ajena) sí se guardó.
        $this->assertSame(70.0, $consulta->fresh()->peso);
        $this->assertSame(1.65, $consulta->fresh()->talla);
    }

    public function test_el_imc_no_se_guarda_en_ninguna_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // El formulario no manda un IMC (es un `<output>`), y aunque lo mandara no hay columna donde
        // ponerlo: el escritorio tampoco lo guarda.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'peso' => '70',
            'talla' => '1.62',
            'imc' => '26.67',
        ]);

        $this->assertSame(0, SyncChange::where('table_name', 'consultas')->where('column_name', 'imc')->count());
        $this->assertSame(0, SyncChange::where('table_name', 'examen_fisico_nuevo')->where('column_name', 'imc')->count());
    }

    public function test_un_numero_invalido_se_rechaza_y_no_borra_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->forceFill(['peso' => 70.0])->save();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from($this->urlSeccion($consulta))
            ->post($this->urlGuardar($consulta), [
                'peso' => 'ochenta',
                'abdomen' => 'Blando',
            ]);

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHasErrors('peso');

        // Nada se guardó: ni el peso inválido ni el abdomen que venía en el mismo POST.
        $this->assertSame(70.0, $consulta->fresh()->peso);
        $this->assertNull(ExamenFisico::where('reg_medico', $medico->reg_medico)->first());
    }

    // ------------------------------------------------------------------------------------ Los roles

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria);

        $contexto->get($this->urlSeccion($consulta))->assertForbidden();
        $contexto->post($this->urlGuardar($consulta), ['peso' => '70'])->assertForbidden();

        $this->assertNull($consulta->fresh()->peso);
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->get($this->urlSeccion($consulta))->assertRedirect(route('login'));
        $this->post($this->urlGuardar($consulta), ['peso' => '70'])->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------------------------- El 404 ajeno

    public function test_la_consulta_de_otro_medico_da_404_al_leer_y_al_guardar(): void
    {
        [, $otro] = $this->medicoConAcceso();
        [$pacienteAjeno, $consultaAjena] = $this->pacienteYConsulta($otro);

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // El shell de la consulta ajena ya da 404, así que no hay sección que leer…
        $contexto->get(route('clinica.consulta', [$consultaAjena->id, 'seccion' => 'evaluacion']))->assertNotFound();
        // …y el guardado tampoco la toca.
        $contexto->post($this->urlGuardar($consultaAjena), ['peso' => '70'])->assertNotFound();

        $this->assertNull($consultaAjena->fresh()->peso);
        $this->assertNotNull(\App\Models\Paciente::find($pacienteAjeno));
        // Y la consulta propia sigue sin datos: el POST ajeno no escribió en la de este médico.
        $this->assertNull($consulta->fresh()->peso);
    }

    // ------------------------------------------------------------------------------------ El panel

    public function test_el_panel_marca_la_evaluacion_fisica_como_disponible(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        $respuesta->assertSee('13 de 14 disponibles', false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'evaluacion']), false);
    }
}
