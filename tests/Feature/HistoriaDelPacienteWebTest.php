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
 * La **historia del paciente** (WEB-3.5): sus consultas con sus secciones, sus antecedentes y sus
 * documentos, y la impresión del resumen.
 *
 * Lo que se prueba es el criterio de la tarea:
 *
 *  - la pantalla **lista lo que corresponde**: las consultas de esa historia (la más reciente primero) con
 *    sus motivos, enfermedad actual, diagnóstico y evolución; los antecedentes agrupados por tipo con su
 *    marca de alerta; y los documentos sin las plantillas vacías que el legado crea al abrir una consulta;
 *  - un paciente **sin historia** no tiene historia que mostrar: estado vacío y el camino (WEB-3.3);
 *  - un paciente de **otro médico** da **404**, en la pantalla y en la impresión, y la historia no mezcla
 *    datos de dos médicos que usen el mismo `numhistoria` (el número es único **por médico**);
 *  - la **impresión** trae el encabezado "Historia clínica" con paciente, cédula, historia y
 *    fecha, y las consultas con sus secciones.
 *
 * Es una pantalla de **sólo lectura**: estos tests no escriben nada que sobreviva al test
 * (`DatabaseTransactions`), y la pantalla no tiene ninguna acción de escritura.
 */
class HistoriaDelPacienteWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'his-web-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'his-web-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Carlos',
            'lastname'   => 'Mendoza',
            'prefix'     => 'Dr',
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

    /** Un paciente con su vínculo. Sin `$numhistoria` es el alta rápida que todavía no tiene número. */
    private function paciente(Medico $medico, string $nombres, ?string $numhistoria = '1001', array $extra = []): int
    {
        $id = DB::table('pacientes')->insertGetId(array_merge([
            'nombres'    => $nombres,
            'apellidos'  => 'De Prueba',
            'cedula'     => (string) random_int(10000000, 30000000),
            'sexo'       => 'F',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

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

    private function consulta(Medico $medico, string $numhistoria, int $nroconsulta, array $extra = []): void
    {
        DB::table('consultas')->insert(array_merge([
            'reg_medico'       => $medico->reg_medico,
            'numhistoria'      => (int) $numhistoria,
            'nroconsulta'      => $nroconsulta,
            'fecha'            => '2026-03-1' . $nroconsulta,
            'enfermedadactual' => 'Enfermedad actual de la consulta ' . $nroconsulta,
            'evolucion'        => 'Evolución de la consulta ' . $nroconsulta,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $extra));
    }

    /** Un motivo de la consulta, como lo deja el legado (con su descripción propia). */
    private function motivo(Medico $medico, string $numhistoria, int $nroconsulta, string $descripcion): void
    {
        DB::table('motivo_consulta_paciente')->insert([
            'reg_medico'   => $medico->reg_medico,
            'codemotivo'   => '000' . $nroconsulta,
            'nrohistoria'  => (int) $numhistoria,
            'nroconsulta'  => $nroconsulta,
            'descripcion'  => $descripcion,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Un diagnóstico de la consulta: el nombre sale del catálogo `diagnosticos`. */
    private function diagnostico(Medico $medico, string $numhistoria, int $nroconsulta, string $codigo, string $nombre): void
    {
        DB::table('diagnosticos')->insert([
            'reg_medico'       => $medico->reg_medico,
            'codediagnostico'  => $codigo,
            'descripcion'      => $nombre,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        DB::table('diagnostico_paciente')->insert([
            'reg_medico'       => $medico->reg_medico,
            'nrohistoria'      => (int) $numhistoria,
            'nroconsulta'      => $nroconsulta,
            'codediagnostico'  => $codigo,
            'orden'            => 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function antecedente(Medico $medico, string $numhistoria, array $extra = []): void
    {
        DB::table('antece_paciente')->insert(array_merge([
            'reg_medico'       => $medico->reg_medico,
            'numhistoria'      => (int) $numhistoria,
            'codeantecedente'  => '0001',
            'detalles'         => 'Detalle del antecedente',
            'descripcion'      => 'DIABETES',
            'tipo'             => '01',
            'tipo2'            => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $extra));
    }

    /** El catálogo del legado: el tipo agrupa y el nombre del antecedente sale de acá. */
    private function catalogoDeAntecedentes(Medico $medico): void
    {
        DB::table('tipo_antecedente')->insert([
            'reg_medico'  => $medico->reg_medico,
            'codetipo'    => '01',
            'descripcion' => 'Personales',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        DB::table('antecedentes')->insert([
            'reg_medico'       => $medico->reg_medico,
            'codantecedente'   => '0001',
            'descripcion'      => 'DIABETES MELLITUS',
            'codtipo'          => '01',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ------------------------------------------------------------------- La pantalla (lo que lista)

    public function test_la_historia_lista_las_consultas_con_sus_secciones_y_la_mas_reciente_primero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana');
        $this->consulta($medico, '1001', 1);
        $this->consulta($medico, '1001', 2);
        $this->motivo($medico, '1001', 2, 'DOLOR PELVICO');
        $this->diagnostico($medico, '1001', 2, 'A01', 'ANEXITIS');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Historia N.º 1001');
        $respuesta->assertSee('De Prueba, Ana');
        // La más reciente primero.
        $respuesta->assertSeeInOrder(['12/03/2026', 'Consulta 2', '11/03/2026', 'Consulta 1']);
        // Las secciones de cada consulta.
        $respuesta->assertSee('DOLOR PELVICO');
        $respuesta->assertSee('Enfermedad actual de la consulta 2');
        $respuesta->assertSee('ANEXITIS');
        $respuesta->assertSee('Evolución de la consulta 2');
        // Y la acción de imprimir (la única de una pantalla de sólo lectura).
        $respuesta->assertSee(route('clinica.pacientes.historia.imprimir', $paciente), false);
    }

    public function test_la_historia_agrupa_los_antecedentes_por_tipo_y_marca_la_alerta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Beto');
        $this->consulta($medico, '1001', 1);
        $this->catalogoDeAntecedentes($medico);
        // El `tipo2 = 'A'` es lo único que la fuente del legado lee como alerta.
        $this->antecedente($medico, '1001', ['tipo2' => 'A', 'detalles' => 'Insulina diaria']);
        $this->antecedente($medico, '1001', ['codeantecedente' => '0002', 'descripcion' => 'OTRO']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $paciente));

        $respuesta->assertOk();
        // El grupo es el tipo del catálogo y el nombre del antecedente sale de él.
        $respuesta->assertSee('Personales');
        $respuesta->assertSee('DIABETES MELLITUS');
        $respuesta->assertSee('Insulina diaria');
        // La alerta se ve con su texto (no sólo con color).
        $respuesta->assertSee('Alerta');
        $respuesta->assertSee('Marcado como alerta para las consultas de este paciente', false);
    }

    /**
     * Los documentos, **sin las plantillas vacías** que el legado crea al abrir cada consulta: un reposo de
     * un día sin observación, un informe con el texto `'.'` y una constancia sin nada propio no son
     * documentos de nadie (es el mismo criterio del móvil, `ReportesRepository::armar`).
     */
    public function test_la_historia_lista_los_documentos_sin_las_plantillas_vacias(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Caro');
        $this->consulta($medico, '1001', 1);

        // Constancia con observación propia (documento) y constancia vacía (no).
        DB::table('constancia_obs')->insert([
            ['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'observacion' => null, 'observacion01' => 'Se indica reposo relativo', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'observacion' => null, 'observacion01' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Reposo real (3 días) y la plantilla de un día sin observación.
        DB::table('reposo_paciente')->insert([
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'numdias' => 3, 'fdesde' => '2026-03-12', 'obser_reposo' => 'Reposo absoluto', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'numdias' => 1, 'fdesde' => '2026-03-11', 'obser_reposo' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Informe real y la plantilla con `'.'`.
        DB::table('informe')->insert([
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'descripcion' => 'Informe médico del caso', 'fe_cha' => '2026-03-13', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'descripcion' => '.', 'fe_cha' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Referencia a otro médico del catálogo.
        DB::table('doctores')->insert([
            'reg_medico' => $medico->reg_medico, 'cedula' => 9876543, 'nombres' => 'José', 'apellidos' => 'Rojas',
            'codeespecial' => '001', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('referencia')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1,
            'ceduladoctor' => 9876543, 'referencia' => 'Por sospecha de cardiopatía', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Un récipe con dos medicamentos (una fila por medicamento, como el legado).
        DB::table('vademecum')->insert([
            ['reg_medico' => $medico->reg_medico, 'codemedicina' => 'M001', 'nombrecomercial' => 'ACIDO FOLICO', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codemedicina' => 'M002', 'nombrecomercial' => 'HIERRO', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('recipes')->insert([
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codemedicina' => 'M001', 'recipe' => 1, 'orden' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codemedicina' => 'M002', 'recipe' => 1, 'orden' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Se indica reposo relativo');
        $respuesta->assertSee('3 días — Reposo absoluto');
        $respuesta->assertSee('Informe médico del caso');
        $respuesta->assertSee('Referido a José Rojas');
        $respuesta->assertSee('Por sospecha de cardiopatía');
        $respuesta->assertSee('2 medicamentos: ACIDO FOLICO, HIERRO');
        // La plantilla de un día sin observación no se lista como reposo.
        $respuesta->assertDontSee('1 día');
        // Y las dos plantillas vacías no dejan ninguna fila de documento: la única forma de que una
        // apareciera es con el resumen vacío.
        $respuesta->assertDontSee('data-etiqueta="Resumen">—</td>', false);
        $respuesta->assertDontSee('Sin documentos');
    }

    public function test_un_paciente_sin_historia_muestra_el_estado_vacio_y_el_camino(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Dora', null);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Todavía sin número de historia');
        $respuesta->assertSee('Este paciente todavía no tiene historia');
        $respuesta->assertSee('Ir a la ficha a asignarla');
        $respuesta->assertSee(route('clinica.pacientes.ver', $paciente), false);
        // No hay resumen que imprimir: el botón está, pero apagado y diciendo por qué.
        $respuesta->assertSee('Todavía no hay consultas en la historia');
        $respuesta->assertDontSee(route('clinica.pacientes.historia.imprimir', $paciente), false);
    }

    public function test_un_paciente_con_historia_pero_sin_datos_muestra_los_tres_estados_vacios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Elsa');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin consultas todavía');
        $respuesta->assertSee('Sin antecedentes cargados');
        $respuesta->assertSee('Sin documentos');
    }

    // ---------------------------------------------------------------------------------- Aislamiento

    public function test_un_paciente_de_otro_medico_da_404_en_la_pantalla_y_en_la_impresion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->paciente($otro, 'Gina');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $ajeno))->assertNotFound();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia.imprimir', $ajeno))->assertNotFound();
    }

    /**
     * El número de historia es único **por médico**, no global: dos consultorios pueden tener los dos una
     * historia `1001`. La pantalla tiene que mostrar sólo la del médico del contexto.
     */
    public function test_la_historia_no_mezcla_consultas_de_otro_medico_con_el_mismo_numero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        $mio = $this->paciente($medico, 'Hugo');
        $this->consulta($medico, '1001', 1, ['enfermedadactual' => 'Consulta de mi médico']);

        $ajeno = $this->paciente($otro, 'Iris');
        $this->consulta($otro, '1001', 1, ['enfermedadactual' => 'Consulta del otro médico']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $mio));

        $respuesta->assertOk();
        $respuesta->assertSee('Consulta de mi médico');
        $respuesta->assertDontSee('Consulta del otro médico');

        // Y la del otro médico no se puede leer desde acá.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $ajeno))->assertNotFound();
    }

    // ------------------------------------------------------------------------------------ Impresión

    public function test_la_impresion_trae_el_encabezado_y_las_consultas_con_sus_secciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Juan', '1001', ['cedula' => '12345678']);
        $this->consulta($medico, '1001', 1, ['resultadoexamencomp' => 'Abdomen blando', 'faringe' => 'Normal']);
        $this->motivo($medico, '1001', 1, 'CONTROL');
        $this->diagnostico($medico, '1001', 1, 'A01', 'ANEXITIS');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia.imprimir', $paciente));

        $respuesta->assertOk();
        // El encabezado del documento, con el membrete del médico del contexto.
        $respuesta->assertSee('Historia clínica');
        $respuesta->assertSee('Dr. Carlos Mendoza'); // el `nombreMostrar` del médico de prueba
        $respuesta->assertSee('De Prueba, Juan');
        $respuesta->assertSee('12345678');
        $respuesta->assertSee('H. 1001');
        // Las secciones de la consulta.
        $respuesta->assertSee('Motivo de consulta');
        $respuesta->assertSee('CONTROL');
        $respuesta->assertSee('Enfermedad actual');
        $respuesta->assertSee('Diagnóstico');
        $respuesta->assertSee('ANEXITIS');
        $respuesta->assertSee('Examen físico');
        $respuesta->assertSee('Resultado del examen: Abdomen blando');
        $respuesta->assertSee('Faringe: Normal');
        // La impresión es una página aparte, sin el armazón de la web, e imprime el navegador.
        $respuesta->assertSee('clinica-resumen-historia.css');
        $respuesta->assertSee('data-imprimir', false);
        $respuesta->assertDontSee('app-topbar');
    }

    public function test_la_impresion_de_un_paciente_sin_consultas_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Karla');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia.imprimir', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Historia clínica');
        $respuesta->assertSee('no tiene consultas registradas');
    }

    public function test_un_invitado_no_ve_la_historia(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Lola');

        $this->get(route('clinica.pacientes.historia', $paciente))->assertRedirect(route('login'));
        $this->get(route('clinica.pacientes.historia.imprimir', $paciente))->assertRedirect(route('login'));
    }
}
