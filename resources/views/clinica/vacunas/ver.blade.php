@extends('clinica.layout')

@section('titulo', 'Vacunas')

@section('contenido')
    <x-ui.page-head :titulo="'Vacunas · ' . $vm->nombre()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlFicha()" volver-texto="Ficha del paciente">
        <x-slot name="acciones">
            @if ($vm->urlCarnet() && ! $vm->sinCatalogo())
                {{-- El carnet (WEB-7.4.4c): «Imprimir» abre la hoja en otra pestaña y «Visualizar» la muestra en un diálogo. --}}
                <x-ui.imprimir-con-vista :url="$vm->urlCarnet()" titulo="Carnet de vacunación">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el carnet
                </x-ui.imprimir-con-vista>
            @endif
        </x-slot>
    </x-ui.page-head>

    {{-- La pantalla es **la lista del paciente** (O-68/O-69), como la solapa *Inmunizaciones* del escritorio: primero lo que
         se le aplicó y lo que quedó programado, y abajo, plegado, el esquema del catálogo. Es la misma pantalla que la sección
         *Inmunizaciones* de la consulta (`?seccion=inmunizaciones`, O-63): los dos parciales son compartidos. --}}
    @include('clinica.vacunas._lista', ['vm' => $vm])
    @include('clinica.vacunas._calendario', ['vm' => $vm])

    {{-- El diálogo de la dosis (registrar o programar) es uno solo por página lo abre `public/js/clinica/vacunas.js`
         desde «Agregar una vacuna», «Aplicar» y «Registrar». --}}
    @if ($vm->puedeEscribir())
        @include('clinica.vacunas._dialogo-registrar', ['vm' => $vm])
    @endif

    @include('clinica.consulta._visualizar')
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/consulta-visualizar.js') }}"></script>
    @if ($vm->puedeEscribir())
        <script src="{{ asset('js/clinica/vacunas.js') }}"></script>
    @endif
@endsection
