{{-- Un «i» pegado a una etiqueta: al hacer clic muestra el texto (el slot) en un cuadro pequeño. Es para un dato
     calculado o de sólo lectura, o un contexto que no cabe en el hint del campo. Un clic más, Esc o un clic
     afuera lo cierran: lo resuelve `shell.js` por delegación en `document` (`data-info`), la vista no lleva JS. --}}
<span {{ $attributes->merge(['class' => 'info-pista']) }}>
    <button type="button" class="info-boton" data-info aria-label="Más información" aria-expanded="false">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
    </button>
    <span class="info-texto" role="note" hidden>{{ $slot }}</span>
</span>
