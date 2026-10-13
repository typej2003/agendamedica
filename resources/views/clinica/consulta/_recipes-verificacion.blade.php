{{-- El QR de verificación de un récipe (WEB-5.6): su estado como pastilla (color + icono + texto) y, si ya hay QR, la acción
     de anularlo o reactivarlo, con confirmación. Recibe `$v` (`ComparteElEditorDeRecipes::verificacionDe()`). Es un formulario
     aparte: no puede ir dentro del editor ni del menú de eliminar. --}}
<x-ui.estado :tono="$v['tono']" :icono="$v['icono']" :title="$v['titulo']">{{ $v['texto'] }}</x-ui.estado>

@if ($v['accion'])
    <form method="POST" action="{{ $v['accion']['url'] }}" data-confirmar="{{ $v['accion']['aviso'] }}">
        @csrf
        <button class="btn btn-sm btn-outline-secondary">
            <i class="bi {{ $v['accion']['icono'] }}" aria-hidden="true"></i> {{ $v['accion']['texto'] }}
        </button>
    </form>
@endif
