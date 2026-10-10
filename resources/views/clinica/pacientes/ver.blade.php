@extends('clinica.layout')

@section('titulo', 'Ficha del paciente')

@section('contenido')
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()"
                    :volver="route('clinica.pacientes')" volver-texto="Pacientes">
        <x-slot name="acciones">
            {{-- Cuando falta la historia, **esa** es la acción primaria de la pantalla: es lo que hay que
                 hacer para poder atender al paciente (wiki `04-historia-clinica.md`: "No se atiende sin
                 historia"). Editar la ficha queda al lado, en secundario. Con la historia puesta, la
                 única acción vuelve a ser editar. Una sola acción sólida por pantalla (guía de UI §1). --}}
            <a class="btn {{ $vm->puedeAsignarHistoria() ? 'btn-outline-primary' : 'btn-primary' }}"
               href="{{ route('clinica.pacientes.editar', $vm->id()) }}">
                <i class="bi bi-pencil" aria-hidden="true"></i> Editar la ficha
            </a>
        </x-slot>
    </x-ui.page-head>

    {{-- Cabecera de la ficha: quién es y cómo está de un vistazo. --}}
    <x-ui.tarjeta class="mb-3">
        <div class="ficha-cabecera">
            <x-ui.avatar :nombre="$vm->nombre()" tamano="lg" />
            <div class="ficha-cabecera-texto">
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($vm->estados() as $estado)
                        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']">{{ $estado['texto'] }}</x-ui.estado>
                    @endforeach
                </div>
                <div class="ficha-cabecera-contacto">
                    @if ($vm->telefono())
                        <span><i class="bi bi-telephone" aria-hidden="true"></i> {{ $vm->telefono() }}</span>
                    @endif
                    @if ($vm->correo())
                        <span class="text-break"><i class="bi bi-envelope" aria-hidden="true"></i> {{ $vm->correo() }}</span>
                    @endif
                    @unless ($vm->telefono() || $vm->correo())
                        <span class="texto-tenue">Sin teléfono ni correo registrados</span>
                    @endunless
                </div>
            </div>
        </div>
    </x-ui.tarjeta>

    {{-- Llenar la historia (WEB-3.3): sólo aparece si el paciente **no** tiene número; cuando ya lo
         tiene, la ficha no ofrece nada de esto, sólo lo muestra en sus datos y en el estado de arriba.
         El bloque explica el efecto porque el número no se puede elegir ni adelantar: lo calcula el
         servidor con el correlativo del consultorio, y la acción no se puede deshacer. --}}
    @if ($vm->puedeAsignarHistoria())
        @php $sinHistoria = $vm->sinHistoria(); @endphp
        <x-ui.tarjeta id="ficha-sin-historia" :titulo="$sinHistoria['titulo']" icono="bi-journal-medical" class="mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <p class="mb-0 texto-tenue">{{ $sinHistoria['texto'] }}</p>
                <form method="POST" action="{{ $vm->urlAsignarHistoria() }}"
                      data-confirmar="{{ $vm->confirmarAsignarHistoria() }}">
                    @csrf
                    <button class="btn btn-primary text-nowrap">
                        <i class="bi bi-journal-medical" aria-hidden="true"></i> {{ $vm->botonAsignarHistoria() }}
                    </button>
                </form>
            </div>
        </x-ui.tarjeta>
    @endif

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <x-ui.tarjeta titulo="Datos del paciente" icono="bi-person-vcard" class="h-100">
                <dl class="datos-rejilla">
                    <x-ui.dato etiqueta="Cédula" :valor="$vm->cedula()" />
                    <x-ui.dato etiqueta="N.º de historia" :valor="$vm->numeroDeHistoria()" />
                    <x-ui.dato etiqueta="Nacimiento" :valor="$vm->nacimiento()" />
                    <x-ui.dato etiqueta="Edad" :valor="$vm->edad()" />
                    <x-ui.dato etiqueta="Sexo" :valor="$vm->sexo()" />
                    <x-ui.dato etiqueta="Teléfono" :valor="$vm->telefono()" />
                    <x-ui.dato etiqueta="Correo" :valor="$vm->correo()" />
                    <x-ui.dato etiqueta="Dirección" :valor="$vm->direccion()" />
                    <x-ui.dato etiqueta="Profesión" :valor="$vm->profesion()" />
                    <x-ui.dato etiqueta="Ocupación" :valor="$vm->ocupacion()" />
                    <x-ui.dato etiqueta="Seguro / empresa" :valor="$vm->seguro()" />
                </dl>
            </x-ui.tarjeta>
        </div>

        <div class="col-12 col-lg-6">
            <x-ui.tarjeta titulo="Consultas" icono="bi-journal-text" sin-relleno class="h-100">
                @if ($vm->totalConsultas() === 0)
                    @php $sin = $vm->sinConsultas(); @endphp
                    <x-ui.vacio :icono="$sin['icono']" :titulo="$sin['titulo']">{{ $sin['texto'] }}</x-ui.vacio>
                @else
                    <ol class="linea-tiempo">
                        @foreach ($vm->consultas() as $consulta)
                            <li>
                                <div class="linea-tiempo-cabeza">
                                    <strong>{{ $consulta['fecha'] }}</strong>
                                    <span class="texto-tenue small">{{ $consulta['numero'] }}</span>
                                </div>
                                @if ($consulta['resumen'])
                                    <p class="linea-tiempo-texto">{{ $consulta['resumen'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.tarjeta>
        </div>
    </div>
@endsection
