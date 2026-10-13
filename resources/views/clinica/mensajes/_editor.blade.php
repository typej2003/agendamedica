{{-- Un editor de plantilla de mensaje (WEB-8.4). Recibe `$editor` (lo arma `PlantillasDeMensajeVista::editores`). El texto es lo que el médico
     tiene guardado o lo que escribió y falló al guardar; vacío significa «usa el mensaje por defecto». «Restablecer» es otro formulario
     (no se anidan formularios): el botón vive en el pie del editor y lo envía con el atributo `form`. La vista previa, el contador y los
     avisos los pinta el servidor al cargar y los actualiza el script al escribir, con la respuesta del mismo cálculo. --}}
@php($analisis = $editor['analisis'])
@php($id = $editor['slug'])

<x-ui.tarjeta :titulo="$editor['titulo']" icono="bi-chat-text" class="mb-3" :id="$editor['ancla']">
    <x-slot name="acciones">
        <x-ui.estado :tono="$editor['estado']['tono']" :icono="$editor['estado']['icono']">{{ $editor['estado']['texto'] }}</x-ui.estado>
    </x-slot>

    <p class="texto-tenue small">{{ $editor['paraQueSirve'] }}</p>

    <form method="POST" id="plantilla-{{ $id }}-restablecer" action="{{ $editor['urlRestablecer'] }}" data-confirmar="{{ $editor['avisoRestablecer'] }}">
        @csrf
    </form>

    <div class="row g-3" data-plantilla data-url-vista-previa="{{ $editor['urlVistaPrevia'] }}" data-maximo="{{ $editor['maximo'] }}">
        <div class="col-12 col-lg-7">
            <form method="POST" action="{{ $editor['urlGuardar'] }}" data-plantilla-form data-aviso="{{ $vm->avisoSinGuardar() }}">
                @csrf
                <input type="hidden" name="_plantilla" value="{{ $id }}">

                <span class="etiqueta d-block mb-2" id="plantilla-{{ $id }}-etiquetas">Etiquetas: toca una para ponerla donde está el cursor</span>
                <div class="plantilla-etiquetas mb-3" role="group" aria-labelledby="plantilla-{{ $id }}-etiquetas">
                    @foreach ($editor['etiquetas'] as $etiqueta)
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-etiqueta="{{ $etiqueta['etiqueta'] }}"
                                title="{{ $etiqueta['ayuda'] }}">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ $etiqueta['texto'] }}
                            <span class="texto-tenue">{{ $etiqueta['etiqueta'] }}</span>
                        </button>
                    @endforeach
                </div>

                <x-ui.campo etiqueta="Mensaje" :for="'plantilla-' . $id . '-texto'" error="texto"
                            :class="'mb-2'">
                    <textarea name="texto" id="plantilla-{{ $id }}-texto" rows="5" maxlength="{{ $editor['maximo'] }}"
                              class="form-control" data-plantilla-texto
                              aria-describedby="plantilla-{{ $id }}-contador"
                              placeholder="{{ $editor['porDefecto'] }}">{{ $editor['texto'] }}</textarea>
                </x-ui.campo>

                <p class="plantilla-contadores texto-tenue small mb-2" id="plantilla-{{ $id }}-contador" aria-live="polite">
                    <span data-contador>{{ $analisis['caracteres'] }} de {{ $editor['maximo'] }} caracteres</span>
                    <span aria-hidden="true">·</span>
                    <span data-sms>{{ $editor['sms'] }}</span>
                </p>

                <ul class="plantilla-avisos list-unstyled mb-0" data-avisos aria-live="polite">
                    @foreach ($analisis['avisos'] as $aviso)
                        <li class="plantilla-aviso plantilla-aviso-{{ $aviso['tono'] }}">
                            <i class="bi {{ $aviso['tono'] === 'aviso' ? 'bi-exclamation-triangle' : 'bi-info-circle' }}" aria-hidden="true"></i>
                            <span>{{ $aviso['texto'] }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" data-plantilla-aviso hidden>
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span>{{ $vm->avisoSinGuardar() }}</span>
                </p>

                <div class="d-flex flex-wrap justify-content-between gap-2 mt-3">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-usar-defecto="{{ $editor['porDefecto'] }}">
                            <i class="bi bi-file-earmark-text" aria-hidden="true"></i> Partir del mensaje por defecto
                        </button>
                        <button type="submit" form="plantilla-{{ $id }}-restablecer" class="btn btn-outline-secondary"
                                @if (! $editor['personalizada']) disabled @endif>
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restablecer
                        </button>
                    </div>
                    <button class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar
                    </button>
                </div>
            </form>
        </div>

        <div class="col-12 col-lg-5">
            <span class="etiqueta d-block mb-2">Así queda con datos de ejemplo</span>
            <div class="plantilla-previa" data-vista-previa aria-live="polite">{{ $analisis['vista_previa'] }}</div>
            <p class="texto-tenue small mt-2 mb-0">{{ $vm->notaDeCanales() }}</p>
        </div>
    </div>
</x-ui.tarjeta>
