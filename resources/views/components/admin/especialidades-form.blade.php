@props([
    'catalogo',
    'sinActivas' => 0,
    'seleccionado',
    'accionGuardar',
    'accionCancelar' => 'cerrarModal',
    'textoCancelar' => 'Cancelar',
    'inline' => false,
])

{{-- Formulario de especialidades y postgrados de un médico. Es el mismo en el panel de Especialidades y en el
     detalle del médico. Usa las propiedades `seleccionadas` y `postgrados` del componente Livewire que lo incluye. --}}
<form wire:submit.prevent="{{ $accionGuardar }}">
    <div class="{{ $inline ? 'card-body' : 'modal-body' }}">
        @if ($catalogo->isEmpty())
            <p class="text-muted mb-0">El catálogo de especialidades está vacío.</p>
        @else
            <h6 class="fw-semibold mb-1">Especialidades</h6>
            <p class="text-muted small">
                Se puede elegir más de una. La especialidad es lo que enciende los módulos del
                consultorio: sin ninguna <strong>activa</strong>, el médico entra a la web clínica y no ve nada.
            </p>

            <div class="row">
                @foreach ($catalogo as $especialidad)
                    <div class="col-md-6" wire:key="catalogo-{{ $especialidad->id }}">
                        <div class="form-check mb-2">
                            {{-- `.defer`: tildar no dispara una petición por casilla. Sin eso, dos clics
                                 seguidos se pisan y se guarda sólo la última tildada — TRAMPAS 51. --}}
                            <input class="form-check-input" type="checkbox" value="{{ $especialidad->id }}"
                                   id="especialidad-{{ $especialidad->id }}" wire:model="seleccionadas">
                            <label class="form-check-label" for="especialidad-{{ $especialidad->id }}">
                                {{ $especialidad->name }}
                                @if ($especialidad->codigo)
                                    <span class="badge bg-light text-dark border" title="Código con el que la llama el escritorio">{{ $especialidad->codigo }}</span>
                                @endif
                                @unless ($especialidad->activo)
                                    <span class="badge bg-warning text-dark">Inactiva</span>
                                @endunless
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($sinActivas > 0)
                <p class="form-text mb-0">
                    Una especialidad inactiva se puede dejar asignada (no se borra), pero no le da módulos
                    al consultorio.
                </p>
            @endif

            @error('especialidades') <span class="text-danger small d-block">{{ $message }}</span> @enderror

            @php
                // Las subespecialidades van abajo, agrupadas por su especialidad. Sólo se pueden tildar las de
                // las especialidades que el médico ya tiene guardadas (TRAMPAS 51).
                $conPostgrados = $catalogo->filter(fn ($e) => $e->subespecialidades->isNotEmpty());
            @endphp

            @if ($conPostgrados->isNotEmpty())
                <hr>
                <h6 class="fw-semibold mb-1">Subespecialidades</h6>
                <p class="text-muted small mb-2">
                    Una subespecialidad (postgrado) cuelga de su especialidad —nefrología pediátrica es un
                    postgrado de pediatría— y enciende sus propias secciones de la consulta. Si el médico
                    tiene más de una, <strong>se suman</strong>.
                </p>

                @foreach ($conPostgrados as $especialidad)
                    @php($tieneEspecialidad = $seleccionado->specialties->contains('id', $especialidad->id))
                    <div class="mb-2" wire:key="postgrados-{{ $especialidad->id }}">
                        <p class="small fw-semibold mb-1">
                            {{ $especialidad->name }}
                            @unless ($tieneEspecialidad)
                                <span class="fw-normal text-muted">· asigna {{ $especialidad->name }}, guarda y vuelve a abrir para elegirlas</span>
                            @endunless
                        </p>
                        <div class="row">
                            @foreach ($especialidad->subespecialidades as $postgrado)
                                <div class="col-md-6" wire:key="postgrado-{{ $postgrado->id }}">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="checkbox" value="{{ $postgrado->id }}"
                                               id="postgrado-{{ $postgrado->id }}" wire:model="postgrados"
                                               {{ $tieneEspecialidad ? '' : 'disabled' }}>
                                        <label class="form-check-label" for="postgrado-{{ $postgrado->id }}">
                                            {{ $postgrado->nombre }}
                                            @if ($postgrado->codigo)
                                                <span class="badge bg-light text-dark border" title="Código con el que la llama el escritorio">{{ $postgrado->codigo }}</span>
                                            @endif
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @error('postgrados') <span class="text-danger small d-block">{{ $message }}</span> @enderror
            @endif
        @endif
    </div>
    <div class="{{ $inline ? 'd-flex justify-content-end gap-2 mt-3' : 'modal-footer' }}">
        <button type="button" class="btn btn-secondary" wire:click="{{ $accionCancelar }}">{{ $textoCancelar }}</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="{{ $accionGuardar }}">
            <i class="bi bi-check2 me-1"></i> Guardar
        </button>
    </div>
</form>
