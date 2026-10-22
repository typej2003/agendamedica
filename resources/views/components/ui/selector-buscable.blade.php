@props([
    'name',
    'id' => null,
    'label' => null,
    'value' => '',
    'valueLabel' => '',
    'options' => null,
    'url' => null,
    'minChars' => 2,
    'limite' => 20,
    'umbral' => 50,
    'puedeCrear' => false,
    'crearUrl' => null,
    'crearEtiqueta' => 'Crear',
    'crearExtra' => null,
    'libre' => false,
    'required' => false,
    'disabled' => false,
    'placeholder' => 'Escribe para buscar',
    'error' => null,
    'conOld' => true,
    'maxlength' => null,
])

{{-- **Selector con búsqueda** (combobox): para elegir un valor de un conjunto grande donde un `<select>` no sirve
     (ciudades, médicos referidos, medicamentos…). Es genérico: no sabe de ciudades. Su JS es
     `public/js/clinica/selector-buscable.js` (cargado por los dos layouts; se activa solo, también en lo que Livewire
     inserta después).

     Parámetros:
       name           nombre del campo que viaja en el POST (el `<input type="hidden">`).
       id             id del campo visible (por defecto `selector-{name}`); la etiqueta `for` apunta a él.
       label          etiqueta (opcional: dentro de `<x-ui.campo>` ya hay una).
       value          valor actual. Con `old($name)` presente (formularios Blade) manda `old`.
       valueLabel     texto que se muestra para `value` (en modo remoto hay que darlo; en local se busca en `options`).
       options        lista incrustada `[['value' => 1, 'label' => 'Uno', 'detail' => 'opcional'], …]`. Si tiene
                      `umbral` opciones o menos (50 por defecto) se filtra en el cliente, sin tildes ni mayúsculas, con
                      las que empiezan por el texto primero.
       url            endpoint de búsqueda `GET url?q=&limite=` → `{resultados: [{value, label, detail}]}`. Se usa si
                      no hay `options` o si superan el `umbral`. Pide a los 250 ms, cancela la petición anterior, desde
                      `minChars` (2) letras y con `limite` (20) resultados.
       puede-crear    si el usuario puede crear lo que no existe: sólo entonces sale «Crear «texto»» como primera
                      opción (no hay coincidencia exacta). Pide además `crear-url`.
       crear-url      POST JSON `{nombre, …extra}` → `{value, label, detail, creada}`; la respuesta queda elegida.
       crear-etiqueta verbo de la opción (por defecto «Crear»).
       crear-extra    dato extra que pide el alta: `['name' => 'state_id', 'label' => 'Estado', 'options' => [[value, label]…]]`.
       libre          el valor es el texto escrito (con sugerencias): para campos de texto libre que sólo se asisten.
       maxlength (largo del texto en modo libre),
       con-old (false si el formulario comparte 
ame con otro), required, disabled, placeholder, error (mensaje; si falta se toma de `$errors` del campo).

     Livewire 2: los atributos extra (`wire:model.defer="city_id"`) van al input oculto; el control va en `wire:ignore`
     y el mensaje de error queda fuera para que se actualice. Cada cambio dispara `input` y `change` en el oculto. --}}
@php
    $campoId = $id ?? 'selector-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
    $listaId = $campoId . '-lista';
    $esLivewire = $attributes->whereStartsWith('wire:model')->getAttributes() !== [];
    $actual = ($conOld && ! $esLivewire && old($name) !== null) ? old($name) : $value;
    $actual = $actual === null ? '' : (string) $actual;
    $local = is_array($options) && (count($options) <= $umbral || ! $url);
    $etiquetaActual = (string) $valueLabel;
    if ($libre && $etiquetaActual === '') {
        $etiquetaActual = $actual;
    } elseif ($actual !== '' && $etiquetaActual === '' && is_array($options)) {
        foreach ($options as $opcion) {
            if ((string) ($opcion['value'] ?? '') === $actual) { $etiquetaActual = (string) ($opcion['label'] ?? ''); break; }
        }
    }
    $mensajeError = $error ?? (isset($errors) ? $errors->first($name) : '');
    $crear = $puedeCrear && $crearUrl;
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'selector-buscable' . ($mensajeError ? ' tiene-error' : '')]) }}
     data-selector-buscable data-modo="{{ $local ? 'local' : 'remoto' }}" data-min-chars="{{ $minChars }}"
     data-limite="{{ $limite }}" @if ($libre) data-libre="1" @endif
     @unless ($local) data-url="{{ $url }}" @endunless
     @if ($crear) data-crear-url="{{ $crearUrl }}" data-crear-etiqueta="{{ $crearEtiqueta }}" data-csrf="{{ csrf_token() }}"
        @if ($crearExtra) data-crear-extra="{{ json_encode($crearExtra, JSON_UNESCAPED_UNICODE) }}" @endif @endif>
    @if ($label)
        <label class="form-label" for="{{ $campoId }}">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
    @endif
    <div wire:ignore>
        <div class="selector-control">
            <input type="text" id="{{ $campoId }}" class="form-control selector-entrada{{ $mensajeError ? ' is-invalid' : '' }}"
                   role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false"
                   aria-controls="{{ $listaId }}" autocomplete="off" placeholder="{{ $placeholder }}"
                   value="{{ $etiquetaActual }}" @if ($maxlength) maxlength="{{ $maxlength }}" @endif @if ($required) data-requerido @endif @disabled($disabled)
                   @if ($mensajeError) aria-invalid="true" @endif>
            <button type="button" class="selector-limpiar" aria-label="Quitar la selección" hidden>
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
            <div class="selector-popup resultados-flotantes" hidden>
                <ul class="list-group list-group-flush selector-lista" id="{{ $listaId }}" role="listbox" aria-label="{{ $label ?: $placeholder }}"></ul>
                <div class="selector-estado" role="status" aria-live="polite" hidden></div>
            </div>
        </div>
        <input type="hidden" name="{{ $name }}" value="{{ $actual }}" class="selector-valor" {{ $attributes->whereStartsWith('wire:') }}>
        @if ($local)
            <script type="application/json" data-opciones>{!! json_encode(array_values($options), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
        @endif
    </div>
    @if ($mensajeError)
        <span class="campo-error d-block">{{ $mensajeError }}</span>
    @endif
</div>