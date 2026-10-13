<?php

namespace App\Http\Controllers;

use App\Clinica\Verificacion\EstadoDeLaVerificacion;
use App\Clinica\Verificacion\LectorDeVerificacion;
use App\Clinica\Verificacion\RecetaVerificada;
use App\Clinica\Verificacion\Vistas\VerificacionPublicaVista;
use Illuminate\Http\Request;

/**
 * **La página pública de verificación del récipe** (WEB-5.6): la que abre quien escanea el QR de la hoja, o teclea el código corto
 * en `/verificar`. **Sin login ni sesión** (el grupo de rutas `verificacion` no pasa por `web`), con un límite de peticiones por
 * IP y sin dejar que se enumere nada: un token mal formado y uno inexistente dan **la misma** página con el mismo 404, y un código
 * corto que no existe, el mismo mensaje.
 *
 * Todo lo que se puede decir en público lo decide `LectorDeVerificacion`; este controlador sólo traduce HTTP. No loguea el token.
 */
class VerificacionPublicaController extends Controller
{
    public function __construct(private LectorDeVerificacion $lector)
    {
    }

    /** `GET /verificar/{token}`: el récipe de ese QR. 404 (la misma página) si no hay ninguno. */
    public function mostrar(string $token)
    {
        $receta = $this->lector->porToken($token);

        return response()->view('verificacion.pagina', [
            'vm' => new VerificacionPublicaVista($receta, route('verificacion.entrada', [], false)),
        ], $receta->estado === EstadoDeLaVerificacion::NO_ENCONTRADO ? 404 : 200);
    }

    /**
     * `GET /verificar`: el campo para tipear el código corto. Con `?codigo=` lo busca y, si existe, sigue a la página del récipe;
     * si no, vuelve al campo con un mensaje genérico (el mismo para un código mal escrito que para uno inexistente).
     */
    public function entrada(Request $request)
    {
        $escrito = $request->query('codigo');

        if (! is_string($escrito) || trim($escrito) === '') {
            return response()->view('verificacion.entrada', [
                'vm' => new VerificacionPublicaVista(RecetaVerificada::noEncontrado(), route('verificacion.entrada', [], false)),
            ]);
        }

        $token = $this->lector->tokenDelCodigo($escrito);

        if ($token !== null) {
            return redirect()->route('verificacion.mostrar', $token);
        }

        return response()->view('verificacion.entrada', [
            'vm' => new VerificacionPublicaVista(
                RecetaVerificada::noEncontrado(),
                route('verificacion.entrada', [], false),
                'No encontramos ese código. Revise que esté bien escrito (8 letras y números, como ABCD-EFGH) o compruébelo con el consultorio.',
                mb_substr(trim($escrito), 0, 20),
            ),
        ], 404);
    }
}
