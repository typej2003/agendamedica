{{-- El cuestionario por aparatos de pediatría (WEB-12.2): la solapa *Cuestionario* (`tabpage_31`) del escritorio. Once cuadros de texto que se guardan solos
     (`data-autoguardar`, `consulta-barra.js`). Sin plantillas: la pestaña del escritorio no las tiene. Sin estilos ni JS inline. --}}
@php $estado = $seccion->estado(); @endphp

<x-ui.tarjeta titulo="Cuestionario" icono="bi-chat-left-dots">
    <x-slot name="acciones">
        <span class="texto-tenue small me-2">{{ $seccion->cargados() }} de {{ $seccion->totalCampos() }} cuadros con dato</span>
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="cuestionario-form" data-autoguardar data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf

        <div class="row g-3 mb-3">
            @foreach ($seccion->campos() as $campo)
                <x-ui.campo :etiqueta="'¿' . $campo['etiqueta'] . '?'" :for="'cuestionario-' . $campo['columna']" :error="$campo['columna']" class="col-12 col-md-4">
                    <textarea name="{{ $campo['columna'] }}" id="cuestionario-{{ $campo['columna'] }}" class="form-control" rows="3">{{ $campo['valor'] }}</textarea>
                </x-ui.campo>
            @endforeach
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el cuestionario
            </button>
        </div>
    </form>
</x-ui.tarjeta>
