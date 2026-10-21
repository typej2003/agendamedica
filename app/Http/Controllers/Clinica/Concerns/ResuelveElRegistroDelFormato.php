<?php

namespace App\Http\Controllers\Clinica\Concerns;

use App\Models\Medico;
use Illuminate\Http\Request;

/**
 * **Qué formato se está editando** en la pantalla «Formato de impresión» (Fase 5 del cambio de `reg_medico`).
 *
 * La pantalla nació siendo **del médico** y se guardaba bajo su registro principal (WEB-8.2). Desde la Fase 5 el
 * **centro médico** también tiene su formato —el membrete, el logo y el pie con los que imprime los reportes de sus
 * doctores— y vive bajo el `reg_medico` del centro, igual que el del doctor bajo el suyo. Esta es la única regla que
 * dice cuál de los dos se edita, y la usan las tres pantallas que lo tocan (leer, guardar y los formatos en blanco):
 *
 *  - **`?reg_medico=<centro>`** — el formato de un **centro**. Sólo lo puede abrir un `Root` (es el panel de
 *    administración el que enlaza acá, porque un centro no inicia sesión) o el propio centro cuando su contexto ya es
 *    él. El contexto de un **doctor que atiende en el centro** no sirve para esta puerta: su formato es el suyo. Un
 *    `reg_medico` que no sea de un centro es **404**: por esta puerta no se edita el formato de un doctor, que cada uno
 *    edita desde su propia pantalla.
 *  - **sin parámetro** — el del **médico del contexto**: el suyo, en su registro personal, o el del centro cuando el
 *    contexto ya es un centro. Sin contexto no hay nada que editar y el que llama manda a elegir consultorio.
 *
 * Lo que se lee y lo que se escribe usan el `reg_medico` de la ficha que devuelve esto (`Medico::registroDelFormato()`),
 * así que la pantalla y la hoja impresa no pueden mirar registros distintos.
 */
trait ResuelveElRegistroDelFormato
{
    /** El parámetro con el que el panel de administración abre el formato de un centro. */
    protected function registroPedido(Request $request): string
    {
        return trim((string) $request->query('reg_medico', ''));
    }

    /** La ficha cuyo formato se edita, o `null` si no hay contexto con el que resolverlo. */
    protected function fichaDelFormato(Request $request): ?Medico
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $pedido = $this->registroPedido($request);

        if ($pedido === '') {
            return $contexto['medico'] ?? null;
        }

        $centro = Medico::where('reg_medico', $pedido)
            ->where('es_centro', true)
            ->orderBy('id')
            ->first();

        abort_if($centro === null, 404);

        // El `Root` puede abrir el de cualquier centro (es quien los administra) y el **propio centro** puede abrir el
        // suyo si algún día tiene cuenta; un doctor que atiende en el centro, no: su pantalla es la de su formato.
        $esElPropioCentro = ($contexto['reg_medico'] ?? null) === $pedido && ($contexto['es_centro'] ?? false) === true;

        abort_unless($esElPropioCentro || (bool) $request->user()?->hasRole('Root'), 403);

        return $centro;
    }

    /** La URL de esta misma pantalla para esa ficha: con el parámetro cuando es un centro. */
    protected function urlDelFormato(Request $request, Medico $ficha): string
    {
        return $this->registroPedido($request) === ''
            ? route('clinica.formato')
            : route('clinica.formato', ['reg_medico' => $ficha->reg_medico]);
    }
}
