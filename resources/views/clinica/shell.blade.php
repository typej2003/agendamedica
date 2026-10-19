@extends('clinica.layout')

@section('titulo', 'Pacientes de hoy')

@section('contenido')
    {{-- **El inicio del médico: las consultas de hoy** (A-12). No es una pantalla de accesos —las tarjetas «Agenda» y
         «Pacientes» se fueron porque el menú lateral ya lleva a las dos— ni otra agenda: es la lista de los pacientes
         del día, en la sede del contexto, sin navegación entre días, sin buscador y sin filtros. La lista y sus
         acciones salen de `InicioVista`, que reusa el armador de la agenda y el parcial de la fila. --}}
    {{-- La acción primaria del encabezado: **nueva cita** (decisión del usuario, 2026-10-19). Lleva al formulario de
         siempre, con el día y la sede del contexto ya elegidos, así el médico agenda sin salir a la agenda; el alta
         de un paciente que llega sin cita vive en el módulo Pacientes. --}}
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ $vm->urlNuevaCita() }}" data-tour="inicio-nueva-cita"
               title="{{ $vm->ayudaNuevaCita() }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva cita
            </a>
        </x-slot>
    </x-ui.page-head>

    {{-- El aviso de cumpleaños del día (WEB-2.9): una línea arriba de la lista, no una tarjeta. La pantalla que lo
         resuelve no es un módulo del menú, así que el inicio es el camino por el que el médico se entera de que hoy
         hay saludos para mandar. --}}
    @if ($vm->hayCumpleanosHoy())
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="alert" data-tour="inicio-cumpleanos">
            <i class="bi bi-balloon" aria-hidden="true"></i>
            <span>{{ $vm->textoCumpleanos() }}</span>
            <a class="ms-auto small fw-semibold" href="{{ $vm->urlCumpleanos() }}">Ver los cumpleaños y saludarlos</a>
        </div>
    @endif

    @if ($vm->estaVacio())
        @php $vacio = $vm->vacio(); @endphp
        <x-ui.tarjeta data-tour="inicio-lista">
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}
                {{-- «Nueva cita» no se repite acá: es la acción primaria del encabezado y está a la vista también
                     en el día vacío. La salida propia del vacío es la agenda, para revisar los próximos días. --}}
                <x-slot name="acciones">
                    <a class="btn btn-outline-secondary" href="{{ $vm->urlAgenda() }}">
                        <i class="bi bi-calendar3" aria-hidden="true"></i> Ir a la agenda
                    </a>
                </x-slot>
            </x-ui.vacio>
        </x-ui.tarjeta>
    @else
        {{-- La misma tabla de la agenda (turno, hora, paciente, estado y acciones). Cada fila es el parcial
             `clinica.agenda._fila`, así que ofrece lo mismo: «Confirmar», «Atender»/«Editar consulta», «Cobrar»,
             «Imprimir documentos» y el menú con el recordatorio y las citas del paciente. No se arrastra: el inicio
             no reordena la cola. --}}
        <x-ui.tarjeta sin-relleno data-tour="inicio-lista">
            <x-ui.tabla class="tabla-agenda">
                <thead>
                <tr>
                    <th class="col-numero">Turno</th>
                    <th class="col-hora">Hora</th>
                    <th>Paciente</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $item)
                    @include('clinica.agenda._fila', ['fila' => $item['fila'], 'reordenable' => false])
                @endforeach
                </tbody>
            </x-ui.tabla>
        </x-ui.tarjeta>
    @endif

    {{-- Los mismos diálogos de la agenda: el de «Atender» (que abre la consulta del día), el del cobro y el del
         recordatorio. El botón de cada fila los llena con sus datos (`shell.js` y `agenda.js`). --}}
    @include('clinica.agenda._dialogo-cobro')
    @include('clinica.agenda._dialogo-recordatorio', ['canales' => $vm->canales()])
    @if ($vm->puedeAtender())
        @include('clinica.agenda._dialogo-atender')
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/agenda.js') }}"></script>
@endsection
