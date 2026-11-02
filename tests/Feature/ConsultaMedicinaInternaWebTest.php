<?php

namespace Tests\Feature;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\Interna\MapaDelInternista;
use App\Clinica\Consulta\TagDeImagen;
use App\Clinica\Escaneo\LecturaFallida;
use App\Clinica\Escaneo\LectorDeHojas;
use App\Models\Consulta;
use App\Models\DatosGrafica;
use App\Models\Ekg;
use App\Models\FormatoPrint;
use App\Models\Historia;
use App\Models\ImagenConsulta;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PreTrombo;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **La consulta del internista** (MedicReport, olas MI-2 a MI-6, 2026-10-28).
 *
 * Lo que se fija:
 *  - el árbol de la consulta es el del escritorio de medicina interna (con plan de diagnóstico, análisis evolutivo y
 *    evaluación pre-operatoria, y sin «Tratamiento»), y la evaluación física de ginecología lleva a la suya;
 *  - **el mapa del escritorio**: el laboratorio va a las columnas de `consultas` que en ginecología son las circunferencias
 *    (HDL → `circcefalica`, glicemia → `circabdominal`, LDL → `otros`) y a `datos_grafica`; la cintura a `grosor_abdominal`;
 *    el IMC se calcula y se guarda;
 *  - el EKG, las estimaciones pre-operatorias y su impresión;
 *  - el análisis evolutivo junta las consultas de la historia;
 *  - el reposo absoluto se ofrece al internista;
 *  - ginecología no cambia.
 */
class ConsultaMedicinaInternaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medico(string $slug = 'medicina-interna'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $especialidad = Specialty::where('slug', $slug)->firstOrFail();
        $regMedico = 'mi-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctor de prueba', 'email' => 'mi-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctor', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $especialidad];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    private function consulta(Medico $medico, int $nro = 1, ?string $fecha = null): Consulta
    {
        if ($nro === 1) {
            $pacienteId = DB::table('pacientes')->insertGetId([
                'nombres' => 'Luis', 'apellidos' => 'Pérez', 'cedula' => (string) random_int(10000000, 30000000),
                'sexo' => 'M', 'fnacimiento' => '1970-05-04', 'created_at' => now(), 'updated_at' => now(),
            ]);
            MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $pacienteId, 'reg_medico' => $medico->reg_medico, 'numhistoria' => '2001']);
            Historia::create(['numhistoria' => '2001', 'reg_medico' => $medico->reg_medico, 'paciente_id' => $pacienteId, 'medico_id' => $medico->id]);
        }

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 2001, 'nroconsulta' => $nro,
            'fecha' => $fecha ?? now()->toDateString(),
        ]);
    }

    private function seccion(Consulta $consulta, string $seccion, ?string $pestana = null): string
    {
        return route('clinica.consulta', array_filter([$consulta->id, 'seccion' => $seccion, 'pestana' => $pestana]));
    }

    public function test_el_arbol_es_el_del_internista_y_la_evaluacion_de_ginecologia_lleva_a_la_suya(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()
            ->assertSee('Plan de diagnóstico')->assertSee('Análisis evolutivo')->assertSee('Evaluación pre-operatoria')
            ->assertSee('Observaciones')
            ->assertDontSee('Estudio ecográfico')->assertDontSee('Control de embarazo');

        $this->conContexto($user, $medico, $mi)->get($this->seccion($consulta, 'evaluacion'))
            ->assertRedirect($this->seccion($consulta, 'fisico-interna'));

        $this->conContexto($user, $medico, $mi)->get($this->seccion($consulta, 'fisico-interna'))
            ->assertOk()
            ->assertSee('Signos vitales')->assertSee('Examen funcional')->assertSee('Resumen físico')
            ->assertSee('Temperatura oral');
    }

    public function test_los_signos_guardan_en_las_columnas_del_escritorio_y_calculan_el_imc(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'signos', 'peso' => '80', 'talla' => '1,60', 'cintura' => '95', 'tasentado' => '120/80', 'tem_oral' => '36,8',
        ])->assertRedirect($this->seccion($consulta, 'fisico-interna', 'signos'));

        $consulta->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $consulta->peso, 0.001);
        $this->assertEqualsWithDelta(36.8, (float) $consulta->tem_oral, 0.001);
        $this->assertSame('120/80', $consulta->tasentado);
        $this->assertNull($consulta->circabdominal, 'la cintura NO va a circabdominal: en medicina interna esa columna es la glicemia');

        $grafica = DatosGrafica::where('reg_medico', $medico->reg_medico)->where('historia', 2001)->where('consulta', 1)->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $grafica->grosor_abdominal, 0.001);
        $this->assertEqualsWithDelta(31.25, (float) $grafica->indice_masa, 0.001, 'IMC = 80 / 1,6²');

        $this->assertTrue(SyncChange::where('table_name', 'datos_grafica')->where('operation', 'created')->where('source', 'web')->exists(), 'deja su rastro para el sync');
    }

    public function test_el_laboratorio_usa_las_columnas_reusadas_del_escritorio(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'laboratorio', 'hdl' => '45', 'ldl' => '130', 'trigliceridos' => '150', 'glicemia' => '98',
            'colesterol' => '210', 'creatinina' => '0,9',
        ])->assertRedirect();

        $consulta->refresh();
        $this->assertEqualsWithDelta(45.0, (float) $consulta->circcefalica, 0.001, 'HDL');
        $this->assertEqualsWithDelta(150.0, (float) $consulta->circtoraxica, 0.001, 'triglicéridos');
        $this->assertEqualsWithDelta(98.0, (float) $consulta->circabdominal, 0.001, 'glicemia');
        $this->assertSame('130', (string) $consulta->otros, 'LDL');

        $grafica = DatosGrafica::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertEqualsWithDelta(210.0, (float) $grafica->otro_13, 0.001, 'colesterol');
        $this->assertEqualsWithDelta(0.9, (float) $grafica->otro_8, 0.001, 'creatinina');
    }

    public function test_un_numero_invalido_no_se_guarda(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'signos', 'peso' => 'ochenta',
        ])->assertSessionHasErrors('peso');

        $this->assertNull($consulta->fresh()->peso);
    }

    public function test_el_ekg_se_guarda_con_sus_bloqueos_y_se_imprime(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'ekg', 'ekg_ritmo' => 'SINUSAL', 'ekg_fc' => '72', 'ekg_bav' => 'S', 'ekg_hbsai' => 'N',
            'ekg_conclusiones' => 'EKG dentro de límites normales.',
        ])->assertRedirect($this->seccion($consulta, 'plan-diagnostico', 'ekg'));

        $ekg = Ekg::where('reg_medico', $medico->reg_medico)->where('numhistoria', 2001)->where('numconsulta', 1)->firstOrFail();
        $this->assertSame('SINUSAL', $ekg->ritmo);
        $this->assertSame('S', $ekg->bav);
        $this->assertSame('N', $ekg->hbsai);

        $this->conContexto($user, $medico, $mi)->get($this->seccion($consulta, 'plan-diagnostico', 'ekg'))
            ->assertOk()->assertSee('EKG dentro de límites normales.')->assertSee('id="internista-imprimir"', false);

        $this->conContexto($user, $medico, $mi)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'ekg']))
            ->assertOk()->assertSee('E.K.G.')->assertSee('SINUSAL')->assertSee('Bloqueos: BAV.');
    }

    public function test_un_ekg_vacio_no_crea_la_fila(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'ekg', 'ekg_ritmo' => '', 'ekg_bav' => 'N',
        ])->assertRedirect();

        $this->assertFalse(Ekg::where('reg_medico', $medico->reg_medico)->exists(), 'una casilla en N no es un dato');
    }

    public function test_las_estimaciones_preoperatorias_se_guardan_y_entran_en_el_informe(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'estimaciones', 'pre_aneste_tipo' => 'B', 'pre_aneste_emergencia' => 'N',
            'pre_trombo_tipo' => 'B', 'pre_trombo_resultado' => 'Usar heparina profiláctica.', 'pre_infec_tipo' => 'Z',
        ])->assertSessionHasErrors('pre_infec_tipo');

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'estimaciones', 'pre_aneste_tipo' => 'B', 'pre_aneste_emergencia' => 'N',
            'pre_trombo_tipo' => 'B', 'pre_trombo_resultado' => 'Usar heparina profiláctica.',
        ])->assertRedirect($this->seccion($consulta, 'preoperatoria', 'estimaciones'));

        $this->assertSame('B', PreTrombo::where('reg_medico', $medico->reg_medico)->value('tipo'));

        $this->conContexto($user, $medico, $mi)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'evaluacion-preoperatoria']))
            ->assertOk()
            ->assertSee('Clase II')
            ->assertSee('Riesgo de trombosis venosa y tromboembolismo: Riesgo medio. Usar heparina profiláctica.');

        $this->conContexto($user, $medico, $mi)->get($this->seccion($consulta, 'preoperatoria', 'riesgo'))
            ->assertOk()->assertSee('Guardar el riesgo');
    }

    public function test_el_analisis_evolutivo_junta_las_consultas_de_la_historia(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $primera = $this->consulta($medico, 1, '2026-09-01');
        $segunda = $this->consulta($medico, 2, '2026-10-01');

        foreach ([[$primera, '200'], [$segunda, '180']] as [$consulta, $colesterol]) {
            $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
                'pestana' => 'laboratorio', 'colesterol' => $colesterol,
            ])->assertRedirect();
        }

        $this->conContexto($user, $medico, $mi)->get($this->seccion($segunda, 'analisis-evolutivo'))
            ->assertOk()
            ->assertSee('id="analisis-tabla"', false)
            ->assertSee('Colesterol')->assertSee('200')->assertSee('180')
            ->assertSee('01/09/2026')->assertSee('<polyline', false);

        $this->conContexto($user, $medico, $mi)
            ->get(route('clinica.consulta.documentos.imprimir', [$segunda->id, 'documento' => 'analisis-evolutivo']))
            ->assertOk()->assertSee('ANÁLISIS EVOLUTIVO')->assertSee('Colesterol (mg/dl): 200');
    }

    public function test_el_reposo_absoluto_es_del_internista(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        DB::table('reposo_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 2001, 'nroconsulta' => 1,
            'fdesde' => now()->toDateString(), 'numdias' => 3, 'obser_reposo' => 'Neumonía.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->conContexto($user, $medico, $mi)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'reposo-absoluto']))
            ->assertOk()->assertSee('DEBE GUARDAR REPOSO ABSOLUTO POR PRESENTAR')->assertSee('Neumonía.');

        [$userG, $medicoG, $gineco] = $this->medico('ginecologia-y-obstetricia');
        $consultaG = Consulta::create(['reg_medico' => $medicoG->reg_medico, 'numhistoria' => 2001, 'nroconsulta' => 1, 'fecha' => now()->toDateString()]);

        $this->conContexto($userG, $medicoG, $gineco)
            ->get(route('clinica.consulta.documentos.imprimir', [$consultaG->id, 'documento' => 'reposo-absoluto']))
            ->assertDontSee('DEBE GUARDAR REPOSO ABSOLUTO');
    }

    public function test_el_examen_funcional_ofrece_y_guarda_sus_plantillas(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        FormatoPrint::create([
            'reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'tipo' => 'EF',
            'titulo' => 'NORMAL', 'descripcion' => 'Sin disnea. Tolera el decúbito.',
        ]);

        $this->conContexto($user, $medico, $mi)->get($this->seccion($consulta, 'fisico-interna', 'funcional'))
            ->assertOk()
            ->assertSee('data-plantillas-panel', false)
            ->assertSee('data-tipo="EF"', false)
            ->assertSee('Plantillas de examen funcional')
            ->assertSee('NORMAL');

        // «Guardar como plantilla» desde el mismo cajón (Alexander, 2026-10-29).
        $this->conContexto($user, $medico, $mi)->postJson(route('clinica.plantillas.guardar'), [
            'tipo' => 'EF', 'titulo' => 'DISNEA', 'descripcion' => 'Disnea de esfuerzo.',
        ])->assertCreated()->assertJsonPath('titulo', 'DISNEA');

        $this->assertSame(2, FormatoPrint::where('reg_medico', $medico->reg_medico)->where('tipo', 'EF')->count());
    }

    public function test_actualizar_arma_el_resumen_fisico_con_los_signos_y_el_examen_guardados(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'signos', 'peso' => '80', 'talla' => '1,60', 'fc' => '72', 'taacostado' => '120/80',
        ])->assertRedirect();

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'examen', 'genera' => 'Buen estado', 'cardiopulmonal' => 'Sin alteraciones',
        ])->assertRedirect();

        // La solapa del resumen ofrece el botón y su URL (el `ue_resume` del escritorio).
        $this->conContexto($user, $medico, $mi)->get($this->seccion($consulta, 'fisico-interna', 'resumen'))
            ->assertOk()
            ->assertSee('data-url-resumen="' . route('clinica.consulta.internista.resumen', $consulta->id) . '"', false)
            ->assertSee('data-resumen-actualizar="fisico"', false)
            ->assertSee('id="interna-resultadoexamencomp"', false);

        $this->conContexto($user, $medico, $mi)->postJson(route('clinica.consulta.internista.resumen', $consulta->id))
            ->assertOk()
            ->assertJsonPath('fisico', 'PESO: 80 kg.  TALLA: 1,6 m.  IMC: 31,25.  F. CARDIACA: 72.  '
                . 'TENSIÓN ARTERIAL: 120/80 mm/Hg.  GENERAL: Buen estado.  CARDIOPULMONAR: Sin alteraciones.  .');

        // Armar el resumen no escribe nada: el médico lo revisa y lo guarda con el botón de la pestaña.
        $this->assertNull($consulta->fresh()->resultadoexamencomp);
    }

    public function test_ginecologia_no_cambia(): void
    {
        [$user, $medico, $gineco] = $this->medico('ginecologia-y-obstetricia');
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $gineco)->get($this->seccion($consulta, 'evaluacion'))
            ->assertOk()->assertDontSee('Plan de diagnóstico')->assertDontSee('Análisis evolutivo');

        $this->conContexto($user, $medico, $gineco)->get($this->seccion($consulta, 'plan-diagnostico'))
            ->assertOk()->assertDontSee('id="internista-form"', false);
    }

    public function test_el_resumen_medico_y_la_historia_impresa_son_los_del_internista(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);
        // En su escritorio `faringe` es la Rx de tórax y `otros` el LDL (trampa 181).
        $consulta->forceFill(['resultadoexamencomp' => 'Paciente en buenas condiciones generales.', 'faringe' => 'Índice cardiotorácico normal.', 'otros' => '130'])->save();
        Ekg::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 2001, 'numconsulta' => 1, 'ritmo' => 'SINUSAL', 'conclusiones' => 'EKG dentro de límites normales.']);

        // Resumen médico: «EXAMEN FÍSICO» es el resumen físico.
        $this->conContexto($user, $medico, $mi)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'resumen-medico']))
            ->assertOk()->assertSee('EXAMEN FÍSICO:')->assertSee('Paciente en buenas condiciones generales.');

        // Historia impresa: el examen, el EKG y la Rx con sus nombres; nada de «Faringe» ni «Otros».
        $paciente = (int) MedicoPaciente::where('reg_medico', $medico->reg_medico)->value('paciente_id');
        $this->conContexto($user, $medico, $mi)->get(route('clinica.pacientes.historia.imprimir', $paciente))
            ->assertOk()
            ->assertSeeInOrder(['Examen físico', 'Paciente en buenas condiciones generales.', 'E.K.G.', 'EKG dentro de límites normales.', 'Rx de tórax', 'Índice cardiotorácico normal.'])
            ->assertDontSee('Faringe:')->assertDontSee('Otros: 130');
    }

    public function test_la_historia_impresa_de_ginecologia_conserva_sus_rotulos(): void
    {
        [$user, $medico, $gineco] = $this->medico('ginecologia-y-obstetricia');
        $consulta = $this->consulta($medico);
        $consulta->forceFill(['faringe' => 'Normal.'])->save();

        $paciente = (int) MedicoPaciente::where('reg_medico', $medico->reg_medico)->value('paciente_id');
        $this->conContexto($user, $medico, $gineco)->get(route('clinica.pacientes.historia.imprimir', $paciente))
            ->assertOk()->assertSee('Faringe: Normal.')->assertDontSee('Rx de tórax');
    }

    /**
     * **Las fotos de la placa de la Rx de tórax** (O-44): la pestaña `rx` del plan de diagnóstico acepta varias
     * imágenes —la placa y su lateral—, las guarda con la etiqueta `rx` y vuelve a su pestaña (no a Evaluación
     * física), y el campo de los hallazgos sigue igual.
     */
    public function test_la_rx_de_torax_sube_varias_placas_y_vuelve_a_su_pestana(): void
    {
        Storage::fake(ArchivosClinicos::DISCO);

        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);
        $consulta->forceFill(['faringe' => 'Índice cardiotorácico normal.'])->save();

        $destino = $this->seccion($consulta, MapaDelInternista::SECCION_PLAN, 'rx');

        // La tarjeta de la pestaña ya ofrece la lista y la subida, y el campo de los hallazgos sigue ahí.
        $this->conContexto($user, $medico, $mi)->get($destino)
            ->assertOk()
            ->assertSee('Fotos de la placa')
            ->assertSee('Índice cardiotorácico normal.')
            ->assertSee('id="rx-placa-subida"', false);

        $this->conContexto($user, $medico, $mi)
            ->post(route('clinica.consulta.examenes.subir', $consulta->id), [
                'examen' => 'rx',
                'imagen' => UploadedFile::fake()->image('placa-pa.jpg', 800, 600),
            ])
            ->assertRedirect($destino);

        $placa = ImagenConsulta::where('reg_medico', $medico->reg_medico)
            ->where('observacion', TagDeImagen::RX)
            ->firstOrFail();

        $this->assertSame(2001, (int) $placa->nrohistoria);
        $this->assertSame(1, (int) $placa->nroconsulta);
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($placa->imagen);

        // Se ven en la tarjeta, y una segunda sube sin pisar la primera (son varias). El nombre del archivo no se
        // muestra —lo pone el servidor—: la fila se anuncia por su examen.
        $this->conContexto($user, $medico, $mi)->get($destino)
            ->assertOk()
            ->assertSee('Imagen de Rx de tórax')
            ->assertDontSee('Todavía no hay imágenes en Rx de tórax');

        $this->conContexto($user, $medico, $mi)
            ->post(route('clinica.consulta.examenes.subir', $consulta->id), [
                'examen' => 'rx',
                'imagen' => UploadedFile::fake()->image('placa-lateral.jpg', 800, 600),
            ])
            ->assertRedirect($destino);

        $this->assertSame(2, ImagenConsulta::where('reg_medico', $medico->reg_medico)->where('observacion', TagDeImagen::RX)->count());

        // Se puede abrir y se puede quitar, y vuelve a la misma pestaña.
        $this->conContexto($user, $medico, $mi)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $placa->id]))
            ->assertOk();

        $this->conContexto($user, $medico, $mi)
            ->delete(route('clinica.consulta.examenes.quitar', [$consulta->id, $placa->id]), ['examen' => 'rx'])
            ->assertRedirect($destino);

        $this->assertNull(ImagenConsulta::find($placa->id));
        Storage::disk(ArchivosClinicos::DISCO)->assertMissing($placa->imagen);
    }

    /**
     * **Resultados de exámenes** (O-49): una sección propia del internista, detrás de *Plan de diagnóstico*, que guarda
     * en la columna nueva `consultas.resultados_examenes`. La columna no se reusa: en medicina interna el escritorio
     * rotula `oido` como «EXAMEN FÍSICO» y `resultadoexamencomp` es el resumen físico.
     */
    public function test_resultados_de_examenes_es_una_seccion_propia_y_guarda_en_su_columna(): void
    {
        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        // Está en el árbol, después de Plan de diagnóstico, y su bloque se abre.
        $arbol = $this->conContexto($user, $medico, $mi)->get(route('clinica.consulta', $consulta->id))->assertOk();
        $arbol->assertSee('Resultados de exámenes');
        $this->assertStringContainsString('Resultados de exámenes', (string) $arbol->getContent());

        $destino = $this->seccion($consulta, MapaDelInternista::SECCION_RESULTADOS, 'resultados');

        $this->conContexto($user, $medico, $mi)->get($destino)
            ->assertOk()
            ->assertSee('Resultados de exámenes')
            ->assertSee('id="interna-resultados_examenes"', false)
            ->assertSee('Guardar resultados de exámenes');

        $this->conContexto($user, $medico, $mi)->post(route('clinica.consulta.internista.guardar', $consulta->id), [
            'pestana' => 'resultados',
            'resultados_examenes' => "HEMOGLOBINA: 12,8 g/dL\nGLUCOSA: 92 mg/dL",
        ])->assertRedirect($destino);

        $consulta->refresh();
        $this->assertSame("HEMOGLOBINA: 12,8 g/dL\nGLUCOSA: 92 mg/dL", $consulta->resultados_examenes);
        $this->assertNull($consulta->oido, 'no se reusa la columna que en medicina interna es el examen físico');
        $this->assertNull($consulta->resultadoexamencomp, 'ni la del resumen físico');
        $this->assertTrue(
            SyncChange::where('table_name', 'consultas')->where('column_name', 'resultados_examenes')->where('source', 'web')->exists(),
            'deja su rastro por columna para el sync',
        );
    }

    /**
     * **Escanear la hoja** (O-49): la misma lectura de WEB-13.15, con la ruta propia de la sección. Devuelve el texto y
     * **no escribe nada** —ni la consulta ni el rastro—: el médico revisa y guarda con el botón de la sección.
     */
    public function test_la_hoja_de_resultados_se_lee_y_no_se_guarda(): void
    {
        $lecturas = new \ArrayObject();
        $this->app->instance(LectorDeHojas::class, new class($lecturas) implements LectorDeHojas {
            public function __construct(private \ArrayObject $lecturas)
            {
            }

            public function disponible(): bool
            {
                return true;
            }

            public function leer(string $contenido, string $mime): string
            {
                $this->lecturas[] = ['mime' => $mime, 'bytes' => strlen($contenido)];

                return "HEMOGLOBINA: 12,8 g/dL\nGLUCOSA: 92 mg/dL";
            }
        });

        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);
        $rastros = SyncChange::count();

        // El botón está en la tarjeta y apunta a la ruta de la sección.
        $this->conContexto($user, $medico, $mi)->get($this->seccion($consulta, MapaDelInternista::SECCION_RESULTADOS))
            ->assertOk()
            ->assertSee('Escanear hoja')
            ->assertSee('data-escanear-hoja', false)
            ->assertSee(route('clinica.consulta.resultados.escanear', $consulta->id), false);

        $this->conContexto($user, $medico, $mi)
            ->post(route('clinica.consulta.resultados.escanear', $consulta->id), [
                'hoja' => UploadedFile::fake()->createWithContent('hoja.png', base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
                )),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('texto', "HEMOGLOBINA: 12,8 g/dL\nGLUCOSA: 92 mg/dL")
            ->assertJsonStructure(['texto', 'aviso']);

        $this->assertCount(1, $lecturas);
        $this->assertSame('image/png', $lecturas[0]['mime']);
        $this->assertNull($consulta->fresh()->resultados_examenes, 'la lectura no escribe: eso es del guardado');
        $this->assertSame($rastros, SyncChange::count(), 'la lectura no deja rastro de sync');
    }

    /** Sin proveedor de lectura, el botón no aparece: el médico escribe el resultado a mano. */
    public function test_sin_proveedor_de_lectura_no_se_ofrece_escanear(): void
    {
        $this->app->instance(LectorDeHojas::class, new class implements LectorDeHojas {
            public function disponible(): bool
            {
                return false;
            }

            public function leer(string $contenido, string $mime): string
            {
                throw LecturaFallida::noDisponible();
            }
        });

        [$user, $medico, $mi] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $mi)->get($this->seccion($consulta, MapaDelInternista::SECCION_RESULTADOS))
            ->assertOk()
            ->assertSee('id="interna-resultados_examenes"', false)
            ->assertDontSee('data-escanear-hoja', false)
            ->assertDontSee('Escanear hoja');
    }
}
