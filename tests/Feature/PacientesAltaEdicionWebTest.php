<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El **alta y la edición de paciente** de la web clínica (`/clinica/pacientes/nuevo` y
 * `/clinica/pacientes/{paciente}/editar`, WEB-3.2).
 *
 * Lo que se prueba es el criterio: dar de alta **numera la historia en el mismo paso** (O-04, desde
 * 2026-10-17) y enlaza al médico del contexto; una cédula que ya existe **enlaza** la ficha en vez de
 * duplicarla y **solo completa los campos vacíos** (un campo ya cargado no cambia, ni siquiera cuando lo
 * manda el mismo médico); la edición sí cambia los datos de la ficha propia; una ficha ajena o inexistente
 * da **404**; y la cédula que ya es de otro paciente se **rechaza** nombrando al otro, con la misma regla
 * del cambio de cédula (WEB-2.15).
 *
 * Se corre con `DatabaseTransactions`, como el resto de los tests de la web clínica. Cada médico de prueba
 * usa su propio `reg_medico` (los pacientes que siembra el seeder de desarrollo, `gineco-00001`, no entran
 * en las cuentas).
 */
class PacientesAltaEdicionWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'pac-alta-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'pac-alta-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Alta',
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

    /** Un médico de otro consultorio, para probar que sus pacientes no se editan desde el contexto. */
    private function otroMedico(): Medico
    {
        return Medico::create([
            'name'       => 'Dr. ajeno',
            'lastname'   => 'Otro',
            'email'      => 'ajeno-' . uniqid() . '@example.com',
            'password'   => Hash::make('secreto123'),
            'reg_medico' => 'pac-alta-ajeno-' . uniqid(),
        ]);
    }

    /**
     * Un paciente con su vínculo. `$campos` fuerza columnas (para probar los datos sucios del legado).
     *
     * @param  array<string, mixed>  $campos
     */
    private function paciente(?Medico $medico, array $campos = [], $numhistoria = null): int
    {
        $id = DB::table('pacientes')->insertGetId(array_merge([
            'nombres'    => 'Ana',
            'apellidos'  => 'Reynoso',
            'cedula'     => '12345678',
            'created_at' => now(),
            'updated_at' => now(),
        ], $campos));

        if ($medico !== null) {
            DB::table('medico_pacientes')->insert([
                'medico_id'   => $medico->id,
                'paciente_id' => $id,
                'numhistoria' => $numhistoria,
                'reg_medico'  => $medico->reg_medico,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        return $id;
    }

    /** El catálogo de seguros del consultorio, que la pantalla necesita para el `<select>`. */
    private function seguro(Medico $medico, string $codigo = '00', string $nombre = 'PERSONAL'): void
    {
        DB::table('seg_emp')->insert([
            'reg_medico' => $medico->reg_medico,
            'codesegemp' => $codigo,
            'nombre'     => $nombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Los datos del formulario de alta, con lo mínimo obligatorio. */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'nac'       => 'V',
            'cedula'    => '24680135',
            'apellidos' => 'Mendoza',
            'nombres'   => 'Luisa',
        ], $extra);
    }

    // ------------------------------------------------------------------------------------------ Alta

    public function test_el_formulario_de_alta_se_abre_con_todos_los_campos_del_legado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '01', 'SEGURO HORIZONTE');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes/nuevo');

        $respuesta->assertOk();
        $respuesta->assertSee('Nuevo paciente');
        foreach (['Cédula', 'Sin cédula (S.C.)', 'Apellidos', 'Nombres', 'Sexo', 'Fecha de nacimiento',
                  'Lugar de nacimiento', 'Estado civil', 'Dirección', 'Celular', 'E-mail', 'Profesión',
                  'Ocupación', 'Escolaridad', 'Seguro / empresa', 'Referido por', 'Fecha de ingreso'] as $etiqueta) {
            $respuesta->assertSee($etiqueta, false);
        }

        $respuesta->assertSee('SEGURO HORIZONTE');
    }

    public function test_el_alta_deja_elegido_el_seguro_personal_del_escritorio(): void
    {
        // El escritorio, al insertar una cédula nueva, deja `codesegemp = '00'` (PERSONAL,
        // `w_agregar_pacientes.srw:378`). El `<select>` tiene que venir con esa opción **elegida**: si no,
        // el navegador manda la primera de la lista alfabética y el default del escritorio se pierde
        // (pasó en la verificación en Chrome).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '00', 'PERSONAL');
        $this->seguro($medico, '01', 'SEGURO HORIZONTE');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes/nuevo');

        $respuesta->assertOk();
        $respuesta->assertSee('<option value="00" selected>PERSONAL</option>', false);
        $respuesta->assertDontSee('<option value="01" selected>', false);
    }

    public function test_el_alta_nueva_crea_la_ficha_y_le_asigna_la_historia_en_el_mismo_paso(): void
    {
        // O-04 (2026-10-17): el alta **desde la web** numera la historia. El número lo sigue dando
        // `AsignarHistoria`; lo que cambió es cuándo se pide.
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/pacientes', $this->formulario(['sexo' => 'F', 'fnacimiento' => '1990-05-04']));

        $paciente = DB::table('pacientes')->where('cedula', '24680135')->first();

        $this->assertNotNull($paciente);
        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente->id));
        $respuesta->assertSessionHas('estado', 'Paciente registrado y con historia H. 1. Ya se lo puede atender.');

        // El vínculo es con el médico del contexto y **con número**: el primero del correlativo.
        $vinculo = DB::table('medico_pacientes')->where('paciente_id', $paciente->id)->first();
        $this->assertSame($medico->id, $vinculo->medico_id);
        $this->assertSame($medico->reg_medico, $vinculo->reg_medico);
        $this->assertSame('1', $vinculo->numhistoria);

        // Y la fila de `historias` quedó creada, que es lo que hace que el paciente sea atendible.
        $historia = DB::table('historias')->where('paciente_id', $paciente->id)->first();
        $this->assertNotNull($historia);
        $this->assertSame('1', $historia->numhistoria);
        $this->assertSame($medico->id, $historia->medico_id);

        // Al volver a la ficha se ofrece atender (O-05): es el paso que sigue al alta.
        $respuesta->assertSessionHas('ofrecer_atender', $paciente->id);
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente->id)
            ->assertSee('¿Atender ahora?', false);

        // Y no se toca nada más del paciente que el formulario no traiga.
        $this->assertNull($paciente->foto_pac);
        $this->assertNull($paciente->password);
    }

    public function test_el_alta_guarda_todos_los_campos_del_formulario(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '01', 'SEGURO HORIZONTE');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post('/clinica/pacientes', $this->formulario([
            'nac'         => 'E',
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'lnacimiento' => 'Caracas',
            'codeestado'  => 'C',
            'direccion'   => 'Av. Principal, casa 4',
            'telefono'    => '04141234567',
            'email'       => 'luisa@example.com',
            'profesion'   => 'Ingeniera',
            'ocupacion'   => 'Docente',
            'escolaridad' => 'Universitaria',
            'codesegemp'  => '01',
            'dependencia' => 'Dra. Rojas',
            'fingreso'    => '2026-10-10',
        ]));

        $paciente = DB::table('pacientes')->where('cedula', '24680135')->first();

        $this->assertSame('E', $paciente->nac);
        $this->assertSame('Mendoza', $paciente->apellidos);
        $this->assertSame('Luisa', $paciente->nombres);
        $this->assertSame('F', $paciente->sexo);
        $this->assertSame('1990-05-04', substr((string) $paciente->fnacimiento, 0, 10));
        $this->assertSame('Caracas', $paciente->lnacimiento);
        $this->assertSame('C', $paciente->codeestado);
        $this->assertSame('Av. Principal, casa 4', $paciente->direccion);
        $this->assertSame('04141234567', $paciente->telefono);
        $this->assertSame('luisa@example.com', $paciente->email);
        $this->assertSame('Ingeniera', $paciente->profesion);
        $this->assertSame('Docente', $paciente->ocupacion);
        $this->assertSame('Universitaria', $paciente->escolaridad);
        $this->assertSame('01', $paciente->codesegemp);
        $this->assertSame('Dra. Rojas', $paciente->dependencia);
        $this->assertSame('2026-10-10', substr((string) $paciente->fingreso, 0, 10));
    }

    public function test_el_alta_con_una_cedula_que_ya_existe_enlaza_en_vez_de_duplicar_y_solo_llena_los_vacios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $ajeno = $this->otroMedico();

        // La ficha la creó **otro** médico, con los datos cargados (apellido, dirección y teléfono) y con su
        // propia historia (77): ese número es del otro consultorio y no se toca.
        $existente = $this->paciente($ajeno, [
            'nombres'   => 'Luisa',
            'apellidos' => 'Mendoza',
            'cedula'    => '24680135',
            'direccion' => 'La dirección que ya tenía',
            'telefono'  => '02121234567',
        ], 77);
        // La fila de `historias` del otro consultorio: su número 77 es el que no se puede tocar.
        DB::table('historias')->insert([
            'numhistoria' => '77',
            'reg_medico'  => $ajeno->reg_medico,
            'paciente_id' => $existente,
            'medico_id'   => $ajeno->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        $antes = DB::table('pacientes')->count();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post('/clinica/pacientes', $this->formulario([
            'cedula'     => '24680135',
            'apellidos'  => 'Mendoza',
            'nombres'    => 'Luisa',
            'direccion'  => 'Otra dirección',
            'telefono'   => '04149999999',
            'email'      => 'luisa@example.com',
            'ocupacion'  => 'Docente',
        ]));

        // No se duplicó la ficha: se enlazó la que ya existía.
        $this->assertSame($antes, DB::table('pacientes')->count());
        $respuesta->assertRedirect(route('clinica.pacientes.ver', $existente));

        $paciente = DB::table('pacientes')->where('id', $existente)->first();

        // Lo que estaba cargado **no cambia** (ni siquiera lo que escribió el mismo médico en el alta).
        $this->assertSame('La dirección que ya tenía', $paciente->direccion);
        $this->assertSame('02121234567', $paciente->telefono);
        $this->assertSame('Mendoza', $paciente->apellidos);

        // Lo que estaba vacío se completa.
        $this->assertSame('luisa@example.com', $paciente->email);
        $this->assertSame('Docente', $paciente->ocupacion);

        // Queda enlazado a **este** médico con **su propio** correlativo…
        $vinculo = DB::table('medico_pacientes')
            ->where('paciente_id', $existente)->where('reg_medico', $medico->reg_medico)->first();
        $this->assertNotNull($vinculo);
        $this->assertSame('1', $vinculo->numhistoria);

        $propia = DB::table('historias')
            ->where('paciente_id', $existente)->where('medico_id', $medico->id)->first();
        $this->assertNotNull($propia);
        $this->assertSame('1', $propia->numhistoria);

        // …y **no le toca** el número del otro médico: su vínculo y su historia siguen como estaban.
        $this->assertSame('77', DB::table('medico_pacientes')
            ->where('paciente_id', $existente)->where('medico_id', $ajeno->id)->value('numhistoria'));
        $this->assertSame(77, (int) DB::table('historias')
            ->where('paciente_id', $existente)->where('medico_id', $ajeno->id)->value('numhistoria'));
    }

    public function test_el_alta_de_una_cedula_que_ya_es_del_mismo_medico_tampoco_pisa_lo_cargado(): void
    {
        // El criterio dice "al completar una ficha ajena": acá se fija el caso más incómodo —la ficha **es
        // del mismo médico**— para que "solo llena los vacíos" valga siempre y no dependa de quién mira.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $existente = $this->paciente($medico, [
            'nombres'   => 'Luisa',
            'apellidos' => 'Mendoza',
            'cedula'    => '24680135',
            'telefono'  => '02121234567',
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post('/clinica/pacientes', $this->formulario([
            'cedula'   => '24680135',
            'telefono' => '04149999999',
        ]));

        $this->assertSame('02121234567', DB::table('pacientes')->where('id', $existente)->value('telefono'));
    }

    public function test_un_paciente_sin_cedula_es_una_ficha_nueva_y_la_cedula_queda_nula(): void
    {
        // `S.C.` es la opción "sin cédula" del alta (wiki `04-historia-clinica.md`): dos pacientes sin
        // documento **no** comparten ficha, y la base guarda el vacío como `null` (como el legado).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sinDocumento = $this->paciente($medico, ['cedula' => null, 'nombres' => 'Pedro', 'apellidos' => 'Gil']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post('/clinica/pacientes', [
            'sin_cedula' => '1',
            'cedula'     => '',
            'apellidos'  => 'Sosa',
            'nombres'    => 'Marta',
        ]);

        $nuevo = DB::table('pacientes')->where('apellidos', 'Sosa')->first();

        $this->assertNotNull($nuevo);
        $this->assertNull($nuevo->cedula);
        $this->assertNotSame($sinDocumento, $nuevo->id, 'Dos pacientes sin cédula no pueden compartir la ficha.');
        $respuesta->assertRedirect(route('clinica.pacientes.ver', $nuevo->id));
    }

    public function test_el_alta_sin_cedula_y_sin_el_tilde_lo_pide_y_no_borra_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post('/clinica/pacientes', [
            'apellidos' => 'Sosa',
            'nombres'   => 'Marta',
        ]);

        $respuesta->assertSessionHasErrors('cedula');
        $this->assertSame(0, DB::table('pacientes')->where('apellidos', 'Sosa')->count());

        // Vuelve al formulario con lo escrito (nunca borrar lo escrito) y con el error debajo del campo.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/nuevo')
            ->assertSee('Escribe la cédula o marca');
        $this->assertSame('Sosa', old('apellidos', 'Sosa'));
    }

    public function test_el_nombre_y_el_apellido_son_obligatorios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $sinNombre = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/pacientes', ['cedula' => '24680135', 'apellidos' => 'Mendoza']);
        $sinNombre->assertSessionHasErrors('nombres');

        $sinApellido = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/pacientes', ['cedula' => '24680135', 'nombres' => 'Luisa']);
        $sinApellido->assertSessionHasErrors('apellidos');

        $this->assertSame(0, DB::table('pacientes')->where('cedula', '24680135')->count());
    }

    public function test_una_cedula_con_letras_o_larga_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        foreach (['12345678901', 'V1234567', '12 34'] as $invalida) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->post('/clinica/pacientes', $this->formulario(['cedula' => $invalida]))
                ->assertSessionHasErrors('cedula');
        }

        $this->assertSame(0, DB::table('pacientes')->where('apellidos', 'Mendoza')->count());
    }

    /**
     * **El alta del chatbot y la del móvil siguen sin número** (O-04): las dos crean por `CrearPaciente`
     * sin consultorio de contexto, así que no hay quién reciba al paciente. El número se pide **sólo**
     * cuando hay consultorio de contexto (la web); si se hubiera metido dentro de `CrearPaciente`, el bot
     * empezaría a numerar pacientes que nadie recibió.
     */
    public function test_el_alta_del_chatbot_y_la_del_movil_siguen_sin_historia(): void
    {
        $medico = $this->otroMedico();

        $paciente = app(\App\Actions\Pacientes\CrearPaciente::class)->ejecutar([
            'cedula'    => '24680135',
            'nombres'   => 'Online',
            'apellidos' => 'Paciente',
        ], $medico, $medico->reg_medico);

        $vinculo = DB::table('medico_pacientes')->where('paciente_id', $paciente->id)->first();
        $this->assertNotNull($vinculo, 'el paciente online queda enlazado al médico');
        $this->assertNull($vinculo->numhistoria);
        $this->assertSame(0, DB::table('historias')->where('paciente_id', $paciente->id)->count());
    }

    /**
     * El alta ya **no** pregunta «¿Atenderá a este paciente hoy?» (O-04/O-05): numera la historia y, al
     * volver a la ficha, la pregunta es «¿Atender ahora?», que es donde se abre la consulta o se crea la
     * cita del día. La pregunta vieja llevaba al formulario de la cita y ya no tiene lugar.
     */
    public function test_el_formulario_de_alta_ya_no_pregunta_si_atiende_hoy(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes/nuevo')
            ->assertOk()
            ->assertDontSee('¿Atenderá a este paciente hoy?', false)
            ->assertDontSee('name="atender_hoy"', false);
    }

    // --------------------------------------------------------------------------------------- Edición

    public function test_la_edicion_cambia_los_datos_de_la_ficha_propia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['telefono' => '02121234567', 'direccion' => 'Vieja'], '01001');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->put('/clinica/pacientes/' . $paciente, [
            'nac'         => 'V',
            'cedula'      => '12345678',
            'apellidos'   => 'Reynoso',
            'nombres'     => 'Ana María',
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'direccion'   => 'Nueva dirección',
            'telefono'    => '04149999999',
            'email'       => 'ana@example.com',
            'codeestado'  => 'S',
            'profesion'   => 'Médica',
            'ocupacion'   => 'Cardióloga',
        ]);

        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente));
        $respuesta->assertSessionHas('estado', 'Se guardaron los cambios de la ficha.');

        $fila = DB::table('pacientes')->where('id', $paciente)->first();
        $this->assertSame('Ana María', $fila->nombres);
        $this->assertSame('Nueva dirección', $fila->direccion);
        $this->assertSame('04149999999', $fila->telefono);
        $this->assertSame('ana@example.com', $fila->email);
        $this->assertSame('F', $fila->sexo);
        $this->assertSame('1990-05-04', substr((string) $fila->fnacimiento, 0, 10));
        $this->assertSame('Médica', $fila->profesion);
        $this->assertSame('Cardióloga', $fila->ocupacion);

        // …y la ficha los muestra: era lo que faltaba para poder comprobar la edición desde la pantalla.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes/' . $paciente)
            ->assertSee('Nueva dirección')
            ->assertSee('Médica')
            ->assertSee('Cardióloga');

        // El número de historia del vínculo no se toca: la ficha no asigna historia (eso es WEB-3.3).
        $this->assertSame('01001', DB::table('medico_pacientes')->where('paciente_id', $paciente)->value('numhistoria'));
    }

    public function test_el_formulario_de_edicion_precarga_la_ficha_y_ofrece_volver_a_ella(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '01', 'SEGURO HORIZONTE');
        $paciente = $this->paciente($medico, ['telefono' => '02121234567', 'codesegemp' => '01'], '01001');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente . '/editar');

        $respuesta->assertOk();
        $respuesta->assertSee('Editar la ficha');
        $respuesta->assertSee('Reynoso, Ana');
        $respuesta->assertSee('value="12345678"', false);
        $respuesta->assertSee('value="02121234567"', false);
        $respuesta->assertSee('SEGURO HORIZONTE');
        $respuesta->assertSee(route('clinica.pacientes.ver', $paciente), false);
        // En la edición no se vuelve a preguntar si atiende hoy (eso es del alta).
        $respuesta->assertDontSee('¿Atenderá a este paciente hoy?', false);
    }

    // --------------------------------------------------------------- Validaciones (revisión 2026-10-11)

    /**
     * Una fecha de nacimiento de 2099 se guardaba con "Se guardaron los cambios" y **borraba la que
     * tenía** (la ficha quedaba sin fecha ni edad). Una fecha futura no es un dato posible.
     */
    public function test_una_fecha_de_nacimiento_futura_se_rechaza_y_no_pisa_la_que_estaba(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['fnacimiento' => '1980-07-02 00:00:00'], '01001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->put('/clinica/pacientes/' . $paciente, $this->formulario(['cedula' => '12345678', 'fnacimiento' => '2099-01-01']))
            ->assertSessionHasErrors(['fnacimiento' => 'La fecha de nacimiento no puede ser posterior a hoy.'])
            ->assertSessionMissing('estado');

        $this->assertStringStartsWith('1980-07-02', (string) DB::table('pacientes')->where('id', $paciente)->value('fnacimiento'));
    }

    public function test_hoy_y_el_pasado_son_fechas_de_nacimiento_validas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, [], '01001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->put('/clinica/pacientes/' . $paciente, $this->formulario(['cedula' => '12345678', 'fnacimiento' => now()->toDateString()]))
            ->assertSessionHasNoErrors();

        $this->assertStringStartsWith(now()->toDateString(), (string) DB::table('pacientes')->where('id', $paciente)->value('fnacimiento'));
    }

    /** El teléfono que no tiene ni un dígito ("hola") se guardaba tal cual. */
    public function test_un_telefono_sin_digitos_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['telefono' => '02121234567'], '01001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->put('/clinica/pacientes/' . $paciente, $this->formulario(['cedula' => '12345678', 'telefono' => 'hola']))
            ->assertSessionHasErrors(['telefono' => 'El teléfono solo lleva números, espacios y los signos + - ( ) .'])
            ->assertSessionMissing('estado');

        $this->assertSame('02121234567', DB::table('pacientes')->where('id', $paciente)->value('telefono'));
    }

    public function test_los_telefonos_con_formato_habitual_se_aceptan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, [], '01001');

        foreach (['04141234567', '0414-123.45.67', '+58 (412) 123 4567', '(0212) 555-0101'] as $telefono) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->put('/clinica/pacientes/' . $paciente, $this->formulario(['cedula' => '12345678', 'telefono' => $telefono]))
                ->assertSessionHasNoErrors();

            $this->assertSame($telefono, DB::table('pacientes')->where('id', $paciente)->value('telefono'));
        }
    }

    /**
     * El legado trae teléfonos escritos de cualquier manera ("llamar a la tía"). Editar **otro dato** de
     * ese paciente no puede quedar bloqueado por un teléfono que no se tocó: la regla mira solo lo que
     * cambia (la misma idea que con la cédula "S.C.").
     */
    public function test_un_telefono_sucio_del_legado_que_no_se_toco_no_bloquea_la_edicion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['telefono' => 'llamar a la tia'], '01001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->put('/clinica/pacientes/' . $paciente, $this->formulario([
                'cedula' => '12345678', 'telefono' => 'llamar a la tia', 'profesion' => 'Docente',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('estado');

        $this->assertSame('Docente', DB::table('pacientes')->where('id', $paciente)->value('profesion'));
    }

    /**
     * Más largo que la columna: el **servidor** lo rechaza (el navegador ya corta con `maxlength`, pero un
     * POST a mano o una app vieja no pasa por ahí) y lo dice en español, sin guardar nada.
     */
    public function test_un_texto_mas_largo_que_la_columna_se_rechaza_en_espanol_y_no_se_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['apellidos' => 'Reynoso'], '01001');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->put('/clinica/pacientes/' . $paciente, $this->formulario(['cedula' => '12345678', 'apellidos' => str_repeat('X', 26)]))
            ->assertSessionHasErrors(['apellidos' => 'El apellido no puede pasar de 25 caracteres.']);

        $this->assertSame('Reynoso', DB::table('pacientes')->where('id', $paciente)->value('apellidos'));
    }

    /**
     * Cada regla de la ficha necesita su aviso en español (el `locale` de la app es `en`; ver la prueba
     * gemela de la cita). Se manda todo mal a la vez y se revisa que ningún aviso salga en inglés ni
     * nombre un campo interno.
     */
    public function test_todos_los_errores_de_la_ficha_estan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/pacientes', [
                'nac'         => 'X',
                'sin_cedula'  => 'quizas',
                'cedula'      => 'abc',
                'apellidos'   => str_repeat('A', 26),
                'nombres'     => str_repeat('N', 26),
                'sexo'        => 'Z',
                'fnacimiento' => 'ayer',
                'lnacimiento' => str_repeat('L', 101),
                'codeestado'  => 'Z',
                'direccion'   => str_repeat('D', 201),
                'telefono'    => str_repeat('9', 21),
                'email'       => 'no-es-un-correo',
                'profesion'   => str_repeat('P', 51),
                'ocupacion'   => str_repeat('O', 101),
                'escolaridad' => str_repeat('E', 101),
                'codesegemp'  => 'ABCD',
                'dependencia' => str_repeat('R', 31),
                'fingreso'    => 'ayer',
            ]);

        $errores = session('errors')->getBag('default')->toArray();

        foreach (['nac', 'sin_cedula', 'cedula', 'apellidos', 'nombres', 'sexo', 'fnacimiento', 'lnacimiento', 'codeestado',
                  'direccion', 'telefono', 'email', 'profesion', 'ocupacion', 'escolaridad', 'codesegemp', 'dependencia',
                  'fingreso'] as $campo) {
            $this->assertArrayHasKey($campo, $errores, "«{$campo}» mal escrito tiene que dar su aviso.");
        }

        foreach ($errores as $campo => $mensajes) {
            foreach ($mensajes as $mensaje) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b(The|field|must|required|format|invalid|selected|greater|less|characters)\b|_|\bid\b/i',
                    $mensaje,
                    "El aviso de «{$campo}» está en inglés o nombra un campo interno: {$mensaje}",
                );
            }
        }
    }

    public function test_editar_o_guardar_la_ficha_de_un_paciente_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $ajeno = $this->otroMedico();
        $deOtro = $this->paciente($ajeno, ['cedula' => '99887766']);

        $este = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $este->get('/clinica/pacientes/' . $deOtro . '/editar')->assertNotFound();
        $este->put('/clinica/pacientes/' . $deOtro, $this->formulario(['cedula' => '99887766']))->assertNotFound();
        $este->get('/clinica/pacientes/99999999/editar')->assertNotFound();

        // Y la ficha ajena quedó como estaba.
        $this->assertSame('Reynoso', DB::table('pacientes')->where('id', $deOtro)->value('apellidos'));
    }

    public function test_la_edicion_rechaza_una_cedula_que_ya_es_de_otro_paciente_y_lo_nombra(): void
    {
        // Es la misma regla del cambio de cédula (WEB-2.15): no puede haber dos fichas con la misma
        // identidad, porque el sync enlaza por cédula. Se rechaza en vez de mover al paciente.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['cedula' => '11111111', 'nombres' => 'Ana', 'apellidos' => 'Reynoso']);
        $this->paciente($medico, ['cedula' => '22222222', 'nombres' => 'Beto', 'apellidos' => 'Salazar']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->put('/clinica/pacientes/' . $paciente, [
            'cedula'    => '22222222',
            'apellidos' => 'Reynoso',
            'nombres'   => 'Ana',
        ]);

        $respuesta->assertSessionHasErrors('cedula');
        $this->assertStringContainsString('Salazar, Beto', session('errors')->first('cedula'));

        // La cédula del paciente no cambió y la del otro quedó igual.
        $this->assertSame('11111111', DB::table('pacientes')->where('id', $paciente)->value('cedula'));
        $this->assertSame(1, DB::table('pacientes')->where('cedula', '22222222')->count());
    }

    public function test_la_edicion_de_un_paciente_sin_cedula_no_pide_la_cedula(): void
    {
        // El caso que encontró la verificación en Chrome: un paciente sin documento (cédula nula) abre la
        // edición con el campo de cédula vacío —el navegador ni lo manda— y guardar cualquier otro dato
        // quedaba rechazado por "escribe la cédula". El formulario no puede pedir un dato que el paciente
        // no tiene para dejar corregir el resto.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['cedula' => null, 'telefono' => '02121234567']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->put('/clinica/pacientes/' . $paciente, [
            'apellidos' => 'Reynoso',
            'nombres'   => 'Ana',
            'telefono'  => '04149998877',
        ]);

        $respuesta->assertSessionHasNoErrors();
        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente));

        $fila = DB::table('pacientes')->where('id', $paciente)->first();
        $this->assertSame('04149998877', $fila->telefono);
        $this->assertNull($fila->cedula);
    }

    public function test_la_edicion_puede_dejar_al_paciente_sin_cedula(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['cedula' => '11111111']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->put('/clinica/pacientes/' . $paciente, [
            'sin_cedula' => '1',
            'cedula'     => '',
            'apellidos'  => 'Reynoso',
            'nombres'    => 'Ana',
        ]);

        $respuesta->assertSessionHasNoErrors();
        $this->assertNull(DB::table('pacientes')->where('id', $paciente)->value('cedula'));
    }

    public function test_guardar_la_edicion_deja_a_la_ficha_como_estaba_si_no_cambio_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['cedula' => '11111111', 'direccion' => 'La misma']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->put('/clinica/pacientes/' . $paciente, [
            'cedula'    => '11111111',
            'apellidos' => 'Reynoso',
            'nombres'   => 'Ana',
            'direccion' => 'La misma',
        ]);

        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente));
        $this->assertSame('La misma', DB::table('pacientes')->where('id', $paciente)->value('direccion'));
    }

    public function test_la_ficha_ofrece_editar_la_ficha_y_el_listado_ofrece_el_alta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['cedula' => '11111111']);

        $este = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $este->get('/clinica/pacientes')->assertSee(route('clinica.pacientes.nuevo'), false)->assertSee('Nuevo paciente');
        $este->get('/clinica/pacientes/' . $paciente)
            ->assertSee(route('clinica.pacientes.editar', $paciente), false)
            ->assertSee('Editar la ficha');
    }

    public function test_la_secretaria_tambien_puede_dar_de_alta_y_editar_los_datos_de_contacto(): void
    {
        // Decisión de WEB-3.2: el alta y la edición de la **filiación y el contacto** son de la secretaría
        // (es la pantalla que usaba en el escritorio, Nivel 3); lo que no ve es la historia clínica, que no
        // se toca acá. La deuda de recortar el módulo por rol está anotada en `ROADMAP.md`.
        [$user, $medico, $gineco] = $this->medicoConAcceso('Secretaria');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/pacientes', $this->formulario());

        $paciente = DB::table('pacientes')->where('cedula', '24680135')->first();
        $this->assertNotNull($paciente);
        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente->id));

        // El alta de la secretaría también numera (O-04): es el alta **desde la web** y la secretaría es
        // quien recibe al paciente. Lo que no puede es **atenderlo** (eso es clínico): por eso la ficha no
        // le ofrece el diálogo de «¿Atender ahora?».
        $this->assertSame('1', DB::table('medico_pacientes')->where('paciente_id', $paciente->id)->value('numhistoria'));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente->id)
            ->assertOk()
            ->assertDontSee('¿Atender ahora?', false);
    }

    // ------------------------------------------- Valores por defecto del alta (O-01 y O-02, 2026-10-17)

    public function test_el_alta_de_ginecologia_propone_femenino_y_el_valor_se_puede_cambiar(): void
    {
        // O-01: en ginecología el alta propone **Femenino** (la clave `paciente.sexo_por_defecto` del
        // manifiesto de la especialidad), «— Sin dato —» sigue disponible y el valor se puede cambiar.
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes/nuevo');

        $respuesta->assertOk();
        $respuesta->assertSee('<option value="F" selected>Femenino</option>', false);
        $respuesta->assertSee('<option value="">— Sin dato —</option>', false);

        // El valor se puede cambiar: lo que se manda es lo que se guarda.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/pacientes', $this->formulario(['sexo' => 'M']))
            ->assertSessionHasNoErrors();

        $this->assertSame('M', DB::table('pacientes')->where('cedula', '24680135')->value('sexo'));
    }

    public function test_el_alta_en_una_especialidad_sin_la_clave_propone_sin_dato(): void
    {
        // O-01: el default no es del producto sino de la especialidad que lo declara. Pediatría tiene
        // manifiesto pero no la clave: su alta sigue abriendo en «— Sin dato —».
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $medico->specialties()->attach($pediatria->id);

        $respuesta = $this->conContexto($user, $medico, $pediatria)->actingAs($user)->get('/clinica/pacientes/nuevo');

        $respuesta->assertOk();
        $respuesta->assertSee('<option value="">— Sin dato —</option>', false);
        $respuesta->assertDontSee('<option value="F" selected>', false);
        $respuesta->assertDontSee('<option value="M" selected>', false);
    }

    public function test_la_edicion_no_pisa_el_sexo_ni_la_fecha_de_ingreso_guardados(): void
    {
        // Los defaults son **sólo del alta**: al editar manda lo que tiene el paciente.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, ['sexo' => 'M', 'fingreso' => '2020-03-15'], '01001');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente . '/editar');

        $respuesta->assertOk();
        $respuesta->assertSee('<option value="M" selected>Masculino</option>', false);
        $respuesta->assertSee('value="2020-03-15"', false);
    }

    public function test_el_alta_propone_la_fecha_de_ingreso_del_consultorio(): void
    {
        // O-02: la fecha de ingreso del alta es **hoy**, y el hoy es el del consultorio. Las 20:30 del
        // sábado 10/10/2026 en Caracas ya son el domingo 11 en UTC (el reloj del servidor, trampa 59).
        Carbon::setTestNow(Carbon::parse('2026-10-11 00:30:00', 'UTC'));

        try {
            [$user, $medico, $gineco] = $this->medicoConAcceso();

            $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes/nuevo')
                ->assertOk()
                ->assertSee('id="fingreso"', false)
                ->assertSee('value="2026-10-10"', false)
                ->assertDontSee('value="2026-10-11"', false);
        } finally {
            Carbon::setTestNow();
        }
    }
}
