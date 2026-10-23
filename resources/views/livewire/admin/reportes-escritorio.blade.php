<div>
    <x-ui.page-head titulo="Reportes del escritorio"
                    subtitulo="Fallos que mandan el instalador, el launcher y la sincronización de los consultorios. Se borran cuando el problema ya está resuelto." />

    <x-ui.avisos />

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label" for="buscar-reportes">Buscar</label>
                    <input id="buscar-reportes" type="text" class="form-control" placeholder="Médico, equipo o mensaje"
                           wire:model.debounce.400ms="buscar">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="tipo-reportes">Origen</label>
                    <select id="tipo-reportes" class="form-select" wire:model="tipo">
                        <option value="">Todos</option>
                        @foreach ($tipos as $clave => $etiqueta)
                            <option value="{{ $clave }}">{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 text-md-end text-muted small">
                    {{ $total }} en total
                </div>
            </div>
        </div>
    </div>

    @if ($abierto)
        <div class="card mb-3 border-primary">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Reporte #{{ $abierto->id }} · {{ $abierto->etiquetaDelTipo() }}</strong>
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cerrar">Cerrar</button>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Cuándo</dt>
                    <dd class="col-sm-9">{{ $abierto->created_at->format('d/m/Y H:i') }}</dd>

                    <dt class="col-sm-3">Médico</dt>
                    <dd class="col-sm-9">{{ $abierto->reg_medico ?: '—' }}</dd>

                    <dt class="col-sm-3">Etapa</dt>
                    <dd class="col-sm-9">{{ $abierto->etapa ?: '—' }}</dd>

                    <dt class="col-sm-3">Mensaje</dt>
                    <dd class="col-sm-9">{{ $abierto->mensaje }}</dd>

                    <dt class="col-sm-3">Equipo</dt>
                    <dd class="col-sm-9">{{ $abierto->equipo ?: '—' }}</dd>

                    <dt class="col-sm-3">Aplicación</dt>
                    <dd class="col-sm-9">{{ $abierto->aplicacion ?: '—' }}</dd>

                    <dt class="col-sm-3">Versión del canal</dt>
                    <dd class="col-sm-9">{{ $abierto->version_canal ?: '—' }}</dd>

                    <dt class="col-sm-3">Windows</dt>
                    <dd class="col-sm-9">{{ $abierto->windows ?: '—' }}</dd>

                    <dt class="col-sm-3">IP</dt>
                    <dd class="col-sm-9">{{ $abierto->ip ?: '—' }}</dd>
                </dl>

                @if ($abierto->detalle)
                    <hr>
                    <h6 class="text-muted">Detalle</h6>
                    <pre class="bg-light border rounded p-2 small mb-0" style="max-height: 320px; overflow: auto; white-space: pre-wrap;">{{ $abierto->detalle }}</pre>
                @endif
            </div>
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Origen</th>
                        <th>Médico</th>
                        <th>Equipo</th>
                        <th>Aplicación</th>
                        <th>Mensaje</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reportes as $reporte)
                        <tr class="{{ (int) $seleccionado === $reporte->id ? 'table-active' : '' }}">
                            <td class="text-nowrap">{{ $reporte->created_at->format('d/m/Y H:i') }}</td>
                            <td><span class="badge bg-secondary">{{ $reporte->etiquetaDelTipo() }}</span></td>
                            <td>{{ $reporte->reg_medico ?: '—' }}</td>
                            <td>{{ $reporte->equipo ?: '—' }}</td>
                            <td>{{ $reporte->aplicacion ?: '—' }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($reporte->mensaje, 90) }}</td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="ver({{ $reporte->id }})">Ver</button>

                                @if ($porBorrar === $reporte->id)
                                    <button type="button" class="btn btn-sm btn-danger" wire:click="eliminar({{ $reporte->id }})">Confirmar</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelarBorrado">No</button>
                                @else
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmarBorrado({{ $reporte->id }})">Borrar</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No hay reportes.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($reportes->hasPages())
            <div class="card-footer">
                {{ $reportes->links() }}
            </div>
        @endif
    </div>
</div>
