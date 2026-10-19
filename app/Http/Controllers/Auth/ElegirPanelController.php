<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * «¿Dónde quieres entrar?»: la pregunta que sólo se hace cuando la cuenta puede trabajar en más de un
 * área (por ejemplo, un médico que también es administrador).
 *
 * Es una pantalla de sólo lectura con un enlace por área: elegir no guarda ninguna preferencia, porque
 * la persona puede querer otra la próxima vez. Quien tiene una sola área no la ve nunca: se lo manda
 * directo a la suya.
 */
class ElegirPanelController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $paneles = $user->panelesDisponibles();

        if (count($paneles) < 2) {
            return redirect()->route($paneles[0]['ruta'] ?? $user->rutaDeInicio());
        }

        return view('auth.elegir-panel', [
            'paneles' => $paneles,
            'usuario' => $user,
        ]);
    }
}
