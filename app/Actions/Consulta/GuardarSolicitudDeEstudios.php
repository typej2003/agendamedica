<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\EstudiosDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\RadiologiaObs;
use App\Models\RadiologiaPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **La solicitud de estudios de una consulta** (WEB-5.4, pestaña *Estudios*): los estudios de imágenes pedidos, en el orden de la
 * lista, y el «Resumen clínico» (la observación).
 *
 * ## Tres escrituras, una por caso (O-29)
 *
 * La pantalla se pinta como *Motivos de consulta*: el catálogo a la vista con su «Pasar» por fila, y **agregar y quitar son
 * inmediatos**. Por eso la Action tiene:
 *
 *  - `agregar($consulta, $codigos)`: da de alta uno o varios estudios del catálogo, **al final** de la lista y con su `orden` (`nroopcion
 *    = 1`, como el escritorio), sin tocar los que ya estaban ni la observación.
 *  - `quitar($consulta, $codigo)`: da de baja **ese** estudio y sólo ése (`deleted` por fila). Es lo que impide que un clic en la web se
 *    lleve lo que el escritorio cargó: `radiologia_paciente` se sube **por fila**.
 *  - `guardarResumen($consulta, $observacion)`: escribe **sólo** el «Resumen clínico», que sigue siendo un formulario con su botón.
 *
 * Las tres reusan la comprobación de catálogo (`CatalogoParaPedirExamenes::resolver`) y el rastro (`EscribeConRastro`).
 *
 * ## Lo que ya no existe: «lo marcado es lo que queda»
 *
 * Hasta O-29 se guardaba la solicitud **entera** en un `POST`: creaba los que faltaban, corregía el `orden` y **borraba los que no
 * venían**. Con altas y bajas inmediatas esa semántica no puede convivir (un guardado de la web se llevaría filas que el escritorio
 * cargó y el médico no tocó), así que se fue: hoy cada estudio entra y sale por su propio clic.
 *
 * Un estudio **nuevo** tiene que estar en el catálogo de estudios del médico (`examenes` con el tipo `002`; `radiologias` no se usa, ver
 * `CatalogoParaPedirExamenes`); uno que ya estaba se acepta aunque ya no esté en el catálogo.
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
     * **Agregar estudios a la solicitud** (O-29): el alta inmediata de un clic en el catálogo.
     *
     * @param  array<int,string>  $codigos  los `coderadio` que se agregan, en el orden en que se agregan
     *
     * @throws InvalidArgumentException si un código no es del catálogo de estudios del médico
     */
    public function agregar(
        Consulta $consulta,
        array $codigos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeOrden {
        $codigos = $this->normalizar($codigos);

        if ($codigos === []) {
            throw new InvalidArgumentException('Elige el estudio que quieres agregar a la solicitud.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $codigos, $cuando, $origen) {
            $regMedico = (string) $consulta->reg_medico;
            $filas = $this->filasDeLaConsulta($consulta);
            $estaban = $filas->map(fn (RadiologiaPaciente $fila) => trim((string) $fila->coderadio))->unique()->all();
            $nuevos = array_values(array_diff($codigos, $estaban));

            $this->comprobarCatalogo($regMedico, $nuevos);

            // Después del último de la lista: el orden de la hoja es el orden en que se pidieron.
            $siguiente = (int) $filas->max(fn (RadiologiaPaciente $fila) => (int) $fila->orden) + 1;

            foreach ($nuevos as $codigo) {
                $this->crearFila(RadiologiaPaciente::class, 'radiologia_paciente', [
                    'reg_medico' => $regMedico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                    'coderadio' => $codigo,
                    'nroopcion' => EstudiosDeLaConsulta::OPCION,
                    'orden' => $siguiente++,
                ], $cuando, $origen);
            }

            return new ResultadoDeOrden(count($nuevos));
        });
    }

    /**
     * **Quitar un estudio de la solicitud** (O-29): la baja inmediata, fila por fila. No toca la observación ni el catálogo.
     *
     * @return int cuántas filas se borraron (0 si ese estudio ya no estaba)
     */
    public function quitar(Consulta $consulta, string $codigo, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $codigo = trim($codigo);

        if ($codigo === '') {
            return 0;
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $codigo, $cuando, $origen) {
            $filas = $this->filasDeLaConsulta($consulta)
                ->filter(fn (RadiologiaPaciente $fila) => trim((string) $fila->coderadio) === $codigo);

            foreach ($filas as $fila) {
                $this->borrarFila($fila, 'radiologia_paciente', $cuando, $origen);
            }

            return $filas->count();
        });
    }

    /**
     * **Guardar el «Resumen clínico»** (O-29): lo único de esta pantalla que sigue siendo un formulario con su botón.
     */
    public function guardarResumen(
        Consulta $consulta,
        ?string $observacion,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeOrden {
        $observacion = TextoDeLaConsulta::limpio($observacion);

        if ($observacion !== null && mb_strlen($observacion) > EstudiosDeLaConsulta::LARGO_OBSERVACION) {
            throw new InvalidArgumentException('El resumen clínico es demasiado largo.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $observacion, $cuando, $origen) {
            [$creada, $columnas, $bloqueadas] = $this->guardarObservacion($consulta, $observacion, $cuando, $origen);

            return new ResultadoDeOrden(0, 0, $creada, $columnas, $bloqueadas);
        });
    }

    /**
     * La observación de la consulta: la crea la primera vez y después corrige **sólo lo que cambió**.
     *
     * @return array{0:bool, 1:array<int,string>, 2:array<int,string>} si se creó la fila, las columnas escritas y las bloqueadas
     */
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

    /**
     * Las filas de la solicitud de esta consulta, en el orden de la hoja.
     *
     * @return \Illuminate\Support\Collection<int,RadiologiaPaciente>
     */
    private function filasDeLaConsulta(Consulta $consulta): \Illuminate\Support\Collection
    {
        return RadiologiaPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int,string>  $codigos
     * @return array<int,string>
     */
    private function normalizar(array $codigos): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($codigo) => trim((string) $codigo), $codigos),
            fn (string $codigo) => $codigo !== '',
        )));
    }

    /**
     * **La comprobación de catálogo, una sola vez**: cada código nuevo tiene que ser un estudio del catálogo del médico.
     *
     * @param  array<int,string>  $codigos
     *
     * @throws InvalidArgumentException
     */
    private function comprobarCatalogo(string $regMedico, array $codigos): void
    {
        if ($codigos === []) {
            return;
        }

        $delCatalogo = $this->catalogo->resolver($regMedico, true, $codigos);

        foreach ($codigos as $codigo) {
            if (! isset($delCatalogo[$codigo])) {
                throw new InvalidArgumentException('El estudio «' . $codigo . '» no está en tu catálogo de estudios.');
            }
        }
    }
}
