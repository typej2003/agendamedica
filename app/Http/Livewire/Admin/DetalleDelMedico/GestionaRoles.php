<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Services\CuentaService;
use Illuminate\Validation\ValidationException;

/**
 * **Roles de la cuenta del médico**, en su detalle. Es el mismo formulario y el mismo servicio que
 * Usuarios › fila › Roles (`CuentaService::asignarRoles`), así que las protecciones (Root sólo por un Root,
 * no quedar sin administrador) son idénticas.
 */
trait GestionaRoles
{
    /** Roles marcados en el formulario. */
    public $rolesSeleccionados = [];

    /** Carga los roles actuales al abrir la pestaña. */
    public function cargarRoles(): void
    {
        $this->rolesSeleccionados = $this->medico->user
            ? $this->medico->user->roles()->pluck('name')->all()
            : [];
    }

    public function guardarRoles(CuentaService $cuentas): void
    {
        $cuenta = $this->medico->user;

        if ($cuenta === null) {
            $this->addError('roles', 'Este médico no tiene cuenta de acceso: no hay a quién asignarle roles.');

            return;
        }

        try {
            $cuentas->asignarRoles($cuenta, (array) $this->rolesSeleccionados, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo === 'roles' ? 'roles' : 'cuenta', $mensajes[0]);
            }

            return;
        }

        $this->medico = $this->medico->fresh(['user.roles']);
        session()->flash('message', 'Roles actualizados.');
    }
}
