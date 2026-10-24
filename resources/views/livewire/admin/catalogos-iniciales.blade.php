<div>
    <x-ui.page-head :titulo="'Catálogo inicial · ' . $def['titulo']"
                    subtitulo="Lo que cada médico recibe como punto de partida al «Cargar el catálogo inicial». Cada médico recibe una copia y la ajusta a su manera: lo que cambies aquí no modifica lo que ya copiaron.">
        <x-slot name="acciones">
            <button type="button" class="btn btn-primary" wire:click="abrir">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo {{ $def['singular'] }}
            </button>
        </x-slot>
    </x-ui.page-head>


    <x-ui.avisos />

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <label class="visually-hidden" for="buscar-inicial">Buscar</label>
                    <input type="text" id="buscar-inicial" wire:model.live.debounce.400ms="search" class="form-control" placeholder="Buscar por nombre o código...">
                </div>
                <div class="col-md-4 mt-2 mt-md-0">
                    <label class="visually-hidden" for="especialidad-inicial">Especialidad</label>
                    <select id="especialidad-inicial" class="form-select" wire:model.live="especialidad">
                        <option value="">Todas</option>
                        <option value="general">Generales</option>
                        @foreach ($especialidades as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 text-md-end mt-2 mt-md-0 text-muted small">{{ $total }} {{ $total === 1 ? 'fila inicial' : 'filas iniciales' }}</div>
            </div>
        </div>

        @if ($catalogo === 'medicamentos')
            {{-- La selección se lleva en el navegador (`wire:model` + este script): marcar una casilla no hace ninguna petición; la selección viaja con «Combinar». --}}
            <div id="barra-combinar" class="card-header bg-light py-2 flex-wrap align-items-center gap-2 {{ count($seleccion) > 0 ? 'd-flex' : 'd-none' }}" role="status" data-sel="{{ json_encode(array_values($seleccion)) }}">
                <span class="small" data-barra-n>{{ count($seleccion) }} {{ count($seleccion) === 1 ? 'marcado' : 'marcados' }}</span>
                <button type="button" class="btn btn-sm btn-primary" wire:click="abrirCombinar" data-barra-combinar @disabled(count($seleccion) < 2)>
                    <i class="bi bi-intersect me-1" aria-hidden="true"></i> Combinar ({{ count($seleccion) }})
                </button>
                <button type="button" class="btn btn-sm btn-link" data-barra-quitar>Quitar la selección</button>
                <span class="text-muted small {{ count($seleccion) >= 2 ? 'd-none' : '' }}" data-barra-aviso>Marca al menos dos para combinarlos en una sola ficha.</span>
            </div>
        @endif

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>@if ($catalogo === 'medicamentos')<th style="width:2.5rem"><span class="visually-hidden">Marcar</span></th>@endif<th>Código</th><th>{{ ucfirst($def['singular']) }}</th><th>Detalle</th><th>Especialidades</th><th class="text-end">Acciones</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            <tr wire:key="ini-{{ $catalogo }}-{{ $fila->id }}">
                                @if ($catalogo === 'medicamentos')
                                    <td><input type="checkbox" class="form-check-input" value="{{ $fila->id }}" wire:model="seleccion" aria-label="Marcar {{ trim((string) $fila->{$def['etiqueta']}) }} para combinar"></td>
                                @endif
                                <td><code>{{ trim((string) $fila->{$def['codigo']}) }}</code></td>
                                <td class="fw-semibold">{{ trim((string) $fila->{$def['etiqueta']}) }}</td>
                                <td class="text-muted small">{{ $detalles[$fila->id] ?: '—' }}</td>
                                <td>
                                    @forelse ($marcas[$fila->id] ?? [] as $especialidadId)
                                        <span class="badge bg-light text-dark border">{{ $especialidades[$especialidadId] ?? '¿?' }}</span>
                                    @empty
                                        <span class="badge bg-secondary">General</span>
                                    @endforelse
                                </td>
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
                                <td colspan="{{ $catalogo === 'medicamentos' ? 6 : 5 }}" class="text-center text-muted py-4">
                                    {{ trim($search) === '' && $especialidad === '' ? 'Todavía no hay filas en este catálogo inicial. Agrega la primera con «Nuevo ' . $def['singular'] . '».' : 'No se encontró nada.' }}
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
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <form wire:submit.prevent="guardar">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-ini">{{ $filaId ? 'Editar' : 'Nuevo' }} {{ $def['singular'] }} (catálogo inicial)</h5>
                            <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @error('f.formulario') <div class="alert alert-danger py-2 small">{{ $message }}</div> @enderror
                            <div class="row g-3">
                                @foreach ($def['campos'] as $columna => $campo)
                                    @continue(($campo[5] ?? null) === 'dosis')
                                    @include('livewire.admin._campo-catalogo-inicial')
                                @endforeach
                            </div>
                            @if (collect($def['campos'])->contains(fn ($campo) => ($campo[5] ?? null) === 'dosis'))
                                {{-- La casilla «La dosis es por kilo» despliega el panel con la fórmula (`.dosis-por-kilo`, en doctorisimo-ui.css). --}}
                                <div class="mt-3 border rounded p-3 dosis-por-kilo">
                                    <div class="row g-3">
                                        @foreach ($def['campos'] as $columna => $campo)
                                            @continue(($campo[5] ?? null) !== 'dosis' || $campo[1] !== 'porkilo')
                                            @include('livewire.admin._campo-catalogo-inicial')
                                        @endforeach
                                    </div>
                                    <div class="dosis-por-kilo__panel mt-3">
                                        <p class="text-muted small mb-2">El récipe calcula la posología con el peso del niño y la da en gotas o en ml si se carga el envase.</p>
                                        <div class="row g-3">
                                            @foreach ($def['campos'] as $columna => $campo)
                                                @continue(($campo[5] ?? null) !== 'dosis' || $campo[1] === 'porkilo')
                                                @include('livewire.admin._campo-catalogo-inicial')
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <fieldset class="mt-3">
                                <legend class="form-label fs-6 mb-1">Aplica a</legend>
                                <div class="d-flex flex-wrap gap-3">
                                    @foreach ($especialidades as $id => $nombre)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="aplica-{{ $id }}" value="{{ $id }}" wire:model="aplica">
                                            <label class="form-check-label" for="aplica-{{ $id }}">{{ $nombre }}</label>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="form-text">Sin ninguna marcada, es general: la recibe el médico de cualquier especialidad.</div>
                            </fieldset>
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

    {{-- ============================ Combinar medicamentos duplicados ============================ --}}
    @if ($modal === 'combinar' && $combinar)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-combinar" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <form wire:submit.prevent="combinarFichas">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-combinar">Combinar {{ count($combinar['fichas']) }} medicamentos</h5>
                            <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @error('c.formulario') <div class="alert alert-danger py-2 small">{{ $message }}</div> @enderror
                            <p class="small text-muted">Quedará una sola ficha. Donde los textos difieren, elige uno con su botón o edítalo. Los tratamientos del catálogo inicial que llevaban las demás pasan a llevar esta.</p>

                            <fieldset class="mb-3">
                                <legend class="form-label fs-6 mb-1">Ficha que se conserva (mantiene su código)</legend>
                                @foreach ($combinar['fichas'] as $ficha)
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="sobrevive" id="cmb-sobrevive-{{ $ficha['id'] }}" value="{{ $ficha['id'] }}" wire:model="combinar.sobrevive">
                                        <label class="form-check-label" for="cmb-sobrevive-{{ $ficha['id'] }}"><code>{{ $ficha['codigo'] }}</code> {{ $ficha['nombre'] }}</label>
                                    </div>
                                @endforeach
                            </fieldset>

                            @foreach ($def['campos'] as $columna => $campo)
                                @php [$rotulo, $tipo, $max, $requerido] = $campo; @endphp
                                <div class="mb-3">
                                    @if ($tipo === 'porkilo')
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="cmb-{{ $columna }}" wire:model="c.{{ $columna }}">
                                            <label class="form-check-label" for="cmb-{{ $columna }}">{{ $rotulo }}</label>
                                        </div>
                                    @else
                                        <label class="form-label" for="cmb-{{ $columna }}">{{ $rotulo }}@if ($requerido) <span class="text-danger" aria-hidden="true">*</span>@endif</label>
                                        @if ($tipo === 'area')
                                            <textarea id="cmb-{{ $columna }}" rows="3" class="form-control" wire:model="c.{{ $columna }}"></textarea>
                                        @else
                                            <input type="text" id="cmb-{{ $columna }}" class="form-control" @if ($max > 0) maxlength="{{ $max }}" @endif wire:model="c.{{ $columna }}">
                                        @endif
                                        @if (count($combinar['opciones'][$columna] ?? []) > 1)
                                            <div class="mt-1 d-flex flex-column gap-1">
                                                <span class="form-text mt-0">Los textos difieren:</span>
                                                @foreach ($combinar['opciones'][$columna] as $k => $valor)
                                                    <button type="button" class="btn btn-sm btn-outline-secondary text-start text-break" data-usar-valor="cmb-{{ $columna }}" data-valor="{{ $valor }}">{{ \Illuminate\Support\Str::limit($valor, 160) }}</button>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @endforeach

                            <fieldset>
                                <legend class="form-label fs-6 mb-1">Aplica a</legend>
                                <div class="d-flex flex-wrap gap-3">
                                    @foreach ($especialidades as $id => $nombre)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="cmb-aplica-{{ $id }}" value="{{ $id }}" wire:model="cAplica">
                                            <label class="form-check-label" for="cmb-aplica-{{ $id }}">{{ $nombre }}</label>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="form-text">Se suman las especialidades de todas las fichas. Sin ninguna marcada, es general.</div>
                                @if ($combinar['mezcla'])
                                    <div class="alert alert-warning py-2 small mt-2 mb-0">Alguna ficha era general y otra tenía especialidades: con las marcadas, la ficha dejaría de llegar a los demás médicos. Desmarca todas para dejarla general.</div>
                                @endif
                            </fieldset>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrar">Cancelar</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="combinarFichas"><i class="bi bi-intersect me-1" aria-hidden="true"></i> Combinar</button>
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
            <div class="modal-dialog modal-lateral">
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
                                    <select id="lin-med" class="form-select" wire:model="l.codemedicina">
                                        <option value="">Elige…</option>
                                        @foreach ($medicamentos as $m)
                                            <option value="{{ $m->codemedicina }}">{{ trim($m->nombrecomercial . ' ' . $m->presentacion) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="lin-cant">Cantidad</label>
                                    <input type="text" inputmode="numeric" id="lin-cant" class="form-control" wire:model="l.cantidad">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="lin-ind">Indicaciones</label>
                                    <textarea id="lin-ind" rows="2" class="form-control" wire:model="l.indicaciones"></textarea>
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

    @if ($catalogo === 'medicamentos')
        <script>
            // Sin peticiones al marcar o elegir: la barra de «Combinar» y los botones de textos alternativos trabajan en el navegador.
            if (! window.__combinarMedicamentos) {
                window.__combinarMedicamentos = true;

                const pintar = (barra, ids) => {
                    barra.dataset.sel = JSON.stringify(ids);
                    barra.classList.toggle('d-flex', ids.length > 0);
                    barra.classList.toggle('d-none', ids.length === 0);
                    barra.querySelector('[data-barra-n]').textContent = ids.length + (ids.length === 1 ? ' marcado' : ' marcados');
                    const boton = barra.querySelector('[data-barra-combinar]');
                    boton.disabled = ids.length < 2;
                    boton.lastChild.textContent = ' Combinar (' + ids.length + ')';
                    barra.querySelector('[data-barra-aviso]').classList.toggle('d-none', ids.length >= 2);
                };

                document.addEventListener('change', (e) => {
                    const casilla = e.target.closest('input[wire\\:model\\.defer="seleccion"]');
                    const barra = document.getElementById('barra-combinar');
                    if (! casilla || ! barra) { return; }
                    let ids = JSON.parse(barra.dataset.sel || '[]').map(String).filter((id) => id !== casilla.value);
                    if (casilla.checked) { ids.push(casilla.value); }
                    pintar(barra, ids);
                });

                document.addEventListener('click', (e) => {
                    const quitar = e.target.closest('[data-barra-quitar]');
                    if (quitar) {
                        const barra = quitar.closest('#barra-combinar');
                        document.querySelectorAll('input[wire\\:model\\.defer="seleccion"]').forEach((c) => { c.checked = false; });
                        window.Livewire.find(barra.closest('[wire\\:id]').getAttribute('wire:id')).set('seleccion', [], true);
                        pintar(barra, []);

                        return;
                    }

                    const alterno = e.target.closest('[data-usar-valor]');
                    if (alterno) {
                        const campo = document.getElementById(alterno.dataset.usarValor);
                        campo.value = alterno.dataset.valor;
                        campo.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            }
        </script>
    @endif
</div>
