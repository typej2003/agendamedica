<?php

namespace App\Http\Middleware;

use App\Models\Consulta;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **Guardar y después imprimir** (O-16): el «Imprimir» de una sección de la consulta ya no manda a la hoja de lo que está
 * guardado —que si el médico cambió algo todavía no existe, y terminaba en la página «No encontramos esa página»—, sino que
 * **primero guarda y después imprime**, con el mismo criterio de «Terminar la consulta», que guarda antes de cerrar.
 *
 * Cómo funciona: el botón «Imprimir» de las secciones es un `submit` del formulario de la sección que lleva
 * `imprimir=<clave del documento>` (`<x-ui.imprimir-guardando>`). El `POST` lo atiende el controlador de siempre —la regla de
 * escritura no cambia— y, si el guardado salió bien, este middleware **cambia el destino** de la redirección por la hoja de ese
 * documento. Así la regla está en **un solo lugar** y vale para todos los imprimibles (los siete ecos, la constancia, el reposo,
 * las referencias, el informe, la hospitalización, la dieta, el laboratorio, los estudios y los informes de los textos del
 * examen), sin tocar cada controlador.
 *
 * Si el guardado **no** salió —la validación falló o la Action dijo que no hay nada que guardar, como un estudio vacío— no se
 * imprime: se queda en la sección con su mensaje y se le agrega qué falta para poder imprimir (`SIN_DATOS`), en vez de mostrar
 * una hoja en blanco o un 404.
 *
 * El camino de la **cita** (la secretaría imprime «todo lo de la cita») no pasa por acá: ahí no hay nada que guardar.
 */
class ImprimeDespuesDeGuardar
{
    /** El respaldo cuando el documento igual no existe: se dice qué hacer en vez de mostrar la página de error (O-16). */
    public const SIN_DATOS = 'Guarda los datos del estudio para poder imprimir.';

    /** El campo que lleva el botón «Imprimir» con la clave del documento (`eco-pelvico`, `constancia`…). */
    public const CAMPO = 'imprimir';

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $clave = trim((string) $request->input(self::CAMPO, ''));

        // Sólo un POST de guardado que terminó en una redirección y que pidió imprimir un documento concreto (`1` es el valor
        // de un botón sin clave y no imprime nada).
        if (! $request->isMethod('post') || $clave === '' || $clave === '1' || ! $respuesta->isRedirect()) {
            return $respuesta;
        }

        $consulta = $request->route('consulta');

        if (! $consulta instanceof Consulta) {
            return $respuesta;
        }

        // El guardado dejó un mensaje de error (el estudio venía vacío, otro equipo pisó una columna…): no hay nada que
        // imprimir y se queda el mensaje, con la indicación de qué hace falta para imprimir.
        $sesion = $request->session();
        $error = $sesion->has('error') ? trim((string) $sesion->get('error')) : '';

        if ($error !== '') {
            $sesion->flash('error', $error . ' ' . self::SIN_DATOS);

            return $respuesta;
        }

        return redirect()->route('clinica.consulta.documentos.imprimir', [
            $consulta->id,
            'documento' => $clave,
        ]);
    }
}
