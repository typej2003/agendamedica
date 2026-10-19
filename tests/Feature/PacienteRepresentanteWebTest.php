<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Database\Seeders\ParentescoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El **representante del paciente** (WEB-3.4): el adulto que responde por él —pediatría y casos
 * generales— y el que presta su teléfono para el recordatorio.
 *
 * Lo que se prueba es el criterio de la tarea:
 *
 *  - la ficha **muestra** el representante cuando existe (con el parentesco por su nombre) y ofrece
 *    cargarlo cuando no;
 *  - se **carga** y se **edita** desde la ficha, y editar **no duplica**: en el legado `representante`
 *    tiene `PRIMARY KEY (numhistoria)`, así que es una fila por historia;
 *  - el paciente **sin historia** no ofrece el formulario —no hay clave con la que guardar— y lo dice,
 *    y un POST armado a mano no escribe nada;
 *  - un paciente de **otro médico** da **404** sin confirmar que exista, en el GET y en el POST;
 *  - el `numhistoria` **no se puede dejar huérfano**: no viaja en el formulario, sale del vínculo.
 *
 * La tabla es del legado y **no se reestructura**: que el sync del escritorio la siga aceptando se prueba
 * en `CambiosEscritorioTest` y `CargaInicialTest` (esos tests se ampliaron, no se tocó el sync).
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class PacienteRepresentanteWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $this->seed(ParentescoSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'rep-web-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'rep-web-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Representante',
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

    /**
     * Un paciente con su vínculo. Sin `$numhistoria` es el alta rápida que todavía no tiene número. Por defecto
     * es un **menor** (8 años): el bloque propio del representante es de los menores de 18; para un adulto
     * pasa `$edad` (los pocos casos de autismo o discapacidad van en la cabecera).
     */
    private function paciente(Medico $medico, string $nombres, ?string $numhistoria = null, int $edad = 8): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => $nombres,
            'apellidos'   => 'De Prueba',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => now()->subYears($edad)->subMonth()->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }

    /** El representante del legado, tal como lo deja el escritorio: sólo las columnas que existían. */
    private function representante(Medico $medico, string $numhistoria, array $extra = []): int
    {
        return DB::table('representante')->insertGetId(array_merge([
            'reg_medico'      => $medico->reg_medico,
            'numhistoria'     => (int) $numhistoria,
            'nombre'          => 'ROSA DE PRUEBA',
            'codeparentesco'  => 'M',
            'direccion'       => 'CALLE 1',
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $extra));
    }

    // ------------------------------------------------------------------ La ficha (lo que se ve)

    public function test_la_ficha_muestra_el_representante_con_su_parentesco(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', '1001');
        $this->representante($medico, '1001', [
            'nombre'   => 'ROSA PEREZ',
            'cedula'   => '87654321',
            'telefono' => '04149876543',
            'email'    => 'rosa@example.com',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Representante');
        $respuesta->assertSee('ROSA PEREZ');
        // El parentesco se muestra por su nombre, no por el código.
        $respuesta->assertSee('Madre');
        $respuesta->assertDontSee('Sin representante cargado');
        $respuesta->assertSee('87654321');
        $respuesta->assertSee('04149876543');
        $respuesta->assertSee('rosa@example.com');
        // Y ofrece editarlo.
        $respuesta->assertSee('Editar el representante');
    }

    public function test_la_ficha_sin_representante_ofrece_cargarlo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Beto', '1002');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin representante cargado');
        $respuesta->assertSee('presta su teléfono para el recordatorio');
        $respuesta->assertSee('Cargar el representante');
        // El formulario está y apunta al POST de ese paciente.
        $respuesta->assertSee('action="' . route('clinica.pacientes.representante', $paciente) . '"', false);
    }

    public function test_un_adulto_no_tiene_bloque_propio_y_ofrece_agregar_el_representante_en_la_cabecera(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Adulta', '1003', 31);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente));

        $respuesta->assertOk();
        $respuesta->assertDontSee('Sin representante cargado');
        $respuesta->assertSee('Agregar representante');
        // El formulario sigue disponible (el botón de la cabecera lo abre).
        $respuesta->assertSee('action="' . route('clinica.pacientes.representante', $paciente) . '"', false);
    }

    public function test_un_menor_conserva_su_bloque_de_representante(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Menor', '1004', 17);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente))
            ->assertOk()
            ->assertSee('Sin representante cargado')
            ->assertDontSee('Agregar representante');
    }

    public function test_un_adulto_sin_historia_no_ofrece_nada_del_representante(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Adulta sin historia', null, 40);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente))
            ->assertOk()
            ->assertDontSee('Agregar representante')
            ->assertDontSee('Todavía no se puede cargar el representante');
    }

    /**
     * **Sin historia no hay formulario.** No es una limitación de la pantalla: `representante` se guarda
     * con `numhistoria` (su clave real en el legado), así que sin número no hay dónde guardarlo. La ficha
     * dice el motivo y el camino —asignar la historia (WEB-3.3)— en vez de ofrecer un formulario que va a
     * fallar.
     */
    public function test_la_ficha_sin_historia_no_ofrece_el_formulario_y_explica_el_motivo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Caro');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Todavía no se puede cargar el representante');
        $respuesta->assertSee('Asígnale el número y después se carga acá');
        // Ni el botón que abre el formulario ni el POST del representante: el camino es el bloque de la
        // historia, que sí está (`Cargar el representante` no sirve para comprobarlo: es también el título
        // del formulario, y el título del diálogo no se dibuja con el diálogo cerrado).
        $respuesta->assertDontSee('data-dialogo="dialogo-representante"', false);
        $respuesta->assertDontSee(route('clinica.pacientes.representante', $paciente), false);
        $respuesta->assertSee('Asignar el número de historia');
    }

    // ------------------------------------------------------------------ Cargar y editar

    public function test_cargar_el_representante_lo_deja_en_la_ficha_y_no_duplica_al_editarlo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Dora', '2001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre'         => 'MARIA GOMEZ',
                'codeparentesco' => 'M',
                'cedula'         => '12345678',
                'telefono'       => '04141112233',
                'email'          => 'maria@example.com',
                'direccion'      => 'AV. SIEMPRE VIVA',
            ])
            ->assertRedirect(route('clinica.pacientes.ver', $paciente))
            ->assertSessionHas('estado');

        $fila = DB::table('representante')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertNotNull($fila, 'el representante se tiene que haber guardado');
        $this->assertSame(2001, (int) $fila->numhistoria, 'la clave es el numhistoria del vínculo');
        $this->assertSame('MARIA GOMEZ', $fila->nombre);
        $this->assertSame('M', $fila->codeparentesco);
        $this->assertSame('04141112233', $fila->telefono);

        // La ficha lo muestra.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente))
            ->assertOk()->assertSee('MARIA GOMEZ');

        // Editar es el **mismo POST**: cambia el teléfono y no aparece una segunda fila.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre'         => 'MARIA GOMEZ DE PRUEBA',
                'codeparentesco' => 'A',
                'telefono'       => '04249998877',
            ])
            ->assertRedirect(route('clinica.pacientes.ver', $paciente));

        $this->assertSame(1, DB::table('representante')->where('reg_medico', $medico->reg_medico)->count());
        $fila = DB::table('representante')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('MARIA GOMEZ DE PRUEBA', $fila->nombre);
        $this->assertSame('A', $fila->codeparentesco);
        $this->assertSame('04249998877', $fila->telefono);
        $this->assertSame(2001, (int) $fila->numhistoria, 'editar no cambia de historia');
    }

    /** El `numhistoria` **no viaja en el formulario**: lo pone la Action desde el vínculo del paciente. */
    public function test_el_numhistoria_sale_del_vinculo_y_no_del_formulario(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Elsa', '3001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre'      => 'TUTOR DE ELSA',
                'numhistoria' => '9999',
            ])
            ->assertRedirect();

        $fila = DB::table('representante')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame(3001, (int) $fila->numhistoria);
        $this->assertSame(0, DB::table('representante')->where('numhistoria', 9999)->count());
    }

    /**
     * Un POST armado a mano a un paciente **sin historia** no escribe nada: sin número no hay clave, y la
     * pantalla ni siquiera ofrece el formulario. Vuelve a la ficha con el motivo.
     */
    public function test_un_post_a_un_paciente_sin_historia_no_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Fabi');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), ['nombre' => 'NADIE'])
            ->assertRedirect(route('clinica.pacientes.ver', $paciente))
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('representante')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------ Ajeno, validación y roles

    public function test_un_paciente_de_otro_medico_da_404_y_no_se_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->paciente($otro, 'Gina', '4001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $ajeno))->assertNotFound();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $ajeno), ['nombre' => 'INTRUSO'])
            ->assertNotFound();

        $this->assertSame(0, DB::table('representante')->where('reg_medico', $otro->reg_medico)->count());
        $this->assertSame(0, DB::table('representante')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_parentesco_de_mas_de_un_caracter_se_rechaza_y_no_se_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Hugo', '5001');

        // La columna legada es `char(1)`: dos caracteres no entran.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre'         => 'TUTOR',
                'codeparentesco' => 'MM',
            ])
            ->assertSessionHasErrors('codeparentesco');

        $this->assertSame(0, DB::table('representante')->where('reg_medico', $medico->reg_medico)->count());
    }

    /**
     * Guardar el diálogo **sin escribir nada** creaba un "Representante sin nombre" con todo en blanco y
     * avisaba "Se guardó" (revisión de la web, 2026-10-11). No es un representante: es una fila vacía que
     * además hace desaparecer el "Cargar el representante" de la ficha.
     */
    public function test_un_formulario_vacio_no_crea_un_representante_en_blanco(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Julia', '7001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from(route('clinica.pacientes.ver', $paciente))
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre' => '', 'codeparentesco' => '', 'cedula' => '', 'telefono' => '', 'email' => '', 'direccion' => '',
            ])
            ->assertRedirect(route('clinica.pacientes.ver', $paciente))
            ->assertSessionHasErrors(['nombre' => 'Escribe el nombre del representante o, al menos, un dato para contactarlo.'])
            ->assertSessionMissing('estado');

        $this->assertSame(0, DB::table('representante')->where('reg_medico', $medico->reg_medico)->count());

        // Y la ficha sigue ofreciendo cargarlo (no quedó una fila "sin nombre").
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente))
            ->assertOk()
            ->assertSee('Cargar el representante')
            ->assertDontSee('Representante sin nombre');
    }

    public function test_un_formulario_vacio_no_borra_el_representante_que_ya_estaba(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Karen', '7002');
        $id = $this->representante($medico, '7002', ['nombre' => 'ROSA DE KAREN']);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre' => '', 'codeparentesco' => '', 'cedula' => '', 'telefono' => '', 'email' => '', 'direccion' => '',
            ])
            ->assertSessionHasErrors('nombre');

        $this->assertSame('ROSA DE KAREN', DB::table('representante')->where('id', $id)->value('nombre'));
    }

    /**
     * Lo que **sí** vale: un representante del legado puede venir sin nombre y con un teléfono, y quien lo
     * completa no debería verse obligado a inventar el nombre. La regla es "algo que guardar", no "nombre
     * obligatorio".
     */
    public function test_con_solo_un_dato_de_contacto_si_se_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Lidia', '7003');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), ['nombre' => '', 'telefono' => '04141234567'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('estado');

        $this->assertSame('04141234567', DB::table('representante')->where('reg_medico', $medico->reg_medico)->value('telefono'));
    }

    public function test_un_dato_invalido_no_borra_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Iris', '6001');

        // El correo mal escrito y una cédula con letras: los dos errores, y lo demás se conserva.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from(route('clinica.pacientes.ver', $paciente))
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre'         => 'TUTORA DE IRIS',
                'codeparentesco' => 'M',
                'cedula'         => 'ABC',
                'email'          => 'no-es-un-correo',
            ])
            ->assertRedirect(route('clinica.pacientes.ver', $paciente))
            ->assertSessionHasErrors(['cedula', 'email']);

        $this->assertSame(0, DB::table('representante')->where('reg_medico', $medico->reg_medico)->count());

        // Y el formulario vuelve con lo escrito y abierto para que el error se lea, con el mensaje
        // **debajo de su control** (guía de UI §3): el `id` del campo lleva prefijo (`representante-…`)
        // para no chocar con el diálogo del cambio de cédula, y su clave de error es el `name`.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente));

        $respuesta->assertSee('TUTORA DE IRIS');
        $respuesta->assertSee('data-abrir-al-cargar="1"', false);
        $respuesta->assertSee('La cédula solo lleva números (hasta 10 dígitos).');
        $respuesta->assertSee('Revisa el correo: no tiene forma de correo.');
    }

    /**
     * Un código que no está en el catálogo de AppDDR (una fila que subió el escritorio con su propio
     * código) **no se traduce y no se pierde**: se muestra tal cual —el código crudo, sin inventarle un
     * nombre— y se vuelve a guardar como vino. El catálogo es nuevo y los códigos del legado son
     * desconocidos: decir "Madre" por un `codeparentesco` heredado sería afirmar una equivalencia que
     * nadie verificó, y rechazarlo obligaría a borrar un dato del escritorio para poder guardar otro.
     */
    public function test_un_codigo_de_parentesco_del_escritorio_se_muestra_tal_cual_y_se_conserva(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Juan', '7001');
        $this->representante($medico, '7001', ['nombre' => 'PADRE DE JUAN', 'codeparentesco' => 'X']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('PADRE DE JUAN');
        // El código crudo tal cual, ofrecido en el `<select>` para no perderlo al guardar (que el nombre
        // del catálogo **no** se le adjudique se prueba en el unitario del ViewModel: en la página el
        // `<select>` lista todos los parentescos).
        $respuesta->assertSee('X (código del escritorio)');

        // Guardar otro dato sin tocar el parentesco lo conserva.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre'         => 'PADRE DE JUAN',
                'codeparentesco' => 'X',
                'telefono'       => '04140001122',
            ])
            ->assertRedirect(route('clinica.pacientes.ver', $paciente))
            ->assertSessionHasNoErrors();

        $fila = DB::table('representante')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('X', $fila->codeparentesco);
        $this->assertSame('04140001122', $fila->telefono);

        // Y dejar el `select` en "Sin dato" guarda `null`, que es "no se dijo", no un código inventado.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre'         => 'PADRE DE JUAN',
                'codeparentesco' => '',
            ])
            ->assertRedirect();

        $this->assertNull(DB::table('representante')->where('reg_medico', $medico->reg_medico)->value('codeparentesco'));
    }

    public function test_la_secretaria_tambien_puede_cargar_el_representante(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso('Secretaria');
        $paciente = $this->paciente($medico, 'Karla', '8001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.representante', $paciente), [
                'nombre'         => 'TUTORA DE KARLA',
                'codeparentesco' => 'L',
            ])
            ->assertRedirect(route('clinica.pacientes.ver', $paciente));

        $this->assertSame(1, DB::table('representante')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_invitado_no_puede_cargar_el_representante(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Lola', '9001');

        $this->post(route('clinica.pacientes.representante', $paciente), ['nombre' => 'NADIE'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('representante')->where('reg_medico', $medico->reg_medico)->count());
    }
}
