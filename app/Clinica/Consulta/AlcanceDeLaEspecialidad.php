<?php

namespace App\Clinica\Consulta;

use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\FormatosEnBlanco;

/**
 * **Qué partes de la consulta aplican a la especialidad del contexto** (WEB-12.1).
 *
 * El panel y las solapas de «Atender» nacieron para ginecología. Esta clase es **el único lugar** donde se decide qué sobra en
 * otra especialidad, para que ni el shell, ni el controlador, ni las vistas pregunten «¿es pediatría?» por su cuenta.
 *
 * **Tres familias, y ninguna más** (2026-10-22, «modo medicina general» de Alexander):
 *
 *  - **Ginecología** (`ginecologia-y-obstetricia`) y **sin especialidad en el contexto**: todo como siempre. Una instalación
 *    sin especialidad es la del legado de ginecología, así que se comporta igual.
 *  - **Pediatría** (WEB-12.1): oculta el embarazo, **el estudio ecográfico entero**, el examen ginecológico, las mamas, la
 *    colposcopía…, y el récipe y el laboratorio de la pareja.
 *  - **Cualquier otra especialidad**: el **núcleo de medicina general**. Alexander lo pidió así para poder habilitar las nueve
 *    especialidades (`roadmap-octubre-alexander.md`) **antes** de construir los módulos propios de cada una: el médico nuevo
 *    atiende con lo que la consulta ya sabe hacer y no ve nada de ginecología ni de pediatría —ni ecos, ni embarazo, ni los
 *    informes de esos estudios—. Cuando su especialidad estrene módulos propios, esa pantalla se agrega; el núcleo no cambia.
 *
 * Lo oculto sólo se deja de **ofrecer**: la URL directa sigue abriendo, como el eco abdominal en ginecología (WEB-11.14).
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

    /**
     * El tipo de plantilla **sólo de medicina interna**: el examen funcional (`EF`, Alexander, 2026-10-29). Ninguna
     * otra especialidad tiene esa pantalla, así que fuera del internista no se ofrece en el ABM.
     */
    private const PLANTILLAS_SOLO_MEDICINA_INTERNA = ['EF'];

    /**
     * Las solapas del examen que son **sólo de ginecología** y que el modo medicina general no ofrece: las mamas, el examen
     * ginecológico y las tres de texto que le son propias (colposcopía, citología e histeroscopía). *Resultado de exámenes* y
     * la galería de imágenes **no** están: sirven a cualquier especialidad y el modo general las conserva.
     */
    private const SOLAPAS_SOLO_GINECOLOGIA = [
        PestanasDelExamen::MAMAS, PestanasDelExamen::GINECOLOGICO,
        TextosDelExamen::COLPOSCOPIA, TextosDelExamen::CITOLOGIA, TextosDelExamen::HISTEROSCOPIA,
    ];

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

    /**
     * **¿Es ginecología?** También lo es **no tener especialidad en el contexto**: la instalación del legado que no declara
     * especialidad es la de ginecología y hasta el 2026-10-22 se comportaba igual que ella, así que se conserva.
     */
    public function esGinecologia(): bool
    {
        return $this->slug === null
            || $this->slug === 'ginecologia-y-obstetricia'
            || str_contains($this->slug, 'ginecolog');
    }

    /**
     * **El modo medicina general** (Alexander, 2026-10-22): cualquier especialidad que no sea ginecología ni pediatría. Es el
     * núcleo compartido —agenda, consulta, récipes y documentos— sin nada de las otras dos: sin estudio ecográfico, sin
     * control de embarazo, sin examen ginecológico y sin la consulta de pareja. Es lo que permite **habilitar las nueve
     * especialidades** mientras los módulos propios de cada una llegan uno por uno.
     */
    public function esGeneral(): bool
    {
        return ! $this->esGinecologia() && ! $this->esPediatria();
    }

    /**
     * **¿Es medicina interna?** (MedicReport, 2026-10-28). Sigue siendo del **modo medicina general** —no ve nada de ginecología
     * ni de pediatría— pero su consulta tiene su propio árbol (`SeccionesDeLaConsulta`), con la evaluación física, el plan de
     * diagnóstico, el análisis evolutivo y la evaluación pre-operatoria del escritorio de medicina interna.
     */
    public function esMedicinaInterna(): bool
    {
        return $this->slug === 'medicina-interna';
    }

    /**
     * **¿El registro es de un internista?** Para lo que se arma sin el contexto clínico a mano (las hojas impresas, el resumen médico):
     * mira las especialidades del médico dueño del `reg_medico`.
     */
    public static function esDeMedicinaInterna(string $regMedico): bool
    {
        return (bool) \App\Models\Medico::where('reg_medico', $regMedico)->first()?->specialties->contains('slug', 'medicina-interna');
    }

    /** ¿Se ofrece el ítem 6 del panel (control de embarazo, gestas, FUR)? */
    public function muestraElEmbarazo(): bool
    {
        return $this->esGinecologia();
    }

    /** ¿Se ofrece el récipe de la pareja y la orden de laboratorio de la pareja? */
    public function muestraLaPareja(): bool
    {
        return $this->esGinecologia();
    }

    /**
     * **¿Se ofrece el ítem 5 del panel, el estudio ecográfico?** En pediatría **no** (Alexander, 2026-10-19: «en pediatría no va
     * el estudio ecográfico»). Hasta entonces la sección se ofrecía con la única eco abdominal, que era la respuesta por defecto
     * de la pregunta 11 de `preguntas-para-alexander.md` a falta de confirmación; pediatría no tiene la sección.
     *
     * En el **modo medicina general** tampoco (2026-10-22): sus siete formularios son ecos ginecológicos, obstétricos y
     * del embarazo, y son justamente «los ecos» que Alexander dejó fuera del modo básico. La eco abdominal, que es general,
     * entra con el módulo de imágenes de la especialidad cuando eso se construya.
     *
     * El escritorio de pediatría **sí** tiene una eco abdominal, pero no es este ítem: es un botón de su solapa *Procedimientos*
     * (`w_consulta_principal.srw`, `open(w_cargar_visualizar_eco_abdominal)`, al lado de las endoscopías). Si algún día entra,
     * entra como lo que es en su pantalla, no reabriendo el ítem 5.
     */
    public function muestraElEstudio(): bool
    {
        return $this->esGinecologia();
    }

    /**
     * Los tipos del estudio ecográfico que **no** se ofrecen. En pediatría y en el modo medicina general, **todos** (la
     * sección no está); en ginecología, todos menos la abdominal.
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
     * En el **modo medicina general** (2026-10-22) quedan *Examen físico*, *Resultado de exámenes* e *Imágenes*: se ocultan las
     * de pediatría y las cinco que son sólo de ginecología (`SOLAPAS_SOLO_GINECOLOGIA`).
     *
     * @return array<int,string>
     */
    public function pestanasDelExamenOcultas(): array
    {
        if ($this->esGeneral()) {
            return array_merge(PestanasDelExamen::PEDIATRICAS, self::SOLAPAS_SOLO_GINECOLOGIA);
        }

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
     * **Las pestañas de *Solicitudes* que no se ofrecen.** Hoy es una sola, y es de pediatría: **Dieta / Recomendaciones**.
     * En pediatría la dieta tiene su **ítem propio**, *Alimentación* (ítem 9 de su árbol), con sección propia desde el
     * 2026-10-19; mientras fue además una pestaña de *Solicitudes* el médico la veía **dos veces** (Alexander, O-41), y el
     * ítem 9 caía dentro de otra sección (O-40).
     *
     * La pestaña no desaparece del catálogo (`PestanasDeSolicitudes::TODAS`): ginecología la sigue teniendo donde siempre.
     *
     * @return array<int,string>
     */
    public function pestanasDeSolicitudesOcultas(): array
    {
        return $this->esPediatria() ? [PestanasDeSolicitudes::DIETA] : [];
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
     * En el **modo medicina general** (2026-10-22) se ocultan las de las dos especialidades: las cinco de ginecología
     * (`CO`, `CI`, `HI`, `EG`, `EM`, `ER`), los siete ecos —porque la sección del estudio no está— y las tres de pediatría.
     * Quedan las que cualquier especialidad usa: evolución (`EV`), informe (`IM`), dietas (`DI`), enfermedad actual (`EA`),
     * tratamiento (`TR`), resumen físico (`RF`) y resultado de exámenes (`RE`). El **examen funcional** (`EF`) es sólo del
     * internista (2026-10-29): se ofrece en medicina interna y se oculta en el resto.
     *
     * @return array<int,string>
     */
    public function tiposDePlantillaOcultos(): array
    {
        // El examen funcional es de medicina interna: fuera de ella no hay pantalla que lo consuma.
        $soloInternista = $this->esMedicinaInterna() ? [] : self::PLANTILLAS_SOLO_MEDICINA_INTERNA;

        // Ginecología (y el consultorio sin especialidad) conserva lo suyo: sólo se ocultan las tres de pediatría y el
        // examen funcional, que es del internista.
        if (! $this->esPediatria() && ! $this->esGeneral()) {
            return array_merge($soloInternista, self::PLANTILLAS_SOLO_PEDIATRIA);
        }

        $ocultos = array_merge(self::PLANTILLAS_SOLO_GINECOLOGIA, $soloInternista);

        // El modo general tampoco ofrece los tres tipos de *Exámenes/Estudios* de pediatría: no hay pantalla que los consuma.
        if ($this->esGeneral()) {
            $ocultos = array_merge($ocultos, self::PLANTILLAS_SOLO_PEDIATRIA);
        }

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
     * consulta y se ofrecen siempre. En pediatría son los cuatro; en el modo medicina general también, porque tampoco ofrece
     * los ecos; ginecología no cambia.
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
