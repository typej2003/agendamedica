<?php

namespace App\Clinica\Listados;

use App\Clinica\Pacientes\Vistas\FormularioDePacienteVista;
use Carbon\CarbonInterface;

/**
 * **Los filtros del listado de pacientes por rango** (WEB-6.8), ya normalizados, con la semántica del
 * escritorio.
 *
 * El reporte del legado es `w_reprotes_pacientes.srw` («Indicadores pacientes»): **una sola tabla**
 * (`pacientes`, sin `JOIN`) con **ocho DataWindows** elegidos por combinación de filtros. La web resuelve esa
 * combinatoria con los filtros de una sola pantalla, que es lo que esta clase normaliza:
 *
 * | Filtro | Cómo lo hace el legado |
 * |---|---|
 * | **Entre edad** | `(today − pacientes.fnacimiento)/365 between :fecha_naci_1 and :fecha_naci_2` (`d_reporte-pacientes_todos_empre.srd:22`), con los campos `em_5`/`em_6` |
 * | **Fecha de ingreso Desde / Hasta** | `pacientes.fingreso between :fecha_ingre_1 and :fecha_ingre_2`, con `em_3` = `01/01/2000` y `em_4` = `today()` (`w_reprotes_pacientes.srw:276-277`) |
 * | **Sexo** | `pacientes.sexo = :sexo` (`d_reporte-pacientes_sexo_todos_todos.srd`), con los `M`/`F` del legado (`values="Masculino M/Femenino F/"`) |
 * | **Estado civil** | el legado compara `pacientes.codesegemp = :estado` — ver la desviación a conciencia abajo |
 * | **Procedencia** | `Todos` (sin filtro), `Personal` (`codesegemp = '00'`) o una empresa elegida del catálogo (`codesegemp = :empresa`), `rb_9`/`rb_7`/`rb_8` |
 * | **Orden** | `sort="numhistoria A codesegemp A compute_2 A"`: número de historia ascendente |
 *
 * ⚠️ **Desviación a conciencia — el estado civil.** Dos de los ocho DataWindows del escritorio
 * (`d_reporte-pacientes_todos_empre_empre.srd:22` y `d_reporte-pacientes_todos_alguno_empre.srd:22`) comparan
 * el parámetro del estado civil contra **`pacientes.codesegemp`** (la empresa) en vez de
 * **`pacientes.codeestado`**. Es una contradicción del legado consigo mismo: el rótulo del filtro dice
 * "Estado civil" (`w_reprotes_pacientes.srw:1039`) y el valor que le llega es `S`/`C`/`D`/`V`
 * (`:874`, `:896`, `:921`, `:949`), que son los códigos de `codeestado`, no los de una empresa. La web usa
 * **`pacientes.codeestado`**, que es lo correcto y lo que el nombre promete. La línea del legado queda
 * anotada acá para que nadie la "arregle" copiándola.
 *
 * **La edad se traduce a fechas de nacimiento** con `RangoDeEdades` (y éste con
 * `RangoDeFechas::nacimientosDeLasEdades`): la misma cuenta de años cumplidos que usan el listado de pacientes
 * y la columna "Edad" de este reporte —el escritorio divide los días vividos por 365—, para que el filtro y lo
 * que se lee no se contradigan.
 *
 * **El rango al revés no bloquea**: se da vuelta y el ViewModel lo avisa (el escritorio no lo valida).
 * Un sexo, un estado civil o una procedencia que no se entienden vuelven a "todos": mostrar la lista entera es
 * menos malo que mostrar una pantalla a medias, y es la misma vara de `FiltroDePacientes::normalizar`.
 */
final class FiltroDePacientesPorRango
{
    /** El valor de `?procedencia=` que significa "sin filtrar". */
    public const PROCEDENCIA_TODOS = 'todos';

    /** El valor de `?procedencia=` de los pacientes que se atienden por su cuenta (`codesegemp = '00'`). */
    public const PROCEDENCIA_PERSONAL = 'personal';

    /** El código de PERSONAL en el catálogo del legado. El SQL filtra por `00`, no por `personal`. */
    private const CODIGO_PERSONAL = '00';

    private function __construct(
        private RangoDeFechas $ingreso,
        private RangoDeEdades $edades,
        private CarbonInterface $hoy,
        private ?string $sexo,
        private ?string $estadoCivil,
        private ?string $procedencia,
        private bool $hayFiltro,
    ) {
    }

    /**
     * Los filtros de una petición, normalizados. `$hoy` es el día del consultorio (`FechaDelConsultorio`): de
     * él salen la fecha de ingreso final por defecto y el rango de nacimiento de las edades.
     *
     * @param  array<string,mixed>  $entrada  el `?desde=…&hasta=…&edad_inicial=…&sexo=…` de la pantalla
     */
    public static function de(array $entrada, CarbonInterface $hoy): self
    {
        // El rango es el de `pacientes.fingreso`, con los defaults del escritorio: 01/01/2000 y hoy
        // (los mismos que `RangoDeFechas` ya usa para el listado de embarazos).
        $ingreso = RangoDeFechas::de(
            self::texto($entrada['desde'] ?? null),
            self::texto($entrada['hasta'] ?? null),
            $hoy,
        );

        $edades = RangoDeEdades::de($entrada['edad_inicial'] ?? null, $entrada['edad_final'] ?? null);
        $sexo = self::sexoValido($entrada['sexo'] ?? null);
        $estadoCivil = self::estadoCivilValido($entrada['estado_civil'] ?? null);
        $procedencia = self::procedenciaValida($entrada['procedencia'] ?? null);

        // "Limpiar" se ofrece sólo si algo se movió de los valores con los que nace la pantalla: el formulario
        // manda todos los campos siempre, así que la presencia de un parámetro no dice nada.
        $porDefecto = RangoDeFechas::de(null, null, $hoy);
        $hayFiltro = $ingreso->desde() !== $porDefecto->desde()
            || $ingreso->hasta() !== $porDefecto->hasta()
            || ! $edades->sonLasDePorDefecto()
            || $sexo !== null
            || $estadoCivil !== null
            || $procedencia !== null;

        return new self($ingreso, $edades, $hoy, $sexo, $estadoCivil, $procedencia, $hayFiltro);
    }

    /** El rango de **fecha de ingreso** (`pacientes.fingreso`), los dos extremos incluidos. */
    public function rangoDeIngreso(): RangoDeFechas
    {
        return $this->ingreso;
    }

    /** El rango de **fechas de nacimiento** que corresponde a las edades pedidas. */
    public function rangoDeNacimiento(): RangoDeFechas
    {
        return $this->edades->rangoDeNacimiento($this->hoy);
    }

    public function edadInicial(): int
    {
        return $this->edades->inicial();
    }

    public function edadFinal(): int
    {
        return $this->edades->final();
    }

    /** El sexo pedido (`M`/`F`), o `null` si no hay ninguno válido puesto. */
    public function sexo(): ?string
    {
        return $this->sexo;
    }

    /** El estado civil pedido (`S`/`C`/`D`/`V`), o `null`. */
    public function estadoCivil(): ?string
    {
        return $this->estadoCivil;
    }

    /** La procedencia pedida: `null` (todas), `personal` o el código de una empresa. */
    public function procedencia(): ?string
    {
        return $this->procedencia;
    }

    /**
     * El valor con el que se compara `pacientes.codesegemp`, o `null` si no hay que filtrar: `Personal` es el
     * código `00` del catálogo del legado, y una empresa es su propio código.
     */
    public function codigoDeProcedencia(): ?string
    {
        if ($this->procedencia === null) {
            return null;
        }

        return $this->procedencia === self::PROCEDENCIA_PERSONAL ? self::CODIGO_PERSONAL : $this->procedencia;
    }

    public function seVoltearonLasFechas(): bool
    {
        return $this->ingreso->seVolteo();
    }

    public function seVoltearonLasEdades(): bool
    {
        return $this->edades->seVoltearon();
    }

    /** ¿Hay algún filtro puesto (dejó de ser el que trae la pantalla)? Lo usa "Limpiar". */
    public function hayFiltro(): bool
    {
        return $this->hayFiltro;
    }

    /** El sexo pedido, si es uno de los del legado; `null` en cualquier otro caso. */
    private static function sexoValido($valor): ?string
    {
        $texto = strtoupper((string) self::texto($valor));

        return array_key_exists($texto, FormularioDePacienteVista::SEXOS) ? $texto : null;
    }

    /**
     * El estado civil pedido, si es uno de los del legado; `null` en cualquier otro caso.
     *
     * El catálogo de estados civiles de la web es el del alta de la ficha (`FormularioDePacienteVista::
     * ESTADOS_CIVILES`, con los mismos códigos del escritorio): **no hay una tabla de estados civiles** que
     * se pueda leer del consultorio, así que se reusa esa lista y un código que no esté en ella no filtra.
     */
    private static function estadoCivilValido($valor): ?string
    {
        $texto = strtoupper((string) self::texto($valor));

        return array_key_exists($texto, FormularioDePacienteVista::ESTADOS_CIVILES) ? $texto : null;
    }

    /**
     * La procedencia pedida: `todos` y el vacío no filtran, `personal` es el `00`, y cualquier otra cosa se
     * toma como el **código** de una empresa del catálogo (el `<select>` manda el código).
     *
     * Un código que no esté en el catálogo del consultorio no se rechaza: filtra por él y no trae filas. El
     * legado tampoco lo validaba, y el catálogo lo lee la pantalla, no este filtro (que no toca la base).
     */
    private static function procedenciaValida($valor): ?string
    {
        $texto = self::texto($valor);

        if ($texto === null || $texto === self::PROCEDENCIA_TODOS) {
            return null;
        }

        return $texto === self::PROCEDENCIA_PERSONAL ? self::PROCEDENCIA_PERSONAL : $texto;
    }

    private static function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
