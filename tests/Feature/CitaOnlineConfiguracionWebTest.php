<?php

namespace Tests\Feature;

use App\Clinica\CitaOnline\ConfiguracionDeCitaOnline;
use App\Models\Evolucion;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Specialty;
use App\Models\User;
use App\Reservas\CatalogoDeMedicos;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Cita Online en Configuración** (2026-10-30): la mitad de configuración del cobro de citas.
 *
 * Lo que se fija acá:
 *  - el porcentaje se guarda en **puntos base** (`10000` = 100 %), incluidos los extremos 0 y 100;
 *  - un valor fuera de rango no se guarda y muestra el error;
 *  - la casilla de publicación se apaga y se vuelve a encender;
 *  - sin fila en `evolucion` el porcentaje es 100 % y la agenda está publicada;
 *  - el catálogo público excluye al médico que despublicó y muestra al que tiene `null`;
 *  - la secretaría no entra y el médico sí.
 *
 * `DatabaseTransactions` como el resto de la web clínica (ver MedicAPI/AGENTS.md).
 */
class CitaOnlineConfiguracionWebTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/clinica/configuracion/cita-online';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EspecialidadesYModulosSeeder::class);
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'cita-online-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'cita-online-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
            'lastname' => 'Cita Online',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

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

    private function sedeConHorario(Medico $medico): Office
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede de la cita online ' . substr(uniqid(), -6),
            'address' => 'Dirección de prueba',
        ]);

        $sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $medico->id,
            'reg_medico' => $medico->reg_medico,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_ORDEN,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        OfficeSchedule::create([
            'office_id' => $sede->id,
            'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 10,
        ]);

        return $sede;
    }

    private function motivoConPrecio(Medico $medico, float $precio, string $razon = 'CONSULTA'): MotivoCita
    {
        return MotivoCita::create([
            'reg_medico' => $medico->reg_medico,
            'codigo' => '01',
            'tipo_atencion' => $razon,
            'precio' => $precio,
        ]);
    }

    /** El `value` del campo del porcentaje, para no depender del formato de los atributos de Blade. */
    private function valorDelPorcentaje(string $html): ?string
    {
        preg_match('/<input[^>]*id="porcentaje"[^>]*>/s', $html, $coincidencia);

        if ($coincidencia === []) {
            return null;
        }

        preg_match('/value="([^"]*)"/', $coincidencia[0], $valor);

        return $valor[1] ?? null;
    }

    private function casillaMarcada(string $html): bool
    {
        preg_match('/<input[^>]*id="publicar_agenda"[^>]*>/s', $html, $coincidencia);

        return $coincidencia !== [] && str_contains($coincidencia[0], 'checked');
    }

    // ---------------------------------------------------------------------------------------- Casos

    public function test_guarda_el_porcentaje_en_puntos_base_incluidos_los_extremos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        foreach (['30' => 3000, '0' => 0, '100' => 10000] as $valor => $esperado) {
            $this->conContexto($user, $medico, $gineco)
                ->post(self::URL, ['porcentaje' => $valor, 'publicar_agenda' => '1'])
                ->assertRedirect(route('clinica.configuracion.cita-online'))
                ->assertSessionHas('estado');

            $this->assertSame(
                $esperado,
                (int) Evolucion::where('reg_medico', $medico->reg_medico)->value('porcentaje_cita_online_bp'),
                "El porcentaje {$valor} tiene que guardarse en puntos base.",
            );
        }
    }

    public function test_un_porcentaje_fuera_de_rango_no_se_guarda_y_muestra_el_error(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)
            ->from(self::URL)
            ->post(self::URL, ['porcentaje' => '101', 'publicar_agenda' => '1'])
            ->assertSessionHasErrors(['porcentaje' => 'El porcentaje no puede ser mayor que 100.']);

        $this->assertNull(Evolucion::where('reg_medico', $medico->reg_medico)->first());

        $this->conContexto($user, $medico, $gineco)
            ->from(self::URL)
            ->post(self::URL, ['porcentaje' => '-1', 'publicar_agenda' => '1'])
            ->assertSessionHasErrors(['porcentaje' => 'El porcentaje no puede ser menor que 0.']);

        $this->assertNull(Evolucion::where('reg_medico', $medico->reg_medico)->first());
    }

    public function test_la_casilla_se_apaga_y_se_vuelve_a_encender(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)
            ->post(self::URL, ['porcentaje' => '30'])
            ->assertSessionHas('estado');

        $this->assertFalse((bool) Evolucion::where('reg_medico', $medico->reg_medico)->value('publicar_agenda'));

        $this->conContexto($user, $medico, $gineco)
            ->post(self::URL, ['porcentaje' => '30', 'publicar_agenda' => '1'])
            ->assertSessionHas('estado');

        $this->assertTrue((bool) Evolucion::where('reg_medico', $medico->reg_medico)->value('publicar_agenda'));
    }

    public function test_sin_fila_en_evolucion_el_porcentaje_es_100_y_la_agenda_esta_publicada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sedeConHorario($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->get(self::URL);

        $respuesta->assertOk();
        $respuesta->assertSee('Cita Online');
        $respuesta->assertSee('Mostrar mi agenda en el portal de reservas y en el bot de WhatsApp');

        $html = $respuesta->getContent();
        $this->assertSame('100.00', $this->valorDelPorcentaje($html));
        $this->assertTrue($this->casillaMarcada($html));

        // La regla del valor por defecto también publica al médico que nunca tocó la pantalla.
        $this->assertContains($medico->id, app(CatalogoDeMedicos::class)->medicos()->pluck('id')->all());
    }

    public function test_el_catalogo_publico_no_muestra_al_que_despublico_y_si_al_que_tiene_null(): void
    {
        [, $despublicado] = $this->medicoConAcceso();
        [, $publicado] = $this->medicoConAcceso();

        $this->sedeConHorario($despublicado);
        $this->sedeConHorario($publicado);

        Evolucion::create([
            'reg_medico' => $despublicado->reg_medico,
            'clave' => 0,
            'publicar_agenda' => false,
        ]);

        Evolucion::create([
            'reg_medico' => $publicado->reg_medico,
            'clave' => 0,
            'publicar_agenda' => null,
        ]);

        $ids = app(CatalogoDeMedicos::class)->medicos()->pluck('id')->all();

        $this->assertNotContains($despublicado->id, $ids, 'El médico que apagó la agenda no puede aparecer en el catálogo.');
        $this->assertContains($publicado->id, $ids, '`null` cuenta como agenda publicada.');
    }

    public function test_la_secretaria_no_entra_y_el_medico_si(): void
    {
        [$medicoUser, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($medicoUser, $medico, $gineco)
            ->get(self::URL)
            ->assertOk();

        $secretaria = User::create([
            'name' => 'Secretaria',
            'email' => 'cita-online-secre-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($secretaria, $medico, $gineco)
            ->get(self::URL)
            ->assertForbidden();
    }

    public function test_muestra_el_precio_de_la_cita_de_un_motivo_con_precio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->motivoConPrecio($medico, 50.0, 'CONSULTA');

        $respuesta = $this->conContexto($user, $medico, $gineco)->get(self::URL);

        $respuesta->assertOk();
        // Con el 100 % por defecto, al reservar se cobra el precio completo del motivo y **no** queda
        // nada por cobrar en el consultorio: la segunda oración no tiene que aparecer.
        $respuesta->assertSee('CONSULTA');
        $respuesta->assertSee('USD 50,00');
        $respuesta->assertSee('Al reservar se cobran');
        $respuesta->assertDontSee('restantes se cobran');

        // Con 30 % se muestra el desglose: 15 al reservar y 35 en el consultorio.
        $this->conContexto($user, $medico, $gineco)
            ->post(self::URL, ['porcentaje' => '30', 'publicar_agenda' => '1'])
            ->assertSessionHas('estado');

        $respuesta = $this->conContexto($user, $medico, $gineco)->get(self::URL);

        $respuesta->assertOk();
        $respuesta->assertSee('USD 15,00');
        $respuesta->assertSee('USD 35,00');
        $respuesta->assertSee('restantes se cobran');
    }

    public function test_configuracion_de_cita_online_aplica_los_valores_por_defecto(): void
    {
        $configuracion = ConfiguracionDeCitaOnline::de(null);

        $this->assertSame(10000, $configuracion->porcentajeBp());
        $this->assertSame('100,00%', $configuracion->porcentajeTexto());
        $this->assertTrue($configuracion->publicaAgenda());
    }
}
