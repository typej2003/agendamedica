<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El buscador y la ficha de paciente de la web clínica (`/clinica/pacientes`, WEB-3.1).
 *
 * Se prueba con `DatabaseTransactions`, como el resto de los tests de la web clínica: que la búsqueda
 * encuentre por apellido, por nombre completo (partido en dos columnas), por cédula y por número de
 * historia —con y sin los ceros a la izquierda del legado—; que tolere los datos sucios (cédula `S.C.`,
 * historia vacía o no numérica, `0000-00-00`, acentos); que la ficha lea de `pacientes` +
 * `medico_pacientes` + `consultas`; y que un paciente de otro médico no se liste ni dé ficha (404).
 *
 * Cada médico de prueba usa su propio `reg_medico`: los pacientes que siembra el seeder de desarrollo
 * (`gineco-00001`) no entran en el listado y no ensucian las cuentas.
 */
class PacientesWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'pac-web-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'pac-web-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Pacientes',
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

    /** Un médico de otro consultorio, para probar que sus pacientes no se ven desde el contexto. */
    private function otroMedico(string $regMedico): Medico
    {
        return Medico::create([
            'name'       => 'Dr. ajeno',
            'lastname'   => 'Otro',
            'email'      => 'ajeno-' . uniqid() . '@example.com',
            'password'   => Hash::make('secreto123'),
            'reg_medico' => $regMedico,
        ]);
    }

    /**
     * Un paciente con su vínculo con el médico. `$numhistoria` nulo = paciente sin historia (el alta
     * rápida); el número se guarda como texto, como en el legado.
     *
     * @param  array<string, mixed>  $campos  columnas de `pacientes` que se quieren forzar (sucias)
     */
    private function paciente(
        ?Medico $medico,
        string $nombres,
        string $apellidos = 'Apellido',
        ?string $cedula = '12345678',
        $numhistoria = null,
        array $campos = []
    ): int {
        $id = DB::table('pacientes')->insertGetId(array_merge([
            'nombres'     => $nombres,
            'apellidos'   => $apellidos,
            'cedula'      => $cedula,
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
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

    private function consulta(Medico $medico, $numhistoria, int $nroconsulta, string $fecha, string $enfermedad = ''): void
    {
        DB::table('consultas')->insert([
            'reg_medico'       => $medico->reg_medico,
            'numhistoria'      => (int) $numhistoria,
            'nroconsulta'      => $nroconsulta,
            'fecha'            => $fecha,
            'enfermedadactual' => $enfermedad,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // -------------------------------------------------------------------------------------- Búsqueda

    public function test_busca_por_apellido(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Reynoso', '11111111', '2001');
        $this->paciente($medico, 'Beto', 'Salazar', '22222222', '2002');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?buscar=Reynoso');

        $respuesta->assertOk();
        $respuesta->assertSee('Reynoso, Ana');
        $respuesta->assertDontSee('Salazar, Beto');
        $respuesta->assertSee('1 resultado para «Reynoso».');
    }

    public function test_busca_por_nombre_completo_aunque_este_partido_en_dos_columnas(): void
    {
        // El caso que el `like` por columna no podía resolver: "Ana" está en `nombres` y "Alvarez" en
        // `apellidos`, así que el texto completo no aparece en ninguna de las dos.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Alvarez', '11111111', '2011');
        $this->paciente($medico, 'Ana', 'Bravo', '22222222', '2012');

        foreach (['Ana Alvarez', 'Alvarez Ana', 'ana   alvarez'] as $buscar) {
            $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->get('/clinica/pacientes?buscar=' . urlencode($buscar));

            $respuesta->assertOk();
            $respuesta->assertSee('Alvarez, Ana');
            $respuesta->assertDontSee('Bravo, Ana');
        }

        // Y exige **todas** las palabras: "Ana" sola trae a las dos.
        $todas = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes?buscar=Ana');
        $todas->assertSee('Alvarez, Ana');
        $todas->assertSee('Bravo, Ana');
    }

    public function test_busca_por_cedula(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Reynoso', '19241357', '2021');
        $this->paciente($medico, 'Beto', 'Salazar', '5003431', '2022');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?buscar=19241357');

        $respuesta->assertOk();
        $respuesta->assertSee('Reynoso, Ana');
        $respuesta->assertDontSee('Salazar, Beto');
    }

    public function test_busca_por_numero_de_historia_con_y_sin_los_ceros_a_la_izquierda(): void
    {
        // El legado numera `01001` y AppDDR numera `1001`: la misma historia tiene que aparecer por las
        // dos formas (el pivote guarda el número como texto).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Reynoso', '11111111', '01001');
        $this->paciente($medico, 'Beto', 'Salazar', '22222222', '02002');

        foreach (['01001', '1001'] as $buscar) {
            $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->get('/clinica/pacientes?buscar=' . $buscar);

            $respuesta->assertOk();
            $respuesta->assertSee('Reynoso, Ana');
            $respuesta->assertDontSee('Salazar, Beto');
        }

        // El número se muestra como lo tiene el legado: con su cero.
        $respuesta->assertSee('Historia 01001');
    }

    public function test_la_busqueda_no_distingue_mayusculas_ni_acentos(): void
    {
        // En SQLite (desarrollo y tests) `LIKE` sólo pliega mayúsculas ASCII; la consulta compara sobre
        // una expresión sin acentos para que la búsqueda sea la misma que en MySQL.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Angela', 'Jáquez Menchaca', '11111111', '2031');

        foreach (['Jáquez', 'jáquez', 'JÁQUEZ', 'jaquez', 'JAQUEZ'] as $buscar) {
            $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->get('/clinica/pacientes?buscar=' . urlencode($buscar));

            $respuesta->assertOk();
            $respuesta->assertSee('Jáquez Menchaca, Angela', false);
        }
    }

    public function test_la_busqueda_no_se_escapa_del_filtro_por_medico(): void
    {
        // El `or` de la búsqueda va agrupado: un paciente de otro `reg_medico` no puede colarse por
        // coincidir con el texto buscado.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $ajeno = $this->otroMedico('pac-web-ajeno-' . uniqid());
        $this->paciente($medico, 'Ana', 'Reynoso', '11111111', '2041');
        $this->paciente($ajeno, 'Ana', 'Reynoso Ajeno', '99999999', '2042');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?buscar=Reynoso');

        $respuesta->assertOk();
        $respuesta->assertSee('Reynoso, Ana');
        $respuesta->assertDontSee('Reynoso Ajeno');
        $respuesta->assertSee('1 resultado para «Reynoso».');
    }

    // --------------------------------------------------------------------------------------- Listado

    public function test_no_lista_pacientes_de_otro_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $ajeno = $this->otroMedico('pac-web-ajeno-' . uniqid());
        $this->paciente($medico, 'Propia', 'Paciente', '11111111', '2051');
        $this->paciente($ajeno, 'Ajena', 'Persona', '22222222', '2052');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes');

        $respuesta->assertOk();
        $respuesta->assertSee('Paciente, Propia');
        $respuesta->assertDontSee('Persona, Ajena');
        $respuesta->assertSee('1 paciente.');
    }

    public function test_el_mismo_paciente_en_dos_consultorios_se_lista_con_el_numero_de_cada_uno(): void
    {
        // Un paciente es uno solo para todos los médicos (`pacientes`) y lo que cambia es el vínculo:
        // cada consultorio lo ve con su propio número de historia.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $ajeno = $this->otroMedico('pac-web-ajeno-' . uniqid());

        $paciente = $this->paciente($medico, 'Ana', 'Reynoso', '11111111', '2061');
        DB::table('medico_pacientes')->insert([
            'medico_id'   => $ajeno->id,
            'paciente_id' => $paciente,
            'numhistoria' => '9001',
            'reg_medico'  => $ajeno->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes');

        $respuesta->assertOk();
        $respuesta->assertSee('Reynoso, Ana');
        $respuesta->assertSee('Historia 2061');
        $respuesta->assertDontSee('Historia 9001');
    }

    public function test_el_listado_marca_sin_cedula_y_sin_historia_sin_romperse(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        // El legado escribe `S.C.` (sin cédula) y el alta rápida deja el vínculo sin número de historia.
        $this->paciente($medico, 'Sin', 'Documento', 'S.C.', null);
        $this->paciente($medico, 'Sin', 'Nombre', '22222222', '2071', ['nombres' => null, 'apellidos' => null]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes');

        $respuesta->assertOk();
        $respuesta->assertSee('Sin cédula');
        $respuesta->assertDontSee('C.I. S.C.');
        $respuesta->assertSee('Sin historia');
        $respuesta->assertSee('Paciente sin nombre');
    }

    public function test_el_listado_distingue_no_haber_pacientes_de_una_busqueda_sin_resultados(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $vacio = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes');
        $vacio->assertOk();
        $vacio->assertSee('Este consultorio todavía no tiene pacientes');

        $this->paciente($medico, 'Ana', 'Reynoso', '11111111', '2081');

        $sinResultados = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?buscar=Zzzz');
        $sinResultados->assertOk();
        $sinResultados->assertSee('Ningún paciente coincide con la búsqueda');
        $sinResultados->assertSee('Sin resultados para «Zzzz».');
        $sinResultados->assertSee('Ver todos los pacientes');
    }

    /**
     * `%` y `_` son comodines del `LIKE`: buscar `%` devolvía los 1033 pacientes (revisión de la web,
     * 2026-10-11) y `An_` encontraba a "Ana". Lo que el usuario escribe se busca **como texto**.
     */
    public function test_los_comodines_del_like_se_buscan_como_texto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Reynoso', '11111111', '2081');
        $this->paciente($medico, 'Beto', 'Salas', '22222222', '2082');
        $this->paciente($medico, 'Cora', '100% Real', '33333333', '2083');

        // Ningún dato tiene un `_`, ni `An_` ni `%a%` ni `1111111_` escritos: sin escape, `_` y `%` harían de
        // comodín y encontrarían a Ana, a Beto o a todos.
        foreach (['_', 'An_', '%a%', '1111111_'] as $buscar) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->get('/clinica/pacientes?buscar=' . urlencode($buscar))
                ->assertOk()
                ->assertSee('Sin resultados para', false)
                ->assertDontSee('Reynoso')
                ->assertDontSee('Salas');
        }

        // Y el `%` que **sí** está escrito en un dato se encuentra tal cual: solo a "100% Real", no a todos.
        foreach (['%', '100%'] as $buscar) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->get('/clinica/pacientes?buscar=' . urlencode($buscar))
                ->assertOk()
                ->assertSee('100% Real')
                ->assertDontSee('Reynoso')
                ->assertDontSee('Salas');
        }
    }

    /**
     * `?page=999` mostraba la tabla vacía, "Mostrando – de 1033" y un paginador sin página actual. Una
     * página que ya no existe (se borró gente, o se escribió a mano en la barra) lleva a la **última**,
     * conservando la búsqueda y el filtro.
     */
    public function test_una_pagina_fuera_de_rango_lleva_a_la_ultima_conservando_la_busqueda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        foreach (range(1, 26) as $n) {
            $this->paciente($medico, 'Paciente' . $n, 'Zeta', (string) (30000000 + $n), (string) (3000 + $n));
        }

        // 26 pacientes a 25 por página: dos páginas.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?page=999')
            ->assertRedirect(route('clinica.pacientes', ['page' => 2]));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?buscar=Zeta&historia=con&page=999')
            ->assertRedirect(route('clinica.pacientes', ['buscar' => 'Zeta', 'historia' => 'con', 'page' => 2]));

        // La última página existe y se muestra; la primera también.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?page=2')->assertOk()->assertSee('Mostrando 26');
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes')->assertOk()->assertSee('Mostrando 1');
    }

    public function test_una_busqueda_sin_resultados_no_redirige_aunque_la_pagina_sea_grande(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Reynoso', '11111111', '2081');

        // Sin ningún resultado no hay "última página" a la que ir: se muestra el vacío, sin bucle.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?buscar=Zzzz&page=5')
            ->assertOk()
            ->assertSee('Sin resultados para');
    }

    // ----------------------------------------------------------------------------------------- Ficha

    public function test_la_ficha_muestra_los_datos_y_las_consultas_de_la_historia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Reynoso', '19241357', '01001');
        $this->consulta($medico, '01001', 3, '2026-09-30', ' Control de rutina ');
        $this->consulta($medico, '01001', 2, '2026-08-01', '');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente);

        $respuesta->assertOk();
        $respuesta->assertSee('Reynoso, Ana');
        $respuesta->assertSee('Historia N.º 01001');
        $respuesta->assertSee('19241357');
        $respuesta->assertSee('04/05/1990');
        $respuesta->assertSee('30/09/2026');
        $respuesta->assertSee('Consulta 3');
        $respuesta->assertSee('Control de rutina');
        $respuesta->assertSee('Consulta 2');
    }

    public function test_la_ficha_tolera_los_datos_sucios_del_legado(): void
    {
        // Cédula `S.C.`, fecha nula y sexo vacío: la ficha tiene que abrir igual y mostrar `—` donde no
        // hay dato (guía de UI), y decir que el paciente no tiene historia todavía.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Sin', 'Documento', 'S.C.', null, [
            'fnacimiento' => '0000-00-00', 'sexo' => '', 'telefono' => '   ', 'email' => null,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente);

        $respuesta->assertOk();
        $respuesta->assertSee('Sin historia');
        $respuesta->assertSee('Todavía sin número de historia');
        $respuesta->assertSee('El paciente todavía no tiene historia');
        $respuesta->assertDontSee('S.C.');
        $respuesta->assertSee('dato-vacio', false);
    }

    public function test_la_ficha_tolera_una_historia_que_no_es_un_numero(): void
    {
        // Una historia escrita a mano no puede volverse `0` al cruzar `consultas`: eso traería las
        // consultas de otro paciente.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Reynoso', '11111111', 'S/H');
        $this->consulta($medico, 0, 1, '2026-09-30', 'No es de este paciente');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente);

        $respuesta->assertOk();
        $respuesta->assertSee('Historia N.º S/H');
        $respuesta->assertDontSee('No es de este paciente');
        $respuesta->assertSee('Sin consultas registradas');
    }

    public function test_la_ficha_de_un_paciente_de_otro_medico_o_inexistente_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $ajeno = $this->otroMedico('pac-web-ajeno-' . uniqid());
        $deOtro = $this->paciente($ajeno, 'Ajena', 'Persona', '22222222', '2091');

        $este = $this->conContexto($user, $medico, $gineco)->actingAs($user);
        $este->get('/clinica/pacientes/' . $deOtro)->assertNotFound();
        $este->get('/clinica/pacientes/99999999')->assertNotFound();
    }

    // ---------------------------------------------------------- El buscador del alta de cita (JSON)

    public function test_el_buscador_del_alta_encuentra_por_nombre_completo_y_exige_dos_letras(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Alvarez', '11111111', '01001');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson('/clinica/pacientes/buscar?q=' . urlencode('Ana Alvarez'));

        $respuesta->assertOk();
        $respuesta->assertJsonFragment(['nombre' => 'Alvarez, Ana', 'cedula' => '11111111', 'numhistoria' => 1001]);

        // Con una sola letra devuelve vacío en vez del consultorio entero.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson('/clinica/pacientes/buscar?q=A')
            ->assertOk()
            ->assertExactJson([]);
    }
}
