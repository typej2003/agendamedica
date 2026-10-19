<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\Riesgo;
use App\Models\RiesgoPaciente;
use App\Models\RiesgoPacienteOpera;
use Illuminate\Support\Collection;

/**
 * **Riesgo quirúrgico** (cirugía pediátrica, 2026-10-19): la tercera solapa del ítem «I.Q.».
 *
 * Son **tres tablas** y hay que distinguirlas:
 *
 * | Tabla | Qué es | Clave |
 * |---|---|---|
 * | `riesgo` | el **catálogo** de factores del médico, con los puntos de cada uno | `clave` |
 * | `riesgo_paciente` | los factores **marcados al paciente** | `historia` + `descripcion` (⚠️ **no** por consulta) |
 * | `riesgo_paciente_opera` | el **resumen** de la evaluación de esa consulta | `historia` + `consulta` |
 *
 * El escritorio da de alta y de baja los marcados con `INSERT`/`DELETE` (`w_consulta_principal.srw:14288` y
 * `:14323`) y guarda el resumen con `INSERT`/`UPDATE` (`:22384` y `:22402`).
 */
final class RiesgoQuirurgico
{
    public const TABLA_PACIENTE = 'riesgo_paciente';
    public const TABLA_RESUMEN = 'riesgo_paciente_opera';

    /** Los campos del resumen que se editan (las recomendaciones no se tocan: el escritorio las pone en `null`). */
    private const CAMPOS_RESUMEN = [
        'clase'               => ['etiqueta' => 'Clase', 'columna' => 'clase', 'clase' => 'texto'],
        'conclusion'          => ['etiqueta' => 'Conclusión', 'columna' => 'conclusion', 'clase' => 'parrafo'],
        'nombre_intervencion' => ['etiqueta' => 'Intervención', 'columna' => 'nombre_intervencion', 'clase' => 'texto'],
    ];

    /** @return array<string,array{etiqueta:string, columna:string, clase:string}> */
    public static function camposDelResumen(): array
    {
        return self::CAMPOS_RESUMEN;
    }

    /** El catálogo del médico, agrupado por `sub_tipo` para la pantalla. */
    public function catalogo(): Collection
    {
        return Riesgo::orderBy('sub_tipo')->orderBy('clave')->get();
    }

    /** Los factores marcados al paciente. @return Collection<int,RiesgoPaciente> */
    public function marcados(Consulta $consulta): Collection
    {
        return RiesgoPaciente::where('historia', (int) $consulta->numhistoria)
            ->orderBy('descripcion')
            ->get();
    }

    /** Las descripciones marcadas (lo que la pantalla marca en los checkboxes). @return array<int,string> */
    public function descripcionesMarcadas(Consulta $consulta): array
    {
        return $this->marcados($consulta)->pluck('descripcion')->all();
    }

    /** Los puntos totales: la suma de lo marcado (lo que muestra y guarda el resumen). */
    public function puntos(Consulta $consulta): int
    {
        return (int) $this->marcados($consulta)->sum('punto');
    }

    /** El resumen de esa consulta, si ya existe. */
    public function resumen(Consulta $consulta): ?RiesgoPacienteOpera
    {
        return RiesgoPacienteOpera::where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * El resumen leído: los campos editables más `puntos` (calculados de lo marcado, que es lo que vale).
     *
     * @return array<string,?string>
     */
    public function de(Consulta $consulta): array
    {
        $fila = $this->resumen($consulta);
        $valores = [];

        foreach (self::CAMPOS_RESUMEN as $clave => $campo) {
            $valor = $fila?->{$campo['columna']};
            $valores[$clave] = $valor === null ? null : (string) $valor;
        }

        $valores['puntos'] = (string) $this->puntos($consulta);

        return $valores;
    }

    public function resumenParaEscribir(Consulta $consulta): RiesgoPacienteOpera
    {
        return $this->resumen($consulta) ?? RiesgoPacienteOpera::create([
            'reg_medico' => $consulta->reg_medico,
            'historia'   => (int) $consulta->numhistoria,
            'consulta'   => (int) $consulta->nroconsulta,
        ]);
    }
}
