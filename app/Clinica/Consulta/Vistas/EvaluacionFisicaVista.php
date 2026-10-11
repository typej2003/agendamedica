<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EvaluacionFisica;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;

/**
 * **La evaluación física de una consulta** (WEB-4.3, ítem 4 del panel del legado): el examen general
 * —antropometría y examen por aparatos— y el ginecológico, con guardado explícito.
 *
 * El ViewModel no sabe de base de datos ni de `Request`: recibe la evaluación ya leída
 * (`App\Clinica\Consulta\EvaluacionFisica`) y el `old()` de un POST que falló. El **mapa de campos** —qué
 * columna, con qué rótulo y de qué tabla— no vive acá sino en `ExamenFisicoDeLaConsulta`, que es el mapa
 * del dato.
 *
 * Tres cosas que decide esta clase y no la vista:
 *
 *  - **El IMC, derivado y no pedido.** El escritorio lo calcula con `gffimc()` (`fuentes/utilitarios/
 *    gffimc.srf`: peso / talla², y 0 si falta cualquiera de los dos) y lo muestra en un campo de sólo
 *    lectura: **no se guarda en ninguna columna**. La web hace lo mismo —se recalcula al escribir peso o
 *    talla— y con talla o peso inválidos muestra `—` en vez de un número inventado.
 *  - **Los resúmenes del escritorio** (`total_f` general y `total_g` ginecológico): se muestran **como
 *    están** y no se regeneran (el porqué, en `App\Actions\Consulta\GuardarEvaluacionFisica`). Un
 *    resumen vacío se muestra como "sin resumen", no como un hallazgo.
 *  - **Los valores del formulario**: lo que se acaba de escribir (`old()`, para no borrar nada ante un
 *    error —guía de UI §3—) o lo guardado.
 */
final class EvaluacionFisicaVista
{
    /**
     * @param  array<string,mixed>  $old  el `old()` del formulario (`[]` en la primera carga)
     * @param  string  $urlGuardar  a dónde postea "Guardar la evaluación física"
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de la guía de UI)
     */
    public function __construct(
        private EvaluacionFisica $evaluacion,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private array $old = [],
    ) {
    }

    /**
     * La antropometría y los signos, en filas listas para pintar.
     *
     * @return array<int,array<string,mixed>>
     */
    public function antropometria(): array
    {
        return $this->filas(ExamenFisicoDeLaConsulta::antropometria());
    }

    /** El examen por aparatos, en filas listas para pintar. */
    public function general(): array
    {
        return $this->filas(ExamenFisicoDeLaConsulta::general());
    }

    /** El examen ginecológico, en filas listas para pintar. */
    public function ginecologico(): array
    {
        return $this->filas(ExamenFisicoDeLaConsulta::ginecologico());
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /**
     * El **IMC** (peso / talla²), el mismo cálculo del escritorio (`gffimc.srf`), o `—` si falta el peso,
     * la talla, o alguno de los dos no es un número positivo.
     *
     * No se guarda en ninguna columna —el legado tampoco lo guarda— así que no queda en el rastro del
     * sync: es una cuenta de pantalla.
     */
    public function imc(): string
    {
        $peso = (float) ($this->evaluacion->valor('peso') ?? 0);
        $talla = (float) ($this->evaluacion->valor('talla') ?? 0);

        if ($peso <= 0 || $talla <= 0) {
            return '—';
        }

        return number_format($peso / ($talla ** 2), 2, ',', '.');
    }

    /**
     * El resumen narrativo del **examen general** tal como lo dejó el escritorio (`total_f`), o `null`.
     *
     * Se lee del dato crudo —no de `columnas()`— porque no es una columna que la web escriba: el
     * escritorio lo arma con su botón *Actualizar* (`w_consulta_principal.srw:8960-9010`) y lo guarda en
     * cada consulta que abre. La web lo muestra y no lo regenera.
     */
    public function resumenGeneral(): ?string
    {
        return $this->evaluacion->delExamenCrudo('total_f');
    }

    /**
     * El resumen narrativo del **examen ginecológico** (`total_g`), o `null`.
     *
     * Ojo: el del escritorio incluye **colposcopia y citología** (`w_consulta_principal.srw:9898-9902`),
     * que son de F6, así que se muestra lo que hay y no se recalcula desde los cuatro campos que la web
     * edita (si no, el resumen perdería esa parte).
     */
    public function resumenGinecologico(): ?string
    {
        return $this->evaluacion->delExamenCrudo('total_g');
    }

    /** ¿El escritorio dejó algún resumen narrativo para mostrar? */
    public function hayResumenes(): bool
    {
        return $this->resumenGeneral() !== null || $this->resumenGinecologico() !== null;
    }

    /**
     * Cuántos de los campos que la web edita tienen dato: es lo que la pastilla del encabezado muestra
     * ("9 de 20 cargados"). Cuenta lo **guardado**, no lo que hay en el formulario: el aviso de cambios
     * sin guardar es otra cosa.
     */
    public function cargados(): int
    {
        $total = 0;
        foreach (array_keys(EvaluacionFisica::columnas()) as $columna) {
            if ($this->evaluacion->valor($columna) !== null) {
                $total++;
            }
        }

        return $total;
    }

    /** Cuántos campos edita la web: el denominador de la pastilla del encabezado. */
    public function totalCampos(): int
    {
        return count(EvaluacionFisica::columnas());
    }

    /**
     * El estado de la sección como pastilla: "Sin cargar" mientras no haya ningún dato y "Cargada" cuando
     * ya hay algo. No dice "guardado" (de eso se encarga el aviso de cambios sin guardar).
     */
    public function estado(): array
    {
        return $this->cargados() === 0
            ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay nada cargado en la evaluación física de esta consulta']
            : ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargada',
                'titulo' => 'La evaluación física de esta consulta ya tiene datos'];
    }

    /** La ayuda del formulario: qué es la sección y qué pasa al salir sin guardar. */
    public function ayuda(): string
    {
        return 'El examen físico general y el ginecológico de la consulta. Se guardan con el botón: si '
            . 'sales sin guardar, el navegador te avisa antes de perder lo escrito.';
    }

    /**
     * El aviso que `consulta.js` muestra y usa para el `beforeunload`.
     */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en la evaluación física. Si sales ahora, se pierden.';
    }

    /**
     * Las filas del formulario: el rótulo, la columna (que es el `name` del control) y el valor que se
     * muestra —el `old()` si se acaba de escribir, si no lo guardado—.
     *
     * @param  array<int,array{columna:string,etiqueta:string,unidad:?string,ayuda:string,tipo:string}>  $campos
     * @return array<int,array<string,mixed>>
     */
    private function filas(array $campos): array
    {
        return array_map(function (array $campo) {
            return [
                'columna' => $campo['columna'],
                'etiqueta' => $campo['etiqueta'],
                'unidad' => $campo['unidad'],
                'ayuda' => $campo['ayuda'],
                'tipo' => $campo['tipo'],
                'valor' => $this->valor($campo['columna']),
            ];
        }, $campos);
    }

    /** Lo escrito en el POST que falló, o lo que hay guardado. */
    private function valor(string $columna): string
    {
        if (array_key_exists($columna, $this->old) && $this->old[$columna] !== null) {
            return (string) $this->old[$columna];
        }

        return (string) ($this->evaluacion->valor($columna) ?? '');
    }
}
