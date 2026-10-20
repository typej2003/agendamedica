<?php

namespace App\Clinica\Pacientes\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use Illuminate\Support\Collection;

/**
 * La tarjeta **«Representante» del alta de pediatría** (2026-10-19): un representante puede tener varios hijos.
 *
 * En el resto de la web el representante se carga **después**, desde la ficha (WEB-3.4), porque el paciente ya tiene historia
 * y la tabla `representante` se llavea por ella. En el alta de pediatría eso obliga a un paso extra y —peor— deja al niño
 * nuevo sin su adulto hasta que alguien se acuerde. Por eso el alta de pediatría lo pide en el mismo formulario.
 *
 * **Es sólo del alta y sólo de pediatría**: la edición de la ficha sigue cargándolo con el diálogo de la ficha, y una
 * especialidad que no es pediatría no lo ve. Lo decide el controlador, que es quien tiene el contexto
 * (`AlcanceDeLaEspecialidad::de($slug)->esPediatria()`), y se lo dice a este ViewModel con `ofreceLaTarjeta`.
 *
 * Es **puro** (guia-arquitectura-web.md): no consulta nada ni arma URLs. Los valores salen de `old()` —para no perder lo
 * escrito cuando la validación falla— y el catálogo de parentescos llega ya leído.
 *
 * Los `name` del formulario llevan el prefijo `representante_` porque el alta ya manda `cedula`, `telefono` y `email` del
 * **paciente** en el mismo POST: sin el prefijo, el representante pisaría los datos del niño.
 */
final class RepresentanteDeAltaVista
{
    /** @var Collection<int,object> */
    private Collection $catalogoDeParentescos;

    /**
     * @param  bool  $ofreceLaTarjeta  el controlador ya resolvió «alta + pediatría»
     * @param  ?string  $urlBuscar  la URL del buscador por cédula; `null` cuando la tarjeta no se ofrece
     * @param  Collection<int,object>|null  $catalogoParentescos  filas con `codigo` y `nombre`
     */
    public function __construct(
        private bool $ofreceLaTarjeta,
        private ?string $urlBuscar = null,
        ?Collection $catalogoParentescos = null,
    ) {
        $this->catalogoDeParentescos = $catalogoParentescos ?? new Collection();
    }

    /** ¿La pantalla muestra la tarjeta? Es lo que decide el `@if` del formulario. */
    public function ofrece(): bool
    {
        return $this->ofreceLaTarjeta;
    }

    /** La URL a la que el script le pide los datos al buscar por cédula. Sin tarjeta no hay búsqueda que ofrecer. */
    public function urlBuscar(): ?string
    {
        return $this->ofreceLaTarjeta ? $this->urlBuscar : null;
    }

    /**
     * Las opciones del `<select>` de parentesco, con la elegida marcada. Las mismas que el diálogo de la ficha
     * (`RepresentanteDePacienteVista::parentescos()`): la primera es "— Sin dato —" y un código que no esté en el catálogo —el
     * que trajo el buscador del escritorio— se ofrece igual, para no perderlo al guardar.
     *
     * @return array<int, array{valor:?string, texto:string, elegido:bool}>
     */
    public function parentescos(): array
    {
        $elegido = $this->valor('parentesco');

        $opciones = [[
            'valor'   => null,
            'texto'   => '— Sin dato —',
            'elegido' => $elegido === null || $elegido === '',
        ]];

        foreach ($this->catalogoDeParentescos as $parentesco) {
            $opciones[] = [
                'valor'   => (string) $parentesco->codigo,
                'texto'   => (string) $parentesco->nombre,
                'elegido' => (string) $parentesco->codigo === (string) $elegido,
            ];
        }

        if ($elegido !== null && $elegido !== '' && ! $this->catalogoDeParentescos->contains('codigo', $elegido)) {
            $opciones[] = ['valor' => $elegido, 'texto' => $elegido . ' (código anterior)', 'elegido' => true];
        }

        return $opciones;
    }

    /**
     * Lo que el usuario escribió en un campo del representante (`old()`, para no perder nada cuando la validación falla).
     *
     * El `old()` se pide **sólo si hay petición**: este ViewModel se prueba sin levantar Laravel y ahí `old()` tira «Target
     * class [request] does not exist». Sin petición no hay nada escrito que recuperar (es un alta: no hay fila que mostrar).
     */
    public function valor(string $campo): ?string
    {
        $escrito = function_exists('app') && app()->bound('request') ? old('representante_' . $campo) : null;

        return CamposDePaciente::texto($escrito);
    }
}
