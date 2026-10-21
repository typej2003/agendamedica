<?php

namespace Tests\Feature;

use App\Actions\Consulta\CrearConsulta;
use App\Clinica\Impresion\MedicoQueFirma;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **La consulta queda con su doctor** (2026-10-20).
 *
 * Hasta acá la consulta que abría la web dejaba `consultas.medico_id` y `consultas.medico` (la clave legacy de
 * `evolucion`) nulas, y `App\Clinica\Impresion\MedicoQueFirma` caía en el médico del contexto. En un centro eso es la
 * fila del centro cuando imprime la secretaría, así que la firma podía no ser la del doctor que atendió.
 *
 * Lo que estos casos fijan:
 *
 *  - la consulta que crea **"Atender" en la web** queda con el **doctor del contexto**: `medico_id` y su clave legacy,
 *    resuelta como en la Fase 2 (en cualquiera de sus registros de trabajo);
 *  - `MedicoQueFirma` la resuelve por `medico_id` —y, sin él, por la clave— sin caer en el respaldo;
 *  - la **impresión** de esa consulta firma ese doctor aunque el membrete sea el del centro y el que imprima sea otro
 *    doctor del centro;
 *  - en el **consultorio personal** sigue igual (la consulta es del doctor y firma él);
 *  - una consulta que **ya existía** no se toca: "re-atender" la reusa y no le escribe el doctor ni ninguna otra
 *    columna;
 *  - una llamada a la Action **sin médico** —el tercer parámetro sigue siendo opcional— deja la consulta sin doctor
 *    y el firmante cae en el respaldo: es el estado de las consultas creadas antes del 2026-10-20 (el sync del móvil
 *    ya pasa el suyo, ver `CreacionesClinicasTest`).
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica. "Hoy" es `2026-10-07 09:00` (UTC) = las 05:00 en
 * Caracas, el mismo día del consultorio.
 */
class MedicoDeLaConsultaWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fijarHoy();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /**
     * El **centro** con un doctor que trabaja en él: el formato del centro (el membrete que se imprime), la firma del
     * doctor y su `clave` de `evolucion` en su propio registro, que es la que va a `consultas.medico`.
     *
     * @return array{centro:Medico, doctor:Medico, doctorUser:User, gineco:Specialty}
     */
    private function escenarioDelCentro(): array
    {
        [$doctorUser, $doctor, $gineco] = $this->medicoConAcceso();

        $centro = Medico::create([
            'name'             => 'Centro',
            'lastname'         => 'Médico',
            'reg_medico'       => 'centro-consulta-' . substr(uniqid(), -6),
            'es_centro'        => true,
            'formato_reportes' => Medico::FORMATO_DEL_CENTRO,
            'is_active'        => true,
        ]);

        // El doctor trabaja en el centro: es lo que lo hace asignable y lo que pone al centro en su contexto.
        MedicoRegistro::create(['medico_id' => $doctor->id, 'reg_medico' => $centro->reg_medico]);

        Storage::disk('public')->put('recipe/firmas/doctor.png', 'png');
        Storage::disk('public')->put('logos/centro.png', 'png');

        // El formato del centro: el membrete que sale en la hoja.
        $this->membrete($centro, [
            'especialidad' => 'Servicio del centro',
            'rif'          => 'J-CENTRO-1',
            'logo'         => 'logos/centro.png',
        ]);

        // El del doctor: su `clave` de `evolucion` (la que el escritorio lee) y su firma, que es la que se imprime.
        $this->membrete($doctor, [
            'correo_med' => $doctor->email,
            'clave'      => 7,
        ], [
            'firma' => 'recipe/firmas/doctor.png',
        ]);

        $this->vademecum($centro);

        return ['centro' => $centro, 'doctor' => $doctor, 'doctorUser' => $doctorUser, 'gineco' => $gineco];
    }

    /**
     * El paciente del consultorio con su número de historia y su historia clínica, **sin consulta**: la crea
     * "Atender". Devuelve el `pacientes.id`; la cita se enlaza por el número.
     */
    private function pacienteConHistoria(Medico $medico, int $numhistoria): int
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nac'         => 'V',
            'nombres'     => 'Ana',
            'apellidos'   => 'Alvarez',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-10-06',
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

    /** El "Atender" del doctor sobre esa cita, con el centro (o el consultorio personal) como contexto. */
    private function atender(User $user, Medico $contexto, Specialty $gineco, Cola $cita): void
    {
        $this->conContexto($user, $contexto, $gineco)
            ->actingAs($user)
            ->post(route('clinica.agenda.atender', $cita->id))
            ->assertRedirect();
    }

    /** La consulta del consultorio que quedó para ese número de historia. */
    private function consultaDe(Medico $medico, int $numhistoria): Consulta
    {
        return Consulta::where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', $numhistoria)
            ->orderBy('nroconsulta')
            ->firstOrFail();
    }

    // --------------------------------------------------------------- La consulta queda con su doctor

    public function test_la_consulta_que_abre_la_web_queda_con_el_doctor_del_contexto_y_su_clave(): void
    {
        $datos = $this->escenarioDelCentro();
        $this->pacienteConHistoria($datos['centro'], 7001);
        $cita = $this->cita($datos['centro'], 7001, '2026-10-07', ['medico' => $datos['centro']->id]);

        $this->atender($datos['doctorUser'], $datos['centro'], $datos['gineco'], $cita);

        $consulta = $this->consultaDe($datos['centro'], 7001);

        // El `medicos.id` del doctor que atendió: es la fuente de verdad de `MedicoQueFirma`.
        $this->assertSame((int) $datos['doctor']->id, (int) $consulta->medico_id);
        // Y su clave legacy de `evolucion`, que es la que sigue leyendo el escritorio (buscada en sus registros).
        $this->assertSame(7, (int) $consulta->medico);
        // La cita quedó atendida por él, como en la Fase 3.
        $this->assertSame((int) $datos['doctor']->id, (int) $cita->fresh()->medico_id);
        $this->assertSame(1, (int) $cita->fresh()->atendido);
    }

    public function test_el_medico_que_firma_resuelve_la_consulta_por_su_doctor_y_no_por_el_contexto(): void
    {
        $datos = $this->escenarioDelCentro();
        $this->pacienteConHistoria($datos['centro'], 7002);
        $cita = $this->cita($datos['centro'], 7002, '2026-10-07', ['medico' => $datos['centro']->id]);
        $this->atender($datos['doctorUser'], $datos['centro'], $datos['gineco'], $cita);

        $consulta = $this->consultaDe($datos['centro'], 7002);
        $firma = app(MedicoQueFirma::class);

        // Aunque el respaldo sea el centro (el contexto de la secretaría), manda el `medico_id` de la consulta.
        $this->assertSame((int) $datos['doctor']->id, (int) $firma->deUnaConsulta($consulta, $datos['centro'])->id);

        // Y sin `medico_id` —una consulta vieja o del móvil— la clave legacy resuelve al mismo doctor.
        $consulta->forceFill(['medico_id' => null])->save();
        $this->assertSame((int) $datos['doctor']->id, (int) $firma->deUnaConsulta($consulta->fresh(), $datos['centro'])->id);
    }

    /**
     * **La hoja de esa consulta firma al doctor** que la atendió: el membrete es el del centro, la firma la del doctor,
     * y el que imprime puede ser **otro** doctor del centro (el médico del contexto) sin cambiar nada.
     */
    public function test_la_impresion_de_esa_consulta_firma_a_ese_doctor_y_no_al_centro(): void
    {
        $datos = $this->escenarioDelCentro();
        $this->pacienteConHistoria($datos['centro'], 7003);
        $cita = $this->cita($datos['centro'], 7003, '2026-10-07', ['medico' => $datos['centro']->id]);
        $this->atender($datos['doctorUser'], $datos['centro'], $datos['gineco'], $cita);

        $consulta = $this->consultaDe($datos['centro'], 7003);
        $this->recipe($datos['centro'], 7003, (int) $consulta->nroconsulta, 1, [['0001', 2, 'Una cápsula cada 6 horas.']], '2026-10-05');

        // Otro doctor del centro, que no tiene `evolucion` ni formato: es sólo el médico del contexto.
        [, $otro] = $this->medicoConAcceso();
        $otro->forceFill(['name' => 'Beatriz', 'lastname' => 'Suplente'])->save();
        $otro = $otro->fresh();
        MedicoRegistro::create(['medico_id' => $otro->id, 'reg_medico' => $datos['centro']->reg_medico]);

        $html = $this->conContexto($otro->user, $datos['centro'], $datos['gineco'])
            ->actingAs($otro->user)
            ->get(route('clinica.consulta.recipes.imprimir', [$consulta->id, 1]))
            ->assertOk()
            ->getContent();

        // Firma el doctor de la consulta, con su imagen de firma.
        $this->assertStringContainsString('firma-nombre">' . $datos['doctor']->nombreMostrar, $html);
        $this->assertStringContainsString('/storage/recipe/firmas/doctor.png', $html);
        $this->assertStringNotContainsString('firma-nombre">' . $otro->nombreMostrar, $html);
        // El membrete es el del centro: el formato no se mezcla con la firma.
        $this->assertStringContainsString('Servicio del centro', $html);
        $this->assertStringContainsString('J-CENTRO-1', $html);
        $this->assertStringNotContainsString('firma-nombre">Centro Médico', $html);
        // Y el QR de verificación queda avalado por el doctor.
        $this->assertSame(
            (int) $datos['doctor']->id,
            (int) DB::table('recipe_verificaciones')->where('reg_medico', $datos['centro']->reg_medico)->value('medico_id'),
        );
    }

    public function test_en_el_consultorio_personal_la_consulta_sigue_quedando_del_doctor(): void
    {
        [$user, $doctor, $gineco] = $this->medicoConAcceso();
        $this->membrete($doctor, ['correo_med' => $doctor->email, 'clave' => 12]);
        $this->pacienteConHistoria($doctor, 7004);
        $cita = $this->cita($doctor, 7004, '2026-10-07');

        $this->atender($user, $doctor, $gineco, $cita);

        $consulta = $this->consultaDe($doctor, 7004);

        $this->assertSame((int) $doctor->id, (int) $consulta->medico_id);
        $this->assertSame(12, (int) $consulta->medico);
        $this->assertSame((int) $doctor->id, (int) app(MedicoQueFirma::class)->deUnaConsulta($consulta)->id);
    }

    /** **Una consulta que ya existía no se toca**: "re-atender" la reusa y no le escribe el doctor. */
    public function test_una_consulta_que_ya_existia_no_se_toca(): void
    {
        $datos = $this->escenarioDelCentro();
        $this->pacienteConHistoria($datos['centro'], 7005);
        $existente = Consulta::create([
            'reg_medico'       => $datos['centro']->reg_medico,
            'numhistoria'      => 7005,
            'nroconsulta'      => 1,
            'fecha'            => '2026-10-07',
            'enfermedadactual' => 'Lo que ya estaba escrito.',
        ]);
        $cita = $this->cita($datos['centro'], 7005, '2026-10-07', ['medico' => $datos['centro']->id]);

        $this->atender($datos['doctorUser'], $datos['centro'], $datos['gineco'], $cita);

        $this->assertSame(1, Consulta::where('reg_medico', $datos['centro']->reg_medico)->where('numhistoria', 7005)->count());

        $fresca = $existente->fresh();
        $this->assertNull($fresca->medico_id, 'La consulta que ya existía no se toca.');
        $this->assertNull($fresca->medico);
        $this->assertSame('Lo que ya estaba escrito.', $fresca->enfermedadactual);
    }

    /**
     * **Sin médico la consulta nace sin doctor**: el tercer parámetro de la Action es opcional, así que una llamada
     * sin él deja `medico_id` y `medico` nulos y el firmante cae en el respaldo. Es lo que queda de las consultas
     * creadas antes del 2026-10-20 (el sync del móvil y la web ya pasan su médico).
     */
    public function test_sin_medico_la_consulta_nace_sin_doctor_y_el_firmante_cae_en_el_respaldo(): void
    {
        $datos = $this->escenarioDelCentro();
        $pacienteId = $this->pacienteConHistoria($datos['centro'], 7006);

        $historia = Historia::where('reg_medico', $datos['centro']->reg_medico)
            ->where('paciente_id', $pacienteId)
            ->firstOrFail();

        $consulta = app(CrearConsulta::class)->ejecutar($historia, Carbon::parse('2026-10-07'));

        $this->assertNull($consulta->medico_id);
        $this->assertNull($consulta->medico);
        $this->assertSame(1, (int) $consulta->nroconsulta);
    }
}
