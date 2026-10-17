<?php

namespace App\Clinica\Consulta;

/**
 * **Los valores de la evaluación física de una consulta** (WEB-4.3), ya leídos de las dos tablas.
 *
 * Es un objeto de valores sin estado y sin base de datos: la lectura la hace
 * `ExamenFisicoDeLaConsulta` y esto es lo que viaja al ViewModel y a la Action. Existe por una razón
 * concreta: la evaluación física es la única sección de la consulta cuyos datos viven **partidos en dos
 * tablas** —la antropometría en `consultas`, los hallazgos por aparatos en `examen_fisico_nuevo`—, así
 * que si no hubiera un solo objeto, esa partición se repetiría en cada capa.
 *
 * Las dos listas de columnas de abajo son **la definición de qué edita la web**, y se comparten entre el
 * ViewModel (para armar el formulario) y la Action (para validar y escribir), así que no pueden divergir.
 */
final class EvaluacionFisica
{
    /**
     * Las columnas que viven en `consultas`: la **antropometría** y los signos.
     *
     * `peso`, `talla`, `fc` y `pp` se llenan en la solapa "Signos" del escritorio
     * (`w_consulta_principal.srw:2747-2748` y `:2819-2822`); `fc` es la **frecuencia cardiaca** y `pp` la
     * **frecuencia respiratoria**. Las tres circunferencias salen de los campos rotulados *Cefálica*,
     * *Toráxica* y *Abdominal* de esa solapa (`:2752`, `:2823-2824`): el nombre de la columna es el del
     * esquema del legado, no el rótulo de la pantalla.
     */
    public const COLUMNAS_CONSULTA = [
        'peso', 'talla', 'fc', 'pp', 'circcefalica', 'circtoraxica', 'circabdominal',
    ];

    /**
     * Las columnas que viven en `examen_fisico_nuevo`: el **examen por aparatos** y el **ginecológico**.
     *
     * Las escribe el `UPDATE "examen_fisico_nuevo"` del guardado del escritorio
     * (`w_consulta_principal.srw:2772-2791`). Quedan afuera, a propósito:
     *
     *  - **`colposcopia`** — es la colposcopia, que en el legado tiene informe propio y es de **F6**
     *    (PLAN-WEB.md §3.11/§3.16); el escritorio la guarda en esta misma tabla (`:2786`) pero la web no
     *    la edita acá.
     *  - **`total_f`, `total_g` y `total_general`** — los resúmenes narrativos que arma el botón
     *    *Actualizar* del escritorio. La web los muestra y no los regenera (ver
     *    `App\Actions\Consulta\GuardarEvaluacionFisica`).
     *
     *  - **`mama_derecha`** se incluye aunque en los datos reales esté casi siempre vacía: es una columna
     *    del examen que el escritorio **sí** lee y escribe (aunque con el rótulo cambiado: la columna
     *    `mama_derecha` recibe el texto del campo rotulado *Mama izquierda*, y la columna `mamas` el de
     *    *Mama derecha*; `w_consulta_principal.srw:9345`/`:9355` contra `:9327`/`:9337` y el `UPDATE`
     *    `:2773`/`:2785`).
     */
    public const COLUMNAS_EXAMEN = [
        'general', 'cardiopulmonar', 'tiroides', 'mamas', 'mama_derecha',
        'abdomen', 'extremidades', 'neurologico',
        'genitales_externos', 'especulos', 'tacto_vaginal', 'tacto_rectal',
    ];

    /**
     * Los dos resúmenes narrativos que arma el botón *Actualizar* (`ArmarResumenDelExamen`): desde WEB-11.8 la web los **edita** y los
     * guarda (con el médico decidiendo cuándo pisar lo editado). No cuentan como «campos con dato» de la evaluación.
     */
    public const COLUMNAS_RESUMEN = ['total_f', 'total_g'];

    /** Las cuatro columnas del examen ginecológico: lo que el escritorio resume en `total_g`. */
    public const COLUMNAS_GINECOLOGICAS = [
        'genitales_externos', 'especulos', 'tacto_vaginal', 'tacto_rectal',
    ];

    /**
     * La **tensión arterial**, que no es una columna del examen por aparatos: el escritorio la guarda en
     * `examen_fisico_nuevo.tension` (texto con la máscara `###/###`, `w_consulta_principal.srw:9045` y el
     * `UPDATE` de `:2782`). Los signos de `consultas` (`tasentado`/`taacostado`/`tapie`, la tensión en
     * tres posiciones) quedan afuera: la que la evaluación muestra es la del examen.
     */
    public const COLUMNA_TENSION = 'tension';

    /**
     * @param  array<string,mixed>  $consulta  las columnas de `consultas` (clave => valor)
     * @param  array<string,mixed>  $examen  las columnas de `examen_fisico_nuevo` (clave => valor)
     */
    public function __construct(
        private array $consulta = [],
        private array $examen = [],
    ) {
    }

    /** Todas las columnas que la web edita, con la tabla a la que pertenecen. */
    public static function columnas(): array
    {
        $mapa = [];
        foreach (self::COLUMNAS_CONSULTA as $columna) {
            $mapa[$columna] = 'consultas';
        }
        foreach (array_merge(self::COLUMNAS_EXAMEN, [self::COLUMNA_TENSION], self::COLUMNAS_RESUMEN) as $columna) {
            $mapa[$columna] = 'examen_fisico_nuevo';
        }

        return $mapa;
    }

    /** El valor de una columna, mire en la tabla que mire. `null` si no está. */
    public function valor(string $columna): ?string
    {
        return $this->limpiar($this->consulta[$columna] ?? $this->examen[$columna] ?? null);
    }

    /**
     * Una columna de `examen_fisico_nuevo` que la web **no** edita pero la pantalla muestra: hoy los tres
     * resúmenes narrativos del escritorio (`total_f`, `total_g`, `total_general`).
     *
     * Existe para que esos textos se lean sin meterlos en `columnas()`: lo que está en `columnas()` es lo
     * que la web escribe, y los resúmenes se muestran y no se tocan (ver `GuardarEvaluacionFisica`).
     */
    public function delExamenCrudo(string $columna): ?string
    {
        return $this->limpiar($this->examen[$columna] ?? null);
    }

    private function limpiar($crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        $texto = trim((string) $crudo);

        // El legado guarda tres formas de "no hay nada": `NULL`, la cadena vacía y el literal `none`
        // que el escritorio escribió en `total_general` (23.971 filas de la base real). Las tres se
        // tratan igual —sin dato— para no mostrar `none` como si fuera un hallazgo.
        return ($texto === '' || strcasecmp($texto, 'none') === 0) ? null : $texto;
    }

    /** Los valores de `consultas`, ya normalizados. */
    public function deLaConsulta(): array
    {
        return $this->normalizar(self::COLUMNAS_CONSULTA);
    }

    /** Los valores de `examen_fisico_nuevo`, ya normalizados. */
    public function delExamen(): array
    {
        return $this->normalizar(array_merge(self::COLUMNAS_EXAMEN, [self::COLUMNA_TENSION]));
    }

    /** @return array<string,?string> */
    private function normalizar(array $columnas): array
    {
        $salida = [];
        foreach ($columnas as $columna) {
            $salida[$columna] = $this->valor($columna);
        }

        return $salida;
    }
}
