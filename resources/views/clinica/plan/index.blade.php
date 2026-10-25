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
            @if ($vm->tieneServicio())
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="fs-5 fw-semibold">{{ $vm->planActual() }}</span>
                    <span class="badge bg-{{ $vm->estadoTono() }}">{{ $vm->estadoLegible() }}</span>
                </div>
                @if ($vm->venceEl())
                    <p class="mb-1">
                        Vence el <strong>{{ \Carbon\Carbon::parse($vm->venceEl())->format('d/m/Y') }}</strong>
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
                        Estás en los días de gracia: todavía sincroniza, pero el servicio ya figura vencido.
                    </p>
                @elseif ($vm->estado['estado'] === 'vencido')
                    <p class="text-danger small mb-0">
                        El servicio venció: la sincronización está suspendida hasta que renueves.
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
                            <th>Frecuencia</th>
                            <th class="text-end">Precio</th>
                            <th class="text-end">Pagar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vm->planes as $plan)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $plan->nombre }}</div>
                                    @if ($plan->descripcion)
                                        <small class="text-muted">{{ $plan->descripcion }}</small>
                                    @endif
                                    @if ($plan->precio_tachado_usd && (float) $plan->precio_tachado_usd > (float) $plan->precio_usd)
                                        <div class="small">
                                            <del class="text-muted">{{ $vm->precioTachadoUsdTexto($plan) }}</del>
                                            <span class="text-success ms-1">ahorras {{ $vm->ahorroTexto($plan) }}</span>
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $plan->frecuencia === \App\Models\Plan::ANUAL ? 'Anual (12 meses)' : 'Mensual' }}</td>
                                <td class="text-end fw-semibold">{{ $vm->precioUsdTexto($plan) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('clinica.plan.elegir', $plan) }}" class="btn btn-primary btn-sm text-nowrap">
                                        <i class="bi bi-credit-card me-1" aria-hidden="true"></i> Pagar
                                    </a>
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
