<div>
    @include('livewire.admin.sedes._migas', ['migas' => [
        ['Médicos', route('admin.cuentas')],
        [$nombre, null],
        ['Sedes', null],
    ]])

    <x-ui.page-head titulo="Sedes de {{ $nombre }}"
                    subtitulo="Dónde atiende. Cada sede lleva sus consultorios y cada consultorio sus horarios y cupos.">
        @if ($tenant)
            <x-slot name="acciones">
                <button type="button" class="btn btn-primary" wire:click="abrirAgregar">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar sede
                </button>
            </x-slot>
        @endif
    </x-ui.page-head>

    <x-ui.avisos />

    @if (! $tenant)
        <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Esta ficha no tiene <code>reg_medico</code>: sin él no hay a quién colgarle sedes ni consultorios.</span>
        </div>
    @else
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" wire:click="$set('verInactivas', false)"
                    class="btn btn-sm {{ $verInactivas ? 'btn-outline-secondary' : 'btn-secondary' }}">Con consultorios activos</button>
            <button type="button" wire:click="$set('verInactivas', true)"
                    class="btn btn-sm {{ $verInactivas ? 'btn-secondary' : 'btn-outline-secondary' }}">Ver también las quitadas</button>
        </div>

        <div class="card">
            <x-ui.cargando cubre wire:loading.delay.flex />
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Sede</th>
                                <th>Dirección</th>
                                <th class="text-end">Consultorios</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sedes as $sede)
                                <tr wire:key="sede-{{ $sede->id }}">
                                    <td>
                                        <div class="fw-semibold">{{ $sede->name }}</div>
                                        @unless ($sede->activo)
                                            <span class="badge bg-warning text-dark" title="La sede está desactivada en el catálogo: no se ofrece al agendar">Sede desactivada</span>
                                        @endunless
                                        @if ($sede->consultorios_activos === 0)
                                            <span class="badge bg-secondary">Quitada</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div>{{ $sede->address ?: '—' }}</div>
                                        <small class="text-muted">{{ $sede->city->name ?? '—' }}@if ($sede->phone) · {{ $sede->phone }}@endif</small>
                                    </td>
                                    <td class="text-end">
                                        {{ $sede->consultorios_activos }}
                                        @if ($sede->consultorios_total > $sede->consultorios_activos)
                                            <small class="text-muted d-block">+{{ $sede->consultorios_total - $sede->consultorios_activos }} de baja</small>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.medicos.sedes.consultorios', [$medico, $sede]) }}" class="btn btn-sm btn-primary">
                                            <i class="bi bi-door-open me-1" aria-hidden="true"></i> Ver consultorios
                                        </a>
                                        <button type="button" wire:click="abrirEditar({{ $sede->id }})" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i> Editar
                                        </button>
                                        @if ($sede->consultorios_activos > 0)
                                            <button type="button" wire:click="abrirQuitar({{ $sede->id }})" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Quitar
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        {{ $verInactivas ? 'Este médico nunca tuvo sedes.' : 'Este médico todavía no tiene sedes: sin ellas no puede agendar.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Agregar sede ============================ --}}
    @if ($modal === 'agregar')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-agregar-sede" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarAlta">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-agregar-sede">Agregar sede a {{ $nombre }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="btn-group mb-3" role="group" aria-label="Qué sede">
                                <input type="radio" class="btn-check" id="modo-existente" value="existente" wire:model.live="modo">
                                <label class="btn btn-outline-primary" for="modo-existente">Sede existente</label>
                                <input type="radio" class="btn-check" id="modo-nueva" value="nueva" wire:model.live="modo">
                                <label class="btn btn-outline-primary" for="modo-nueva">Sede nueva</label>
                            </div>

                            @if ($modo === 'existente')
                                <div class="mb-3">
                                    <label class="form-label" for="sede-existente">Sede <span class="text-danger">*</span></label>
                                    <select id="sede-existente" class="form-select @error('sede_existente_id') is-invalid @enderror" wire:model="sede_existente_id">
                                        <option value="">Elige…</option>
                                        @foreach ($catalogo as $opcion)
                                            <option value="{{ $opcion->id }}">{{ $opcion->name }}{{ $opcion->city ? ' · ' . $opcion->city->name : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error('sede_existente_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @else
                                @include('livewire.admin.sedes._campos-sede')
                            @endif

                            <h6 class="mt-2">Primer consultorio en esa sede</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="alta-numero">Consultorio <span class="text-danger">*</span></label>
                                    <input id="alta-numero" type="text" class="form-control @error('office_number') is-invalid @enderror"
                                           wire:model="office_number" placeholder="Ej.: 204">
                                    @error('office_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="alta-modalidad">Modalidad <span class="text-danger">*</span></label>
                                    <select id="alta-modalidad" class="form-select @error('modalidad') is-invalid @enderror" wire:model="modalidad">
                                        @foreach ($modalidades as $valor => $etiqueta)
                                            <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                        @endforeach
                                    </select>
                                    @error('modalidad') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="alta-duracion">Duración de la cita (min)</label>
                                    <input id="alta-duracion" type="number" min="5" max="480" class="form-control @error('duracion_cita') is-invalid @enderror"
                                           wire:model="duracion_cita">
                                    @error('duracion_cita') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="alta-telefono-consultorio">Teléfono del consultorio</label>
                                    <input id="alta-telefono-consultorio" type="text" class="form-control @error('office_phone') is-invalid @enderror"
                                           wire:model="office_phone">
                                    @error('office_phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardarAlta">
                                <i class="bi bi-check2 me-1" aria-hidden="true"></i> Agregar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Editar sede ============================ --}}
    @if ($modal === 'editar' && $seleccionada)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-editar-sede" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarEdicion">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-editar-sede">Editar sede {{ $seleccionada->name }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @if ($otros > 0)
                                <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="status">
                                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                    <span>La sede es del catálogo de la clínica: {{ $otros }} {{ $otros === 1 ? 'médico más atiende' : 'médicos más atienden' }} ahí y {{ $otros === 1 ? 've' : 'ven' }} el cambio.</span>
                                </div>
                            @endif
                            @include('livewire.admin.sedes._campos-sede')
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardarEdicion">
                                <i class="bi bi-check2 me-1" aria-hidden="true"></i> Guardar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Quitar sede ============================ --}}
    @if ($modal === 'quitar' && $seleccionada)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-quitar-sede" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="titulo-quitar-sede">¿Quitar {{ $seleccionada->name }} a {{ $nombre }}?</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Se dan de baja sus consultorios en esta sede:
                            <strong>{{ $aQuitar->pluck('office_number')->implode(', ') }}</strong>.
                            Dejan de ofrecerse al agendar; <strong>no se borra nada</strong> (ni la sede, que es del catálogo, ni
                            los horarios) y se pueden reactivar.</p>
                        @if ($citas > 0)
                            <p class="text-danger mb-0">
                                <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                                Tiene {{ $citas }} {{ $citas === 1 ? 'cita' : 'citas' }} en esta sede desde hoy: siguen en la agenda, pero sin consultorio activo.
                            </p>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                        <button type="button" class="btn btn-danger" wire:click="confirmarQuitar" wire:loading.attr="disabled">
                            <i class="bi bi-x-lg me-1" aria-hidden="true"></i> Quitar sede
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
