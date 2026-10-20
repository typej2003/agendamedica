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
                    <input type="text" id="buscar-especialidades" wire:model.debounce.400ms="search" class="form-control"
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
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarAsignacion">
                        <div class="modal-header">
                            <h5 class="modal-title">Especialidades de {{ trim($seleccionado->name . ' ' . $seleccionado->lastname) }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @if ($catalogo->isEmpty())
                                <p class="text-muted mb-0">El catálogo de especialidades está vacío.</p>
                            @else
                                <p class="text-muted small">
                                    Se puede elegir más de una. La especialidad es lo que enciende los módulos del
                                    consultorio: sin ninguna <strong>activa</strong>, el médico entra a la web clínica y no ve nada.
                                </p>

                                <div class="row">
                                    @foreach ($catalogo as $especialidad)
                                        <div class="col-md-6" wire:key="catalogo-{{ $especialidad->id }}">
                                            <div class="form-check mb-2">
                                                {{-- `.defer`: tildar no dispara una petición por casilla. Sin eso, dos clics
                                                     seguidos se pisan (el segundo viaja antes de que vuelva el primero) y
                                                     se guarda sólo la última tildada — medido en Chrome, ver TRAMPAS 51. --}}
                                                <input class="form-check-input" type="checkbox" value="{{ $especialidad->id }}"
                                                       id="especialidad-{{ $especialidad->id }}" wire:model.defer="seleccionadas">
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
                                    // Los postgrados que se pueden tildar: los de las especialidades que el médico **ya tiene**
                                    // (los que se acaban de tildar aparecen después de guardar y volver a abrir; usar
                                    // `$seleccionadas` obligaría a quitarle el `.defer` a las casillas y volvería la
                                    // carrera de dos clics seguidos — TRAMPAS 51).
                                    $conPostgrados = $catalogo->filter(fn ($e) => $seleccionado->specialties->contains('id', $e->id)
                                        && $e->subespecialidades->isNotEmpty());
                                @endphp

                                @if ($conPostgrados->isNotEmpty())
                                    <hr>
                                    <p class="text-muted small mb-2">
                                        <strong>Postgrados.</strong> Una subespecialidad cuelga de su especialidad —nefrología
                                        pediátrica es un postgrado de pediatría— y enciende sus propias secciones de la consulta.
                                        Si el médico tiene más de una, <strong>se suman</strong>. Para asignar los de una
                                        especialidad que recién agregás, guardá y volvé a abrir esta ventana.
                                    </p>

                                    @foreach ($conPostgrados as $especialidad)
                                        <div class="mb-2" wire:key="postgrados-{{ $especialidad->id }}">
                                            <p class="small fw-semibold mb-1">{{ $especialidad->name }}</p>
                                            <div class="row">
                                                @foreach ($especialidad->subespecialidades as $postgrado)
                                                    <div class="col-md-6" wire:key="postgrado-{{ $postgrado->id }}">
                                                        <div class="form-check mb-1">
                                                            <input class="form-check-input" type="checkbox" value="{{ $postgrado->id }}"
                                                                   id="postgrado-{{ $postgrado->id }}" wire:model.defer="postgrados">
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
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardarAsignacion">
                                <i class="bi bi-check2 me-1"></i> Guardar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
