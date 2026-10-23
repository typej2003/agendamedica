<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Muestra o rota la clave que autoriza **publicar** en el canal del escritorio.
 *
 * Es una clave de servicio, distinta de la del sync: la usan los scripts que publican (desde una PC de
 * desarrollo, con `escritorio:publicar`), no los consultorios —que descargan sin credencial.
 *
 * USO:
 *   php artisan escritorio:canal-clave            # muestra la clave en uso
 *   php artisan escritorio:canal-clave --rotar    # genera una nueva y la guarda en .env
 */
class EscritorioCanalClaveCommand extends Command
{
    protected $signature = 'escritorio:canal-clave
        {--rotar : Genera una clave nueva y la persiste}
        {--forzar : No pedir confirmación (despliegue desatendido)}';

    protected $description = 'Muestra o rota la clave para publicar en el canal del escritorio';

    public function handle(): int
    {
        $actual = (string) config('escritorio.clave');

        $this->newLine();

        if ($actual === '') {
            $this->error('No hay ESCRITORIO_CANAL_KEY configurada: publicar responde 503.');
        } else {
            $this->line('Clave en uso: <options=bold>' . $actual . '</>');
        }

        if (! $this->option('rotar')) {
            $this->newLine();
            $this->comment('Para rotarla: php artisan escritorio:canal-clave --rotar');
            return self::SUCCESS;
        }

        if (! $this->option('forzar') && ! $this->confirm('¿Rotar ahora? Los scripts que publiquen con la clave vieja dejan de funcionar.', false)) {
            $this->line('Cancelado.');
            return self::FAILURE;
        }

        $nueva = bin2hex(random_bytes(32));

        $this->persistir($nueva);

        $this->newLine();
        $this->info('Clave rotada. Va en el script que publica (ESCRITORIO_CANAL_KEY):');
        $this->line('');
        $this->line('    ' . $nueva);
        $this->line('');
        $this->comment('Los consultorios no la usan: descargan sin credencial.');

        return self::SUCCESS;
    }

    /**
     * Persiste la clave en el `.env`. Si no se puede escribir, la deja en pantalla igual: es preferible
     * que el operador la copie a mano antes que perderla.
     */
    private function persistir(string $clave): void
    {
        $env = base_path('.env');

        if (! is_file($env) || ! is_writable($env)) {
            $this->error('No se pudo escribir .env — agregala a mano:');
            $this->line('  ESCRITORIO_CANAL_KEY=' . $clave);
            return;
        }

        $contenido = file_get_contents($env);
        $linea = 'ESCRITORIO_CANAL_KEY=' . $clave;

        if (preg_match('/^ESCRITORIO_CANAL_KEY=.*$/m', $contenido)) {
            $contenido = preg_replace('/^ESCRITORIO_CANAL_KEY=.*$/m', $linea, $contenido);
        } else {
            $contenido = rtrim($contenido) . PHP_EOL . PHP_EOL
                . '# Clave para publicar en el canal del escritorio (ver escritorio:canal-clave)' . PHP_EOL
                . $linea . PHP_EOL;
        }

        file_put_contents($env, $contenido);

        config(['escritorio.clave' => $clave]);

        $this->line('Escrita en .env como ESCRITORIO_CANAL_KEY.');
    }
}
