@props([
    'roles',
    'accionGuardar',
    'accionCancelar' => 'cerrarModal',
    'textoCancelar' => 'Cancelar',
    'inline' => false,
])

{{-- Formulario de roles de una cuenta (el mismo en Usuarios › fila › Roles y en el detalle del médico).
     Usa las propiedades `rolesSeleccionados` del componente Livewire que lo incluye y su método de guardado. --}}
@php
    $descripciones = [
        'Root'          => 'Control total. Solo otro Root puede darlo o quitarlo.',
        'Administrador' => 'Ve y usa las secciones Usuarios y API Keys.',
        'Medico'        => 'Usa la agenda y el app como médico.',
        'Secretaria'    => 'Asistente de un consultorio.',
        'Paciente'      => 'Acceso de paciente.',
        'Representante' => 'Representante de un paciente.',
    ];
@endphp

<form wire:submit.prevent="{{ $accionGuardar }}">
    <div class="{{ $inline ? 'card-body' : 'modal-body' }}">
        @error('roles') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
        @error('cuenta') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror
        <p class="text-muted small">Marca todos los que correspondan: una cuenta puede tener varios (por ejemplo, médico y administrador).</p>

        @foreach ($roles as $rol)
            <div class="form-check mb-2" wire:key="rol-{{ $rol }}">
                <input class="form-check-input" type="checkbox" id="rol-{{ $rol }}" value="{{ $rol }}" wire:model="rolesSeleccionados">
                <label class="form-check-label" for="rol-{{ $rol }}">
                    <span class="fw-semibold">{{ $rol }}</span>
                    @if (isset($descripciones[$rol]))
                        <br><small class="text-muted">{{ $descripciones[$rol] }}</small>
                    @endif
                </label>
            </div>
        @endforeach
    </div>
    <div class="{{ $inline ? 'd-flex justify-content-end gap-2 mt-3' : 'modal-footer' }}">
        <button type="button" class="btn btn-secondary" wire:click="{{ $accionCancelar }}">{{ $textoCancelar }}</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="{{ $accionGuardar }}">
            Guardar roles
        </button>
    </div>
</form>
