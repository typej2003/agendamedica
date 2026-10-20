<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Impresion\FormatosEnBlanco;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\Vistas\HojaEnBlancoVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveElRegistroDelFormato;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **Formatos en blanco** (WEB-5.5b), la hoja: un documento (récipe, informe, constancia, reposo, referencia, hospitalización, dieta,
 * laboratorio o estudios) sin datos de paciente, con el formato guardado del médico, para imprimir y rellenar a mano.
 *
 * Sólo lee (`evolucion`, `recipe_formatos` y el médico): no escribe nada ni crea tokens de verificación. Sólo del médico, como el resto de
 * la pantalla «Formato de impresión» (`role:Root|Medico`): la secretaría responde 403.
 *
 * El formato que se usa es el de la **ficha que se está editando** (Fase 5 del cambio de `reg_medico`): el del médico del
 * contexto o, con `?reg_medico=`, el de un **centro médico** que el `Root` abrió desde el panel. Un formato en blanco no tiene
 * doctor que firme, así que sale con el membrete y la firma de esa ficha —la del centro, si es su formato—.
 */
class FormatosEnBlancoController extends Controller
{
    use ResuelveElRegistroDelFormato;

    public function __construct(private MembreteDelMedico $membretes)
    {
    }

    public function ver(Request $request, string $formato)
    {
        abort_unless(FormatosEnBlanco::existe($formato), 404);

        $medico = $this->fichaDelFormato($request);

        if ($medico === null) {
            return redirect()->route('clinica.contexto');
        }

        return view('clinica.imprimir.en-blanco', [
            'vm' => new HojaEnBlancoVista(
                $formato,
                $this->membretes->de($medico),
                $this->membretes->opciones($medico),
                $request->query('modo') === null ? null : (string) $request->query('modo'),
                [
                    'volver' => $this->urlDelFormato($request, $medico),
                    'hoja' => route('clinica.formato.en-blanco', $this->registroPedido($request) === ''
                        ? ['formato' => $formato]
                        : ['formato' => $formato, 'reg_medico' => $this->registroPedido($request)]),
                ],
            ),
        ]);
    }
}
