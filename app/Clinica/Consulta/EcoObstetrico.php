<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\EcoObstetrico as EcoObstetricoModelo;

/**
 * **El eco obstétrico de una consulta** (WEB-6.2): su lectura y —cuando el DataWindow lo dicte— su mapa del
 * dato. Es el hermano de `EcoGinecologico` (el eco pélvico, WEB-6.1) y comparte con él la forma:
 *
 *  - **La clave es `numhistoria` + `nroconsulta`** (`historia` + `consulta` en la tabla, las dos con
 *    `key=yes` en el DataWindow del escritorio): **una fila por consulta**, no una por gesta.
 *  - La lectura devuelve el **mismo objeto de valores** que el eco pélvico, `EstudioEcografico`, que es
 *    genérico (envuelve la fila y normaliza los "vacíos" del legado): así la pantalla, la hoja y la regla de
 *    "esto es un documento o no" (`QueEsUnDocumento::ecografia`) se escriben una sola vez.
 *
 * Lo que **este** eco tiene y el pélvico no son tres columnas que lo atan al embarazo: **`gesta`** (el número
 * de la gesta que se estaba siguiendo), **`fur`** (la FUR, que el escritorio copia del control prenatal) y
 * **`eg`** (la edad gestacional, guardada como texto). Ver `App\Clinica\Embarazo\` para de dónde salen la FUR
 * y la gesta (`FurDelControlPrenatal`, `GestasDeLaHistoria`).
 *
 * Los **estudios** de este ítem son varios —el **primer trimestre** (`eco_obstetrico`) y el **tercero**
 * (`eco_obstetrico_tercer`, con sus variantes `_o`/`_2`)— y cada uno tiene su tabla: esta clase es la del
 * **primer trimestre**, que es la primera que se implementa. El **Doppler** y el **ecocardiograma fetal**
 * son WEB-6.3.
 */
final class EcoObstetrico
{
    use MapaDelEcoObstetrico;

    /** La tabla del eco obstétrico del primer trimestre. */
    public const TABLA = 'eco_obstetrico';

    /** La columna de la historia, como en el DataWindow (`key=yes`). */
    public const COLUMNA_HISTORIA = 'historia';

    /** La columna de la consulta: **es parte de la clave** (`consulta`, `NOT NULL`). */
    public const COLUMNA_CONSULTA = 'consulta';

    /**
     * Las columnas de la **ficha** del estudio: la fecha, la FUR y la gesta son del embarazo que se está
     * siguiendo (no hallazgos), así que no cuentan para decidir si el eco dice algo. Es la misma lista de la
     * Action (`GuardarEcoObstetricoBase::NO_CUENTAN`) y de la pantalla, y la que usa la historia del paciente y
     * el lote de documentos de la cita al mirar la tabla en conjunto (WEB-6.3).
     *
     * @var array<int,string>
     */
    public const COLUMNAS_QUE_NO_CUENTAN = ['fecha', 'fur', 'gesta'];

    /**
     * **El mapa del dato del eco obstétrico del primer trimestre**, en los bloques del escritorio.
     *
     * Los rótulos son los del DataWindow vivo; los `códigos` de cada lista, los del escritorio. Dos avisos:
     *
     *  - La columna **`observacion`** lleva el rótulo ***Conclusión:*** y **`otros`** el de
     *    ***Observaciones:*** (al revés de lo que uno esperaría): se respeta lo que el médico ve.
     *  - Las tres medidas de cada **ovario** y las dos del **cuerpo lúteo** en el escritorio se rotulan con
     *    sub-rótulos **posicionales** (*Long:*, *Trans:*, *ANT.POST:*, *Der.:*, *Izq.:*) que el DataWindow
     *    no asocia a cada columna: acá van con el nombre del grupo adelante (`Ovario derecho: Long`) y la
     *    columna que corresponde, que es lo que el escritorio guarda.
     *
     * @return array<int,array{titulo:string, icono:string, campos:array<int,array<string,mixed>>}>
     */
    public static function grupos(): array
    {
        return [
            ['titulo' => 'El embarazo', 'icono' => 'bi-calendar-heart', 'campos' => [
                self::fecha('fecha', 'Fecha', 'La fecha del estudio: abre con la de hoy y se puede cambiar.'),
                self::fechaTecleada('fur', 'FUR', 'La fecha de la última regla: se teclea como dd/mm/aaaa (en el escritorio se teclea a mano: su ventana no la lee del control prenatal).'),
                self::lista('gesta', 'Gesta', ['I' => '1', 'II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5',
                    'VI' => '6', 'VII' => '7', 'VIII' => '8', 'IX' => '9', 'X' => '10', 'XI' => '11'],
                    'El número de la gesta que se está siguiendo (el escritorio lo numera en romanos).'),
            ]],

            ['titulo' => 'El útero y los ovarios', 'icono' => 'bi-circle', 'campos' => [
                self::lista('cuerpo_utero', 'Útero', ['Visible' => '1', 'No visible' => '2']),
                // Las medidas: el escritorio las pinta con máscara `###.00` (decimales de dos cifras) y el
                // servidor valida numérico; la columna es `decimal(5,2)`.
                self::decimal('cuerpo', 'Útero: Long'),
                self::decimal('transverso', 'Útero: Trans'),
                self::decimal('antero', 'Útero: ANT.POST'),
                self::decimal('ovarioderlong', 'Ovario derecho: Long'),
                self::decimal('ovarioderap', 'Ovario derecho: AP'),
                self::decimal('ovariodertras', 'Ovario derecho: Trans'),
                self::decimal('ovarioizqlong', 'Ovario izquierdo: Long'),
                self::decimal('ovarioizqap', 'Ovario izquierdo: AP'),
                self::decimal('ovarioizqtras', 'Ovario izquierdo: Trans'),
                self::decimal('der', 'Cuerpo lúteo: derecho'),
                self::decimal('izqu', 'Cuerpo lúteo: izquierdo'),
                self::lista('posicionutreo', 'Utero', ['AVF' => '1', 'RVF' => '2', 'Indiferente' => '3']),
                self::lista('posicionavf_d', 'Posición AVF', ['Derecho' => 'D', 'Izquierdo' => 'I', 'Línea media' => 'L']),
                self::lista('posicionrvf_d', 'Posición RVF', ['Derecha' => 'D', 'Izquierda' => 'I']),
            ]],

            ['titulo' => 'El saco y la vitalidad', 'icono' => 'bi-activity', 'campos' => [
                self::lista('saco_gestacional', 'Saco gestacional', ['Sí' => '1', 'No' => '2'],
                    'Ojo: esta casilla guarda 1 y 2, no S y N como las demás.'),
                self::siNo('circular_ovuide', 'Circular ovoide'),
                self::siNo('alargado', 'Alargado'),
                self::siNo('retraido', 'Retraído'),
                self::siNo('anormal', 'Anormal'),
                self::siNo('movimiento_embrionario', 'Movimiento embrionario'),
                self::siNo('actividad_cardiaca', 'Actividad cardíaca'),
            ]],

            ['titulo' => 'Las medidas y los marcadores', 'icono' => 'bi-rulers', 'campos' => [
                // Enteros, como las máscaras `###`/`##` del DataWindow (con su spin: LCC y DBP 5-100, TN 1-10).
                self::entero('lcc', 'LCC', 'La longitud cefalocaudal.'),
                self::entero('dbp', 'DBP', 'El diámetro biparietal.'),
                self::entero('tn', 'TN', 'La translucencia nucal.'),
                // El escritorio la pinta con máscara `##` (entera) aunque la columna sea `decimal(5,2)`.
                self::entero('sa_vit', 'Saco vitelino'),
                self::lista('dc_venoso', 'Ductus venoso', ['Normal' => 'N', 'Patológico' => 'P']),
            ]],

            ['titulo' => 'El riesgo del embarazo temprano', 'icono' => 'bi-exclamation-triangle', 'campos' => [
                self::siNo('huevo_anembrionado', 'Huevo anembrionado'),
                self::siNo('mola', 'Mola'),
                self::siNo('restos_abortivos', 'Restos ovulares'),
                self::siNo('embarazo_ectopico', 'Embarazo ectópico'),
            ]],

            ['titulo' => 'La conclusión', 'icono' => 'bi-file-earmark-text', 'campos' => [
                ['columna' => 'observacion', 'etiqueta' => 'Conclusión', 'tipo' => 'area',
                    'ayuda' => 'La conclusión del estudio (la columna se llama `observacion`).'],
                ['columna' => 'otros', 'etiqueta' => 'Observaciones', 'tipo' => 'area',
                    'ayuda' => 'Las observaciones (la columna se llama `otros`).'],
            ]],
        ];
    }

    /**
     * Las columnas que la pantalla edita, en el orden del mapa: es lo que la Action guarda.
     *
     * @return array<int,string>
     */
    public static function columnas(): array
    {
        $columnas = [];

        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $columnas[] = $campo['columna'];
            }
        }

        return $columnas;
    }

    /**
     * El estudio de una consulta: sus valores, normalizados, o el objeto **vacío** si esa consulta no tiene
     * eco obstétrico (la pantalla lo muestra en blanco y la hoja no se ofrece).
     */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /**
     * La fila del eco obstétrico de una consulta, o `null`.
     *
     * La consulta ya viene resuelta contra el contexto (el médico y su consultorio), así que acá se busca por
     * su clave exacta: el `reg_medico` de la consulta y sus `numhistoria`/`nroconsulta`.
     */
    public function filaDe(Consulta $consulta): ?EcoObstetricoModelo
    {
        return EcoObstetricoModelo::query()
            ->where('reg_medico', $consulta->reg_medico)
            ->where(self::COLUMNA_HISTORIA, (int) $consulta->numhistoria)
            ->where(self::COLUMNA_CONSULTA, (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * La fila del eco de una consulta **para escribir**: la que ya existe o una nueva con su clave puesta
     * (`reg_medico`, `historia` y `consulta`) para que la Action sólo tenga que completar lo que el médico
     * cargó. Es el mismo camino del eco pélvico: la clave la arma el mapa del dato y no el formulario.
     */
    public function filaParaEscribir(Consulta $consulta): EcoObstetricoModelo
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new EcoObstetricoModelo([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
        ]);
    }
}
