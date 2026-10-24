<div>
    <x-ui.page-head titulo="Catálogo inicial · Referencia de crecimiento"
                    subtitulo="Los percentiles de peso, talla, perímetros e IMC que ve todo médico de pediatría que no cargó su propia referencia. Cada fila es un rango de edad en días con tres valores por medida: bajo, medio y alto.">
        <x-slot name="acciones">
            <button type="button" class="btn btn-primary" wire:click="abrir">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva fila
            </button>
        </x-slot>
    </x-ui.page-head>


    <x-ui.avisos />

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="btn-group" role="group" aria-label="Referencia de niños o de niñas">
                <button type="button" class="btn btn-sm {{ $sexo === 'nino' ? 'btn-primary' : 'btn-outline-primary' }}" wire:click="elegir('nino')">Niños ({{ $cuentas['nino'] }})</button>
                <button type="button" class="btn btn-sm {{ $sexo === 'nina' ? 'btn-primary' : 'btn-outline-primary' }}" wire:click="elegir('nina')">Niñas ({{ $cuentas['nina'] }})</button>
            </div>
            <span class="text-muted small">{{ count($filas) }} {{ count($filas) === 1 ? 'rango' : 'rangos' }} de edad</span>
        </div>

        @if ($huecos !== [])
            <div class="alert alert-warning rounded-0 mb-0 py-2 small" role="status">
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                Sin referencia entre:
                @foreach ($huecos as $h) días {{ $h['desde'] }} y {{ $h['hasta'] }}{{ $loop->last ? '.' : ';' }} @endforeach
            </div>
        @endif

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Edad</th>
                            <th>Días</th>
                            @foreach ($medidas as $clave => $nombre)
                                <th>{{ $nombre }} <span class="fw-normal small">(bajo · medio · alto)</span></th>
                            @endforeach
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($filas as $fila)
                            <tr wire:key="pct-{{ $sexo }}-{{ $fila->id }}">
                                <td class="fw-semibold">{{ $fila->edad_texto }}</td>
                                <td class="text-nowrap">{{ $fila->edad_ini_dia }} – {{ $fila->edad_fin_dia }}</td>
                                @foreach ($medidas as $clave => $nombre)
                                    @php $v = [$fila->{$clave . '_b'}, $fila->{$clave . '_m'}, $fila->{$clave . '_a'}]; @endphp
                                    <td class="text-nowrap">
                                        @if ($v[0] === null && $v[1] === null && $v[2] === null)
                                            <span class="text-muted">—</span>
                                        @else
                                            {{ implode(' · ', array_map(fn ($x) => $x === null ? '—' : rtrim(rtrim(number_format((float) $x, 2, ',', ''), '0'), ','), $v)) }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrir({{ $fila->id }})" aria-label="Editar {{ $fila->edad_texto }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="abrirBorrar({{ $fila->id }})" aria-label="Eliminar {{ $fila->edad_texto }}">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 3 + count($medidas) }}" class="text-center text-muted py-4">
                                    Todavía no hay referencia inicial para {{ $sexo === 'nino' ? 'niños' : 'niñas' }}. Agrega el primer rango con «Nueva fila».
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================ Alta y edición de una fila ============================ --}}
    @if ($modal === 'fila')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-pct" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lateral">
                <div class="modal-content">
                    <form wire:submit.prevent="guardar">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-pct">{{ $filaId ? 'Editar el rango' : 'Nuevo rango' }} · {{ $sexo === 'nino' ? 'niños' : 'niñas' }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="pct-ini">Desde (día)</label>
                                    <input type="number" min="0" id="pct-ini" class="form-control" wire:model="f.edad_ini_dia">
                                    @error('f.edad_ini_dia') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="pct-fin">Hasta (día)</label>
                                    <input type="number" min="0" id="pct-fin" class="form-control" wire:model="f.edad_fin_dia">
                                    @error('f.edad_fin_dia') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="pct-texto">Texto de la edad</label>
                                    <input type="text" id="pct-texto" class="form-control" maxlength="100" wire:model="f.edad_texto" placeholder="3 a 6 meses">
                                    @error('f.edad_texto') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="pct-meses">Edad en meses</label>
                                    <input type="text" inputmode="numeric" id="pct-meses" class="form-control" wire:model="f.edad_meses">
                                    @error('f.edad_meses') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="pct-anos">Edad en años</label>
                                    <input type="text" inputmode="decimal" id="pct-anos" class="form-control" wire:model="f.anos">
                                    @error('f.anos') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-12 col-md-6 d-flex align-items-end">
                                    <p class="text-muted small mb-0">Si dejas el texto, los meses o los años vacíos, se calculan con los días. Dos rangos no pueden solaparse, pero sí compartir el borde.</p>
                                </div>
                            </div>

                            <hr>
                            <p class="small text-muted mb-2">Cada medida va completa o vacía, con <strong>bajo ≤ medio ≤ alto</strong>. Carga al menos el peso o la talla.</p>
                            @error('f.medidas') <div class="alert alert-danger py-2 small">{{ $message }}</div> @enderror

                            @foreach ($medidas as $clave => $nombre)
                                <div class="row g-2 mb-2 align-items-end">
                                    <div class="col-12 col-md-3"><strong>{{ $nombre }}</strong></div>
                                    @foreach (['b' => 'Bajo', 'm' => 'Medio', 'a' => 'Alto'] as $letra => $rotulo)
                                        <div class="col-4 col-md-3">
                                            <label class="form-label small mb-0" for="pct-{{ $clave }}-{{ $letra }}">{{ $rotulo }}</label>
                                            <input type="text" inputmode="decimal" id="pct-{{ $clave }}-{{ $letra }}" class="form-control form-control-sm" wire:model="f.{{ $clave }}_{{ $letra }}">
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrar">Cancelar</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardar">
                                <i class="bi bi-check2 me-1" aria-hidden="true"></i> Guardar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Eliminar una fila ============================ --}}
    @if ($modal === 'borrar')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-pct-borrar" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="titulo-pct-borrar">Eliminar el rango</h5>
                        <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">Se quita de la referencia inicial: los niños de esa edad quedan sin referencia para los médicos que no cargaron la suya. Los consultorios con su propia referencia no cambian.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrar">Cancelar</button>
                        <button type="button" class="btn btn-danger" wire:click="borrar" wire:loading.attr="disabled" wire:target="borrar">
                            <i class="bi bi-trash me-1" aria-hidden="true"></i> Eliminar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
