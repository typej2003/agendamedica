<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\EstudiosDeLaConsulta;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\RadiologiaObs;
use App\Models\RadiologiaPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar la solicitud de estudios de una consulta** (WEB-5.4, pestaña *Estudios*): los estudios de imágenes pedidos, en el orden de la
 * lista, y el «Resumen clínico» (la observación).
 *
 * Igual que la orden de laboratorio, **se guarda entera: lo marcado es lo que queda** (`$codigos` es la lista completa y ordenada de
 * `coderadio`). Los estudios que ya estaban se dejan y se les corrige el `orden` si cambió su posición; los que faltan se **crean**
 * (`nroopcion = 1`, como el escritorio) y los que sobran se **borran**. Un estudio no se repite en la misma consulta.
 *
 * Un estudio **nuevo** tiene que estar en el catálogo de estudios del médico (`examenes` con el tipo `002`; `radiologias` no se usa, ver
 * `CatalogoParaPedirExamenes`); uno que ya estaba se acepta aunque ya no esté en el catálogo. Sin ningún estudio y sin observación no es
 * un documento (`QueEsUnDocumento::estudios`) y no se guarda: para vaciarla, `EliminarSolicitudDeEstudios`.
 *
 * El `orden` queda numerado 1, 2, 3… según la posición en la lista (el escritorio le ponía la cantidad de filas del catálogo, el mismo
 * número a todos, y sólo la sexta parte de las filas lo tiene). Rastro en `sync_changes` (`source = web`) por fila y por columna.
 */
final class GuardarSolicitudDeEstudios
{
    use EscribeConRastro;

    public function __construct(private EstudiosDeLaConsulta $solicitudes, private CatalogoParaPedirExamenes $catalogo)
    {
    }

    /**
     * @param  array<int,string>  $codigos  los `coderadio` que debe tener la consulta, en el orden de la hoja
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si la solicitud está vacía, un estudio nuevo no es del catálogo o la observación es demasiado larga
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

        if (! QueEsUnDocumento::estudios(count($codigos), $observacion)) {
            throw new InvalidArgumentException('La solicitud necesita al menos un estudio o un resumen clínico.');
        }

        if ($observacion !== null && mb_strlen($observacion) > EstudiosDeLaConsulta::LARGO_OBSERVACION) {
            throw new InvalidArgumentException('El resumen clínico es demasiado largo.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $codigos, $observacion, $cuando, $origen) {
            $regMedico = (string) $consulta->reg_medico;

            $filas = RadiologiaPaciente::where('reg_medico', $regMedico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->orderBy('id')
                ->get();
            $estaban = $filas->map(fn (RadiologiaPaciente $fila) => trim((string) $fila->coderadio))->unique()->all();

            $nuevos = array_values(array_diff($codigos, $estaban));
            $delCatalogo = $this->catalogo->resolver($regMedico, true, $nuevos);

            foreach ($nuevos as $codigo) {
                if (! isset($delCatalogo[$codigo])) {
                    throw new InvalidArgumentException('El estudio «' . $codigo . '» no está en tu catálogo de estudios.');
                }
            }

            $quitados = 0;
            foreach ($filas as $fila) {
                if (! in_array(trim((string) $fila->coderadio), $codigos, true)) {
                    $this->borrarFila($fila, 'radiologia_paciente', $cuando, $origen);
                    $quitados++;
                }
            }

            $columnas = [];
            $bloqueadas = [];

            foreach ($codigos as $posicion => $codigo) {
                $orden = $posicion + 1;
                $existente = $filas->first(fn (RadiologiaPaciente $fila) => trim((string) $fila->coderadio) === $codigo);

                if ($existente === null) {
                    $this->crearFila(RadiologiaPaciente::class, 'radiologia_paciente', [
                        'reg_medico' => $regMedico,
                        'nrohistoria' => (int) $consulta->numhistoria,
                        'nroconsulta' => (int) $consulta->nroconsulta,
                        'coderadio' => $codigo,
                        'nroopcion' => EstudiosDeLaConsulta::OPCION,
                        'orden' => $orden,
                    ], $cuando, $origen);

                    continue;
                }

                [$escritas, $bloqueo] = $this->corregirFila($existente, 'radiologia_paciente', ['orden' => $orden], $cuando, $origen);
                $columnas = array_merge($columnas, $escritas);
                $bloqueadas = array_merge($bloqueadas, $bloqueo);
            }

            [$creada, $deLaObservacion, $bloqueoObservacion] = $this->guardarObservacion($consulta, $observacion, $cuando, $origen);

            return new ResultadoDeOrden(
                count($nuevos),
                $quitados,
                $creada,
                array_values(array_unique(array_merge($columnas, $deLaObservacion))),
                array_values(array_unique(array_merge($bloqueadas, $bloqueoObservacion))),
            );
        });
    }

    /** @return array{0:bool, 1:array<int,string>, 2:array<int,string>} si se creó la fila, las columnas escritas y las bloqueadas */
    private function guardarObservacion(Consulta $consulta, ?string $observacion, $cuando, ?string $origen): array
    {
        $fila = $this->solicitudes->filaDeObservacion($consulta);

        if ($fila === null) {
            if ($observacion === null) {
                return [false, [], []];
            }

            $this->crearFila(RadiologiaObs::class, 'radiologia_obs', [
                'reg_medico' => (string) $consulta->reg_medico,
                'numhistoria' => (int) $consulta->numhistoria,
                'numconsulta' => (int) $consulta->nroconsulta,
                'observacion' => $observacion,
            ], $cuando, $origen);

            return [true, [], []];
        }

        [$escritas, $bloqueadas] = $this->corregirFila($fila, 'radiologia_obs', ['observacion' => $observacion], $cuando, $origen);

        return [false, $escritas, $bloqueadas];
    }
}
