<?php

namespace App\Console\Commands;

use App\Models\SyncCarga;
use App\Models\SyncTraslado;
use App\Sync\Escritorio\Traslados;
use Illuminate\Console\Command;
use Throwable;

/**
 * Los registros que la carga inicial **movió** para que el escritorio mandara (ver
 * `App\Sync\Escritorio\Traslados`).
 *
 *   php artisan sync:traslados                              (lista todo lo que hay)
 *   php artisan sync:traslados gineco-00001 --pendientes    (lo que espera número)
 *   php artisan sync:traslados gineco-00001 --reacomodar --confirmar
 *
 * Para qué sirve:
 *  - **ver** qué se movió y con qué números: la consulta 1 de la web quedó en la 4, por ejemplo.
 *  - **reintentar** la pasada final cuando `finalizar` se cortó (la carga quedó en curso y la fila sigue
 *    viva en el rango reservado; nada se perdió).
 *  - **revisar** los catálogos reemplazados (`a_revisar`): con `--copia` se imprime la fila tal como estaba
 *    para volver a crearla si el médico la quiere.
 *
 * Sin `--confirmar`, `--reacomodar` sólo simula: nada escribe.
 */
class SyncTrasladosCommand extends Command
{
    protected $signature = 'sync:traslados
        {reg_medico? : reg_medico del consultorio (sin esto, todos los que tengan traslados)}
        {--pendientes : sólo los que todavía esperan su número final}
        {--reacomodar : reintenta la pasada final (pide --confirmar para escribir)}
        {--confirmar : sin esto, --reacomodar sólo cuenta lo que haría}
        {--copia : imprime la copia de cada fila movida}';

    protected $description = 'Lista y reacomoda los registros que la carga inicial apartó para que mande el escritorio';

    public function handle(): int
    {
        $regMedico = trim((string) $this->argument('reg_medico'));

        $cargas = SyncCarga::query()
            ->when($regMedico !== '', fn ($q) => $q->where('reg_medico', $regMedico))
            ->whereIn('id', SyncTraslado::query()->select('sync_carga_id')->distinct())
            ->orderBy('reg_medico')
            ->get();

        if ($cargas->isEmpty()) {
            $this->info('No hay traslados de carga inicial' . ($regMedico !== '' ? " para {$regMedico}" : '') . '.');

            return self::SUCCESS;
        }

        foreach ($cargas as $carga) {
            $this->listarCarga($carga);
            $this->reacomodarCarga($carga);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function listarCarga(SyncCarga $carga): void
    {
        $traslados = SyncTraslado::where('sync_carga_id', $carga->id)
            ->when($this->option('pendientes'), fn ($q) => $q->where('estado', SyncTraslado::PENDIENTE))
            ->orderByRaw("CASE nivel WHEN 'historia' THEN 0 WHEN 'consulta' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get();

        $this->info("Carga de {$carga->reg_medico} ({$carga->estado}, modo " . ($carga->modo ?: 'limpia') . ')');

        if ($traslados->isEmpty()) {
            $this->line('  Sin traslados que mostrar.');

            return;
        }

        $this->table(
            ['id', 'tabla', 'nivel', 'motivo', 'original', 'temporal', 'final', 'estado'],
            $traslados->map(fn (SyncTraslado $t) => [
                $t->id,
                $t->tabla,
                $t->nivel,
                $t->motivo,
                $t->clave_original ?? '-',
                $t->clave_temporal ?? '-',
                $t->clave_final ?? '-',
                $t->estado,
            ])->all()
        );

        if ($this->option('copia')) {
            foreach ($traslados as $traslado) {
                $this->line("  #{$traslado->id} {$traslado->tabla}: " . json_encode(
                    $traslado->copiaDecodificada(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
            }
        }

        $pendientes = $traslados->where('estado', SyncTraslado::PENDIENTE)->count();
        $aRevisar = $traslados->where('estado', SyncTraslado::A_REVISAR)->count();
        $this->line("  {$traslados->count()} traslados · {$pendientes} pendientes · {$aRevisar} a revisar");
    }

    private function reacomodarCarga(SyncCarga $carga): void
    {
        if (! $this->option('reacomodar')) {
            return;
        }

        $pendientes = SyncTraslado::where('sync_carga_id', $carga->id)
            ->where('estado', SyncTraslado::PENDIENTE)->count();

        if ($pendientes === 0) {
            $this->line('  No hay nada pendiente de reacomodar.');

            return;
        }

        if (! $this->option('confirmar')) {
            $this->warn("  Simulación: se reacomodarían {$pendientes} registros. Repite con --confirmar.");

            return;
        }

        try {
            $movidos = app(Traslados::class)->reacomodar($carga);
            $this->info("  Reacomodados {$movidos} registros.");

            // La carga se cerró sin este paso (finalizar se cortó): se completa ahora.
            if ($carga->estado !== SyncCarga::COMPLETA
                && ! SyncTraslado::where('sync_carga_id', $carga->id)->where('estado', SyncTraslado::PENDIENTE)->exists()) {
                $this->warn('  La carga sigue en curso: hay que volver a llamar a "Finalizar" desde el escritorio.');
            }
        } catch (Throwable $e) {
            $this->error('  No se pudo reacomodar: ' . $e->getMessage());
        }
    }
}
