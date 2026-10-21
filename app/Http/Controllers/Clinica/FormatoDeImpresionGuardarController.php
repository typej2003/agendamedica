<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Configuracion\GuardarFormatoDelRecipe;
use App\Actions\Configuracion\GuardarMembreteDelMedico;
use App\Actions\Configuracion\LimitesDelMembrete;
use App\Actions\Configuracion\ReglasDelFormato;
use App\Actions\Configuracion\ResultadoDelMembrete;
use App\Clinica\Archivos\ImagenesDelMembrete;
use App\Http\Controllers\Clinica\Concerns\ResuelveElRegistroDelFormato;
use App\Http\Controllers\Controller;
use App\Models\Medico;
use App\Models\RecipeFormato;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

/**
 * **Formato de impresión del médico** (WEB-8.2), la escritura: una acción por sección de la pantalla, cada una con su botón
 * de guardar, más «Restablecer valores».
 *
 *  - `membrete`: la especialidad, el RIF y las tres líneas de pie (`evolucion`).
 *  - `logo`: la imagen del logo (`evolucion.logo`) y sus opciones —tamaño, alineación, borde y visibilidad—
 *    (`recipe_formatos.elementos.logo`).
 *  - `estilo`: la fuente, la alineación y el estilo de médico, especialidad, RIF y pie, y el color de la línea.
 *  - `firma`: la firma y el sello (`recipe_formatos`) y la posición del sello.
 *  - `restablecer`: una sección vuelve a los valores del legado.
 *
 * Todo es de la **ficha del formato** (el médico del contexto o, con `?reg_medico=`, un centro médico — Fase 5 del
 * cambio de `reg_medico`) y se guarda bajo su registro (`Medico::registroDelFormato()`), igual que el móvil. Las reglas
 * de validación y de escritura son las del API (`ReglasDelFormato`, `LimitesDelMembrete`, `GuardarMembreteDelMedico`,
 * `GuardarFormatoDelRecipe`): este controlador sólo traduce HTTP. Las Actions no saben de la vista.
 *
 * **La firma y el sello de un centro no se aceptan**: la firma de los reportes es siempre la del doctor que atendió, así
 * que la sección no se ofrece en la pantalla y un POST directo contesta con un aviso, no guardando algo que no se imprime.
 *
 * Los textos de error van en español (trampa 38: el `locale` de la app es inglés): cada regla tiene su aviso.
 */
class FormatoDeImpresionGuardarController extends Controller
{
    use ResuelveElRegistroDelFormato;

    /** Los datos del membrete. */
    public function membrete(Request $request, GuardarMembreteDelMedico $guardar)
    {
        $reglas = [];
        foreach (LimitesDelMembrete::IMPRESOS as $columna) {
            $reglas[$columna] = LimitesDelMembrete::regla($columna);
        }

        $datos = $request->validate($reglas, $this->mensajes());

        try {
            $resultado = $guardar->ejecutar($this->medico($request), $datos, null, false, null, 'web');
        } catch (InvalidArgumentException $excepcion) {
            return back()->withInput()->with('error', $excepcion->getMessage());
        }

        return $this->despuesDeGuardar($request, $resultado, 'formato-membrete', 'los datos del membrete');
    }

    /** El logo (la imagen) y sus opciones. */
    public function logo(Request $request, GuardarMembreteDelMedico $guardarMembrete, GuardarFormatoDelRecipe $guardarFormato)
    {
        $datos = $request->validate([
            'logo' => array_merge(['nullable'], ImagenesDelMembrete::reglas()),
            'quitar_logo' => 'sometimes|boolean',
            'elementos' => 'sometimes|array',
        ] + $this->reglasDeElementos(['logo']), $this->mensajes());

        $medico = $this->medico($request);
        $logo = $request->hasFile('logo') ? $request->file('logo') : null;
        $quitar = $request->boolean('quitar_logo');

        try {
            $resultado = ($logo !== null || $quitar)
                ? $guardarMembrete->ejecutar($medico, [], $logo, $quitar, null, 'web')
                : null;

            if (isset($datos['elementos'])) {
                $guardarFormato->ejecutar($medico, ['elementos' => $datos['elementos']]);
            }
        } catch (InvalidArgumentException $excepcion) {
            return back()->withInput()->with('error', $excepcion->getMessage());
        } catch (RuntimeException $excepcion) {
            return back()->withInput()->with('error', 'No se pudo guardar la imagen. Inténtalo de nuevo.');
        }

        if ($resultado !== null && $resultado->huboBloqueo()) {
            return $this->bloqueado($request, 'formato-logo');
        }

        return $this->aFormato($request, 'formato-logo')->with('estado', $logo !== null
            ? 'Listo: el logo y sus opciones quedaron guardados.'
            : ($quitar ? 'Listo: se quitó el logo y se guardaron sus opciones.' : 'Listo: se guardaron las opciones del logo.'));
    }

    /** El estilo de los bloques de texto y el color de la línea. */
    public function estilo(Request $request, GuardarFormatoDelRecipe $guardar)
    {
        $datos = $request->validate([
            'color_linea' => ['required', Rule::in(RecipeFormato::COLORES_LINEA)],
            'elementos' => 'sometimes|array',
        ] + $this->reglasDeElementos(RecipeFormato::ELEMENTOS_TEXTO), $this->mensajes());

        try {
            $guardar->ejecutar($this->medico($request), $datos);
        } catch (InvalidArgumentException $excepcion) {
            return back()->withInput()->with('error', $excepcion->getMessage());
        }

        return $this->aFormato($request, 'formato-estilo')->with('estado', 'Listo: se guardó el estilo del membrete y el color de la línea.');
    }

    /** La firma, el sello y la posición del sello. */
    public function firma(Request $request, GuardarFormatoDelRecipe $guardar)
    {
        // La firma de los reportes es la del doctor que atendió: la del centro no se imprime nunca, así que no se
        // configura (la pantalla tampoco ofrece la sección cuando el formato es de un centro, Fase 5).
        if ($this->esElFormatoDeUnCentro($request)) {
            return $this->aFormato($request, 'formato-firma')->with('error', 'La firma y el sello son siempre los del doctor que atendió: el centro no los configura.');
        }

        $datos = $request->validate([
            'firma' => array_merge(['nullable'], ImagenesDelMembrete::reglas()),
            'sello' => array_merge(['nullable'], ImagenesDelMembrete::reglas()),
            'firma_eliminar' => 'sometimes|boolean',
            'sello_eliminar' => 'sometimes|boolean',
            'sello_posicion' => ['required', Rule::in(RecipeFormato::POSICIONES_SELLO)],
        ], $this->mensajes());

        try {
            $guardar->ejecutar(
                $this->medico($request),
                ['sello_posicion' => $datos['sello_posicion']],
                $request->hasFile('firma') ? $request->file('firma') : null,
                $request->hasFile('sello') ? $request->file('sello') : null,
                $request->boolean('firma_eliminar'),
                $request->boolean('sello_eliminar'),
            );
        } catch (InvalidArgumentException $excepcion) {
            return back()->withInput()->with('error', $excepcion->getMessage());
        } catch (RuntimeException $excepcion) {
            return back()->withInput()->with('error', 'No se pudo guardar la imagen. Inténtalo de nuevo.');
        }

        return $this->aFormato($request, 'formato-firma')->with('estado', 'Listo: la firma y el sello quedaron guardados.');
    }

    /** «Restablecer valores»: una sección vuelve a los valores del legado. */
    public function restablecer(Request $request, GuardarFormatoDelRecipe $guardar)
    {
        $datos = $request->validate([
            'seccion' => ['required', Rule::in(GuardarFormatoDelRecipe::SECCIONES)],
        ], $this->mensajes());

        // La posición del sello también es de la firma: con el formato de un centro no hay nada que restablecer ahí.
        if ($datos['seccion'] === GuardarFormatoDelRecipe::SECCION_SELLO && $this->esElFormatoDeUnCentro($request)) {
            return $this->aFormato($request, 'formato-firma')->with('error', 'La firma y el sello son siempre los del doctor que atendió: el centro no los configura.');
        }

        try {
            $cambio = $guardar->restablecer($this->medico($request), $datos['seccion']);
        } catch (InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        $ancla = ['logo' => 'formato-logo', 'textos' => 'formato-estilo', 'sello' => 'formato-firma'][$datos['seccion']];
        $que = ['logo' => 'las opciones del logo', 'textos' => 'el estilo del membrete y el color de la línea', 'sello' => 'la posición del sello'][$datos['seccion']];

        return $this->aFormato($request, $ancla)->with('estado', $cambio
            ? 'Listo: ' . $que . ' volvieron a los valores del legado.'
            : 'No había nada que restablecer: ' . $que . ' ya están con los valores del legado.');
    }

    // ----------------------------------------------------------------------------------------- Adentro

    /**
     * La ficha cuyo formato se guarda: el médico del contexto o, con `?reg_medico=`, el centro (Fase 5). La regla vive
     * en `ResuelveElRegistroDelFormato`, la misma que usa la pantalla, así que lo que se ve y lo que se guarda es
     * siempre lo mismo.
     */
    private function medico(Request $request): Medico
    {
        return $this->fichaDelFormato($request) ?? $request->attributes->get('contexto_clinico')['medico'];
    }

    private function aFormato(Request $request, string $ancla)
    {
        $pedido = $this->registroPedido($request);

        return redirect(route('clinica.formato', $pedido === '' ? [] : ['reg_medico' => $pedido]) . '#' . $ancla);
    }

    private function despuesDeGuardar(Request $request, ResultadoDelMembrete $resultado, string $ancla, string $que)
    {
        if ($resultado->huboBloqueo()) {
            return $this->bloqueado($request, $ancla);
        }

        return $this->aFormato($request, $ancla)->with('estado', $resultado->huboCambios()
            ? 'Listo: se guardaron ' . $que . '.'
            : 'No había cambios que guardar en ' . $que . '.');
    }

    /** Otro equipo (el escritorio o el teléfono) editó esos datos más recientemente: no se pisa. */
    private function bloqueado(Request $request, string $ancla)
    {
        return $this->aFormato($request, $ancla)->with('error', 'No se guardó: otro equipo (por ejemplo el teléfono) editó esos datos más recientemente. Recarga la página para ver lo último.');
    }

    /**
     * ¿El formato que se está guardando es el de un **centro**? La firma y el sello no se configuran ahí: los de cada
     * reporte son los del doctor que atendió.
     */
    private function esElFormatoDeUnCentro(Request $request): bool
    {
        return (bool) ($this->fichaDelFormato($request)?->es_centro ?? false);
    }

    /**
     * Las reglas de `elementos.<bloque>.<opción>` de los bloques pedidos, las mismas del API.
     *
     * @param  array<int,string>  $bloques
     * @return array<string,mixed>
     */
    private function reglasDeElementos(array $bloques): array
    {
        return array_filter(
            ReglasDelFormato::reglasDeElementos(),
            fn (string $clave) => in_array(explode('.', $clave)[1] ?? '', $bloques, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<string,string> un aviso en español por cada regla que puede fallar */
    private function mensajes(): array
    {
        $mensajes = [
            'color_linea.required' => 'Elige el color de la línea.',
            'color_linea.in' => 'Elige uno de los colores de la lista.',
            'sello_posicion.required' => 'Elige dónde va el sello.',
            'sello_posicion.in' => 'Elige izquierda, centro o derecha.',
            'seccion.required' => 'No se sabe qué sección restablecer.',
            'seccion.in' => 'No se sabe qué sección restablecer.',
            'elementos.array' => 'Las opciones del formato no llegaron bien: recarga la página.',
            'elementos.*.alineacion.in' => 'Elige izquierda, centro o derecha.',
            'elementos.*.fuente.in' => 'Elige una de las fuentes de la lista.',
            'elementos.*.tamano.in' => 'Elige chico, mediano o grande.',
            'elementos.*.*.boolean' => 'Esa opción no llegó bien: recarga la página.',
            'quitar_logo.boolean' => 'La opción de quitar el logo no llegó bien: recarga la página.',
            'firma_eliminar.boolean' => 'La opción de quitar la firma no llegó bien: recarga la página.',
            'sello_eliminar.boolean' => 'La opción de quitar el sello no llegó bien: recarga la página.',
        ];

        foreach (['logo', 'firma', 'sello'] as $imagen) {
            $mensajes["{$imagen}.file"] = 'Elige un archivo de imagen.';
            $mensajes["{$imagen}.uploaded"] = 'No se pudo subir la imagen: pesa más de lo que el servidor admite (' . round(ReglasDelFormato::MAXIMO_IMAGEN_KB / 1024) . ' MB).';
            $mensajes["{$imagen}.max"] = 'La imagen no puede pesar más de ' . round(ReglasDelFormato::MAXIMO_IMAGEN_KB / 1024) . ' MB.';
        }

        foreach (LimitesDelMembrete::POR_COLUMNA as $columna => $maximo) {
            $mensajes["{$columna}.string"] = 'Escribe solo texto.';
            $mensajes["{$columna}.max"] = 'No puede tener más de ' . $maximo . ' caracteres.';
        }

        return $mensajes;
    }
}
