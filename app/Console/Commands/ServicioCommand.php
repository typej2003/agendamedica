<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Services\ServicioService;
use DomainException;
use Illuminate\Console\Command;

/**
 * Operar los servicios por consola (mientras el panel no tenga su pantalla):
 *
 *   php artisan servicio ver gineco-00001
 *   php artisan servicio renovar gineco-00001 anual --monto=199.99 --nota="Pago por transferencia"
 *   php artisan servicio planes
 */
class ServicioCommand extends Command
{
    protected $signature = 'servicio {accion : ver | renovar | planes} {reg_medico? : el reg_medico} {plan? : código del plan (renovar)}
                            {--monto= : lo cobrado en USD; por defecto el precio del plan}
                            {--nota= : una nota para el historial}';

    protected $description = 'Ver o renovar el servicio contratado por un reg_medico, y listar los planes';

    public function handle(ServicioService $servicio): int
    {
        switch ($this->argument('accion')) {
            case 'planes':
                return $this->planes();
            case 'ver':
                return $this->ver($servicio);
            case 'renovar':
                return $this->renovar($servicio);
        }

        $this->error('Acciones: ver, renovar, planes.');

        return self::FAILURE;
    }

    private function planes(): int
    {
        $this->table(
            ['código', 'nombre', 'frecuencia', 'USD', 'tachado', 'default', 'visible', 'restricciones'],
            Plan::orderBy('orden')->orderBy('id')->get()->map(fn (Plan $p) => [
                $p->codigo, $p->nombre, $p->frecuencia, $p->precio_usd, $p->precio_tachado_usd ?? '-',
                $p->es_default ? 'sí' : '', $p->visible ? 'sí' : '', json_encode($p->restricciones),
            ])
        );

        return self::SUCCESS;
    }

    private function ver(ServicioService $servicio): int
    {
        $reg = $this->regMedico();
        if ($reg === null) {
            return self::FAILURE;
        }

        $e = $servicio->estado($reg);
        $this->line("{$reg}: {$e['estado']}" . ($e['vence_el'] ? " (vence {$e['vence_el']}, {$e['dias_restantes']} días)" : ''));
        $this->table(
            ['id', 'plan', 'origen', 'inicia', 'vence', 'USD', 'estado'],
            RegMedicoServicio::where('reg_medico', $reg)->orderByDesc('vence_el')->get()->map(fn ($s) => [
                $s->id, $s->plan_nombre, $s->origen, $s->inicia_el->toDateString(), $s->vence_el->toDateString(), $s->monto_usd, $s->estado,
            ])
        );

        return self::SUCCESS;
    }

    private function renovar(ServicioService $servicio): int
    {
        $reg = $this->regMedico();
        if ($reg === null) {
            return self::FAILURE;
        }
        $plan = Plan::porCodigo((string) $this->argument('plan'));
        if (! $plan) {
            $this->error('Plan inexistente. Los códigos se ven con: php artisan servicio planes');

            return self::FAILURE;
        }
        $monto = $this->option('monto');
        if ($monto !== null && ! is_numeric($monto)) {
            $this->error('--monto tiene que ser un número.');

            return self::FAILURE;
        }

        try {
            $s = $servicio->renovar($reg, $plan, $monto === null ? null : (float) $monto, $this->option('nota'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$reg}: {$s->plan_nombre} del {$s->inicia_el->toDateString()} al {$s->vence_el->toDateString()}.");

        return self::SUCCESS;
    }

    private function regMedico(): ?string
    {
        $reg = trim((string) $this->argument('reg_medico'));
        if ($reg === '') {
            $this->error('Falta el reg_medico.');

            return null;
        }

        return $reg;
    }
}
