<?php

namespace Tests\Feature;

use App\Clinica\Guia\PasosEsenciales;
use App\Models\City;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Sedes por médico** (observación de Alexander, 2026-10-26): «no debe ver las sedes de otros doctores, cada
 * doctor tiene sus sedes».
 *
 * Lo que se fija acá: la pantalla «Sedes y consultorios» muestra y administra sólo las **del médico del
 * contexto** —la unión de las suyas (`medical_centers.medico_id`), las asignadas en `medico_medical_center` y
 * las que tienen un consultorio suyo—, el alta queda con dueño, el nombre es único **por médico** y lo de otro
 * da 404. La verificación en Chrome está en `.agent-tools/verificar-sedes-por-medico-web.js`.
 */
class SedesPorMedicoWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0: User, 1: Medico, 2: Specialty} */
    private function medico(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'sedes-med-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Médico de prueba',
            'email'                => 'sedes-med-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Sedes',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    private function ciudad(): City
    {
        return City::with('state')->firstOrFail();
    }

    private function sede(string $nombre, ?Medico $duenio = null, bool $activa = true): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => $this->ciudad()->state_id,
            'city_id'    => $this->ciudad()->id,
            'medico_id'  => $duenio?->id,
            'name'       => $nombre . ' ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => $activa,
        ]);
    }

    private function datosDeSede(string $nombre, array $extra = []): array
    {
        return array_merge([
            'name'    => $nombre,
            'address' => 'Calle 1 con carrera 2',
            'phone'   => '0212-1234567',
            'city_id' => $this->ciudad()->id,
        ], $extra);
    }

    /** El `<select>` de la sede del panel «Nuevo consultorio», tal como lo recibe el navegador. */
    private function selectDeSedes(string $html): string
    {
        preg_match('/<select name="medical_center_id".*?<\/select>/s', $html, $coincidencia);

        return $coincidencia[0] ?? '';
    }

    /** Un segundo registro para el mismo usuario: hay algo que elegir y la pantalla no resuelve sola el dueño. */
    private function segundoRegistro(User $user): Medico
    {
        $regMedico = 'centro-' . substr(uniqid(), -6);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Centro de prueba',
            'lastname'   => 'Dos registros',
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    // ----------------------------------------------------------------- (a) sólo las sedes del médico

    public function test_el_medico_a_no_ve_las_sedes_de_b_ni_en_las_tarjetas_ni_en_el_desplegable(): void
    {
        [$userA, $medicoA, $gineco] = $this->medico();
        [, $medicoB] = $this->medico();

        $suyaUno = $this->sede('Sede propia uno', $medicoA);
        $suyaDos = $this->sede('Sede propia dos', $medicoA);
        $ajena = $this->sede('Sede del otro', $medicoB);

        $html = $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->get('/clinica/sedes')->assertOk()->getContent();

        // (1) Las tarjetas: las suyas sí, la del otro no. El contador «Activas (N)» cuenta sólo las suyas.
        $this->assertStringContainsString(e($suyaUno->name), $html);
        $this->assertStringContainsString(e($suyaDos->name), $html);
        $this->assertStringNotContainsString(e($ajena->name), $html);
        $this->assertStringContainsString('Activas (2)', $html);
        // (2) El desplegable «Sede» del panel del consultorio: ninguna opción del otro.
        $opciones = $this->selectDeSedes($html);
        $this->assertStringContainsString(e($suyaUno->name), $opciones);
        $this->assertStringContainsString(e($suyaDos->name), $opciones);
        $this->assertStringNotContainsString(e($ajena->name), $opciones);
        $this->assertStringNotContainsString('value="' . $ajena->id . '"', $opciones);
    }

    // ------------------------------------------------------------ (b) el alta queda con dueño

    public function test_la_sede_creada_desde_la_web_queda_con_dueno_del_medico_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medico();
        $datos = $this->datosDeSede('Sede nueva ' . substr(uniqid(), -6));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/sedes', $datos)
            ->assertRedirect(route('clinica.sedes'))
            ->assertSessionHas('sede_creada');

        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();

        $this->assertSame($medico->id, (int) $sede->medico_id);
        $this->assertSame($medico->id, (int) $sede->medico->id);
    }

    // ------------------------------------------------- (c) el nombre es único por médico

    public function test_dos_medicos_pueden_tener_cada_uno_una_sede_con_el_mismo_nombre_y_uno_no_la_repite(): void
    {
        [$userA, $medicoA, $ginecoA] = $this->medico();
        [$userB, $medicoB, $ginecoB] = $this->medico();
        $nombre = 'CEMOC ' . substr(uniqid(), -6);

        $this->conContexto($userA, $medicoA, $ginecoA)->actingAs($userA)
            ->post('/clinica/sedes', $this->datosDeSede($nombre))->assertRedirect();
        $this->conContexto($userB, $medicoB, $ginecoB)->actingAs($userB)
            ->post('/clinica/sedes', $this->datosDeSede($nombre))->assertRedirect();

        $this->assertSame(2, MedicalCenter::where('name', $nombre)->count(), 'cada médico tiene la suya');
        $this->assertSame(1, MedicalCenter::where('name', $nombre)->where('medico_id', $medicoA->id)->count());
        $this->assertSame(1, MedicalCenter::where('name', $nombre)->where('medico_id', $medicoB->id)->count());

        // El mismo médico, otra vez el mismo nombre: no.
        $this->conContexto($userA, $medicoA, $ginecoA)->actingAs($userA)
            ->post('/clinica/sedes', $this->datosDeSede($nombre))
            ->assertSessionHasErrors('name');

        $this->assertSame(2, MedicalCenter::where('name', $nombre)->count());
        $this->assertSame(
            'Ya tienes una sede con ese nombre. Si es la misma, edítala desde la lista.',
            session('errors')->first('name'),
        );
    }

    // ---------------------------------------------- (d) editar y activar lo ajeno da 404

    public function test_editar_o_activar_la_sede_de_otro_medico_da_404(): void
    {
        [$userA, $medicoA, $gineco] = $this->medico();
        [, $medicoB] = $this->medico();
        $ajena = $this->sede('Sede intocable', $medicoB);

        $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->get('/clinica/sedes?sede=' . $ajena->id)
            ->assertNotFound();

        $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->patch('/clinica/sedes/' . $ajena->id . '/activar')
            ->assertNotFound();

        $this->assertTrue((bool) $ajena->refresh()->activo);
    }

    // --------------------------------- (e) el consultorio no se cuelga de una sede ajena

    public function test_no_se_carga_un_consultorio_en_la_sede_de_otro_medico(): void
    {
        [$userA, $medicoA, $gineco] = $this->medico();
        [, $medicoB] = $this->medico();
        $ajena = $this->sede('Sede del otro', $medicoB);

        $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->post('/clinica/consultorios', [
                'medical_center_id' => $ajena->id,
                'office_number'     => 'X-9',
                'modalidad'         => Office::MODALIDAD_HORA,
                'duracion_cita'     => 20,
            ])
            ->assertSessionHasErrors('medical_center_id');

        $this->assertSame('Esa sede no es tuya: elige una de tus sedes para el consultorio.', session('errors')->first('medical_center_id'));
        $this->assertSame(0, Office::where('medical_center_id', $ajena->id)->count());
    }

    // ---------------- (f) un médico sin sedes ve el vacío y la guía le sigue pidiendo la sede

    public function test_un_medico_sin_sedes_ve_el_estado_vacio_aunque_existan_las_de_otros(): void
    {
        [$userA, $medicoA, $gineco] = $this->medico();
        [, $medicoB] = $this->medico();
        $this->sede('Sede del otro', $medicoB);

        $html = $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString('Empieza por tu primera sede', $html);
        $this->assertStringContainsString('Activas (0)', $html);
        // La guía no se salta el paso: el catálogo de otros no cuenta como «tener sede».
        $this->assertSame('sede', app(PasosEsenciales::class)->siguiente($medicoA)->clave);
    }

    // ------------------------------------- (g) sin contexto no se ofrece ni se crea nada

    public function test_sin_contexto_no_se_ofrece_crear_ni_se_crea_una_sede(): void
    {
        [$user] = $this->medico();
        $this->segundoRegistro($user);
        $datos = $this->datosDeSede('Sede sin dueño ' . substr(uniqid(), -6));
        $antes = MedicalCenter::count();

        $html = $this->actingAs($user)->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString('Elegir consultorio aquí', $html);
        $this->assertStringNotContainsString('id="dialogo-sede"', $html);
        $this->assertStringNotContainsString('id="dialogo-consultorio"', $html);
        $this->assertStringNotContainsString('Nueva sede', $html);

        $this->actingAs($user)->post('/clinica/sedes', $datos)
            ->assertRedirect(route('clinica.sedes'))
            ->assertSessionHasErrors('name');

        $this->assertSame($antes, MedicalCenter::count());
        $this->assertNull(MedicalCenter::where('name', $datos['name'])->first());
    }

    // ------------------------------------------------------- La unión de criterios, caso por caso

    public function test_una_sede_asignada_en_la_tabla_puente_se_ve_y_se_administra(): void
    {
        [$userA, $medicoA, $gineco] = $this->medico();
        [, $medicoB] = $this->medico();
        $asignada = $this->sede('Sede asignada', $medicoB);
        $medicoA->medicalCenters()->attach($asignada->id, ['reg_medico' => $medicoA->reg_medico]);

        $html = $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString(e($asignada->name), $html);
        // Dueño o asignada: se administra (lápiz y tacho).
        $this->assertStringContainsString('aria-label="Editar la sede ' . e($asignada->name) . '"', $html);

        $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->patch('/clinica/sedes/' . $asignada->id . '/activar')
            ->assertRedirect(route('clinica.sedes'));

        $this->assertFalse((bool) $asignada->refresh()->activo);
    }

    public function test_una_sede_sin_dueno_donde_ya_tiene_consultorio_se_ve_pero_no_se_administra(): void
    {
        [$userA, $medicoA, $gineco] = $this->medico();
        $prestada = $this->sede('Sede vieja');
        $consultorio = Office::create([
            'medical_center_id' => $prestada->id,
            'medico_id'         => $medicoA->id,
            'reg_medico'        => $medicoA->reg_medico,
            'office_number'     => 'V-1',
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);

        $html = $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->get('/clinica/sedes')->assertOk()->getContent();

        // Se ve, con su consultorio y su «+ Consultorio» para seguir cargando…
        $this->assertStringContainsString(e($prestada->name), $html);
        $this->assertStringContainsString('V-1', $html);
        $this->assertStringContainsString('data-sede="' . $prestada->id . '"', $html);
        // …pero la ficha de la sede no es suya: sin lápiz ni tacho.
        $this->assertStringNotContainsString('aria-label="Editar la sede ' . e($prestada->name) . '"', $html);
        $this->assertStringNotContainsString('aria-label="Desactivar la sede ' . e($prestada->name) . '"', $html);

        $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->get('/clinica/sedes?sede=' . $prestada->id)->assertNotFound();
        $this->conContexto($userA, $medicoA, $gineco)->actingAs($userA)
            ->patch('/clinica/sedes/' . $prestada->id . '/activar')->assertNotFound();

        $this->assertTrue((bool) $prestada->refresh()->activo);
        $this->assertNotNull($consultorio->fresh());
    }
}
