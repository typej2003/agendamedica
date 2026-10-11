@extends('clinica.layout')

@section('titulo', 'Consulta')

@section('contenido')
    {{-- El encabezado con lo que identifica la atención: el paciente, su historia, la consulta y el día
         (wiki 04-historia-clinica.md). El título es el paciente y no el módulo porque lo que el médico
         necesita al entrar es confirmar **a quién** está atendiendo. --}}
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
        <x-slot name="acciones">
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

    <div class="row g-3">
        {{-- El panel de navegación del legado (`w_consulta_principal`): los 13 ítems con su estado. Los que
             todavía no tienen pantalla dicen "Próximamente" y con qué tarea llegan, en vez de ofrecer un
             enlace muerto.

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
                                <li class="consulta-panel-item {{ $item['listo'] ? '' : 'consulta-panel-item-pendiente' }}">
                                    <span class="consulta-panel-icono"><i class="bi {{ $item['icono'] }}" aria-hidden="true"></i></span>
                                    <div class="consulta-panel-texto">
                                        @if ($item['url'])
                                            <a class="consulta-panel-nombre" href="{{ $item['url'] }}">{{ $item['titulo'] }}</a>
                                        @else
                                            <span class="consulta-panel-nombre">{{ $item['titulo'] }}</span>
                                        @endif
                                        <span class="consulta-panel-detalle">{{ $item['detalle'] }}</span>
                                    </div>
                                    {{-- El estado del ítem: color + icono + texto, como pide la guía de UI. --}}
                                    <x-ui.estado :tono="$item['estado']['tono']" :icono="$item['estado']['icono']"
                                                 :title="$item['titulo_estado']">{{ $item['estado']['texto'] }}</x-ui.estado>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </x-ui.tarjeta>
        </div>

        {{-- El contenido. Hoy es el armazón: cada ítem del panel va a poner acá su sección, y el estado
             vacío dice exactamente eso (y no "no hay datos", que sería mentira). --}}
        <div class="col-12 col-lg-7 col-xl-8">
            <x-ui.tarjeta id="consulta-contenido" titulo="Contenido de la consulta" icono="bi-journal-medical">
                @php $vacio = $vm->sinContenido(); @endphp
                <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
            </x-ui.tarjeta>
        </div>
    </div>
@endsection
