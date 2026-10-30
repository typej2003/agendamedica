<?php

namespace App\Console\Commands;

use App\Services\BajasDeCuenta;
use Illuminate\Console\Command;

/**
 * **Ejecuta las bajas de cuenta que cumplieron sus 30 días** (2026-10-30).
 *
 * La página pública `https://doctorisimo.app/eliminar-cuenta` es el requisito de Google Play; esto es lo
 * que cumple la promesa: lo confirmado desde el correo se programa a 30 días y, vencido el plazo, la
 * cuenta se elimina.
 *
 * **Por defecto no borra nada**: sin `--ejecutar` lista lo que haría. Es a propósito —un borrado clínico
 * no se deshace— y permite mirar el estado de un servidor antes de encender el scheduler:
 *
 *   php artisan cuentas:ejecutar-bajas              qué se borraría (simulación)
 *   php artisan cuentas:ejecutar-bajas --ejecutar   borra las vencidas
 *
 * Control de acceso: sólo usuarios de consola. Lo corre el scheduler (`routes/console.php`), no una ruta.
 */
class EjecutarBajasDeCuentaCommand extends Command
{
    protected $signature = 'cuentas:ejecutar-bajas
        {--ejecutar : borra de verdad (sin esto, sólo informa)}
        {--json : salida JSON}';

    protected $description = 'Elimina las cuentas cuya baja venció sus 30 días de plazo (simula por defecto)';

    public function handle(BajasDeCuenta $bajas): int
    {
        $ejecutar = (bool) $this->option('ejecutar');
        $vencidas = $bajas->vencidas()->get();

        if ($vencidas->isEmpty()) {
            $this->info('No hay bajas vencidas para ejecutar.');

            return self::SUCCESS;
        }

        $resumenes = [];

        foreach ($vencidas as $solicitud) {
            try {
                $resumenes[] = $bajas->ejecutar($solicitud, simular: ! $ejecutar);
            } catch (\Throwable $e) {
                $this->error("No se pudo ejecutar la baja de {$solicitud->email}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($resumenes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['solicitud', 'correo', 'médicos', 'secretarías', 'registros'],
            array_map(fn (array $fila) => [
                $fila['solicitud'],
                $fila['email'],
                $fila['medicos'],
                $fila['secretarias'],
                implode(', ', $fila['registros']) ?: '—',
            ], $resumenes)
        );

        if ($ejecutar) {
            $this->info(sprintf('Bajas ejecutadas: %d.', count($resumenes)));
        } else {
            $this->warn('Simulación: no se borró nada. Vuelve a correrlo con --ejecutar.');
        }

        return self::SUCCESS;
    }
}
