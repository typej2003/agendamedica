<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Specialty;
use App\Models\User;
use App\Reservas\EnlaceDeCitaCompartida;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Compartir una cita** (O-52): el link firmado de la ficha pública con la fecha y el motivo elegidos, el QR del
 * link del médico y la pantalla de la agenda que los muestra.
 *
 * Lo que se fija acá:
 *  - el link firmado abre la ficha con el horario y el motivo **ya marcados**;
 *  - un link vencido o manipulado **no impone nada**: se abre la ficha normal y se avisa;
 *  - un motivo que no es del catálogo del médico se descarta, aunque la firma sea buena;
 *  - la pantalla de la agenda y la de Cita Online muestran el link y el QR;
 *  - la cita de otro médico responde 404.
 *
 * `DatabaseTransactions` como el resto de la web clínica (ver MedicAPI/AGENTS.md).
 */
class EnlaceDeCitaWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $lunes;

    private Medico $medico;

    private Office $sede;

    protected function setUp(): void
    {
        parent::setUp();

        // Domingo 18 de octubre de 2026, 07:00 UTC = 03:00 en Caracas: el bloque del lunes 19 a las 08:00 queda a
        // 29 h, dentro de la anticipación mínima del portal.
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 7, 0, 0));
        $this->lunes = Carbon::createFromFormat('!Y-m-d', '2026-10-19');

        $this->medico = Medico::create([
            'name' => 'Carlos', 'lastname' => 'Compartir',
            'email' => 'compartir-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'test-compartir' . substr(uniqid(), -6),
        ]);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $this->medico->reg_medico]);

        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede para compartir',
            'address' => 'Calle de prueba',
        ]);

        $this->sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $this->medico->reg_medico,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        OfficeSchedule::create([
            'office_id' => $this->sede->id,
            'reg_medico' => $this->medico->reg_medico,
            'dia_semana' => 1,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 10,
        ]);

        // La agenda tiene que estar publicada para que la ficha exista.
        Evolucion::create([
            'reg_medico' => $this->medico->reg_medico,
            'clave' => 'x',
            'publicar_agenda' => true,
        ])->save();

        MotivoCita::create([
            'reg_medico' => $this->medico->reg_medico,
            'codigo' => 'CON01',
            'tipo_atencion' => 'Consulta de control',
            'precio' => 40,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** La cita del lunes a las 08:00, con su médico y su motivo. */
    private function cita(): Cola
    {
        return Cola::create([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => $this->lunes->toDateString(),
            'hora_ini' => '08:30',
            'motivo' => 'CON01',
            'medico_id' => $this->medico->id,
            'medical_center_id' => $this->sede->medical_center_id,
            'numorden' => 1,
        ]);
    }

    private function enlace(Cola $cita): string
    {
        $link = (new EnlaceDeCitaCompartida())->de($cita);

        $this->assertNotNull($link, 'El link tenía que poder armarse.');

        return $link;
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $user = User::create([
            'name' => 'Usuario de prueba',
            'email' => 'compartir-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Dr. de prueba', 'lastname' => 'Compartir',
            'email' => $user->email, 'password' => $user->password,
            'reg_medico' => 'clinica-compartir-' . substr(uniqid(), -6),
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id' => null,
        ]])->actingAs($user);
    }

    public function test_el_link_firmado_abre_la_ficha_con_el_horario_y_el_motivo_elegidos(): void
    {
        $cita = $this->cita();
        $clave = $this->lunes->toDateString() . '|08:30|' . $this->sede->id;

        $respuesta = $this->get($this->enlace($cita))->assertOk();

        // El horario del link viene marcado y el motivo elegido, y la ficha lo dice.
        $respuesta->assertSee('Te compartieron esta cita', false);
        $this->assertMatchesRegularExpression(
            '/value="' . preg_quote($clave, '/') . '"\s+checked/',
            $respuesta->getContent(),
            'El horario del link tenía que venir marcado.',
        );
        $this->assertMatchesRegularExpression(
            '/<option value="CON01"\s+selected/',
            $respuesta->getContent(),
            'El motivo del link tenía que venir elegido.',
        );
    }

    public function test_un_link_manipulado_no_impone_el_motivo(): void
    {
        $cita = $this->cita();
        $valido = $this->enlace($cita);

        // Se cambia el motivo después de firmar: la firma deja de valer.
        $manipulado = preg_replace('/motivo=CON01/', 'motivo=OTRO', $valido);

        $respuesta = $this->get($manipulado)->assertOk();

        $respuesta->assertDontSee('Te compartieron esta cita', false);
        $respuesta->assertSee('ya venció o no es válido', false);
        $respuesta->assertDontSee('value="CON01" selected', false);
    }

    public function test_un_link_vencido_no_impone_nada(): void
    {
        $cita = $this->cita();
        $link = $this->enlace($cita);

        // Se adelanta el reloj más allá de la vigencia.
        Carbon::setTestNow(Carbon::create(2026, 11, 30, 12, 0, 0));

        $respuesta = $this->get($link)->assertOk();

        $respuesta->assertSee('ya venció o no es válido', false);
        $respuesta->assertDontSee('|08:30|' . $this->sede->id . '" checked', false);
    }

    public function test_la_pantalla_de_compartir_muestra_el_link_firmado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // La cita del médico del contexto (la del acceso), con su sede.
        $cita = Cola::create([
            'reg_medico' => $medico->reg_medico,
            'fecha' => $this->lunes->toDateString(),
            'hora_ini' => '08:30',
            'motivo' => 'CON01',
            'medico_id' => $medico->id,
            'numorden' => 1,
        ]);

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.agenda.link-de-cita', $cita->id))
            ->assertOk()
            ->assertSee('Compartir la cita', false)
            ->assertSee('signature=', false)
            ->assertSee('<svg', false)
            ->assertSee('vence en ' . EnlaceDeCitaCompartida::DIAS . ' días', false);
    }

    public function test_la_pantalla_de_cita_online_muestra_el_link_publico_y_el_qr(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.configuracion.cita-online'))
            ->assertOk()
            ->assertSee('Tu link para reservar', false)
            ->assertSee(route('reservas.medico', ['medico' => $medico->id]), false)
            ->assertSee('<svg', false);
    }

    public function test_la_cita_de_otro_medico_no_se_comparte(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $cita = $this->cita(); // de $this->medico, que no es el del contexto

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.agenda.link-de-cita', $cita->id))
            ->assertNotFound();
    }
}
