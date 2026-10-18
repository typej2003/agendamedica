<?php

namespace Tests\Feature;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Models\Consulta;
use App\Models\DibujoDeColposcopia;
use App\Models\ExamenFisico;
use App\Models\Historia;
use App\Models\ImagenConsulta;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **La colposcopía de la consulta** (WEB-6.5): la primera solapa que se agrega al examen del ítem 4 del panel
 * del legado (`tabpage_31` de `tab_7` en `w_consulta_principal`).
 *
 * Lo que estos casos fijan:
 *
 *  - **Las solapas del examen**: la sección `?seccion=evaluacion` sigue abriendo en la evaluación física
 *    (WEB-4.3, sin `&pestana=`) y la colposcopía es su propia solapa (`&pestana=colposcopia`), con su
 *    formulario y su aviso de cambios sin guardar.
 *  - **Dónde vive el texto**: el escritorio escribe **uno** en **dos columnas de dos tablas**
 *    (`consultas.observaciones`, la canónica que imprime el informe, y su copia
 *    `examen_fisico_nuevo.colposcopia`), y la web escribe las dos con el **rastro por columna** en
 *    `sync_changes` (`source = web`) y el guard last-write-wins del sync.
 *  - **Sólo lo que cambió**: guardar el mismo texto no deja rastro nuevo, y una columna que ya tiene el texto
 *    no se reescribe.
 *  - **La lectura**: manda la canónica, con la copia como respaldo (una fila guardada a medias no pierde el
 *    texto), y el `none` del legado es "sin texto".
 *  - **Las plantillas**: las de la colposcopía (`formato_print` tipo `CO`) se ofrecen, y las de la evolución
 *    (`EV`) **no** —la misma tabla, otro tipo—.
 *  - El **403** de la secretaría, el **404** de la consulta de otro consultorio y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica. La ronda de Chrome está en
 * `../.agent-tools/verificar-consulta-colposcopia-web.js`.
 */
class ConsultaColposcopiaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** Un PNG de 1×1 válido (el más chico que existe): el contenido es real, así que el MIME se detecta. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        // Las imágenes viven en el disco privado `clinica`: los tests no escriben en el de verdad.
        Storage::fake(ArchivosClinicos::DISCO);

        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
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
        $regMedico = 'colposcopia-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'colposcopia-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Colposcopía',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'colposcopia-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
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
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1): array
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres'     => 'Ana',
            'apellidos'   => 'Alvarez',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $pacienteId,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $pacienteId,
            'medico_id'   => $medico->id,
        ]);

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    /**
     * La fila del examen como la escribe el escritorio: `examen_fisico_nuevo` se llavea por
     * `(historia, consulta)` con el **número** de la consulta, no su id.
     */
    private function examen(Medico $medico, Consulta $consulta, array $extra = []): ExamenFisico
    {
        return ExamenFisico::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia'   => (int) $consulta->numhistoria,
            'consulta'   => (int) $consulta->nroconsulta,
        ], $extra));
    }

    private function urlSeccion(Consulta $consulta, ?string $pestana = null): string
    {
        $parametros = [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION];

        if ($pestana !== null) {
            $parametros['pestana'] = $pestana;
        }

        return route('clinica.consulta', $parametros);
    }

    private function urlColposcopia(Consulta $consulta): string
    {
        return $this->urlSeccion($consulta, PestanasDelExamen::COLPOSCOPIA);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.examen.texto.guardar', [$consulta->id, PestanasDelExamen::COLPOSCOPIA]);
    }

    /** Una plantilla del escritorio (`formato_print`), como la deja su ABM. */
    private function plantilla(Medico $medico, string $tipo, string $codigo, string $titulo, ?string $texto): void
    {
        DB::table('formato_print')->insert([
            'reg_medico'  => $medico->reg_medico,
            'codigo'      => $codigo,
            'titulo'      => $titulo,
            'descripcion' => $texto,
            'tipo'        => $tipo,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** @return array<int,array{columna:?string, source:?string, operation:string}> */
    private function rastros(string $tabla, int $id): array
    {
        return SyncChange::where('table_name', $tabla)->where('record_id', $id)->orderBy('id')
            ->get(['operation', 'column_name', 'source'])
            ->map(fn ($r) => ['operation' => $r->operation, 'columna' => $r->column_name, 'source' => $r->source])
            ->all();
    }

    // ------------------------------------------------------------------------------------ Las solapas

    public function test_la_seccion_del_examen_ofrece_sus_solapas_y_abre_en_la_evaluacion_fisica(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sin `&pestana=` la sección sigue abriendo donde abría (WEB-4.3): la evaluación física.
        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('Evaluación física', $html);
        $this->assertStringContainsString('evaluacion-form', $html);
        // Y el segmentado ofrece la colposcopía con su enlace.
        $this->assertStringContainsString('Colposcopía', $html);
        $this->assertStringContainsString('pestana=' . PestanasDelExamen::COLPOSCOPIA, $html);
    }

    public function test_la_solapa_de_colposcopia_trae_su_formulario_y_su_aviso(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('name="colposcopia"', $html);
        $this->assertStringContainsString('colposcopia-form', $html);
        $this->assertStringContainsString('colposcopia-form-sin-guardar', $html);
        // El formulario de la evaluación física no está en esta solapa.
        $this->assertStringNotContainsString('evaluacion-form', $html);
    }

    // -------------------------------------------------------------------------------------- La lectura

    public function test_la_lectura_toma_la_columna_canonica(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->examen($medico, $consulta, ['colposcopia' => 'Copia del examen.']);
        $consulta->observaciones = 'La canónica, la que imprime el informe.';
        $consulta->save();

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('La canónica, la que imprime el informe.', $html);
        $this->assertStringNotContainsString('Copia del examen.', $html);
    }

    public function test_la_lectura_cae_a_la_copia_si_la_canonica_esta_vacia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->examen($medico, $consulta, ['colposcopia' => 'Sólo quedó la copia del escritorio.']);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('Sólo quedó la copia del escritorio.', $html);
    }

    public function test_el_literal_none_del_escritorio_no_se_muestra(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->observaciones = 'none';
        $consulta->save();

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertOk()->getContent();

        $this->assertStringNotContainsString('>none<', $html);
    }

    // -------------------------------------------------------------------------------------- El guardado

    public function test_guardar_escribe_las_dos_columnas_con_su_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), ['colposcopia' => 'Zona de transformación tipo 1.'])
            ->assertRedirect()
            ->assertSessionHas('estado', 'Colposcopía guardada.');

        $consulta->refresh();
        $this->assertSame('Zona de transformación tipo 1.', $consulta->observaciones);

        $fila = ExamenFisico::where('reg_medico', $medico->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)->firstOrFail();
        $this->assertSame('Zona de transformación tipo 1.', $fila->colposcopia);

        // Una fila de rastro por columna, cada una en su tabla y con su id.
        $canonica = $this->rastros('consultas', (int) $consulta->id);
        $this->assertCount(1, $canonica);
        $this->assertSame(['operation' => 'updated', 'columna' => 'observaciones', 'source' => 'web'], $canonica[0]);

        $copia = $this->rastros('examen_fisico_nuevo', (int) $fila->id);
        $this->assertCount(1, $copia);
        $this->assertSame(['operation' => 'updated', 'columna' => 'colposcopia', 'source' => 'web'], $copia[0]);
    }

    public function test_guardar_el_mismo_texto_no_deja_rastro_nuevo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $web->post($this->urlGuardar($consulta), ['colposcopia' => 'Sin cambios.'])->assertRedirect();
        $fila = ExamenFisico::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $antes = [$this->rastros('consultas', (int) $consulta->id), $this->rastros('examen_fisico_nuevo', (int) $fila->id)];

        $web->post($this->urlGuardar($consulta), ['colposcopia' => 'Sin cambios.'])
            ->assertRedirect()
            ->assertSessionHas('estado', 'La colposcopía no tenía cambios.');

        $this->assertSame($antes, [$this->rastros('consultas', (int) $consulta->id), $this->rastros('examen_fisico_nuevo', (int) $fila->id)]);
    }

    public function test_una_columna_que_ya_tenia_el_texto_no_se_reescribe(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        // El escritorio dejó la canónica y no la copia: al guardar se completa **sólo** la que falta.
        $consulta->observaciones = 'Ya estaba en la canónica.';
        $consulta->save();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), ['colposcopia' => 'Ya estaba en la canónica.'])
            ->assertRedirect();

        $this->assertSame([], $this->rastros('consultas', (int) $consulta->id));

        $fila = ExamenFisico::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame('Ya estaba en la canónica.', $fila->colposcopia);
        $this->assertSame(['operation' => 'updated', 'columna' => 'colposcopia', 'source' => 'web'], $this->rastros('examen_fisico_nuevo', (int) $fila->id)[0]);
    }

    public function test_el_guard_del_sync_no_pisa_una_edicion_mas_nueva(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Un rastro **más nuevo** sobre la canónica (el escritorio, un segundo después).
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'consultas', 'record_id' => (int) $consulta->id,
            'operation' => 'updated', 'column_name' => 'observaciones', 'value' => 'Lo del escritorio',
            'occurred_at' => $this->hoy->copy()->addSecond(), 'source' => 'escritorio',
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), ['colposcopia' => 'Lo de la web'])
            ->assertRedirect()
            ->assertSessionHas('error');

        // La canónica no se pisó; la copia (sin rastro que la bloquee) sí se escribió.
        $this->assertNull($consulta->refresh()->observaciones);

        $fila = ExamenFisico::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame('Lo de la web', $fila->colposcopia);
    }

    // ------------------------------------------------------------------------------------ Las plantillas

    public function test_las_plantillas_de_colposcopia_se_ofrecen_y_las_de_evolucion_no(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->plantilla($medico, 'CO', 'CO01', 'Hallazgo típico', 'Epitelio acetoblanco en el labio anterior.');
        $this->plantilla($medico, 'EV', 'EV01', 'Evolución cualquiera', 'Texto de la evolución.');
        // Una plantilla sin texto no es una plantilla: no hay nada que copiar.
        $this->plantilla($medico, 'CO', 'CO02', 'Vacía', 'none');

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('Hallazgo típico', $html);
        $this->assertStringContainsString('Epitelio acetoblanco en el labio anterior.', $html);
        $this->assertStringNotContainsString('Evolución cualquiera', $html);
        $this->assertStringNotContainsString('Texto de la evolución.', $html);
        $this->assertStringNotContainsString('CO02', $html);
    }

    // ------------------------------------------------------------------ Quién entra y de quién es la consulta

    public function test_la_secretaria_recibe_403(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get($this->urlColposcopia($consulta))->assertForbidden();
    }

    public function test_una_consulta_de_otro_consultorio_es_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $otro = 'colposcopia-otro-' . uniqid();
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $otro]);

        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico);
        // La consulta se mueve a otro `reg_medico` (que no es el del contexto).
        $consulta->reg_medico = $otro;
        $consulta->save();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertNotFound();
        $this->assertGreaterThan(0, $pacienteId);
    }

    public function test_el_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->post($this->urlGuardar($consulta), ['colposcopia' => 'x'])->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------------------- El informe

    private function urlInforme(Consulta $consulta): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'colposcopia']);
    }

    public function test_el_informe_sale_con_su_titulo_y_el_texto_del_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->observaciones = "Zona de transformación tipo 1.\nEpitelio acetoblanco en el labio anterior.";
        $consulta->save();

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlInforme($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('INFORME DE COLPOSCOPÍA', $html);
        $this->assertStringContainsString('Ana', $html);
        $this->assertStringContainsString('Zona de transformación tipo 1.', $html);
        $this->assertStringContainsString('Epitelio acetoblanco en el labio anterior.', $html);
        // Una sola firma, como el resto de las hojas de media A4.
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
    }

    public function test_el_informe_se_ofrece_solo_si_hay_texto(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $documentos = app(\App\Clinica\Impresion\DocumentosParaImprimir::class);

        $sinTexto = collect($documentos->disponibles($consulta, [$medico->reg_medico], true))->pluck('clave');
        $this->assertNotContains('colposcopia', $sinTexto);

        // El literal `none` del legado tampoco es un documento.
        $consulta->observaciones = 'none';
        $consulta->save();
        $this->assertNotContains('colposcopia', collect($documentos->disponibles($consulta, [$medico->reg_medico], true))->pluck('clave'));

        $consulta->observaciones = 'Un texto cualquiera.';
        $consulta->save();
        $this->assertContains('colposcopia', collect($documentos->disponibles($consulta, [$medico->reg_medico], true))->pluck('clave'));
    }

    public function test_la_solapa_ofrece_imprimir_cuando_ya_hay_texto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Sin texto no hay nada que imprimir: el botón no está.
        $this->assertStringNotContainsString('documento=colposcopia', $web->get($this->urlColposcopia($consulta))->getContent());

        $consulta->observaciones = 'Ya hay informe.';
        $consulta->save();

        $this->assertStringContainsString('documento=colposcopia', $web->get($this->urlColposcopia($consulta))->getContent());
    }

    // ------------------------------------------------------------------------------------ Las imágenes

    /** Un PNG real, con el nombre que se le pase (el nombre del cliente lo descarta el servidor). */
    private function png(string $nombre = 'colposcopia.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, base64_decode(self::PNG));
    }

    /** Una fila de `imagen_consulta` como la deja el escritorio (la ruta de su PC) o la web (`disco`). */
    private function imagen(Medico $medico, Consulta $consulta, string $observacion, bool $enElServidor = false): ImagenConsulta
    {
        $ruta = $enElServidor ? 'examenes/' . $medico->reg_medico . '/foto.png' : 'C:\\Users\\IBYTE\\Downloads\\foto.jpg';

        if ($enElServidor) {
            Storage::disk(ArchivosClinicos::DISCO)->put($ruta, base64_decode(self::PNG));
        }

        return ImagenConsulta::create([
            'reg_medico'  => $medico->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'observacion' => $observacion,
            'imagen'      => $ruta,
            'disco'       => $enElServidor ? ArchivosClinicos::DISCO : null,
            'orden'       => 1,
        ]);
    }

    public function test_subir_una_imagen_desde_la_solapa_la_marca_con_la_etiqueta_del_examen(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.examenes.subir', $consulta->id), [
                'imagen' => $this->png(),
                'examen' => PestanasDelExamen::COLPOSCOPIA,
            ])
            ->assertRedirect($this->urlColposcopia($consulta))
            ->assertSessionHas('estado', 'Imagen subida a este examen.');

        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        // La etiqueta canónica del examen (la de su grilla) y el archivo en el disco privado.
        $this->assertSame('@', $imagen->observacion);
        $this->assertSame(ArchivosClinicos::DISCO, $imagen->disco);
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($imagen->imagen);
    }

    public function test_la_solapa_lista_solo_las_imagenes_de_su_examen(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->imagen($medico, $consulta, '@');                  // la canónica de la colposcopía
        $this->imagen($medico, $consulta, 'colpo');              // el alias del botón del escritorio
        $this->imagen($medico, $consulta, 'Una descripción libre'); // de la sección genérica
        $this->imagen($medico, $consulta, 'H');                  // de otra solapa (histeroscopía)

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertOk()->getContent();

        // Las dos de la colposcopía se listan… y son dos, no cuatro.
        $this->assertSame(2, substr_count($html, 'Imagen de colposcopía'));
        $this->assertStringNotContainsString('Una descripción libre', $html);
    }

    public function test_la_seccion_generica_lista_todas_y_nombra_el_examen_de_cada_una(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->imagen($medico, $consulta, '@');
        $this->imagen($medico, $consulta, 'Una descripción libre');

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']))
            ->assertOk()->getContent();

        // La etiqueta se traduce (el código `@` no le dice nada al médico) y la descripción se respeta.
        $this->assertStringContainsString('Imagen de colposcopía', $html);
        $this->assertStringContainsString('Una descripción libre', $html);
    }

    public function test_la_imagen_de_la_solapa_se_sirve_por_la_ruta_autorizada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $imagen = $this->imagen($medico, $consulta, '@', true);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $imagen->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_quitar_una_imagen_desde_la_solapa_vuelve_a_ella(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $imagen = $this->imagen($medico, $consulta, '@', true);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->delete(route('clinica.consulta.examenes.quitar', [$consulta->id, $imagen->id]), ['examen' => PestanasDelExamen::COLPOSCOPIA])
            ->assertRedirect($this->urlColposcopia($consulta));

        $this->assertNull(ImagenConsulta::find($imagen->id));
        Storage::disk(ArchivosClinicos::DISCO)->assertMissing($imagen->imagen);
    }

    public function test_la_subida_generica_no_acepta_una_etiqueta_como_descripcion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // `H` es la etiqueta de la histeroscopía: como descripción haría que la imagen apareciera ahí.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']))
            ->post(route('clinica.consulta.examenes.subir', $consulta->id), [
                'imagen' => $this->png(),
                'descripcion' => 'H',
            ])
            ->assertSessionHasErrors('descripcion');

        $this->assertSame(0, ImagenConsulta::count());
        $this->assertSame([], Storage::disk(ArchivosClinicos::DISCO)->allFiles());
    }

    // ------------------------------------------------------------- Las otras tres solapas de texto (WEB-6.5)

    /** La dirección de una solapa del examen. */
    private function urlSolapa(Consulta $consulta, string $clave): string
    {
        return route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EVALUACION,
            'pestana' => $clave,
        ]);
    }

    public function test_la_fila_de_solapas_tiene_las_cinco_del_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertOk()->getContent();

        foreach (['Evaluación física', 'Colposcopía', 'Citología', 'Histeroscopía', 'Resultado de exámenes'] as $rotulo) {
            $this->assertStringContainsString($rotulo, $html, "Falta la solapa «{$rotulo}».");
        }
    }

    public function test_cada_solapa_de_texto_escribe_su_columna_con_su_rastro(): void
    {
        // La clave de la solapa y la columna de `consultas` que el escritorio escribe en cada una
        // (`w_consulta_principal.srw:2830-2834`).
        $columnas = [
            PestanasDelExamen::COLPOSCOPIA => 'observaciones',
            'citologia' => 'faringe',
            'histeroscopia' => 'cuello',
            'resultados' => 'oido',
        ];

        foreach ($columnas as $clave => $columna) {
            [$user, $medico, $gineco] = $this->medicoConAcceso();
            [, $consulta] = $this->pacienteYConsulta($medico);

            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->post(route('clinica.consulta.examen.texto.guardar', [$consulta->id, $clave]), [
                    $clave => 'Texto de la solapa ' . $clave . '.',
                ])
                ->assertRedirect($this->urlSolapa($consulta, $clave));

            $this->assertSame('Texto de la solapa ' . $clave . '.', $consulta->fresh()->getAttribute($columna),
                "La solapa {$clave} no escribió en {$columna}.");

            $this->assertSame(1, SyncChange::where('table_name', 'consultas')
                ->where('column_name', $columna)
                ->where('value', 'Texto de la solapa ' . $clave . '.')
                ->where('source', 'web')
                ->count(), "La solapa {$clave} no dejó el rastro de {$columna}.");
        }
    }

    public function test_la_histeroscopia_no_ofrece_plantillas_y_si_su_informe(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sin texto: el campo está, el botón de imprimir no y el aviso de plantillas dice que no hay (el
        // escritorio no tiene plantillas de histeroscopía: su solapa no hace `retrieve`).
        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSolapa($consulta, 'histeroscopia'))->assertOk()->getContent();

        $this->assertStringContainsString('id="histeroscopia"', $html);
        $this->assertStringNotContainsString('data-plantilla-texto', $html);
        $this->assertStringNotContainsString('documento=histeroscopia', $html);

        // Con texto, la solapa ofrece su hoja.
        $consulta->cuello = 'Cavidad uterina sin lesiones.';
        $consulta->save();

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSolapa($consulta, 'histeroscopia'))->assertOk()->getContent();

        $this->assertStringContainsString('documento=histeroscopia', $html);
    }

    public function test_las_solapas_sin_informe_no_ofrecen_imprimir(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        foreach (['citologia', 'resultados'] as $clave) {
            $consulta->setAttribute($clave === 'citologia' ? 'faringe' : 'oido', 'Un texto cargado.');
            $consulta->save();

            $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->get($this->urlSolapa($consulta, $clave))->assertOk()->getContent();

            $this->assertStringNotContainsString('Imprimir el informe', $html, "La solapa {$clave} ofrece imprimir y no tiene hoja.");
        }
    }

    public function test_una_solapa_que_no_existe_es_un_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta', $consulta->id) . '/examen/inventada', ['inventada' => 'x'])
            ->assertNotFound();
    }

    public function test_el_informe_de_histeroscopia_sale_en_la_lista_de_la_cita_y_en_la_historia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$paciente, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.examen.texto.guardar', [$consulta->id, 'histeroscopia']), [
                'histeroscopia' => 'Cavidad uterina sin lesiones.',
            ]);

        // La hoja: el título del DataWindow del escritorio (`d_histeroscopia.srd:27`) y el texto del médico.
        $hoja = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'histeroscopia']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('INFORME DE HISTEROSCOPÍA', $hoja);
        $this->assertStringContainsString('Cavidad uterina sin lesiones.', $hoja);
        $this->assertSame(1, substr_count($hoja, 'class="firmas"'));

        // Y la historia del paciente lo lista.
        $listado = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $paciente))->assertOk()->getContent();

        $this->assertStringContainsString('Histeroscopía', $listado);
    }

    public function test_los_estudios_ecograficos_suben_imagenes_una_a_una_y_cada_tipo_ve_las_suyas(): void
    {
        // WEB-11.12: sin límite de imágenes; cada tipo de estudio las guarda con su etiqueta del escritorio y vuelve a su estudio.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);
        $volver = route('clinica.consulta', [$consulta->id, 'seccion' => 'estudio', 'tipo' => 'mamario']);

        foreach (['uno.png', 'dos.png', 'tres.png'] as $nombre) {
            $web->post(route('clinica.consulta.examenes.subir', $consulta->id), ['imagen' => $this->png($nombre), 'examen' => 'eco-mamario'])
                ->assertRedirect($volver);
        }
        $web->post(route('clinica.consulta.examenes.subir', $consulta->id), ['imagen' => $this->png(), 'examen' => 'eco-ginecologico']);

        $this->assertSame(3, ImagenConsulta::where('reg_medico', $medico->reg_medico)->where('observacion', 'm')->count());
        $this->assertSame(1, ImagenConsulta::where('reg_medico', $medico->reg_medico)->where('observacion', 'p')->count());

        // El estudio mamario muestra sus tres y el ginecológico sólo la suya.
        $this->assertSame(3, substr_count($web->get($volver)->assertOk()->assertSee('Imágenes del estudio')->getContent(), '<li class="examen-imagen '));
        $this->assertSame(1, substr_count($web->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'estudio', 'tipo' => 'ginecologico']))->getContent(), '<li class="examen-imagen '));
    }

    public function test_la_colposcopia_lleva_una_sola_imagen_la_nueva_reemplaza_a_la_anterior(): void
    {
        // WEB-11.11 (Alexander): una sola imagen por colposcopía.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $web->post(route('clinica.consulta.examenes.subir', $consulta->id), ['imagen' => $this->png('a.png'), 'examen' => 'colposcopia']);
        $web->post(route('clinica.consulta.examenes.subir', $consulta->id), ['imagen' => $this->png('b.png'), 'examen' => 'colposcopia']);

        $this->assertSame(1, ImagenConsulta::where('reg_medico', $medico->reg_medico)->where('observacion', '@')->count());
    }

    public function test_la_galeria_ve_las_imagenes_de_todas_las_consultas_pero_solo_quita_las_de_esta(): void
    {
        // WEB-11.22: el chip «Todas» suma las imágenes de las otras consultas del paciente (se ven y se descargan, no se quitan desde acá).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1);
        $segunda = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => $this->hoy->toDateString()]);
        $vieja = $this->imagen($medico, $primera, 'p', true);
        $actual = $this->imagen($medico, $segunda, 'm', true);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $esta = $web->get(route('clinica.consulta', [$segunda->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']))->assertOk()->assertSee('Esta consulta')->getContent();
        $this->assertSame(1, substr_count($esta, '<li class="examen-imagen '));

        $todas = $web->get(route('clinica.consulta', [$segunda->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes', 'alcance' => 'todas']))->assertOk()->getContent();
        $this->assertSame(2, substr_count($todas, '<li class="examen-imagen '));
        $this->assertStringContainsString('Consulta N.º 1', $todas);
        // Quitar sólo se ofrece para la imagen de esta consulta.
        $this->assertSame(1, substr_count($todas, 'name="_method" value="DELETE"'));

        // La de la otra consulta se descarga con el alcance «todas» y no sin él.
        $web->get(route('clinica.consulta.examenes.descargar', [$segunda->id, $vieja->id]))->assertNotFound();
        $web->get(route('clinica.consulta.examenes.descargar', [$segunda->id, $vieja->id, 'alcance' => 'todas']))->assertOk();
    }

    // -------------------------------------------------------------------- El dibujo del cuello uterino (A-13)

    private function urlLienzo(Consulta $consulta, string $variante, string $verbo = 'ver'): string
    {
        return route('clinica.consulta.colposcopia.' . $verbo, [$consulta->id, $variante]);
    }

    /** La tarjeta de los dos lienzos, en la solapa de la colposcopía. */
    public function test_la_solapa_muestra_los_dos_lienzos_con_sus_dos_bases_y_la_vectorial_por_defecto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlColposcopia($consulta))->assertOk()->getContent();

        // Las dos variantes del escritorio, cada una con su lienzo.
        $this->assertStringContainsString('data-variante="nulipara"', $html);
        $this->assertStringContainsString('data-variante="multipara"', $html);
        $this->assertSame(2, substr_count($html, 'data-lienzo-canvas'));
        $this->assertStringContainsString('Cuello nulípara', $html);
        $this->assertStringContainsString('Cuello multípara', $html);

        // Las dos imágenes base, con la **vectorial** elegida por defecto (la limpieza nueva).
        $this->assertStringContainsString('img/clinica/colposcopia-nulipara.svg', $html);
        $this->assertStringContainsString('img/clinica/colposcopia-multipara.svg', $html);
        $this->assertStringContainsString('img/clinica/colposcopia-nulipara.png', $html);
        $this->assertStringContainsString('img/clinica/colposcopia-multipara.png', $html);
        $this->assertSame(2, preg_match_all('/<option value="vector"[^>]*selected>/', $html));
        $this->assertSame(0, preg_match_all('/<option value="escritorio"[^>]*selected>/', $html));

        // Y el lienzo del texto sigue estando: el dibujo es una tarjeta más, no reemplaza la colposcopía.
        $this->assertStringContainsString('id="colposcopia-form"', $html);
        $this->assertStringContainsString('name="colposcopia"', $html);
    }

    public function test_el_trazo_se_guarda_por_variante_se_sirve_y_se_quita(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $web->post($this->urlLienzo($consulta, 'nulipara', 'guardar'), [
            'base' => 'vector',
            'dibujo' => $this->png(),
        ])->assertOk()->assertJson(['guardado' => true, 'base' => 'vector', 'dibujo' => true]);

        $fila = DibujoDeColposcopia::where('variante', 'nulipara')->firstOrFail();
        $this->assertSame((int) $consulta->nroconsulta, (int) $fila->nroconsulta);
        $this->assertSame('vector', $fila->base);
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($fila->archivo);

        // Se sirve sólo la que tiene trazo.
        $web->get($this->urlLienzo($consulta, 'nulipara'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $web->get($this->urlLienzo($consulta, 'multipara'))->assertNotFound();

        // Guardar de nuevo reemplaza: una fila y el archivo viejo se borra.
        $viejo = $fila->archivo;
        $web->post($this->urlLienzo($consulta, 'nulipara', 'guardar'), [
            'base' => 'vector',
            'dibujo' => $this->png(),
        ])->assertOk();
        $this->assertSame(1, DibujoDeColposcopia::where('variante', 'nulipara')->count());
        Storage::disk(ArchivosClinicos::DISCO)->assertMissing($viejo);

        // Quitar borra el trazo (el archivo y el `archivo` de la fila) pero **conserva la fila y la base**.
        $web->delete($this->urlLienzo($consulta, 'nulipara', 'quitar') . '?base=vector')->assertOk()->assertJson(['quitado' => true]);
        $this->assertSame(0, DibujoDeColposcopia::whereNotNull('archivo')->count());
        $this->assertSame(1, DibujoDeColposcopia::where('variante', 'nulipara')->count());
        $this->assertSame('vector', DibujoDeColposcopia::where('variante', 'nulipara')->value('base'));
        $web->get($this->urlLienzo($consulta, 'nulipara'))->assertNotFound();
    }

    public function test_cambiar_la_base_conserva_el_trazo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $web->post($this->urlLienzo($consulta, 'multipara', 'guardar'), [
            'base' => 'vector',
            'dibujo' => $this->png(),
        ])->assertOk();

        $archivo = DibujoDeColposcopia::where('variante', 'multipara')->value('archivo');
        $this->assertNotNull($archivo);

        // Cambiar la base manda **sólo la base**: el trazo no se toca ni se pierde.
        $web->post($this->urlLienzo($consulta, 'multipara', 'guardar'), ['base' => 'escritorio'])
            ->assertOk()->assertJson(['guardado' => true, 'base' => 'escritorio', 'dibujo' => true]);

        $fila = DibujoDeColposcopia::where('variante', 'multipara')->firstOrFail();
        $this->assertSame('escritorio', $fila->base);
        $this->assertSame($archivo, $fila->archivo);
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($archivo);
        $web->get($this->urlLienzo($consulta, 'multipara'))->assertOk();

        // Y la pantalla recuerda la base elegida en su selector.
        $html = $web->get($this->urlColposcopia($consulta))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="escritorio"[^>]*selected>Del escritorio<\/option>/', $html);
        $this->assertStringContainsString('img/clinica/colposcopia-multipara.png', $html);
    }

    public function test_la_base_se_recuerda_aunque_todavia_no_haya_trazo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $web->post($this->urlLienzo($consulta, 'nulipara', 'guardar'), ['base' => 'escritorio'])
            ->assertOk()->assertJson(['guardado' => true, 'base' => 'escritorio', 'dibujo' => false]);

        $fila = DibujoDeColposcopia::where('variante', 'nulipara')->firstOrFail();
        $this->assertNull($fila->archivo);
        $this->assertSame('escritorio', $fila->base);

        $html = $web->get($this->urlColposcopia($consulta))->getContent();
        $this->assertMatchesRegularExpression('/<option value="escritorio"[^>]*selected>Del escritorio<\/option>/', $html);
    }

    public function test_el_lienzo_rechaza_variantes_y_bases_invalidas_y_archivos_que_no_son_png(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $web->post(route('clinica.consulta.colposcopia.guardar', [$consulta->id, 'arriba']), [
            'base' => 'vector', 'dibujo' => $this->png(),
        ])->assertNotFound();
        $web->get(route('clinica.consulta.colposcopia.ver', [$consulta->id, 'arriba']))->assertNotFound();
        $web->delete(route('clinica.consulta.colposcopia.quitar', [$consulta->id, 'arriba']))->assertNotFound();

        // El lienzo siempre habla JSON (`Accept: application/json`), como el `fetch` del navegador.
        $web->post($this->urlLienzo($consulta, 'nulipara', 'guardar'), ['base' => 'otra', 'dibujo' => $this->png()],
            ['Accept' => 'application/json'])
            ->assertStatus(422);

        // Un JPG no es el PNG transparente que guarda el lienzo.
        $web->post($this->urlLienzo($consulta, 'nulipara', 'guardar'), [
            'base' => 'vector',
            'dibujo' => UploadedFile::fake()->image('foto.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, DibujoDeColposcopia::count());
    }

    public function test_la_secretaria_no_dibuja_y_el_medico_ajeno_recibe_404(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post($this->urlLienzo($consulta, 'nulipara', 'guardar'), ['base' => 'vector', 'dibujo' => $this->png()])
            ->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->delete($this->urlLienzo($consulta, 'nulipara', 'quitar'))->assertForbidden();

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $ajeno = $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser);

        $ajeno->post($this->urlLienzo($consulta, 'nulipara', 'guardar'), ['base' => 'vector', 'dibujo' => $this->png()])
            ->assertNotFound();
        $ajeno->get($this->urlLienzo($consulta, 'nulipara'))->assertNotFound();
        $ajeno->delete($this->urlLienzo($consulta, 'nulipara', 'quitar'))->assertNotFound();

        $this->assertSame(0, DibujoDeColposcopia::count());
    }

    /** El dibujo es una tarjeta más: el texto de la colposcopía se sigue guardando como antes (las dos columnas). */
    public function test_la_solapa_sigue_guardando_el_texto_como_antes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), ['colposcopia' => 'Zona de transformación tipo 1.'])
            ->assertSessionHas('estado', 'Colposcopía guardada.');

        $consulta->refresh();
        $this->assertSame('Zona de transformación tipo 1.', $consulta->observaciones);
        $this->assertSame('Zona de transformación tipo 1.', ExamenFisico::where('historia', 1001)->value('colposcopia'));
    }

    // ----------------------------------------------------------------- La impresión con imágenes (A-13)

    public function test_el_informe_ofrece_incluir_imagenes_con_los_dibujos_del_cuello(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->observaciones = 'Un texto de colposcopía.';
        $consulta->save();
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Sin dibujos, la hoja es la de siempre y no ofrece imágenes.
        $sinDibujos = $web->get($this->urlInforme($consulta))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-incluir-imagenes', $sinDibujos);
        $this->assertStringNotContainsString('data-hoja-imagenes', $sinDibujos);

        $web->post($this->urlLienzo($consulta, 'nulipara', 'guardar'), ['base' => 'escritorio', 'dibujo' => $this->png()])->assertOk();
        $web->post($this->urlLienzo($consulta, 'multipara', 'guardar'), ['base' => 'vector', 'dibujo' => $this->png()])->assertOk();

        $html = $web->get($this->urlInforme($consulta))->assertOk()->getContent();

        // La casilla de la barra (la misma de las ecos) y la hoja aparte con los dos dibujos compuestos.
        $this->assertStringContainsString('data-incluir-imagenes', $html);
        $this->assertStringContainsString('Incluir imágenes', $html);
        $this->assertSame(1, substr_count($html, 'data-hoja-imagenes'));
        $this->assertStringContainsString('INFORME DE COLPOSCOPÍA · IMÁGENES', $html);
        $this->assertSame(2, substr_count($html, '<figure class="informe-imagen informe-imagen-dibujo">'));

        // La base elegida en cada variante viaja a la hoja, y el trazo va incrustado (data URI).
        $this->assertStringContainsString('img/clinica/colposcopia-nulipara.png', $html);
        $this->assertStringContainsString('img/clinica/colposcopia-multipara.svg', $html);
        $this->assertSame(2, substr_count($html, 'class="informe-dibujo-trazo"'));
        $this->assertStringContainsString('src="data:image/', $html);
        $this->assertStringContainsString('Cuello nulípara (orificio transverso)', $html);
        $this->assertStringContainsString('Cuello multípara (orificio redondo)', $html);

        // El texto del informe no cambió: sigue en su media hoja, con su título y su firma.
        $this->assertStringContainsString('Un texto de colposcopía.', $html);
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
    }
}
