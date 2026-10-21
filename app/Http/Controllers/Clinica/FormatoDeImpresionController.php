<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Impresion\FormatoDelMedico;
use App\Clinica\Impresion\FormatosEnBlanco;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Impresion\Vistas\FormatoDeImpresionVista;
use App\Clinica\Impresion\Vistas\HojaDePruebaVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveElRegistroDelFormato;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **Formato de impresión** (WEB-8.2), la lectura: la pantalla con las cuatro secciones (datos del membrete, logo,
 * estilo de cada bloque, firma y sello) y la **hoja de prueba**, la vista previa fiel del récipe con datos de ejemplo.
 *
 * Sólo del médico y del `Root` (`role:Root|Medico`): la secretaría imprime con el formato pero no lo configura. Qué
 * ficha se edita lo decide `ResuelveElRegistroDelFormato` (Fase 5 del cambio de `reg_medico`): **el médico en su
 * registro personal** —como siempre— o **un centro médico en el suyo**, que es lo único que un `Root` puede abrir con
 * `?reg_medico=` desde el panel de cuentas (un centro no inicia sesión). Lo que se escribe está en
 * `FormatoDeImpresionGuardarController`.
 */
class FormatoDeImpresionController extends Controller
{
    use ResuelveElRegistroDelFormato;

    public function __construct(
        private FormatoDelMedico $formatos,
        private MembreteDelMedico $membretes,
    ) {
    }

    /** La pantalla del formato. */
    public function index(Request $request)
    {
        $ficha = $this->fichaDelFormato($request);

        if ($ficha === null) {
            return redirect()->route('clinica.contexto');
        }

        $contexto = $request->attributes->get('contexto_clinico');
        $pedido = $this->registroPedido($request);

        return view('clinica.formato.index', [
            'vm' => new FormatoDeImpresionVista(
                $this->formatos->de($ficha),
                (string) $ficha->nombreMostrar,
                $contexto['reg_medico'] ?? null,
                $this->urls($pedido),
                array_map(
                    fn (array $formato) => $formato + ['url' => route('clinica.formato.en-blanco', $this->conRegistro(['formato' => $formato['clave']], $pedido))],
                    // Los informes de los ecos que la especialidad del contexto no tiene no se ofrecen (en pediatría no hay
                    // embarazo ni Doppler): la lista de lo que sobra la da `AlcanceDeLaEspecialidad`.
                    FormatosEnBlanco::catalogo(
                        AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug ?? null)->formatosEnBlancoOcultos(),
                    ),
                ),
                (bool) $ficha->es_centro,
            ),
        ]);
    }

    /**
     * La hoja de prueba: el récipe de ejemplo con el formato **guardado**. Con `?incrustada=1` va sin barra (es lo que carga
     * el `<iframe>` de la pantalla); sin ella es la página completa que abre «Imprimir prueba».
     */
    public function vistaPrevia(Request $request)
    {
        $ficha = $this->fichaDelFormato($request);

        if ($ficha === null) {
            return redirect()->route('clinica.contexto');
        }

        return view('clinica.imprimir.hoja-de-prueba', [
            'vm' => new HojaDePruebaVista(
                $this->membretes->de($ficha),
                $this->membretes->opciones($ficha),
                $request->boolean('incrustada'),
                $this->urlDelFormato($request, $ficha),
            ),
        ]);
    }

    // ----------------------------------------------------------------------------------------- Adentro

    /**
     * Las direcciones de la pantalla. Cuando se está editando un **centro** todas llevan `?reg_medico=`, para que cada
     * sección guarde bajo el centro y no bajo el médico del contexto (el guardado lo vuelve a resolver igual).
     *
     * @return array{vistaPrevia:string, vistaPreviaCompleta:string, membrete:string, logo:string, estilo:string, firma:string, restablecer:string}
     */
    private function urls(string $pedido): array
    {
        return [
            'vistaPrevia' => route('clinica.formato.vista-previa', $this->conRegistro(['incrustada' => 1], $pedido)),
            'vistaPreviaCompleta' => route('clinica.formato.vista-previa', $this->conRegistro([], $pedido)),
            'membrete' => route('clinica.formato.membrete', $this->conRegistro([], $pedido)),
            'logo' => route('clinica.formato.logo', $this->conRegistro([], $pedido)),
            'estilo' => route('clinica.formato.estilo', $this->conRegistro([], $pedido)),
            'firma' => route('clinica.formato.firma', $this->conRegistro([], $pedido)),
            'restablecer' => route('clinica.formato.restablecer', $this->conRegistro([], $pedido)),
        ];
    }

    /**
     * @param  array<string,mixed>  $parametros
     * @return array<string,mixed>
     */
    private function conRegistro(array $parametros, string $pedido): array
    {
        return $pedido === '' ? $parametros : $parametros + ['reg_medico' => $pedido];
    }
}
