<?php

namespace App\Escritorio\Canal;

use Illuminate\Support\Facades\Storage;

/**
 * **El canal de publicación del escritorio**: las versiones que se le bajan al consultorio y el
 * manifiesto que dice cuál está vigente.
 *
 * Estructura en el disco `escritorio` (`storage/app/escritorio`):
 *
 *   canal/manifiesto.txt                 <- la versión vigente; se escribe ÚLTIMA (ver `cerrar`)
 *   canal/versiones/<version>/<ruta>     <- los archivos, con la ruta relativa a la instalación
 *   canal/instalar.exe                   <- el launcher, aparte (no viaja en el manifiesto)
 *
 * **El manifiesto es la única verdad.** Un consultorio sólo puede bajar lo que está listado ahí, y
 * sólo se publica cuando la versión está completa: mientras se sube, el manifiesto sigue apuntando a
 * la versión anterior. Por eso una subida cortada no deja a nadie bajando archivos a medias.
 *
 * El manifiesto es texto plano a propósito —el launcher lo lee sin parser de JSON—:
 *
 *   version=20261023.153012
 *   apps=ginecoreport.exe,pediareport.exe
 *   <sha256> <bytes> <ruta>
 */
class CanalDelEscritorio
{
    /** `AAAAMMDD.HHMMSS` (o con menos dígitos de hora). Se ordena solo. */
    public const PATRON_VERSION = '/^\d{8}\.\d{4,6}$/';

    /** Ruta relativa a la instalación: sin `..`, sin `\`, sin empezar con `/`. */
    public const PATRON_RUTA = '#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#';

    public const PATRON_SHA256 = '/^[a-f0-9]{64}$/';

    public function disco(): string
    {
        return (string) config('escritorio.disk', 'escritorio');
    }

    public function prefijo(): string
    {
        return trim((string) config('escritorio.prefijo', 'canal'), '/');
    }

    public function rutaManifiesto(): string
    {
        return $this->prefijo() . '/manifiesto.txt';
    }

    public function rutaLauncher(): string
    {
        return $this->prefijo() . '/' . config('escritorio.launcher', 'instalar.exe');
    }

    public function rutaVersion(string $version, string $ruta = ''): string
    {
        $base = $this->prefijo() . '/versiones/' . $version;

        return $ruta === '' ? $base : $base . '/' . ltrim($ruta, '/');
    }

    public function versionValida(string $version): bool
    {
        return (bool) preg_match(self::PATRON_VERSION, $version);
    }

    public function rutaValida(string $ruta): bool
    {
        if ($ruta === '' || strlen($ruta) > 200) {
            return false;
        }

        if (strpos($ruta, '..') !== false || strpos($ruta, '\\') !== false || $ruta[0] === '/') {
            return false;
        }

        return (bool) preg_match(self::PATRON_RUTA, $ruta);
    }

    /**
     * El manifiesto vigente, ya parseado. `null` si todavía no se publicó nada.
     *
     * @return array{version:string, apps:string[], archivos:array<int,array{sha256:string,bytes:int,ruta:string}>}|null
     */
    public function manifiesto(): ?array
    {
        $texto = $this->textoManifiesto();

        return $texto === null ? null : $this->parsear($texto);
    }

    public function textoManifiesto(): ?string
    {
        $disco = Storage::disk($this->disco());

        if (! $disco->exists($this->rutaManifiesto())) {
            return null;
        }

        return (string) $disco->get($this->rutaManifiesto());
    }

    /**
     * @return array{version:string, apps:string[], archivos:array<int,array{sha256:string,bytes:int,ruta:string}>}
     */
    private function parsear(string $texto): array
    {
        $resultado = ['version' => '', 'apps' => [], 'archivos' => []];

        foreach (preg_split('/\R/', trim($texto)) as $linea) {
            $linea = trim($linea);

            if ($linea === '' || $linea[0] === '#') {
                continue;
            }

            if (str_starts_with($linea, 'version=')) {
                $resultado['version'] = trim(substr($linea, 8));
                continue;
            }

            if (str_starts_with($linea, 'apps=')) {
                $apps = array_filter(array_map('trim', explode(',', substr($linea, 5))));
                $resultado['apps'] = array_values($apps);
                continue;
            }

            $partes = preg_split('/\s+/', $linea);
            if (count($partes) !== 3) {
                continue;
            }

            [$sha, $bytes, $ruta] = $partes;

            if (! preg_match(self::PATRON_SHA256, $sha) || ! ctype_digit($bytes) || ! $this->rutaValida($ruta)) {
                continue;
            }

            $resultado['archivos'][] = ['sha256' => $sha, 'bytes' => (int) $bytes, 'ruta' => $ruta];
        }

        return $resultado;
    }

    /**
     * La ruta interna del archivo **si está en el manifiesto vigente** (nada fuera de la lista se sirve,
     * y así una ruta inventada no puede salirse de la carpeta).
     */
    public function archivoPublicado(string $ruta): ?string
    {
        $manifiesto = $this->manifiesto();

        if ($manifiesto === null || ! $this->rutaValida($ruta)) {
            return null;
        }

        $listado = array_column($manifiesto['archivos'], 'ruta');

        if (! in_array($ruta, $listado, true)) {
            return null;
        }

        $path = $this->rutaVersion($manifiesto['version'], $ruta);

        return Storage::disk($this->disco())->exists($path) ? $path : null;
    }

    /**
     * Arranca (o retoma) la publicación de una versión.
     *
     * `$reutilizar` es `ruta => sha256`: los archivos que no cambiaron respecto de la versión vigente.
     * Se copian desde esa versión **sólo si el hash coincide con el que dice el manifiesto**, así el
     * cliente no puede colar contenido distinto con el hash de otro archivo.
     *
     * @param  array<string,string>  $reutilizar
     */
    public function iniciar(string $version, array $reutilizar = []): void
    {
        $disco = Storage::disk($this->disco());
        $disco->makeDirectory($this->rutaVersion($version));

        $vigente = $this->manifiesto();

        if ($vigente === null) {
            return;
        }

        $hashPorRuta = [];
        foreach ($vigente['archivos'] as $archivo) {
            $hashPorRuta[$archivo['ruta']] = $archivo['sha256'];
        }

        foreach ($reutilizar as $ruta => $sha) {
            if (! $this->rutaValida((string) $ruta) || ! is_string($sha) || ! preg_match(self::PATRON_SHA256, $sha)) {
                continue;
            }

            if (($hashPorRuta[$ruta] ?? null) !== $sha) {
                continue;
            }

            $origen = $this->rutaVersion($vigente['version'], $ruta);
            $destino = $this->rutaVersion($version, $ruta);

            if (! $disco->exists($origen) || $disco->exists($destino)) {
                continue;
            }

            $disco->copy($origen, $destino);
        }
    }

    public function guardarArchivo(string $version, string $ruta, string $contenido): string
    {
        $disco = Storage::disk($this->disco());
        $disco->put($this->rutaVersion($version, $ruta), $contenido);

        return hash('sha256', $contenido);
    }

    /**
     * Cierra la versión: arma el manifiesto **leyendo lo que quedó en disco** (no lo que dijo el cliente)
     * y recién entonces lo publica. Devuelve el texto publicado.
     *
     * @param  string[]  $apps
     */
    public function cerrar(string $version, array $apps): string
    {
        $disco = Storage::disk($this->disco());
        $base = $this->rutaVersion($version);

        $apps = array_values(array_unique(array_filter(array_map('trim', $apps), function ($app) {
            return (bool) preg_match('/^[A-Za-z0-9._-]+\.exe$/i', $app);
        })));

        $archivos = [];

        foreach ($disco->allFiles($base) as $path) {
            $ruta = substr($path, strlen($base) + 1);

            if (! $this->rutaValida($ruta)) {
                continue;
            }

            $absoluta = $disco->path($path);

            $archivos[] = [
                'sha256' => hash_file('sha256', $absoluta),
                'bytes'  => filesize($absoluta),
                'ruta'   => $ruta,
            ];
        }

        usort($archivos, function ($a, $b) {
            return strcmp($a['ruta'], $b['ruta']);
        });

        $texto = 'version=' . $version . "\n";
        $texto .= 'apps=' . implode(',', $apps) . "\n";

        foreach ($archivos as $archivo) {
            $texto .= $archivo['sha256'] . ' ' . $archivo['bytes'] . ' ' . $archivo['ruta'] . "\n";
        }

        // Atómico: el manifiesto viejo sigue vigente hasta que este queda escrito del todo.
        $temporal = $this->rutaManifiesto() . '.tmp';
        $disco->put($temporal, $texto);

        $final = $this->rutaManifiesto();
        $renombrado = false;

        if (method_exists($disco, 'path')) {
            // `rename()` de PHP sí pisa el destino en Windows (MoveFileEx con REPLACE_EXISTING), a
            // diferencia del `move()` de Flysystem, que falla si el archivo ya existe.
            $renombrado = @rename($disco->path($temporal), $disco->path($final));
        }

        if (! $renombrado) {
            $disco->delete($final);
            $disco->move($temporal, $final);
        }

        $this->limpiarVersiones($version);

        return $texto;
    }

    public function guardarLauncher(string $contenido): void
    {
        Storage::disk($this->disco())->put($this->rutaLauncher(), $contenido);
    }

    /**
     * Borra las versiones más viejas, conservando las últimas `escritorio.conservar`. La vigente no se
     * toca nunca.
     */
    public function limpiarVersiones(string $vigente): void
    {
        $disco = Storage::disk($this->disco());
        $carpeta = $this->prefijo() . '/versiones';

        if (! $disco->exists($carpeta)) {
            return;
        }

        $versiones = [];

        foreach ($disco->directories($carpeta) as $directorio) {
            $nombre = basename($directorio);

            if ($nombre !== $vigente && $this->versionValida($nombre)) {
                $versiones[] = $nombre;
            }
        }

        sort($versiones);

        $conservar = max(1, (int) config('escritorio.conservar', 3));
        $sobran = count($versiones) - ($conservar - 1);

        for ($i = 0; $i < $sobran; $i++) {
            if ($versiones[$i] !== $vigente) {
                $disco->deleteDirectory($this->rutaVersion($versiones[$i]));
            }
        }
    }
}
