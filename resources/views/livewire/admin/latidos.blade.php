<div>
    <x-ui.page-head titulo="Latidos"
                    subtitulo="El programa de descuentos por consultorio: disponibles, retenidos y movimientos. 100 Latidos = USD 1 de descuento." />

    <x-ui.avisos />

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-semibold">{{ \App\Latidos\Conversion::formatear($totalDisponibles) }}</div>
                    <div class="text-muted small">Latidos disponibles en total</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-semibold text-warning">{{ \App\Latidos\Conversion::formatear($totalRetenido) }}</div>
                    <div class="text-muted small">Retenidos por pagos pendientes</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-semibold">{{ $consultorios }}</div>
                    <div class="text-muted small">Consultorios con fila de Latidos</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white py-3">
            <input type="text" wire:model.live.debounce.400ms="search" class="form-control"
                   placeholder="Buscar por consultorio (reg_medico)...">
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Médico</th>
                            <th>Consultorio</th>
                            <th class="text-end">Disponibles</th>
                            <th class="text-end">Retenidos</th>
                            <th>Renovación automática</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($latidos as $latido)
                            @php
                                $medico = $nombres[$latido->reg_medico] ?? null;
                                $retenido = (int) ($retenidoPorLatido[$latido->id] ?? 0);
                            @endphp
                            <tr wire:key="latido-{{ $latido->id }}">
                                <td>
                                    <div class="fw-semibold">
                                        {{ $medico ? trim($medico->name . ' ' . $medico->lastname) : '—' }}
                                    </div>
                                    <small class="text-muted">{{ $latido->reg_medico }}</small>
                                </td>
                                <td>{{ $latido->reg_medico }}</td>
                                <td class="text-end text-nowrap fw-semibold">
                                    {{ \App\Latidos\Conversion::formatear((int) $latido->disponibles) }}
                                </td>
                                <td class="text-end text-nowrap {{ $retenido > 0 ? 'text-warning' : 'text-muted' }}">
                                    {{ \App\Latidos\Conversion::formatear($retenido) }}
                                </td>
                                <td>
                                    @if ($latido->renovacion_automatica)
                                        <span class="badge bg-success">Sí</span>
                                        @if ($latido->plan)
                                            <small class="text-muted d-block">{{ $latido->plan->nombre }}</small>
                                        @endif
                                    @else
                                        <span class="badge bg-secondary">No</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <button wire:click="abrir({{ $latido->id }})" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> Detalle
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No hay consultorios con Latidos todavía. Corre <code>php artisan latidos:crear</code>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($latidos->hasPages())
            <div class="card-footer bg-white">
                {{ $latidos->links() }}
            </div>
        @endif
    </div>

    @if ($seleccionado)
        <div class="card mt-3" wire:key="detalle-latido-{{ $seleccionado->id }}">
            <div class="card-header bg-white d-flex justify-content-between align-items-start py-3">
                <div>
                    <h2 class="h5 mb-1">
                        {{ $nombres[$seleccionado->reg_medico] ?? $seleccionado->reg_medico }}
                        <small class="text-muted fs-6">· {{ $seleccionado->reg_medico }}</small>
                    </h2>
                    <div class="text-muted small">
                        Renovación automática:
                        <strong>{{ $seleccionado->renovacion_automatica ? 'encendida' : 'apagada' }}</strong>
                        @if ($seleccionado->plan)
                            · {{ $seleccionado->plan->nombre }}
                        @endif
                    </div>
                </div>
                <button type="button" wire:click="cerrar" class="btn btn-sm btn-outline-secondary" aria-label="Cerrar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Disponibles</div>
                            <div class="fs-5 fw-semibold">
                                {{ \App\Latidos\Conversion::formatear((int) $seleccionado->disponibles) }}
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Retenidos</div>
                            <div class="fs-5 fw-semibold text-warning">
                                {{ \App\Latidos\Conversion::formatear((int) $seleccionado->retenciones->where('estado', 'activa')->sum('latidos')) }}
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Valor de descuento</div>
                            <div class="fs-5 fw-semibold">
                                {{ \App\Pagos\Centavos::formatear(\App\Latidos\Conversion::aUsdE2((int) $seleccionado->disponibles), 'USD') }}
                            </div>
                        </div>
                    </div>
                </div>

                @if ($esRoot)
                    <div class="border rounded p-3 mb-4">
                        <h3 class="h6">Ajuste manual</h3>
                        <p class="text-muted small mb-2">
                            Positivo acredita, negativo descuenta. Queda con tu usuario y el motivo.
                            Si el ajuste deja los Latidos en negativo, marca la casilla.
                        </p>
                        <form wire:submit.prevent="ajustar" class="row g-2 align-items-end">
                            <div class="col-12 col-md-3">
                                <label class="form-label small" for="ajuste-latidos">Latidos</label>
                                <input id="ajuste-latidos" type="number" step="1" wire:model="ajusteLatidos"
                                       class="form-control @error('ajusteLatidos') is-invalid @enderror"
                                       placeholder="Ej. 2000 o -500">
                                @error('ajusteLatidos') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label small" for="ajuste-motivo">Motivo</label>
                                <input id="ajuste-motivo" type="text" wire:model="ajusteMotivo"
                                       class="form-control @error('ajusteMotivo') is-invalid @enderror"
                                       placeholder="Ej. Cortesía autorizada por Alexander">
                                @error('ajusteMotivo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="permitir-negativo"
                                           wire:model="permitirNegativo">
                                    <label class="form-check-label small" for="permitir-negativo">Permitir negativo</label>
                                </div>
                            </div>
                            <div class="col-12 col-md-2 d-grid">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                                    Ajustar
                                </button>
                            </div>
                        </form>
                        @error('ajuste')
                            <div class="alert alert-danger py-2 mt-2 mb-0">{{ $message }}</div>
                        @enderror
                    </div>
                @endif

                <h3 class="h6">Últimos movimientos</h3>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th class="text-end">Latidos</th>
                                <th class="text-end">Antes</th>
                                <th class="text-end">Después</th>
                                <th>Concepto</th>
                                <th>Nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($seleccionado->movimientos as $movimiento)
                                <tr wire:key="movimiento-{{ $movimiento->id }}">
                                    <td class="text-nowrap small">{{ $movimiento->created_at?->format('d/m/Y H:i') }}</td>
                                    <td><span class="badge bg-light text-dark">{{ $movimiento->tipo }}</span></td>
                                    <td class="text-end text-nowrap fw-semibold {{ (int) $movimiento->latidos < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ \App\Latidos\Conversion::formatear((int) $movimiento->latidos) }}
                                    </td>
                                    <td class="text-end text-nowrap">{{ \App\Latidos\Conversion::formatear((int) $movimiento->disponibles_antes) }}</td>
                                    <td class="text-end text-nowrap">{{ \App\Latidos\Conversion::formatear((int) $movimiento->disponibles_despues) }}</td>
                                    <td>{{ $movimiento->concepto }}</td>
                                    <td class="small text-muted">{{ $movimiento->nota ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-3">Sin movimientos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <h3 class="h6 mt-4">Retenciones</h3>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Pago</th>
                                <th class="text-end">Latidos</th>
                                <th>Estado</th>
                                <th>Vence</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($seleccionado->retenciones as $retencion)
                                <tr wire:key="retencion-{{ $retencion->id }}">
                                    <td>#{{ $retencion->pago_id }}</td>
                                    <td class="text-end text-nowrap">{{ \App\Latidos\Conversion::formatear((int) $retencion->latidos) }}</td>
                                    <td><span class="badge bg-{{ $retencion->estado === 'activa' ? 'warning text-dark' : 'secondary' }}">{{ $retencion->estado }}</span></td>
                                    <td class="text-nowrap small">{{ $retencion->expira_el?->format('d/m/Y H:i') ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">Sin retenciones.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
