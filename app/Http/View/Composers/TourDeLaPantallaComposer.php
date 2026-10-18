<?php

namespace App\Http\View\Composers;

use App\Clinica\Tours\RegistroDeTours;
use App\Clinica\Tours\ToursDisponibles;
use App\Clinica\Tours\Vistas\TourVista;
use Illuminate\View\View;

/**
 * Le da a `clinica/layout` su **tour guiado** (`$tour`) — A-15: el que se muestra la primera vez que se
 * entra a una pantalla y que el botón «¿Cómo funciona esta pantalla?» vuelve a abrir.
 *
 * La pantalla sale del nombre de la ruta (`ToursDisponibles::pantallaDe`), así que no hay que tocar los
 * controladores de las cinco pantallas: el tour es parte del armazón, como el menú. Si la ruta no tiene
 * tour, el usuario no puede verlo por su rol o no quedan pasos, `$tour` queda en `null` y la vista no
 * pinta nada.
 *
 * La marca de «ya visto» vive en el servidor (`tours_vistos`, por usuario y pantalla), no en el navegador:
 * entrar desde otra computadora o desde el celular no vuelve a mostrar el tour.
 */
class TourDeLaPantallaComposer
{
    public function __construct(private RegistroDeTours $registro)
    {
    }

    public function compose(View $vista): void
    {
        $peticion = request();
        $usuario = $peticion->user();

        $pantalla = ToursDisponibles::pantallaDe($peticion->route()?->getName());
        $pasos = $pantalla === null ? [] : ToursDisponibles::pasos($pantalla, $usuario);

        if ($pantalla === null || $pasos === []) {
            $vista->with('tour', null);

            return;
        }

        $vista->with('tour', new TourVista(
            $pantalla,
            $pasos,
            $this->registro->yaVisto($usuario->id, $pantalla),
            // **Ruta relativa**: el `fetch` de la marca tiene que ir al mismo origen que la página. `route()`
            // arma la URL absoluta con `APP_URL`, que en desarrollo puede no ser el host donde se está mirando
            // (p. ej. `http://localhost` con `php artisan serve` en `127.0.0.1:8000`), y el POST se perdería.
            (string) parse_url(route('clinica.tour.visto', ['pantalla' => $pantalla]), PHP_URL_PATH),
        ));
    }
}
