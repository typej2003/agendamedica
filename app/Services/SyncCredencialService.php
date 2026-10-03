<?php

namespace App\Services;

use App\Models\SyncCredential;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emisión y revocación de credenciales de sincronización por equipo (la "API key" del escritorio PowerBuilder).
 *
 * Se extrajo de `SyncCredencialCommand` (`php artisan sync:credencial`) para que el comando y la sección
 * "API Keys" del panel hagan exactamente lo mismo sin duplicar la lógica.
 *
 * El token en claro solo existe en el valor de retorno de `emitir()`: en la base se guarda su SHA-256
 * (`SyncCredential::hashToken`) y no se puede volver a obtener.
 */
class SyncCredencialService
{
    public const ANIOS_MIN = 1;
    public const ANIOS_MAX = 10;

    /** Credenciales no revocadas del equipo (incluye las vencidas: siguen figurando hasta revocarlas). */
    public function previasDelEquipo(string $regMedico, string $equipo): Collection
    {
        return SyncCredential::where('reg_medico', $regMedico)
            ->where('machine_label', $equipo)
            ->whereNull('revoked_at')
            ->get();
    }

    /**
     * @return array{credencial: SyncCredential, token: string, revocadas: int}
     *
     * @throws DomainException si la vigencia o la atadura son inválidas, o si el equipo ya tiene una
     *                         credencial activa y no se pidió reemplazarla.
     */
    public function emitir(
        string $regMedico,
        string $equipo,
        int $anios = 1,
        bool $atarEquipo = false,
        ?string $host = null,
        bool $revocarPrevias = false
    ): array {
        $regMedico = trim($regMedico);
        $equipo = trim($equipo);
        $host = $host !== null && trim($host) !== '' ? trim($host) : null;

        if ($regMedico === '' || $equipo === '') {
            throw new DomainException('Faltan el médico y/o el nombre del equipo.');
        }
        if ($anios < self::ANIOS_MIN || $anios > self::ANIOS_MAX) {
            throw new DomainException('La vigencia debe estar entre ' . self::ANIOS_MIN . ' y ' . self::ANIOS_MAX . ' años.');
        }
        if ($atarEquipo && $host === null) {
            throw new DomainException('Atar la credencial al equipo requiere el nombre del host (si no, no podría usarse nunca).');
        }

        $previas = $this->previasDelEquipo($regMedico, $equipo);
        if ($previas->isNotEmpty() && ! $revocarPrevias) {
            throw new DomainException("Ya hay {$previas->count()} credencial(es) activa(s) para '{$equipo}'.");
        }

        return DB::transaction(function () use ($regMedico, $equipo, $anios, $atarEquipo, $host, $previas) {
            foreach ($previas as $previa) {
                $previa->forceFill(['revoked_at' => now()])->save();
            }

            $token = SyncCredential::generarToken();
            $credencial = SyncCredential::create([
                'reg_medico'       => $regMedico,
                'machine_label'    => $equipo,
                'machine_host'     => $host,
                'bound_to_machine' => $atarEquipo,
                'token_hash'       => SyncCredential::hashToken($token),
                'expires_at'       => now()->addYears($anios),
            ]);

            return ['credencial' => $credencial, 'token' => $token, 'revocadas' => $previas->count()];
        });
    }

    /** Ese equipo deja de poder subir lotes. Idempotente. */
    public function revocar(SyncCredential $credencial): void
    {
        if (! $credencial->estaRevocada()) {
            $credencial->forceFill(['revoked_at' => now()])->save();
        }
    }
}
