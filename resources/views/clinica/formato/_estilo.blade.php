{{-- El estilo de cada bloque de texto del membrete y el color de la línea (WEB-8.2): las "pestañas" de "Configurar Datos del Recipe"
     del escritorio —nombre del médico, especialidad, RIF y pie— con su fuente, alineación, negrita, cursiva, subrayado, borde y
     si se imprime. Se guarda en `recipe_formatos.elementos`, que es lo que lee la hoja y lo que baja al teléfono. --}}
@php($estado = $vm->estadoDeLosTextos())

<x-ui.tarjeta titulo="Estilo del membrete" icono="bi-fonts" class="mb-3" id="formato-estilo">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']">{{ $estado['texto'] }}</x-ui.estado>

        <form method="POST" action="{{ $vm->urlRestablecer() }}"
              data-confirmar="¿Volver el estilo del membrete y el color de la línea a los valores del legado?">
            @csrf
            <input type="hidden" name="seccion" value="{{ $vm->seccionTextos() }}">
            <button class="btn btn-outline-secondary btn-sm" @if ($vm->textosSonDelLegado()) disabled @endif>Restablecer valores</button>
        </form>
    </x-slot>

    <form method="POST" action="{{ $vm->urlGuardarEstilo() }}" data-formato-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        @foreach ($vm->bloquesDeTexto() as $bloque)
            @php($e = $bloque['elemento'])
            <fieldset class="formato-bloque">
                <legend class="formato-bloque-titulo">{{ $bloque['titulo'] }}</legend>
                <p class="texto-tenue small mb-2">{{ $bloque['ayuda'] }}</p>

                <div class="row g-3 align-items-end">
                    <x-ui.campo etiqueta="Fuente" :for="'estilo-' . $e . '-fuente'" :error="'elementos.' . $e . '.fuente'" class="col-sm-7">
                        <select name="elementos[{{ $e }}][fuente]" id="estilo-{{ $e }}-fuente" class="form-select">
                            @foreach ($vm->fuentes() as $id => $nombre)
                                <option value="{{ $id }}" {{ $bloque['fuente'] === $id ? 'selected' : '' }}>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Alineación" :for="'estilo-' . $e . '-alineacion-izquierda'" :error="'elementos.' . $e . '.alineacion'" class="col-sm-5">
                        @include('clinica.formato._alineacion', [
                            'nombre' => 'elementos[' . $e . '][alineacion]', 'prefijo' => 'estilo-' . $e . '-alineacion',
                            'actual' => $bloque['alineacion'], 'opciones' => $vm->alineaciones(), 'etiqueta' => 'Alineación de ' . $bloque['titulo'],
                        ])
                    </x-ui.campo>
                </div>

                <div class="mt-2">
                    @include('clinica.formato._opcion', ['nombre' => 'elementos[' . $e . '][negrita]', 'id' => 'estilo-' . $e . '-negrita', 'texto' => 'Negrita', 'marcada' => $bloque['negrita']])
                    @include('clinica.formato._opcion', ['nombre' => 'elementos[' . $e . '][italica]', 'id' => 'estilo-' . $e . '-italica', 'texto' => 'Cursiva', 'marcada' => $bloque['italica']])
                    @include('clinica.formato._opcion', ['nombre' => 'elementos[' . $e . '][subrayado]', 'id' => 'estilo-' . $e . '-subrayado', 'texto' => 'Subrayado', 'marcada' => $bloque['subrayado']])
                    @include('clinica.formato._opcion', ['nombre' => 'elementos[' . $e . '][borde]', 'id' => 'estilo-' . $e . '-borde', 'texto' => 'Con borde', 'marcada' => $bloque['borde']])
                    @include('clinica.formato._opcion', ['nombre' => 'elementos[' . $e . '][visible]', 'id' => 'estilo-' . $e . '-visible', 'texto' => 'Se imprime', 'marcada' => $bloque['visible']])
                </div>
            </fieldset>
        @endforeach

        <fieldset class="formato-bloque">
            <legend class="formato-bloque-titulo">Color de la línea</legend>
            <p class="texto-tenue small mb-2">La línea que separa el membrete del resto de la hoja.</p>

            <div class="formato-colores" role="radiogroup" aria-label="Color de la línea">
                @foreach ($vm->colores() as $color)
                    <input type="radio" class="btn-check" name="color_linea" id="color-{{ $color['valor'] }}" value="{{ $color['valor'] }}"
                           autocomplete="off" {{ $color['marcado'] ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary btn-sm" for="color-{{ $color['valor'] }}">
                        <span class="formato-color-muestra formato-color-{{ $color['valor'] }}" aria-hidden="true"></span> {{ $color['texto'] }}
                    </label>
                @endforeach
            </div>
            @error('color_linea')
                <span class="campo-error d-block">{{ $message }}</span>
            @enderror
        </fieldset>

        @include('clinica.formato._guardar', ['texto' => 'Guardar el estilo'])
    </form>
</x-ui.tarjeta>
