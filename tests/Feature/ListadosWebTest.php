<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El listado de embarazos / partos entre fechas** (WEB-6.8) en `/clinica/listados`, contra la base: la
 * pantalla abre y lista lo que corresponde, el rango acota por **FPP** (los −283 del legado), el filtro de
 * edad acota, el modo "Por semanas" filtra por la semana exacta, el filtro fijo `final = 'N'` se aplica, todo
 * se acota al `reg_medico` del contexto y la secretaría recibe **403**.
 *
 * Es el primer listado clínico entre fechas de la web: el *Listado de Partos* del escritorio
 * (`w_reportes_consultas.srw:449` → `w_reporte_consulta_partos.srw`).
 */
class ListadosWebTest extends TestCase
{
    use DatabaseTransactions;

    /** El día del consultorio con el que se arman los datos: de él salen la fecha final y la semana. */
    private const HOY = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::HOY . ' 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'list-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'listados-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Listados', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    /**
     * Un paciente del consultorio con su gesta.
     *
     * El `numhistoria` va en el pivote (`medico_pacientes`), que es donde el esquema de AppDDR lo guarda y de
     * dónde lo lee el listado.
     */
    private function pacienteConGesta(Medico $medico, int $historia, string $apellido, array $gesta = [], array $paciente = []): void
    {
        $ahora = self::HOY . ' 00:00:00';

        $pacienteId = DB::table('pacientes')->insertGetId(array_merge([
            'nombres'     => 'Ana',
            'apellidos'   => $apellido,
            'fnacimiento' => '1992-10-14',
            'fingreso'    => '2020-03-12',
            'codesegemp'  => '02',
            'created_at'  => $ahora,
            'updated_at'  => $ahora,
        ], $paciente));

        DB::table('medico_pacientes')->insert([
            'medico_id'    => $medico->id,
            'paciente_id'  => $pacienteId,
            'numhistoria'  => (string) $historia,
            'reg_medico'   => $medico->reg_medico,
            'created_at'   => $ahora,
            'updated_at'   => $ahora,
        ]);

        DB::table('pre_natal_observaciones')->insert(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia'   => $historia,
            'fur'        => '2026-01-15',
            'final'      => 'N',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $gesta));
    }

    private function seguro(Medico $medico, string $codigo, string $nombre): void
    {
        DB::table('seg_emp')->insert([
            'reg_medico' => $medico->reg_medico, 'codesegemp' => $codigo, 'nombre' => $nombre,
            'created_at' => self::HOY . ' 00:00:00', 'updated_at' => self::HOY . ' 00:00:00',
        ]);
    }

    // ------------------------------------------------------------------------------------ La pantalla

    public function test_la_pantalla_lista_las_gestas_del_medico_con_la_fpp_en_el_rango(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '02', 'PDVSA');
        $this->pacienteConGesta($medico, 1001, 'Pérez');

        $respuesta = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk();

        // Las seis columnas del legado, en su orden.
        $respuesta->assertSee('Paciente')->assertSee('F. ingreso')->assertSee('Edad')
            ->assertSee('F.U.R.')->assertSee('F.P.P.')->assertSee('Procedencia');

        $respuesta->assertSee('Pérez, Ana');
        $respuesta->assertSee('12/03/2020');   // F. ingreso
        $respuesta->assertSee('34 años');      // Edad
        $respuesta->assertSee('15/01/2026');   // F.U.R.
        $respuesta->assertSee('22/10/2026');   // F.P.P. = FUR + 283
        $respuesta->assertSee('PDVSA');        // Procedencia, el nombre del catálogo

        // La clave del listado viaja en el formulario: la pantalla es una sola y parametrizada.
        $respuesta->assertSee('name="listado" value="embarazos"', false);
    }

    public function test_el_rango_acota_por_fpp_y_no_por_fur(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->pacienteConGesta($medico, 1001, 'Adentro', ['fur' => '2026-01-18']);          // FPP 22/10/2026
        $this->pacienteConGesta($medico, 1002, 'BordeInicial', ['fur' => '2025-12-25']);     // FPP 01/10/2026, el primero del rango
        $this->pacienteConGesta($medico, 1003, 'BordeFinal', ['fur' => '2026-01-24']);       // FPP 31/10/2026, el último
        $this->pacienteConGesta($medico, 1004, 'AntesDelRango', ['fur' => '2025-12-24']);    // FPP 30/09/2026
        // Con la FUR **dentro** del rango pero la FPP muy lejos: es lo que prueba el −280. Si el filtro se
        // aplicara a la FUR tal cual, esta gesta aparecería.
        $this->pacienteConGesta($medico, 1005, 'FurEnElRango', ['fur' => '2026-10-05']);

        $respuesta = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk();

        $respuesta->assertSee('Adentro')->assertSee('BordeInicial')->assertSee('BordeFinal');
        $respuesta->assertDontSee('AntesDelRango');
        $respuesta->assertDontSee('FurEnElRango');

        // Y el que quedó afuera entra con el rango que le corresponde: el filtro no descarta de más.
        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))
            ->assertOk()
            ->assertSee('AntesDelRango')
            ->assertDontSee('BordeInicial');
    }

    public function test_el_filtro_de_edad_acota(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->pacienteConGesta($medico, 1001, 'Joven', [], ['fnacimiento' => '1992-10-14']);       // 34 años
        $this->pacienteConGesta($medico, 1002, 'Mayor', [], ['fnacimiento' => '1976-01-01']);       // 50 años

        $rango = ['desde' => '2026-10-01', 'hasta' => '2026-10-31'];

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', $rango + ['edad_inicial' => '30', 'edad_final' => '40']))
            ->assertOk()
            ->assertSee('Joven')
            ->assertDontSee('Mayor');

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', $rango + ['edad_inicial' => '45', 'edad_final' => '55']))
            ->assertOk()
            ->assertSee('Mayor')
            ->assertDontSee('Joven');

        // La edad propia del paciente también sale en la fila (`1992-10-14` son 34 años a hoy).
        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', $rango))
            ->assertOk()
            ->assertSee('34 años')
            ->assertSee('50 años');
    }

    public function test_el_modo_por_semanas_filtra_por_la_semana_exacta_y_no_usa_las_fechas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // Hoy es 2026-10-14: la semana 25 son las FUR del 16/04/2026 al 22/04/2026 (7 días, división entera).
        $this->pacienteConGesta($medico, 1001, 'SemanaVeinticinco', ['fur' => '2026-04-16']);
        $this->pacienteConGesta($medico, 1002, 'SemanaVeintiseis', ['fur' => '2026-04-15']);
        $this->pacienteConGesta($medico, 1003, 'SemanaVeinticuatro', ['fur' => '2026-04-23']);

        // Las fechas van fuera de la FPP de todas: en el modo por semanas no se usan.
        $respuesta = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['desde' => '2026-01-01', 'hasta' => '2026-01-02', 'por_semanas' => '1', 'semana' => '25']))
            ->assertOk();

        // El título es el del DataWindow de la semana del legado.
        $respuesta->assertSee('Embarazos de 25 semanas de gestación');
        $respuesta->assertSee('SemanaVeinticinco');
        $respuesta->assertDontSee('SemanaVeintiseis');
        $respuesta->assertDontSee('SemanaVeinticuatro');
    }

    public function test_la_semana_que_no_se_entiende_se_avisa_y_no_lista(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->pacienteConGesta($medico, 1001, 'Pérez');

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['por_semanas' => '1', 'semana' => '99']))
            ->assertOk()
            ->assertSee('Escribe una semana de gestación entre 1 y 40', false)
            ->assertDontSee('Pérez, Ana');
    }

    public function test_el_filtro_fijo_final_n_se_aplica_tal_cual(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // El legado exige `pre_natal_observaciones.final = 'N'` (`d_partos.srd`). El significado de la
        // columna no está documentado: se replica el filtro y estos tres casos lo fijan.
        $this->pacienteConGesta($medico, 1001, 'FinalN', ['final' => 'N']);
        $this->pacienteConGesta($medico, 1002, 'FinalS', ['final' => 'S']);
        $this->pacienteConGesta($medico, 1003, 'FinalVacio', ['final' => null]);

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk()
            ->assertSee('FinalN')
            ->assertDontSee('FinalS')
            ->assertDontSee('FinalVacio');
    }

    public function test_solo_se_ven_las_gestas_del_medico_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$otroUser, $otro] = $this->medicoConAcceso();

        $this->pacienteConGesta($medico, 1001, 'Propio');
        // El otro médico numera **la misma historia** desde su propia instalación: el listado tiene que atar
        // el número al registro, no cruzarlo con el paciente homónimo de al lado.
        $this->pacienteConGesta($otro, 1001, 'Ajeno');

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk()
            ->assertSee('Propio')
            ->assertDontSee('Ajeno');
    }

    public function test_la_pantalla_avisa_cuando_las_fechas_vienen_al_reves(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->pacienteConGesta($medico, 1001, 'Pérez');

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['desde' => '2026-10-31', 'hasta' => '2026-10-01']))
            ->assertOk()
            ->assertSee('al revés')
            ->assertSee('Pérez, Ana');
    }

    public function test_la_procedencia_muestra_el_codigo_cuando_no_esta_en_el_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // El catálogo de seguros/empresas todavía no tiene pantalla de administración (F8): el reporte lee el
        // que ya existe y, si el código no está, muestra el código en vez de inventar un nombre.
        $this->seguro($medico, '02', 'PDVSA');
        $this->pacienteConGesta($medico, 1001, 'ConCatalogo', [], ['codesegemp' => '02']);
        $this->pacienteConGesta($medico, 1002, 'SinCatalogo', [], ['codesegemp' => 'Z9']);

        $respuesta = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk()
            ->assertSee('PDVSA')
            ->assertSee('Z9');

        $this->assertStringContainsString('ConCatalogo', $respuesta->getContent());
        $this->assertStringContainsString('SinCatalogo', $respuesta->getContent());

        // Y el aviso de dónde sale la procedencia está en la pantalla.
        $respuesta->assertSee('un código que no esté en él se muestra tal cual', false);
    }

    // -------------------------------------------------------------------------------------- La hoja

    public function test_la_hoja_imprimible_sale_con_los_mismos_filtros(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->pacienteConGesta($medico, 1001, 'Pérez');

        $respuesta = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados.imprimir', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']))
            ->assertOk()
            ->assertSee('Pérez, Ana')
            ->assertSee('22/10/2026')
            // La cabecera lleva los filtros: un listado sin su rango no dice nada en papel.
            ->assertSee('Desde')
            ->assertSee('01/10/2026')
            ->assertSee('Hasta')
            ->assertSee('31/10/2026')
            ->assertSee('1 gesta.');

        // Es una hoja aparte: sin el armazón de la web.
        $respuesta->assertDontSee('app-sidebar', false);
    }

    // ------------------------------------------------------------------------------------- Permisos

    public function test_la_secretaria_recibe_403_en_las_dos_rutas(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        [$secretaria] = $this->medicoConAcceso('Secretaria');

        // La secretaría no es médico del registro: se le arma su propio vínculo para que el contexto sea válido
        // y el 403 lo dé el rol de la ruta, no el contexto.
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.listados'))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.listados.imprimir'))->assertForbidden();
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get(route('clinica.listados'))->assertRedirect(route('login'));
        $this->get(route('clinica.listados.imprimir'))->assertRedirect(route('login'));
    }

    public function test_el_menu_ofrece_el_listado_al_medico_y_no_a_la_secretaria(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $delMedico = $this->conContexto($user, $medico, $gineco)->get(route('clinica.pacientes'))->assertOk()->getContent();
        $this->assertStringContainsString('Listados', $delMedico);
        $this->assertStringContainsString(route('clinica.listados'), $delMedico);

        [$secretaria] = $this->medicoConAcceso('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $deLaSecretaria = $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('clinica.listados'), $deLaSecretaria);
    }

    /**
     * «Listados» es un **grupo plegable** con un hijo por reporte (revisión de Alexander): la pantalla ya no tiene el
     * segmentado de arriba y el hijo activo se decide por la ruta **y** por el `?listado=` (los tres comparten ruta).
     */
    public function test_el_menu_es_un_grupo_con_un_hijo_por_listado_y_marca_el_activo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $hijos = [
            'embarazos' => 'Embarazos y partos',
            'pacientes-por-rango' => 'Pacientes por rango',
            'consultas-por-rango' => 'Consultas por rango',
        ];

        foreach ($hijos as $clave => $titulo) {
            $html = $this->conContexto($user, $medico, $gineco)
                ->get(route('clinica.listados', ['listado' => $clave]))->assertOk()->getContent();

            // El segmentado ya no está: los tres reportes se eligen desde el menú lateral.
            $this->assertStringNotContainsString('role="group" aria-label="Listados"', $html);

            $this->assertSame(1, preg_match('/data-menu="listados".*?<\/details>/s', $html, $bloque), 'falta el grupo «Listados» en el menú');

            // Están los tres hijos y el activo es el de la clave pedida.
            foreach ($hijos as $url => $texto) {
                $this->assertStringContainsString(route('clinica.listados', ['listado' => $url]), $bloque[0]);
            }
            $this->assertMatchesRegularExpression(
                '/nav-link active[^>]*title="' . preg_quote($titulo, '/') . '"/',
                $bloque[0],
                'el hijo activo tiene que ser «' . $titulo . '»',
            );
        }

        // Sin `?listado=` manda el de por defecto, que es el que la pantalla muestra.
        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.listados'))->assertOk()->getContent();
        preg_match('/data-menu="listados".*?<\/details>/s', $html, $bloque);
        $this->assertMatchesRegularExpression('/nav-link active[^>]*title="Embarazos y partos"/', $bloque[0] ?? '');
    }
}
