@foreach ($semana as $dia)
    @php
        $delDia = $jornadas->where('fecha', $dia->toDateString())->values();
        $citasDelDia = $delDia->sum(fn ($jornada) => $jornada->cantidad());
    @endphp

    <div class="d-flex align-items-center gap-2 mt-3 mb-2">
        <h2 class="h6 mb-0 {{ $dia->isToday() ? 'text-primary' : '' }}">
            {{ ucfirst(\App\Support\FechaClinica::tituloDia($dia)) }}
        </h2>
        <span class="badge bg-light text-dark">{{ $citasDelDia }} {{ $citasDelDia === 1 ? 'cita' : 'citas' }}</span>
    </div>

    @forelse ($delDia as $jornada)
        @include('clinica.agenda._jornada', ['jornada' => $jornada, 'conDrag' => false])
    @empty
        <p class="text-muted small mb-0">Sin citas.</p>
    @endforelse
@endforeach
