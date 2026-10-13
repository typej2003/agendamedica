{{-- El logo (WEB-8.2): la imagen (se guarda en `evolucion.logo`, la misma ruta que usa el móvil) y sus opciones —tamaño, alineación,
     borde y si se imprime—, que son del formato (`recipe_formatos.elementos.logo`). «Restablecer valores» es un formulario aparte
     (no se anidan formularios) que vuelve las opciones al legado sin tocar la imagen. --}}
@php($logo = $vm->logo())
@php($estado = $vm->estadoDelLogo())

<x-ui.tarjeta titulo="Logo" icono="bi-image" class="mb-3" id="formato-logo">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']">{{ $estado['texto'] }}</x-ui.estado>

        <form method="POST" action="{{ $vm->urlRestablecer() }}"
              data-confirmar="¿Volver las opciones del logo a los valores del legado? La imagen no se toca.">
            @csrf
            <input type="hidden" name="seccion" value="{{ $vm->seccionLogo() }}">
            <button class="btn btn-outline-secondary btn-sm" @if ($vm->logoEsDelLegado()) disabled @endif>Restablecer valores</button>
        </form>
    </x-slot>

    <form method="POST" action="{{ $vm->urlGuardarLogo() }}" enctype="multipart/form-data" data-formato-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        <div class="row g-3">
            <div class="col-12">
                <div class="formato-imagen">
                    @if ($vm->tieneLogo())
                        <img class="formato-miniatura" src="{{ $vm->urlDelLogo() }}" alt="Logo actual del consultorio">
                    @else
                        <div class="formato-miniatura formato-miniatura-vacia" aria-hidden="true"><i class="bi bi-image"></i></div>
                    @endif

                    <div class="formato-imagen-controles">
                        <x-ui.campo :etiqueta="$vm->tieneLogo() ? 'Reemplazar el logo' : 'Subir el logo'" for="logo-archivo" error="logo"
                                    :ayuda="$vm->ayudaDeLasImagenes()">
                            <input type="file" name="logo" id="logo-archivo" class="form-control"
                                   accept="image/jpeg,image/png,image/webp">
                        </x-ui.campo>

                        @if ($vm->tieneLogo() || $vm->logoGuardado())
                            <div class="form-check mt-2">
                                <input type="hidden" name="quitar_logo" value="0">
                                <input class="form-check-input" type="checkbox" name="quitar_logo" id="quitar-logo" value="1">
                                <label class="form-check-label" for="quitar-logo">Quitar el logo actual</label>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <x-ui.campo etiqueta="Tamaño" for="logo-tamano" error="elementos.logo.tamano" class="col-sm-6">
                <select name="elementos[logo][tamano]" id="logo-tamano" class="form-select">
                    @foreach ($vm->tamanosDelLogo() as $tamano)
                        <option value="{{ $tamano['valor'] }}" {{ $tamano['marcada'] ? 'selected' : '' }}>{{ $tamano['texto'] }}</option>
                    @endforeach
                </select>
            </x-ui.campo>

            <x-ui.campo etiqueta="Dónde va" for="logo-alineacion-izquierda" error="elementos.logo.alineacion" class="col-sm-6">
                @include('clinica.formato._alineacion', [
                    'nombre' => 'elementos[logo][alineacion]', 'prefijo' => 'logo-alineacion', 'actual' => $logo['alineacion'],
                    'opciones' => $vm->alineaciones(), 'etiqueta' => 'Dónde va el logo',
                ])
            </x-ui.campo>

            <div class="col-12">
                @include('clinica.formato._opcion', ['nombre' => 'elementos[logo][borde]', 'id' => 'logo-borde', 'texto' => 'Con borde', 'marcada' => $logo['borde']])
                @include('clinica.formato._opcion', ['nombre' => 'elementos[logo][visible]', 'id' => 'logo-visible', 'texto' => 'Se imprime', 'marcada' => $logo['visible']])
            </div>
        </div>

        @include('clinica.formato._guardar', ['texto' => 'Guardar el logo'])
    </form>
</x-ui.tarjeta>
