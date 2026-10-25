<div>
    <x-ui.page-head titulo="Pagos"
                    subtitulo="Lo que entró por SyPago: estado, bitácora del webhook y conciliación manual." />

    <x-ui.avisos />

    @php
        $estados = [
            'todos' => 'Todos',
            \App\Models\Pago::PENDIENTE => 'Pendientes',
            \App\Models\Pago::EN_PROCESO => 'En proceso',
            \App\Models\Pago::APROBADO => 'Aprobados',
            \App\Models\Pago::RECHAZADO => 'Rechazados',
            \App\Models\Pago::EXPIRADO => 'Vencidos',
            \App\Models\Pago::CANCELADO => 'Cancelados',
            \App\Models\Pago::REEMBOLSADO => 'Reembolsados',
        ];
        $canales = [
            'todos' => 'Todos los canales',
            \App\Models\Pago::CANAL_PLAN => 'Plan',
            \App\Models\Pago::CANAL_LINK => 'Link de pago',
            \App\Models\Pago::CANAL_RESERVA_ONLINE => 'Reserva online',
            \App\Models\Pago::CANAL_MOSTRADOR => 'Mostrador',
        ];
    @endphp

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-semibold">{{ $conteos['todos'] }}</div>
                    <div class="text-muted small">Pagos registrados</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-semibold text-success">{{ \App\Pagos\Centavos::formatear($aprobadoUsdE2, 'USD') }}</div>
                    <div class="text-muted small">Aprobado en USD</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-semibold">{{ \App\Pagos\Centavos::formatear($aprobadoVesE2, 'VES') }}</div>
                    <div class="text-muted small">Aprobado en VES</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-semibold text-warning">{{ $conteos[\App\Models\Pago::PENDIENTE] + $conteos[\App\Models\Pago::EN_PROCESO] }}</div>
                    <div class="text-muted small">Pendientes o en proceso</div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach ($estados as $clave => $etiqueta)
            <button type="button" wire:click="$set('estado', '{{ $clave }}')" wire:key="chip-{{ $clave }}"
                    class="btn btn-sm {{ $estado === $clave ? 'btn-dark' : 'btn-outline-secondary' }}">
                {{ $etiqueta }} <span class="badge bg-light text-dark ms-1">{{ $conteos[$clave] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3">
            <div class="row g-2">
                <div class="col-md-6">
                    <input type="text" wire:model.live.debounce.400ms="search" class="form-control"
                           placeholder="Buscar por referencia, consultorio o concepto...">
                </div>
                <div class="col-md-3">
                    <select class="form-select" wire:model.live="estado" aria-label="Filtrar por estado">
                        @foreach ($estados as $clave => $etiqueta)
                            <option value="{{ $clave }}">{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" wire:model.live="canal" aria-label="Filtrar por canal">
                        @foreach ($canales as $clave => $etiqueta)
                            <option value="{{ $clave }}">{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Fecha</th>
                            <th>Médico</th>
                            <th>Concepto</th>
                            <th class="text-end">Monto</th>
                            <th>Estado</th>
                            <th>Método</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pagos as $pago)
                            @php $medico = $nombres[$pago->reg_medico] ?? null; @endphp
                            <tr wire:key="pago-{{ $pago->id }}">
                                <td class="text-nowrap">
                                    <div>{{ \App\Support\HoraLocal::instante($pago->created_at)?->format(\App\Support\Regionalizacion::formatoActual()) }}</div>
                                    <small class="text-muted">{{ \App\Support\HoraLocal::hora($pago->created_at) }}</small>
                                </td>
                                <td>
                                    <div class="fw-semibold">
                                        {{ $medico ? trim($medico->name . ' ' . $medico->lastname) : ($pago->reg_medico ?: '—') }}
                                    </div>
                                    <small class="text-muted">{{ $pago->reg_medico ?: '—' }}</small>
                                </td>
                                <td>
                                    <div>{{ $pago->concepto }}</div>
                                    <small class="text-muted">{{ $canales[$pago->canal] ?? $pago->canal }}</small>
                                </td>
                                <td class="text-end text-nowrap">
                                    <div class="fw-semibold">{{ $pago->montoUsdTexto() }}</div>
                                    <small class="text-muted">{{ $pago->montoTexto() }}</small>
                                </td>
                                <td>
                                    <span class="badge bg-{{ $this->estadoTono($pago->estado) }}">{{ $this->estadoLegible($pago->estado) }}</span>
                                </td>
                                <td>{{ $pago->metodoPago?->etiqueta() ?? '—' }}</td>
                                <td class="text-end text-nowrap">
                                    <button wire:click="abrir({{ $pago->id }})" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> Detalle
                                    </button>
                                    @unless ($pago->estaAprobado())
                                        <button wire:click="consultar({{ $pago->id }})" class="btn btn-sm btn-outline-secondary"
                                                wire:loading.attr="disabled" wire:target="consultar">
                                            <i class="bi bi-arrow-clockwise"></i> Consultar
                                        </button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No hay pagos con esos filtros.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($pagos->hasPages())
            <div class="card-footer bg-white">
                {{ $pagos->links() }}
            </div>
        @endif
    </div>

    {{-- ===================== Detalle del pago ===================== --}}
    @if ($pagoId && $seleccionado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Pago #{{ $seleccionado->id }}
                            <span class="badge bg-{{ $this->estadoTono($seleccionado->estado) }} ms-2">{{ $this->estadoLegible($seleccionado->estado) }}</span>
                        </h5>
                        <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-lg-6">
                                <dl class="row mb-0">
                                    <dt class="col-5 fw-normal text-muted">Fecha</dt>
                                    <dd class="col-7">{{ \App\Support\HoraLocal::fechaHoraConSegundos($seleccionado->created_at) }}</dd>

                                    <dt class="col-5 fw-normal text-muted">Médico</dt>
                                    <dd class="col-7">
                                        @php $medicoDetalle = $nombres[$seleccionado->reg_medico] ?? null; @endphp
                                        {{ $medicoDetalle ? trim($medicoDetalle->name . ' ' . $medicoDetalle->lastname) : '—' }}
                                        <div><small class="text-muted">{{ $seleccionado->reg_medico ?: '—' }}</small></div>
                                    </dd>

                                    <dt class="col-5 fw-normal text-muted">Concepto</dt>
                                    <dd class="col-7">
                                        {{ $seleccionado->concepto }}
                                        <div><small class="text-muted">{{ $this->canalLegible($seleccionado->canal) }} · {{ $seleccionado->pagable_tipo }}</small></div>
                                    </dd>

                                    <dt class="col-5 fw-normal text-muted">Método</dt>
                                    <dd class="col-7">{{ $seleccionado->metodoPago?->etiqueta() ?? '—' }}</dd>

                                    <dt class="col-5 fw-normal text-muted">Monto</dt>
                                    <dd class="col-7">
                                        <strong>{{ $seleccionado->montoUsdTexto() }}</strong>
                                        <div><small class="text-muted">{{ $seleccionado->montoTexto() }}</small></div>
                                    </dd>

                                    <dt class="col-5 fw-normal text-muted">Tasa</dt>
                                    <dd class="col-7">{{ $seleccionado->tasa ? number_format((float) $seleccionado->tasa, 4, ',', '.') : '—' }}</dd>

                                    <dt class="col-5 fw-normal text-muted">Comisiones</dt>
                                    <dd class="col-7">{{ \App\Pagos\Centavos::formatear((int) $seleccionado->comision_total_usd_e2, 'USD') }}</dd>

                                    <dt class="col-5 fw-normal text-muted">Neto del médico</dt>
                                    <dd class="col-7">{{ $seleccionado->netoUsdTexto() }}</dd>
                                </dl>
                            </div>
                            <div class="col-lg-6">
                                <dl class="row mb-0">
                                    <dt class="col-5 fw-normal text-muted">Referencia</dt>
                                    <dd class="col-7"><code class="small">{{ $seleccionado->referencia }}</code></dd>

                                    <dt class="col-5 fw-normal text-muted">internal_id</dt>
                                    <dd class="col-7"><code class="small">{{ $seleccionado->internal_id ?: '—' }}</code></dd>

                                    <dt class="col-5 fw-normal text-muted">Referencia externa</dt>
                                    <dd class="col-7"><code class="small">{{ $seleccionado->referencia_externa ?: '—' }}</code></dd>

                                    <dt class="col-5 fw-normal text-muted">Pagado el</dt>
                                    <dd class="col-7">{{ \App\Support\HoraLocal::fechaHora($seleccionado->pagado_el, '—') }}</dd>

                                    <dt class="col-5 fw-normal text-muted">Vence el</dt>
                                    <dd class="col-7">{{ \App\Support\HoraLocal::fechaHora($seleccionado->expira_el, '—') }}</dd>

                                    <dt class="col-5 fw-normal text-muted">Liquidación</dt>
                                    <dd class="col-7">{{ $seleccionado->estado_liquidacion }}</dd>

                                    <dt class="col-5 fw-normal text-muted">Beneficiario</dt>
                                    <dd class="col-7">{{ $seleccionado->beneficiario_tipo }}</dd>

                                    <dt class="col-5 fw-normal text-muted">Recaudador</dt>
                                    <dd class="col-7">{{ $seleccionado->recaudador }}</dd>
                                </dl>
                            </div>
                        </div>

                        @if (! empty($seleccionado->datos_pagador))
                            <hr>
                            <p class="fw-semibold mb-2">Datos del pagador</p>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        @foreach ($seleccionado->datos_pagador as $campo => $valor)
                                            <tr>
                                                <th class="fw-normal text-muted" style="width: 30%">{{ $campo }}</th>
                                                <td>{{ is_scalar($valor) ? $valor : json_encode($valor) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if ($seleccionado->comisiones->isNotEmpty())
                            <hr>
                            <p class="fw-semibold mb-2">Comisiones</p>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Tipo</th>
                                            <th class="text-end">Base USD</th>
                                            <th class="text-end">%</th>
                                            <th class="text-end">Comisión USD</th>
                                            <th class="text-end">Comisión VES</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($seleccionado->comisiones as $comision)
                                            <tr>
                                                <td>{{ $comision->secuencia }}</td>
                                                <td>{{ $comision->tipo }}</td>
                                                <td class="text-end">{{ \App\Pagos\Centavos::formatear((int) $comision->base_usd_e2, 'USD') }}</td>
                                                <td class="text-end">{{ \App\Pagos\Porcentaje::aTexto((int) $comision->porcentaje_bp) }}</td>
                                                <td class="text-end">{{ \App\Pagos\Centavos::formatear((int) $comision->monto_usd_e2, 'USD') }}</td>
                                                <td class="text-end">{{ \App\Pagos\Centavos::formatear((int) $comision->monto_ves_e2, 'VES') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <hr>
                        <p class="fw-semibold mb-2">Bitácora</p>
                        <div class="table-responsive" style="max-height: 18rem; overflow-y: auto;">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Cuándo</th>
                                        <th>Origen</th>
                                        <th>Evento</th>
                                        <th>Estado</th>
                                        <th>Payload</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($eventos as $fila)
                                        <tr wire:key="evento-{{ $fila['evento']->id }}">
                                            <td class="text-nowrap">{{ \App\Support\HoraLocal::fechaHoraConSegundos($fila['evento']->created_at) }}</td>
                                            <td>{{ $fila['evento']->origen }}</td>
                                            <td>{{ $fila['evento']->evento }}</td>
                                            <td class="text-nowrap">{{ $fila['evento']->estado_anterior ?? '—' }} → {{ $fila['evento']->estado_nuevo ?? '—' }}</td>
                                            <td>
                                                @if (! empty($fila['payload']))
                                                    <details>
                                                        <summary class="small">Ver</summary>
                                                        <pre class="small mb-0" style="max-height: 10rem; overflow: auto;">{{ json_encode($fila['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    </details>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-center text-muted py-3">Sin eventos.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if (! empty($respuestaSegura))
                            <details class="mt-3">
                                <summary class="small text-muted">Respuesta de SyPago</summary>
                                <pre class="small mb-0" style="max-height: 18rem; overflow: auto;">{{ json_encode($respuestaSegura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @endif
                    </div>
                    <div class="modal-footer">
                        @unless ($seleccionado->estaAprobado())
                            <button type="button" wire:click="consultar({{ $seleccionado->id }})" class="btn btn-outline-secondary"
                                    wire:loading.attr="disabled" wire:target="consultar">
                                <i class="bi bi-arrow-clockwise me-1"></i> Consultar ahora
                            </button>
                        @endunless
                        <button type="button" class="btn btn-secondary" wire:click="cerrar">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
