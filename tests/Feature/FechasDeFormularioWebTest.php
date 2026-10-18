<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
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
 * **Las fechas que se teclean** (O-18 y O-19): la FUR en `dd/mm/aaaa` y todas las columnas `date` guardadas en
 * `AAAA-MM-DD`.
 *
 * El bug de producción no se puede reproducir con un test que espere el error de MySQL: **SQLite acepta
 * `09/05/2026` en una columna `date`** y la suite entera seguiría verde. Lo que fijan estos casos es el
 * **formato guardado**: después de guardar con la fecha tecleada como la escribe el médico, la columna tiene
 * `AAAA-MM-DD`. Esa aserción falla en SQLite **y** en MySQL, así que es la que evita la regresión.
 *
 * Lo que cubren:
 *
 *  - **Los ecos**: cada uno de sus campos de fecha (la fecha del estudio y la FUR), tecleados
 *    `09/05/2026`, quedan `2026-05-09` en su tabla, y el resto del estudio se guarda igual.
 *  - **Una fecha imposible** (`32/13/2026`, `2026-02-30`) se rechaza con un **mensaje en español** y no se
 *    guarda nada.
 *  - **La lectura**: lo que hay en la base y no es una fecha no rompe la pantalla y se muestra **vacío** (no se
 *    inventa), y lo escrito con un error vuelve al campo como se escribió.
 *  - **Los documentos**: el reposo y el informe aceptan la fecha tecleada y la guardan en `AAAA-MM-DD`.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class FechasDeFormularioWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-06 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** La fecha que se teclea en la pantalla y la que tiene que quedar en la columna. */
    private const TECLEADA = '09/05/2026';
    private const GUARDADA = '2026-05-09';

    /**
     * **El inventario de los ecos** (los siete del ítem 5, con el 2.º y el 3.er trimestre en tablas distintas:
     * ocho pantallas), con su tabla, el modelo que la lee, el mapa del dato y una columna
     * del estudio con la que el eco se vuelve un documento.
     *
     * @return array<string,array{tabla:string, modelo:class-string, mapa:array<int,array<string,mixed>>, dato:array<string,string>}>
     */
    private function estudios(): array
    {
        return [
            'ginecológico' => [
                'tabla' => 'eco_pelvico', 'modelo' => \App\Models\EcoPelvico::class,
                'mapa' => EcoGinecologico::campos(), 'dato' => ['cuerpo' => '60'],
            ],
            'obstétrico (1.er trimestre)' => [
                'tabla' => 'eco_obstetrico', 'modelo' => \App\Models\EcoObstetrico::class,
                'mapa' => EcoObstetrico::grupos(), 'dato' => ['observacion' => 'Embarazo temprano normal.'],
            ],
            'obstétrico (2.º trimestre)' => [
                'tabla' => 'eco_obstetrico_tercer_2', 'modelo' => \App\Models\EcoObstetricoSegundo::class,
                'mapa' => EcoObstetricoTercero::grupos(), 'dato' => ['observacion' => 'Sin hallazgos.'],
            ],
            'obstétrico (3.er trimestre)' => [
                'tabla' => 'eco_obstetrico_tercer', 'modelo' => \App\Models\EcoObstetricoTercer::class,
                'mapa' => EcoObstetricoTercero::grupos(), 'dato' => ['observacion' => 'Sin hallazgos.'],
            ],
            'Doppler perinatal' => [
                'tabla' => 'eco_doppler', 'modelo' => \App\Models\EcoDoppler::class,
                'mapa' => EcoDoppler::grupos(), 'dato' => ['conclusion' => 'Flujo conservado.'],
            ],
            'ecocardiograma fetal' => [
                'tabla' => 'ecocadiograma_fetal', 'modelo' => \App\Models\EcoCardiogramaFetal::class,
                'mapa' => EcoCardiogramaFetal::grupos(), 'dato' => ['conclusion' => 'Corazón normal.'],
            ],
            'mamario' => [
                'tabla' => 'ultra_mama', 'modelo' => \App\Models\UltraMama::class,
                'mapa' => EcoMamario::grupos(), 'dato' => ['conclusion' => 'Sin hallazgos.'],
            ],
            'abdominal' => [
                'tabla' => 'ultra_abdominal', 'modelo' => \App\Models\UltraAbdominal::class,
                'mapa' => EcoAbdominal::grupos(), 'dato' => ['conclusiones' => 'Sin hallazgos.'],
            ],
        ];
    }

    /** La clave del tipo en la URL (`&tipo=`) de cada estudio, emparejada con el inventario. */
    private function tipos(): array
    {
        return [
            'ginecológico' => 'ginecologico',
            'obstétrico (1.er trimestre)' => 'obstetrico',
            'obstétrico (2.º trimestre)' => 'segundo',
            'obstétrico (3.er trimestre)' => 'tercero',
            'Doppler perinatal' => 'doppler',
            'ecocardiograma fetal' => 'fetal',
            'mamario' => 'mamario',
            'abdominal' => 'abdominal',
        ];
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'fechas-test-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'fechas-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Fechas',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $regMedico,
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

    private function urlGuardar(Consulta $consulta, string $tipo): string
    {
        return route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]);
    }

    private function urlSeccion(Consulta $consulta, string $tipo): string
    {
        return route('clinica.consulta', [
            $consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo,
        ]);
    }

    /** Los campos del mapa de un estudio, en una lista plana. */
    private function campos(array $mapa): array
    {
        $campos = [];

        foreach ($mapa as $grupo) {
            // El mapa del eco pélvico es una lista de campos; los demás, una lista de bloques.
            foreach (isset($grupo['campos']) ? $grupo['campos'] : [$grupo] as $campo) {
                $campos[] = $campo;
            }
        }

        return $campos;
    }

    /** Las columnas de fecha de ese mapa, con el control con el que se pintan. */
    private function columnasDeFecha(array $campos): array
    {
        $fechas = [];

        foreach ($campos as $campo) {
            $control = $campo['tipo'] ?? 'texto';

            if ($control === 'fecha' || $control === 'fecha-tecleada') {
                $fechas[$campo['columna']] = $control;
            }
        }

        return $fechas;
    }

    /** El formulario completo del estudio: todos sus campos, las fechas tecleadas y el resto vacío. */
    private function formulario(array $campos, array $valores): array
    {
        $datos = [];

        foreach ($campos as $campo) {
            $datos[$campo['columna']] = '';
        }

        // Las dos fechas de todos los ecos, tal como se teclean en la pantalla; lo que diga `$valores`
        // (una fecha imposible, por ejemplo) manda sobre eso.
        foreach (array_keys($this->columnasDeFecha($campos)) as $columna) {
            $datos[$columna] = self::TECLEADA;
        }

        return array_merge($datos, $valores);
    }

    // ---------------------------------------------------- El formato guardado, en todos los ecos

    /**
     * **La prueba que faltaba**: cada campo de fecha de cada eco, tecleado `09/05/2026`, queda `2026-05-09` en
     * su columna. Falla en SQLite y en MySQL, que es lo que hace que la regresión no vuelva sin que se note.
     */
    public function test_cada_campo_de_fecha_de_los_ecos_se_guarda_en_el_formato_de_la_base(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        foreach ($this->estudios() as $nombre => $estudio) {
            $campos = $this->campos($estudio['mapa']);
            $fechas = $this->columnasDeFecha($campos);

            $this->assertNotEmpty($fechas, 'El estudio «' . $nombre . '» no declara ningún campo de fecha.');

            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->post($this->urlGuardar($consulta, $this->tipos()[$nombre]), $this->formulario($campos, $estudio['dato']))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('estado');

            $fila = $estudio['modelo']::query()->where('reg_medico', $medico->reg_medico)->first();

            $this->assertNotNull($fila, 'No se guardó el estudio «' . $nombre . '».');

            $crudas = $fila->getAttributes();

            foreach ($fechas as $columna => $control) {
                $guardada = (string) ($crudas[$columna] ?? '');

                // `AAAA-MM-DD` (o con la hora en cero, que es como Eloquent escribe una fecha casteada y
                // que una columna `date` de MySQL recorta): lo que **no** puede haber es `09/05/2026`.
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/',
                    $guardada,
                    'La columna `' . $columna . '` del ' . $nombre . ' no quedó en el formato de la base: «' . $guardada . '».',
                );
                $this->assertSame(
                    self::GUARDADA,
                    substr($guardada, 0, 10),
                    'La fecha de `' . $columna . '` del ' . $nombre . ' no es la tecleada.',
                );
            }

            // Y lo demás del estudio se guarda igual: las fechas no se llevaron nada por delante.
            foreach ($estudio['dato'] as $columna => $valor) {
                $this->assertSame($valor, (string) $crudas[$columna], 'El campo `' . $columna . '` del ' . $nombre . ' no se guardó.');
            }
        }
    }

    /**
     * **Ningún campo de fecha se declara como texto**: la fecha del estudio y la FUR son fechas, y los cuatro
     * ecos cuya FUR era `self::texto(...)` —los dos obstétricos, el Doppler y el cardiograma fetal— la
     * declaran **tecleada**, que es lo que pide O-18 (el eco pélvico ya tenía un `<input type="date">`).
     */
    public function test_la_fur_se_declara_como_fecha_y_se_teclea_como_en_el_escritorio(): void
    {
        $tecleadas = [];

        foreach ($this->estudios() as $nombre => $estudio) {
            $campos = $this->campos($estudio['mapa']);
            $fechas = $this->columnasDeFecha($campos);

            // No queda ninguna columna de fecha declarada como texto libre.
            foreach ($campos as $campo) {
                if (! in_array($campo['columna'], ['fecha', 'fur'], true)) {
                    continue;
                }

                $this->assertContains(
                    $campo['tipo'] ?? 'texto',
                    ['fecha', 'fecha-tecleada'],
                    'La columna `' . $campo['columna'] . '` del ' . $nombre . ' se declara como texto.',
                );
            }

            $this->assertArrayHasKey('fecha', $fechas, 'El ' . $nombre . ' no declara su fecha del estudio.');
            $this->assertSame('fecha', $fechas['fecha'], 'La fecha del ' . $nombre . ' tiene que ser una fecha.');

            if (($fechas['fur'] ?? null) === 'fecha-tecleada') {
                $tecleadas[] = $nombre;
            }
        }

        // Los cuatro ecos cuya FUR era texto libre en el mapa (O-18 y O-19).
        $this->assertSame([
            'obstétrico (1.er trimestre)',
            'obstétrico (2.º trimestre)',
            'obstétrico (3.er trimestre)',
            'Doppler perinatal',
            'ecocardiograma fetal',
        ], $tecleadas);
    }

    // ------------------------------------------------------------------------ Fechas imposibles

    public function test_una_fecha_imposible_se_rechaza_con_un_mensaje_en_espanol_y_no_guarda_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $campos = $this->campos(EcoObstetrico::grupos());

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'obstetrico'), $this->formulario($campos, [
                'fur' => '32/13/2026',
                'fecha' => '2026-02-30',
                'observacion' => 'Embarazo temprano normal.',
            ]));

        $respuesta->assertSessionHasErrors(['fur', 'fecha']);
        $respuesta->assertSessionHas('errors', function ($errores) {
            $mensajes = implode(' ', $errores->all());

            $this->assertStringContainsString('«FUR» no es una fecha válida', $mensajes);
            $this->assertStringContainsString('dd/mm/aaaa', $mensajes);
            // El mensaje de Laravel dice «The fur does not match the format Y-m-d»: no sirve, y no es lo que
            // ve el médico.
            $this->assertStringNotContainsString('does not match the format', $mensajes);

            return true;
        });

        // No se guardó nada: la fila no existe.
        $this->assertSame(0, \App\Models\EcoObstetrico::query()->where('reg_medico', $medico->reg_medico)->count());
    }

    /** Lo escrito con error vuelve al campo como se escribió, no vacío y no convertido a otra fecha. */
    public function test_lo_escrito_con_error_vuelve_al_campo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $campos = $this->campos(EcoObstetrico::grupos());

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'obstetrico'), $this->formulario($campos, [
                'fur' => '32/13/2026',
                'observacion' => 'Embarazo temprano normal.',
            ]))
            ->assertSessionHasErrors('fur');

        $this->assertSame('32/13/2026', session('_old_input')['fur']);
    }

    /** La FUR tecleada bien llega al campo del formulario en `dd/mm/aaaa`, no en el formato de la base. */
    public function test_el_formulario_muestra_la_fur_como_se_teclea(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $fila = new \App\Models\EcoObstetrico([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
        ]);
        $fila->fur = self::GUARDADA;
        $fila->observacion = 'Embarazo temprano normal.';
        $fila->save();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'obstetrico'))
            ->assertOk()
            ->assertSee('value="' . self::TECLEADA . '"', false)
            ->assertSee('data-mascara="fecha"', false)
            ->assertSee('placeholder="dd/mm/aaaa"', false);
    }

    // ----------------------------------------------------------------------------------- La lectura

    /**
     * Lo que está guardado y **no** es una fecha (la base de desarrollo tiene textos viejos en `fur`) no rompe
     * la pantalla y se muestra **vacío**: no se inventa una fecha.
     */
    public function test_un_texto_que_no_es_fecha_se_muestra_vacio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Se escribe por SQL, como quedó en las bases que ya tenían el estudio antes de esta regla.
        DB::table('eco_obstetrico')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'fur' => '5 semanas', 'observacion' => 'Embarazo temprano normal.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'obstetrico'));

        $respuesta->assertOk()
            ->assertDontSee('value="5 semanas"', false)
            ->assertSee('value=""', false)
            // Y el resto del estudio sigue a la vista.
            ->assertSee('Embarazo temprano normal.');
    }

    // ------------------------------------------------------------------------------- Los documentos

    public function test_el_reposo_guarda_la_fecha_tecleada_en_el_formato_de_la_base(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.reposo.guardar', $consulta->id), [
                'desde' => self::TECLEADA,
                'dias' => '3',
                'observacion' => 'Reposo relativo.',
            ])
            ->assertSessionHasNoErrors();

        // El modelo castea `fdesde` a fecha, así que Eloquent la escribe como `AAAA-MM-DD 00:00:00` y la
        // columna `date` de MySQL la recorta: lo que importa es que la fecha guardada empiece en su año.
        $this->assertSame(
            self::GUARDADA,
            substr((string) DB::table('reposo_paciente')->where('nrohistoria', 1001)->value('fdesde'), 0, 10),
        );
    }

    public function test_el_informe_guarda_la_fecha_tecleada_en_el_formato_de_la_base(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.informe.guardar', $consulta->id), [
                'para' => 'Dra. Pérez',
                'descripcion' => 'Se informa lo que corresponde.',
                'fecha' => self::TECLEADA,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            self::GUARDADA,
            substr((string) DB::table('informe')->where('nrohistoria', 1001)->value('fe_cha'), 0, 10),
        );
    }
}
