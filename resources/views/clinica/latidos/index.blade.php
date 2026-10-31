@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" />

    <x-ui.errores />

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Disponibles</div>
                    <div class="fs-3 fw-semibold">{{ $vm->disponiblesTexto() }}</div>
                    <div class="small text-muted">Se usan como descuento en tu plan.</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Para usar ahora</div>
                    <div class="fs-3 fw-semibold">{{ $vm->paraUsarTexto() }}</div>
                    <div class="small text-muted">Descontando lo reservado por pagos pendientes.</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Retenidos</div>
                    <div class="fs-3 fw-semibold text-warning">{{ $vm->retenidoTexto() }}</div>
                    <div class="small text-muted">En pagos que todavía no se aprobaron.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex align-items-start gap-2" role="status">
        <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
        <div>
            Los Latidos <strong>no son dinero</strong> y no se retiran: se canjean como descuento en los planes
            de Doctorísimo. <a href="{{ route('clinica.plan') }}" class="alert-link">Ver mi plan</a>.
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white py-3"><strong>Últimos movimientos</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Concepto</th>
                            <th class="text-end">Latidos</th>
                            <th class="text-end">Después</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vm->movimientos() as $movimiento)
                            <tr>
                                <td class="text-nowrap small">{{ $movimiento->created_at?->format('d/m/Y H:i') }}</td>
                                <td><span class="badge bg-light text-dark">{{ $vm->tipoLegible($movimiento->tipo) }}</span></td>
                                <td>
                                    <div>{{ $movimiento->concepto }}</div>
                                    @if ($movimiento->nota)
                                        <small class="text-muted">{{ $movimiento->nota }}</small>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap fw-semibold {{ (int) $movimiento->latidos < 0 ? 'text-danger' : 'text-success' }}">
                                    {{ \App\Latidos\Conversion::formatear((int) $movimiento->latidos) }}
                                </td>
                                <td class="text-end text-nowrap">
                                    {{ \App\Latidos\Conversion::formatear((int) $movimiento->disponibles_despues) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    Todavía no tienes movimientos de Latidos.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
