<div>
    <x-ui.page-head titulo="Referidos"
                    subtitulo="El programa de referidos: configuración, premios y anulación. Los premios se miden en Latidos." />

    <x-ui.avisos />
    <x-ui.errores />

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body py-3">
                <div class="fs-4 fw-semibold text-success">{{ $totalAcreditado }}</div>
                <div class="text-muted small">Acreditados</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body py-3">
                <div class="fs-4 fw-semibold text-warning">{{ $totalRechazado }}</div>
                <div class="text-muted small">Rechazados</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body py-3">
                <div class="fs-4 fw-semibold text-secondary">{{ $totalAnulado }}</div>
                <div class="text-muted small">Anulados</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body py-3">
                <div class="fs-5 fw-semibold">{{ $referidos->total() }}</div>
                <div class="text-muted small">Referidos en total</div>
            </div></div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white py-3"><strong>Configuración</strong></div>
        <div class="card-body">
            <form wire:submit.prevent="guardarConfiguracion" class="row g-2 align-items-end">
                <div class="col-12 col-md-2">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="referidos-activos"
                               wire:model="referidosActivos">
                        <label class="form-check-label small" for="referidos-activos">Programa activo</label>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="latidos-referente">Latidos referente</label>
                    <input id="latidos-referente" type="number" min="0" wire:model="latidosReferente"
                           class="form-control @error('latidosReferente') is-invalid @enderror">
                    @error('latidosReferente') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="latidos-referido">Latidos referido</label>
                    <input id="latidos-referido" type="number" min="0" wire:model="latidosReferido"
                           class="form-control @error('latidosReferido') is-invalid @enderror">
                    @error('latidosReferido') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="vigencia-dias">Días de atribución</label>
                    <input id="vigencia-dias" type="number" min="1" max="365" wire:model="vigenciaDias"
                           class="form-control @error('vigenciaDias') is-invalid @enderror">
                    @error('vigenciaDias') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="tope-mensual">Tope mensual (0 = sin tope)</label>
                    <input id="tope-mensual" type="number" min="0" wire:model="topeMensual"
                           class="form-control @error('topeMensual') is-invalid @enderror">
                    @error('topeMensual') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="url-descarga">URL de descarga (opcional)</label>
                    <input id="url-descarga" type="url" wire:model="urlDescarga"
                           class="form-control @error('urlDescarga') is-invalid @enderror"
                           placeholder="https://play.google.com/...">
                    @error('urlDescarga') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
            <p class="text-muted small mb-0 mt-2">
                100 Latidos = USD 1 de descuento. El premio se acredita al aprobar el registro, una sola vez
                por consultorio referido.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white py-3"><strong>Referidos</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Referente</th>
                            <th>Referido</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th class="text-end">Latidos</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($referidos as $referido)
                            @php
                                $referente = $referido->referente;
                                $colega = $referido->referido;
                                $tono = match ($referido->estado) {
                                    \App\Models\Referido::ACREDITADO => 'success',
                                    \App\Models\Referido::RECHAZADO => 'warning text-dark',
                                    default => 'secondary',
                                };
                            @endphp
                            <tr wire:key="referido-{{ $referido->id }}">
                                <td>
                                    <div class="fw-semibold">
                                        {{ $referente ? trim($referente->name . ' ' . $referente->lastname) : '—' }}
                                    </div>
                                    <small class="text-muted">{{ $referido->referente_reg_medico }}</small>
                                </td>
                                <td>
                                    <div class="fw-semibold">
                                        {{ $colega ? trim($colega->name . ' ' . $colega->lastname) : '—' }}
                                    </div>
                                    <small class="text-muted">{{ $referido->referido_reg_medico }}</small>
                                </td>
                                <td class="text-nowrap small">{{ $referido->created_at?->format('d/m/Y H:i') }}</td>
                                <td>
                                    <span class="badge bg-{{ $tono }}">{{ ucfirst($referido->estado) }}</span>
                                    @if ($referido->motivo)
                                        <small class="text-muted d-block">{{ $referido->motivo }}</small>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <div>{{ \App\Latidos\Conversion::formatear((int) $referido->latidos_referente) }}</div>
                                    <small class="text-muted">al referido: {{ \App\Latidos\Conversion::formatear((int) $referido->latidos_referido) }}</small>
                                </td>
                                <td class="text-end">
                                    @if ($referido->estaAcreditado())
                                        <button type="button" wire:click="abrir({{ $referido->id }})"
                                                class="btn btn-sm btn-outline-danger">Anular</button>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Todavía no hay referidos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($referidos->hasPages())
            <div class="card-footer bg-white">{{ $referidos->links() }}</div>
        @endif
    </div>

    @if ($seleccionado)
        <div class="card mt-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-start py-3">
                <div>
                    <strong>Anular referido #{{ $seleccionado->id }}</strong>
                    <div class="small text-muted">
                        {{ $seleccionado->referente_reg_medico }} → {{ $seleccionado->referido_reg_medico }}
                    </div>
                </div>
                <button type="button" wire:click="cerrar" class="btn btn-sm btn-outline-secondary" aria-label="Cerrar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    Se devuelven {{ \App\Latidos\Conversion::formatear((int) $seleccionado->latidos_referente) }} al
                    referente y {{ \App\Latidos\Conversion::formatear((int) $seleccionado->latidos_referido) }} al
                    referido. Si alguno ya gastó los Latidos, no se fuerza el negativo y hay que ajustarlo a mano.
                </p>
                <form wire:submit.prevent="anular" class="row g-2 align-items-end">
                    <div class="col-12 col-md-8">
                        <label class="form-label small" for="motivo-anulacion">Motivo</label>
                        <input id="motivo-anulacion" type="text" wire:model="motivoAnulacion"
                               class="form-control @error('motivoAnulacion') is-invalid @enderror"
                               placeholder="Ej. registro duplicado o fraude">
                        @error('motivoAnulacion') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12 col-md-4 d-grid">
                        <button type="submit" class="btn btn-danger">Anular y revertir</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
