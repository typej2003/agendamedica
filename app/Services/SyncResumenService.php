<?php

namespace App\Services;

use App\Models\MedicoRegistro;
use App\Models\SyncCarga;
use App\Models\SyncChange;
use App\Models\SyncCredential;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resumen de sincronización por médico, para la sección "API Keys" del panel: cuándo sincronizó por última vez
 * (escritorio y app móvil), cómo va su carga inicial, cuántas credenciales activas tiene y cuántos registros
 * hay en la nube.
 *
 * Todo se calcula EN LOTE para una página de médicos (una consulta por tabla, agrupada), no una por médico.
 *
 * De dónde sale cada dato:
 *  - Escritorio: lo más reciente entre `sync_credentials.last_used_at` (cada petición autenticada con la
 *    credencial), `sync_cargas.finalizada_at` (la carga inicial, que puede haberse hecho con la clave estática y
 *    no dejar rastro en las credenciales) y `sync_changes` con `source = escritorio`.
 *  - App móvil: lo más reciente entre `personal_access_tokens.last_used_at` del usuario del médico y
 *    `sync_changes` con `source = mobile`. Ojo: cerrar sesión o resetear la clave borra los tokens, así que la
 *    última actividad puede verse más vieja de lo real hasta que el app vuelva a conectarse.
 *  - Conteos: pacientes (`medico_pacientes`) e historias (`historias`), los dos indexados por médico.
 */
class SyncResumenService
{
    /**
     * @param  Collection  $medicos  colección de `Medico`
     * @return array<int, array{
     *     regs: string[],
     *     credenciales: Collection,
     *     activas: int,
     *     carga: ?SyncCarga,
     *     ultima_escritorio: ?Carbon,
     *     ultima_app: ?Carbon,
     *     pacientes: int,
     *     historias: int
     * }> indexado por `medicos.id`
     */
    public function resumir(Collection $medicos): array
    {
        if ($medicos->isEmpty()) {
            return [];
        }

        $ids = $medicos->pluck('id')->all();
        $userIds = $medicos->pluck('user_id')->filter()->all();

        // Un médico puede tener varios registros (`medico_registros`) además de `medicos.reg_medico`.
        $registros = MedicoRegistro::whereIn('medico_id', $ids)->get()->groupBy('medico_id');
        $regsPorMedico = [];
        foreach ($medicos as $medico) {
            $regsPorMedico[$medico->id] = collect([$medico->reg_medico])
                ->merge(($registros[$medico->id] ?? collect())->pluck('reg_medico'))
                ->filter(fn ($r) => $r !== null && $r !== '')
                ->unique()->values()->all();
        }
        $todosLosRegs = collect($regsPorMedico)->flatten()->unique()->values()->all();

        $credenciales = $todosLosRegs
            ? SyncCredential::whereIn('reg_medico', $todosLosRegs)->orderByDesc('created_at')->get()->groupBy('reg_medico')
            : collect();
        $cargas = $todosLosRegs ? SyncCarga::whereIn('reg_medico', $todosLosRegs)->get()->keyBy('reg_medico') : collect();

        $cambios = $todosLosRegs
            ? SyncChange::whereIn('reg_medico', $todosLosRegs)
                ->selectRaw('reg_medico, source, max(created_at) as ultimo')
                ->groupBy('reg_medico', 'source')->get()
            : collect();

        $sesiones = $userIds
            ? DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)
                ->selectRaw('tokenable_id, max(last_used_at) as ultimo')->groupBy('tokenable_id')
                ->pluck('ultimo', 'tokenable_id')
            : collect();

        $pacientes = DB::table('medico_pacientes')->whereIn('medico_id', $ids)
            ->selectRaw('medico_id, count(*) as n')->groupBy('medico_id')->pluck('n', 'medico_id');
        $historias = $todosLosRegs
            ? DB::table('historias')->whereIn('reg_medico', $todosLosRegs)
                ->selectRaw('reg_medico, count(*) as n')->groupBy('reg_medico')->pluck('n', 'reg_medico')
            : collect();

        $resumen = [];
        foreach ($medicos as $medico) {
            $regs = $regsPorMedico[$medico->id];

            $creds = collect($regs)->flatMap(fn ($r) => $credenciales[$r] ?? collect())->sortByDesc('created_at')->values();
            $cargasDelMedico = collect($regs)->map(fn ($r) => $cargas[$r] ?? null)->filter();
            // Si hay varias, la completa manda; si no, la más reciente.
            $carga = $cargasDelMedico->first(fn ($c) => $c->estado === SyncCarga::COMPLETA) ?? $cargasDelMedico->sortByDesc('id')->first();

            $deCambios = fn (string $source) => collect($cambios)
                ->filter(fn ($c) => in_array($c->reg_medico, $regs, true) && $c->source === $source)
                ->map(fn ($c) => $this->fecha($c->ultimo));

            $resumen[$medico->id] = [
                'regs'              => $regs,
                'credenciales'      => $creds,
                'activas'           => $creds->filter(fn (SyncCredential $c) => $c->estaActiva())->count(),
                'carga'             => $carga,
                'ultima_escritorio' => $this->masReciente(
                    $creds->pluck('last_used_at'),
                    $cargasDelMedico->pluck('finalizada_at'),
                    $deCambios('escritorio')
                ),
                'ultima_app'        => $this->masReciente(
                    collect([$this->fecha($sesiones[$medico->user_id] ?? null)]),
                    $deCambios('mobile')
                ),
                'pacientes'         => (int) ($pacientes[$medico->id] ?? 0),
                'historias'         => (int) collect($regs)->sum(fn ($r) => $historias[$r] ?? 0),
            ];
        }

        return $resumen;
    }

    private function fecha($valor): ?Carbon
    {
        return $valor ? Carbon::parse($valor) : null;
    }

    /** La fecha más reciente de varias colecciones de fechas (ignora los nulos). */
    private function masReciente(Collection ...$colecciones): ?Carbon
    {
        $fechas = collect($colecciones)->flatten()->filter()->map(fn ($f) => $f instanceof Carbon ? $f : Carbon::parse($f));

        return $fechas->isEmpty() ? null : $fechas->sort()->last();
    }
}
