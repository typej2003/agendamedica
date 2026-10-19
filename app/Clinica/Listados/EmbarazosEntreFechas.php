<?php

namespace App\Clinica\Listados;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **El listado de embarazos / partos entre fechas** (WEB-6.8): lo que el escritorio muestra en
 * `w_reporte_consulta_partos.srw` con los DataWindows `d_partos` (por rango) y `d_partos_semana` (por
 * semana exacta).
 *
 * La consulta del legado es un `JOIN` de dos tablas:
 *
 * ```
 * FROM pacientes, pre_natal_observaciones
 * WHERE pre_natal_observaciones.historia = pacientes.numhistoria
 * ```
 *
 * con las columnas `pacientes.nombres + ' ' + pacientes.apellidos`, `pacientes.fingreso`,
 * `(today - pacientes.fnacimiento)/365`, `pre_natal_observaciones.fur` (y su `compute` FPP = `fur + 283`) y
 * `pacientes.codesegemp`; ordenada por FPP descendente.
 *
 * **Las tres diferencias con el escritorio, y por qué:**
 *
 *  1. **`pacientes.numhistoria` no existe en el esquema de AppDDR.** El número de historia vive en
 *     `medico_pacientes.numhistoria` —el pivote del médico, que es de dónde lo lee toda la web, y el mismo
 *     que escribe el sync—, así que el `JOIN` del legado se escribe acá como `medico_pacientes` +
 *     `pacientes`. Ese pivote es además **el que acota el listado al médico del contexto**: el legado no
 *     necesitaba filtrar por médico porque cada instalación tenía su propia base.
 *  2. **El `reg_medico` del contexto es obligatorio** (regla del proyecto): se filtra por
 *     `pre_natal_observaciones.reg_medico` —el registro al que pertenece la gesta, así como lo lee
 *     `GestasDeLaHistoria`— y el pivote queda atado al mismo registro. Una gesta sin `reg_medico` (nula, de
 *     una instalación vieja) no se muestra, igual que en el control prenatal.
 *  3. **`pacientes.codesegemp` se resuelve al nombre** en el ViewModel con `CatalogoDeSeguros` (el
 *     `d_dww_empresas` del escritorio es la tabla `seg_emp`: `codesegemp` + `nombre`); si el código no está
 *     en el catálogo se muestra el código, que es lo que la web hace ya en la ficha del paciente.
 *
 * **Lo que se replica tal cual, sin interpretar:** el `WHERE pre_natal_observaciones.final = 'N'` del
 * legado (`d_partos.srd`, `d_partos_semana.srd`). El significado de esa columna **no está documentado en
 * ninguna fuente** del proyecto: es el filtro fijo del reporte y se aplica carácter por carácter, sin
 * suponer que "N" quiera decir nada. Si alguna vez se documenta, esta es la línea que hay que revisar.
 *
 * **El orden** es el del `sort="compute_1 D"` del DataWindow: FPP descendente, que como la FPP es la FUR más
 * una constante es lo mismo que `fur` descendente. Se agrega el `id` como desempate —el legado no lo tiene—
 * para que dos gestas con la misma FUR salgan siempre en el mismo orden.
 */
final class EmbarazosEntreFechas
{
    /** La tabla del control prenatal: la de la gesta y su FUR. */
    public const TABLA = 'pre_natal_observaciones';

    /** Cuántas filas muestra una página de la pantalla. La hoja impresa las trae todas. */
    public const POR_PAGINA = 25;

    /**
     * Una página de las gestas que caen en el rango (o en la semana) y en las edades pedidas, del médico del
     * contexto. El corte lo hace la base.
     *
     * @return LengthAwarePaginator<int,object>|Collection<int,object> filas con `paciente_id`, `nombres`,
     *         `apellidos`, `numhistoria`, `fingreso`, `fnacimiento`, `codesegemp` y `fur`; una colección vacía
     *         cuando la semana pedida no se entiende
     */
    public function filas(string $regMedico, FiltroDeEmbarazos $filtro): LengthAwarePaginator|Collection
    {
        // Una semana que no se entiende no se busca: no hay rango de FUR que la represente y devolver "todo"
        // sería peor que no devolver nada (la pantalla lo explica).
        if (! $filtro->esValido()) {
            return collect();
        }

        return $this->consultaDelRango($regMedico, $filtro)
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Todas las gestas del rango, sin cortar: es lo que imprime la hoja del listado (el papel no tiene páginas).
     *
     * @return Collection<int,object>
     */
    public function todas(string $regMedico, FiltroDeEmbarazos $filtro): Collection
    {
        if (! $filtro->esValido()) {
            return collect();
        }

        return $this->consultaDelRango($regMedico, $filtro)->get();
    }

    /** La consulta con los filtros del rango (o de la semana) y el orden del `sort="compute_1 D"` del legado. */
    private function consultaDelRango(string $regMedico, FiltroDeEmbarazos $filtro)
    {
        $fur = $filtro->rangoDeFur();
        $nacimiento = $filtro->rangoDeNacimiento();

        return $this->consulta($regMedico)
            ->whereBetween(self::TABLA . '.fur', [$fur->desde(), $fur->hasta()])
            ->whereBetween('pacientes.fnacimiento', [$nacimiento->desde(), $nacimiento->hasta()])
            ->orderByDesc(self::TABLA . '.fur')
            ->orderBy(self::TABLA . '.id')
            ->select([
                'pacientes.id as paciente_id',
                'pacientes.nombres',
                'pacientes.apellidos',
                'pacientes.fingreso',
                'pacientes.fnacimiento',
                'pacientes.codesegemp',
                'medico_pacientes.numhistoria',
                self::TABLA . '.fur',
            ]);
    }

    /**
     * La consulta base: la gesta del control prenatal con su paciente, acotada al médico del contexto.
     *
     * El `JOIN` del pivote va por `numhistoria` **y** por `reg_medico`: el número de historia se repite entre
     * consultorios (cada instalación numera desde 1), así que atarlo también al registro es lo que evita
     * cruzar la gesta de un médico con el paciente homónimo de otro.
     */
    private function consulta(string $regMedico)
    {
        return DB::table(self::TABLA)
            ->join('medico_pacientes', function ($join) {
                // En MySQL `pre_natal_observaciones.historia` (utf8mb4_general_ci, tabla del legado) y
                // `medico_pacientes.numhistoria` (utf8mb4_unicode_ci) tienen collation distinta y el `=` falla
                // con el error 1267 «Illegal mix of collations»: se fuerza la del pivote. SQLite no lo necesita.
                if (DB::connection()->getDriverName() === 'mysql') {
                    $join->whereRaw('medico_pacientes.numhistoria = ' . self::TABLA . '.historia COLLATE utf8mb4_unicode_ci')
                        ->whereRaw('medico_pacientes.reg_medico = ' . self::TABLA . '.reg_medico COLLATE utf8mb4_unicode_ci');
                } else {
                    $join->on('medico_pacientes.numhistoria', '=', self::TABLA . '.historia')
                        ->on('medico_pacientes.reg_medico', '=', self::TABLA . '.reg_medico');
                }
            })
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where(self::TABLA . '.reg_medico', $regMedico)
            // El filtro fijo del legado. El significado de `final` no está documentado en ninguna fuente:
            // se replica tal cual (ver el docblock de la clase).
            ->where(self::TABLA . '.final', 'N');
    }
}
