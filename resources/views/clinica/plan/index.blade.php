@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" />

    <x-ui.errores />

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
                        @if ($vm->diasRestantes() !== null)
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

    @if ($vm->aviso())
        <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $vm->aviso() }}
        </div>
    @endif

    <div class="card">
        <div class="card-header bg-white py-3">
            <strong>Renovar o cambiar de plan</strong>
            <span class="text-muted small ms-2">Si todavía no venció, el período nuevo empieza donde termina el actual.</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Plan</th>
                            <th>Incluye</th>
                            <th>Frecuencia</th>
                            <th class="text-end">Precio para tu equipo</th>
                            <th class="text-end">Pagar</th>
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
                                    @if (! $vm->esPorAsiento($plan) && $plan->precio_tachado_usd && (float) $plan->precio_tachado_usd > (float) $plan->precio_usd)
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
                                <td>{{ $plan->frecuencia === \App\Models\Plan::ANUAL ? 'Anual (12 meses)' : 'Mensual' }}</td>
                                <td class="text-end">
                                    <div class="fw-semibold">{{ $vm->precioUsdTexto($plan) }}</div>
                                    @if ($vm->detallePrecio($plan))
                                        <small class="text-muted d-block">{{ $vm->detallePrecio($plan) }}</small>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($vm->puedeContratar($plan))
                                        <a href="{{ route('clinica.plan.elegir', $plan) }}" class="btn btn-primary btn-sm text-nowrap">
                                            <i class="bi bi-credit-card me-1" aria-hidden="true"></i>
                                            {{ $vm->esElPlanActual($plan) ? 'Renovar' : 'Pagar' }}
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
                                <td colspan="5" class="text-center text-muted py-4">No hay planes disponibles.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
