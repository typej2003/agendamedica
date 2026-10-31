@extends('clinica.layout')

@section('titulo', 'Invita y gana')

@section('contenido')
    <x-ui.page-head titulo="Invita y gana"
                    subtitulo="Comparte tu link con un colega. Cuando su registro sea aprobado, los dos ganan Latidos." />

    <x-ui.errores />
    <x-ui.avisos />

    @if (! $config->referidosActivos())
        <div class="alert alert-warning" role="alert">
            El programa de referidos está pausado. Los links existentes no acreditan premios hasta que se
            vuelva a activar.
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Latidos ganados</div>
                    <div class="fs-3 fw-semibold">{{ \App\Latidos\Conversion::formatear($totalGanado) }}</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Colegas acreditados</div>
                    <div class="fs-3 fw-semibold">{{ $acreditados }}</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Premio por referido</div>
                    <div class="fs-5 fw-semibold">
                        {{ \App\Latidos\Conversion::formatear((int) $config->latidos_referente) }} para ti
                    </div>
                    <div class="small text-muted">
                        {{ \App\Latidos\Conversion::formatear((int) $config->latidos_referido) }} para tu colega
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white py-3"><strong>Tu link</strong></div>
        <div class="card-body">
            <p class="small text-muted">
                Compártelo por WhatsApp o correo. El colega lo abre, descarga la app o crea su cuenta, y el
                registro queda asociado a ti.
            </p>
            <div class="input-group mb-2">
                <input type="text" class="form-control" value="{{ $url }}" readonly
                       aria-label="Tu link de referido">
                <span class="input-group-text"><code>{{ $codigo->codigo }}</code></span>
            </div>
            <p class="small text-muted mb-0">
                El código también se puede escribir a mano en el registro. El premio se acredita
                <strong>una sola vez</strong>, cuando un administrador aprueba al colega.
            </p>

            <form method="POST" action="{{ route('clinica.referidos.regenerar') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Regenerar link
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white py-3"><strong>Colegas que invitaste</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Colega</th>
                            <th>Consultorio</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th class="text-end">Latidos para ti</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($referidos as $referido)
                            @php $colega = $referido->referido; @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        {{ $colega ? trim($colega->name . ' ' . $colega->lastname) : '—' }}
                                    </div>
                                </td>
                                <td><small class="text-muted">{{ $referido->referido_reg_medico }}</small></td>
                                <td class="text-nowrap small">{{ $referido->created_at?->format('d/m/Y') }}</td>
                                <td>
                                    @php
                                        $tono = match ($referido->estado) {
                                            \App\Models\Referido::ACREDITADO => 'success',
                                            \App\Models\Referido::RECHAZADO => 'warning text-dark',
                                            default => 'secondary',
                                        };
                                        $texto = match ($referido->estado) {
                                            \App\Models\Referido::ACREDITADO => 'Acreditado',
                                            \App\Models\Referido::RECHAZADO => 'No acreditado',
                                            default => 'Anulado',
                                        };
                                    @endphp
                                    <span class="badge bg-{{ $tono }}">{{ $texto }}</span>
                                    @if ($referido->motivo)
                                        <small class="text-muted d-block">{{ $referido->motivo }}</small>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    {{ \App\Latidos\Conversion::formatear((int) $referido->latidos_referente) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    Todavía no invitaste a ningún colega.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
