<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\Cuestionario;
use App\Models\ExamenFisicoPediatrico;

/**
 * **El mapa del dato del examen pediátrico y del cuestionario** (WEB-12.2): qué columna guarda cada cuadro de la pestaña *Examen Físico*
 * (`tabpage_30`) y *Cuestionario* (`tabpage_31`) de `w_consulta_principal` del escritorio de pediatría, y su lectura.
 *
 * ⚠️ **Los rótulos de la pantalla no son los nombres de las columnas.** El escritorio reusó las columnas del examen general: el cuadro «CONDICIONES
 * GENERALES» se guarda en `cabeza`, «PIEL» en `torax`, «CABEZA» en `abdomen`, «ORL» en `pelvis`, «TÓRAX» en `genitales`, «ABDOMEN» en `brazos`,
 * «GENITALES» en `piernas` y «EXTREMIDADES» en `otros` (`ue_fisico`, `:11424`, y los `UPDATE` de `:2888`). El «NEUROLÓGICO» no está en esa tabla: va a
 * `consultas.otros` (`:2855`). La web respeta ese mapa para que el escritorio y la web lean lo mismo.
 *
 * El examen vive en `examen_fisico` (la tabla de pediatría, clave `nrohistoria` + `nroconsulta`); el cuestionario en `cuestionario`, también por
 * `nrohistoria` + `nroconsulta`. Ninguna de las dos lleva el id de la consulta: llevan su **número**.
 */
final class ExamenPediatrico
{
    /**
     * Los cuadros del examen, en el orden de la pantalla del escritorio (tres columnas): columna => rótulo. `neurologico` vive en `consultas.otros`.
     *
     * @var array<string,string>
     */
    public const EXAMEN = [
        'cabeza' => 'Condiciones generales',
        'torax' => 'Piel',
        'abdomen' => 'Cabeza',
        'pelvis' => 'ORL',
        'genitales' => 'Tórax',
        'brazos' => 'Abdomen',
        'piernas' => 'Genitales',
        'otros' => 'Extremidades',
        'neurologico' => 'Neurológico',
    ];

    /** La columna de `consultas` donde se guarda el neurológico. */
    public const COLUMNA_NEUROLOGICO = 'otros';

    /**
     * Los cuadros del cuestionario, en el orden de la pantalla: columna => rótulo.
     *
     * @var array<string,string>
     */
    public const CUESTIONARIO = [
        'digestivo' => 'Digestivo',
        'respiratorio' => 'Respiratorio',
        'circulatorio' => 'Circulatorio',
        'musculoesqueletico' => 'Músculo-esquelético',
        'nerviosa' => 'Nervioso',
        'urinario' => 'Urinario',
        'reproductor' => 'Reproductor',
        'sentidos' => 'Sentidos',
        'denticion' => 'Dentición',
        'lactancia' => 'Lactancia',
        'otras' => 'Otros',
    ];

    /**
     * El examen de la consulta como `columna => texto|null` (con el neurológico de `consultas.otros` y el resumen), con los vacíos del legado
     * (`''`, `none`) ya hechos `null`.
     *
     * @return array<string,?string>
     */
    public function examen(Consulta $consulta): array
    {
        $fila = $this->filaDelExamen($consulta);
        $salida = [];

        foreach (array_keys(self::EXAMEN) as $columna) {
            $salida[$columna] = $columna === 'neurologico'
                ? TextoDeLaConsulta::limpio($consulta->{self::COLUMNA_NEUROLOGICO})
                : TextoDeLaConsulta::limpio($fila?->{$columna});
        }

        $salida['fisico_resumen'] = TextoDeLaConsulta::limpio($fila?->fisico_resumen);

        return $salida;
    }

    /** @return array<string,?string> */
    public function cuestionario(Consulta $consulta): array
    {
        $fila = $this->filaDelCuestionario($consulta);

        return collect(array_keys(self::CUESTIONARIO))
            ->mapWithKeys(fn (string $columna) => [$columna => TextoDeLaConsulta::limpio($fila?->{$columna})])
            ->all();
    }

    public function filaDelExamen(Consulta $consulta): ?ExamenFisicoPediatrico
    {
        return ExamenFisicoPediatrico::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)->where('nroconsulta', (int) $consulta->nroconsulta)->orderBy('id')->first();
    }

    public function filaDelCuestionario(Consulta $consulta): ?Cuestionario
    {
        return Cuestionario::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)->where('nroconsulta', (int) $consulta->nroconsulta)->orderBy('id')->first();
    }
}
