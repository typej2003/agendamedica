<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Embarazo\Vistas\PestanasDelEmbarazo;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PreNatalDesarrolloFino;
use App\Models\PrenaExamenB;
use App\Models\PreNatalObservacion;
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
 * **El control prenatal en la consulta** (WEB-6.6, ítem 6 del panel del legado): la pestaña *Prenatal*.
 *
 * Lo que fijan estos casos:
 *
 *  - **El dato es de la historia, no de la consulta**: `pre_natal_observaciones` se llavea por `historia` +
 *    `gesta_clave`, y la consulta sólo aporta su `numhistoria`.
 *  - La **EG y la FPP se calculan de la FUR** (la FPP con los 270 días de esta pantalla, no con los 283 del
 *    eco obstétrico) y **no se guardan**.
 *  - **Nueva gesta**: el formulario sin `gesta` crea la siguiente, con el número que asigna el servidor.
 *  - El rastro por columna en `sync_changes` (`source = web`) y el rechazo de una gesta **sin FUR**.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 */
class ConsultaPrenatalWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-10-13 menos 146 días (20 semanas y 6 días) es la FUR de las gestas de estos casos.
        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'prenatal-test' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'prenatal-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Prenatal',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'prenatal-s-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
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

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001): array
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $numhistoria,
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        $consulta = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria,
            'nroconsulta' => 1, 'fecha' => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    private function urlSeccion(Consulta $consulta, string $extra = ''): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EMBARAZO, 'pestana' => PestanasDelEmbarazo::PRENATAL]) . $extra;
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.embarazo.prenatal.guardar', $consulta->id);
    }

    /** @return array<string,string> */
    private function formulario(array $valores = []): array
    {
        $campos = ['gesta' => '', 'fur' => '', 'menarquia' => '', 'gestas' => '', 'parto' => '', 'tipiaje' => '', 'tipiaje_cony' => '', 'observaciones' => ''];

        return array_merge($campos, $valores);
    }

    /** El HTML del control con ese `id` (`<select … id="tipiaje" …>…</select>` o un `<input>`). */
    private function control(string $html, string $id): string
    {
        if (preg_match('/<select\b[^>]*id="' . preg_quote($id, '/') . '".*?<\/select>/s', $html, $m)) {
            return $m[0];
        }

        if (preg_match('/<(input|textarea|output)\b[^>]*id="' . preg_quote($id, '/') . '"[^>]*>/s', $html, $m)) {
            return $m[0];
        }

        return '';
    }

    /** La posición del control en el HTML: es lo que fija el orden de la pantalla. */
    private function posicion(string $html, string $id): int
    {
        $posicion = strpos($html, 'id="' . $id . '"');

        return $posicion === false ? -1 : $posicion;
    }

    /** La FUR de estos casos: 146 días antes de hoy, o sea 20 semanas y 6 días. */
    private function fur(): string
    {
        return $this->hoy->copy()->subDays(146)->toDateString();
    }

    // ------------------------------------------------------------------------------------- La sección

    public function test_la_pestana_abre_con_sus_campos_y_las_dos_cuentas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        // El panel ya no dice "Próximamente" de este ítem: pasó a 12 de 13.
        $respuesta->assertSee('Pre-factura');
        $respuesta->assertSee('Control de embarazo');

        // La pestaña y sus campos, con los rótulos del escritorio.
        $respuesta->assertSee('Control prenatal');
        $respuesta->assertSee('Prenatal');
        foreach (['FUR', 'Menarquia', 'Gestas', 'Parto', 'Tipiaje (madre)', 'Tipiaje (padre)', 'Observaciones'] as $rotulo) {
            $respuesta->assertSee($rotulo);
        }

        // Las dos cuentas: sin FUR no hay ninguna, y la pantalla lo dice.
        $respuesta->assertSee('Edad gestacional');
        $respuesta->assertSee('FPP');
        $respuesta->assertSee('Sin FUR');

        // Una historia sin gestas lo dice.
        $respuesta->assertSee('Esta historia todavía no tiene ninguna gesta');
        $respuesta->assertSee($this->urlGuardar($consulta), false);
    }

    public function test_el_item_6_del_panel_enlaza_a_la_seccion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $panel = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.consulta', $consulta->id));

        $panel->assertOk();
        $panel->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EMBARAZO]), false);
    }

    // ------------------------------------------------------------------------------------- El guardado

    public function test_guardar_crea_la_primera_gesta_y_muestra_sus_dos_cuentas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'fur' => $this->fur(),
            'menarquia' => '12 años',
            'gestas' => '2',
            'parto' => '1 cesárea',
            'tipiaje' => 'O+',
            'tipiaje_cony' => 'A+',
            'observaciones' => 'Embarazo en curso.',
        ]));

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('estado');

        $gesta = PreNatalObservacion::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->first();

        $this->assertNotNull($gesta);
        $this->assertSame(1, $gesta->gesta_clave);
        $this->assertSame($this->fur(), $gesta->fur);
        $this->assertSame('12 años', $gesta->menarquia);
        $this->assertSame(2, $gesta->gestas);
        $this->assertSame('O+', $gesta->tipiaje);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'pre_natal_observaciones', 'record_id' => $gesta->id,
            'operation' => 'created', 'source' => 'web',
        ]);

        // La pestaña muestra la edad gestacional y la FPP de esa FUR (270 días, la de esta pantalla).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('20 semanas y 6 días')
            ->assertSee('Gesta 1')
            // La ayuda de la FPP deja claro cuál de los números usa esta pantalla (281, el del DataWindow
            // vivo del control prenatal; el eco obstétrico usa 283).
            ->assertSee('280 días');
    }

    public function test_nueva_gesta_crea_la_siguiente_con_el_numero_que_asigna_el_servidor(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => '2024-01-10', 'final' => 'S',
        ]);

        // El formulario sin `gesta` (el que abre "Nueva gesta") crea la 2.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'fur' => $this->fur(), 'tipiaje' => 'B+',
        ]))->assertSessionHas('estado');

        $nueva = PreNatalObservacion::where('reg_medico', $medico->reg_medico)->where('gesta_clave', 2)->first();

        $this->assertNotNull($nueva);
        $this->assertSame('B+', $nueva->tipiaje);

        // Las dos gestas se ofrecen como enlaces, y la actual (la 2) es la que se muestra.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Gesta 1')->assertSee('Gesta 2');
    }

    public function test_editar_una_gesta_corrige_solo_lo_que_cambio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $gesta = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fur' => $this->fur(), 'menarquia' => '12 años',
        ]);

        Carbon::setTestNow($this->hoy->copy()->addMinute());

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'gesta' => '1', 'fur' => $this->fur(), 'menarquia' => '13 años',
        ]))->assertSessionHas('estado');

        $this->assertSame('13 años', $gesta->fresh()->menarquia);
        $this->assertSame(1, SyncChange::where('table_name', 'pre_natal_observaciones')->where('column_name', 'menarquia')->count());
        // La FUR no se tocó (el formulario mandó la misma): ni rastro ni cambio.
        $this->assertSame(0, SyncChange::where('table_name', 'pre_natal_observaciones')->where('column_name', 'fur')->count());
        $this->assertSame($this->fur(), $gesta->fresh()->fur);
    }

    public function test_una_gesta_sin_fur_no_se_guarda_y_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La FUR es obligatoria en el formulario: el error vuelve al campo.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'menarquia' => '12 años',
        ]))->assertSessionHasErrors('fur');

        $this->assertSame(0, PreNatalObservacion::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_gesta_se_mira_por_su_numero_en_la_url(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => '2024-01-10', 'tipiaje' => 'O+']);
        PreNatalObservacion::create(['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 2, 'fur' => $this->fur(), 'tipiaje' => 'B+']);

        // Sin `gesta` se muestra la actual (la 2)…
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()->assertSee('value="' . $this->fur() . '"', false);

        // …y con `&gesta=1`, la primera (su FUR es la de 2024).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, '&gesta=1'))
            ->assertOk()->assertSee('value="2024-01-10"', false);

        // Y `&nueva=1` abre el formulario en blanco, sin gesta que guardar todavía.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, '&nueva=1'))
            ->assertOk()->assertDontSee('value="' . $this->fur() . '"', false);
    }

    // ------------------------------------------------------------------------ O-21: orden y jerarquía

    /**
     * **El orden y el agrupamiento de la ficha son los del escritorio** (O-21, captura
     * `24-control-de-embarazo-prenatal-con-gesta.png`): primer renglón `FUR · FPP · EG · Menarquia · Gestas`,
     * segundo `Partos · Césareas · Abortos · Otros · Tipiaje (madre) · Tipiaje (padre)`, el **memo** de
     * observaciones y, al final, los tres del estado del embarazo.
     */
    public function test_el_orden_de_los_campos_es_el_del_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fur' => $this->fur(), 'multiple' => 'S', 'cantidad' => 2, 'final' => 'N',
        ]);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        $orden = [
            'fur', 'prenatal-fpp', 'prenatal-eg', 'menarquia', 'gestas',      // renglón 1
            'partos', 'cesarea', 'abortos', 'otros', 'tipiaje', 'tipiaje_cony', // renglón 2
            'observaciones',                                                 // el memo
            'multiple', 'cantidad', 'final',                                 // el estado, al final
        ];

        $anterior = -1;
        foreach ($orden as $id) {
            $posicion = $this->posicion($html, $id);
            $this->assertGreaterThan($anterior, $posicion, 'El campo «' . $id . '» no está en el orden del escritorio.');
            $anterior = $posicion;
        }

        // Los tres del estado del embarazo están **juntos y discretos**: dos casillas y un número corto, no
        // tres campos de la jerarquía de los datos clínicos (que en la web eran una fila entera).
        $this->assertStringContainsString('estado-embarazo', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*id="multiple"[^>]*>/s', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*id="final"[^>]*>/s', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="number"[^>]*id="cantidad"[^>]*>/s', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*id="multiple"[^>]*checked/s', $html);

        // El memo de observaciones es el campo grande: un `<textarea>` de seis renglones a todo el ancho.
        $this->assertMatchesRegularExpression('/<textarea[^>]*rows="6"[^>]*id="observaciones"/s', $html);
    }

    // ------------------------------------------------------------------------ O-23: una línea de ayuda

    /**
     * **Una línea de ayuda como máximo** (O-23): el párrafo de apertura y los tres pasos de antes están
     * detrás del «¿Cómo funciona?» del encabezado, que abre un diálogo (el mismo patrón del tour).
     */
    public function test_la_ayuda_es_una_linea_y_los_pasos_estan_en_el_dialogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        // El botón, al lado del título, y su diálogo.
        $this->assertStringContainsString('data-abrir-dialogo="dialogo-prenatal"', $html);
        $this->assertStringContainsString('¿Cómo funciona?', $html);
        $this->assertMatchesRegularExpression('/<dialog[^>]*id="dialogo-prenatal"/s', $html);

        // La ayuda del cuerpo es **una línea**: un solo párrafo corto antes del formulario.
        $this->assertSame(1, preg_match('/<p class="texto-tenue small mb-3">(.*?)<\/p>/s', $html, $ayuda));
        $texto = trim(strip_tags($ayuda[1]));
        $this->assertLessThanOrEqual(110, mb_strlen($texto), 'La ayuda del cuerpo ocupa más de una línea: «' . $texto . '»');

        // Los tres pasos, dentro del diálogo, que va después del formulario (o sea: ya no abre la pestaña).
        $dialogo = strpos($html, 'id="dialogo-prenatal"');
        foreach (['Crea la gesta', 'Registra sus laboratorios y controles', 'Al terminar el embarazo, marca «Final de embarazo»'] as $paso) {
            $this->assertGreaterThan($dialogo, strpos($html, $paso), 'El paso «' . $paso . '» no está en el diálogo.');
        }
        $this->assertLessThan($dialogo, strpos($html, '<form'));
    }

    // --------------------------------------------------------------------------- O-24: el tipiaje

    /** **El tipiaje es un desplegable con los ocho valores del escritorio**, en su orden (O-24). */
    public function test_el_tipiaje_es_un_desplegable_con_los_ocho_valores_del_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fur' => $this->fur(), 'tipiaje' => 'O+', 'tipiaje_cony' => 'Incógnito',
        ]);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        foreach (['tipiaje', 'tipiaje_cony'] as $id) {
            $select = $this->control($html, $id);
            $this->assertStringContainsString('<select', $select, 'El campo «' . $id . '» no es un desplegable.');

            preg_match_all('/<option value="([^"]*)"/', $select, $valores);
            // La opción vacía adelante y, después, los nueve del DataWindow **vivo** (`_2`) **en ese orden**.
            $this->assertSame(
                ['', 'A+', 'A-', 'B-', 'B+', 'AB+', 'O+', 'O-', 'Incógnito', 'AB-'],
                $valores[1],
            );

            // Se muestra elegido lo guardado.
            $esperado = $id === 'tipiaje' ? 'O+' : 'Incógnito';
            $this->assertMatchesRegularExpression('/<option value="' . preg_quote($esperado, '/') . '"\s+selected/s', $select);
        }
    }

    /**
     * **Un tipiaje viejo que no está en la lista no se pierde** (O-24): se muestra como la opción elegida y
     * sólo se reemplaza si el médico elige otra.
     */
    public function test_un_tipiaje_viejo_fuera_de_la_lista_se_conserva(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        // Lo que el escritorio pudo haber escrito como texto libre antes de tener la lista.
        $gesta = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fur' => $this->fur(), 'tipiaje' => 'A positivo', 'tipiaje_cony' => 'AB+',
        ]);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        // El valor viejo viaja como una opción más —la última— y es la elegida.
        $select = $this->control($html, 'tipiaje');
        $this->assertMatchesRegularExpression('/<option value="A positivo"\s+selected/s', $select);
        $this->assertStringContainsString('(del escritorio)', $select);
        // Y `AB+`, que tampoco está entre los ocho, igual.
        $this->assertMatchesRegularExpression('/<option value="AB\+"\s+selected/s', $this->control($html, 'tipiaje_cony'));

        // Si el médico guarda sin tocarlo, no se pierde.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'gesta' => '1', 'fur' => $this->fur(), 'tipiaje' => 'A positivo', 'tipiaje_cony' => 'AB+',
        ]))->assertSessionHas('estado');

        $this->assertSame('A positivo', $gesta->fresh()->tipiaje);
        $this->assertSame('AB+', $gesta->fresh()->tipiaje_cony);

        // Y si elige otro de la lista, se reemplaza.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'gesta' => '1', 'fur' => $this->fur(), 'tipiaje' => 'O-',
        ]))->assertSessionHas('estado');

        $this->assertSame('O-', $gesta->fresh()->tipiaje);
    }

    /**
     * **La ficha guarda lo que muestra** (O-21): los conteos del segundo renglón y los tres controles del
     * estado del embarazo. Antes el formulario los mandaba y la validación del controlador los descartaba, así
     * que una gesta del escritorio perdía sus partos al guardarse desde la web.
     */
    public function test_guardar_la_gesta_guarda_los_conteos_y_el_estado_del_embarazo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $gesta = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fur' => $this->fur(), 'partos' => 2, 'cesarea' => 1,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'gesta' => '1', 'fur' => $this->fur(), 'gestas' => '3',
            'partos' => '2', 'cesarea' => '1', 'abortos' => '1', 'otros' => '0',
            // Lo que manda el formulario: las dos casillas con la casilla oculta que las precede.
            'multiple' => 'S', 'cantidad' => '2', 'final' => 'S',
        ]))->assertSessionHas('estado');

        $fresca = $gesta->fresh();
        $this->assertSame(3, $fresca->gestas);
        $this->assertSame(2, $fresca->partos);
        $this->assertSame(1, $fresca->cesarea);
        $this->assertSame(1, $fresca->abortos);
        $this->assertSame(0, $fresca->otros);
        $this->assertSame('S', $fresca->multiple);
        $this->assertSame(2, $fresca->cantidad);
        $this->assertSame('S', $fresca->final);
    }

    // -------------------------------------------------------------------------------------- Permisos

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get($this->urlSeccion($consulta))->assertForbidden();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post($this->urlGuardar($consulta), $this->formulario(['fur' => $this->fur()]))->assertForbidden();

        $this->assertSame(0, PreNatalObservacion::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->post($this->urlGuardar($consulta), $this->formulario(['fur' => $this->fur()]))
            ->assertRedirect(route('login'));
    }

    public function test_la_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();

        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)
            ->get($this->urlSeccion($consulta))->assertNotFound();

        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)
            ->post($this->urlGuardar($consulta), $this->formulario(['fur' => $this->fur()]))->assertNotFound();
    }

    // ------------------------------------------------------ Las dos pestañas por fecha (WEB-6.6)

    /** La URL de una pestaña por fecha (`?pestana=laboratorio&gesta=1&fecha=…`). */
    private function urlFecha(Consulta $consulta, string $pestana, string $extra = ''): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EMBARAZO, 'pestana' => $pestana]) . $extra;
    }

    public function test_las_pestanas_laboratorio_y_controles_abren_con_sus_campos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Las cuatro pestañas del escritorio, en su orden.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlFecha($consulta, PestanasDelEmbarazo::LABORATORIO));
        $respuesta->assertOk()
            ->assertSee('Laboratorio')->assertSee('Controles')->assertSee('Prenatal')->assertSee('Reporte')
            ->assertSee('Laboratorio del embarazo')
            // Los rótulos del DataWindow del laboratorio.
            ->assertSee('Hb.')->assertSee('Hto')->assertSee('VDRL')->assertSee('Orina')
            // El rótulo de las dos columnas que el escritorio rotula mal (PT/PTT).
            ->assertSee('Progesterona')->assertSee('Prolactina');

        // Y los del DataWindow de los controles, con la placenta (que en la tabla es `mf`).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlFecha($consulta, PestanasDelEmbarazo::CONTROLES))
            ->assertOk()
            ->assertSee('Controles del embarazo')
            ->assertSee('Presentación')->assertSee('Placenta')->assertSee('A.U.')
            // La opción del desplegable de la presentación muestra el texto y guarda el código.
            ->assertSee('Cefálica - Cefálica');
    }

    /** El `href` del enlace que lleva ese texto (se busca hacia atrás desde el texto). */
    private function urlDelEnlace(string $html, string $texto): string
    {
        $posicion = strpos($html, $texto);

        if ($posicion === false) {
            return '';
        }

        $href = strrpos(substr($html, 0, $posicion), 'href="');

        if ($href === false) {
            return '';
        }

        $inicio = $href + strlen('href="');

        return html_entity_decode(substr($html, $inicio, strpos($html, '"', $inicio) - $inicio));
    }

    /**
     * **La fecha de un laboratorio nuevo abre con hoy** (O-13): es la fecha del estudio, no la de la consulta.
     * Una fila que ya existe manda con su fecha (lo cubre la pestaña con `&fecha=`).
     *
     * Una fecha nueva es **la pestaña sin `&fecha=`**, no una marca: la gesta es la de la pestaña (acá la 1,
     * la que tiene la fila). Antes se llegaba con `&nueva=1`, que era la marca de la **gesta** nueva, y eso
     * abría una gesta que todavía no existía.
     */
    public function test_el_laboratorio_nuevo_abre_con_la_fecha_de_hoy(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => $this->fur(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlFecha($consulta, PestanasDelEmbarazo::LABORATORIO, '&gesta=1'));

        $respuesta->assertOk();
        $this->assertMatchesRegularExpression(
            '/id="fecha"[^>]*value="' . $this->hoy->toDateString() . '"/s',
            $respuesta->getContent(),
        );
        // Y el formulario es el de la gesta en curso, no el de una gesta nueva.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="gesta"[^>]*value="1"/s',
            $respuesta->getContent(),
        );
    }

    // ------------------------------------------- Varios laboratorios en la misma gesta (Alexander, 2026-10-19)

    /**
     * **Se pueden cargar varios laboratorios en la misma gesta, uno por fecha**: la tabla se llavea por
     * `historia` + `gesta_clave` + `fecha`, así que el tope son las fechas, no las filas.
     *
     * Antes, «Agregar una fecha» enlazaba con `&nueva=1` —la marca de la **gesta** nueva de *Prenatal*—, el
     * controlador anulaba la gesta elegida y caía en `siguienteClave()`: el formulario viajaba con `gesta=2`,
     * la fila se guardaba en una gesta inexistente y desaparecía de la pestaña. El segundo guardado, además,
     * volvía a la pestaña sin `fecha`, o sea a un formulario en blanco.
     */
    public function test_se_cargan_dos_laboratorios_con_fechas_distintas_en_la_misma_gesta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => $this->fur(),
        ]);
        $url = route('clinica.consulta.embarazo.laboratorio.guardar', $consulta->id);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($url, ['gesta' => '1', 'fecha' => '2026-08-14', 'hemoglobina' => '11,5'])
            ->assertSessionHas('estado');

        $segundo = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($url, ['gesta' => '1', 'fecha' => '2026-09-20', 'hemoglobina' => '12']);

        $segundo->assertSessionHas('estado');

        // La vuelta es a la fila guardada: su gesta y su fecha.
        $destino = (string) $segundo->headers->get('Location');
        $this->assertStringContainsString('pestana=laboratorio', $destino);
        $this->assertStringContainsString('gesta=1', $destino);
        $this->assertStringContainsString('fecha=2026-09-20', $destino);

        // Las dos filas son de la **misma** gesta, y no apareció ninguna gesta nueva.
        $filas = PrenaExamenB::where('historia', 1001)->orderBy('fecha')->get();
        $this->assertCount(2, $filas);
        $this->assertSame([1, 1], $filas->pluck('gesta_clave')->map(fn ($clave) => (int) $clave)->all());
        $this->assertSame(1, PreNatalObservacion::where('historia', 1001)->count());

        // La pestaña lista las dos fechas y el estado dice cuántas son.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlFecha($consulta, PestanasDelEmbarazo::LABORATORIO, '&gesta=1'))
            ->assertOk()
            ->assertSee('14/08/2026')
            ->assertSee('20/09/2026')
            ->assertSee('2 fechas');
    }

    /**
     * **«Agregar una fecha» abre una fecha nueva de la gesta en curso**: la misma pestaña con `gesta` y sin
     * `fecha` ni `nueva`; la fecha de hoy y los campos vacíos —no los de la fila que se estaba mirando— y el
     * aviso de que se está sumando una fila.
     */
    public function test_agregar_una_fecha_abre_el_formulario_en_blanco_de_la_gesta_en_curso(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => $this->fur(),
        ]);
        PrenaExamenB::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fecha' => '2026-08-14', 'hemoglobina' => '11.5', 'orina' => 'Amarilla',
        ]);

        // Se está mirando la fila guardada: su formulario la muestra.
        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlFecha($consulta, PestanasDelEmbarazo::LABORATORIO, '&gesta=1&fecha=2026-08-14'))
            ->assertOk()->assertSee('Amarilla')->getContent();

        // El botón es un enlace a la pestaña con la gesta y sin fecha (y sin la marca de la gesta nueva).
        $href = $this->urlDelEnlace($html, 'Agregar una fecha');
        $this->assertNotSame('', $href);
        $this->assertStringContainsString('pestana=laboratorio', $href);
        $this->assertStringContainsString('gesta=1', $href);
        $this->assertStringNotContainsString('fecha=', $href);
        $this->assertStringNotContainsString('nueva=1', $href);

        // Esa URL abre la fecha nueva: la gesta en curso, la fecha de hoy y lo que se estaba mirando en blanco.
        $nuevo = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($href);
        $nuevo->assertOk();
        $otraVez = $nuevo->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="gesta"[^>]*value="1"/s', $otraVez);
        $this->assertMatchesRegularExpression('/id="fecha"[^>]*value="' . $this->hoy->toDateString() . '"/s', $otraVez);
        $this->assertStringNotContainsString('Amarilla', $otraVez);
        $this->assertMatchesRegularExpression('/id="hemoglobina"[^>]*value=""/s', $otraVez);

        // Y dice que se está **agregando** una fecha a la gesta, no que la gesta esté vacía.
        $nuevo->assertSee('Fecha nueva: completá y guardá para agregarla a la gesta.');
    }

    /**
     * **`?nueva=1` en *Laboratorio* ya no cambia la gesta** (la regresión): la marca es de la gesta nueva de
     * *Prenatal*, y en una pestaña por fecha abría la gesta siguiente —que no existe—, así que las filas de la
     * gesta en curso desaparecían de la pestaña.
     */
    public function test_el_parametro_nueva_no_cambia_la_gesta_en_laboratorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => $this->fur(),
        ]);
        PrenaExamenB::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fecha' => '2026-08-14', 'orina' => 'Amarilla',
        ]);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlFecha($consulta, PestanasDelEmbarazo::LABORATORIO, '&nueva=1'))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="gesta"[^>]*value="1"/s', $html);
        // Las filas de la gesta siguen a la vista.
        $this->assertStringContainsString('14/08/2026', $html);
        $this->assertStringContainsString('1 fecha', $html);
    }

    /**
     * Lo mismo en *Controles* —el ViewModel y el parcial son los mismos—, y el guardado vuelve a la fecha de
     * la fila, no a un formulario en blanco.
     */
    public function test_el_parametro_nueva_no_cambia_la_gesta_en_controles_y_el_guardado_vuelve_a_la_fecha(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => $this->fur(),
        ]);
        PreNatalDesarrolloFino::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fecha' => '2026-06-01', 'peso' => '68',
        ]);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlFecha($consulta, PestanasDelEmbarazo::CONTROLES, '&nueva=1'))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="gesta"[^>]*value="1"/s', $html);
        $this->assertStringContainsString('1 fecha', $html);

        // Y el guardado de otra fecha vuelve a esa fecha, con su gesta.
        $guardado = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.embarazo.controles.guardar', $consulta->id), [
                'gesta' => '1', 'fecha' => '2026-07-01', 'peso' => '70',
            ]);

        $destino = (string) $guardado->headers->get('Location');
        $this->assertStringContainsString('pestana=controles', $destino);
        $this->assertStringContainsString('gesta=1', $destino);
        $this->assertStringContainsString('fecha=2026-07-01', $destino);
        $this->assertSame(2, PreNatalDesarrolloFino::where('historia', 1001)->where('gesta_clave', 1)->count());
    }

    public function test_la_pestana_reporte_abre_con_la_ficha_de_la_gesta_y_el_boton_de_imprimir(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => $this->fur(),
            'tipiaje' => 'O+', 'observaciones' => 'En curso.',
        ]);
        PreNatalDesarrolloFino::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fecha' => '2026-06-01', 'peso' => '68', 'mf' => 'Fúndica',
        ]);

        $reporte = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlFecha($consulta, PestanasDelEmbarazo::REPORTE, '&gesta=1'));

        $reporte->assertOk()
            ->assertSee('Informe del control de embarazo')
            ->assertSee('Imprimir el informe')
            // La ficha de la gesta, con su FPP (una sola desde WEB-11.16: la hoja y la pantalla).
            ->assertSee('FUR')->assertSee('FPP')
            ->assertSee('La hoja imprime la FPP de la gesta')
            ->assertSee('Tipiaje (madre)')
            // Y lo cargado en los controles, que es lo que sale en la hoja.
            ->assertSee('Peso: 68')->assertSee('Placenta: Fúndica');

        // El enlace al motor de documentos, con la gesta.
        $reporte->assertSee('documento=control-de-embarazo', false);
    }

    public function test_guardar_el_laboratorio_y_el_control_de_una_gesta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => $this->fur(),
        ]);

        // El laboratorio: la fecha + los campos del DataWindow.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.embarazo.laboratorio.guardar', $consulta->id), [
                'gesta' => '1', 'fecha' => '2026-08-14', 'hemoglobina' => '11,5', 'vdrl' => 'No reactivo',
            ])
            ->assertSessionHas('estado');

        $lab = PrenaExamenB::where('historia', 1001)->first();
        $this->assertNotNull($lab);
        $this->assertSame(1, $lab->gesta_clave);
        $this->assertSame(11.5, (float) $lab->hemoglobina);
        // El reactivo se guarda con el código de una letra del escritorio (la columna es `char(1)` en
        // MySQL, trampa 140), aunque el formulario haya mandado el rótulo.
        $this->assertSame('N', $lab->vdrl);
        $this->assertSame(1, mb_strlen($lab->vdrl));

        // Los controles: la presentación guarda el código y la placenta el texto del desplegable.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.embarazo.controles.guardar', $consulta->id), [
                'gesta' => '1', 'fecha' => '2026-08-14', 'peso' => '68', 'presentacion' => 'CC', 'mf' => 'Fúndica',
            ])
            ->assertSessionHas('estado');

        $control = PreNatalDesarrolloFino::where('historia', 1001)->first();
        $this->assertNotNull($control);
        $this->assertSame('CC', $control->presentacion);
        $this->assertSame('Fúndica', $control->mf);

        // Y la pestaña ya ofrece la fecha cargada.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlFecha($consulta, PestanasDelEmbarazo::LABORATORIO, '&gesta=1'))
            ->assertOk()->assertSee('14/08/2026');
    }

    /**
     * **La otra cara de la trampa 140**: los números del laboratorio también tienen su ancho. Las columnas
     * son `char(5)` en el escritorio (`hemoglobina`, `glob_blanco`, `urea`…) y el `max` genérico de la
     * validación era 20, así que un valor de seis caracteres habría llegado al `INSERT` y en MySQL —modo
     * estricto— habría muerto igual que el «Negativo» del `hiv`. Se frena en el campo.
     */
    public function test_un_numero_mas_largo_que_su_columna_se_frena_en_el_campo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => $this->fur(),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from($this->urlFecha($consulta, PestanasDelEmbarazo::LABORATORIO))
            ->post(route('clinica.consulta.embarazo.laboratorio.guardar', $consulta->id), [
                'gesta' => '1', 'fecha' => '2026-08-14', 'glob_blanco' => '123456',
            ])
            ->assertSessionHasErrors('glob_blanco');

        $this->assertSame(0, PrenaExamenB::where('historia', 1001)->count());
    }

    public function test_una_fecha_sin_gesta_no_se_guarda_y_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sin `gesta`: el laboratorio y los controles son de una gesta, y no se inventa cuál. La validación
        // lo frena en el campo (la regla lo exige) y no se guarda nada.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from($this->urlFecha($consulta, PestanasDelEmbarazo::LABORATORIO))
            ->post(route('clinica.consulta.embarazo.laboratorio.guardar', $consulta->id), ['fecha' => '2026-08-14', 'gesta' => ''])
            ->assertSessionHasErrors('gesta');

        $this->assertSame(0, PrenaExamenB::where('historia', 1001)->count());
    }

    public function test_el_dialogo_de_nueva_gesta_crea_la_siguiente_y_respeta_la_regla(): void
    {
        // WEB-11.15: «Nueva gesta» sólo pregunta los bebés; con la anterior abierta avisa y no crea nada.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $primera = PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1, 'fur' => '2024-01-10',
        ]);
        $url = route('clinica.consulta.embarazo.gesta.crear', $consulta->id);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()->assertSee('¿Cuántos bebés son?')->assertSee('Crea la gesta');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($url, ['bebes' => 1])
            ->assertRedirect()->assertSessionHas('error', fn ($e) => str_contains($e, 'Final de embarazo'));
        $this->assertSame(1, PreNatalObservacion::where('reg_medico', $medico->reg_medico)->count());

        $primera->forceFill(['final' => 'S'])->save();
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($url, ['bebes' => 2])
            ->assertRedirect()->assertSessionHas('estado', fn ($e) => str_contains($e, 'Gesta 2 creada'));
        $this->assertSame('S', PreNatalObservacion::where('reg_medico', $medico->reg_medico)->where('gesta_clave', 2)->value('multiple'));
    }
}
