<?php

namespace App\Clinica\Vistas;

/**
 * El valor de un campo de un formulario de alta/edición: lo que quedó de una validación fallida (`old()`) o,
 * si no hay, el del registro que se edita (`$this->editando`) o un valor por defecto. Lo usan los ViewModels
 * de las pantallas de configuración (sedes, consultorios, horarios, días no laborables).
 */
trait ConValoresDeFormulario
{
    public function valor(string $campo, $defecto = null)
    {
        return old($campo, $this->editando?->{$campo} ?? $defecto);
    }

    public function editando(): bool
    {
        return $this->editando !== null;
    }

    public function idEditando()
    {
        return old('id', $this->editando?->id);
    }
}
