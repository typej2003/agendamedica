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
 * **El registro se elige** (2026-11-05): un médico puede tener varios `reg_medico` —el suyo y las sedes que le
 * asignaron— y la credencial queda atada a **uno** (el consultorio al que esa PC sube sus datos). Antes se
 * tomaba siempre el principal, así que el segundo PC de una sede subía al tenant de la primera sin avisar.
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

    /** El `reg_medico` (consultorio) al que se ata la credencial. Uno de los registros del médico. */
    public $registro = '';

    /** Se muestra una sola vez: ['medico','reg_medico','equipo','vence','token','revocadas']. */
    public $tokenGenerado = null;

    public function abrirEmitir(int $medicoId)
    {
        $this->resetErrorBag();
        $medico = Medico::findOrFail($medicoId);
        $this->medicoId = $medico->id;
        $this->equipo = 'CONSULTORIO-1';
        $this->anios = 1;
        $this->reemplazar = false;
        $this->mostrarReemplazo = false;
        // Por defecto el consultorio propio; si tiene varios, el desplegable del diálogo deja elegir la sede.
        $this->registro = (string) ($medico->regMedicoPrincipal() ?? '');
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
        $registros = $medico->registrosDeTrabajo();

        if ($registros === []) {
            $this->addError('registro', 'Este médico no tiene reg_medico: no se le puede emitir una credencial.');

            return;
        }

        // Sólo un registro del médico: nunca uno ajeno, ni siquiera adivinándolo en el payload.
        if (! in_array($this->registro, $registros, true)) {
            $this->addError('registro', 'Elige uno de los consultorios de este médico.');

            return;
        }

        $reg = $this->registro;

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
            'vence'      => $r['credencial']->expires_at->format(\App\Support\Regionalizacion::formatoActual()),
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
