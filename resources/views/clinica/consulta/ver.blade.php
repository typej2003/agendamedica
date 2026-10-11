@extends('clinica.layout')

@section('titulo', 'Consulta')

@section('contenido')
    {{-- El encabezado con lo que identifica la atención: el paciente, su historia, la consulta y el día
         (wiki 04-historia-clinica.md). El título es el paciente y no el módulo porque lo que el médico
         necesita al entrar es confirmar **a quién** está atendiendo. --}}
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
        <x-slot name="acciones">
            {{-- La **acción primaria** de la pantalla (guía de UI §1: un solo botón sólido): terminar la
                 atención. Es lo que el escritorio hace al cerrar `w_consulta_principal` —la cita ya había
                 quedado `atendido` en su `open`—, así que no hay ningún estado nuevo que inventar: la
                 consulta se cierra, la cita de hoy queda atendida y se vuelve a la agenda del día. Pide
                 confirmación con el mecanismo de `shell.js` (`data-confirmar`), sin diálogo propio ni JS
                 nuevo: la acción es irreversible y la pantalla lo dice con el texto del ViewModel. --}}
            @if ($vm->urlTerminar())
                <form method="POST" action="{{ $vm->urlTerminar() }}" data-confirmar="{{ $vm->avisoTerminar() }}">
                    @csrf
                    <button class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Terminar la consulta
                    </button>
                </form>
            @endif
            @if ($vm->urlHistoria())
                <a class="btn btn-outline-secondary" href="{{ $vm->urlHistoria() }}">
                    <i class="bi bi-journal-medical" aria-hidden="true"></i> Ver la historia del paciente
                </a>
            @endif
            @if ($vm->urlPaciente())
                <a class="btn btn-outline-secondary" href="{{ $vm->urlPaciente() }}">
                    <i class="bi bi-person-vcard" aria-hidden="true"></i> Ver la ficha
                </a>
            @endif
        </x-slot>
    </x-ui.page-head>

    {{-- Los errores de validación de la sección (el nombre del motivo nuevo) van arriba y no dentro del
         diálogo: el diálogo se vuelve a abrir solo, pero el aviso tiene que verse aunque no se abra. --}}
    <x-ui.errores />

    <div class="row g-3">
        {{-- El panel de navegación del legado (`w_consulta_principal`): los 13 ítems con su estado. Los que
             todavía no tienen pantalla dicen "Próximamente" y con qué tarea llegan, en vez de ofrecer un
             enlace muerto; los de WEB-4.2 (motivos y enfermedad actual) abren su sección dentro de esta
             misma pantalla con `?seccion=`.

             Ocupa más que un tercio a propósito (5 de 12 en pantalla grande): son trece renglones con
             nombre, detalle y estado, y en una columna más angosta el estado se come el texto. --}}
        <div class="col-12 col-lg-5 col-xl-4">
            <x-ui.tarjeta id="consulta-panel" titulo="Secciones de la consulta" icono="bi-list-ul">
                <p class="texto-tenue small mb-2">{{ $vm->totalListos() }} de {{ $vm->totalItems() }} disponibles</p>
                @foreach ($vm->panel() as $bloque)
                    <div class="consulta-panel-grupo">
                        <h3 class="consulta-panel-titulo">{{ $bloque['titulo'] }}</h3>
                        <ul class="consulta-panel">
                            @foreach ($bloque['items'] as $item)
                                <li class="consulta-panel-item {{ $item['listo'] ? '' : 'consulta-panel-item-pendiente' }} {{ $item['activo'] ? 'consulta-panel-item-activo' : '' }}">
                                    <span class="consulta-panel-icono"><i class="bi {{ $item['icono'] }}" aria-hidden="true"></i></span>
                                    <div class="consulta-panel-texto">
                                        @if ($item['url'])
                                            <a class="consulta-panel-nombre" href="{{ $item['url'] }}"
                                               @if ($item['activo']) aria-current="true" @endif>{{ $item['titulo'] }}</a>
                                        @else
                                            <span class="consulta-panel-nombre">{{ $item['titulo'] }}</span>
                                        @endif
                                        <span class="consulta-panel-detalle">{{ $item['detalle'] }}</span>
                                    </div>
                                    {{-- Sólo los que todavía no están llevan estado ("Próximamente", color + icono + texto,
                                         como pide la guía de UI); los disponibles se reconocen por su enlace. --}}
                                    @if ($item['estado'])
                                        <x-ui.estado :tono="$item['estado']['tono']" :icono="$item['estado']['icono']"
                                                     :title="$item['titulo_estado']">{{ $item['estado']['texto'] }}</x-ui.estado>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </x-ui.tarjeta>
        </div>

        {{-- El área de trabajo: la sección que el panel abrió, o el estado vacío del shell si no se eligió
             ninguna. Las secciones llegan por los parciales —`_motivos` y `_enfermedad` (WEB-4.2),
             `_evaluacion` (WEB-4.3), `_antecedentes` (WEB-4.4), `_diagnostico` (WEB-4.5), `_evolucion`
             (WEB-4.6) y `_examenes` (WEB-4.7)— y las siguientes tareas agregan las suyas: el nombre del
             parcial lo resuelve el ViewModel a partir del mapa del panel. --}}
        <div class="col-12 col-lg-7 col-xl-8">
            @if ($contenido && $vm->parcial())
                @include($vm->parcial(), ['seccion' => $contenido, 'catalogo' => $catalogo ?? null, 'imagenes' => $imagenes ?? null])
            @else
                <x-ui.tarjeta id="consulta-contenido" titulo="Contenido de la consulta" icono="bi-journal-medical">
                    @php $vacio = $vm->sinContenido(); @endphp
                    <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
                </x-ui.tarjeta>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/consulta.js') }}"></script>
@endsection
