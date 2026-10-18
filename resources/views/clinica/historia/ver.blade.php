@extends('clinica.layout')

@section('titulo', 'Resumen del paciente')

@section('contenido')
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlFicha()" volver-texto="Ficha del paciente">
        <x-slot name="acciones">
            <a class="btn btn-outline-secondary" href="{{ $vm->urlFicha() }}">
                <i class="bi bi-person-vcard" aria-hidden="true"></i> Ver la ficha
            </a>
            {{-- La acción primaria de una pantalla de sólo lectura es imprimir: es lo único que sale de acá.
                 Se abre aparte (`target="_blank"`) porque la vista de impresión es otra página, sin el
                 armazón de la web, como el listado del día de la agenda (WEB-2.7). Sin historia —o sin
                 consultas— no se ofrece: no hay historia que imprimir. --}}
            @if ($vm->puedeImprimir())
                <a class="btn btn-primary" href="{{ $vm->urlImprimir() }}" target="_blank" rel="noopener">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir historia
                </a>
            @else
                <button type="button" class="btn btn-primary" disabled
                        title="Todavía no hay consultas en la historia">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir historia
                </button>
            @endif
        </x-slot>
    </x-ui.page-head>

    {{-- El resumen propiamente dicho vive en el parcial `_resumen` (O-30): lo muestra esta pantalla y también
         el cajón de la consulta, para no armar un segundo resumen. --}}
    @include('clinica.historia._resumen')
@endsection
