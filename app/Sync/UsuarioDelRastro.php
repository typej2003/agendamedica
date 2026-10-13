<?php

namespace App\Sync;

use Illuminate\Support\Facades\Auth;

/**
 * **Quién** hizo una escritura de la web (WEB-8.5): el único lugar que decide qué usuario lleva el rastro de `sync_changes`.
 *
 * Lo llama el modelo `SyncChange` al crear una fila con `source = web` (o `api`, que es el que dejan los modelos `Cola` y
 * `Paciente` al borrarse). Así **ninguna Action tiene que acordarse** del usuario: cualquiera que deje su rastro por el modelo
 * —que es todo escritor del código— lo lleva sola.
 *
 * Es la cuenta de la **sesión web**: el móvil y el sync llegan por `api/*` con un token (nunca con la sesión), el escritorio
 * llega con su propia credencial y los comandos de consola no tienen sesión; en todos esos casos es `null`.
 */
final class UsuarioDelRastro
{
    public static function actual(): ?int
    {
        // Una petición del API (el móvil, el sync) nunca atribuye la escritura a una sesión web aunque la hubiera.
        if (app()->bound('request') && app('request')->is('api/*')) {
            return null;
        }

        $id = Auth::guard('web')->id();

        return $id === null ? null : (int) $id;
    }
}
