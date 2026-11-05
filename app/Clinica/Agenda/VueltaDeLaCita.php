<?php

namespace App\Clinica\Agenda;

use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * **A dónde vuelve el formulario de la cita** cuando no se llegó desde la agenda (WEB-5.2): "Hacer cita" desde la
 * sección de récipes de una consulta abre el formulario de siempre con el paciente ya elegido y, al guardar,
 * **vuelve a esa sección** y no a la agenda.
 *
 * No agrega ninguna regla de agenda: sólo recuerda de qué consulta se venía (`volver_consulta`) y comprueba que sea
 * **una consulta del contexto y de quien puede entrar a ella** —la consulta es sólo del médico, así que la secretaría
 * no tiene a dónde volver y el parámetro se ignora—. Una consulta de otro consultorio, un valor que no es un número
 * o cualquier cosa rara dan `null`, y el formulario se comporta como siempre (vuelve a la agenda): el parámetro no
 * sirve ni para espiar consultas ajenas ni para redirigir a otro lado.
 *
 * ## Volver a la pantalla en la que estaba el médico (O-65, 2026-11-05)
 *
 * La sección de récipes tiene **cuatro pantallas** y las cuatro viven en el mismo `?seccion=recipes`: la lista
 * (`?lista=1`), el editor de un récipe (`?recipe=2`), el de uno nuevo (`?recipe=nuevo`) y el de la pareja
 * (`?recipe=pareja`). «Agendar aquí» está en todas, y el médico suele agendar **mientras arma el récipe**: si la
 * vuelta no repite el modo, la sección entra por su puerta de siempre (O-28, el editor del primer récipe) y el médico
 * aterriza en otra pantalla —la lista de medicamentos que estaba viendo desaparece—. Por eso la vuelta **repite el
 * modo** que viajó en `volver_recipe`, con las mismas claves que la URL (`lista`, `nuevo`, `pareja` o el número del
 * récipe). Sin él, la vuelta es la de siempre (la entrada de la sección) y nada cambia para quien no lo manda.
 */
final class VueltaDeLaCita
{
    /** La pantalla de la **lista** de récipes de la consulta (`?lista=1`), que no es un `?recipe=`. */
    public const LA_LISTA = 'lista';

    /** Las claves de `?recipe=` que no son el número de un récipe: el editor de uno nuevo y el de la pareja. */
    private const MODOS = ['nuevo', 'pareja'];

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /**
     * @param  array<string,mixed>  $contexto  el contexto clínico
     * @param  array<int,string>  $registrosMedicos  los `reg_medico` del médico del contexto
     * @param  bool  $trabajaLaConsulta  si quien agenda puede entrar a la consulta (`User::trabajaLaConsulta()`)
     * @param  mixed  $consultaId  el `volver_consulta` que llegó (o no)
     * @param  mixed  $recipe  el `volver_recipe` que llegó (o no): la pantalla de la sección en la que estaba
     * @return array{consulta:int, url:string, texto:string, recipe:?string}|null
     */
    public function de(array $contexto, array $registrosMedicos, bool $trabajaLaConsulta, $consultaId, $recipe = null): ?array
    {
        if (! $trabajaLaConsulta || ! is_scalar($consultaId) || ! ctype_digit((string) $consultaId)) {
            return null;
        }

        try {
            $consulta = $this->consultas->consulta($registrosMedicos, (int) $consultaId, $contexto['reg_medico']);
        } catch (ModelNotFoundException $excepcion) {
            return null;
        }

        $recipe = self::pantallaEnLaUrl($recipe);

        // La sección, con el modo que traía el médico (`?lista=1` o `?recipe=`); sin él, su entrada de siempre.
        $destino = [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES];

        if ($recipe === self::LA_LISTA) {
            $destino['lista'] = 1;
        } elseif ($recipe !== null) {
            $destino['recipe'] = $recipe;
        }

        return [
            'consulta' => (int) $consulta->id,
            'url' => route('clinica.consulta', $destino),
            'texto' => 'Volver a los récipes',
            'recipe' => $recipe,
        ];
    }

    /**
     * La pantalla de la sección de récipes que nombra un `?recipe=` —o el `volver_recipe` que vuelve del formulario—,
     * o `null` si no es ninguna de las que existen: un valor desconocido se ignora y la vuelta es la de siempre. Sólo
     * pasan las claves que la propia sección usa en su URL (`?lista=1`, `?recipe=nuevo`, `?recipe=pareja`,
     * `?recipe=2`), así que esto no puede apuntar a otro lado. La usa también la sección al armar el formulario, para
     * que lo que se manda y lo que se acepta sean la misma lista.
     */
    public static function pantallaEnLaUrl($recipe): ?string
    {
        if (! is_scalar($recipe)) {
            return null;
        }

        $recipe = (string) $recipe;

        if ($recipe === self::LA_LISTA || in_array($recipe, self::MODOS, true)) {
            return $recipe;
        }

        // El número de un récipe (el editor de ése). El tope es el de un número de récipe, no una regla nueva.
        return ctype_digit($recipe) && strlen($recipe) <= 4 ? $recipe : null;
    }
}
