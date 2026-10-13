<?php

namespace App\Console\Commands;

use App\Actions\Cumpleanos\EnviarSaludoDeCumpleanos;
use App\Clinica\FechaDelConsultorio;
use App\Cumpleanos\ConfiguracionDeCumpleanos;
use App\Cumpleanos\CumpleanosDeHoy;
use App\Cumpleanos\RegistroDeCumpleanos;
use App\Models\Evolucion;
use App\Models\NotificacionCumple;
use App\Notificaciones\ExcepcionDeEnvio;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * **El envío automático del saludo de cumpleaños** (WEB-2.9): recorre los médicos que lo tienen activo y,
 * a los que ya les llegó la hora, les manda el saludo a los pacientes que cumplen años hoy.
 *
 * Es la primera tarea **programada** del proyecto (`app/Console/Kernel.php` la registra cada 15 minutos),
 * y de ahí salen las dos decisiones que no se ven en el código de un envío manual:
 *
 *  1. **La hora es por médico**, así que el comando tiene que correr **más seguido que diario** y decidir
 *     por médico (`ConfiguracionDeCumpleanos::yaEsLaHora`). Corre cada 15 minutos y compara con `>=`, no
 *     con `==`: una corrida que se saltee —el servidor estaba reiniciándose— no puede hacer que ese
 *     paciente no reciba nunca el saludo. Que no se repita lo garantiza el **registro**, no la hora.
 *  2. **El registro se consulta justo antes de mandar**, paciente por paciente, y no al armar la lista:
 *     entre que se lee la lista y que sale el mensaje puede haber pasado otra corrida (o un envío manual
 *     del consultorio), y el único que lo ve es la tabla.
 *
 * `--fecha=AAAA-MM-DD` y `--simular` existen para poder probarlo y verificarlo sin esperar un día: la
 * fecha reemplaza al "hoy" del consultorio —y con eso se puede probar el 29 de febrero— y `--simular`
 * recorre todo el camino (configuración, hora, pacientes, registro) **sin mandar nada ni registrar
 * nada**. Las pruebas y la verificación en el navegador usan `--simular`: **nunca se mandan mensajes
 * reales**.
 *
 * En producción necesita el **cron del servidor** (`* * * * * php artisan schedule:run`): sin eso, nada
 * de esto se dispara solo.
 */
class EnviarCumpleanosCommand extends Command
{
    protected $signature = 'cumpleanos:enviar
        {--fecha= : el día a saludar, AAAA-MM-DD (por defecto, hoy en el consultorio)}
        {--simular : recorre todo sin mandar ni registrar nada}';

    protected $description = 'Manda el saludo de cumpleaños a los pacientes que cumplen años hoy (envío automático de WEB-2.9)';

    public function handle(
        CumpleanosDeHoy $cumpleanos,
        RegistroDeCumpleanos $registro,
        EnviarSaludoDeCumpleanos $accion
    ): int {
        try {
            $dia = FechaDelConsultorio::dia($this->option('fecha'));
        } catch (Throwable $e) {
            $this->error('La fecha no se entiende: usá AAAA-MM-DD.');

            return self::FAILURE;
        }

        $simular = (bool) $this->option('simular');
        $ahora = FechaDelConsultorio::ahora();

        $this->line(($simular ? '<comment>Simulación</comment> — ' : '') . 'Cumpleaños del <info>' . $dia->toDateString() . '</info> a las ' . $ahora->format('H:i') . ' (' . FechaDelConsultorio::zona() . ')');

        $evoluciones = Evolucion::query()
            ->where('enviar_cumpleanos', true)
            ->orderBy('reg_medico')
            ->get();

        if ($evoluciones->isEmpty()) {
            $this->line('No hay ningún médico con el envío automático activo.');

            return self::SUCCESS;
        }

        $enviados = 0;
        $salteados = 0;
        $fallidos = 0;

        foreach ($evoluciones as $evolucion) {
            $configuracion = ConfiguracionDeCumpleanos::de($evolucion);
            $regMedico = (string) $evolucion->reg_medico;

            // Cada médico tiene su hora: los que todavía no llegaron se saltean sin dejar rastro (la
            // corrida siguiente los va a tomar).
            if (! $configuracion->yaEsLaHora($ahora)) {
                $this->line('  ' . $regMedico . ': todavía no es la hora (' . $configuracion->hora() . ').');
                continue;
            }

            $cumpleanosDelMedico = $cumpleanos->delMedico($regMedico, $dia);

            if ($cumpleanosDelMedico->isEmpty()) {
                $this->line('  ' . $regMedico . ': hoy no cumple años nadie.');
                continue;
            }

            $this->line('  ' . $regMedico . ': ' . $cumpleanosDelMedico->count() . ' cumpleaños, por ' . $configuracion->etiquetaDelCanal() . '.');

            foreach ($cumpleanosDelMedico as $cumpleano) {
                if ($registro->yaSeLeMando($cumpleano->pacienteId(), $dia)) {
                    $salteados++;
                    $this->line('    - ' . $cumpleano->nombre() . ': ya se le mandó.');
                    continue;
                }

                if ($simular) {
                    $this->line('    - ' . $cumpleano->nombre() . ': se le mandaría (simulación).');
                    continue;
                }

                try {
                    // Paciente por paciente a propósito: que a uno le falte el dato del canal o que el
                    // proveedor rechace un mensaje no puede abortar el resto del envío. La Action ya es
                    // idempotente por el registro: si otro ya lo mandó, devuelve **lo que pasó** sin
                    // volver a mandarlo.
                    $notificacion = $accion->ejecutar($cumpleano, $regMedico, $configuracion->canal());
                } catch (ExcepcionDeEnvio|InvalidArgumentException $excepcion) {
                    // El envío ni se intentó (le falta el dato, o el proveedor no está configurado):
                    // no hay nada que registrar y el intento del día siguiente lo vuelve a intentar.
                    $fallidos++;
                    $this->warn('    - ' . $cumpleano->nombre() . ': ' . $excepcion->getMessage());
                    continue;
                }

                if (! $notificacion->wasRecentlyCreated) {
                    $salteados++;
                    $this->line('    - ' . $cumpleano->nombre() . ': ya se le mandó.');
                    continue;
                }

                if ($notificacion->estado === NotificacionCumple::ESTADO_ENVIADA) {
                    $enviados++;
                    $this->info('    - ' . $cumpleano->nombre() . ': enviado a ' . $notificacion->destino . '.');
                } else {
                    $fallidos++;
                    $this->warn('    - ' . $cumpleano->nombre() . ': el proveedor no lo aceptó (el intento quedó registrado).');
                }
            }
        }

        $this->newLine();
        $this->line($simular
            ? 'Simulación: no se mandó ni se registró nada.'
            : sprintf('Listo: %d enviados, %d ya tenían el saludo y %d sin enviar.', $enviados, $salteados, $fallidos));

        return self::SUCCESS;
    }
}
