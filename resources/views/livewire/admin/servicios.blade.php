<div class="mt-4">
    <div class="row mb-3 align-items-center">
        <div class="col-md-8">
            <h4 class="fw-bold mb-0">Planes y servicios</h4>
            <small class="text-muted">
                Qué plan tiene cada médico y hasta cuándo. Con el servicio vencido (pasados 5 días de gracia) se suspende solo la sincronización.
            </small>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button type="button" class="nav-link {{ $pestana === 'servicios' ? 'active' : '' }}" wire:click="cambiarPestana('servicios')">
                <i class="bi bi-people me-1"></i> Servicios por médico
            </button>
        </li>
        <li class="nav-item">
            <button type="button" class="nav-link {{ $pestana === 'planes' ? 'active' : '' }}" wire:click="cambiarPestana('planes')">
                <i class="bi bi-card-checklist me-1"></i> Planes
            </button>
        </li>
    </ul>

    {{-- ========================= Servicios por médico ========================= --}}
    @if ($pestana === 'servicios')
        @php
            $chips = [
                'todos' => ['Todos', 'secondary'],
                'vigente' => ['Vigentes', 'success'],
                'por_vencer' => ['Por vencer (30 días o menos)', 'warning'],
                'gracia' => ['En gracia', 'warning'],
                'vencido' => ['Vencidos', 'danger'],
                'sin_servicio' => ['Sin servicio', 'secondary'],
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

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <div class="row">
                    <div class="col-md-5">
                        <input type="text" wire:model.debounce.400ms="search" class="form-control"
                               placeholder="Buscar por nombre o registro...">
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Médico</th>
                                <th>Plan</th>
                                <th>Vence</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($medicos as $medico)
                                @php $e = $filas[$medico->id]; @endphp
                                <tr wire:key="medico-{{ $medico->id }}">
                                    <td>
                                        <div class="fw-semibold">{{ trim($medico->name . ' ' . $medico->lastname) }}</div>
                                        <small class="text-muted">{{ $medico->reg_medico }}</small>
                                    </td>
                                    <td>{{ $e['plan'] ?? '—' }}</td>
                                    <td>
                                        @if ($e['vence_el'])
                                            {{ \Carbon\Carbon::parse($e['vence_el'])->format('d/m/Y') }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($e['estado'] === 'vigente')
                                            <span class="badge bg-success">Vigente</span>
                                            @if ($e['dias_restantes'] <= \App\Http\Livewire\Admin\Servicios::POR_VENCER_DIAS)
                                                <span class="badge bg-warning text-dark">Vence en {{ $e['dias_restantes'] }} {{ $e['dias_restantes'] === 1 ? 'día' : 'días' }}</span>
                                            @endif
                                        @elseif ($e['estado'] === 'gracia')
                                            <span class="badge bg-warning text-dark">En gracia</span>
                                            <div><small class="text-muted">Sincroniza hasta el {{ \Carbon\Carbon::parse($e['gracia_hasta'])->format('d/m/Y') }}</small></div>
                                        @elseif ($e['estado'] === 'vencido')
                                            <span class="badge bg-danger">Vencido</span>
                                            <div><small class="text-muted">Sin sincronizar</small></div>
                                        @else
                                            <span class="badge bg-secondary">Sin servicio</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button wire:click="abrirRenovar({{ $medico->id }})" class="btn btn-sm btn-primary">
                                            <i class="bi bi-arrow-repeat me-1"></i> Renovar
                                        </button>
                                        <button wire:click="verHistorial({{ $medico->id }})" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-clock-history me-1"></i> Historial
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No se encontraron médicos.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($medicos->hasPages())
                <div class="card-footer bg-white">
                    {{ $medicos->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- ================================ Planes ================================ --}}
    @if ($pestana === 'planes')
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="text-muted small">
                    El plan <strong>predeterminado</strong> es el que recibe un médico al registrarse: su primer mes es gratis aunque el plan sea de pago.
                </span>
                <button wire:click="nuevoPlan" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Nuevo plan
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Plan</th>
                                <th>Frecuencia</th>
                                <th class="text-end">Monto (USD)</th>
                                <th class="text-end">Tachado</th>
                                <th class="text-end">Ahorro</th>
                                <th class="text-center">Contratos</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($planes as $plan)
                                <tr wire:key="plan-{{ $plan->id }}" class="{{ $plan->activo ? '' : 'text-muted' }}">
                                    <td>
                                        <div class="fw-semibold">{{ $plan->nombre }}</div>
                                        <small class="text-muted">{{ $plan->codigo }}</small>
                                    </td>
                                    <td>{{ $plan->frecuencia === 'anual' ? 'Anual (12 meses)' : 'Mensual' }}</td>
                                    <td class="text-end">{{ number_format((float) $plan->precio_usd, 2, ',', '.') }}</td>
                                    <td class="text-end">
                                        @if ($plan->precio_tachado_usd !== null)
                                            <del>{{ number_format((float) $plan->precio_tachado_usd, 2, ',', '.') }}</del>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($plan->ahorroUsd() > 0)
                                            <span class="text-success">{{ number_format($plan->ahorroUsd(), 2, ',', '.') }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $contratos[$plan->id] ?? 0 }}</td>
                                    <td>
                                        @if ($plan->es_default)
                                            <span class="badge bg-primary">Predeterminado</span>
                                        @endif
                                        <span class="badge {{ $plan->activo ? 'bg-success' : 'bg-secondary' }}">{{ $plan->activo ? 'Activo' : 'Inactivo' }}</span>
                                        @unless ($plan->visible)
                                            <span class="badge bg-light text-dark border">Oculto</span>
                                        @endunless
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button wire:click="editarPlan({{ $plan->id }})" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil me-1"></i> Editar
                                        </button>
                                        @unless ($plan->es_default && $plan->activo)
                                            <button wire:click="alternarActivo({{ $plan->id }})" class="btn btn-sm btn-outline-secondary">
                                                {{ $plan->activo ? 'Desactivar' : 'Activar' }}
                                            </button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Ventanas ============================ --}}

    {{-- Renovar --}}
    @if ($modal === 'renovar' && $seleccionado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="renovar">
                        <div class="modal-header">
                            <h5 class="modal-title">Renovar servicio</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">
                                Para <strong>{{ trim($seleccionado->name . ' ' . $seleccionado->lastname) }}</strong>
                                <small class="text-muted">({{ $seleccionado->reg_medico }})</small>.
                                El nuevo período empieza <strong>{{ $inicioRenovacion }}</strong>.
                            </p>

                            <div class="mb-3">
                                <label class="form-label">Plan <span class="text-danger">*</span></label>
                                <select class="form-select" wire:model="planElegido">
                                    @foreach ($planesActivos as $plan)
                                        <option value="{{ $plan->id }}">
                                            {{ $plan->nombre }} · {{ $plan->frecuencia === 'anual' ? 'anual' : 'mensual' }} · USD {{ number_format((float) $plan->precio_usd, 2, ',', '.') }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('planElegido') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>

                            <div class="row">
                                <div class="col-6 mb-3">
                                    <label class="form-label">Meses de servicio</label>
                                    <input type="number" min="1" max="60" class="form-control" wire:model.defer="meses">
                                    <div class="form-text">Se pueden dar más de los que cobra el plan (por ejemplo, paga 10 y disfruta 12).</div>
                                    @error('meses') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Monto cobrado (USD)</label>
                                    <input type="number" min="0" step="0.01" class="form-control" wire:model.defer="monto">
                                    <div class="form-text">0 = cortesía, no cuenta como compra.</div>
                                    @error('monto') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="mb-1">
                                <label class="form-label">Nota</label>
                                <input type="text" class="form-control" wire:model.defer="nota" maxlength="255" autocomplete="off"
                                       placeholder="Por ejemplo: transferencia del 05/10, ref. 1234">
                                @error('nota') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Renovar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Historial --}}
    @if ($modal === 'historial' && $seleccionado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Historial de {{ trim($seleccionado->name . ' ' . $seleccionado->lastname) }}</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Plan</th>
                                        <th>Origen</th>
                                        <th>Desde</th>
                                        <th>Hasta</th>
                                        <th class="text-end">USD</th>
                                        <th>Nota</th>
                                        <th>Estado</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $origenes = ['registro' => 'Prueba al registrarse', 'powerbuilder' => 'Cortesía escritorio', 'compra' => 'Compra', 'manual' => 'Cortesía manual'];
                                    @endphp
                                    @forelse ($historial as $s)
                                        <tr wire:key="serv-{{ $s->id }}" class="{{ $s->estado === 'cancelado' ? 'text-muted' : '' }}">
                                            <td class="fw-semibold">{{ $s->plan_nombre }}</td>
                                            <td>{{ $origenes[$s->origen] ?? $s->origen }}</td>
                                            <td>{{ $s->inicia_el->format('d/m/Y') }}</td>
                                            <td>{{ $s->vence_el->format('d/m/Y') }}</td>
                                            <td class="text-end">{{ number_format((float) $s->monto_usd, 2, ',', '.') }}</td>
                                            <td><small>{{ $s->nota }}</small></td>
                                            <td>
                                                <span class="badge {{ $s->estado === 'activo' ? 'bg-success' : 'bg-secondary' }}">{{ $s->estado === 'activo' ? 'Activo' : 'Cancelado' }}</span>
                                            </td>
                                            <td class="text-end">
                                                @if ($s->estado === 'activo')
                                                    <button wire:click="cancelarServicio({{ $s->id }})" class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('¿Cancelar este período? Deja de contar para el vencimiento.')">
                                                        Cancelar
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8" class="text-center text-muted py-4">Este médico todavía no tiene servicios.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Plan (alta / edición) --}}
    @if ($modal === 'plan')
        @php
            $ahorroVista = is_numeric($precio_tachado_usd) && is_numeric($precio_usd) ? round((float) $precio_tachado_usd - (float) $precio_usd, 2) : 0;
        @endphp
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarPlan">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $planId ? 'Editar plan' : 'Nuevo plan' }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-7 mb-3">
                                    <label class="form-label">Nombre <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model.defer="nombre" maxlength="100" autocomplete="off">
                                    @error('nombre') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-5 mb-3">
                                    <label class="form-label">Código <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model.defer="codigo" maxlength="40" autocomplete="off" placeholder="anual-12">
                                    @error('codigo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Descripción</label>
                                <input type="text" class="form-control" wire:model.defer="descripcion" maxlength="255" autocomplete="off">
                                <div class="form-text">Solo para mostrar, por ejemplo "Paga 10 meses y disfruta 12".</div>
                                @error('descripcion') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>

                            <div class="row">
                                <div class="col-4 mb-3">
                                    <label class="form-label">Frecuencia</label>
                                    <select class="form-select" wire:model.defer="frecuencia">
                                        <option value="mensual">Mensual (1 mes)</option>
                                        <option value="anual">Anual (12 meses)</option>
                                    </select>
                                    @error('frecuencia') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-4 mb-3">
                                    <label class="form-label">Monto (USD) <span class="text-danger">*</span></label>
                                    <input type="number" min="0" step="0.01" class="form-control" wire:model="precio_usd">
                                    @error('precio_usd') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-4 mb-3">
                                    <label class="form-label">Tachado (USD)</label>
                                    <input type="number" min="0" step="0.01" class="form-control" wire:model="precio_tachado_usd">
                                    @error('precio_tachado_usd') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                            @if ($ahorroVista > 0)
                                <p class="text-success small mb-3">
                                    Se mostrará <del>{{ number_format((float) $precio_tachado_usd, 2, ',', '.') }}</del>
                                    <strong>{{ number_format((float) $precio_usd, 2, ',', '.') }}</strong>
                                    · ahorro de USD {{ number_format($ahorroVista, 2, ',', '.') }}
                                </p>
                            @endif

                            <div class="row">
                                <div class="col-4 mb-3">
                                    <label class="form-label">Orden</label>
                                    <input type="number" min="0" class="form-control" wire:model.defer="orden">
                                    @error('orden') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-8 pt-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="plan-visible" wire:model.defer="visible">
                                        <label class="form-check-label" for="plan-visible">Visible en el catálogo que se le ofrece al médico</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="plan-activo" wire:model.defer="activo">
                                        <label class="form-check-label" for="plan-activo">Activo (se puede asignar)</label>
                                    </div>
                                    @error('activo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="plan-default" wire:model.defer="es_default">
                                        <label class="form-check-label" for="plan-default">Predeterminado (lo recibe quien se registra, gratis el primer mes)</label>
                                    </div>
                                    @error('es_default') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
