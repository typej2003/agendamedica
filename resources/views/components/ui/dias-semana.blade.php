@props(['nombre', 'elegidos' => [], 'multiple' => true, 'prefijo' => 'dia'])

{{-- Los siete días de la semana como botones de dos letras (Lu Ma Mi Ju Vi Sa Do) que se encienden y se apagan.
     `multiple` = casillas (`name="dias[]"`, para cargar de una vez varios días con el mismo horario); sin `multiple`
     son botones de radio (un solo día, para editar un bloque que ya existe). El valor es el número ISO del día
     (lunes = 1, `OfficeSchedule::DIAS`). Usa `.btn-check` de Bootstrap: el input queda oculto y la etiqueta es el
     botón, así el teclado y el lector de pantalla siguen funcionando. `elegidos` son los números marcados. --}}
@php
    $abreviaturas = [1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Ju', 5 => 'Vi', 6 => 'Sa', 7 => 'Do'];
    $marcados = array_map('intval', (array) $elegidos);
    $campo = $multiple ? $nombre . '[]' : $nombre;
@endphp
<div {{ $attributes->merge(['class' => 'dias-semana']) }} role="group" aria-label="{{ $multiple ? 'Días de la semana' : 'Día de la semana' }}">
    @foreach (\App\Models\OfficeSchedule::DIAS as $numero => $dia)
        <input type="{{ $multiple ? 'checkbox' : 'radio' }}" class="btn-check" name="{{ $campo }}" id="{{ $prefijo }}-{{ $numero }}"
               value="{{ $numero }}" autocomplete="off" {{ in_array($numero, $marcados, true) ? 'checked' : '' }}>
        <label class="btn btn-outline-primary btn-sm dias-semana-boton" for="{{ $prefijo }}-{{ $numero }}"
               title="{{ $dia }}" aria-label="{{ $dia }}">{{ $abreviaturas[$numero] }}</label>
    @endforeach
</div>
