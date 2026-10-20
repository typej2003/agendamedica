{{-- El examen físico de pediatría (WEB-12.2): la solapa *Examen Físico* (`tabpage_30`) del escritorio de pediatría. Nueve cuadros por aparatos —con los
     rótulos de la pantalla, que no son los nombres de sus columnas— y el resumen físico, que arma «Actualizar» (`consulta-resumen.js`, el mismo botón de la
     evaluación) y es editable. Se guarda solo (`data-autoguardar`, `consulta-barra.js`). Sin estilos ni JS inline. --}}
@php $estado = $seccion->estado(); @endphp

<x-ui.tarjeta titulo="Examen físico" icono="bi-heart-pulse">
    <x-slot name="acciones">
        <span class="texto-tenue small me-2">{{ $seccion->cargados() }} de {{ $seccion->totalCampos() }} cuadros con dato</span>
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="examen-form"
          data-autoguardar data-url-resumen="{{ $seccion->urlResumen() }}" data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf

        <div class="row g-3 mb-3">
            @foreach ($seccion->campos() as $campo)
                <x-ui.campo :etiqueta="$campo['etiqueta']" :for="'examen-' . $campo['columna']" :error="$campo['columna']" class="col-12 col-md-4">
                    <textarea name="{{ $campo['columna'] }}" id="examen-{{ $campo['columna'] }}" class="form-control" rows="4">{{ $campo['valor'] }}</textarea>
                </x-ui.campo>
            @endforeach
        </div>

        {{-- El resumen (WEB-12.2): lo arma «Actualizar» con los cuadros de arriba y las mediciones; es editable y se guarda con la sección. --}}
        <x-ui.plantillas :plantillas="$seccion->plantillas()" destino="#fisico_resumen" tipo="RF" nombre="resumen físico" class="mb-3" />

        <div class="row g-3 mb-3" data-resumen="fisico">
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                    <label class="etiqueta mb-0" for="fisico_resumen">Examen físico (resumido)</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-resumen-actualizar="fisico">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Actualizar
                    </button>
                </div>
                <textarea name="fisico_resumen" id="fisico_resumen" class="form-control" rows="5"
                          placeholder="Aprieta «Actualizar» para armar el resumen con los datos de arriba, o escríbelo.">{{ $seccion->resumen() }}</textarea>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el examen físico
            </button>
        </div>
    </form>
</x-ui.tarjeta>
