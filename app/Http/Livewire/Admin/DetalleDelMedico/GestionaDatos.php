<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Services\CuentaService;
use Illuminate\Validation\ValidationException;

/**
 * **Editar los datos del médico desde su detalle**, sin volver a la lista: los mismos campos y las mismas
 * reglas que el diálogo de `Admin\Cuentas` (la lógica sigue en `CuentaService`).
 */
trait GestionaDatos
{
    /** ¿El formulario de edición está abierto dentro de la pestaña «Datos»? */
    public $editando = false;

    // Mismos nombres de propiedad que `Admin\Cuentas`: el formulario es el mismo componente Blade.
    public $name = '';
    public $lastname = '';
    public $prefix = '';
    public $email = '';
    public $phone = '';
    public $license_number = '';
    public $reg_medico = '';
    public $es_centro = false;
    public $formato_reportes = '';

    /** Abre el formulario en la pestaña «Datos» (el botón «Editar» de la cabecera). */
    public function abrirEdicion(): void
    {
        $this->pestana = 'datos';
        $this->resetErrorBag();
        $this->cargarDatosDelMedico();
        $this->editando = true;
    }

    public function cancelarEdicion(): void
    {
        $this->editando = false;
        $this->resetErrorBag();
    }

    public function guardarEdicion(CuentaService $cuentas): void
    {
        $this->validate([
            'name'           => 'required|string|max:255',
            'lastname'       => 'required|string|max:255',
            'prefix'         => 'nullable|string|max:20',
            // Un centro médico no inicia sesión: su correo es opcional.
            'email'          => ($this->es_centro ? 'nullable' : 'required') . '|email|max:255',
            'phone'          => 'nullable|string|max:50',
            'license_number' => 'nullable|string|max:100',
            'formato_reportes' => 'nullable|in:' . implode(',', CuentaService::FORMATOS_DE_REPORTE),
        ]);

        try {
            $cuentas->actualizarMedico($this->medico, [
                'name'           => $this->name,
                'lastname'       => $this->lastname,
                'prefix'         => $this->prefix,
                'email'          => $this->email,
                'phone'          => $this->phone,
                'license_number' => $this->license_number,
            ]);

            $cuentas->configurarCentro(
                $this->medico,
                (bool) $this->es_centro,
                $this->formato_reportes ?: null,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo, $mensajes[0]);
            }

            return;
        }

        $this->medico = $this->medico->fresh(['user.roles', 'registros']);
        $this->editando = false;
        session()->flash('message', 'Datos actualizados.');
    }

    /** Copia a las propiedades del formulario lo que tiene la ficha. */
    private function cargarDatosDelMedico(): void
    {
        $this->name = (string) $this->medico->name;
        $this->lastname = (string) $this->medico->lastname;
        $this->prefix = (string) $this->medico->prefix;
        $this->email = (string) $this->medico->email;
        $this->phone = (string) $this->medico->phone;
        $this->license_number = (string) $this->medico->license_number;
        // Sólo para mostrarlo: no se edita (es la llave de todos sus datos clínicos en la nube).
        $this->reg_medico = (string) $this->medico->reg_medico;
        $this->es_centro = (bool) $this->medico->es_centro;
        $this->formato_reportes = (string) $this->medico->formato_reportes;
    }
}
