<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Impresion\FormatosEnBlanco;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\Vistas\HojaEnBlancoVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **Formatos en blanco** (WEB-5.5b), la hoja: un documento (récipe, informe, constancia, reposo, referencia, hospitalización, dieta,
 * laboratorio o estudios) sin datos de paciente, con el formato guardado del médico, para imprimir y rellenar a mano.
 *
 * Sólo lee (`evolucion`, `recipe_formatos` y el médico): no escribe nada ni crea tokens de verificación. Sólo del médico, como el resto de
 * la pantalla «Formato de impresión» (`role:Root|Medico`): la secretaría responde 403.
 */
class FormatosEnBlancoController extends Controller
{
    public function __construct(private MembreteDelMedico $membretes)
    {
    }

    public function ver(Request $request, string $formato)
    {
        abort_unless(FormatosEnBlanco::existe($formato), 404);

        $medico = $request->attributes->get('contexto_clinico')['medico'];

        return view('clinica.imprimir.en-blanco', [
            'vm' => new HojaEnBlancoVista(
                $formato,
                $this->membretes->de($medico),
                $this->membretes->opciones($medico),
                $request->query('modo') === null ? null : (string) $request->query('modo'),
                [
                    'volver' => route('clinica.formato'),
                    'hoja' => route('clinica.formato.en-blanco', ['formato' => $formato]),
                ],
            ),
        ]);
    }
}
