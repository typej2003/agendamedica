<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoSegundo;
use App\Clinica\Consulta\EcoObstetricoTercero;
use Tests\TestCase;

/**
 * `ecos:mapas`: **el contrato del JSON que consume el app móvil** (primer paso de la salida a los ecos).
 *
 * Lo que fijan estos tests es lo que se rompería sin que se note en la web: el archivo **parsea**, lleva
 * los **cuatro** estudios con sus claves, el pélvico conserva sus **35 columnas y 9 bloques**, ningún campo
 * pierde `columna`/`etiqueta`/`tipo`, las opciones son **siempre pares** `{valor, texto}` (con un caso
 * concreto: `vagina = H` es «Llena») y el archivo sale **UTF-8 sin BOM** —un BOM hace que el `json_decode`
 * del app falle—.
 *
 * Los totales no se escriben a mano: se comparan contra `columnas()` de cada mapa, que es la fuente. Así,
 * cuando el otro agente agregue o quite un campo del eco, este test lo sigue en vez de exigirle un número
 * viejo.
 */
class EcosMapasCommandTest extends TestCase
{
    /** La ruta del JSON de la corrida; distinta por test para no pisarse. */
    private string $ruta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ruta = storage_path('app/testing/e' . substr(uniqid(), -6) . '.json');
        if (! is_dir(dirname($this->ruta))) {
            mkdir(dirname($this->ruta), 0775, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->ruta)) {
            @unlink($this->ruta);
        }

        parent::tearDown();
    }

    /**
     * Corre el comando y devuelve el JSON ya decodificado como array.
     *
     * @param  array<string,mixed>  $opciones
     * @return array<string,mixed>
     */
    private function exportar(array $opciones = []): array
    {
        $this->artisan('ecos:mapas', ['--salida' => $this->ruta] + $opciones)
            ->assertExitCode(0);

        $this->assertFileExists($this->ruta);

        $datos = json_decode((string) file_get_contents($this->ruta), true);
        $this->assertIsArray($datos, 'El JSON del comando no parsea: ' . json_last_error_msg());

        return $datos;
    }

    /**
     * Los estudios del JSON indexados por su clave.
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,array<string,mixed>>
     */
    private function porClave(array $datos): array
    {
        $estudios = [];
        foreach ($datos['estudios'] as $estudio) {
            $estudios[$estudio['clave']] = $estudio;
        }

        return $estudios;
    }

    public function test_el_json_parsea_y_trae_los_ocho_estudios(): void
    {
        $datos = $this->exportar();

        $this->assertSame(1, $datos['version']);
        $this->assertSame(
            ['pelvico', 'obstetrico', 'segundo', 'tercero', 'doppler', 'mamario', 'abdominal', 'fetal'],
            array_column($datos['estudios'], 'clave')
        );

        foreach ($datos['estudios'] as $estudio) {
            // La forma del contrato: las claves de la clave y la lista de columnas editables.
            foreach (['titulo', 'titulo_del_informe', 'tabla', 'columna_historia', 'columna_consulta',
                'columna_fecha', 'columnas', 'grupos'] as $llave) {
                $this->assertArrayHasKey($llave, $estudio, 'Al estudio ' . $estudio['clave'] . ' le falta ' . $llave);
            }

            $this->assertNotSame('', $estudio['tabla']);
            $this->assertIsArray($estudio['columnas']);
            $this->assertIsArray($estudio['grupos']);
        }
    }

    public function test_el_pelvico_tiene_35_columnas_editables_y_9_grupos(): void
    {
        $pelvico = $this->porClave($this->exportar())['pelvico'];

        // Los 35 campos del formulario del escritorio y sus nueve bloques (WEB-6.1).
        $this->assertCount(35, $pelvico['columnas']);
        $this->assertCount(9, $pelvico['grupos']);
        $this->assertSame('eco_pelvico', $pelvico['tabla']);
        $this->assertSame('numhistoria', $pelvico['columna_historia']);
        $this->assertSame('nroconsulta', $pelvico['columna_consulta']);
        $this->assertSame('fecha', $pelvico['columna_fecha']);
    }

    public function test_la_suma_de_campos_coincide_con_las_columnas_de_cada_mapa(): void
    {
        $estudios = $this->porClave($this->exportar());

        $esperados = [
            'pelvico' => EcoGinecologico::columnas(),
            'obstetrico' => EcoObstetrico::columnas(),
            'segundo' => EcoObstetricoSegundo::columnas(),
            'tercero' => EcoObstetricoTercero::columnas(),
            // Los cuatro de la ola §10 (Doppler, mamario, abdominal y ecocardiograma fetal).
            'doppler' => EcoDoppler::columnas(),
            'mamario' => EcoMamario::columnas(),
            'abdominal' => EcoAbdominal::columnas(),
            'fetal' => EcoCardiogramaFetal::columnas(),
        ];

        foreach ($esperados as $clave => $columnas) {
            $campos = 0;
            foreach ($estudios[$clave]['grupos'] as $grupo) {
                $campos += count($grupo['campos']);
            }

            // El JSON y el mapa no pueden discrepar: si se agrega un campo en el mapa, viaja al app.
            $this->assertSame(count($columnas), $campos, 'La suma de campos de ' . $clave . ' no coincide con su mapa');
            $this->assertCount(count($columnas), $estudios[$clave]['columnas']);
        }

        // El obstétrico del 1.er trimestre es su propia tabla y su propia clave.
        $this->assertSame('eco_obstetrico', $estudios['obstetrico']['tabla']);
        $this->assertSame('historia', $estudios['obstetrico']['columna_historia']);
        $this->assertSame('consulta', $estudios['obstetrico']['columna_consulta']);
        $this->assertSame('eco_obstetrico_tercer', $estudios['tercero']['tabla']);
        $this->assertSame('historia', $estudios['tercero']['columna_historia']);
        $this->assertSame('consulta', $estudios['tercero']['columna_consulta']);
    }

    /**
     * **Los cuatro de la ola §10**: su tabla legada (una de ellas con el typo del legado), su clave por
     * `historia` + `consulta` y su título. Es lo que el sync y el esquema local del teléfono necesitan para
     * bajarlos y escribirlos.
     */
    public function test_los_cuatro_ecos_nuevos_traen_su_tabla_y_su_clave(): void
    {
        $estudios = $this->porClave($this->exportar());

        $esperados = [
            'doppler' => ['eco_doppler', 'Eco Doppler perinatal', 'ECO DOPPLER PERINATAL'],
            'mamario' => ['ultra_mama', 'Eco mamario', 'ULTRASONIDO DE MAMAS'],
            'abdominal' => ['ultra_abdominal', 'Eco abdominal', 'ULTRASONIDO ABDOMINAL'],
            // ⚠️ `ecocadiograma_fetal` está así en el legado (con el typo): es la tabla que usa la web y la
            // que sube el escritorio. Escribirla «bien» dejaría el estudio sin bajar.
            'fetal' => ['ecocadiograma_fetal', 'Ecocardiograma fetal', 'ECOCARDIOGRAMA FETAL'],
        ];

        foreach ($esperados as $clave => [$tabla, $titulo, $tituloDelInforme]) {
            $estudio = $estudios[$clave];
            $this->assertSame($tabla, $estudio['tabla'], $clave);
            $this->assertSame('historia', $estudio['columna_historia'], $clave);
            $this->assertSame('consulta', $estudio['columna_consulta'], $clave);
            $this->assertSame('fecha', $estudio['columna_fecha'], $clave);
            $this->assertSame($titulo, $estudio['titulo'], $clave);
            $this->assertSame($tituloDelInforme, $estudio['titulo_del_informe'], $clave);
            // Todos tienen campos y bloques: un mapa vacío pintaría un estudio en blanco.
            $this->assertNotEmpty($estudio['columnas'], $clave);
            $this->assertNotEmpty($estudio['grupos'], $clave);
        }
    }

    /**
     * **El `referido` del mamario y del abdominal sale como texto, no como un desplegable vacío.**
     *
     * En la web es un `opciones` que su ViewModel llena con la libreta del médico (guarda la cédula de un
     * doctor del catálogo `doctores`); el formulario genérico del app no tiene esa libreta, así que un
     * `opciones` sin opciones le dejaría un control inutilizable. El exportador lo pasa a `texto` y el campo
     * se puede escribir a mano.
     */
    public function test_el_referido_sale_como_texto_y_no_como_un_desplegable_vacio(): void
    {
        $estudios = $this->porClave($this->exportar());

        foreach (['mamario', 'abdominal'] as $clave) {
            $campos = [];
            foreach ($estudios[$clave]['grupos'] as $grupo) {
                foreach ($grupo['campos'] as $campo) {
                    $campos[$campo['columna']] = $campo;
                }
            }

            $this->assertArrayHasKey('referido', $campos, $clave);
            $this->assertSame('texto', $campos['referido']['tipo'], $clave);
            $this->assertSame([], $campos['referido']['opciones'], $clave);
        }

        // Y ningún otro campo del JSON queda como `opciones` sin opciones (sería un desplegable roto).
        foreach ($estudios as $clave => $estudio) {
            foreach ($estudio['grupos'] as $grupo) {
                foreach ($grupo['campos'] as $campo) {
                    if ($campo['tipo'] === 'opciones') {
                        $this->assertNotEmpty($campo['opciones'], $clave . '/' . $campo['columna']);
                    }
                }
            }
        }
    }

    public function test_el_segundo_trimestre_es_su_propia_tabla_con_el_mapa_del_tercero(): void
    {
        $segundo = $this->porClave($this->exportar())['segundo'];

        // WEB-11.14: el 2.º comparte el mapa con el 3.º (55 campos en 6 bloques) pero vive en su tabla.
        $this->assertCount(55, $segundo['columnas']);
        $this->assertCount(6, $segundo['grupos']);
        $this->assertSame('eco_obstetrico_tercer_2', $segundo['tabla']);
        $this->assertSame('historia', $segundo['columna_historia']);
        $this->assertSame('consulta', $segundo['columna_consulta']);
        $this->assertSame('fecha', $segundo['columna_fecha']);
        $this->assertSame('Eco obstétrico (2.º trimestre)', $segundo['titulo']);
        $this->assertSame('ECOSONOGRAFÍA OBSTÉTRICA · SEGUNDO TRIMESTRE', $segundo['titulo_del_informe']);

        // La tabla NO puede ser la del 3.º: `EcoObstetricoSegundo` hereda `TABLA` y sería el error fácil.
        $this->assertNotSame('eco_obstetrico_tercer', $segundo['tabla']);

        // El mapa del 2.º es el mismo del 3.º, completo (columnas y grupos en el mismo orden).
        $estudios = $this->porClave($this->exportar());
        $this->assertSame($estudios['tercero']['columnas'], $segundo['columnas']);
        $this->assertSame($estudios['tercero']['grupos'], $segundo['grupos']);
    }

    public function test_cada_campo_trae_su_columna_su_etiqueta_y_su_tipo(): void
    {
        $datos = $this->exportar();

        foreach ($datos['estudios'] as $estudio) {
            foreach ($estudio['grupos'] as $grupo) {
                $this->assertNotSame('', $grupo['titulo']);
                $this->assertArrayHasKey('ayuda', $grupo);

                foreach ($grupo['campos'] as $campo) {
                    foreach (['columna', 'etiqueta', 'tipo', 'unidad', 'ayuda', 'opciones'] as $llave) {
                        $this->assertArrayHasKey($llave, $campo);
                    }

                    $this->assertNotSame('', (string) $campo['columna']);
                    // **La etiqueta puede estar vacía**, pero sólo en los memorandos de texto libre: el
                    // DataWindow de tres de los ecos nuevos (el `texto` del Doppler, y el `texto_inicio` y
                    // `anatomi_datos` del ecocardiograma) no les pone rótulo, y la web los pinta bajo el
                    // título del bloque con un nombre accesible. El resto sí la necesita: el informe
                    // imprime «Etiqueta: valor».
                    if ($campo['tipo'] !== 'area') {
                        $this->assertNotSame('', (string) $campo['etiqueta']);
                    }
                    // Los tipos que el formulario genérico del app sabe pintar.
                    $this->assertContains($campo['tipo'], ['opciones', 'numero', 'texto', 'area', 'fecha', 'fecha-tecleada']);
                    // `opciones` es siempre lista, nunca null: el app no distingue dos formas.
                    $this->assertIsArray($campo['opciones']);
                }
            }
        }
    }

    public function test_las_opciones_son_pares_de_valor_y_texto(): void
    {
        $estudios = $this->porClave($this->exportar());

        $todas = [];
        foreach ($estudios as $estudio) {
            foreach ($estudio['grupos'] as $grupo) {
                foreach ($grupo['campos'] as $campo) {
                    foreach ($campo['opciones'] as $opcion) {
                        $todas[] = array_keys($opcion);
                        $this->assertSame(['valor', 'texto'], array_keys($opcion));
                        $this->assertIsString($opcion['valor']);
                        $this->assertIsString($opcion['texto']);
                        $this->assertNotSame('', $opcion['texto']);
                    }
                }
            }
        }

        $this->assertNotEmpty($todas, 'Ningún campo del JSON trae opciones: el mapa se habría perdido.');

        // El caso concreto del contrato: la vejiga que el legado guarda como `H`.
        $vagina = null;
        foreach ($estudios['pelvico']['grupos'] as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                if ($campo['columna'] === 'vagina') {
                    $vagina = $campo;
                }
            }
        }

        $this->assertNotNull($vagina, 'El eco pélvico perdió el campo `vagina`.');
        $this->assertSame('opciones', $vagina['tipo']);
        $this->assertContains(['valor' => 'H', 'texto' => 'Llena'], $vagina['opciones']);
        $this->assertContains(['valor' => 'V', 'texto' => 'Vacia'], $vagina['opciones']);
    }

    public function test_el_archivo_se_escribe_sin_bom(): void
    {
        $this->exportar();

        $bytes = (string) file_get_contents($this->ruta);

        // Un BOM UTF-8 rompe el `json_decode` del app: los primeros bytes tienen que ser la llave.
        $this->assertNotSame("\xEF\xBB\xBF", substr($bytes, 0, 3));
        $this->assertSame('{', substr($bytes, 0, 1));
        $this->assertIsArray(json_decode($bytes, true));
        $this->assertStringContainsString('Eco pélvico', $bytes, 'El JSON va en UTF-8 con las tildes sin escapar.');
    }

    public function test_el_estudio_elegido_exporta_solo_ese(): void
    {
        // `segundo` es la clave nueva de WEB-11.14: la tiene que aceptar `--estudio`.
        $datos = $this->exportar(['--estudio' => 'segundo']);

        $this->assertCount(1, $datos['estudios']);
        $this->assertSame('segundo', $datos['estudios'][0]['clave']);
        $this->assertSame('eco_obstetrico_tercer_2', $datos['estudios'][0]['tabla']);
    }

    public function test_un_estudio_que_no_existe_termina_con_codigo_2(): void
    {
        $this->artisan('ecos:mapas', ['--salida' => $this->ruta, '--estudio' => 'inventado'])
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->ruta);
    }
}
