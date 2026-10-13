<?php

namespace Tests\Feature;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Models\ImagenDeLaPc;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\RegMedicoServicio;
use App\Models\SyncChange;
use App\Models\SyncCredential;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\TipoRealDeImagenTest;

/**
 * **Subir a la nube las imágenes que viven en el disco de la PC del consultorio** (WEB-T.1): los tres endpoints
 * `POST /api/sync/imagenes/{pendientes,subir,omitir}` y el comando `imagenes:estado`.
 *
 * Lo que estos casos fijan, en este orden:
 *
 *  1. **La autenticación es la del resto del sync**: sin credencial 401; la credencial de un equipo es de SU médico (el
 *     cuerpo con otro `reg_medico` da 403 y las filas de otro médico dan 404); el servicio vencido da 402.
 *  2. **`pendientes`**: paginado con cursor, sólo valores con ruta (nada de vacíos ni `none`), sin lo ya subido ni omitido
 *     (salvo `reintentar_omitidas`), con las fotos de paciente por `medico_pacientes`.
 *  3. **`subir`**: acepta JPG/PNG/GIF/WEBP/HEIC por su **contenido**; rechaza un `.exe`, un texto con nombre `.jpg`, lo que
 *     pesa de más y un `sha256` que no coincide; es idempotente; guarda en el disco privado con nombre del servidor; y si la
 *     base falla **borra el archivo**.
 *  4. **El contrato con la fila legada**: `imagen_consulta` queda apuntando al archivo de la nube (`disco`) con el rastro
 *     por columna y `source = migracion`; las demás tablas **no se tocan**.
 *  5. **`omitir`** y **`imagenes:estado`**.
 *
 * `DatabaseTransactions` + `Storage::fake('clinica')`, como los demás tests de archivos.
 */
class ImagenesDeLaPcTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ArchivosClinicos::DISCO);
    }

    protected function tearDown(): void
    {
        ImagenDeLaPc::flushEventListeners();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medico(?string $reg = null): Medico
    {
        $reg ??= 'imgpc-' . uniqid();

        $user = User::create([
            'name' => 'Médico ' . $reg,
            'email' => $reg . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Médico',
            'lastname' => 'De Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $reg,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return $medico;
    }

    /** @return array<string,string> */
    private function credencial(string $reg): array
    {
        $token = SyncCredential::generarToken();
        SyncCredential::create([
            'reg_medico' => $reg,
            'machine_label' => 'EQUIPO-' . uniqid(),
            'machine_host' => 'PC-TEST',
            'bound_to_machine' => false,
            'token_hash' => SyncCredential::hashToken($token),
            'expires_at' => now()->addYear(),
        ]);

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    /** @return array<string,string> */
    private function claveEstatica(): array
    {
        return ['X-API-KEY' => self::API_KEY, 'Accept' => 'application/json'];
    }

    /** El contenido real de una imagen de cada formato (el servidor decide por la cabecera). */
    public static function contenido(string $formato): string
    {
        switch ($formato) {
            case 'png':
                return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
            case 'heic':
                return TipoRealDeImagenTest::iso('heic', ['mif1', 'heic']) . random_bytes(64);
            default:
                $im = imagecreatetruecolor(12, 12);
                imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 255), 80, 120));
                ob_start();
                match ($formato) {
                    'jpg' => imagejpeg($im),
                    'gif' => imagegif($im),
                    'webp' => imagewebp($im),
                };

                return (string) ob_get_clean();
        }
    }

    private function imagenConsulta(string $reg, string $ruta, ?string $disco = null): int
    {
        return (int) DB::table('imagen_consulta')->insertGetId([
            'reg_medico' => $reg, 'nrohistoria' => 7001, 'nroconsulta' => 1, 'observacion' => 'Colposcopia',
            'imagen' => $ruta, 'disco' => $disco, 'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function imagenPaciente(string $reg, ?string $imagen, ?string $imagen2 = null): int
    {
        return (int) DB::table('imagen_pacientes')->insertGetId([
            'reg_medico' => $reg, 'nrohistoria' => 7001, 'imagen' => $imagen ?? '', 'imagen2' => $imagen2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function subir(array $cabeceras, string $reg, string $tabla, int $fila, string $columna, string $rutaPc, ?string $contenido, array $extra = [])
    {
        $datos = array_merge([
            'reg_medico' => $reg, 'tabla' => $tabla, 'fila_id' => $fila, 'columna' => $columna, 'ruta_pc' => $rutaPc,
            'sha256' => $contenido === null ? str_repeat('0', 64) : hash('sha256', $contenido),
        ], $extra);

        if ($contenido !== null) {
            $datos['archivo'] = UploadedFile::fake()->createWithContent(basename(str_replace('\\', '/', $rutaPc)), $contenido);
        }

        return $this->post('/api/sync/imagenes/subir', $datos, $cabeceras);
    }

    private function pendientes(array $cabeceras, string $reg, array $extra = [])
    {
        return $this->postJson('/api/sync/imagenes/pendientes', array_merge(['reg_medico' => $reg], $extra), $cabeceras);
    }

    /** Los archivos que hay en el disco privado de la prueba. */
    private function archivos(): array
    {
        return Storage::disk(ArchivosClinicos::DISCO)->allFiles();
    }

    // ------------------------------------------------------------------- 1) Autenticación y alcance

    public function test_sin_credencial_es_401_en_los_tres_endpoints(): void
    {
        foreach (['pendientes', 'subir', 'omitir'] as $ruta) {
            $this->postJson("/api/sync/imagenes/{$ruta}", ['reg_medico' => 'x'])->assertStatus(401);
        }

        $this->postJson('/api/sync/imagenes/pendientes', ['reg_medico' => 'x'], ['X-API-KEY' => 'clave-mala'])->assertStatus(401);
    }

    public function test_la_credencial_de_un_equipo_manda_el_medico_y_el_cuerpo_ajeno_es_403(): void
    {
        $a = $this->medico();
        $b = $this->medico();
        $this->imagenConsulta($a->reg_medico, 'C:\\fotos\\a.jpg');
        $this->imagenConsulta($b->reg_medico, 'C:\\fotos\\b.jpg');
        $cabeceras = $this->credencial($a->reg_medico);

        // Sin `reg_medico` en el cuerpo: lo pone la credencial y sólo ve lo suyo.
        $r = $this->postJson('/api/sync/imagenes/pendientes', [], $cabeceras)->assertOk();
        $this->assertSame($a->reg_medico, $r->json('reg_medico'));
        $this->assertSame(['C:\\fotos\\a.jpg'], array_column($r->json('items'), 'ruta_pc'));

        // Con el de otro médico: 403 en los tres.
        $this->postJson('/api/sync/imagenes/pendientes', ['reg_medico' => $b->reg_medico], $cabeceras)
            ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'medico_ajeno']);
        $this->postJson('/api/sync/imagenes/omitir', ['reg_medico' => $b->reg_medico, 'items' => []], $cabeceras)
            ->assertStatus(403)->assertJson(['error' => 'medico_ajeno']);
        $this->subir($cabeceras, $b->reg_medico, 'imagen_consulta', 1, 'imagen', 'C:\\x.jpg', self::contenido('png'))
            ->assertStatus(403)->assertJson(['error' => 'medico_ajeno']);
        $this->assertSame([], $this->archivos());
    }

    public function test_con_la_clave_estatica_el_medico_va_en_el_cuerpo_y_es_obligatorio(): void
    {
        $a = $this->medico();
        $this->imagenConsulta($a->reg_medico, 'C:\\fotos\\a.jpg');

        $this->postJson('/api/sync/imagenes/pendientes', [], $this->claveEstatica())
            ->assertStatus(422)->assertJson(['error' => 'parametros']);

        $this->pendientes($this->claveEstatica(), $a->reg_medico)->assertOk()->assertJsonPath('pendientes_total', 1);
    }

    public function test_con_el_servicio_vencido_es_402_como_el_resto_del_sync(): void
    {
        $a = $this->medico();
        $fila = $this->imagenConsulta($a->reg_medico, 'C:\\fotos\\a.jpg');
        RegMedicoServicio::where('reg_medico', $a->reg_medico)->update(['vence_el' => Carbon::today()->subDays(30)->toDateString()]);
        $cabeceras = $this->credencial($a->reg_medico);

        $this->postJson('/api/sync/imagenes/pendientes', [], $cabeceras)->assertStatus(402)->assertJson(['error' => 'servicio_vencido']);
        $this->postJson('/api/sync/imagenes/omitir', ['items' => []], $cabeceras)->assertStatus(402);
        $this->subir($cabeceras, $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\fotos\\a.jpg', self::contenido('png'))->assertStatus(402);
        $this->assertSame([], $this->archivos());
        $this->assertSame(0, ImagenDeLaPc::where('reg_medico', $a->reg_medico)->count());
    }

    // ------------------------------------------------------------------------------- 2) pendientes

    public function test_pendientes_lista_solo_valores_con_ruta_y_de_ese_medico(): void
    {
        $a = $this->medico();
        $b = $this->medico();
        $con = $this->imagenConsulta($a->reg_medico, '  C:\\Users\\IBYTE\\Downloads\\foto.jpg  ');
        $this->imagenConsulta($b->reg_medico, 'C:\\ajena.jpg');
        $this->imagenConsulta($a->reg_medico, 'none');
        $this->imagenConsulta($a->reg_medico, '   ');
        $ip = $this->imagenPaciente($a->reg_medico, 'C:\\MICONSULTAGI\\imagenes\\05078senos_d.jpg', 'C:\\MICONSULTAGI\\imagenes\\05078senos_i.jpg');
        $this->imagenPaciente($a->reg_medico, '', null); // vacía: no cuenta

        $r = $this->pendientes($this->claveEstatica(), $a->reg_medico)->assertOk();

        $items = collect($r->json('items'));
        $this->assertSame(3, $r->json('pendientes_total'));
        $this->assertNull($r->json('siguiente'));
        $this->assertSame(ArchivosClinicos::maximoBytes(), $r->json('tope_bytes'));
        $this->assertEqualsCanonicalizing(
            [['imagen_consulta', $con, 'imagen'], ['imagen_pacientes', $ip, 'imagen'], ['imagen_pacientes', $ip, 'imagen2']],
            $items->map(fn ($i) => [$i['tabla'], $i['fila_id'], $i['columna']])->all()
        );
        // La ruta sale recortada y tal cual (una ruta de Windows, con su letra de unidad).
        $this->assertSame('C:\\Users\\IBYTE\\Downloads\\foto.jpg', $items->firstWhere('tabla', 'imagen_consulta')['ruta_pc']);
    }

    public function test_pendientes_pagina_con_cursor_sin_repetir_ni_saltar(): void
    {
        $a = $this->medico();
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->imagenConsulta($a->reg_medico, "C:\\fotos\\{$i}.jpg");
        }
        $this->imagenPaciente($a->reg_medico, 'C:\\p1.jpg', 'C:\\p2.jpg');
        $cabeceras = $this->claveEstatica();

        $vistas = [];
        $cursor = null;
        $paginas = 0;
        do {
            $r = $this->pendientes($cabeceras, $a->reg_medico, ['limite' => 2, 'cursor' => $cursor])->assertOk();
            $paginas++;
            // El total sólo viene en la primera página.
            if ($paginas === 1) {
                $this->assertSame(7, $r->json('pendientes_total'));
            } else {
                $this->assertNull($r->json('pendientes_total'));
            }
            foreach ($r->json('items') as $item) {
                $vistas[] = $item['tabla'] . '#' . $item['fila_id'] . '#' . $item['columna'];
            }
            $cursor = $r->json('siguiente');
        } while ($cursor !== null && $paginas < 10);

        $this->assertSame(7, count($vistas));
        $this->assertSame(7, count(array_unique($vistas)), 'ninguna referencia se repite');
        $this->assertSame(4, $paginas);
    }

    public function test_pendientes_filtra_por_tabla_y_trae_la_foto_del_paciente_por_su_medico(): void
    {
        $a = $this->medico();
        $b = $this->medico();
        $this->imagenConsulta($a->reg_medico, 'C:\\c.jpg');

        $propio = DB::table('pacientes')->insertGetId(['nombres' => 'Ana', 'apellidos' => 'Foto', 'cedula' => (string) random_int(1e7, 3e7), 'foto_pac' => 'C:\\MICONSULTAGI\\fotos\\ana.jpg', 'created_at' => now(), 'updated_at' => now()]);
        $ajeno = DB::table('pacientes')->insertGetId(['nombres' => 'Eva', 'apellidos' => 'Foto', 'cedula' => (string) random_int(1e7, 3e7), 'foto_pac' => 'C:\\MICONSULTAGI\\fotos\\eva.jpg', 'created_at' => now(), 'updated_at' => now()]);
        MedicoPaciente::create(['medico_id' => $a->id, 'paciente_id' => $propio, 'reg_medico' => $a->reg_medico, 'numhistoria' => '7001']);
        MedicoPaciente::create(['medico_id' => $b->id, 'paciente_id' => $ajeno, 'reg_medico' => $b->reg_medico, 'numhistoria' => '7002']);

        $r = $this->pendientes($this->claveEstatica(), $a->reg_medico, ['tabla' => 'pacientes'])->assertOk();

        $this->assertSame([['pacientes', $propio, 'foto_pac']], collect($r->json('items'))->map(fn ($i) => [$i['tabla'], $i['fila_id'], $i['columna']])->all());
    }

    public function test_pendientes_excluye_lo_subido_y_lo_omitido_y_reintentar_trae_las_omitidas(): void
    {
        $a = $this->medico();
        $subida = $this->imagenConsulta($a->reg_medico, 'C:\\subida.jpg');
        $omitida = $this->imagenConsulta($a->reg_medico, 'C:\\omitida.exe');
        $falta = $this->imagenConsulta($a->reg_medico, 'C:\\falta.jpg');
        $cab = $this->claveEstatica();

        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $subida, 'imagen', 'C:\\subida.jpg', self::contenido('jpg'))->assertOk();
        $this->postJson('/api/sync/imagenes/omitir', ['reg_medico' => $a->reg_medico, 'items' => [
            ['tabla' => 'imagen_consulta', 'fila_id' => $omitida, 'columna' => 'imagen', 'ruta_pc' => 'C:\\omitida.exe', 'motivo' => 'tipo_no_permitido'],
        ]], $cab)->assertOk();

        $normal = $this->pendientes($cab, $a->reg_medico)->assertOk();
        $this->assertSame([$falta], array_column($normal->json('items'), 'fila_id'));
        $this->assertSame(1, $normal->json('pendientes_total'));

        $reintento = $this->pendientes($cab, $a->reg_medico, ['reintentar_omitidas' => true])->assertOk();
        $this->assertEqualsCanonicalizing([$omitida, $falta], array_column($reintento->json('items'), 'fila_id'));
        $this->assertNotContains($subida, array_column($reintento->json('items'), 'fila_id'), 'lo subido nunca vuelve');
    }

    public function test_si_el_escritorio_cambia_la_ruta_la_fila_vuelve_a_ser_pendiente(): void
    {
        $a = $this->medico();
        $fila = $this->imagenPaciente($a->reg_medico, 'C:\\vieja.jpg');
        $cab = $this->claveEstatica();
        $this->subir($cab, $a->reg_medico, 'imagen_pacientes', $fila, 'imagen', 'C:\\vieja.jpg', self::contenido('jpg'))->assertOk();
        $this->assertSame(0, $this->pendientes($cab, $a->reg_medico)->json('pendientes_total'));

        DB::table('imagen_pacientes')->where('id', $fila)->update(['imagen' => 'C:\\nueva.jpg']);

        $this->assertSame([$fila], array_column($this->pendientes($cab, $a->reg_medico)->json('items'), 'fila_id'));
    }

    // ------------------------------------------------------------------------------------ 3) subir

    /** @dataProvider formatos */
    public function test_sube_cada_formato_por_su_contenido_con_nombre_del_servidor(string $formato, string $mime, string $extension): void
    {
        $a = $this->medico();
        $fila = $this->imagenPaciente($a->reg_medico, 'C:\\MICONSULTAGI\\imagenes\\foto.dat'); // el nombre no manda
        $contenido = self::contenido($formato);

        $r = $this->subir($this->credencial($a->reg_medico), $a->reg_medico, 'imagen_pacientes', $fila, 'imagen', 'C:\\MICONSULTAGI\\imagenes\\foto.dat', $contenido)
            ->assertOk()->assertJson(['ok' => true, 'estado' => 'subida', 'tipo' => $mime, 'tamano' => strlen($contenido), 'sha256' => hash('sha256', $contenido)]);

        $registro = ImagenDeLaPc::where('reg_medico', $a->reg_medico)->where('fila_id', $fila)->firstOrFail();
        $this->assertSame('subida', $registro->estado);
        $this->assertSame('clinica', $registro->disco);
        $this->assertMatchesRegularExpression('#^importadas/' . preg_quote($a->reg_medico, '#') . '/imagen_pacientes/\d{4}-\d{2}/[A-Za-z0-9]{40}\.' . $extension . '$#', $registro->ruta);
        $this->assertSame($mime, $registro->tipo);
        // El archivo está y es idéntico; la respuesta no trae la ruta del sistema ni la de la nube.
        $this->assertSame($contenido, Storage::disk('clinica')->get($registro->ruta));
        $this->assertStringNotContainsString('importadas/', $r->getContent());
        $this->assertStringNotContainsString(storage_path(), $r->getContent());
    }

    public static function formatos(): array
    {
        return [
            'jpg' => ['jpg', 'image/jpeg', 'jpg'],
            'png' => ['png', 'image/png', 'png'],
            'gif' => ['gif', 'image/gif', 'gif'],
            'webp' => ['webp', 'image/webp', 'webp'],
            'heic' => ['heic', 'image/heic', 'heic'],
        ];
    }

    public function test_la_extension_sale_del_contenido_y_no_del_nombre_de_la_pc(): void
    {
        $a = $this->medico();
        $fila = $this->imagenPaciente($a->reg_medico, 'C:\\fotos\\IMG_0001.JPG');

        // Un PNG que en la PC se llama `.JPG`: se guarda como PNG.
        $this->subir($this->claveEstatica(), $a->reg_medico, 'imagen_pacientes', $fila, 'imagen', 'C:\\fotos\\IMG_0001.JPG', self::contenido('png'))->assertOk();

        $this->assertStringEndsWith('.png', ImagenDeLaPc::where('fila_id', $fila)->value('ruta'));
    }

    public function test_rechaza_lo_que_no_es_una_imagen_aunque_se_llame_jpg(): void
    {
        $a = $this->medico();
        $cab = $this->credencial($a->reg_medico);
        $malos = [
            'avgui.exe' => "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00" . str_repeat("\0", 100),
            'foto.jpg' => 'esto es texto, no una foto',
            'codigo.png' => "<?php echo 'hola'; ?>" . str_repeat(' ', 30),
            'vacio.jpg' => '',
            'dibujo.svg' => "<svg xmlns='http://www.w3.org/2000/svg'><script>alert(1)</script></svg>",
        ];

        foreach ($malos as $nombre => $contenido) {
            $ruta = 'C:\\varios\\' . $nombre;
            $fila = $this->imagenConsulta($a->reg_medico, $ruta);

            $this->subir($cab, $a->reg_medico, 'imagen_consulta', $fila, 'imagen', $ruta, $contenido)
                ->assertStatus(422)->assertJson(['ok' => false, 'error' => 'tipo_no_permitido']);

            // Nada se guardó, nada se registró y la fila legada sigue con su ruta de la PC.
            $this->assertSame($ruta, DB::table('imagen_consulta')->where('id', $fila)->value('imagen'));
            $this->assertNull(DB::table('imagen_consulta')->where('id', $fila)->value('disco'));
        }

        $this->assertSame([], $this->archivos());
        $this->assertSame(0, ImagenDeLaPc::where('reg_medico', $a->reg_medico)->count());
    }

    public function test_rechaza_lo_que_pesa_mas_del_tope_y_acepta_justo_el_tope(): void
    {
        $a = $this->medico();
        $cab = $this->claveEstatica();
        $fila = $this->imagenConsulta($a->reg_medico, 'C:\\grande.jpg');
        $cabecera = self::contenido('jpg');

        $justo = $cabecera . str_repeat('x', ArchivosClinicos::maximoBytes() - strlen($cabecera));
        $this->assertSame(ArchivosClinicos::maximoBytes(), strlen($justo));
        $pasado = $justo . 'x';

        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\grande.jpg', $pasado)
            ->assertStatus(422)->assertJson(['error' => 'demasiado_grande']);
        $this->assertSame([], $this->archivos());

        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\grande.jpg', $justo)->assertOk();
        $this->assertCount(1, $this->archivos());
    }

    public function test_un_sha256_que_no_coincide_se_rechaza_sin_guardar(): void
    {
        $a = $this->medico();
        $fila = $this->imagenConsulta($a->reg_medico, 'C:\\a.jpg');

        $this->subir($this->claveEstatica(), $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\a.jpg', self::contenido('jpg'), ['sha256' => str_repeat('a', 64)])
            ->assertStatus(422)->assertJson(['error' => 'sha256_no_coincide']);
        $this->subir($this->claveEstatica(), $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\a.jpg', self::contenido('jpg'), ['sha256' => 'no-es-un-hash'])
            ->assertStatus(422)->assertJson(['error' => 'parametros']);

        $this->assertSame([], $this->archivos());
    }

    public function test_los_parametros_y_el_archivo_son_obligatorios(): void
    {
        $a = $this->medico();
        $fila = $this->imagenConsulta($a->reg_medico, 'C:\\a.jpg');
        $cab = $this->claveEstatica();

        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\a.jpg', null)
            ->assertStatus(422)->assertJson(['error' => 'archivo_ausente']);
        $this->subir($cab, $a->reg_medico, 'imagen_consulta', 0, 'imagen', 'C:\\a.jpg', self::contenido('jpg'))
            ->assertStatus(422)->assertJson(['error' => 'parametros']);
    }

    public function test_otra_tabla_o_columna_no_se_puede_subir(): void
    {
        $a = $this->medico();
        $cab = $this->claveEstatica();

        // `evolucion.logo` está fuera a propósito (WEB-8.2), y una columna de texto cualquiera tampoco.
        $this->subir($cab, $a->reg_medico, 'evolucion', 1, 'logo', 'C:\\logo.png', self::contenido('png'))
            ->assertStatus(422)->assertJson(['error' => 'tabla_no_permitida']);
        $this->subir($cab, $a->reg_medico, 'imagen_consulta', 1, 'observacion', 'C:\\x.png', self::contenido('png'))
            ->assertStatus(422)->assertJson(['error' => 'tabla_no_permitida']);
        $this->subir($cab, $a->reg_medico, 'users', 1, 'password', 'C:\\x.png', self::contenido('png'))
            ->assertStatus(422)->assertJson(['error' => 'tabla_no_permitida']);

        $this->assertSame([], $this->archivos());
    }

    public function test_una_fila_de_otro_medico_o_inexistente_es_404_y_una_ruta_distinta_es_409(): void
    {
        $a = $this->medico();
        $b = $this->medico();
        $ajena = $this->imagenConsulta($b->reg_medico, 'C:\\ajena.jpg');
        $propia = $this->imagenConsulta($a->reg_medico, 'C:\\propia.jpg');
        $cab = $this->credencial($a->reg_medico);

        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $ajena, 'imagen', 'C:\\ajena.jpg', self::contenido('png'))
            ->assertStatus(404)->assertJson(['error' => 'fila_inexistente']);
        $this->subir($cab, $a->reg_medico, 'imagen_consulta', 999999999, 'imagen', 'C:\\x.jpg', self::contenido('png'))
            ->assertStatus(404)->assertJson(['error' => 'fila_inexistente']);
        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $propia, 'imagen', 'C:\\otra-ruta.jpg', self::contenido('png'))
            ->assertStatus(409)->assertJson(['error' => 'fila_cambio']);

        $this->assertSame([], $this->archivos());
        $this->assertSame('C:\\ajena.jpg', DB::table('imagen_consulta')->where('id', $ajena)->value('imagen'));
    }

    public function test_es_idempotente_y_un_contenido_distinto_reemplaza_al_anterior(): void
    {
        $a = $this->medico();
        $fila = $this->imagenPaciente($a->reg_medico, 'C:\\foto.jpg');
        $cab = $this->claveEstatica();
        $uno = self::contenido('jpg');

        $this->subir($cab, $a->reg_medico, 'imagen_pacientes', $fila, 'imagen', 'C:\\foto.jpg', $uno)->assertOk()->assertJson(['estado' => 'subida']);
        $primera = ImagenDeLaPc::where('fila_id', $fila)->value('ruta');

        // Lo mismo otra vez: no se guarda de nuevo.
        $this->subir($cab, $a->reg_medico, 'imagen_pacientes', $fila, 'imagen', 'C:\\foto.jpg', $uno)->assertOk()->assertJson(['estado' => 'ya_estaba']);
        $this->assertSame([$primera], $this->archivos());
        $this->assertSame(1, ImagenDeLaPc::where('fila_id', $fila)->count());

        // Otro contenido para la misma celda: se reemplaza (un solo archivo queda).
        $otro = self::contenido('png');
        $this->subir($cab, $a->reg_medico, 'imagen_pacientes', $fila, 'imagen', 'C:\\foto.jpg', $otro)->assertOk()->assertJson(['estado' => 'reemplazada']);
        $nueva = ImagenDeLaPc::where('fila_id', $fila)->value('ruta');
        $this->assertNotSame($primera, $nueva);
        $this->assertSame([$nueva], $this->archivos());
        $this->assertSame(hash('sha256', $otro), ImagenDeLaPc::where('fila_id', $fila)->value('sha256'));
    }

    public function test_si_la_base_falla_se_borra_el_archivo_recien_guardado(): void
    {
        $a = $this->medico();
        $fila = $this->imagenConsulta($a->reg_medico, 'C:\\a.jpg');

        ImagenDeLaPc::saving(function () {
            throw new \RuntimeException('la base se cayó');
        });

        $this->subir($this->claveEstatica(), $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\a.jpg', self::contenido('jpg'))
            ->assertStatus(500)->assertJson(['ok' => false, 'error' => 'error_guardando']);

        $this->assertSame([], $this->archivos(), 'un archivo clínico sin fila no puede quedar en el disco');
        $this->assertSame('C:\\a.jpg', DB::table('imagen_consulta')->where('id', $fila)->value('imagen'));
        $this->assertSame(0, SyncChange::where('table_name', 'imagen_consulta')->where('record_id', $fila)->count());
    }

    // ------------------------------------------------------- 4) El contrato con la fila legada

    public function test_imagen_consulta_pasa_a_apuntar_al_archivo_de_la_nube_con_su_rastro(): void
    {
        $a = $this->medico();
        $fila = $this->imagenConsulta($a->reg_medico, 'C:\\Users\\IBYTE\\Downloads\\20211025_121548.jpg');

        $this->subir($this->credencial($a->reg_medico), $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\Users\\IBYTE\\Downloads\\20211025_121548.jpg', self::contenido('jpg'))->assertOk();

        $registro = ImagenDeLaPc::where('fila_id', $fila)->where('tabla', 'imagen_consulta')->firstOrFail();
        $fila = DB::table('imagen_consulta')->where('id', $fila)->first();
        $this->assertSame($registro->ruta, $fila->imagen, 'la columna legada guarda la ruta relativa al disco, como la web');
        $this->assertSame('clinica', $fila->disco);
        $this->assertSame('C:\\Users\\IBYTE\\Downloads\\20211025_121548.jpg', $registro->ruta_pc, 'la ruta de la PC queda en el registro');

        // El rastro: `updated` por columna, de la migración, sin rutas de la PC ni del sistema ni tokens.
        $cambios = SyncChange::where('table_name', 'imagen_consulta')->where('record_id', $fila->id)->get();
        $this->assertEqualsCanonicalizing(['imagen', 'disco'], $cambios->pluck('column_name')->all());
        $this->assertSame(['migracion'], $cambios->pluck('source')->unique()->values()->all());
        $this->assertSame(['updated'], $cambios->pluck('operation')->unique()->values()->all());
        $this->assertSame($a->reg_medico, $cambios->first()->reg_medico);
        foreach ($cambios as $cambio) {
            $this->assertStringNotContainsString('C:\\', (string) $cambio->value);
            $this->assertStringNotContainsString(storage_path(), (string) $cambio->value);
            $this->assertStringNotContainsString('ddr_sync_', (string) $cambio->value);
        }

        // Ya no es pendiente aunque la columna cambió: lo que tiene es la ruta de la nube.
        $this->assertSame(0, $this->pendientes($this->claveEstatica(), $a->reg_medico)->json('pendientes_total'));
        // Y repetir la subida con la ruta de la PC es `ya_estaba`.
        $this->subir($this->claveEstatica(), $a->reg_medico, 'imagen_consulta', $fila->id, 'imagen', 'C:\\Users\\IBYTE\\Downloads\\20211025_121548.jpg', Storage::disk('clinica')->get($registro->ruta))
            ->assertOk()->assertJson(['estado' => 'ya_estaba']);
        $this->assertCount(1, $this->archivos());
    }

    public function test_las_demas_tablas_no_se_reescriben_y_no_dejan_rastro_de_sync(): void
    {
        $a = $this->medico();
        $filaP = $this->imagenPaciente($a->reg_medico, 'C:\\MICONSULTAGI\\imagenes\\05078senos_d.jpg', 'C:\\MICONSULTAGI\\imagenes\\05078senos_i.jpg');
        $filaI = (int) DB::table('imagenes')->insertGetId(['reg_medico' => $a->reg_medico, 'descripcion' => 'LOGO', 'imagen' => 'C:\\img\\logo.png', 'created_at' => now(), 'updated_at' => now()]);
        $paciente = (int) DB::table('pacientes')->insertGetId(['nombres' => 'Ana', 'apellidos' => 'Foto', 'cedula' => (string) random_int(1e7, 3e7), 'foto_pac' => 'C:\\fotos\\ana.png', 'created_at' => now(), 'updated_at' => now()]);
        MedicoPaciente::create(['medico_id' => $a->id, 'paciente_id' => $paciente, 'reg_medico' => $a->reg_medico, 'numhistoria' => '7001']);
        $cab = $this->claveEstatica();
        $cambiosAntes = SyncChange::count();

        $this->subir($cab, $a->reg_medico, 'imagen_pacientes', $filaP, 'imagen', 'C:\\MICONSULTAGI\\imagenes\\05078senos_d.jpg', self::contenido('jpg'))->assertOk();
        $this->subir($cab, $a->reg_medico, 'imagen_pacientes', $filaP, 'imagen2', 'C:\\MICONSULTAGI\\imagenes\\05078senos_i.jpg', self::contenido('png'))->assertOk();
        $this->subir($cab, $a->reg_medico, 'imagenes', $filaI, 'imagen', 'C:\\img\\logo.png', self::contenido('png'))->assertOk();
        $this->subir($cab, $a->reg_medico, 'pacientes', $paciente, 'foto_pac', 'C:\\fotos\\ana.png', self::contenido('webp'))->assertOk();

        // Las columnas legadas siguen con la ruta de la PC (el escritorio las lee y su subida podría revertirlas).
        $this->assertSame('C:\\MICONSULTAGI\\imagenes\\05078senos_d.jpg', DB::table('imagen_pacientes')->where('id', $filaP)->value('imagen'));
        $this->assertSame('C:\\MICONSULTAGI\\imagenes\\05078senos_i.jpg', DB::table('imagen_pacientes')->where('id', $filaP)->value('imagen2'));
        $this->assertSame('C:\\img\\logo.png', DB::table('imagenes')->where('id', $filaI)->value('imagen'));
        $this->assertSame('C:\\fotos\\ana.png', DB::table('pacientes')->where('id', $paciente)->value('foto_pac'));
        $this->assertSame($cambiosAntes, SyncChange::count(), 'sin rastro de sync: no se editó ninguna fila legada');

        // Y el archivo se resuelve por el registro (para cuando la web las muestre).
        $migradas = new \App\Clinica\Archivos\ImagenesMigradas();
        $this->assertSame('05078senos_i.jpg', $migradas->deLaFila($a->reg_medico, 'imagen_pacientes', $filaP, 'imagen2')->nombre);
        $this->assertSame('image/webp', $migradas->deLaFila($a->reg_medico, 'pacientes', $paciente, 'foto_pac')->tipo);
        $this->assertNull($migradas->deLaFila('otro-medico', 'pacientes', $paciente, 'foto_pac'), 'el médico es parte de la clave');
    }

    public function test_un_medico_no_puede_subir_la_celda_de_otro_con_su_propio_registro(): void
    {
        $a = $this->medico();
        $b = $this->medico();
        $fila = $this->imagenConsulta($a->reg_medico, 'C:\\a.jpg');
        $this->imagenConsulta($b->reg_medico, 'C:\\b.jpg');
        $cab = $this->claveEstatica();

        // El médico B intenta subir la celda de A con su propio reg_medico: no la ve.
        $this->subir($cab, $b->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\a.jpg', self::contenido('jpg'))->assertStatus(404);
        $this->assertSame(0, ImagenDeLaPc::where('fila_id', $fila)->count());
        $this->assertSame([], $this->archivos());
    }

    // ------------------------------------------------------------------------------------ 5) omitir

    public function test_omitir_registra_el_motivo_sin_tocar_la_fila_y_no_degrada_lo_subido(): void
    {
        $a = $this->medico();
        $b = $this->medico();
        $exe = $this->imagenConsulta($a->reg_medico, 'C:\\Archivos de programa\\AVG\\AVG8\\avgui.exe');
        $falta = $this->imagenConsulta($a->reg_medico, 'C:\\desaparecida.jpg');
        $subida = $this->imagenConsulta($a->reg_medico, 'C:\\ok.jpg');
        $ajena = $this->imagenConsulta($b->reg_medico, 'C:\\ajena.jpg');
        $cab = $this->credencial($a->reg_medico);
        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $subida, 'imagen', 'C:\\ok.jpg', self::contenido('png'))->assertOk();

        $r = $this->postJson('/api/sync/imagenes/omitir', ['items' => [
            ['tabla' => 'imagen_consulta', 'fila_id' => $exe, 'columna' => 'imagen', 'ruta_pc' => 'C:\\Archivos de programa\\AVG\\AVG8\\avgui.exe', 'motivo' => 'tipo_no_permitido'],
            ['tabla' => 'imagen_consulta', 'fila_id' => $falta, 'columna' => 'imagen', 'ruta_pc' => 'C:\\desaparecida.jpg', 'motivo' => 'inventado'],
            ['tabla' => 'imagen_consulta', 'fila_id' => $subida, 'columna' => 'imagen', 'ruta_pc' => 'C:\\ok.jpg', 'motivo' => 'no_existe'],
            ['tabla' => 'imagen_consulta', 'fila_id' => $ajena, 'columna' => 'imagen', 'ruta_pc' => 'C:\\ajena.jpg', 'motivo' => 'no_existe'],
            ['tabla' => 'imagen_consulta', 'fila_id' => $falta, 'columna' => 'imagen', 'ruta_pc' => 'C:\\otra.jpg', 'motivo' => 'no_existe'],
            ['tabla' => 'evolucion', 'fila_id' => 1, 'columna' => 'logo', 'ruta_pc' => 'C:\\logo.png', 'motivo' => 'no_existe'],
            ['tabla' => 'imagen_consulta'],
        ]], $cab)->assertOk();

        // Registradas: el exe y la desaparecida (y la ya subida cuenta como correcta pero no se degrada).
        $this->assertSame(3, $r->json('registradas'));
        $this->assertEqualsCanonicalizing(
            [['indice' => 3, 'error' => 'fila_inexistente'], ['indice' => 4, 'error' => 'fila_cambio'], ['indice' => 5, 'error' => 'tabla_no_permitida'], ['indice' => 6, 'error' => 'parametros']],
            $r->json('rechazadas')
        );

        $this->assertSame(['omitida', 'tipo_no_permitido'], [ImagenDeLaPc::where('fila_id', $exe)->value('estado'), ImagenDeLaPc::where('fila_id', $exe)->value('motivo')]);
        $this->assertSame('otro', ImagenDeLaPc::where('fila_id', $falta)->value('motivo'), 'un motivo desconocido se guarda como `otro`');
        $this->assertSame('subida', ImagenDeLaPc::where('fila_id', $subida)->value('estado'));
        // La fila legada sigue con su ruta de la PC.
        $this->assertSame('C:\\Archivos de programa\\AVG\\AVG8\\avgui.exe', DB::table('imagen_consulta')->where('id', $exe)->value('imagen'));
        $this->assertNull(DB::table('imagen_consulta')->where('id', $exe)->value('disco'));
        $this->assertSame(0, ImagenDeLaPc::where('reg_medico', $b->reg_medico)->count());

        // Pide entre 1 y N elementos.
        $this->postJson('/api/sync/imagenes/omitir', ['items' => []], $cab)->assertStatus(422)->assertJson(['error' => 'parametros']);
    }

    public function test_una_omitida_se_puede_subir_despues(): void
    {
        $a = $this->medico();
        $fila = $this->imagenConsulta($a->reg_medico, 'C:\\despues.jpg');
        $cab = $this->claveEstatica();

        $this->postJson('/api/sync/imagenes/omitir', ['reg_medico' => $a->reg_medico, 'items' => [
            ['tabla' => 'imagen_consulta', 'fila_id' => $fila, 'columna' => 'imagen', 'ruta_pc' => 'C:\\despues.jpg', 'motivo' => 'no_existe'],
        ]], $cab)->assertOk();
        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $fila, 'imagen', 'C:\\despues.jpg', self::contenido('jpg'))->assertOk()->assertJson(['estado' => 'subida']);

        $registro = ImagenDeLaPc::where('fila_id', $fila)->firstOrFail();
        $this->assertSame('subida', $registro->estado);
        $this->assertNull($registro->motivo);
        $this->assertSame(1, ImagenDeLaPc::where('fila_id', $fila)->count());
    }

    // ------------------------------------------------------------------------- imagenes:estado

    public function test_el_comando_de_estado_informa_subidas_omitidas_pendientes_y_peso(): void
    {
        $a = $this->medico();
        $s1 = $this->imagenConsulta($a->reg_medico, 'C:\\1.jpg');
        $s2 = $this->imagenConsulta($a->reg_medico, 'C:\\2.png');
        $o = $this->imagenConsulta($a->reg_medico, 'C:\\3.exe');
        $this->imagenConsulta($a->reg_medico, 'C:\\4.jpg'); // pendiente
        $p = $this->imagenPaciente($a->reg_medico, 'C:\\5.jpg', 'C:\\6.jpg');
        $cab = $this->claveEstatica();
        $c1 = self::contenido('jpg');
        $c2 = self::contenido('png');

        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $s1, 'imagen', 'C:\\1.jpg', $c1)->assertOk();
        $this->subir($cab, $a->reg_medico, 'imagen_consulta', $s2, 'imagen', 'C:\\2.png', $c2)->assertOk();
        $this->subir($cab, $a->reg_medico, 'imagen_pacientes', $p, 'imagen', 'C:\\5.jpg', $c2)->assertOk();
        $this->postJson('/api/sync/imagenes/omitir', ['reg_medico' => $a->reg_medico, 'items' => [
            ['tabla' => 'imagen_consulta', 'fila_id' => $o, 'columna' => 'imagen', 'ruta_pc' => 'C:\\3.exe', 'motivo' => 'tipo_no_permitido'],
        ]], $cab)->assertOk();

        $this->assertSame(0, Artisan::call('imagenes:estado', ['--reg-medico' => $a->reg_medico]));
        $salida = Artisan::output();

        $this->assertMatchesRegularExpression('/imagen_consulta\.imagen\s*\|\s*4\s*\|\s*2\s*\|\s*1 \(tipo_no_permitido: 1\)\s*\|\s*1\s*\|/', $salida);
        $this->assertMatchesRegularExpression('/imagen_pacientes\.imagen\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|/', $salida);
        $this->assertMatchesRegularExpression('/imagen_pacientes\.imagen2\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|/', $salida);
        $this->assertMatchesRegularExpression('/TOTAL\s*\|\s*6\s*\|\s*3\s*\|\s*1\s*\|\s*2\s*\|/', $salida);
        $this->assertStringContainsString('Faltan 2 por subir', $salida);

        // El peso es la suma de lo subido.
        $this->assertSame(strlen($c1) + 2 * strlen($c2), (int) ImagenDeLaPc::where('reg_medico', $a->reg_medico)->where('estado', 'subida')->sum('tamano'));
    }

    public function test_el_comando_exige_un_medico_y_no_escribe_nada(): void
    {
        $this->assertSame(2, Artisan::call('imagenes:estado'));

        $a = $this->medico();
        $this->imagenConsulta($a->reg_medico, 'C:\\1.jpg');
        $antes = [ImagenDeLaPc::count(), SyncChange::count(), DB::table('imagen_consulta')->where('reg_medico', $a->reg_medico)->value('imagen')];

        $this->assertSame(0, Artisan::call('imagenes:estado', ['--reg-medico' => $a->reg_medico]));
        $this->assertSame(0, Artisan::call('imagenes:estado', ['--todos' => true]));

        $this->assertSame($antes, [ImagenDeLaPc::count(), SyncChange::count(), DB::table('imagen_consulta')->where('reg_medico', $a->reg_medico)->value('imagen')]);
    }
}
