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

    {{-- El calendario es el mismo que se ve dentro de la consulta (`?seccion=inmunizaciones`, O-63): el parcial compartido
         trae el resumen, el filtro, la tabla, las otras vacunas y el diálogo de registrar una dosis. --}}
    @include('clinica.vacunas._calendario', ['vm' => $vm])

    @include('clinica.consulta._visualizar')
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/consulta-visualizar.js') }}"></script>
    @if ($vm->puedeRegistrar())
        <script src="{{ asset('js/clinica/vacunas.js') }}"></script>
    @endif
@endsection
