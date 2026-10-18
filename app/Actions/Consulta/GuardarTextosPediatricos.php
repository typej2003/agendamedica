<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * **La regla de guardado de las pestañas de texto de pediatría** (WEB-12.2): el examen físico (`examen_fisico`) y el cuestionario (`cuestionario`).
 * Las dos tienen la misma forma —**una fila por consulta**, llaveada por `reg_medico` + `nrohistoria` + `nroconsulta`, con columnas de texto libre—
 * así que la regla vive una vez: guarda **sólo lo que cambió** (columna que no viaja, no se toca; vacía, se borra), con el rastro por columna en
 * `sync_changes` (`source = web`) y el last-write-wins del sync, y **no crea una fila en blanco**. El texto «vacío» del legado (`none`) es `null`.
 */
abstract class GuardarTextosPediatricos
{
    use EscribeConRastro;

    /** @return class-string<Model> */
    abstract protected function modelo(): string;

    abstract protected function tabla(): string;

    /** @return array<int,string> las columnas de texto que edita la pantalla (en la fila de esta tabla) */
    abstract protected function columnas(): array;

    /**
     * @param  array<string,mixed>  $valores  `columna => texto` de lo que viajó
     */
    public function ejecutar(Consulta $consulta, array $valores, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeSolicitud
    {
        $nuevos = [];
        foreach ($this->columnas() as $columna) {
            if (array_key_exists($columna, $valores)) {
                $nuevos[$columna] = TextoDeLaConsulta::limpio($valores[$columna]);
            }
        }

        $cuando = $this->ahora($cuando);
        $modelo = $this->modelo();

        return DB::transaction(function () use ($consulta, $nuevos, $cuando, $origen, $modelo) {
            $fila = $modelo::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)->where('nroconsulta', (int) $consulta->nroconsulta)
                ->lockForUpdate()->orderBy('id')->first();

            if ($fila === null) {
                if (count(array_filter($nuevos, fn ($v) => $v !== null)) === 0) {
                    return new ResultadoDeSolicitud();
                }

                $this->crearFila($modelo, $this->tabla(), $nuevos + [
                    'reg_medico' => $consulta->reg_medico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                ], $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, $this->tabla(), $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }
}
