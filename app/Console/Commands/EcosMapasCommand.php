<?php

namespace App\Console\Commands;

use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoSegundo;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\DocumentoImpreso;
use Illuminate\Console\Command;
use ReflectionClass;

/**
 * **Exporta los mapas de los cuatro estudios ecográficos a JSON** para que el app móvil los consuma como
 * asset: un solo formulario genérico en Flutter pinta los ~180 campos sin reescribirlos a mano.
 *
 *   php artisan ecos:mapas
 *   php artisan ecos:mapas --salida=- --estudio=pelvico
 *   php artisan ecos:mapas --salida=D:\tmp\ecos.json
 *
 * **La fuente es el mapa del dato de la web**, no una lista nueva: `grupos()`, `campos()` y `columnas()`
 * de cada clase (WEB-6.1 y WEB-6.2). Si mañana el médico cambia un rótulo o una opción en la web, el JSON
 * del app sale distinto la próxima vez que se genere; por eso el comando **no guarda copia propia** de
 * los campos.
 *
 * La metadata (tabla y las dos columnas de la clave) sale de las **constantes que declara el mapa**
 * —son la fuente que usan la Action de guardado y la lectura—, y el respaldo de abajo queda por si alguna
 * clase nueva no las declara todavía. Los títulos de los tres obstétricos no están en su mapa: se toman de
 * donde la web ya los tiene (`TiposDeEstudio` para el nombre de la pestaña y `DocumentoImpreso` para el
 * título de la hoja impresa, con su trimestre).
 *
 * ⚠️ El **2.º trimestre** (`EcoObstetricoSegundo`) hereda el mapa del 3.º y **también su constante
 * `TABLA`**: la suya es `TABLA_DEL_SEGUNDO`. Por eso una constante cuenta sólo si la declara la clase del
 * estudio (ver `constante()`); si no, manda la tabla del respaldo.
 *
 * El archivo se escribe en **UTF-8 sin BOM** (`json_encode` no agrega BOM y se guarda tal cual), con las
 * tildes sin escapar (`JSON_UNESCAPED_UNICODE`) para que el asset se lea a ojo.
 */
class EcosMapasCommand extends Command
{
    /** La versión del contrato del JSON: el app la mira para saber si entiende el archivo. */
    private const VERSION = 1;

    /** La ruta por defecto, relativa a la raíz del proyecto. */
    private const SALIDA_POR_DEFECTO = 'storage/app/ecos-mapas.json';

    protected $signature = 'ecos:mapas
        {--salida= : ruta del JSON (por defecto storage/app/ecos-mapas.json; - para la salida estándar)}
        {--estudio= : sólo un estudio: pelvico, obstetrico, segundo o tercero}';

    protected $description = 'Exporta a JSON los mapas de los cuatro estudios ecográficos para el app móvil';

    public function handle(): int
    {
        $elegido = $this->option('estudio');
        $estudios = $this->estudios();

        if ($elegido !== null && ! isset($estudios[$elegido])) {
            $this->error('El estudio "' . $elegido . '" no existe. Los que hay: ' . implode(', ', array_keys($estudios)) . '.');

            return 2;
        }

        $claves = $elegido === null ? array_keys($estudios) : [$elegido];
        $exportados = [];
        foreach ($claves as $clave) {
            $exportados[] = $this->exportar($clave, $estudios[$clave]);
        }

        $json = json_encode(
            ['version' => self::VERSION, 'estudios' => $exportados],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            $this->error('No se pudo armar el JSON: ' . json_last_error_msg());

            return 1;
        }

        // Una sola escritura: el archivo es un asset, no un registro que se vaya completando.
        $json .= PHP_EOL;

        $salida = (string) ($this->option('salida') ?? '');
        if ($salida === '-') {
            // El JSON limpio va a stdout y el resumen a stderr (redirigir `> ecos.json` no lo ensucia).
            $this->output->write($json);
            $this->resumen($exportados, 'la salida estándar', true);
        } else {
            $ruta = $this->rutaAbsoluta($salida === '' ? self::SALIDA_POR_DEFECTO : $salida);
            $carpeta = dirname($ruta);
            if (! is_dir($carpeta) && ! @mkdir($carpeta, 0775, true) && ! is_dir($carpeta)) {
                $this->error('No se pudo crear la carpeta ' . $carpeta . '.');

                return 1;
            }

            if (@file_put_contents($ruta, $json) === false) {
                $this->error('No se pudo escribir ' . $ruta . '.');

                return 1;
            }

            $this->resumen($exportados, $ruta, false);
        }

        return 0;
    }

    /**
     * Los tres estudios que se exportan, en el orden del app. La **clave** es la del contrato del JSON
     * (`pelvico`, `obstetrico`, `tercero`) y no la de `TiposDeEstudio` porque es la que el app ya espera.
     *
     * `tabla`/`historia`/`consulta` son el respaldo documentado —salen del encabezado de cada clase y de
     * la migración `2026_08_25_055351_create_medical_tables_schema`—: si la clase **declara** su
     * constante, manda la constante.
     *
     * @return array<string,array{clase:class-string, tipo:string, documento:string, tabla:string,
     *     historia:string, consulta:string, fecha:string, titulo:string, titulo_del_informe:?string}>
     */
    private function estudios(): array
    {
        return [
            'pelvico' => [
                'clase' => EcoGinecologico::class,
                // `EcoGinecologico` declara las suyas (`TABLA`, `COLUMNA_HISTORIA`, `COLUMNA_CONSULTA`,
                // `COLUMNA_FECHA`, `TITULO` y `TITULO_DEL_INFORME`); el respaldo es el mismo valor.
                'tipo' => TiposDeEstudio::GINECOLOGICO,
                'documento' => DocumentoImpreso::ECO_PELVICO,
                'tabla' => 'eco_pelvico',
                'historia' => 'numhistoria',
                'consulta' => 'nroconsulta',
                'fecha' => 'fecha',
                'titulo' => 'Eco pélvico',
                'titulo_del_informe' => 'Informe ecosonográfico ginecológico',
            ],
            'obstetrico' => [
                'clase' => EcoObstetrico::class,
                // La clase del 1.er trimestre declara `TABLA = eco_obstetrico` y la clave `historia` +
                // `consulta` (encabezado y DataWindow `d_eco_obstetrico_nueva`); no tiene título propio.
                'tipo' => TiposDeEstudio::OBSTETRICO,
                'documento' => DocumentoImpreso::ECO_OBSTETRICO,
                'tabla' => 'eco_obstetrico',
                'historia' => 'historia',
                'consulta' => 'consulta',
                'fecha' => 'fecha',
                'titulo' => 'Eco obstétrico (1.er trimestre)',
                'titulo_del_informe' => null,
            ],
            'segundo' => [
                // WEB-11.14: Alexander lo quiere separado del 3.º, con **su** tabla
                // (`eco_obstetrico_tercer_2`) y el mismo mapa (la clase hereda de `EcoObstetricoTercero`:
                // 6 grupos y 55 campos, los del 3.º). Su tabla no sale de `TABLA` —heredada y del 3.º—
                // sino de su propia constante y de este respaldo.
                'clase' => EcoObstetricoSegundo::class,
                'tipo' => TiposDeEstudio::SEGUNDO,
                'documento' => DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO,
                'tabla' => 'eco_obstetrico_tercer_2',
                'historia' => 'historia',
                'consulta' => 'consulta',
                'fecha' => 'fecha',
                'titulo' => 'Eco obstétrico (2.º trimestre)',
                'titulo_del_informe' => null,
            ],
            'tercero' => [
                'clase' => EcoObstetricoTercero::class,
                // El 3.er trimestre declara `TABLA = eco_obstetrico_tercer`.
                'tipo' => TiposDeEstudio::TERCERO,
                'documento' => DocumentoImpreso::ECO_OBSTETRICO_TERCERO,
                'tabla' => 'eco_obstetrico_tercer',
                'historia' => 'historia',
                'consulta' => 'consulta',
                'fecha' => 'fecha',
                'titulo' => 'Eco obstétrico (3.er trimestre)',
                'titulo_del_informe' => null,
            ],
        ];
    }

    /**
     * El estudio en la forma que consume el app. Todo lo opcional se **normaliza**: `unidad`, `ayuda` y
     * `titulo_del_informe` van en `null` cuando no hay, y `opciones` es **siempre** una lista (nunca
     * `null`), para que el formulario genérico no tenga que distinguir dos formas.
     *
     * @param  array{clase:class-string, tipo:string, documento:string, tabla:string, historia:string,
     *     consulta:string, fecha:string, titulo:string, titulo_del_informe:?string}  $definicion
     * @return array<string,mixed>
     */
    private function exportar(string $clave, array $definicion): array
    {
        $clase = $definicion['clase'];

        // Los mapas viejos devuelven `columnas()` como lista de nombres; el del eco pélvico, como
        // `columna => tabla`. Las dos formas se aplanan a la lista del contrato.
        $columnas = [];
        foreach ($clase::columnas() as $llave => $valor) {
            $columnas[] = is_string($llave) ? $llave : $valor;
        }

        $grupos = [];
        foreach ($clase::grupos() as $grupo) {
            $campos = [];
            foreach ($grupo['campos'] as $campo) {
                $campos[] = $this->campo($campo);
            }

            $grupos[] = [
                'titulo' => $grupo['titulo'],
                'icono' => $grupo['icono'] ?? null,
                'ayuda' => $grupo['ayuda'] ?? null,
                'campos' => $campos,
            ];
        }

        return [
            'clave' => $clave,
            'titulo' => $this->constante($clase, 'TITULO', $definicion['titulo']),
            'titulo_del_informe' => $this->tituloDelInforme($definicion),
            'tabla' => $this->constante($clase, 'TABLA', $definicion['tabla']),
            'columna_historia' => $this->constante($clase, 'COLUMNA_HISTORIA', $definicion['historia']),
            'columna_consulta' => $this->constante($clase, 'COLUMNA_CONSULTA', $definicion['consulta']),
            'columna_fecha' => $this->constante($clase, 'COLUMNA_FECHA', $definicion['fecha']),
            'columnas' => $columnas,
            'grupos' => $grupos,
        ];
    }

    /**
     * Un campo del mapa con la forma fija del contrato. Las opciones de `EcoGinecologico` ya vienen como
     * `{valor, texto}` (las traduce `deLaLista()`); las de los obstétricos, también (`lista()`); igual se
     * reconstruyen una por una para que no se cuele una clave extra ni un `null`.
     *
     * @param  array<string,mixed>  $campo
     * @return array<string,mixed>
     */
    private function campo(array $campo): array
    {
        $opciones = [];
        foreach ($campo['opciones'] ?? [] as $opcion) {
            $opciones[] = [
                'valor' => (string) $opcion['valor'],
                'texto' => (string) $opcion['texto'],
            ];
        }

        return [
            'columna' => $campo['columna'],
            'etiqueta' => $campo['etiqueta'],
            'tipo' => $campo['tipo'],
            'unidad' => $campo['unidad'] ?? null,
            'ayuda' => $campo['ayuda'] ?? null,
            'opciones' => $opciones,
        ];
    }

    /**
     * El título del informe: el que declara el mapa o, si no lo tiene, el de la hoja impresa
     * (`DocumentoImpreso::tituloConSufijo()`, que le agrega el trimestre a los obstétricos). No se
     * inventa uno nuevo: el app tiene que mostrar lo mismo que la web imprime.
     *
     * @param  array{documento:string, titulo_del_informe:?string}  $definicion
     */
    private function tituloDelInforme(array $definicion): ?string
    {
        if ($definicion['titulo_del_informe'] !== null) {
            return $this->constante($definicion['clase'], 'TITULO_DEL_INFORME', $definicion['titulo_del_informe']);
        }

        return DocumentoImpreso::tituloConSufijo($definicion['documento']);
    }

    /**
     * El valor de una constante **declarada por la clase del mapa**, o el respaldo. Una constante
     * heredada no cuenta: `EcoObstetricoSegundo` hereda `TABLA = eco_obstetrico_tercer` del 3.º y su tabla
     * es la hermana `eco_obstetrico_tercer_2`, así que usarla exportaría el estudio con la tabla del otro.
     * Se lee con reflexión para no atar el comando a que las cuatro clases tengan las mismas constantes.
     */
    private function constante(string $clase, string $nombre, string $porDefecto): string
    {
        $constante = (new ReflectionClass($clase))->getReflectionConstant($nombre);

        if ($constante !== false && $constante->getDeclaringClass()->getName() === $clase) {
            return (string) $constante->getValue();
        }

        return $porDefecto;
    }

    /**
     * Una ruta relativa se resuelve contra la raíz del proyecto (así `--salida=storage/app/x.json` hace
     * lo que dice) y una absoluta se respeta tal cual, con `/` o con `\` de Windows.
     */
    private function rutaAbsoluta(string $ruta): string
    {
        if (preg_match('/^([A-Za-z]:[\\\\\\/]|[\\\\\\/])/', $ruta) === 1) {
            return $ruta;
        }

        return base_path($ruta);
    }

    /**
     * El resumen del final: un renglón por estudio con sus grupos y campos, y el total. Si el destino es
     * la salida estándar va a stderr para no mezclarse con el JSON.
     *
     * @param  array<int,array<string,mixed>>  $exportados
     */
    private function resumen(array $exportados, string $destino, bool $aStderr): void
    {
        $lineas = [];
        foreach ($exportados as $estudio) {
            $campos = 0;
            foreach ($estudio['grupos'] as $grupo) {
                $campos += count($grupo['campos']);
            }

            $lineas[] = sprintf(
                '%s (%s): %d grupos, %d campos · %s',
                $estudio['titulo'],
                $estudio['clave'],
                count($estudio['grupos']),
                $campos,
                $estudio['tabla']
            );
        }

        $lineas[] = sprintf('%d estudio(s) escritos en %s.', count($exportados), $destino);

        foreach ($lineas as $linea) {
            if ($aStderr) {
                fwrite(STDERR, $linea . PHP_EOL);
            } else {
                $this->info($linea);
            }
        }
    }
}
