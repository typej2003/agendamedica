@props([
    'formatos' => [],
    'accionGuardar',
    'accionCancelar',
    'textoCancelar' => 'Cancelar',
    'textoGuardar' => 'Guardar cambios',
    'inline' => false,
])

{{-- Formulario de datos del médico (nombre, correo, teléfono, licencia, centro y formato de sus reportes).
     Es el mismo en el diálogo de Usuarios y en la edición dentro del detalle del médico. Usa las propiedades
     `name`, `lastname`, `prefix`, `email`, `phone`, `license_number`, `reg_medico`, `es_centro` y
     `formato_reportes` del componente Livewire que lo incluye. --}}
<form wire:submit.prevent="{{ $accionGuardar }}">
    <div class="{{ $inline ? 'card-body' : 'modal-body' }}">
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label" for="datos-prefix">Prefijo</label>
                <input type="text" class="form-control" id="datos-prefix" maxlength="20" placeholder="Dr" wire:model="prefix">
                @error('prefix') <span class="text-danger small d-block">{{ $message }}</span> @enderror
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label" for="datos-name">Nombres <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="datos-name" wire:model="name">
                @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label" for="datos-lastname">Apellidos <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="datos-lastname" wire:model="lastname">
                @error('lastname') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            <div class="col-12 mb-3">
                <label class="form-label" for="datos-email">Correo @if (! $es_centro) <span class="text-danger">*</span> @endif</label>
                <input type="email" class="form-control" id="datos-email" wire:model="email" autocomplete="off">
                <div class="form-text">
                    {{ $es_centro ? 'Opcional: un centro médico no inicia sesión.' : 'Es el correo con el que inicia sesión.' }}
                </div>
                @error('email') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label" for="datos-phone">Teléfono</label>
                <input type="text" class="form-control" id="datos-phone" wire:model="phone">
                @error('phone') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label" for="datos-license">Número de licencia</label>
                <input type="text" class="form-control" id="datos-license" wire:model="license_number">
                @error('license_number') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-12 mb-3">
                <label class="form-label" for="datos-reg">Registro médico (reg_medico)</label>
                <input type="text" class="form-control" id="datos-reg" value="{{ $reg_medico }}" disabled>
                <div class="form-text">No se puede cambiar: con él están guardados todos los datos del médico en la nube.</div>
            </div>

            <div class="col-12 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="datos-es-centro" wire:model.live="es_centro">
                    <label class="form-check-label" for="datos-es-centro">
                        <strong>Es centro médico</strong>
                        <span class="d-block form-text mb-0">
                            El centro no inicia sesión ni atiende: es el tenant donde trabajan sus doctores.
                        </span>
                    </label>
                </div>
            </div>

            @if ($es_centro)
                <div class="col-12 mb-1">
                    <label class="form-label" for="datos-formato">Formato de los reportes</label>
                    <select class="form-select" id="datos-formato" wire:model="formato_reportes">
                        <option value="">— Sin elegir (se usa el del médico) —</option>
                        @foreach ($formatos as $formato)
                            <option value="{{ $formato }}">{{ $formato === 'centro' ? 'Del centro' : 'Del médico' }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Con qué membrete, logo y pie se imprimen los reportes del centro. La firma y el sello son siempre
                        los del doctor que atendió.
                    </div>
                    @error('formato_reportes') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>
    </div>
    <div class="{{ $inline ? 'd-flex justify-content-end gap-2 mt-3' : 'modal-footer' }}">
        <button type="button" class="btn btn-secondary" wire:click="{{ $accionCancelar }}">{{ $textoCancelar }}</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="{{ $accionGuardar }}">
            {{ $textoGuardar }}
        </button>
    </div>
</form>
