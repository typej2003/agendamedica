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

        // Tasa oficial del BCV (2026-10-21): cada media hora, de 5 a 9 pm de Caracas, y sólo guarda si
        // cambió. El Banco Central publica de 4 a 7 pm; las 2 horas de margen cubren los timeouts. Fuera de
        // esa ventana la tasa no cambia, así que no corre (2026-10-23: antes arrancaba a las 5 am). Si
        // `configuracion_pagos.tasa_automatica` está apagada, el comando no toca nada. La app corre en UTC.
        $schedule->command('tasas:bcv')
            ->everyThirtyMinutes()
            ->timezone('America/Caracas')
            ->between('17:00', '21:00')
            ->withoutOverlapping();

        // **Red de seguridad de los índices de PecleSearch** (2026-10-24): los guardados sueltos se indexan
        // al instante y las cargas masivas reconstruyen el de pacientes al completarse; esto cubre lo que
        // entre sin eventos de Eloquent (una importación, un `UPDATE` masivo) y mantiene el vocabulario de
        // los catálogos (vademécum, diagnósticos, antecedentes, motivos, exámenes y doctores). Sin
        // argumentos reconstruye **todos** los índices. De madrugada, hora de Caracas, la de los consultorios.
        $schedule->command('pecle-search:rebuild')
            ->timezone('America/Caracas')
            ->dailyAt('03:20')
            ->withoutOverlapping();

        // Laravel Pulse (2026-10-24): `pulse:check --once` toma **una** foto del servidor (CPU, memoria,
        // disco) para la tarjeta «Servers» y termina. No se programa el demonio (`pulse:check` sin
        // `--once`): `schedule:run` lo esperaría para siempre. Si algún día se quiere muestreo continuo,
        // el demonio va bajo Supervisor, no acá. Necesita el cron del servidor (`schedule:run`), como el
        // resto de las tareas.
        $schedule->command('pulse:check --once')
            ->everyMinute()
            ->withoutOverlapping();

        // **Worker de la cola** (2026-10-24). Desde que `QUEUE_CONNECTION=database`, los jobs se encolan en
        // la tabla `jobs` y nadie los ejecuta si no hay un worker. En Plesk no hay Supervisor, así que el
        // worker se despierta acá: `--stop-when-empty` lo hace salir apenas la cola queda vacía (con la cola
        // al día tarda menos de un segundo, no demora al resto de las tareas) y `--max-time=50` lo corta
        // antes de que entre el próximo `schedule:run`. Sin solapamiento por si una corrida se cuelga: a los
        // 10 minutos vence el candado y la cola vuelve a moverse sola.
        //
        // ⚠️ Esto **depende del cron del servidor** (`schedule:run`), igual que el resto de las tareas. Si el
        // cron no está, los jobs se acumulan en `jobs` y la última actividad web nunca se actualiza: en ese
        // caso conviene dejar `QUEUE_CONNECTION=sync` hasta que el cron exista.
        $schedule->command('queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1')
            ->everyMinute()
            ->withoutOverlapping(10);
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
