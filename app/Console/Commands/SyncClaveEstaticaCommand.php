<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Rota (o muestra) la `X-API-KEY` estática de los endpoints de sync del escritorio.
 *
 * POR QUE EXISTE:
 *  - La clave estaba HARDCODEADA dentro del `.pbl` del escritorio (`ls_api_key = "MiClaveSecreta123!"`),
 *    que es el default del backend. Cualquiera con los `.pbl` (se copian entre PCs, se respaldan,
 *    se mandan por correo) podía subir datos arbitrarios al API.
 *  - Cuando se rota, hay que actualizarla en cada PC. Para que eso no sea adivinar el valor,
 *    este comando imprime el valor rotado una sola vez para copiarlo al `config.json` del puente.
 *
 * USO:
 *   php artisan sync:clave-estatica              # muestra la clave en uso (sin rotar)
 *   php artisan sync:clave-estatica --rotar      # genera una nueva, la persiste y la muestra
 *   php artisan sync:clave-estatica --rotar --forzar   # sin confirmacion (despliegue desatendido)
 *
 * IMPORTANTE: rotar la clave corta el sync de TODO equipo que todavia la use. Los equipos que ya
 * usan credencial por equipo (`sync:credencial`, Bearer) NO se ven afectados: esas credenciales no
 * pasan por esta clave.
 */
class SyncClaveEstaticaCommand extends Command
{
    protected $signature = 'sync:clave-estatica
        {--rotar : Genera una clave nueva y la persiste}
        {--forzar : No pedir confirmacion (despliegue desatendido)}';

    protected $description = 'Muestra o rota la X-API-KEY estatica que usan los endpoints de sync del escritorio';

    public function handle(): int
    {
        $actual = (string) config('app.sync_api_key', 'MiClaveSecreta123!');
        $esDefault = $actual === 'MiClaveSecreta123!';

        $this->newLine();
        $this->line('Clave en uso: <options=bold>' . $actual . '</>');

        if ($esDefault) {
            $this->error('⚠ Es el valor POR DEFECTO hardcodeado en el backend y en el .pbl del escritorio.');
            $this->line('  Cualquiera con los .pbl puede escribir en el API. Rotala cuanto antes.');
        }

        if (! $this->option('rotar')) {
            $this->newLine();
            $this->comment('Para rotarla: php artisan sync:clave-estatica --rotar');
            return self::SUCCESS;
        }

        if (! $this->option('forzar')) {
            $this->newLine();
            $this->warn('Rotar la clave CORTA el sync de todo equipo que todavia la use (el escritorio actual).');
            $this->line('Los equipos que ya usan credencial por equipo (Bearer) no se ven afectados.');
            if (! $this->confirm('¿Rotar ahora?', false)) {
                $this->line('Cancelado.');
                return self::FAILURE;
            }
        }

        $nueva = bin2hex(random_bytes(32));

        $this->persistir($nueva);

        $this->newLine();
        $this->info('Clave rotada. Guardala en cada equipo:');
        $this->line('');
        $this->line('    ' . $nueva);
        $this->line('');
        $this->comment('En el equipo, va en C:\\MICONSULTAGI\\bridge\\config.json (campo "apiKey").');
        $this->comment('Despues de actualizar el .pbl o los config.json, el valor viejo deja de servir.');

        return self::SUCCESS;
    }

    /**
     * Persiste la clave en el `.env`. Si no se puede escribir, al menos la deja en pantalla:
     * es preferible que el operador la copie a mano antes que perderla.
     */
    private function persistir(string $clave): void
    {
        $env = base_path('.env');

        if (! is_file($env) || ! is_writable($env)) {
            $this->error('No se pudo escribir .env — agregala a mano:');
            $this->line('  SYNC_API_KEY=' . $clave);
            return;
        }

        $contenido = file_get_contents($env);
        $linea = 'SYNC_API_KEY=' . $clave;

        if (preg_match('/^SYNC_API_KEY=.*$/m', $contenido)) {
            $contenido = preg_replace('/^SYNC_API_KEY=.*$/m', $linea, $contenido);
        } else {
            $contenido = rtrim($contenido) . PHP_EOL . PHP_EOL . '# Clave estatica de los endpoints de sync del escritorio (ver sync:clave-estatica)' . PHP_EOL . $linea . PHP_EOL;
        }

        file_put_contents($env, $contenido);

        // Refrescar el valor en el proceso actual, para que quede claro que se aplico.
        config(['app.sync_api_key' => $clave]);

        $this->line('Escrita en .env como SYNC_API_KEY.');
    }
}
