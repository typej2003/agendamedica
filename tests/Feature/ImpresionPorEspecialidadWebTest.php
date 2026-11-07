<?php

namespace Tests\Feature;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\DocumentosPorCita;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Los imprimibles se separan por especialidad** (Alexander, 2026-11-06): «Es importante separar los imprimibles por
 * especialidad, no debemos ofrecer documentos de otras especialidades. ¿Un eco ginecológico a un niño?».
 *
 * El caso real: en pediatría, «Imprimir documentos» —el de la consulta y el de una cita ya atendida— seguía ofreciendo
 * la hoja del eco ginecológico, y con ella las de la colposcopía, la citología y la histeroscopía. La decisión es **la
 * misma de la historia del paciente** (O-72): la toma `AlcanceDeLaEspecialidad::documentosDeGinecologiaQueNoSeOfrecen()`,
 * no una lista nueva, y `DocumentosParaImprimir::ofrecidos()` deja esos documentos afuera de lo que se ofrece.
 *
 * Lo mismo con **lo de la pareja** (el récipe y su orden de laboratorio), que es de la misma especialidad
 * (`documentosDeLaParejaQueNoSeOfrecen()`), y con el **lote de la agenda** (`DocumentosPorCita::conDocumentos`), que
 * decide si la fila de la cita ofrece el botón.
 *
 * Lo que **no** es de otra especialidad —constancia, reposo, referencia, informe, hospitalización, dieta, laboratorio,
 * estudios, resumen médico, récipes y pre-factura— se sigue ofreciendo; **en ginecología no cambia nada**; y **sin
 * especialidad tampoco se ofrecen** (2026-10-30: no tener especialidad no es ginecología).
 *
 * La hoja pedida **por URL directa sigue abriendo**: se deja de ofrecer, no se rompe el enlace (2026-10-22).
 *
 * Los datos son inventados; lo que se fija es qué documentos se ofrecen.
 */
class ImpresionPorEspecialidadWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();
        parent::tearDown();
    }

    /**
     * El médico de la especialidad, su paciente con historia y consulta de hoy, la cita atendida y la secretaría.
     *
     * @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta, 4:Cola, 5:User, 6:int}
     */
    private function escenario(string $especialidad = 'ginecologia-y-obstetricia'): array
    {
        [$user, $medico, $specialty] = $this->medicoConAcceso('Medico', $especialidad);
        $this->membrete($medico);
        $this->vademecum($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $cita = $this->cita($medico, 1001, $this->hoy->toDateString(), ['atendido' => 1, 'estado' => 1]);
        $secretaria = $this->secretariaConAcceso($medico);

        return [$user, $medico, $specialty, $consulta, $cita, $secretaria, $pacienteId];
    }

    /**
     * Lo que el escritorio deja en una consulta que además tiene un eco ginecológico y dos de sus solapas de texto: es
     * el caso del niño que igual arrastra lo cargado en ginecología.
     */
    private function datosDeGinecologia(Medico $medico, Consulta $consulta): void
    {
        DB::table('eco_pelvico')->insert([
            'reg_medico'    => $medico->reg_medico,
            'numhistoria'   => (int) $consulta->numhistoria,
            'nroconsulta'   => (int) $consulta->nroconsulta,
            'fecha'         => '2026-10-01',
            'observacion'   => 'Anexos libres.',
            'idxecografico' => 'Útero en anteversión.',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // El texto de la histeroscopía (`cuello`) y el de la colposcopía (`observaciones`): columnas de `consultas`, que
        // se leen de la fila en memoria, así que se escriben por el modelo y no por SQL.
        $consulta->cuello = 'Canal cervical permeable, cavidad normal.';
        $consulta->observaciones = 'Zona de transformación tipo 1.';
        $consulta->save();
    }

    /** Los documentos del núcleo: una constancia con texto propio, un reposo y un récipe. */
    private function datosDelNucleo(Medico $medico, Consulta $consulta): void
    {
        DB::table('constancia_obs')->insert([
            'reg_medico'    => $medico->reg_medico,
            'numhistoria'   => (int) $consulta->numhistoria,
            'numconsulta'   => (int) $consulta->nroconsulta,
            'observacion01' => 'Asistió a su control.',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::table('reposo_paciente')->insert([
            'reg_medico'  => $medico->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'fdesde'      => '2026-10-07',
            'numdias'     => 3,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        $this->recipe($medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta, 1, ['0001']);
    }

    /** Los documentos de la pareja: su récipe y la orden de laboratorio a su nombre (sólo de ginecología). */
    private function datosDeLaPareja(Medico $medico, Consulta $consulta): void
    {
        $this->pareja($medico, (int) $consulta->numhistoria, (int) $consulta->nroconsulta, ['0001']);

        DB::table('examen_pareja')->insert([
            'reg_medico'  => $medico->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'codeexamen'  => '001',
            'cedula'      => '9876543',
            'nombre'      => 'Mariana Rojas',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** @return array<int,string> las claves de lo que se **ofrece** imprimir en esa especialidad */
    private function ofrecidas(Medico $medico, Consulta $consulta, ?string $especialidad): array
    {
        return array_column(
            app(DocumentosParaImprimir::class)->ofrecidos($consulta, [$medico->reg_medico], $this->alcance($especialidad)),
            'clave',
        );
    }

    /** La lista de los documentos de la cita atendida («Imprimir documentos» de la agenda). */
    private function listaDeLaCita(User $user, Medico $medico, Specialty $specialty, Cola $cita)
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user)
            ->get(route('clinica.agenda.documentos', $cita->id));
    }

    // ============================================================== Pediatría: la ginecología no se ofrece

    /**
     * **En pediatría**, con un niño cuya consulta arrastra los datos de un eco ginecológico, esa hoja **no se ofrece** —ni
     * sus solapas de texto— y las del núcleo siguen ahí. Es la observación de Alexander, en las dos pantallas: la lista de
     * la cita y lo que imprime «todos».
     */
    public function test_en_pediatria_no_se_ofrecen_los_documentos_de_ginecologia_y_si_los_del_nucleo(): void
    {
        [$user, $medico, $pediatria, $consulta, $cita] = $this->escenario('pediatria');
        $this->datosDeGinecologia($medico, $consulta);
        $this->datosDelNucleo($medico, $consulta);

        $ofrecidas = $this->ofrecidas($medico, $consulta, 'pediatria');

        // Lo de ginecología no se ofrece: el eco y las tres solapas de texto con hoja.
        $this->assertNotContains(DocumentoImpreso::ECO_PELVICO, $ofrecidas);
        $this->assertNotContains(DocumentoImpreso::COLPOSCOPIA, $ofrecidas);
        $this->assertNotContains(DocumentoImpreso::HISTEROSCOPIA, $ofrecidas);
        // Y lo que no es de ginecología sigue: constancia, reposo y el récipe.
        $this->assertContains(DocumentoImpreso::CONSTANCIA, $ofrecidas);
        $this->assertContains(DocumentoImpreso::REPOSO, $ofrecidas);
        $this->assertContains('recipe-1', $ofrecidas);

        // «Lo que la consulta tiene» no cambia (`disponibles`): por eso la hoja pedida por URL directa sigue abriendo.
        $this->assertContains(
            DocumentoImpreso::ECO_PELVICO,
            array_column(app(DocumentosParaImprimir::class)->disponibles($consulta, [$medico->reg_medico]), 'clave'),
        );

        // La lista de la cita: sin el eco ni la histeroscopía, con el núcleo.
        $this->listaDeLaCita($user, $medico, $pediatria, $cita)
            ->assertOk()
            ->assertSee('Constancia')
            ->assertSee('Reposo médico')
            ->assertDontSee('Eco pélvico')
            ->assertDontSee('Informe del eco pélvico')
            ->assertDontSee('Histeroscopía');

        // «Imprimir todos» imprime lo que se ofrece: la hoja del eco ginecológico no entra en el lote.
        $this->conContexto($user, $medico, $pediatria)->actingAs($user)
            ->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentosParaImprimir::TODOS]))
            ->assertOk()
            ->assertSee('CONSTANCIA')
            ->assertDontSee(DocumentoImpreso::tituloConSufijo(DocumentoImpreso::ECO_PELVICO));
    }

    /**
     * **La hoja por URL directa sigue abriendo** (2026-10-22): el alcance **deja de ofrecer** el documento, no rompe el
     * enlace ni borra el dato. Es la misma decisión que en la historia del paciente (O-72).
     */
    public function test_en_pediatria_la_hoja_del_eco_pedida_por_url_directa_sigue_abriendo(): void
    {
        [$user, $medico, $pediatria, $consulta] = $this->escenario('pediatria');
        $this->datosDeGinecologia($medico, $consulta);

        $this->conContexto($user, $medico, $pediatria)->actingAs($user)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => DocumentoImpreso::ECO_PELVICO]))
            ->assertOk()
            ->assertSee(DocumentoImpreso::tituloConSufijo(DocumentoImpreso::ECO_PELVICO));
    }

    // ======================================================================== Ginecología: no cambia nada

    /** **En ginecología no cambia nada**: el eco se sigue ofreciendo y se puede imprimir con todo lo demás. */
    public function test_en_ginecologia_el_eco_se_sigue_ofreciendo(): void
    {
        [$user, $medico, $gineco, $consulta, $cita] = $this->escenario();
        $this->datosDeGinecologia($medico, $consulta);
        $this->datosDelNucleo($medico, $consulta);

        $ofrecidas = $this->ofrecidas($medico, $consulta, 'ginecologia-y-obstetricia');

        $this->assertContains(DocumentoImpreso::ECO_PELVICO, $ofrecidas);
        $this->assertContains(DocumentoImpreso::COLPOSCOPIA, $ofrecidas);
        $this->assertContains(DocumentoImpreso::HISTEROSCOPIA, $ofrecidas);

        $this->listaDeLaCita($user, $medico, $gineco, $cita)
            ->assertOk()
            ->assertSee('Eco pélvico')
            ->assertSee('Histeroscopía');
    }

    // ============================================================== Sin especialidad: tampoco se ofrecen

    /**
     * **Sin especialidad tampoco se ofrecen** (2026-10-30: sin especialidad no es ginecología, es modo medicina general):
     * el consultorio sin especialidad no recibe el permiso más amplio por un dato que falta.
     */
    public function test_sin_especialidad_no_se_ofrecen_los_documentos_de_ginecologia(): void
    {
        [$user, $medico, $specialty, $consulta, $cita] = $this->escenario();
        // Un consultorio sin especialidad: la ficha no tiene ninguna asignada.
        $medico->specialties()->detach();
        $this->datosDeGinecologia($medico, $consulta);
        $this->datosDelNucleo($medico, $consulta);

        $ofrecidas = $this->ofrecidas($medico, $consulta, null);

        $this->assertNotContains(DocumentoImpreso::ECO_PELVICO, $ofrecidas);
        $this->assertNotContains(DocumentoImpreso::HISTEROSCOPIA, $ofrecidas);
        $this->assertContains(DocumentoImpreso::CONSTANCIA, $ofrecidas);
        $this->assertContains('recipe-1', $ofrecidas);

        // Y la pantalla, con el contexto sin especialidad, tampoco lo muestra.
        $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => null,
            'office_id'    => null,
        ]])->actingAs($user)
            ->get(route('clinica.agenda.documentos', $cita->id))
            ->assertOk()
            ->assertSee('Constancia')
            ->assertDontSee('Eco pélvico');
    }

    // ============================================================== La pareja: es de la misma especialidad

    /**
     * **El récipe de la pareja y su orden de laboratorio son de ginecología**: fuera de ginecología no se ofrecen
     * (`AlcanceDeLaEspecialidad::muestraLaPareja()`), aunque la consulta tenga los datos cargados; en ginecología se
     * siguen ofreciendo los dos.
     */
    public function test_fuera_de_ginecologia_no_se_ofrecen_los_imprimibles_de_la_pareja(): void
    {
        [, $pediatra, , $consultaDelNino] = $this->escenario('pediatria');
        $this->datosDeLaPareja($pediatra, $consultaDelNino);
        // El récipe del paciente, que sí se sigue ofreciendo: el filtro es de la pareja, no de la consulta entera.
        $this->recipe($pediatra, (int) $consultaDelNino->numhistoria, (int) $consultaDelNino->nroconsulta, 1, ['0002']);

        $dePediatria = $this->ofrecidas($pediatra, $consultaDelNino, 'pediatria');

        $this->assertNotContains('recipe-pareja', $dePediatria);
        $this->assertNotContains(DocumentoImpreso::LABORATORIO_PAREJA, $dePediatria);

        // Y lo del paciente se sigue ofreciendo: el filtro no se lleva la consulta entera.
        $this->assertContains('recipe-1', $dePediatria);

        [, $ginecologa, , $consulta] = $this->escenario();
        $this->datosDeLaPareja($ginecologa, $consulta);

        $deGinecologia = $this->ofrecidas($ginecologa, $consulta, 'ginecologia-y-obstetricia');

        $this->assertContains('recipe-pareja', $deGinecologia);
        $this->assertContains(DocumentoImpreso::LABORATORIO_PAREJA, $deGinecologia);
    }

    // ========================================================= El lote de la agenda: el botón de la fila

    /**
     * **El lote de la agenda no cuenta lo que la especialidad no ofrece**: en pediatría, una consulta cuyo único
     * contenido es un eco ginecológico no tiene nada para imprimir, así que la fila de la cita no ofrece el botón
     * (antes lo ofrecía y la lista decía que no había nada).
     */
    public function test_el_lote_de_la_agenda_no_cuenta_un_eco_ginecologico_en_pediatria(): void
    {
        [$user, $medico, $pediatria, $consulta, $cita, , $pacienteId] = $this->escenario('pediatria');
        $this->datosDeGinecologia($medico, $consulta);

        $deLaAgenda = new CitaDeAgenda(
            id: $cita->id, fecha: $this->hoy->toDateString(), horaIni: '10:30:00', numOrden: 1,
            estado: 1, atendido: 1, monto: null, montoPagado: null, numHistoria: (int) $consulta->numhistoria,
            pacienteSinHistoriaId: null, pacienteId: $pacienteId, paciente: 'Ana Alvarez',
            centroId: null, motivo: null, tipo: null, medico: null,
        );

        // Sin la lista de lo que la especialidad no ofrece (la lectura de ginecología) el eco cuenta.
        $this->assertArrayHasKey(
            $cita->id,
            app(DocumentosPorCita::class)->conDocumentos($medico->reg_medico, collect([$deLaAgenda])),
        );

        // Con la lista de pediatría, no: el único contenido es de ginecología.
        $this->assertSame(
            [],
            app(DocumentosPorCita::class)->conDocumentos(
                $medico->reg_medico,
                collect([$deLaAgenda]),
                $this->alcance('pediatria')->documentosQueNoSeOfrecen(),
            ),
        );

        // Y la agenda de pediatría no le ofrece el botón a esa cita.
        $agenda = $this->conContexto($user, $medico, $pediatria)->actingAs($user)
            ->get(route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => 'todas']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Imprimir documentos', $agenda);
    }
}
