<?php

namespace App\Http\Controllers\Clinica;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * **El latido de la sesión** (2026-10-19): un `GET` vacío que el JS de la web clínica llama cada pocos
 * minutos mientras la pantalla está a la vista, para que la sesión no se venza mientras el médico la usa.
 *
 * **Por qué existe.** La sesión de Laravel vence a los `SESSION_LIFETIME` minutos **sin peticiones**
 * (`config/session.php`, 120 por defecto). Las pantallas de la web clínica se quedan abiertas horas —la
 * consulta, sobre todo— y sus secciones de texto se guardan solas: entre tecleo y tecleo, o entre un
 * paciente y el siguiente, puede haber largos ratos sin una sola petición. Si la sesión ya había vencido,
 * el `POST` de «Terminar la consulta» contestaba **419** («Tu sesión venció, vuelve a cargar la página») y
 * el médico quedaba en un callejón sin salida: la cita no se marcaba como atendida y no había forma de
 * terminar la atención sin volver a entrar. Con el latido, la sesión se refresca sola mientras haya una
 * pantalla abierta y a la vista.
 *
 * **Por qué es un `GET`.** La regla de la casa es que una petición de lectura no escribe datos del
 * consultorio, y esta no escribe ninguno: lo único que se toca es la marca de actividad de la sesión —lo
 * que `StartSession` hace en **cualquier** petición— y la cookie, que se vuelve a firmar en cada respuesta.
 * No lee ni escribe una sola fila de la base y contesta **204 sin cuerpo**, así que el latido no cuesta
 * nada. Un `POST` (para «escribir») pediría token y confirmación y no tendría nada que escribir.
 *
 * **Dónde vive.** Va **fuera** del grupo que exige contexto (`clinica.contexto`): su único trabajo es
 * tocar la sesión, así que también sirve en la pantalla de elegir consultorio, que es a la que se cae
 * cuando se viene de una sesión vencida.
 *
 * Lo llama `public/js/clinica/latido-sesion.js`, que el armazón carga en **todas** las pantallas de
 * `/clinica` (ver `resources/views/clinica/layout.blade.php`).
 */
class LatidoDeSesionController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
