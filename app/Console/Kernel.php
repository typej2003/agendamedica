<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Saludo de cumpleaños automático (WEB-2.9). **Cada 15 minutos y no una vez al día** porque la
        // hora del envío es por médico (`evolucion.hora_cumpleanos`): el comando tiene que despertarse
        // más seguido que la granularidad de la configuración y decidir él a quién le llegó la hora. Que
        // no se mande dos veces no lo garantiza el horario, lo garantiza el registro del envío
        // (`notificaciones_cumple`), así que correr de más es inofensivo.
        //
        // ⚠️ En producción esto **necesita el cron del servidor** (`* * * * * php artisan schedule:run`);
        // sin él, el saludo automático no sale nunca. Anotado en ROADMAP.md §5.
        $schedule->command('cumpleanos:enviar')
            ->everyFifteenMinutes()
            // Sin solapamiento: una corrida lenta no puede pisar a la siguiente (el registro igual lo
            // cubre, pero así el log del comando se lee).
            ->withoutOverlapping();

        // Conciliación de pagos (2026-10-21): expira los pendientes vencidos y consulta a SyPago los que
        // quedaron en proceso. El webhook es el camino principal; esto es la red de seguridad. En
        // producción también necesita el cron del servidor (la misma nota que el cumpleaños).
        $schedule->command('pagos:conciliar')
            ->everyMinute()
            ->withoutOverlapping();

        // Tasa oficial del BCV (2026-10-21): cada media hora en la ventana en la que el Banco Central
        // publica, y sólo guarda si cambió. Si `configuracion_pagos.tasa_origen` está en `manual`, el
        // comando no toca nada. Hora de Caracas: la app corre en UTC.
        $schedule->command('tasas:bcv')
            ->everyThirtyMinutes()
            ->timezone('America/Caracas')
            ->between('05:00', '21:00')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
