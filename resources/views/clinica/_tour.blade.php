{{--
    El **tour guiado de la pantalla** (A-15): los pasos salen de `App\Clinica\Tours\ToursDisponibles` (título y
    texto), pero el HTML lo escribe esta vista —el JS `public/js/clinica/tour.js` sólo recorre los selectores—:
    así agregar un paso es una línea en el catálogo de PHP más el `data-tour` en la pantalla.

    El bloque tiene dos partes:

    - el **botón de reabrir** («¿Cómo funciona esta pantalla?»), discreto, arriba del encabezado de la pantalla
      y alineado a la derecha. Vuelve a lanzar el tour aunque ya esté visto: no lo marca de nuevo hasta que se
      termine o se salte.
    - los **pasos**, ocultos, y el **globo** donde se muestran. El globo es `role="dialog"` sin `aria-modal`:
      no bloquea la pantalla (se puede cerrar con «Saltar», con `Esc` y con «Listo»).

    Lo pinta `clinica/layout` para todas las pantallas; el compositor `TourDeLaPantallaComposer` deja `$tour`
    en `null` cuando la ruta no tiene tour, así que acá no hay nada que decidir.
--}}
@if ($tour && $tour->hayPasos())
    <div class="tour" data-tour-raiz data-url="{{ $tour->urlMarcarVisto() }}"
         data-mostrar="{{ $tour->debeMostrarse() ? '1' : '0' }}" data-pantalla="{{ $tour->pantalla() }}">

        <div class="tour-reabrir-fila">
            <button type="button" class="tour-reabrir" data-tour-reabrir
                    aria-label="¿Cómo funciona esta pantalla?" title="¿Cómo funciona esta pantalla?">
                <i class="bi bi-question-circle" aria-hidden="true"></i>
                <span class="tour-reabrir-texto">¿Cómo funciona esta pantalla?</span>
            </button>
        </div>

        {{-- Los pasos: cada uno sabe qué elemento señala y qué dice. `mostrar` no se usa en el HTML: el JS
             arma la lista con los selectores que existen en la pantalla. --}}
        <div class="tour-pasos" hidden>
            @foreach ($tour->pasos() as $paso)
                <div data-tour-paso data-selector="{{ $paso['selector'] }}">
                    <p class="tour-paso-titulo">{{ $paso['titulo'] }}</p>
                    <p class="tour-paso-texto">{{ $paso['texto'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="tour-globo" data-tour-globo role="dialog" aria-modal="false"
             aria-labelledby="tour-globo-titulo" aria-live="polite" tabindex="-1" hidden>
            <p class="tour-globo-cuenta" data-tour-cuenta></p>
            <h2 class="tour-globo-titulo" id="tour-globo-titulo" data-tour-titulo></h2>
            <p class="tour-globo-texto" data-tour-texto></p>
            <div class="tour-globo-acciones">
                <button type="button" class="btn btn-sm btn-link" data-tour-saltar>Saltar</button>
                <div class="tour-globo-botones">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tour-anterior>Anterior</button>
                    <button type="button" class="btn btn-sm btn-primary" data-tour-siguiente>Siguiente</button>
                </div>
            </div>
        </div>
    </div>
@endif
