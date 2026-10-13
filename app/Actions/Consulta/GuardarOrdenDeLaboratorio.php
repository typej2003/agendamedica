<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\ExamenObs;
use App\Models\ExamenPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar la orden de exámenes de laboratorio de una consulta** (WEB-5.4, pestaña *Laboratorio*): los exámenes pedidos y el «Resumen
 * clínico» (la observación).
 *
 * **La orden se guarda entera: lo marcado es lo que queda.** `$codigos` es la lista completa de `codeexamen` que debe tener la consulta
 * (como el editor de récipes de WEB-5.1): los que ya estaban se dejan **tal cual** (no se reescriben, así su `resultado` y su
 * `vinculante` no se tocan), los que faltan se **crean** y los que sobran se **borran**. Un examen no se repite en la misma consulta (la
 * clave del escritorio es `nrohistoria` + `nroconsulta` + `codeexamen`).
 *
 * Un examen **nuevo** tiene que estar en el catálogo de laboratorio del médico (`examenes` sin el tipo `002`); uno que ya estaba en la
 * consulta se acepta aunque su código ya no esté en el catálogo, para no obligar a quitarlo. La orden **sin ningún examen y sin
 * observación** no es un documento (`QueEsUnDocumento::laboratorio`) y no se guarda: para vaciarla, `EliminarOrdenDeLaboratorio`.
 *
 * La observación es una fila de `examen_obs` por consulta: se crea con el primer texto y se corrige columna por columna.
 *
 * Deja su rastro en `sync_changes` (`source = web`): `created`/`deleted` por cada examen, `created` de la observación y `updated` de su
 * columna con el last-write-wins del sync, y cambia `updated_at`. El móvil no baja estas tablas; el escritorio las sube fila por fila
 * (tienen clave primaria, trampa 75).
 */
final class GuardarOrdenDeLaboratorio
{
    use EscribeConRastro;

    public function __construct(private LaboratorioDeLaConsulta $ordenes, private CatalogoParaPedirExamenes $catalogo)
    {
    }

    /**
     * @param  array<int,string>  $codigos  los `codeexamen` que debe tener la consulta
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si la orden está vacía, un examen nuevo no es del catálogo o la observación es demasiado larga
     */
    public function ejecutar(
        Consulta $consulta,
        array $codigos,
        ?string $observacion,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeOrden {
        $codigos = array_values(array_unique(array_filter(array_map(fn ($codigo) => trim((string) $codigo), $codigos), fn (string $codigo) => $codigo !== '')));
        $observacion = TextoDeLaConsulta::limpio($observacion);

        if (! QueEsUnDocumento::laboratorio(count($codigos), $observacion)) {
            throw new InvalidArgumentException('La orden necesita al menos un examen o un resumen clínico.');
        }

        if ($observacion !== null && mb_strlen($observacion) > LaboratorioDeLaConsulta::LARGO_OBSERVACION) {
            throw new InvalidArgumentException('El resumen clínico es demasiado largo.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $codigos, $observacion, $cuando, $origen) {
            $regMedico = (string) $consulta->reg_medico;

            $filas = ExamenPaciente::where('reg_medico', $regMedico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->orderBy('id')
                ->get();
            $estaban = $filas->map(fn (ExamenPaciente $fila) => trim((string) $fila->codeexamen))->unique()->all();

            $nuevos = array_values(array_diff($codigos, $estaban));
            $delCatalogo = $this->catalogo->resolver($regMedico, false, $nuevos);

            foreach ($nuevos as $codigo) {
                if (! isset($delCatalogo[$codigo])) {
                    throw new InvalidArgumentException('El examen «' . $codigo . '» no está en tu catálogo de laboratorio.');
                }
            }

            foreach ($nuevos as $codigo) {
                $this->crearFila(ExamenPaciente::class, 'examen_paciente', [
                    'reg_medico' => $regMedico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                    'codeexamen' => $codigo,
                ], $cuando, $origen);
            }

            $quitados = 0;
            foreach ($filas as $fila) {
                if (! in_array(trim((string) $fila->codeexamen), $codigos, true)) {
                    $this->borrarFila($fila, 'examen_paciente', $cuando, $origen);
                    $quitados++;
                }
            }

            [$creada, $columnas, $bloqueadas] = $this->guardarObservacion($consulta, $observacion, $cuando, $origen);

            return new ResultadoDeOrden(count($nuevos), $quitados, $creada, $columnas, $bloqueadas);
        });
    }

    /** @return array{0:bool, 1:array<int,string>, 2:array<int,string>} si se creó la fila, las columnas escritas y las bloqueadas */
    private function guardarObservacion(Consulta $consulta, ?string $observacion, $cuando, ?string $origen): array
    {
        $fila = $this->ordenes->filaDeObservacion($consulta);

        if ($fila === null) {
            if ($observacion === null) {
                return [false, [], []];
            }

            $this->crearFila(ExamenObs::class, 'examen_obs', [
                'reg_medico' => (string) $consulta->reg_medico,
                'numhistoria' => (int) $consulta->numhistoria,
                'numconsulta' => (int) $consulta->nroconsulta,
                'observacion' => $observacion,
            ], $cuando, $origen);

            return [true, [], []];
        }

        [$escritas, $bloqueadas] = $this->corregirFila($fila, 'examen_obs', ['observacion' => $observacion], $cuando, $origen);

        return [false, $escritas, $bloqueadas];
    }
}
