{{-- La semana: cada día con su conteo, su aviso de día no laborable y sus jornadas. Recibe `$cuerpo`
     (SemanaVista). Sin arrastre: reordenar es de la vista de día. --}}
@foreach ($cuerpo->dias() as $dia)
    <section class="agenda-dia">
        <header class="agenda-dia-cabecera">
            <h2 class="agenda-dia-titulo {{ $dia['esHoy'] ? 'es-hoy' : '' }}">
                <a href="{{ $dia['url'] }}">{{ $dia['titulo'] }}</a>
            </h2>
            <x-ui.estado tono="neutro">{{ $dia['conteo'] }}</x-ui.estado>
            {{-- Día no laborable (WEB-2.8): se ve en la semana para no agendar sobre un feriado. --}}
            @if ($dia['noLaborable'])
                <x-ui.estado tono="aviso" icono="bi-calendar-x" :title="$dia['noLaborable']['titulo']">{{ $dia['noLaborable']['texto'] }}</x-ui.estado>
            @endif
        </header>

        @forelse ($dia['jornadas'] as $jornada)
            @include('clinica.agenda._jornada', ['jornada' => $jornada])
        @empty
            <p class="texto-tenue small mb-0">Sin citas.</p>
        @endforelse
    </section>
@endforeach
