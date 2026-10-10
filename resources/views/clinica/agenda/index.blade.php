@extends('clinica.layout')

@section('titulo', 'Agenda')

@section('contenido')
    <x-ui.page-head titulo="Agenda" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            {{-- Herramientas del día (WEB-2.7/2.8): la ventana de envío, el listado que se imprime y el ABM
                 de días no laborables. Solo en la vista de día: son "las del día", no las del rango. --}}
            @if ($vm->esDia())
                <x-ui.menu etiqueta="Herramientas del día" texto="Más">
                    <a class="menu-item" href="{{ $vm->urlEnvio() }}">
                        <i class="bi bi-send" aria-hidden="true"></i> Enviar a todos
                    </a>
                    <a class="menu-item" href="{{ $vm->urlImprimir() }}" target="_blank" rel="noopener">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir listado
                    </a>
                    <a class="menu-item" href="{{ $vm->urlNoLaborables() }}">
                        <i class="bi bi-calendar-x" aria-hidden="true"></i> Días no laborables
                    </a>
                    {{-- Motivos de cita (WEB-2.17): dónde se marca que un motivo admite varias citas
                         pendientes del mismo paciente. --}}
                    <a class="menu-item" href="{{ $vm->urlMotivos() }}">
                        <i class="bi bi-tags" aria-hidden="true"></i> Motivos de cita
                    </a>
                </x-ui.menu>
            @endif

            <a class="btn btn-primary" href="{{ $vm->urlNueva() }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva cita
            </a>
        </x-slot>
    </x-ui.page-head>

    {{-- Barra de la agenda: dónde estoy (anterior / hoy / siguiente + título), la vista y los filtros. --}}
    <div class="agenda-barra">
        <div class="agenda-navegacion">
            <div class="btn-group" role="group" aria-label="Navegación">
                <a class="btn btn-outline-secondary" href="{{ $vm->urlAnterior() }}"
                   title="{{ $vm->etiquetaAnterior() }}" aria-label="{{ $vm->etiquetaAnterior() }}">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </a>
                <a class="btn btn-outline-secondary" href="{{ $vm->urlHoy() }}">Hoy</a>
                <a class="btn btn-outline-secondary" href="{{ $vm->urlSiguiente() }}"
                   title="{{ $vm->etiquetaSiguiente() }}" aria-label="{{ $vm->etiquetaSiguiente() }}">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </div>

            <h2 class="agenda-titulo">{{ $vm->titulo() }}</h2>

            @if ($vm->avisoNoLaborable())
                <x-ui.estado tono="aviso" icono="bi-calendar-x" title="{{ $vm->avisoNoLaborable() }}">Día no laborable</x-ui.estado>
            @endif

            {{-- La razón activa se ve como chip, no solo por el `<select>`: así queda claro por qué el
                 listado tiene menos renglones. --}}
            @if ($razon = $vm->razonActiva())
                <span class="chip">
                    <i class="bi bi-funnel" aria-hidden="true"></i> Razón: {{ $razon['texto'] }}
                    <a href="{{ $razon['urlQuitar'] }}" class="text-decoration-none" title="Quitar el filtro de razón"
                       aria-label="Quitar el filtro de razón">&times;</a>
                </span>
            @endif
        </div>

        <div class="agenda-controles">
            <div class="btn-group" role="group" aria-label="Vista">
                @foreach ($vm->vistas() as $opcion)
                    <a class="btn btn-outline-primary {{ $opcion['activa'] ? 'active' : '' }}"
                       href="{{ $opcion['url'] }}" data-vista="{{ $opcion['clave'] }}"
                       @if ($opcion['activa']) aria-current="true" @endif>{{ $opcion['etiqueta'] }}</a>
                @endforeach
            </div>

            {{-- Sede y razón en el **mismo** GET: los dos son el encabezado de la pantalla y se combinan
                 (`ddlb_1` y `ddlb_2` de `w_hacer_cita`, que se aplican juntos). --}}
            <form method="GET" action="{{ route('clinica.agenda') }}" class="agenda-filtros">
                @foreach ($vm->ocultos() as $nombre => $valor)
                    <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
                @endforeach
                <select name="sede" class="form-select control-sede" data-autoenviar aria-label="Sede">
                    @foreach ($vm->opcionesDeSede() as $opcion)
                        <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                    @endforeach
                </select>
                <select name="razon" class="form-select control-razon" data-autoenviar aria-label="Razón de la cita">
                    @foreach ($vm->opcionesDeRazon() as $opcion)
                        <option value="{{ $opcion['valor'] }}" {{ $opcion['elegida'] ? 'selected' : '' }}>{{ $opcion['texto'] }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    {{-- El aviso del día no laborable se ve en la agenda **antes** de agendar: es el texto que el
         escritorio muestra en su `messagebox` al intentar crear la cita. --}}
    @if ($vm->avisoNoLaborable())
        <div class="alert alert-warning py-2 d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-calendar-x" aria-hidden="true"></i>
            <span>{{ $vm->avisoNoLaborable() }}</span>
            <a class="ms-auto small" href="{{ $vm->urlNoLaborables() }}">Ver o editar los días no laborables</a>
        </div>
    @endif

    @include($vm->plantilla(), ['cuerpo' => $vm->cuerpo()])

    {{-- Un solo diálogo de cobro y uno de recordatorio para toda la página: el botón de cada cita los
         llena con sus datos (`public/js/clinica/agenda.js`). --}}
    @include('clinica.agenda._dialogo-cobro')
    @include('clinica.agenda._dialogo-recordatorio', ['canales' => $vm->canales()])
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/agenda.js') }}"></script>
    @if ($vm->esDia())
        <script src="{{ asset('js/clinica/agenda-pacientes.js') }}"></script>
    @endif
@endsection
