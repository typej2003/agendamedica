@php
    $urlDia = fn (\Carbon\Carbon $dia) => route('clinica.agenda', array_merge(request()->query(), [
        'vista' => 'dia',
        'fecha' => $dia->toDateString(),
    ]));
@endphp

<div class="card">
    <div class="card-body">
        <table class="table table-bordered mb-0 calendario-mes">
            <thead class="table-light">
            <tr>
                @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $etiqueta)
                    <th class="text-center small">{{ $etiqueta }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($semanasDelMes as $semana)
                <tr>
                    @foreach ($semana as $dia)
                        @php $marcado = $noLaborables->get($dia['fecha']->toDateString()); @endphp
                        <td class="{{ $dia['enMes'] ? '' : 'bg-light text-muted' }} align-top" style="height: 5rem;">
                            <a class="d-flex justify-content-between align-items-start text-decoration-none {{ $dia['fecha']->isToday() ? 'fw-bold text-primary' : 'text-body' }}"
                               href="{{ $urlDia($dia['fecha']) }}">
                                <span>{{ $dia['fecha']->day }}</span>
                                @if ($dia['conteo'] > 0)
                                    <span class="badge bg-primary">{{ $dia['conteo'] }}</span>
                                @endif
                            </a>
                            {{-- Día no laborable (WEB-2.8): se marca aunque no tenga citas: es el dato que
                                 el escritorio no muestra en su calendario y la secretaria necesita. --}}
                            @if ($marcado)
                                <span class="badge bg-warning text-dark mt-1 d-block text-truncate"
                                      title="{{ $marcado->motivo ?: $marcado->etiquetaTipo() }}">
                                    <i class="bi bi-calendar-x"></i> {{ $marcado->etiquetaTipo() }}
                                </span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
