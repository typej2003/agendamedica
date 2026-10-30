<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\SolicitudDeBaja;
use App\Services\BajasDeCuenta;
use Illuminate\Http\Request;

/**
 * **Las tres páginas legales públicas** (2026-10-30): privacidad, términos y eliminación de cuenta.
 *
 * Son requisito de Google Play y tienen que abrirse **sin sesión y sin instalar la app**. El texto vive en
 * las vistas (`resources/views/legal/`); los datos del responsable, en `config/legal.php`, para que los tres
 * documentos no se desincronicen.
 */
class PaginasLegalesController extends Controller
{
    public function privacidad()
    {
        return view('legal.privacidad');
    }

    public function terminos()
    {
        return view('legal.terminos');
    }

    public function eliminarCuenta()
    {
        return view('legal.eliminar-cuenta');
    }

    /**
     * **Pide la baja.** La respuesta es la misma exista o no la cuenta: la página no sirve para averiguar qué
     * correos están registrados. La baja no arranca hasta que se confirma desde el correo.
     */
    public function solicitar(Request $request, BajasDeCuenta $bajas)
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ], [
            'email.required' => 'Escribe el correo con el que entras a Doctorísimo.',
            'email.email'    => 'Ese correo no parece válido.',
        ]);

        $bajas->solicitar((string) $datos['email']);

        return redirect()
            ->route('legal.baja.formulario')
            ->with('baja_enviada', true);
    }

    /** El enlace del correo: acá arrancan los 30 días. */
    public function confirmar(string $token, BajasDeCuenta $bajas)
    {
        $solicitud = $bajas->confirmar($token);

        return view('legal.baja-confirmada', [
            'solicitud' => $solicitud,
            'dias'      => (int) config('legal.dias_de_gracia_de_la_baja', 30),
        ]);
    }

    /** El enlace de cancelación del correo. */
    public function cancelar(string $token, BajasDeCuenta $bajas)
    {
        $solicitud = SolicitudDeBaja::where('token', $token)->first();

        if ($solicitud !== null) {
            $bajas->cancelar($solicitud, SolicitudDeBaja::MOTIVO_ENLACE);
        }

        return view('legal.baja-cancelada', ['solicitud' => $solicitud]);
    }
}
