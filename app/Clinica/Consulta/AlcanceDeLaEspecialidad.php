<?php

namespace App\Clinica\Consulta;

use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;

/**
 * **Qué partes de la consulta aplican a la especialidad del contexto** (WEB-12.1).
 *
 * El panel y las solapas de «Atender» nacieron para ginecología. Esta clase es **el único lugar** donde se decide qué sobra en
 * otra especialidad, para que ni el shell, ni el controlador, ni las vistas pregunten «¿es pediatría?» por su cuenta.
 *
 * **Ginecología y las demás especialidades no cambian**: sólo la pediatría oculta partes (embarazo, estudios ginecológicos y
 * obstétricos, examen ginecológico, mamas, colposcopía…, el récipe y el laboratorio de la pareja). Lo oculto sólo se deja de
 * **ofrecer**: la URL directa sigue abriendo, como el eco abdominal en ginecología (WEB-11.14).
 *
 * Es pura (sin base de datos): recibe el `slug` de la especialidad (`pediatria`, `ginecologia-y-obstetricia`…).
 */
final class AlcanceDeLaEspecialidad
{
    public function __construct(private ?string $slug)
    {
    }

    public static function de(?string $slug): self
    {
        return new self($slug);
    }

    public function esPediatria(): bool
    {
        return $this->slug !== null && str_contains($this->slug, 'pediatr');
    }

    /** ¿Se ofrece el ítem 6 del panel (control de embarazo, gestas, FUR)? */
    public function muestraElEmbarazo(): bool
    {
        return ! $this->esPediatria();
    }

    /** ¿Se ofrece el récipe de la pareja y la orden de laboratorio de la pareja? */
    public function muestraLaPareja(): bool
    {
        return ! $this->esPediatria();
    }

    /**
     * Los tipos del estudio ecográfico que **no** se ofrecen. En pediatría queda sólo la eco abdominal (respuesta por defecto de la
     * pregunta 11 de `preguntas-para-alexander.md`: se confirma con Alexander); en ginecología, todos menos la abdominal.
     *
     * @return array<int,string>
     */
    public function tiposDeEstudioOcultos(): array
    {
        if ($this->esPediatria()) {
            return array_values(array_diff(TiposDeEstudio::claves(), [TiposDeEstudio::ABDOMINAL]));
        }

        return $this->slug === 'ginecologia-y-obstetricia' ? [TiposDeEstudio::ABDOMINAL] : [];
    }

    /**
     * El tipo del estudio que se abre: el pedido si existe (la URL directa sigue abriendo lo que no se ofrece, como el eco abdominal en
     * ginecología) y, si no se pidió ninguno, el primero que se ofrece.
     */
    public function tipoDeEstudio(string $pedido): string
    {
        if (TiposDeEstudio::existe($pedido)) {
            return $pedido;
        }

        foreach (TiposDeEstudio::claves() as $clave) {
            if (! in_array($clave, $this->tiposDeEstudioOcultos(), true)) {
                return $clave;
            }
        }

        return TiposDeEstudio::POR_DEFECTO;
    }

    /**
     * Las solapas del examen (ítem 4) que **no** se ofrecen: en pediatría, todas las ginecológicas (examen ginecológico, mamas y
     * las cuatro de texto). Quedan el examen físico y, con las tareas pediátricas, *Mediciones* y *Cuestionario*.
     *
     * @return array<int,string>
     */
    public function pestanasDelExamenOcultas(): array
    {
        if (! $this->esPediatria()) {
            return [];
        }

        return array_values(array_diff(PestanasDelExamen::claves(), [PestanasDelExamen::FISICO]));
    }

    /** La solapa del examen que se abre: la pedida si existe (la URL directa sigue abriendo lo oculto) y, si no, la de siempre. */
    public function pestanaDelExamen(string $pedida): string
    {
        return PestanasDelExamen::normalizar($pedida);
    }
}
