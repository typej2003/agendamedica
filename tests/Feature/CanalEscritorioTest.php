<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El canal de publicación del escritorio: el launcher del consultorio baja de acá el manifiesto y los
 * archivos, y publicar exige la clave de servicio.
 *
 * Lo que fijan estos tests:
 *  - el manifiesto es la única verdad: un archivo que no está listado no se sirve (y una ruta con `..`
 *    tampoco);
 *  - el manifiesto **no** cambia hasta que la versión se cierra, así que una subida cortada no deja a
 *    nadie bajando archivos a medias;
 *  - el servidor no confía en el hash que le mandan: lo recalcula sobre lo que recibió;
 *  - los archivos que no cambiaron se reutilizan de la versión anterior (no se vuelven a subir).
 */
class CanalEscritorioTest extends TestCase
{
    private const CLAVE = 'clave-de-prueba-del-canal';

    private const VERSION = '20261023.153012';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('escritorio');

        config([
            'escritorio.disk'      => 'escritorio',
            'escritorio.clave'     => self::CLAVE,
            'escritorio.prefijo'   => 'canal',
            'escritorio.conservar' => 3,
        ]);
    }

    private function autorizado()
    {
        return $this->withHeader('X-API-KEY', self::CLAVE);
    }

    private function iniciar(string $version, array $reutilizar = [], array $apps = ['pediareport.exe'])
    {
        return $this->autorizado()->postJson('/api/escritorio/publicar/iniciar', [
            'version'    => $version,
            'apps'       => $apps,
            'reutilizar' => $reutilizar,
        ]);
    }

    private function subir(string $version, string $ruta, string $contenido, ?string $sha = null)
    {
        return $this->autorizado()->post('/api/escritorio/publicar/archivo', [
            'version' => $version,
            'ruta'    => $ruta,
            'sha256'  => $sha ?? hash('sha256', $contenido),
            'archivo' => UploadedFile::fake()->createWithContent(basename($ruta), $contenido),
        ], ['Accept' => 'application/json']);
    }

    private function cerrar(string $version, array $apps = ['pediareport.exe'])
    {
        return $this->autorizado()->post('/api/escritorio/publicar/cerrar', [
            'version' => $version,
            'apps'    => $apps,
        ], ['Accept' => 'application/json']);
    }

    /** Publica una versión entera de una vez. */
    private function publicar(string $version, array $archivos, array $apps = ['pediareport.exe'], array $reutilizar = []): void
    {
        $this->iniciar($version, $reutilizar, $apps)->assertOk();

        foreach ($archivos as $ruta => $contenido) {
            $this->subir($version, $ruta, $contenido)->assertOk();
        }

        $this->cerrar($version, $apps)->assertOk();
    }

    public function test_al_principio_no_hay_nada_publicado(): void
    {
        $this->get('/api/escritorio/canal')->assertStatus(404);
    }

    public function test_publica_una_version_y_la_sirve(): void
    {
        $this->publicar(self::VERSION, [
            'actualizar-consultorio.bat' => "@echo off\n",
            'bridge/sync.exe'            => 'binario-de-prueba',
        ], ['ginecoreport.exe', 'pediareport.exe']);

        $respuesta = $this->get('/api/escritorio/canal');

        $respuesta->assertOk();
        $this->assertStringContainsString('version=' . self::VERSION, $respuesta->getContent());
        $this->assertStringContainsString('apps=ginecoreport.exe,pediareport.exe', $respuesta->getContent());
        $this->assertStringContainsString(hash('sha256', 'binario-de-prueba') . ' 17 bridge/sync.exe', $respuesta->getContent());

        $archivo = $this->get('/api/escritorio/canal/bridge/sync.exe');

        $archivo->assertOk();
        $this->assertSame('binario-de-prueba', $archivo->streamedContent());
    }

    public function test_publicar_exige_la_clave(): void
    {
        $this->postJson('/api/escritorio/publicar/iniciar', ['version' => self::VERSION])->assertStatus(401);

        $this->withHeader('X-API-KEY', 'otra-clave')
            ->postJson('/api/escritorio/publicar/iniciar', ['version' => self::VERSION])
            ->assertStatus(401);
    }

    public function test_descargar_no_exige_clave(): void
    {
        $this->publicar(self::VERSION, ['bridge/sync.exe' => 'binario']);

        $this->get('/api/escritorio/canal/bridge/sync.exe')->assertOk();
    }

    public function test_no_sirve_una_ruta_que_no_esta_en_el_manifiesto(): void
    {
        $this->publicar(self::VERSION, ['bridge/sync.exe' => 'binario']);

        $this->get('/api/escritorio/canal/bridge/secreto.txt')->assertStatus(404);
        $this->get('/api/escritorio/canal/bridge/../manifiesto.txt')->assertStatus(404);
        $this->get('/api/escritorio/canal/..%2Fmanifiesto.txt')->assertStatus(404);
    }

    public function test_rechaza_un_archivo_cuyo_hash_no_coincide(): void
    {
        $this->iniciar(self::VERSION)->assertOk();

        $respuesta = $this->subir(self::VERSION, 'bridge/sync.exe', 'contenido real', str_repeat('a', 64));

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('error', 'hash_no_coincide');

        // Y no quedó nada a medio publicar.
        $this->get('/api/escritorio/canal')->assertStatus(404);
    }

    public function test_rechaza_una_version_con_formato_raro(): void
    {
        $this->autorizado()
            ->postJson('/api/escritorio/publicar/iniciar', ['version' => '../../etc'])
            ->assertStatus(422);
    }

    public function test_el_manifiesto_no_cambia_hasta_que_se_cierra(): void
    {
        $this->publicar(self::VERSION, ['bridge/sync.exe' => 'v1']);

        $this->iniciar('20261024.090000')->assertOk();
        $this->subir('20261024.090000', 'bridge/sync.exe', 'v2')->assertOk();

        // Todavía no se cerró: sigue vigente la anterior.
        $this->assertStringContainsString('version=' . self::VERSION, $this->get('/api/escritorio/canal')->getContent());
    }

    public function test_reutiliza_los_archivos_que_no_cambiaron(): void
    {
        $this->publicar(self::VERSION, [
            'bridge/sync.exe' => 'igual',
            'bridge/otro.txt' => 'viejo',
        ]);

        $hashIgual = hash('sha256', 'igual');

        // La versión nueva sólo sube el que cambió; el otro se copia de la anterior.
        $this->iniciar('20261024.090000', ['bridge/sync.exe' => $hashIgual])->assertOk();
        $this->subir('20261024.090000', 'bridge/otro.txt', 'nuevo')->assertOk();
        $this->cerrar('20261024.090000')->assertOk();

        $manifiesto = $this->get('/api/escritorio/canal')->getContent();

        $this->assertStringContainsString('version=20261024.090000', $manifiesto);
        $this->assertStringContainsString($hashIgual . ' 5 bridge/sync.exe', $manifiesto);
        $this->assertSame('igual', $this->get('/api/escritorio/canal/bridge/sync.exe')->streamedContent());
    }

    public function test_no_reutiliza_un_archivo_si_el_hash_no_es_el_del_manifiesto(): void
    {
        $this->publicar(self::VERSION, ['bridge/sync.exe' => 'original']);

        $this->iniciar('20261024.090000', ['bridge/sync.exe' => str_repeat('b', 64)])->assertOk();
        $this->cerrar('20261024.090000')->assertOk();

        // No se copió nada: el archivo no está en la versión nueva y no se sirve.
        $this->get('/api/escritorio/canal/bridge/sync.exe')->assertStatus(404);
    }

    public function test_publica_y_sirve_el_launcher(): void
    {
        $this->get('/api/escritorio/launcher')->assertStatus(404);

        $this->autorizado()
            ->post('/api/escritorio/publicar/launcher', [
                'archivo' => UploadedFile::fake()->createWithContent('instalar.exe', 'launcher'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('launcher', $this->get('/api/escritorio/launcher')->streamedContent());
    }

    public function test_conserva_solo_las_ultimas_versiones(): void
    {
        foreach (['20261020.100000', '20261021.100000', '20261022.100000', '20261023.100000'] as $version) {
            $this->publicar($version, ['bridge/sync.exe' => 'v-' . $version]);
        }

        $discos = Storage::disk('escritorio')->directories('canal/versiones');

        $this->assertCount(3, $discos);
        $this->assertContains('canal/versiones/20261023.100000', $discos);
        $this->assertNotContains('canal/versiones/20261020.100000', $discos);
    }
}
