<?php

namespace App\Http\Livewire\Admin\Sedes;

use App\Models\Medico;
use App\Services\Admin\SedesDelMedico;
use Illuminate\Validation\ValidationException;

/**
 * Lo común a los tres niveles de «Sedes del médico» en el panel (sedes → consultorios → horarios).
 *
 * Como el resto del panel (`Cuentas`, `Especialidades`…), el permiso se vuelve a comprobar **en cada acción**
 * de Livewire (`hydrate()`): la ruta sólo protege la carga de la página.
 */
trait ConfiguraUnMedico
{
    public Medico $medico;

    public function hydrate()
    {
        $this->autorizar();
    }

    protected function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    protected function lector(): SedesDelMedico
    {
        return app(SedesDelMedico::class);
    }

    /** El tenant del médico; sin `reg_medico` la ficha no tiene de quién leer ni a quién escribirle. */
    protected function tenantOFallar(): string
    {
        $tenant = $this->lector()->tenant($this->medico);
        abort_if($tenant === null, 404);

        return $tenant;
    }

    protected function nombreDelMedico(): string
    {
        return $this->medico->nombre_mostrar;
    }

    /** Lleva los errores de una Action (`ValidationException`) al formulario abierto. */
    protected function mostrarErrores(ValidationException $e, array $renombrar = []): void
    {
        foreach ($e->errors() as $campo => $mensajes) {
            $this->addError($renombrar[$campo] ?? $campo, $mensajes[0]);
        }
    }
}
