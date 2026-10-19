<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\ExamenPacienteConsulta;

/**
 * **Indicadores incidentes**: el panel de **nefrología pediátrica** (2026-10-19), ítem 5 del árbol de PediaReport
 * (`w_consulta_principal.srw:2656`, pestaña `tabpage_35`).
 *
 * Lo que hace el escritorio, medido en el fuente:
 *
 *  - el médico teclea los valores en campos sueltos (`em_urea`, `em_1_cr`, …) y al guardar la consulta se
 *    escriben **en la tabla `examenes_paciente_consulta`**, una fila por consulta con clave `hhistoria` +
 *    `hconsulta` (`UPDATE`/`INSERT`, `:2924-2960` y `:3554`), y se vuelven a leer al abrir la consulta
 *    (`dw_76.retrieve`, `:25651`);
 *  - además, el botón *Actualizar* **compone un texto** con `ETIQUETA: valor.` por cada campo cargado
 *    (`ue_guard_exam`, `:25090-25204`) y ese texto va a **`consultas.resultadoexamencomp`** (`:2821`, `:2850`),
 *    que es lo que imprimen el resumen médico y la evaluación pre-operatoria.
 *
 * ⚠️ Las dos cosas son distintas: **los números viven en su tabla** y **el texto** en la consulta. La web escribe
 * las dos, como el escritorio.
 *
 * Los gases (`g_v_ph`, `g_v_pco2`, `g_v_hco3`, `g_v_eb`), el volumen urinario, el sodio urinario y la creatinina
 * «calculada» **se guardan pero no entran en el texto**: en el fuente las líneas que los agregaban están
 * comentadas (`:25191-25200`). Por eso se editan igual, pero no aparecen en el resumen.
 *
 * Sin estado: el mapa del dato, el formateador del texto y la lectura de la fila.
 */
final class IndicadoresIncidentes
{
    /** La columna de `consultas` donde el escritorio deja el resumen compuesto. */
    public const COLUMNA_TEXTO = 'resultadoexamencomp';

    /** La tabla de los números (una fila por consulta). */
    public const TABLA = 'examenes_paciente_consulta';

    /**
     * Los campos del panel, en el orden del escritorio: `clave => [etiqueta, grupo, columna, enTexto]`.
     *
     * `enTexto` dice si el campo entra en el resumen compuesto (`EXA_RESU`, `:25203`). El orden **de este arreglo**
     * es el del resumen.
     *
     * @var array<string,array{etiqueta:string, grupo:string, columna:string, enTexto:bool}>
     */
    private const CAMPOS = [
        // Sangre
        'urea'            => ['etiqueta' => 'UREA', 'grupo' => 'Sangre', 'columna' => 'urea', 'enTexto' => true],
        'creatinina'      => ['etiqueta' => 'Cr s', 'grupo' => 'Sangre', 'columna' => 'cr', 'enTexto' => true],
        'sodio'           => ['etiqueta' => 'Na', 'grupo' => 'Sangre', 'columna' => 'sodio', 'enTexto' => true],
        'cloro'           => ['etiqueta' => 'Cl', 'grupo' => 'Sangre', 'columna' => 'cloro', 'enTexto' => true],
        'potasio'         => ['etiqueta' => 'K', 'grupo' => 'Sangre', 'columna' => 'potasio', 'enTexto' => true],
        'calcio'          => ['etiqueta' => 'Ca', 'grupo' => 'Sangre', 'columna' => 'calcio', 'enTexto' => true],
        'fosforo'         => ['etiqueta' => 'P', 'grupo' => 'Sangre', 'columna' => 'fosforo', 'enTexto' => true],
        'magnesio'        => ['etiqueta' => 'MAGNESIO', 'grupo' => 'Sangre', 'columna' => 'magnesio', 'enTexto' => true],
        'paratohormona'   => ['etiqueta' => 'PARATOHORMONA', 'grupo' => 'Sangre', 'columna' => 'paratohormona', 'enTexto' => true],
        'ferritina'       => ['etiqueta' => 'FERRITINA', 'grupo' => 'Sangre', 'columna' => 'ferritina', 'enTexto' => true],
        'proteinuria'     => ['etiqueta' => 'PROTEINURIA', 'grupo' => 'Sangre', 'columna' => 'proteinuria', 'enTexto' => true],
        'transferrina'    => ['etiqueta' => 'TRANSFERRINA', 'grupo' => 'Sangre', 'columna' => 'trasferrina', 'enTexto' => true],
        'depuracion'      => ['etiqueta' => 'DEPURACIÓN DE CREATININA', 'grupo' => 'Sangre', 'columna' => 'depuracion_creatinina', 'enTexto' => true],
        'hemoglobina'     => ['etiqueta' => 'HEMOGLOBINA', 'grupo' => 'Sangre', 'columna' => 'hb', 'enTexto' => true],
        'hematocrito'     => ['etiqueta' => 'HEMATOCRITOS', 'grupo' => 'Sangre', 'columna' => 'hto', 'enTexto' => true],
        'hemoglobina_gli' => ['etiqueta' => 'HEMOGLOBINA GLICOSILADA', 'grupo' => 'Sangre', 'columna' => 'hb_glicosilada', 'enTexto' => true],
        'creatinina_s'    => ['etiqueta' => 'Cr s (calculada)', 'grupo' => 'Sangre', 'columna' => 'creatinina_s', 'enTexto' => false],
        'acido_urico'     => ['etiqueta' => 'Ac U', 'grupo' => 'Sangre', 'columna' => 'ac_urico_serico', 'enTexto' => true],
        // Orina
        'acido_urico_u'   => ['etiqueta' => 'Ac Uu', 'grupo' => 'Orina', 'columna' => 'ac_urico_u', 'enTexto' => true],
        'creatinina_u'    => ['etiqueta' => 'Cr u', 'grupo' => 'Orina', 'columna' => 'creatinina_u', 'enTexto' => true],
        'creatinina_u24'  => ['etiqueta' => 'Cr u 24h', 'grupo' => 'Orina', 'columna' => 'creatinina_u_24', 'enTexto' => true],
        'potasio_u'       => ['etiqueta' => 'K u', 'grupo' => 'Orina', 'columna' => 'potasio_u', 'enTexto' => true],
        'cloro_u'         => ['etiqueta' => 'Cl u', 'grupo' => 'Orina', 'columna' => 'cloro_u', 'enTexto' => true],
        'fosforo_u'       => ['etiqueta' => 'P u', 'grupo' => 'Orina', 'columna' => 'fosforo_u', 'enTexto' => true],
        'magnesio_u'      => ['etiqueta' => 'MAGNESIO u', 'grupo' => 'Orina', 'columna' => 'magnesio_u', 'enTexto' => true],
        'albumina'        => ['etiqueta' => 'ALBUMINA', 'grupo' => 'Orina', 'columna' => 'albumina_u', 'enTexto' => true],
        'calcio_u'        => ['etiqueta' => 'Ca u', 'grupo' => 'Orina', 'columna' => 'calcio_u', 'enTexto' => true],
        'sodio_u'         => ['etiqueta' => 'Na u', 'grupo' => 'Orina', 'columna' => 'sodio_u', 'enTexto' => false],
        'volumen'         => ['etiqueta' => 'Volumen urinario', 'grupo' => 'Orina', 'columna' => 'v_urina', 'enTexto' => false],
        // Gases (se guardan, no entran en el texto)
        'ph'              => ['etiqueta' => 'pH', 'grupo' => 'Gases', 'columna' => 'g_v_ph', 'enTexto' => false],
        'pco2'            => ['etiqueta' => 'PCO₂', 'grupo' => 'Gases', 'columna' => 'g_v_pco2', 'enTexto' => false],
        'hco3'            => ['etiqueta' => 'HCO₃', 'grupo' => 'Gases', 'columna' => 'g_v_hco3', 'enTexto' => false],
        'exceso_base'     => ['etiqueta' => 'Exceso de base', 'grupo' => 'Gases', 'columna' => 'g_v_eb', 'enTexto' => false],
    ];

    /** @return array<string,array{etiqueta:string, grupo:string, columna:string, enTexto:bool}> */
    public static function campos(): array
    {
        return self::CAMPOS;
    }

    /** @return array<int,string> */
    public static function claves(): array
    {
        return array_keys(self::CAMPOS);
    }

    /** Las columnas de la tabla que escribe el panel. @return array<int,string> */
    public static function columnas(): array
    {
        return array_values(array_unique(array_column(self::CAMPOS, 'columna')));
    }

    /**
     * Los grupos del formulario, en orden: `grupo => [clave => etiqueta]`.
     *
     * @return array<string,array<string,string>>
     */
    public static function grupos(): array
    {
        $grupos = [];

        foreach (self::CAMPOS as $clave => $campo) {
            $grupos[$campo['grupo']][$clave] = $campo['etiqueta'];
        }

        return $grupos;
    }

    /**
     * **El texto que guarda el escritorio**: `ETIQUETA: valor.` por cada campo cargado que entra en el resumen, en
     * su orden. Sin ningún valor devuelve `''`.
     *
     * @param  array<string,mixed>  $valores
     */
    public static function componer(array $valores): string
    {
        $partes = [];

        foreach (self::CAMPOS as $clave => $campo) {
            if (! $campo['enTexto']) {
                continue;
            }

            $valor = trim((string) ($valores[$clave] ?? ''));

            if ($valor === '') {
                continue;
            }

            $partes[] = $campo['etiqueta'] . ': ' . $valor . '.';
        }

        return implode(' ', $partes);
    }

    /** El texto guardado en la consulta, o `null` si no hay. */
    public static function textoGuardado(Consulta $consulta): ?string
    {
        return TextoDeLaConsulta::limpio($consulta->{self::COLUMNA_TEXTO});
    }

    /**
     * Los valores guardados en la fila del panel, por clave (los números como texto, para el formulario), más
     * `fecha` en formato `Y-m-d`.
     *
     * @return array<string,?string>
     */
    public function de(Consulta $consulta): array
    {
        $fila = $this->fila($consulta);
        $valores = [];

        foreach (self::CAMPOS as $clave => $campo) {
            $valores[$clave] = $fila === null ? null : self::comoTexto($fila->{$campo['columna']});
        }

        $valores['fecha'] = $fila?->fecha?->format('Y-m-d');

        return $valores;
    }

    /** La fila del panel de esa consulta, si existe. */
    public function fila(Consulta $consulta): ?ExamenPacienteConsulta
    {
        return ExamenPacienteConsulta::where('hhistoria', (int) $consulta->numhistoria)
            ->where('hconsulta', (int) $consulta->nroconsulta)
            ->first();
    }

    /** La fila del panel de esa consulta, creada si todavía no existe (la clave es del DataWindow del escritorio). */
    public function filaParaEscribir(Consulta $consulta): ExamenPacienteConsulta
    {
        return $this->fila($consulta) ?? ExamenPacienteConsulta::create([
            'hhistoria' => (int) $consulta->numhistoria,
            'hconsulta' => (int) $consulta->nroconsulta,
        ]);
    }

    /** Un número de la columna como texto del formulario: `30`, `1.2` (sin ceros de más ni comas). */
    public static function comoTexto($valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $numero = (float) $valor;

        // Un entero se muestra sin decimales; si no, con los que tenga (hasta 3, que es lo que se teclea).
        return fmod($numero, 1.0) === 0.0
            ? (string) (int) $numero
            : rtrim(rtrim(number_format($numero, 3, '.', ''), '0'), '.');
    }
}
