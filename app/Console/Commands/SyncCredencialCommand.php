<?php

namespace App\Console\Commands;

use App\Models\Medico;
use App\Models\SyncCredential;
use Illuminate\Console\Command;

/**
 * Emite, lista y revoca credenciales de sincronización por equipo.
 *
 * La emisión es por consola a propósito: NO hay endpoint público de login. Un endpoint de login
 * obligaría a que la contraseña del médico circule por la red desde cada PC, que es justo el
 * secreto de bootstrap que se quiere evitar. Esto se corre una vez por equipo (y una vez al año,
 * cuando la credencial vence).
 *
 *   php artisan sync:credencial emitir --reg-medico=gineco-00001 --equipo=CONSULTORIO-1
 *   php artisan sync:credencial listar
 *   php artisan sync:credencial revocar --equipo=CONSULTORIO-1
 */
class SyncCredencialCommand extends Command
{
    protected $signature = 'sync:credencial
        {accion : emitir | listar | revocar}
        {--reg-medico= : reg_medico del médico (obligatorio en "emitir")}
        {--equipo= : etiqueta del equipo, ej. CONSULTORIO-1 (obligatorio en "emitir" y "revocar")}
        {--anios=1 : vigencia en años (decisión del proyecto: 1 año)}
        {--atar-equipo : exige que el hostname coincida en cada sync (si no se pasa, solo se registra)}
        {--host= : hostname a registrar/atar; si se omite, queda vacío}
        {--id= : id de la credencial a revocar (alternativa a --equipo)}
        {--si : emitir aunque el reg_medico no exista, sin preguntar (instalación desatendida)}
        {--forzar : en "emitir", revoca las credenciales activas previas del mismo equipo}';

    protected $description = 'Gestiona las credenciales de sincronización por equipo del escritorio PowerBuilder';

    public function handle(): int
    {
        return match ($this->argument('accion')) {
            'emitir'  => $this->emitir(),
            'listar'  => $this->listar(),
            'revocar' => $this->revocar(),
            default   => $this->accionInvalida(),
        };
    }

    private function emitir(): int
    {
        $regMedico = (string) $this->option('reg-medico');
        $equipo    = (string) $this->option('equipo');
        $anios     = (int) $this->option('anios');

        if ($regMedico === '' || $equipo === '') {
            $this->error('Faltan --reg-medico y/o --equipo.');
            return self::FAILURE;
        }
        if ($anios < 1 || $anios > 10) {
            $this->error('--anios debe estar entre 1 y 10.');
            return self::FAILURE;
        }

        // Avisar (no bloquear) si el reg_medico no existe todavía: en desarrollo puede pasar,
        // pero en producción casi siempre significa un error de tipeo.
        //
        // Prioridad: el flag explícito manda, después se pregunta, y si no hay terminal NO se
        // bloquea (una instalación desatendida quedaría colgada esperando input para siempre).
        if (! Medico::where('reg_medico', $regMedico)->exists()) {
            $this->warn("Ojo: no existe ningún médico con reg_medico='{$regMedico}' en esta base.");

            $confirmado = match (true) {
                (bool) $this->option('si')        => true,
                $this->input->isInteractive()     => $this->confirm('¿Emitir igual?', false),
                default                            => false,
            };

            if (! $confirmado) {
                $this->error($this->input->isInteractive()
                    ? 'Cancelado.'
                    : 'Sin confirmación: agregá --si para emitir credenciales de un médico inexistente.');
                return self::FAILURE;
            }
        }

        $host = (string) ($this->option('host') ?? '');
        $atar = (bool) $this->option('atar-equipo');
        if ($atar && $host === '') {
            $this->error('--atar-equipo requiere --host (si no, la credencial no podría usarse nunca).');
            return self::FAILURE;
        }

        $previas = SyncCredential::where('reg_medico', $regMedico)
            ->where('machine_label', $equipo)
            ->whereNull('revoked_at')
            ->get();

        if ($previas->isNotEmpty()) {
            if (! $this->option('forzar')) {
                $this->error("Ya hay {$previas->count()} credencial(es) activa(s) para '{$equipo}'. "
                    . 'Usá --forzar para revocarlas y emitir una nueva.');
                return self::FAILURE;
            }
            foreach ($previas as $p) {
                $p->forceFill(['revoked_at' => now()])->save();
            }
            $this->warn("Revocadas {$previas->count()} credencial(es) previas de '{$equipo}'.");
        }

        $token = SyncCredential::generarToken();
        $cred  = SyncCredential::create([
            'reg_medico'       => $regMedico,
            'machine_label'    => $equipo,
            'machine_host'     => $host !== '' ? $host : null,
            'bound_to_machine' => $atar,
            'token_hash'       => SyncCredential::hashToken($token),
            'expires_at'       => now()->addYears($anios),
        ]);

        $this->newLine();
        $this->info('Credencial emitida.');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['id',          $cred->id],
                ['reg_medico',  $cred->reg_medico],
                ['equipo',      $cred->machine_label],
                ['host',        $cred->machine_host ?: '(no registrado)'],
                ['atada al equipo', $cred->bound_to_machine ? 'sí' : 'no (solo se registra)'],
                ['vence',       $cred->expires_at->format('d/m/Y H:i')],
            ]
        );

        $this->newLine();
        $this->line('Guardá este token en el equipo — NO se vuelve a mostrar:');
        $this->line('');
        $this->line('    ' . $token);
        $this->line('');
        $this->comment('En el equipo, escribirlo en: C:\\MICONSULTAGI\\bridge\\secrets\\sync-token.txt');
        $this->comment('(una sola línea, sin espacios; el puente lo manda como Authorization: Bearer).');

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $creds = SyncCredential::orderBy('reg_medico')->orderBy('machine_label')->get();

        if ($creds->isEmpty()) {
            $this->info('No hay credenciales emitidas.');
            return self::SUCCESS;
        }

        $filas = $creds->map(function (SyncCredential $c) {
            $estado = $c->estaRevocada()
                ? 'REVOCADA ' . $c->revoked_at->format('d/m/Y')
                : ($c->estaVencida() ? 'VENCIDA ' . $c->expires_at->format('d/m/Y') : 'activa');

            return [
                $c->id,
                $c->reg_medico,
                $c->machine_label,
                $c->machine_host ?: '-',
                $c->expires_at ? $c->expires_at->format('d/m/Y') : '-',
                $c->diasParaVencer(),
                $estado,
                $c->last_used_at ? $c->last_used_at->format('d/m/Y H:i') : 'nunca',
            ];
        });

        $this->table(
            ['id', 'reg_medico', 'equipo', 'host', 'vence', 'días', 'estado', 'último uso'],
            $filas
        );

        $porVencer = $creds->filter(fn ($c) => $c->estaActiva() && $c->diasParaVencer() !== null && $c->diasParaVencer() <= 30);
        if ($porVencer->isNotEmpty()) {
            $this->newLine();
            $this->warn("⚠ {$porVencer->count()} credencial(es) vencen en 30 días o menos:");
            foreach ($porVencer as $c) {
                $this->line("   - {$c->machine_label} ({$c->reg_medico}): {$c->diasParaVencer()} días");
            }
        }

        return self::SUCCESS;
    }

    private function revocar(): int
    {
        $equipo = (string) ($this->option('equipo') ?? '');
        $id     = $this->option('id');

        if ($equipo === '' && ! $id) {
            $this->error('Indicá --equipo=... o --id=...');
            return self::FAILURE;
        }

        $query = SyncCredential::query()->whereNull('revoked_at');
        $id ? $query->where('id', (int) $id) : $query->where('machine_label', $equipo);

        $creds = $query->get();

        if ($creds->isEmpty()) {
            $this->info('No hay credenciales activas que coincidan.');
            return self::SUCCESS;
        }

        foreach ($creds as $c) {
            $c->forceFill(['revoked_at' => now()])->save();
            $this->info("Revocada id={$c->id} equipo='{$c->machine_label}' ({$c->reg_medico}).");
        }

        $this->comment('Ese equipo ya no puede subir lotes. Emití una credencial nueva cuando corresponda.');

        return self::SUCCESS;
    }

    private function accionInvalida(): int
    {
        $this->error('Acción inválida. Usá: emitir | listar | revocar');
        return self::FAILURE;
    }
}
