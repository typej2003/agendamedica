@if ($jornadas->isEmpty())
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            No hay citas para este día{{ $sedeId ? ' en la sede elegida' : '' }}.
        </div>
    </div>
@else
    @foreach ($jornadas as $jornada)
        @include('clinica.agenda._jornada', ['jornada' => $jornada, 'conDrag' => true])
    @endforeach
@endif
