<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Publica una versión del canal del escritorio: sube los archivos que cambiaron y **cierra** el
 * manifiesto (recién ahí los consultorios ven la versión nueva).
 *
 *   php artisan escritorio:publicar --carpeta=..\ShareCommons\Standalone\payload
 *       --url=https://doctorisimo.app [--etiqueta=20261023.153012] [--apps=ginecoreport.exe,pediareport.exe]
 *       [--launcher=..\ShareCommons\Standalone\instalar.exe] [--simular]
 *
 * La clave sale de `--clave` o de `ESCRITORIO_CANAL_KEY`; la URL, de `--url` o de `ESCRITORIO_URL`
 * (por defecto `APP_URL`).
 *
 * **Sólo sube lo que cambió**: compara el SHA-256 de cada archivo con el del manifiesto vigente y los
 * que coinciden los marca para que el servidor los copie de la versión anterior. Un paquete de 130 MB
 * donde cambió un `.md` sube un archivo.
 */
class EscritorioPublicarCommand extends Command
{
    protected $signature = 'escritorio:publicar
        {--carpeta= : Carpeta con los archivos a publicar (las rutas se guardan relativas a ella)}
        {--url= : Base del API (por defecto ESCRITORIO_URL o APP_URL)}
        {--clave= : Clave de publicación (por defecto ESCRITORIO_CANAL_KEY)}
        {--etiqueta= : Versión a publicar (por defecto la fecha y hora)}
        {--apps= : Ejecutables soportados, separados por coma (por defecto los de config/escritorio.php)}
        {--launcher= : Ruta del instalar.exe para publicarlo también}
        {--simular : No sube nada: informa qué haría}';

    protected $description = 'Publica una versión del canal del escritorio (manifiesto + archivos)';

    public function handle(): int
    {
        try {
            return $this->publicar();
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function publicar(): int
    {
        $carpeta = rtrim((string) ($this->option('carpeta') ?: base_path('../ShareCommons/Standalone/payload')), '\\/');

        if (! is_dir($carpeta)) {
            throw new \RuntimeException("No existe la carpeta a publicar: {$carpeta}");
        }

        $base = rtrim((string) ($this->option('url') ?: env('ESCRITORIO_URL') ?: config('app.url')), '/');
        $api = $base . '/api/escritorio';

        $this->asegurarMemoria();

        $clave = (string) ($this->option('clave') ?: config('escritorio.clave'));

        if ($clave === '') {
            throw new \RuntimeException('Falta la clave de publicación (--clave o ESCRITORIO_CANAL_KEY).');
        }

        $apps = $this->apps();

        $archivos = $this->inventario($carpeta);

        if ($archivos === []) {
            throw new \RuntimeException("La carpeta {$carpeta} no tiene archivos para publicar.");
        }

        $vigente = $this->manifiestoVigente($api);

        $version = (string) ($this->option('etiqueta') ?: now()->format('Ymd.His'));

        if ($vigente !== null && version_compare($version, $vigente['version'], '<=')) {
            // Dos publicaciones en el mismo segundo: la versión tiene que avanzar igual.
            $version = now()->addSecond()->format('Ymd.His');
        }

        $reutilizar = [];
        $subir = [];

        $hashVigente = [];
        foreach ($vigente['archivos'] ?? [] as $archivo) {
            $hashVigente[$archivo['ruta']] = $archivo['sha256'];
        }

        foreach ($archivos as $ruta => $datos) {
            if (($hashVigente[$ruta] ?? null) === $datos['sha256']) {
                $reutilizar[$ruta] = $datos['sha256'];
            } else {
                $subir[$ruta] = $datos;
            }
        }

        $this->newLine();
        $this->line('Versión: <options=bold>' . $version . '</>');
        $this->line('Archivos: ' . count($archivos) . ' (' . count($subir) . ' para subir, ' . count($reutilizar) . ' se reutilizan)');
        $this->line('Apps: ' . implode(', ', $apps));

        if ($vigente === null) {
            $this->line('Publicado hasta ahora: nada');
        } else {
            $this->line('Publicado hasta ahora: ' . $vigente['version']);
        }

        if ($subir !== []) {
            $this->newLine();
            foreach ($subir as $ruta => $datos) {
                $this->line(sprintf('  %-45s %10s', $ruta, $this->tamano($datos['bytes'])));
            }
        }

        if ($this->option('simular')) {
            $this->newLine();
            $this->comment('Simulación: no se subió nada.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Subiendo…');

        $this->pedir($api, $clave, 'iniciar', [
            'version'    => $version,
            'apps'       => $apps,
            'reutilizar' => $reutilizar,
        ]);

        $hechos = 0;
        foreach ($subir as $ruta => $datos) {
            $respuesta = Http::withHeaders(['X-API-KEY' => $clave])
                ->timeout(300)
                ->attach('archivo', (string) file_get_contents($carpeta . '/' . $ruta), basename($ruta))
                ->post($api . '/publicar/archivo', [
                    'version' => $version,
                    'ruta'    => $ruta,
                    'sha256'  => $datos['sha256'],
                ]);

            if (! $respuesta->successful()) {
                throw new \RuntimeException("Falló la subida de {$ruta}: HTTP {$respuesta->status()} " . $respuesta->body());
            }

            $hechos++;
            $this->line(sprintf('  [%d/%d] %s', $hechos, count($subir), $ruta));
        }

        $manifiesto = $this->pedir($api, $clave, 'cerrar', [
            'version' => $version,
            'apps'    => $apps,
        ]);

        $this->newLine();
        $this->info('Publicado. Manifiesto:');
        $this->line($manifiesto);

        if ($this->option('launcher')) {
            $launcher = (string) $this->option('launcher');

            if (! is_file($launcher)) {
                throw new \RuntimeException("No existe el launcher: {$launcher}");
            }

            $respuesta = Http::withHeaders(['X-API-KEY' => $clave])
                ->timeout(300)
                ->attach('archivo', (string) file_get_contents($launcher), basename($launcher))
                ->post($api . '/publicar/launcher');

            if (! $respuesta->successful()) {
                throw new \RuntimeException('Falló la subida del launcher: HTTP ' . $respuesta->status() . ' ' . $respuesta->body());
            }

            $this->info('Launcher publicado: ' . $launcher . ' (' . $this->tamano((int) filesize($launcher)) . ')');
        }

        return self::SUCCESS;
    }

    /**
     * Publicar un binario de 30 MB necesita más que el `memory_limit` de 128 MB con el que suele venir
     * el CLI: el archivo se lee entero y Guzzle arma el cuerpo multipart con otra copia.
     */
    private function asegurarMemoria(): void
    {
        $actual = trim((string) ini_get('memory_limit'));

        if ($actual === '-1') {
            return;
        }

        $bytes = $this->aBytes($actual);

        if ($bytes > 0 && $bytes < 536870912) {
            @ini_set('memory_limit', '512M');
        }
    }

    private function aBytes(string $valor): int
    {
        $numero = (int) $valor;
        $sufijo = strtolower(trim(substr($valor, strlen((string) $numero))));

        switch ($sufijo) {
            case 'g':
                return $numero * 1024 * 1024 * 1024;
            case 'm':
                return $numero * 1024 * 1024;
            case 'k':
                return $numero * 1024;
            default:
                return $numero > 0 ? $numero : 0;
        }
    }

    /** @return string[] */
    private function apps(): array
    {
        $opcion = (string) $this->option('apps');

        $apps = $opcion !== ''
            ? array_map('trim', explode(',', $opcion))
            : (array) config('escritorio.apps', []);

        $apps = array_values(array_filter($apps));

        foreach ($apps as $app) {
            if (! preg_match('/^[A-Za-z0-9._-]+\.exe$/i', $app)) {
                throw new \RuntimeException("Ejecutable inválido en --apps: {$app}");
            }
        }

        if ($apps === []) {
            throw new \RuntimeException('No hay ejecutables soportados (--apps o config/escritorio.php).');
        }

        return $apps;
    }

    /**
     * Los archivos de la carpeta, con su sha256 y tamaño, indexados por la ruta relativa con `/`.
     *
     * @return array<string,array{sha256:string,bytes:int,absoluta:string}>
     */
    private function inventario(string $carpeta): array
    {
        $archivos = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($carpeta, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterador as $archivo) {
            if (! $archivo->isFile()) {
                continue;
            }

            $relativa = str_replace('\\', '/', substr($archivo->getPathname(), strlen($carpeta) + 1));

            // Lo que no se publica: archivos ocultos, secretos y restos de una descarga.
            if (preg_match('#(^|/)\.#', $relativa) || preg_match('#(^|/)secrets/#', $relativa) || substr($relativa, -5) === '.part') {
                continue;
            }

            $archivos[$relativa] = [
                'sha256'   => hash_file('sha256', $archivo->getPathname()),
                'bytes'    => (int) $archivo->getSize(),
                'absoluta' => $archivo->getPathname(),
            ];
        }

        ksort($archivos);

        return $archivos;
    }

    /**
     * @return array{version:string, apps:string[], archivos:array<int,array{sha256:string,bytes:int,ruta:string}>}|null
     */
    private function manifiestoVigente(string $api): ?array
    {
        $respuesta = Http::timeout(30)->get($api . '/canal');

        if ($respuesta->status() === 404) {
            return null;
        }

        if (! $respuesta->successful()) {
            throw new \RuntimeException('No se pudo leer el manifiesto vigente: HTTP ' . $respuesta->status());
        }

        $manifiesto = ['version' => '', 'apps' => [], 'archivos' => []];

        foreach (preg_split('/\R/', trim($respuesta->body())) as $linea) {
            $linea = trim($linea);

            if (str_starts_with($linea, 'version=')) {
                $manifiesto['version'] = substr($linea, 8);
            } elseif (str_starts_with($linea, 'apps=')) {
                $manifiesto['apps'] = array_values(array_filter(explode(',', substr($linea, 5))));
            } else {
                $partes = preg_split('/\s+/', $linea);
                if (count($partes) === 3) {
                    $manifiesto['archivos'][] = ['sha256' => $partes[0], 'bytes' => (int) $partes[1], 'ruta' => $partes[2]];
                }
            }
        }

        return $manifiesto;
    }

    private function pedir(string $api, string $clave, string $accion, array $datos): string
    {
        $respuesta = Http::withHeaders(['X-API-KEY' => $clave])
            ->timeout(120)
            ->post($api . '/publicar/' . $accion, $datos);

        if (! $respuesta->successful()) {
            throw new \RuntimeException("Falló «{$accion}»: HTTP {$respuesta->status()} " . $respuesta->body());
        }

        return (string) $respuesta->body();
    }

    private function tamano(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }

        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }
}
