<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\ExamenFisico;

/**
 * **La evaluación física de una consulta** (WEB-4.3, ítem 4 del panel del legado): la lee de las **dos**
 * tablas donde la guarda el escritorio y arma el mapa de campos que pinta la pantalla.
 *
 * El reparto es del esquema del legado, no una decisión de la web:
 *
 * | Parte | Qué es | Dónde vive | Fuente |
 * |---|---|---|---|
 * | Antropometría y signos | peso, talla, FC, FR, circunferencias cefálica/toráxica/abdominal | `consultas` | `w_consulta_principal.srw:2747-2752` y `:2819-2827` |
 * | Examen por aparatos | general, cardiopulmonar, tiroides, mamas (rótulo *derecha*), mama (`mama_derecha`, rótulo *izquierda*), abdomen, extremidades, neurológico | `examen_fisico_nuevo` | `w_consulta_principal.srw:2772-2791` |
 * | Examen ginecológico | genitales externos, espéculos, tacto vaginal, tacto rectal | `examen_fisico_nuevo` | `w_consulta_principal.srw:2778-2781` |
 * | Resúmenes narrativos | `total_f` (general) y `total_g` (ginecológico) | `examen_fisico_nuevo` | botón *Actualizar*: `w_consulta_principal.srw:8960-9010` y `:9882-9907` |
 *
 * Lo que **no** entra (y por qué) está en el encabezado de `EvaluacionFisica`: la colposcopia y la
 * citología son de F6, y `examen_fisico` (la tabla vieja) no la usa nadie.
 *
 * El catálogo de campos (`general()`, `ginecologico()`, `antropometria()`) vive acá y no en el
 * ViewModel porque es **el mapa del dato** —qué columna, con qué rótulo y de qué tabla—, y el ViewModel
 * es presentación. Así el mismo mapa sirve para la pantalla y para el reporte de paridad.
 */
final class ExamenFisicoDeLaConsulta
{
    /**
     * La evaluación física de una consulta, ya leída.
     *
     * La fila del examen sale de `filaDe()`, que es lo que también usa la Action para escribir: **la
     * misma clave** para leer y para guardar, así que la pantalla no puede estar mostrando una fila y
     * guardando en otra.
     */
    public function de(Consulta $consulta): EvaluacionFisica
    {
        $examen = $this->filaDe($consulta);

        return new EvaluacionFisica(
            [
                'peso' => $consulta->peso,
                'talla' => $consulta->talla,
                'fc' => $consulta->fc,
                'pp' => $consulta->pp,
                'circcefalica' => $consulta->circcefalica,
                'circtoraxica' => $consulta->circtoraxica,
                'circabdominal' => $consulta->circabdominal,
                'tension' => $examen?->tension,
            ],
            // Todo lo que el examen tiene (incluida `colposcopia`, que es de F6 y sólo se muestra, y los
            // resúmenes): el objeto de valores sabe qué columnas se editan, pero también deja leer las
            // que no (`delExamenCrudo`).
            $examen === null ? [] : $examen->getAttributes(),
        );
    }

    /**
     * La fila de `examen_fisico_nuevo` de una consulta, o `null`.
     *
     * ⚠️ **`examen_fisico_nuevo.consulta` no es el id de la consulta: es su número dentro de la historia**
     * (`consultas.nroconsulta`). Lo dice el esquema real del legado y lo confirma la base: de las 19.900
     * filas de un médico de prueba, **19.899** cruzan por `(historia, consulta) = (numhistoria,
     * nroconsulta)` y **ninguna** por `(historia, consulta) = (numhistoria, id)`. La clave del escritorio
     * es `(historia, consulta)` del DataWindow (`dbname="examen_fisico_nuevo.historia"` /
     * `.consulta` en `d_exa_fi_fisico.srd`), y `historia` es el número de historia y `consulta` el de la
     * consulta: la fila la crea con `(Historia, Consulta)` al abrirla (`w_consulta_principal.srw:5020`) y
     * el `UPDATE` la vuelve a buscar con esos dos (`:2790`).
     *
     * Buscar por el **id** no encuentra la fila del escritorio y crea una nueva al lado: es el error que
     * se midió al verificar WEB-4.3 (la fila quedaba huérfana y con los campos vacíos).
     */
    public function filaDe(Consulta $consulta): ?ExamenFisico
    {
        return ExamenFisico::where('reg_medico', $consulta->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * La fila del examen para escribir sobre ella: la que ya existe o una nueva **sin guardar**. Es un
     * `firstOrNew` con la clave explícita, para que el `reg_medico`/`historia`/`consulta` queden puestos
     * aunque la fila se cree recién en el `save()`.
     */
    public function filaParaEscribir(Consulta $consulta): ExamenFisico
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new ExamenFisico([
            'reg_medico' => $consulta->reg_medico,
            'historia' => (int) $consulta->numhistoria,
            'consulta' => (int) $consulta->nroconsulta,
        ]);
    }

    /**
     * La **antropometría**, los signos y la tensión, con su rótulo de pantalla y su unidad.
     *
     * Los rótulos salen de la solapa "Signos" del escritorio (`w_consulta_principal.srw:23534`
     * *Frecuencia cardiaca x min*, `:23496` *Frecuencia respiratoria*, `:23305`/`:23343`/`:23381`
     * *Abdominal* / *Toráxica* / *Cefálica*), y la tensión y la cadera de la solapa del examen
     * (`:9030` y `:8819`). La columna se llama distinto que el rótulo en las tres circunferencias: el
     * nombre es el del esquema del legado y no se renombra.
     *
     * `tipo` dice con qué control se pinta: `numero` es un `<input type="number">` (una columna `double`
     * de `consultas` o el entero del examen) y `texto` un `<input type="text">` (el `char(8)` de
     * `tension`, con su máscara `###/###`).
     *
     * @return array<int,array{columna:string,etiqueta:string,unidad:?string,ayuda:string,tipo:string}>
     */
    public static function antropometria(): array
    {
        return [
            ['columna' => 'peso', 'etiqueta' => 'Peso', 'unidad' => 'kg', 'tipo' => 'numero',
                'ayuda' => 'El peso de hoy.'],
            ['columna' => 'talla', 'etiqueta' => 'Talla', 'unidad' => 'm', 'tipo' => 'numero',
                'ayuda' => 'En metros (1,62), como en el escritorio.'],
            ['columna' => 'tension', 'etiqueta' => 'Tensión arterial', 'unidad' => 'mmHg', 'tipo' => 'texto',
                'ayuda' => 'Sistólica/diastólica (120/80).'],
            ['columna' => 'fc', 'etiqueta' => 'Frecuencia cardiaca', 'unidad' => 'x min', 'tipo' => 'numero',
                'ayuda' => 'Latidos por minuto.'],
            ['columna' => 'pp', 'etiqueta' => 'Frecuencia respiratoria', 'unidad' => 'x min', 'tipo' => 'numero',
                'ayuda' => 'Respiraciones por minuto.'],
            ['columna' => 'circabdominal', 'etiqueta' => 'Circunferencia abdominal', 'unidad' => 'cm', 'tipo' => 'numero',
                'ayuda' => 'En la columna `circabdominal` (solapa *Abdominal* del escritorio).'],
            ['columna' => 'circtoraxica', 'etiqueta' => 'Circunferencia toráxica', 'unidad' => 'cm', 'tipo' => 'numero',
                'ayuda' => 'En la columna `circtoraxica` (solapa *Toráxica* del escritorio).'],
            ['columna' => 'circcefalica', 'etiqueta' => 'Circunferencia cefálica', 'unidad' => 'cm', 'tipo' => 'numero',
                'ayuda' => 'En la columna `circcefalica` (solapa *Cefálica* del escritorio).'],
        ];
    }

    /**
     * El **examen por aparatos**: los ocho hallazgos de la solapa "Examen Físico" del escritorio.
     *
     * ⚠️ **Los rótulos de las mamas están cambiados respecto de las columnas**, y se conserva el rótulo
     * del escritorio para que el médico lea lo mismo: el campo rotulado *Mama derecha* guarda en
     * `mamas` y el rotulado *Mama izquierda* en `mama_derecha`
     * (`w_consulta_principal.srw:9345-9355` y `:9327-9337`, contra el `UPDATE` de `:2773` y `:2785`).
     * La web **no** intercambia las columnas: eso duplicaría o perdería datos del escritorio.
     *
     * @return array<int,array{columna:string,etiqueta:string,unidad:?string,ayuda:string,tipo:string}>
     */
    public static function general(): array
    {
        return [
            ['columna' => 'general', 'etiqueta' => 'General', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Estado general de la paciente.'],
            ['columna' => 'cardiopulmonar', 'etiqueta' => 'Cardiopulmonar', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Ruidos cardiacos y murmullo vesicular.'],
            ['columna' => 'tiroides', 'etiqueta' => 'Tiroides', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Tamaño, nódulos, dolor.'],
            ['columna' => 'mamas', 'etiqueta' => 'Mama derecha', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'El rótulo del escritorio. Se guarda en `mamas`.'],
            ['columna' => 'mama_derecha', 'etiqueta' => 'Mama izquierda', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'El rótulo del escritorio. Se guarda en `mama_derecha`.'],
            ['columna' => 'abdomen', 'etiqueta' => 'Abdomen', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Blando, depresible, doloroso…'],
            ['columna' => 'extremidades', 'etiqueta' => 'Extremidades', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Edemas, várices, pulsos.'],
            ['columna' => 'neurologico', 'etiqueta' => 'Neurológico', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Consciente, orientada, reflejos.'],
        ];
    }

    /**
     * El **examen ginecológico**: los cuatro hallazgos de la solapa homónima del escritorio
     * (`w_consulta_principal.srw:2778-2781`, rótulos en `:9885-9896`).
     *
     * La **colposcopia** y la **citología** no están acá a propósito: viven en la misma tabla y en la
     * misma solapa, pero tienen informe propio en el legado y son de **F6** (TODO-WEB.md WEB-4.3,
     * PLAN-WEB.md §3.16).
     *
     * @return array<int,array{columna:string,etiqueta:string,unidad:?string,ayuda:string,tipo:string}>
     */
    public static function ginecologico(): array
    {
        return [
            ['columna' => 'genitales_externos', 'etiqueta' => 'Genitales externos', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Vulva, periné, lesiones.'],
            ['columna' => 'especulos', 'etiqueta' => 'Espéculos', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Vagina y cérvix a la visión con espéculo.'],
            ['columna' => 'tacto_vaginal', 'etiqueta' => 'Tacto vaginal', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Útero, anexos, fondo de saco.'],
            ['columna' => 'tacto_rectal', 'etiqueta' => 'Tacto rectal', 'unidad' => null, 'tipo' => 'area',
                'ayuda' => 'Sólo si se practicó.'],
        ];
    }
}
