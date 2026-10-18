<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\ExamenPediatrico;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\ExamenFisicoPediatrico;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Guardar el examen físico de pediatría** (WEB-12.2): los ocho cuadros por aparatos y el resumen en `examen_fisico` (la regla es la de
 * `GuardarTextosPediatricos`) **más el neurológico**, que el escritorio guarda en `consultas.otros` y esta Action escribe ahí, también sólo si cambió y con su
 * rastro (`source = web`). Los rótulos y las columnas están en `ExamenPediatrico` (los de la pantalla no son los nombres de las columnas).
 */
final class GuardarExamenFisicoPediatrico extends GuardarTextosPediatricos
{
    // Los métodos de la mecánica del rastro son privados del trait: la subclase usa su propia copia para el neurológico.
    use EscribeConRastro;

    protected function modelo(): string
    {
        return ExamenFisicoPediatrico::class;
    }

    protected function tabla(): string
    {
        return 'examen_fisico';
    }

    protected function columnas(): array
    {
        return array_merge(array_values(array_diff(array_keys(ExamenPediatrico::EXAMEN), ['neurologico'])), ['fisico_resumen']);
    }

    public function ejecutar(Consulta $consulta, array $valores, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeSolicitud
    {
        $deLaFila = parent::ejecutar($consulta, $valores, $cuando, $origen);

        if (! array_key_exists('neurologico', $valores)) {
            return $deLaFila;
        }

        $columna = ExamenPediatrico::COLUMNA_NEUROLOGICO;
        $momento = $this->ahora($cuando);

        [$escritas, $bloqueadas] = DB::transaction(
            fn () => $this->corregirFila($consulta->newQuery()->lockForUpdate()->findOrFail($consulta->id), 'consultas', [$columna => TextoDeLaConsulta::limpio($valores['neurologico'])], $momento, $origen),
        );

        return new ResultadoDeSolicitud(
            creado: $deLaFila->creado,
            columnas: array_merge($deLaFila->columnas, array_map(fn () => 'neurologico', $escritas)),
            bloqueadas: array_merge($deLaFila->bloqueadas, array_map(fn () => 'neurologico', $bloqueadas)),
        );
    }
}
