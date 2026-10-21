<?php

namespace App\Http\Controllers\RegistroDeMedicos;

use App\Http\Controllers\Controller;
use App\Models\SolicitudMedico;
use App\RegistroDeMedicos\CarnetDeLaSolicitud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **Servir la foto del carnet de una solicitud** a la bandeja de `/admin`. Es el único camino al archivo (disco
 * privado `solicitudes`); lo ve sólo quien atiende la bandeja (Root o Administrador).
 */
class CarnetDeLaSolicitudController extends Controller
{
    public function __invoke(Request $request, int $solicitud): StreamedResponse
    {
        $usuario = $request->user();

        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);

        $fila = SolicitudMedico::find($solicitud);

        abort_if($fila === null || ! $fila->carnet_ruta, 404);

        $disco = Storage::disk(CarnetDeLaSolicitud::DISCO);

        abort_unless($disco->exists($fila->carnet_ruta), 404);

        return $disco->response($fila->carnet_ruta, $fila->carnet_nombre ?: basename($fila->carnet_ruta), [
            'Content-Type' => $fila->carnet_tipo ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
