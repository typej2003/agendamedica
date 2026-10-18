<?php

namespace Tests\Feature;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Impresion\DocumentoImpreso;
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
 * **Los dos ecos obstétricos en la historia del paciente y en el lote de la cita** (el hueco que dejó WEB-6.2,
 * cerrado en WEB-6.3).
 *
 * WEB-6.2 les dio su hoja y su botón de imprimir en la consulta, pero dejó dos lugares sin sumar sus claves:
 * la **historia del paciente** no los listaba y `DocumentosPorCita` —el SQL que decide si la cita atendida
 * lleva el botón «Imprimir documentos»— tampoco los contaba. Las dos hojas se imprimían sólo desde la consulta,
 * así que la secretaría no las veía.
 *
 * Lo que estos casos fijan, entonces:
 *
 *  - la historia **lista** un eco obstétrico con datos, el del primer trimestre y el del 2.º/3.er;
 *  - el **lote de la cita** lo cuenta (con la clave `historia` + `consulta` de su tabla);
 *  - y un eco que sólo trae su ficha (la fecha, la FUR y la gesta) **no** se lista ni cuenta, como los demás
 *    estudios: no es un documento.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class EcosObstetricosEnHistoriaYCitaTest extends TestCase
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

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta, 4:Cola, 5:int, 6:CitaDeAgenda} */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $cita = $this->cita($medico, 1001, $this->hoy->toDateString(), ['atendido' => 1, 'estado' => 1]);

        $deLaAgenda = new CitaDeAgenda(
            id: $cita->id, fecha: $this->hoy->toDateString(), horaIni: '10:30:00', numOrden: 1,
            estado: 1, atendido: 1, monto: null, montoPagado: null, numHistoria: 1001,
            pacienteSinHistoriaId: null, pacienteId: $pacienteId, paciente: 'Ana Alvarez',
            centroId: null, motivo: null, tipo: null, medico: null,
        );

        return [$user, $medico, $gineco, $consulta, $cita, $pacienteId, $deLaAgenda];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    /**
     * El eco obstétrico del **primer trimestre** (`eco_obstetrico`), como lo escribe el escritorio: su clave es
     * `historia` + `consulta` y `lcc` es una de sus medidas.
     */
    private function primerTrimestre(Medico $medico, ?int $lcc = 60): void
    {
        DB::table('eco_obstetrico')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'fecha' => '2026-06-01', 'fur' => '2026-05-20', 'gesta' => 2, 'lcc' => $lcc,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** El del **2.º/3.er trimestre** (`eco_obstetrico_tercer`): la misma clave y otra tabla. */
    private function tercerTrimestre(Medico $medico, ?int $dbp = 40): void
    {
        DB::table('eco_obstetrico_tercer')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'fecha' => '2026-06-01', 'fur' => '2026-05-20', 'gesta' => 2, 'bio_ca_bp' => $dbp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ======================================================================== La historia del paciente

    public function test_la_historia_lista_el_eco_obstetrico_del_primer_trimestre(): void
    {
        [$user, $medico, $gineco, , , $pacienteId] = $this->escenario();
        $this->primerTrimestre($medico);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertSee('Eco obstétrico (1.er trimestre)')
            ->assertSee('Informe del eco obstétrico (1.er trimestre)');
    }

    public function test_la_historia_lista_el_eco_obstetrico_del_tercer_trimestre(): void
    {
        [$user, $medico, $gineco, , , $pacienteId] = $this->escenario();
        $this->tercerTrimestre($medico);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertSee('Eco obstétrico (3.er trimestre)')
            ->assertSee('Informe del eco obstétrico (3.er trimestre)');
    }

    public function test_la_historia_no_lista_un_eco_obstetrico_que_solo_trae_su_ficha(): void
    {
        [$user, $medico, $gineco, , , $pacienteId] = $this->escenario();
        // Sólo la ficha (la fecha, la FUR y la gesta): no es un documento de nadie.
        $this->primerTrimestre($medico, null);
        $this->tercerTrimestre($medico, null);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertDontSee('Eco obstétrico');
    }

    // ================================================================== El lote de la cita atendida

    public function test_el_lote_de_la_cita_cuenta_los_dos_ecos_obstetricos(): void
    {
        [, $medico, , , $cita, , $deLaAgenda] = $this->escenario();
        $this->primerTrimestre($medico);
        $this->tercerTrimestre($medico);

        $this->assertArrayHasKey(
            $cita->id,
            app(DocumentosPorCita::class)->conDocumentos($medico->reg_medico, collect([$deLaAgenda])),
        );
    }

    public function test_un_eco_obstetrico_sin_datos_no_hace_que_la_cita_tenga_documentos(): void
    {
        [, $medico, , , , , $deLaAgenda] = $this->escenario();
        $this->primerTrimestre($medico, null);
        $this->tercerTrimestre($medico, null);

        $this->assertSame(
            [],
            app(DocumentosPorCita::class)->conDocumentos($medico->reg_medico, collect([$deLaAgenda])),
        );
    }

    /** Y la hoja de cada uno sigue saliendo desde la consulta, como antes de este cambio. */
    public function test_las_dos_hojas_se_siguen_ofreciendo_desde_la_consulta(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->primerTrimestre($medico);
        $this->tercerTrimestre($medico);

        foreach ([DocumentoImpreso::ECO_OBSTETRICO, DocumentoImpreso::ECO_OBSTETRICO_TERCERO] as $documento) {
            $this->como($user, $medico, $gineco)
                ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]))
                ->assertOk();
        }
    }
}
