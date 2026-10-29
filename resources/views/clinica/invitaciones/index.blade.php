@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" />

    <x-ui.errores />

    @if ($vm->motivoSinMedico())
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i> {{ $vm->motivoSinMedico() }}
        </div>
    @endif

    @if (! $vm->hay())
        <x-ui.vacio icono="bi-envelope-open" titulo="No tienes invitaciones pendientes">
            Cuando un médico te invite a su equipo, la vas a ver acá y también te va a llegar un correo.
        </x-ui.vacio>
    @else
        <div class="row g-3">
            @foreach ($vm->filas() as $fila)
                @php $invitacion = $fila['invitacion']; @endphp
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <h2 class="h5 mb-1">{{ $fila['equipo'] }}</h2>
                                    <p class="text-muted small mb-2">
                                        @if ($fila['invitador'])
                                            Te invitó <strong>{{ $fila['invitador'] }}</strong>.
                                        @else
                                            Te invitaron a unirte al equipo.
                                        @endif
                                    </p>
                                </div>
                                <x-ui.estado tono="{{ $invitacion->estaVigente() ? 'info' : 'neutro' }}" icono="bi-envelope-paper">
                                    {{ ucfirst($invitacion->estadoLegible()) }}
                                </x-ui.estado>
                            </div>

                            <p class="small texto-tenue">
                                Invitación del {{ $invitacion->created_at?->format(\App\Support\Regionalizacion::formatoActual()) }}
                                · vence el {{ $invitacion->expira_el?->format(\App\Support\Regionalizacion::formatoActual()) }}.
                            </p>

                            @if ($fila['puedeAceptar'])
                                <div class="d-flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('clinica.invitaciones.aceptar', $invitacion->id) }}">
                                        @csrf
                                        <button class="btn btn-primary">
                                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Aceptar
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('clinica.invitaciones.rechazar', $invitacion->id) }}"
                                          data-confirmar="¿Rechazar la invitación de {{ $fila['equipo'] }}?">
                                        @csrf
                                        <button class="btn btn-outline-secondary">Rechazar</button>
                                    </form>
                                </div>
                            @elseif (! $invitacion->estaVigente())
                                <p class="small text-muted mb-0">
                                    Esta invitación ya no está vigente. Pídele al médico que te envíe otra.
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
