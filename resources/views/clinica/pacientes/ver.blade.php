@extends('clinica.layout')

@section('titulo', 'Ficha del paciente')

@section('contenido')
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()"
                    :volver="route('clinica.pacientes')" volver-texto="Pacientes" />

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
