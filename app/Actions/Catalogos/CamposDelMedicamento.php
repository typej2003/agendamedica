<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use App\Actions\Consulta\ReglasDelCatalogo;
use InvalidArgumentException;

/**
 * **Los campos de un medicamento del vademécum como el ABM los escribe** (WEB-8.1a): la normalización y los topes
 * que comparten el alta y la edición, para que las dos escriban la misma forma de fila (PLAN-WEB.md, R3).
 *
 * ## De dónde salen los campos (`d_a_vademecum_go.srd`, el DataWindow del ABM del escritorio)
 *
 * Los que el DataWindow deja editar (tienen `tabsequence`) son: el principio activo (`nombregenerico`, 35,
 * mayúsculas), el nombre comercial (`nombrecomercial`, 35, mayúsculas), las otras tres marcas
 * (`nombrecomercial1` a `3`, 40, mayúsculas), la presentación (50, como se escriba), la casilla *Psicotrópico*
 * (`sico`, `S` o `N`) y las indicaciones (`uso`). El código lo da el correlativo, no se escribe.
 * La web suma `dosificacion` (texto libre): el DataWindow la trae pero **no** la deja editar, y el usuario la
 * pidió como parte del mínimo del ABM (2026-10-11).
 *
 * ## La dosis pediátrica (2026-10-19)
 *
 * `dosificacion` **no** es sólo una nota: es la marca que usa la fórmula del récipe
 * (`App\Clinica\Consulta\DosisPediatrica`). El escritorio compara `dosificacion = 'SI'` (la columna es el texto
 * largo de `vademecum.dosificacion`, `d_a_escoger_medicamentos.srd`), y con eso el récipe calcula la posología por
 * peso. Por eso la ayuda del formulario dice que hay que escribir **SI**.
 *
 * Los seis números de la fórmula se editan acá desde esta tarea, con los nombres y las unidades del DataWindow:
 * `dosis` (mg/kg **al día**: la fórmula la divide entre `cada`), `cada` (**veces al día**), `durante` (días), `pvc` (mg del envase), `pvs` (ml del envase) y
 * `concentracion` (gotas por ml). Todos son opcionales: sin ellos, el récipe compone el rótulo en miligramos.

 * Las cuatro primeras reglas (nombre, genérico, presentación, indicaciones) son **las del alta rápida de la
 * consulta** (`AgregarMedicamentoAlVademecum`): se usan sus constantes y su normalización, no se copian.
 */
final class CamposDelMedicamento
{
    /** `vademecum.nombrecomercial1` a `3` son `char(40)`. */
    public const LARGO_MARCA = 40;

    /** `dosificacion` es un texto largo; el formulario pone el mismo tope razonable que las indicaciones. */
    public const LARGO_DOSIFICACION = 2000;

    /** Las columnas que el ABM escribe, en el orden en que se muestran. */
    public const COLUMNAS = [
        'nombrecomercial', 'nombregenerico', 'nombrecomercial1', 'nombrecomercial2', 'nombrecomercial3',
        'presentacion', 'sico', 'uso', 'dosificacion',
        // La dosis por peso del récipe pediátrico (2026-10-19).
        'dosis', 'cada', 'durante', 'pvc', 'pvs', 'concentracion',
    ];

    /** Los campos que son números (la dosis por peso): el `double` de la columna no se compara como texto. */
    public const NUMEROS = ['dosis', 'cada', 'durante', 'pvc', 'pvs', 'concentracion'];

    /** Cómo se llama cada columna en los avisos ("cambió el nombre comercial y la presentación"). */
    public const ETIQUETAS = [
        'nombrecomercial' => 'el nombre comercial',
        'nombregenerico' => 'el principio activo',
        'nombrecomercial1' => 'la segunda marca',
        'nombrecomercial2' => 'la tercera marca',
        'nombrecomercial3' => 'la cuarta marca',
        'presentacion' => 'la presentación',
        'sico' => 'la marca de psicotrópico',
        'uso' => 'las indicaciones',
        'dosificacion' => 'la dosificación',
        'dosis' => 'la dosis por kilo',
        'cada' => 'las veces al día',
        'durante' => 'los días de tratamiento',
        'pvc' => 'los mg del envase',
        'pvs' => 'los ml del envase',
        'concentracion' => 'las gotas por mililitro',
    ];

    /**
     * Los campos como se escriben.
     *
     * @param  array<string,mixed>  $datos  `columna => valor` tal como llegó (`sico` es un booleano o `S`/`N`)
     * @param  bool  $exigirComercial  el alta pide el nombre comercial (como el alta rápida); la edición pide
     *                                 **alguno de los dos nombres**, porque el vademécum del legado trae
     *                                 medicamentos que sólo tienen principio activo
     * @return array<string,?string> `columna => valor` de **todas** las columnas del ABM
     *
     * @throws InvalidArgumentException si falta el nombre o algo pasa del largo de su columna
     */
    public static function normalizar(array $datos, bool $exigirComercial): array
    {
        $comercial = self::nombre($datos['nombrecomercial'] ?? null, 'El nombre comercial');
        $generico = self::nombre($datos['nombregenerico'] ?? null, 'El principio activo');

        if ($exigirComercial) {
            // El mismo texto y el mismo tope que el alta rápida de la consulta.
            $comercial = ReglasDelCatalogo::descripcion((string) ($datos['nombrecomercial'] ?? ''), AgregarMedicamentoAlVademecum::LARGO_NOMBRE, 'medicamento');
        } elseif ($comercial === null && $generico === null) {
            throw new InvalidArgumentException('El medicamento necesita un nombre: escribe el nombre comercial o el principio activo.');
        }

        return [
            'nombrecomercial' => $comercial,
            'nombregenerico' => $generico,
            'nombrecomercial1' => self::marca($datos['nombrecomercial1'] ?? null, 'La segunda marca'),
            'nombrecomercial2' => self::marca($datos['nombrecomercial2'] ?? null, 'La tercera marca'),
            'nombrecomercial3' => self::marca($datos['nombrecomercial3'] ?? null, 'La cuarta marca'),
            'presentacion' => AgregarMedicamentoAlVademecum::opcional(
                $datos['presentacion'] ?? null, AgregarMedicamentoAlVademecum::LARGO_PRESENTACION, 'La presentación', false
            ),
            'sico' => self::psicotropico($datos['sico'] ?? null),
            'uso' => AgregarMedicamentoAlVademecum::opcional(
                $datos['uso'] ?? null, AgregarMedicamentoAlVademecum::LARGO_USO, 'Las indicaciones', false, false
            ),
            'dosificacion' => AgregarMedicamentoAlVademecum::opcional(
                $datos['dosificacion'] ?? null, self::LARGO_DOSIFICACION, 'La dosificación', false, false
            ),
            // La dosis por peso del récipe pediátrico (2026-10-19): números opcionales.
            'dosis' => self::numero($datos['dosis'] ?? null, 'La dosis por kilo'),
            'cada' => self::numero($datos['cada'] ?? null, 'Las veces al día'),
            'durante' => self::numero($datos['durante'] ?? null, 'Los días de tratamiento'),
            'pvc' => self::numero($datos['pvc'] ?? null, 'Los mg del envase'),
            'pvs' => self::numero($datos['pvs'] ?? null, 'Los ml del envase'),
            'concentracion' => self::numero($datos['concentracion'] ?? null, 'Las gotas por mililitro'),
        ];
    }

    /**
     * Uno de los números de la dosis: vacío es `null` y lo que no es un número (o es negativo) se rechaza con el
     * nombre del campo, para que el aviso del formulario diga cuál es.
     */
    private static function numero($valor, string $cual): ?float
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        if (! is_numeric(str_replace(',', '.', (string) $valor))) {
            throw new InvalidArgumentException($cual . ' tiene que ser un número.');
        }

        $numero = round((float) str_replace(',', '.', (string) $valor), 3);

        if ($numero < 0) {
            throw new InvalidArgumentException($cual . ' no puede ser negativo.');
        }

        return $numero;
    }

    /** El nombre comercial o el genérico: mayúsculas, hasta 35 y opcional. */
    private static function nombre($valor, string $cual): ?string
    {
        return AgregarMedicamentoAlVademecum::opcional($valor, AgregarMedicamentoAlVademecum::LARGO_NOMBRE, $cual, true);
    }

    /** Una de las otras marcas: mayúsculas, hasta 40 y opcional. */
    private static function marca($valor, string $cual): ?string
    {
        return AgregarMedicamentoAlVademecum::opcional($valor, self::LARGO_MARCA, $cual, true);
    }

    /** La casilla *Psicotrópico* del DataWindow: `S` si está tildada y `N` si no (el valor por defecto del escritorio). */
    public static function psicotropico($valor): string
    {
        return $valor === true || $valor === 1 || $valor === '1' || $valor === 'S' || $valor === 's' || $valor === 'on' ? 'S' : 'N';
    }
}
