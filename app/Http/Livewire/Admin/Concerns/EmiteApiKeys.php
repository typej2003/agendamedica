<?php

namespace App\Http\Livewire\Admin\Concerns;

use App\Models\Medico;
use App\Services\SyncCredencialService;
use DomainException;

/**
 * Generar la API key (credencial de sync) de un médico. Lo comparten la sección "API Keys" y el menú de cada
 * fila de "Usuarios", para que haga exactamente lo mismo en los dos sitios.
 *
 * El componente que lo use debe tener las propiedades `$modal` y `$medicoId`, y un método `cerrarModal()`, y
 * mostrar las vistas parciales `admin.partials.api-key-emitir` (con `$seleccionado`) y `admin.partials.api-key-generada`.
 *
 * El token en claro se guarda en `$tokenGenerado` SOLO hasta que se cierra el aviso: en la base queda su hash.
 */
trait EmiteApiKeys
{
    // Formulario de emisión
    public $equipo = 'CONSULTORIO-1';
    public $anios = 1;
    public $reemplazar = false;
    public $mostrarReemplazo = false;

    /** Se muestra una sola vez: ['medico','reg_medico','equipo','vence','token','revocadas']. */
    public $tokenGenerado = null;

    public function abrirEmitir(int $medicoId)
    {
        $this->resetErrorBag();
        $this->medicoId = Medico::findOrFail($medicoId)->id;
        $this->equipo = 'CONSULTORIO-1';
        $this->anios = 1;
        $this->reemplazar = false;
        $this->mostrarReemplazo = false;
        $this->modal = 'emitir';
    }

    public function emitir(SyncCredencialService $credenciales)
    {
        $this->validate([
            'equipo' => 'required|string|max:60',
            'anios'  => 'required|integer|min:1|max:3',
        ], [
            'equipo.required' => 'Escribe el nombre del equipo (por ejemplo CONSULTORIO-1).',
        ]);

        $medico = Medico::findOrFail($this->medicoId);
        $reg = $medico->regMedicoPrincipal();
        if (! $reg) {
            $this->addError('equipo', 'Este médico no tiene reg_medico: no se le puede emitir una credencial.');

            return;
        }

        try {
            $r = $credenciales->emitir($reg, $this->equipo, (int) $this->anios, false, null, (bool) $this->reemplazar);
        } catch (DomainException $e) {
            $this->mostrarReemplazo = str_starts_with($e->getMessage(), 'Ya hay');
            $this->addError('equipo', $this->mostrarReemplazo
                ? 'Ese equipo ya tiene una credencial activa. Marca "Reemplazar" para revocarla y emitir una nueva.'
                : $e->getMessage());

            return;
        }

        $this->tokenGenerado = [
            'medico'     => trim($medico->name . ' ' . $medico->lastname),
            'reg_medico' => $reg,
            'equipo'     => $r['credencial']->machine_label,
            'vence'      => $r['credencial']->expires_at->format('d/m/Y'),
            'token'      => $r['token'],
            'revocadas'  => $r['revocadas'],
        ];
        $this->cerrarModal();
    }

    public function cerrarToken()
    {
        $this->tokenGenerado = null;
    }
}
