<div>
    <x-ui.page-head titulo="Especialidades"
                    subtitulo="Con qué especialidades trabaja cada médico en el consultorio. La especialidad decide qué módulos ve en la web clínica." />

    <x-ui.avisos />

    {{-- No es un error (se guardó), pero deja al médico sin consultorio: se dice aparte. --}}
    @if ($aviso)
        <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="status">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>{{ $aviso }}</span>
        </div>
    @endif

    @php
        $chips = [
            'todos' => ['Todos', 'secondary'],
            'sin'   => ['Sin especialidad', 'danger'],
            'con'   => ['Con especialidad', 'success'],
        ];
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach ($chips as $clave => [$etiqueta, $color])
            <button type="button" wire:click="filtrar('{{ $clave }}')" wire:key="chip-{{ $clave }}"
                    class="btn btn-sm {{ $filtro === $clave ? 'btn-' . $color : 'btn-outline-' . $color }}">
                {{ $etiqueta }} <span class="badge bg-light text-dark ms-1">{{ $conteo[$clave] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3">
            <div class="row">
                <div class="col-md-5">
                    <label class="visually-hidden" for="buscar-especialidades">Buscar médico</label>
                    <input type="text" id="buscar-especialidades" wire:model.live.debounce.400ms="search" class="form-control"
                           placeholder="Buscar por nombre, correo o registro...">
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Médico</th>
                            <th>Especialidades</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($filas as $medico)
                            @php $activas = $medico->specialties->where('activo', true)->count(); @endphp
                            <tr wire:key="medico-{{ $medico->id }}">
                                <td>
                                    <div class="fw-semibold">{{ trim($medico->name . ' ' . $medico->lastname) }}</div>
                                    <small class="text-muted">{{ $medico->reg_medico }}</small>
                                    @if ($medico->user_id === null)
                                        <span class="badge bg-secondary ms-1" title="Ficha de médico sin cuenta de acceso">Sin acceso</span>
                                    @endif
                                </td>
                                <td>
                                    @forelse ($medico->specialties->sortBy('name') as $especialidad)
                                        <span class="badge {{ $especialidad->activo ? 'bg-light text-dark border' : 'bg-warning text-dark' }} me-1 mb-1"
                                              @unless ($especialidad->activo) title="Inactiva: no le da módulos en el consultorio" @endunless>
                                            {{ $especialidad->name }}@unless ($especialidad->activo) · inactiva @endunless
                                        </span>
                                    @empty
                                        <span class="badge bg-danger">Sin especialidad</span>
                                        <small class="text-muted d-block">Su consultorio no muestra ningún módulo.</small>
                                    @endforelse

                                    @if ($medico->specialties->isNotEmpty() && $activas === 0)
                                        <small class="text-danger d-block">Ninguna activa: su consultorio no muestra módulos.</small>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <button wire:click="abrirAsignar({{ $medico->id }})" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-heart-pulse-fill me-1"></i> Especialidades
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No se encontraron médicos.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($filas->hasPages())
            <div class="card-footer bg-white">
                {{ $filas->links() }}
            </div>
        @endif
    </div>

    {{-- ============================ Ventana de asignación ============================ --}}
    @if ($modal === 'asignar' && $seleccionado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Especialidades de {{ trim($seleccionado->name . ' ' . $seleccionado->lastname) }}</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <x-admin.especialidades-form :catalogo="$catalogo" :sin-activas="$sinActivas" :seleccionado="$seleccionado"
                                                 accion-guardar="guardarAsignacion" accion-cancelar="cerrarModal" />
                </div>
            </div>
        </div>
    @endif
</div>
