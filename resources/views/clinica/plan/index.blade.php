@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" />

    <x-ui.errores />
    <x-ui.avisos />

    <div class="card mb-4">
        <div class="card-header bg-white py-3">
            <strong>Tu servicio</strong>
        </div>
        <div class="card-body">
            <p class="mb-2 text-muted">{{ $vm->uso() }}</p>

            @if ($vm->tieneServicio())
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="fs-5 fw-semibold">{{ $vm->planActual() }}</span>
                    <span class="badge bg-{{ $vm->estadoTono() }}">{{ $vm->estadoLegible() }}</span>
                    @if ($vm->estado['permite_imprimir'] ?? false)
                        <span class="badge bg-success">Impresión incluida</span>
                    @else
                        <span class="badge bg-secondary">Sin impresión</span>
                    @endif
                </div>
                @if ($vm->venceEl())
                    <p class="mb-1">
                        Vence el <strong>{{ \Carbon\Carbon::parse($vm->venceEl())->format(\App\Support\Regionalizacion::formatoActual()) }}</strong>
                        @if ($vm->renovacionCubierta() && ($vm->diasRestantes() ?? -1) >= 0)
                            <span class="badge bg-success">{{ $vm->renovacionTexto() }}</span>
                        @elseif ($vm->diasRestantes() !== null && ! $vm->renovacionCubierta())
                            @if ($vm->diasRestantes() >= 0)
                                <span class="text-muted">({{ $vm->diasRestantes() }} {{ $vm->diasRestantes() === 1 ? 'día' : 'días' }})</span>
                            @else
                                <span class="text-danger">(venció hace {{ abs($vm->diasRestantes()) }} {{ abs($vm->diasRestantes()) === 1 ? 'día' : 'días' }})</span>
                            @endif
                        @endif
                    </p>
                @endif
                @if ($vm->estado['estado'] === 'gracia')
                    <p class="text-warning-emphasis small mb-0">
                        Estás en los días de gracia: todavía sincroniza e imprime, pero el servicio ya figura vencido.
                    </p>
                @elseif ($vm->estado['estado'] === 'vencido')
                    <p class="text-danger small mb-0">
                        El servicio venció: la sincronización y la impresión están suspendidas hasta que renueves.
                    </p>
                @endif
            @else
                <p class="mb-0 text-muted">Todavía no tienes un plan asignado a este consultorio.</p>
            @endif
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <strong>Renovación automática con Latidos</strong>
                <p class="small text-muted mb-0">
                    @if ($vm->renovacionAutomatica() && $vm->renovacionCubierta())
                        Tus Latidos cubren el próximo plan: se descuentan el día del vencimiento y no se te
                        manda el recordatorio de “por vencer”.
                    @elseif ($vm->renovacionAutomatica())
                        La preferencia está encendida. Cuando tengas Latidos suficientes, el plan se pagará
                        solo el día del vencimiento.
                    @else
                        El plan no se renueva solo: al vencer, puedes usar tus Latidos desde «Mi plan».
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('clinica.plan.renovacion') }}" class="d-flex align-items-center gap-2">
                @csrf
                <input type="hidden" name="renovacion_automatica" value="0">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="renovacion-automatica"
                           name="renovacion_automatica" value="1"
                           {{ $vm->renovacionAutomatica() ? 'checked' : '' }}>
                    <label class="form-check-label small" for="renovacion-automatica">
                        {{ $vm->renovacionAutomatica() ? 'Encendida' : 'Apagada' }}
                    </label>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
            </form>
        </div>
    </div>

    @if ($vm->latidosDisponibles() > 0)
        <div class="alert alert-success d-flex align-items-start gap-2" role="status">
            <i class="bi bi-stars mt-1" aria-hidden="true"></i>
            <div>
                Tienes <strong>{{ $vm->latidosDisponiblesTexto() }}</strong>.
                Se aplican como descuento al pagar un plan: 100 Latidos = USD 1 de descuento.
            </div>
        </div>
    @endif

    @if ($vm->aviso())
        <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $vm->aviso() }}
        </div>
    @endif

    <div class="card">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Renovar o cambiar de plan</strong>
                <span class="text-muted small ms-2">Si todavía no venció, el período nuevo empieza donde termina el actual.</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">Ver precios:</span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Frecuencia de pago">
                    <a href="{{ $vm->urlFrecuencia('anual') }}"
                       class="btn {{ $vm->esAnual() ? 'btn-primary' : 'btn-outline-primary' }}">Anual</a>
                    <a href="{{ $vm->urlFrecuencia('mensual') }}"
                       class="btn {{ $vm->esAnual() ? 'btn-outline-primary' : 'btn-primary' }}">Mensual</a>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Plan</th>
                            <th>Incluye</th>
                            <th class="text-end">Precio para tu equipo</th>
                            <th class="text-end">Contratar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vm->planes as $plan)
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        {{ $plan->nombre }}
                                        @if ($vm->esElPlanActual($plan))
                                            <span class="badge bg-primary ms-1">Tu plan actual</span>
                                        @endif
                                    </div>
                                    @if ($plan->descripcion)
                                        <small class="text-muted">{{ $plan->descripcion }}</small>
                                    @endif
                                    @if ($vm->periodoDeLaFila($plan) === 'mes'
                                        && ! $vm->esPorAsiento($plan)
                                        && $plan->precio_tachado_usd
                                        && (float) $plan->precio_tachado_usd > (float) $plan->precio_usd)
                                        <div class="small">
                                            <del class="text-muted">{{ $vm->precioTachadoUsdTexto($plan) }}</del>
                                            <span class="text-success ms-1">ahorras {{ $vm->ahorroTexto($plan) }}</span>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <ul class="list-unstyled small mb-0">
                                        @foreach ($vm->incluye($plan) as $item)
                                            <li><i class="bi bi-check2 text-success" aria-hidden="true"></i> {{ $item }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="text-end">
                                    @if (! $plan->ofreceMensual() && ! $plan->ofreceAnual())
                                        <span class="fw-semibold">Gratis</span>
                                    @else
                                        <div class="fw-semibold">
                                            {{ $vm->precioDeLaFila($plan) }}
                                            <small class="text-muted fw-normal">/{{ $vm->periodoDeLaFila($plan) }}</small>
                                        </div>
                                        @if ($vm->ahorroDeLaFila($plan))
                                            <small class="text-success d-block">
                                                Ahorras {{ $vm->ahorroDeLaFila($plan) }}
                                                @if ($vm->mesesDeAhorroDeLaFila($plan) > 0)
                                                    ({{ $vm->mesesDeAhorroDeLaFila($plan) }} {{ $vm->mesesDeAhorroDeLaFila($plan) === 1 ? 'mes' : 'meses' }})
                                                @endif
                                            </small>
                                        @endif
                                        @if ($vm->detalleDeLaFila($plan))
                                            <small class="text-muted d-block">{{ $vm->detalleDeLaFila($plan) }}</small>
                                        @endif
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($vm->puedeContratar($plan))
                                        <a href="{{ $vm->urlComoPagar($plan) }}" class="btn btn-primary btn-sm text-nowrap">
                                            <i class="bi bi-credit-card me-1" aria-hidden="true"></i>
                                            {{ $plan->ofreceMensual() && $plan->ofreceAnual() ? 'Cómo pagar' : ($vm->esElPlanActual($plan) ? 'Renovar' : 'Pagar') }}
                                        </a>
                                    @else
                                        <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" disabled
                                                title="{{ $vm->motivoNoContratable($plan) }}">
                                            <i class="bi bi-lock me-1" aria-hidden="true"></i> No cabe tu equipo
                                        </button>
                                        <small class="text-muted d-block mt-1">{{ $vm->motivoNoContratable($plan) }}</small>
                                        <a class="small" href="{{ route('clinica.equipo') }}">Ver el equipo</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No hay planes disponibles.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
