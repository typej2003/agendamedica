@props(['name', 'valor' => null, 'id' => null, 'required' => false, 'error' => null])

{{-- Un campo de hora que se escribe y se lee en 12 h con AM/PM («8:00 AM», «1:00 PM»), o en 24 h («13:00») si el
     dispositivo usa ese reloj (WEB-13.5, `App\Support\Regionalizacion`).

     Por qué no `<input type="time">`: el navegador lo pinta según su idioma (24 h en muchos) y la gente termina
     escribiendo 13:00 o 1:00pm sin saber qué va a pasar. Acá el campo visible es de texto, tolerante («1pm»,
     «1:30p», «13:00», «0800»), y al salir se normaliza a «1:00 PM» (`public/js/clinica/hora-ampm.js`).

     El servidor NO cambia: el `<input type="hidden" name="…">` lleva `HH:MM` de 24 h, que es lo que ya validan
     las Actions y los FormRequest. El campo visible no tiene `name`, así que no viaja. Si lo escrito no es una
     hora, el hidden queda vacío y el campo se marca inválido (no se envía basura).

     `valor` es `HH:MM[:SS]` (24 h, hora del consultorio) o ya un texto con AM/PM. `id` es el del campo visible
     (para el `<label for>`); el `class` y los demás atributos (`aria-label`, `data-*`) van al campo visible. --}}
@php
    $idDelCampo = $id ?? $name;
    $claveDelError = $error ?? $name;
    $texto = \App\Support\HoraLocal::reloj((string) $valor) ?: trim((string) $valor);
    $hhmm = \App\Support\HoraLocal::hora24((string) $valor);
    $ejemplo = \App\Support\Regionalizacion::actual()->hora(8, 0);
@endphp
<input type="text" id="{{ $idDelCampo }}" autocomplete="off" placeholder="{{ $ejemplo }}" maxlength="12"
       data-hora-ampm="{{ $idDelCampo }}-valor" value="{{ $texto }}" @if ($required) required @endif
       {{ $attributes->merge(['class' => 'form-control hora-ampm' . (isset($errors) && $errors->has($claveDelError) ? ' is-invalid' : '')]) }}>
<input type="hidden" name="{{ $name }}" id="{{ $idDelCampo }}-valor" value="{{ $hhmm }}">
