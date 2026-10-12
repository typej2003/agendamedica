<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Impresion\FormatoDelMedico;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\Vistas\FormatoDeImpresionVista;
use App\Clinica\Impresion\Vistas\HojaDePruebaVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **Formato de impresión del médico** (WEB-8.2), la lectura: la pantalla con las cuatro secciones (datos del membrete, logo,
 * estilo de cada bloque, firma y sello) y la **hoja de prueba**, la vista previa fiel del récipe con datos de ejemplo.
 *
 * Sólo del médico (`role:Root|Medico`): la secretaría imprime con el formato del médico pero no lo configura. El formato es
 * **del médico y no del consultorio**: se lee y se guarda bajo su registro principal, el mismo bajo el que lo lee la hoja
 * que imprime (`MembreteDelMedico`) y el que usa el móvil. Lo que se escribe está en `FormatoDeImpresionGuardarController`.
 */
class FormatoDeImpresionController extends Controller
{
    public function __construct(
        private FormatoDelMedico $formatos,
        private MembreteDelMedico $membretes,
    ) {
    }

    /** La pantalla del formato. */
    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $medico = $contexto['medico'];

        return view('clinica.formato.index', [
            'vm' => new FormatoDeImpresionVista(
                $this->formatos->de($medico),
                (string) $medico->nombreMostrar,
                $contexto['reg_medico'] ?? null,
                [
                    'vistaPrevia' => route('clinica.formato.vista-previa', ['incrustada' => 1]),
                    'vistaPreviaCompleta' => route('clinica.formato.vista-previa'),
                    'membrete' => route('clinica.formato.membrete'),
                    'logo' => route('clinica.formato.logo'),
                    'estilo' => route('clinica.formato.estilo'),
                    'firma' => route('clinica.formato.firma'),
                    'restablecer' => route('clinica.formato.restablecer'),
                ],
            ),
        ]);
    }

    /**
     * La hoja de prueba: el récipe de ejemplo con el formato **guardado**. Con `?incrustada=1` va sin barra (es lo que carga
     * el `<iframe>` de la pantalla); sin ella es la página completa que abre «Imprimir prueba».
     */
    public function vistaPrevia(Request $request)
    {
        $medico = $request->attributes->get('contexto_clinico')['medico'];

        return view('clinica.imprimir.hoja-de-prueba', [
            'vm' => new HojaDePruebaVista(
                $this->membretes->de($medico),
                $this->membretes->opciones($medico),
                $request->boolean('incrustada'),
                route('clinica.formato'),
            ),
        ]);
    }
}
