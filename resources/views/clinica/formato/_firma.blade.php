{{-- La firma y el sello (WEB-8.2): imágenes que se imprimen sobre la línea de la firma del récipe y de los documentos, y la posición
     del sello. Se guardan en `recipe_formatos` (la misma ruta que usa el móvil, que las baja por URL). «Restablecer valores»
     vuelve la posición del sello al centro sin tocar las imágenes. --}}
@php($estado = $vm->estadoDelSello())

<x-ui.tarjeta titulo="Firma y sello" icono="bi-pen" class="mb-3" id="formato-firma">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']">{{ $estado['texto'] }}</x-ui.estado>

        <form method="POST" action="{{ $vm->urlRestablecer() }}"
              data-confirmar="¿Volver el sello al centro? Las imágenes de la firma y del sello no se tocan.">
            @csrf
            <input type="hidden" name="seccion" value="{{ $vm->seccionSello() }}">
            <button class="btn btn-outline-secondary btn-sm" @if ($vm->selloEsDelLegado()) disabled @endif>Restablecer valores</button>
        </form>
    </x-slot>

    <form method="POST" action="{{ $vm->urlGuardarFirma() }}" enctype="multipart/form-data" data-formato-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        <div class="row g-3">
            @foreach ($vm->firmaYSello() as $imagen)
                <div class="col-12">
                    <div class="formato-imagen">
                        @if ($imagen['tiene'])
                            <img class="formato-miniatura" src="{{ $imagen['url'] }}" alt="{{ $imagen['alt'] }}">
                        @else
                            <div class="formato-miniatura formato-miniatura-vacia" aria-hidden="true"><i class="bi bi-image"></i></div>
                        @endif

                        <div class="formato-imagen-controles">
                            <x-ui.campo :etiqueta="$imagen['etiqueta']" :for="$imagen['campo'] . '-archivo'" :error="$imagen['campo']"
                                        :ayuda="$vm->ayudaDeLasImagenes()">
                                <input type="file" name="{{ $imagen['campo'] }}" id="{{ $imagen['campo'] }}-archivo" class="form-control"
                                       accept="image/jpeg,image/png,image/webp">
                            </x-ui.campo>

                            @if ($imagen['tiene'])
                                <div class="form-check mt-2">
                                    <input type="hidden" name="{{ $imagen['campo'] }}_eliminar" value="0">
                                    <input class="form-check-input" type="checkbox" name="{{ $imagen['campo'] }}_eliminar"
                                           id="{{ $imagen['campo'] }}-eliminar" value="1">
                                    <label class="form-check-label" for="{{ $imagen['campo'] }}-eliminar">{{ $imagen['quitar'] }}</label>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach

            <x-ui.campo etiqueta="Dónde va el sello" for="sello-posicion-izquierda" error="sello_posicion" class="col-12">
                @include('clinica.formato._alineacion', [
                    'nombre' => 'sello_posicion', 'prefijo' => 'sello-posicion', 'actual' => $vm->posicionDelSello(),
                    'opciones' => $vm->alineaciones(), 'etiqueta' => 'Dónde va el sello',
                ])
            </x-ui.campo>
        </div>

        <p class="form-text mt-3 mb-0">
            Las imágenes se guardan en el servidor y tu teléfono las baja en la próxima sincronización.
            Quien tenga la dirección de la imagen puede verla, así que no subas nada que no quieras que se vea impreso.
        </p>

        @include('clinica.formato._guardar', ['texto' => 'Guardar la firma y el sello'])
    </form>
</x-ui.tarjeta>
