<div>
    <x-ui.page-head :titulo="'Catálogo inicial · ' . $def['titulo']"
                    subtitulo="Lo que cada médico recibe como punto de partida al «Cargar el catálogo inicial». Cada médico recibe una copia y la ajusta a su manera: lo que cambies aquí no modifica lo que ya copiaron.">
        <x-slot name="acciones">
            <button type="button" class="btn btn-primary" wire:click="abrir">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo {{ $def['singular'] }}
            </button>
        </x-slot>
    </x-ui.page-head>

    @include('livewire.admin._catalogo-inicial-chips', ['activo' => 'admin.catalogo-inicial.catalogo', 'activoSlug' => $catalogo])

    <x-ui.avisos />

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <label class="visually-hidden" for="buscar-inicial">Buscar</label>
                    <input type="text" id="buscar-inicial" wire:model.debounce.400ms="search" class="form-control" placeholder="Buscar por nombre o código...">
                </div>
                <div class="col-md-7 text-md-end mt-2 mt-md-0 text-muted small">{{ $total }} {{ $total === 1 ? 'fila inicial' : 'filas iniciales' }}</div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr><th>Código</th><th>{{ ucfirst($def['singular']) }}</th><th>Detalle</th><th class="text-end">Acciones</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            <tr wire:key="ini-{{ $catalogo }}-{{ $fila->id }}">
                                <td><code>{{ trim((string) $fila->{$def['codigo']}) }}</code></td>
                                <td class="fw-semibold">{{ trim((string) $fila->{$def['etiqueta']}) }}</td>
                                <td class="text-muted small">{{ $detalles[$fila->id] ?: '—' }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($catalogo === 'tratamientos')
                                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirLineas({{ $fila->id }})">
                                            <i class="bi bi-list-ul me-1" aria-hidden="true"></i> Medicamentos
                                        </button>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrir({{ $fila->id }})" aria-label="Editar {{ trim((string) $fila->{$def['etiqueta']}) }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="abrirBorrar({{ $fila->id }})" aria-label="Quitar {{ trim((string) $fila->{$def['etiqueta']}) }}">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    {{ trim($search) === '' ? 'Todavía no hay filas en este catálogo inicial. Agrega la primera con «Nuevo ' . $def['singular'] . '».' : 'No se encontró nada.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($filas->hasPages())
            <div class="card-footer bg-white">{{ $filas->links() }}</div>
        @endif
    </div>

    {{-- ============================ Alta y edición ============================ --}}
    @if ($modal === 'fila')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-ini" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit.prevent="guardar">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-ini">{{ $filaId ? 'Editar' : 'Nuevo' }} {{ $def['singular'] }} (catálogo inicial)</h5>
                            <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @error('f.formulario') <div class="alert alert-danger py-2 small">{{ $message }}</div> @enderror
                            <div class="row g-3">
                                @foreach ($def['campos'] as $columna => [$rotulo, $tipo, $max, $requerido])
                                    <div class="{{ $tipo === 'area' ? 'col-12' : 'col-md-6' }}">
                                        @if ($tipo === 'marca')
                                            <div class="form-check mt-4">
                                                <input class="form-check-input" type="checkbox" id="ini-{{ $columna }}" wire:model.defer="f.{{ $columna }}">
                                                <label class="form-check-label" for="ini-{{ $columna }}">{{ $rotulo }}</label>
                                            </div>
                                        @else
                                            <label class="form-label" for="ini-{{ $columna }}">{{ $rotulo }}@if ($requerido) <span class="text-danger" aria-hidden="true">*</span>@endif</label>
                                            @if ($tipo === 'area')
                                                <textarea id="ini-{{ $columna }}" rows="{{ $max > 5000 ? 8 : 3 }}" class="form-control" wire:model.defer="f.{{ $columna }}"></textarea>
                                            @elseif ($tipo === 'ref' || $tipo === 'tipo')
                                                <select id="ini-{{ $columna }}" class="form-select" wire:model.defer="f.{{ $columna }}">
                                                    <option value="">Elige…</option>
                                                    @foreach ($opciones[$columna] ?? [] as $valor => $texto)
                                                        <option value="{{ $valor }}">{{ $texto }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" id="ini-{{ $columna }}" class="form-control" @if ($tipo === 'numero') inputmode="decimal" @endif
                                                       @if ($max > 0) maxlength="{{ $max }}" @endif wire:model.defer="f.{{ $columna }}">
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <p class="text-muted small mt-3 mb-0">El código lo asigna el sistema.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrar">Cancelar</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardar"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Quitar ============================ --}}
    @if ($modal === 'borrar')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-ini-borrar" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="titulo-ini-borrar">Quitar del catálogo inicial</h5>
                        <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">Se quita del catálogo inicial: los consultorios que ya la copiaron la conservan y los que no, ya no la recibirán.@if ($catalogo === 'tratamientos') También se quitan sus medicamentos.@endif</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrar">Cancelar</button>
                        <button type="button" class="btn btn-danger" wire:click="borrar" wire:loading.attr="disabled" wire:target="borrar"><i class="bi bi-trash me-1" aria-hidden="true"></i> Quitar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Medicamentos de un tratamiento ============================ --}}
    @if ($modal === 'lineas' && $tratamiento)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-lineas" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="titulo-lineas">Medicamentos de {{ trim((string) $tratamiento->tratamiento) }}</h5>
                        <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <x-ui.avisos />
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Medicamento</th><th>Cant.</th><th>Indicaciones</th><th class="text-end">Acciones</th></tr></thead>
                                <tbody>
                                    @forelse ($lineas as $linea)
                                        @php $m = $medicamentos->firstWhere('codemedicina', $linea->codemedicina); @endphp
                                        <tr wire:key="lin-{{ $linea->id }}">
                                            <td>{{ $m ? trim($m->nombrecomercial . ' ' . $m->presentacion) : $linea->codemedicina }}</td>
                                            <td>{{ $linea->cantidad }}</td>
                                            <td class="small text-muted">{{ \Illuminate\Support\Str::limit((string) $linea->indicaciones, 70) }}</td>
                                            <td class="text-end text-nowrap">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="editarLinea({{ $linea->id }})" aria-label="Editar la línea"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="quitarLinea({{ $linea->id }})" aria-label="Quitar la línea"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted py-3">Este tratamiento todavía no tiene medicamentos.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <form wire:submit.prevent="guardarLinea" class="border rounded p-3">
                            <h6 class="mb-3">{{ $lineaId ? 'Editar la línea' : 'Agregar un medicamento' }}</h6>
                            @error('l.formulario') <div class="alert alert-danger py-2 small">{{ $message }}</div> @enderror
                            <div class="row g-3">
                                <div class="col-md-7">
                                    <label class="form-label" for="lin-med">Medicamento del vademécum inicial</label>
                                    <select id="lin-med" class="form-select" wire:model.defer="l.codemedicina">
                                        <option value="">Elige…</option>
                                        @foreach ($medicamentos as $m)
                                            <option value="{{ $m->codemedicina }}">{{ trim($m->nombrecomercial . ' ' . $m->presentacion) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="lin-cant">Cantidad</label>
                                    <input type="text" inputmode="numeric" id="lin-cant" class="form-control" wire:model.defer="l.cantidad">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="lin-ind">Indicaciones</label>
                                    <textarea id="lin-ind" rows="2" class="form-control" wire:model.defer="l.indicaciones"></textarea>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                @if ($lineaId)
                                    <button type="button" class="btn btn-outline-secondary" wire:click="nuevaLinea">Cancelar edición</button>
                                @endif
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardarLinea"><i class="bi bi-check2 me-1" aria-hidden="true"></i> {{ $lineaId ? 'Guardar la línea' : 'Agregar' }}</button>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" wire:click="cerrar">Cerrar</button></div>
                </div>
            </div>
        </div>
    @endif
</div>
