@extends('clinica.layout')

@section('titulo', 'Sedes y consultorios')

@section('contenido')
    {{-- **Sedes y consultorios** (2026-10-20): una tarjeta por sede con sus consultorios adentro, y las altas y
         ediciones en paneles laterales. El panel que se abre al cargar (un error, `?panel=`, editar o la pregunta de la
         sede recién creada) lo dice `data-panel-al-cargar` y lo abre `sedes.js`.

         Desde el 2026-10-26 las sedes son del **médico del contexto** (las de otros no se ven ni se ofrecen): sin
         contexto la pantalla queda en el aviso y no ofrece crear nada. --}}
    <div class="sedes-pantalla" data-panel-al-cargar="{{ $vm->panelAlCargar() ? 'dialogo-' . $vm->panelAlCargar() : '' }}"
         data-url-limpia="{{ $vm->url() }}">
        <x-ui.page-head titulo="Sedes y consultorios" :volver="$vm->urlVolver()" volver-texto="Volver">
            @if ($vm->puedeCrearSede() && ! $vm->estaVacio())
                <x-slot name="acciones">
                    <a class="btn btn-primary" href="{{ $vm->urlNuevaSede() }}" data-panel="dialogo-sede" data-tour="sedes-nueva">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva sede
                    </a>
                </x-slot>
            @endif
        </x-ui.page-head>

        @if ($vm->puedeCargarConsultorios())
            <x-ui.barra-herramientas>
                <x-slot name="filtros">
                    <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Qué sedes ver" />
                </x-slot>
            </x-ui.barra-herramientas>
        @endif

        <x-ui.errores />

        @if ($vm->avisoSinContexto())
            {{-- Sin dueño resuelto no hay sedes ni «+ Consultorio»: la pantalla no ofrece crear nada y el enlace es la
                 salida (el médico nuevo no tenía ni menú lateral). --}}
            <div class="alert alert-info py-2 d-flex align-items-center gap-2" role="status">
                <i class="bi bi-signpost-split-fill" aria-hidden="true"></i>
                <span>{{ $vm->avisoSinContexto() }}
                    <a class="alert-link" href="{{ $vm->urlElegirConsultorio() }}">Elegir consultorio aquí</a>.</span>
            </div>
        @endif

        @if ($vm->puedeCargarConsultorios())
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
            @endif
        @endif
    </div>

    @if ($vm->puedeCrearSede())
        @include('clinica.sedes._dialogo-sede')
    @endif

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
