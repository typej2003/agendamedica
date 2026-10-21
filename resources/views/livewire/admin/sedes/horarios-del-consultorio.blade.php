<div>
    @include('livewire.admin.sedes._migas', ['migas' => [
        ['Médicos', route('admin.cuentas')],
        [$nombre, route('admin.medicos.sedes', $medico)],
        ['Sedes', route('admin.medicos.sedes', $medico)],
        [$sede->name, route('admin.medicos.sedes.consultorios', [$medico, $sede])],
        ['Consultorios', route('admin.medicos.sedes.consultorios', [$medico, $sede])],
        ['Consultorio ' . $consultorio->office_number, null],
        ['Horarios', null],
    ]])

    <x-ui.page-head titulo="Horarios y cupos · consultorio {{ $consultorio->office_number }}"
                    subtitulo="{{ $nombre }} · {{ $sede->name }}. Cada bloque es una jornada de la agenda; el cupo es el tope de pacientes de ese bloque."
                    :volver="route('admin.medicos.sedes.consultorios', [$medico, $sede])" volver-texto="Consultorios">
        <x-slot name="acciones">
            <button type="button" class="btn btn-primary" wire:click="abrirAgregar">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar horario
            </button>
        </x-slot>
    </x-ui.page-head>

    <x-ui.avisos />

    @foreach ($avisos as $aviso)
        <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="status">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>{{ $aviso }}</span>
        </div>
    @endforeach

    @unless ($consultorio->activo)
        <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="status">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Este consultorio está dado de baja: sus horarios no se ofrecen al agendar hasta reactivarlo.</span>
        </div>
    @endunless

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Día</th>
                            <th>Horario</th>
                            <th class="text-end">Cupo</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bloques as $bloque)
                            <tr wire:key="bloque-{{ $bloque->id }}">
                                <td class="fw-semibold">{{ $bloque->nombreDelDia() }}</td>
                                <td>{{ $bloque->rango() }}</td>
                                <td class="text-end">{{ $bloque->cupo ? $bloque->cupo . ' pacientes' : 'Sin tope' }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" wire:click="abrirEditar({{ $bloque->id }})" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i> Editar
                                    </button>
                                    <button type="button" wire:click="abrirQuitar({{ $bloque->id }})" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i> Quitar
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">Sin horarios: la agenda de este consultorio no tiene jornada ni tope.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================ Agregar / editar ============================ --}}
    @if ($modal === 'form')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-horario" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="guardar">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-horario">{{ $seleccionado ? 'Editar horario' : 'Agregar horario' }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @if ($seleccionado)
                                <div class="mb-3">
                                    <label class="form-label" for="horario-dia">Día <span class="text-danger">*</span></label>
                                    <select id="horario-dia" class="form-select @error('dia_semana') is-invalid @enderror" wire:model.defer="dia_semana">
                                        @foreach ($nombresDeDias as $numero => $dia)
                                            <option value="{{ $numero }}">{{ $dia }}</option>
                                        @endforeach
                                    </select>
                                    @error('dia_semana') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @else
                                <fieldset class="mb-3">
                                    <legend class="form-label fs-6">Días <span class="text-danger">*</span></legend>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($nombresDeDias as $numero => $dia)
                                            {{-- `.defer`: tildar varios seguidos no se pisa (TRAMPAS 51). --}}
                                            <input type="checkbox" class="btn-check" id="dia-{{ $numero }}" value="{{ $numero }}" wire:model.defer="dias">
                                            <label class="btn btn-sm btn-outline-primary" for="dia-{{ $numero }}">{{ $dia }}</label>
                                        @endforeach
                                    </div>
                                    @error('dias') <span class="text-danger small d-block mt-1">{{ $message }}</span> @enderror
                                    <small class="form-text">Un bloque por día, con el mismo horario y cupo.</small>
                                </fieldset>
                            @endif

                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="form-label" for="horario-inicio">Desde <span class="text-danger">*</span></label>
                                    <input id="horario-inicio" type="time" class="form-control @error('hora_inicio') is-invalid @enderror" wire:model.defer="hora_inicio">
                                    @error('hora_inicio') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="horario-fin">Hasta <span class="text-danger">*</span></label>
                                    <input id="horario-fin" type="time" class="form-control @error('hora_fin') is-invalid @enderror" wire:model.defer="hora_fin">
                                    @error('hora_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="horario-cupo">Cupo (pacientes)</label>
                                    <input id="horario-cupo" type="number" min="1" class="form-control @error('cupo') is-invalid @enderror" wire:model.defer="cupo"
                                           placeholder="Vacío = sin tope">
                                    @error('cupo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardar">
                                <i class="bi bi-check2 me-1" aria-hidden="true"></i> Guardar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Quitar ============================ --}}
    @if ($modal === 'quitar' && $seleccionado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-quitar-horario" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="titulo-quitar-horario">¿Quitar el bloque {{ $seleccionado->nombreDelDia() }} {{ $seleccionado->rango() }}?</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">Esas horas dejan de contarse como jornada. Las citas que ya caían ahí siguen en la agenda.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                        <button type="button" class="btn btn-danger" wire:click="confirmarQuitar" wire:loading.attr="disabled">
                            <i class="bi bi-trash me-1" aria-hidden="true"></i> Quitar bloque
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
