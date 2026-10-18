{{--
    El **botón que reabre el tour** (O-31): un solo lugar para su HTML, porque se pinta de dos maneras.

    - `redondo = true`: un botón circular con «?», sin texto. Es el de la **consulta**, que lo pone dentro
      de la fila de su encabezado, junto a Guardar y Terminar (`consulta/ver.blade.php`), para que la ayuda
      no ocupe un renglón propio. La fila no crece: es el mismo alto con un botón más.
    - `redondo = false`: el botón discreto con el texto «¿Cómo funciona esta pantalla?» al lado del icono.
      Es el de las demás pantallas, arriba de su encabezado (`_tour.blade.php`), como siempre.

    `data-tour-reabrir` lo encuentra desde `public/js/clinica/tour.js` (lo busca dentro de la raíz del tour
    y, si no está ahí, en el documento). El `aria-label` y el `title` son los mismos en las dos formas: el
    icono solo tiene que entenderse sin verlo. El foco visible ya es global.
--}}
@if ($redondo)
    <button type="button" class="tour-reabrir-redondo" data-tour-reabrir
            aria-label="¿Cómo funciona esta pantalla?" title="¿Cómo funciona esta pantalla?">
        <i class="bi bi-question-lg" aria-hidden="true"></i>
    </button>
@else
    <button type="button" class="tour-reabrir" data-tour-reabrir
            aria-label="¿Cómo funciona esta pantalla?" title="¿Cómo funciona esta pantalla?">
        <i class="bi bi-question-circle" aria-hidden="true"></i>
        <span class="tour-reabrir-texto">¿Cómo funciona esta pantalla?</span>
    </button>
@endif
