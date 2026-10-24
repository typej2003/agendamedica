@extends('clinica.layout')

@section('titulo', 'Pacientes')

@section('contenido')
    <x-ui.page-head titulo="Pacientes" :subtitulo="$vm->subtitulo()">
        <x-slot name="acciones">
            <a class="btn btn-primary" href="{{ route('clinica.pacientes.nuevo') }}">
                <i class="bi bi-person-plus" aria-hidden="true"></i> Nuevo paciente
            </a>
        </x-slot>
    </x-ui.page-head>

    {{-- El buscador y el filtro "Sin historia" (WEB-3.3) van en su barra, no en el encabezado: el filtro es lo que la
         secretaría necesita para ir a llenar las que faltan, y el conteo de cada segmento sale del ViewModel y respeta
         la búsqueda escrita. --}}
    <x-ui.barra-herramientas>
        <x-ui.buscador :agregar-url="route('clinica.pacientes.nuevo')" agregar-texto="Nuevo paciente" data-buscar-filas=".identidad-nombre"
                       :accion="route('clinica.pacientes')" :valor="$vm->buscar()"
                       :limpiar="$vm->urlLimpiarBusqueda()"
                       placeholder="Nombre, cédula o n.º de historia" etiqueta="Buscar paciente">
            {{-- Buscar dentro del segmento en el que se está: si no, escribir en el buscador
                 devolvería a "Todos" sin que nadie lo haya pedido. --}}
            @if ($vm->hayFiltro())
                <input type="hidden" name="historia" value="{{ $vm->filtro() }}">
            @endif
        </x-ui.buscador>
        <x-slot name="filtros">
            <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Filtrar por historia clínica" />
        </x-slot>
    </x-ui.barra-herramientas>

    {{-- La región que la búsqueda en vivo reemplaza (y que el controlador devuelve sola cuando la petición es
         AJAX): la nota de la corrección, la tabla y el pie. --}}
    @include('clinica.pacientes._resultados')
@endsection
