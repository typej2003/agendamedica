@if ($jornadas->isEmpty())
    <div class="card">
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <p>No hay citas para este día{{ $sedeId ? ' en la sede elegida' : '' }}.</p>
            <p class="small">Probá con otra fecha o cambiá la sede del filtro.</p>
        </div>
    </div>
@else
    @foreach ($jornadas as $jornada)
        @include('clinica.agenda._jornada', ['jornada' => $jornada, 'conDrag' => true])
    @endforeach
@endif
