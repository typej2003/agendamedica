@extends('clinica.layout')

@section('titulo', 'Sedes y consultorios')

@section('contenido')
    {{-- **Sedes y consultorios** (2026-10-20): una tarjeta por sede con sus consultorios adentro, y las altas y
         ediciones en paneles laterales. El panel que se abre al cargar (un error, `?panel=`, editar o la pregunta de la
         sede recién creada) lo dice `data-panel-al-cargar` y lo abre `sedes.js`. --}}
    <div class="sedes-pantalla" data-panel-al-cargar="{{ $vm->panelAlCargar() ? 'dialogo-' . $vm->panelAlCargar() : '' }}"
         data-url-limpia="{{ $vm->url() }}">
        <x-ui.page-head titulo="Sedes y consultorios" :volver="$vm->urlVolver()" volver-texto="Volver">
            @unless ($vm->estaVacio())
                <x-slot name="acciones">
                    <a class="btn btn-primary" href="{{ $vm->urlNuevaSede() }}" data-panel="dialogo-sede" data-tour="sedes-nueva">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva sede
                    </a>
                </x-slot>
            @endunless
        </x-ui.page-head>

        <x-ui.barra-herramientas>
            <x-slot name="filtros">
                <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Qué sedes ver" />
            </x-slot>
        </x-ui.barra-herramientas>

        <x-ui.errores />

        @if ($vm->estaVacio())
            {{-- Sin sedes: la tarjeta guía es el único camino, y su botón es la acción principal de la pantalla. --}}
            <x-ui.tarjeta class="sedes-vacio">
                <x-ui.vacio icono="bi-geo-alt" :titulo="$vm->tituloVacio()">
                    <x-slot name="acciones">
                        <a class="btn btn-primary" href="{{ $vm->urlNuevaSede() }}" data-panel="dialogo-sede" data-tour="sedes-nueva">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva sede
                        </a>
                    </x-slot>
                </x-ui.vacio>
            </x-ui.tarjeta>
        @else
            <div class="sedes-grilla" data-tour="consultorios-lista">
                @foreach ($vm->tarjetas() as $sede)
                    @include('clinica.sedes._tarjeta', ['sede' => $sede])
                @endforeach
            </div>

            @if ($vm->otrasSedes() !== [])
                {{-- El catálogo de sedes es de toda la clínica: las que no tienen un consultorio de este médico van
                     plegadas, para empezar a atender en una sin tener que crearla de nuevo. --}}
                <details class="sedes-otras">
                    <summary><i class="bi bi-chevron-right" aria-hidden="true"></i> {{ $vm->tituloOtrasSedes() }}</summary>
                    <div class="sedes-grilla">
                        @foreach ($vm->otrasSedes() as $sede)
                            @include('clinica.sedes._tarjeta', ['sede' => $sede])
                        @endforeach
                    </div>
                </details>
            @endif
        @endif
    </div>

    @include('clinica.sedes._dialogo-sede')

    @if ($vm->puedeCargarConsultorios())
        @include('clinica.sedes._dialogo-consultorio')
        @include('clinica.sedes._dialogo-sede-rapida')
        @include('clinica.sedes._dialogo-horario')

        @if ($vm->preguntaSedeCreada())
            @include('clinica.sedes._dialogo-sede-creada', ['creada' => $vm->preguntaSedeCreada()])
        @endif
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/sedes.js') }}"></script>
@endsection
