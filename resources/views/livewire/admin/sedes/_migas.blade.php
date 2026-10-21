{{-- Migas de «Sedes del médico»: Médicos › Dr. X › Sedes › Sede › Consultorios › Consultorio › Horarios.
     `$migas` es una lista de [texto, url|null]; la última es la pantalla actual (sin enlace). --}}
<nav aria-label="Ubicación" class="mb-2">
    <ol class="breadcrumb mb-0 small">
        @foreach ($migas as [$texto, $url])
            @if ($loop->last || $url === null)
                <li class="breadcrumb-item {{ $loop->last ? 'active' : '' }}" @if ($loop->last) aria-current="page" @endif>{{ $texto }}</li>
            @else
                <li class="breadcrumb-item"><a href="{{ $url }}">{{ $texto }}</a></li>
            @endif
        @endforeach
    </ol>
</nav>
