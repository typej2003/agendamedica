<?php

namespace App\Clinica\Consulta;

use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\FormatosEnBlanco;

/**
 * **Qué partes de la consulta aplican a la especialidad del contexto** (WEB-12.1).
 *
 * El panel y las solapas de «Atender» nacieron para ginecología. Esta clase es **el único lugar** donde se decide qué sobra en
 * otra especialidad, para que ni el shell, ni el controlador, ni las vistas pregunten «¿es pediatría?» por su cuenta.
 *
 * **Ginecología y las demás especialidades no cambian**: sólo la pediatría oculta partes (embarazo, **el estudio ecográfico
 * entero**, examen ginecológico, mamas, colposcopía…, el récipe y el laboratorio de la pareja). Lo oculto sólo se deja de
 * **ofrecer**: la URL directa sigue abriendo, como el eco abdominal en ginecología (WEB-11.14).
 *
 * **Fuera de la consulta** el alcance también dice qué no se ofrece en las pantallas que mezclan especialidades: los
 * **tipos de plantilla de texto** —las plantillas de los exámenes y de los ecos que la especialidad no tiene— y los
 * **formatos en blanco** de esos ecos. El **listado de embarazos y partos** no se decide acá: es un reporte que declara su
 * especialidad en `ListadosDisponibles`, junto a los demás.
 *
 * Es pura (sin base de datos): recibe el `slug` de la especialidad (`pediatria`, `ginecologia-y-obstetricia`…).
 */
final class AlcanceDeLaEspecialidad
{
    /**
     * Los tipos de plantilla de texto que son **sólo de ginecología** y no se deducen de una pantalla: el resumen del examen
     * ginecológico (`EG`) y los dos ecos que la web no ofrece en ninguna parte (`EM` cuello, `ER` renal). Los demás —los de
     * las solapas del examen y los de los estudios— salen de lo que la especialidad sí muestra (ver
     * `tiposDePlantillaOcultos`), para que no haya dos verdades que se puedan contradecir.
     */
    private const PLANTILLAS_SOLO_GINECOLOGIA = ['EG', 'EM', 'ER'];

    /**
     * Los tipos de plantilla que son **sólo de pediatría**: los de las tres solapas de *Exámenes/Estudios*
     * (ítem 6, 2026-10-19). Fuera de pediatría no se ofrecen, porque no hay pantalla que las consuma; el tipo
     * sigue existiendo en `TiposDePlantillaDeTexto` y una plantilla con ese tipo **no se pierde** al guardar.
     */
    private const PLANTILLAS_SOLO_PEDIATRIA = ['EX', 'IG', 'EE'];

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
     * **¿Se ofrece el ítem 5 del panel, el estudio ecográfico?** En pediatría **no** (Alexander, 2026-10-19: «en pediatría no va
     * el estudio ecográfico»). Hasta entonces la sección se ofrecía con la única eco abdominal, que era la respuesta por defecto
     * de la pregunta 11 de `preguntas-para-alexander.md` a falta de confirmación; pediatría no tiene la sección.
     *
     * El escritorio de pediatría **sí** tiene una eco abdominal, pero no es este ítem: es un botón de su solapa *Procedimientos*
     * (`w_consulta_principal.srw`, `open(w_cargar_visualizar_eco_abdominal)`, al lado de las endoscopías). Si algún día entra,
     * entra como lo que es en su pantalla, no reabriendo el ítem 5.
     */
    public function muestraElEstudio(): bool
    {
        return ! $this->esPediatria();
    }

    /**
     * Los tipos del estudio ecográfico que **no** se ofrecen. En pediatría, **todos** (la sección no está); en ginecología,
     * todos menos la abdominal.
     *
     * @return array<int,string>
     */
    public function tiposDeEstudioOcultos(): array
    {
        if (! $this->muestraElEstudio()) {
            return TiposDeEstudio::claves();
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
     * Las solapas del examen (ítem 4) que **no** se ofrecen: en pediatría, todas las ginecológicas (examen físico general, examen
     * ginecológico, mamas y las cuatro de texto); quedan las propias: *Mediciones*, *Examen físico* y *Cuestionario* (WEB-7.4.3a y WEB-12.2).
     * Fuera de pediatría se ocultan esas tres. La **galería de imágenes** (O-22) no es clínica de una especialidad: se ofrece siempre.
     *
     * @return array<int,string>
     */
    public function pestanasDelExamenOcultas(): array
    {
        if (! $this->esPediatria()) {
            return PestanasDelExamen::PEDIATRICAS;
        }

        return array_values(array_diff(
            PestanasDelExamen::claves(),
            array_merge(PestanasDelExamen::PEDIATRICAS, PestanasDelExamen::COMUNES),
        ));
    }

    /**
     * La solapa del examen que se abre: la pedida si existe (la URL directa sigue abriendo lo oculto) y, si no se pidió ninguna, la de
     * siempre; en pediatría, las mediciones, que es con lo que arranca el escritorio (`tabpage_29`).
     */
    public function pestanaDelExamen(string $pedida): string
    {
        if ($this->esPediatria() && trim($pedida) === '') {
            return PestanasDelExamen::MEDICIONES;
        }

        return PestanasDelExamen::normalizar($pedida);
    }

    /**
     * Los **tipos de plantilla de texto que no se ofrecen** en esta especialidad: las plantillas de lo que la especialidad no
     * muestra. Son las de las solapas del examen que no están (la colposcopía `CO`, la citología `CI`, la histeroscopía `HI`,
     * el resultado de exámenes `RE`), las del resumen del examen ginecológico (`EG`) y las de los ecos que no se ofrecen —en
     * pediatría **los siete**, porque la sección no está—, más los dos que la web no ofrece en ninguna especialidad (`EM`, `ER`).
     *
     * En pediatría quedan, entonces, las de las secciones que sí tiene: evolución (`EV`), informe (`IM`), dietas (`DI`),
     * enfermedad actual (`EA`), tratamiento (`TR`), **resumen físico (`RF`)** —que es el de su solapa *Examen físico*— y las
     * tres de su ítem *Exámenes/Estudios* (`EX`, `IG`, `EE`). El `RF` no se oculta con la solapa `fisico` de ginecología
     * porque la solapa pediátrica `examen` usa el mismo tipo: por eso la lista se deduce de las solapas que quedan y no de
     * las que se van.
     *
     * Fuera de pediatría, en cambio, se ocultan **esas tres** (`PLANTILLAS_SOLO_PEDIATRIA`): no hay pantalla que las
     * consuma. La única diferencia con lo que hacía antes es eso — las demás siguen ofreciéndose todas.
     *
     * @return array<int,string>
     */
    public function tiposDePlantillaOcultos(): array
    {
        if (! $this->esPediatria()) {
            return self::PLANTILLAS_SOLO_PEDIATRIA;
        }

        $ocultos = self::PLANTILLAS_SOLO_GINECOLOGIA;

        foreach ($this->pestanasDelExamenOcultas() as $pestana) {
            $tipo = PestanasDelExamen::esDeTexto($pestana) ? TextosDelExamen::tipoDePlantillas($pestana) : null;

            if ($tipo !== null) {
                $ocultos[] = $tipo;
            }
        }

        foreach ($this->tiposDeEstudioOcultos() as $estudio) {
            $ocultos = array_merge($ocultos, PlantillasDelEstudio::tiposDe($estudio));
        }

        return array_values(array_unique($ocultos));
    }

    /**
     * Los **formatos en blanco que no se ofrecen** en esta especialidad: los informes de los ecos que no tiene (las claves de
     * `DocumentoImpreso`: el obstétrico del 1.er trimestre y el del 2.º/3.er, y el Doppler perinatal). El resto —el récipe, el
     * informe, la constancia, el reposo, la referencia, la hospitalización, la dieta, el laboratorio y los estudios— son de la
     * consulta y se ofrecen siempre. En pediatría son los cuatro; ginecología y las demás no cambian.
     *
     * La regla no es una lista de formatos: se deduce de los **estudios** que la especialidad no ofrece, que es lo que ya
     * decide `tiposDeEstudioOcultos`. Un formato nuevo de un estudio escondido queda escondido sin tocar esto.
     *
     * @return array<int,string>
     */
    public function formatosEnBlancoOcultos(): array
    {
        $ocultos = [];

        foreach (FormatosEnBlanco::porEstudio() as $formato => $estudio) {
            if (in_array($estudio, $this->tiposDeEstudioOcultos(), true)) {
                $ocultos[] = $formato;
            }
        }

        return $ocultos;
    }
}
