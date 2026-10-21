<div>
    @include('livewire.admin.sedes._migas', ['migas' => [
        ['Médicos', route('admin.cuentas')],
        [$nombre, route('admin.medicos.sedes', $medico)],
        ['Sedes', route('admin.medicos.sedes', $medico)],
        [$sede->name, null],
        ['Consultorios', null],
    ]])

    <x-ui.page-head titulo="Consultorios en {{ $sede->name }}"
                    subtitulo="{{ $nombre }} · {{ $sede->address ?: 'sin dirección' }}"
                    :volver="route('admin.medicos.sedes', $medico)" volver-texto="Sedes">
        <x-slot name="acciones">
            <button type="button" class="btn btn-primary" wire:click="abrirAgregar" @unless ($sede->activo) disabled @endunless>
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar consultorio
            </button>
        </x-slot>
    </x-ui.page-head>

    <x-ui.avisos />

    @unless ($sede->activo)
        <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="status">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>La sede está desactivada en el catálogo: no se le pueden agregar ni editar consultorios hasta activarla.</span>
        </div>
    @endunless

    @if ($inactivos > 0 || $verInactivos)
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" wire:click="$set('verInactivos', false)"
                    class="btn btn-sm {{ $verInactivos ? 'btn-outline-secondary' : 'btn-secondary' }}">Activos</button>
            <button type="button" wire:click="$set('verInactivos', true)"
                    class="btn btn-sm {{ $verInactivos ? 'btn-secondary' : 'btn-outline-secondary' }}">
                Ver también los de baja <span class="badge bg-light text-dark ms-1">{{ $inactivos }}</span>
            </button>
        </div>
    @endif

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Consultorio</th>
                            <th>Modalidad</th>
                            <th class="text-end">Duración</th>
                            <th>Horarios</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($consultorios as $consultorio)
                            <tr wire:key="consultorio-{{ $consultorio->id }}" class="{{ $consultorio->activo ? '' : 'text-muted' }}">
                                <td>
                                    <div class="fw-semibold">{{ $consultorio->office_number }}</div>
                                    @if ($consultorio->phone)<small class="text-muted">{{ $consultorio->phone }}</small>@endif
                                    @unless ($consultorio->activo)
                                        <span class="badge bg-secondary">De baja</span>
                                    @endunless
                                </td>
                                <td>{{ $modalidades[$consultorio->modalidad] ?? '—' }}</td>
                                <td class="text-end">{{ $consultorio->duracion_cita ? $consultorio->duracion_cita . ' min' : '—' }}</td>
                                <td>
                                    @forelse ($consultorio->schedules as $bloque)
                                        <span class="badge bg-light text-dark border me-1 mb-1">
                                            {{ mb_substr($bloque->nombreDelDia(), 0, 3) }} {{ $bloque->rango() }}@if ($bloque->cupo) · {{ $bloque->cupo }}@endif
                                        </span>
                                    @empty
                                        <small class="text-muted">Sin horarios: la jornada no tiene tope.</small>
                                    @endforelse
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('admin.medicos.sedes.consultorios.horarios', [$medico, $sede, $consultorio]) }}" class="btn btn-sm btn-primary">
                                        <i class="bi bi-clock me-1" aria-hidden="true"></i> Horarios y cupos
                                    </a>
                                    @if ($consultorio->activo)
                                        <button type="button" wire:click="abrirEditar({{ $consultorio->id }})" class="btn btn-sm btn-outline-primary" @unless ($sede->activo) disabled @endunless>
                                            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i> Editar
                                        </button>
                                        <button type="button" wire:click="abrirQuitar({{ $consultorio->id }})" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Quitar
                                        </button>
                                    @else
                                        <button type="button" wire:click="reactivar({{ $consultorio->id }})" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Reactivar
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">{{ $nombre }} no tiene consultorios activos en esta sede.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================ Agregar / editar ============================ --}}
    @if ($modal === 'form')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-consultorio" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="guardar">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-consultorio">
                                {{ $seleccionado ? 'Editar consultorio ' . $seleccionado->office_number : 'Agregar consultorio en ' . $sede->name }}
                            </h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="consultorio-numero">Consultorio <span class="text-danger">*</span></label>
                                    <input id="consultorio-numero" type="text" class="form-control @error('office_number') is-invalid @enderror"
                                           wire:model.defer="office_number" placeholder="Ej.: 204">
                                    @error('office_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consultorio-telefono">Teléfono</label>
                                    <input id="consultorio-telefono" type="text" class="form-control @error('phone') is-invalid @enderror" wire:model.defer="phone">
                                    @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consultorio-modalidad">Modalidad <span class="text-danger">*</span></label>
                                    <select id="consultorio-modalidad" class="form-select @error('modalidad') is-invalid @enderror" wire:model.defer="modalidad">
                                        @foreach ($modalidades as $valor => $etiqueta)
                                            <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                        @endforeach
                                    </select>
                                    @error('modalidad') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consultorio-duracion">Duración de la cita (min)</label>
                                    <input id="consultorio-duracion" type="number" min="5" max="480" class="form-control @error('duracion_cita') is-invalid @enderror"
                                           wire:model.defer="duracion_cita">
                                    @error('duracion_cita') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-quitar-consultorio" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="titulo-quitar-consultorio">¿Quitar el consultorio {{ $seleccionado->office_number }}?</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">Se da de baja: deja de ofrecerse al agendar. <strong>No se borra</strong>: sus citas y sus
                            horarios quedan como estaban y se puede reactivar.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                        <button type="button" class="btn btn-danger" wire:click="confirmarQuitar" wire:loading.attr="disabled">
                            <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Quitar consultorio
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
