<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\LaboratorioDeLaConsulta;
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
 * ## Tres escrituras, una por caso (O-29)
 *
 * La pantalla se pinta como *Motivos de consulta*: el catálogo a la vista con su «Pasar» por fila, y **agregar y quitar son
 * inmediatos** (una escritura por clic, con su rastro `source = web` y el last-write-wins del sync). Por eso la Action tiene:
 *
 *  - `agregar($consulta, $codigos)`: da de alta **uno o varios** exámenes del catálogo del médico, sin tocar los que ya estaban ni la
 *    observación. Un examen que ya está en la consulta no se duplica (la clave del escritorio es `nrohistoria` + `nroconsulta` +
 *    `codeexamen`).
 *  - `quitar($consulta, $codigo)`: da de baja **ese** examen y sólo ése. Es lo que hace que un clic en la web no se lleve las filas que
 *    el escritorio cargó y el médico no tocó: la tabla se sube **por fila** (trampa 75) y no hay ningún «lo marcado es lo que queda»
 *    en el camino de la web.
 *  - `guardarResumen($consulta, $observacion)`: escribe **sólo** el «Resumen clínico», que sigue siendo un formulario con su botón.
 *
 * Las tres reusan la comprobación de catálogo (`CatalogoParaPedirExamenes::resolver`) y el rastro (`EscribeConRastro`).
 *
 * ## Lo que ya no existe: «lo marcado es lo que queda»
 *
 * Hasta O-29 esta Action guardaba la orden **entera** en un `POST`: creaba los que faltaban y **borraba los que no venían**. Con altas y
 * bajas inmediatas esa semántica no puede convivir (un guardado de la web se llevaría filas que el escritorio cargó y el médico no tocó),
 * así que se fue: hoy cada examen entra y sale por su propio clic. La orden de laboratorio de la **pareja** (WEB-11.21) tiene su propia
 * Action (`GuardarOrdenDeLaPareja`) y su propia tabla, y no pasa por acá.
 *
 * Un examen **nuevo** tiene que estar en el catálogo de laboratorio del médico (`examenes` sin el tipo `002`); uno que ya estaba en la
 * consulta se acepta aunque su código ya no esté en el catálogo, para no obligar a quitarlo.
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
     * **Agregar exámenes a la orden** (O-29): el alta inmediata de un clic en el catálogo (o de una rutina completa).
     *
     * @param  array<int,string>  $codigos  los `codeexamen` que se agregan
     *
     * @throws InvalidArgumentException si un código no es del catálogo de laboratorio del médico
     */
    public function agregar(
        Consulta $consulta,
        array $codigos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeOrden {
        $codigos = $this->normalizar($codigos);

        if ($codigos === []) {
            throw new InvalidArgumentException('Elige el examen que quieres agregar a la orden.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $codigos, $cuando, $origen) {
            $regMedico = (string) $consulta->reg_medico;
            $estaban = $this->existentes($consulta);
            $nuevos = array_values(array_diff($codigos, $estaban));

            $this->comprobarCatalogo($regMedico, $nuevos);

            foreach ($nuevos as $codigo) {
                $this->crearFila(ExamenPaciente::class, 'examen_paciente', [
                    'reg_medico' => $regMedico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                    'codeexamen' => $codigo,
                ], $cuando, $origen);
            }

            return new ResultadoDeOrden(count($nuevos));
        });
    }

    /**
     * **Quitar un examen de la orden** (O-29): la baja inmediata, fila por fila. No toca la observación ni el catálogo.
     *
     * @return int cuántas filas se borraron (0 si ese examen ya no estaba)
     */
    public function quitar(Consulta $consulta, string $codigo, ?CarbonInterface $cuando = null, ?string $origen = 'web'): int
    {
        $codigo = trim($codigo);

        if ($codigo === '') {
            return 0;
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $codigo, $cuando, $origen) {
            $filas = ExamenPaciente::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->where('codeexamen', $codigo)
                ->orderBy('id')
                ->get();

            foreach ($filas as $fila) {
                $this->borrarFila($fila, 'examen_paciente', $cuando, $origen);
            }

            return $filas->count();
        });
    }

    /**
     * **Guardar el «Resumen clínico»** (O-29): lo único de esta pantalla que sigue siendo un formulario con su botón.
     *
     * No toca los exámenes: los agrega y los quita cada clic del catálogo.
     */
    public function guardarResumen(
        Consulta $consulta,
        ?string $observacion,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeOrden {
        $observacion = TextoDeLaConsulta::limpio($observacion);

        if ($observacion !== null && mb_strlen($observacion) > LaboratorioDeLaConsulta::LARGO_OBSERVACION) {
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

    /**
     * Los códigos que la consulta ya tiene, normalizados y sin repetir.
     *
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

    /** @return array<int,string> */
    private function existentes(Consulta $consulta): array
    {
        return ExamenPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->pluck('codeexamen')
            ->map(fn ($codigo) => trim((string) $codigo))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * **La comprobación de catálogo, una sola vez**: cada código nuevo tiene que ser un examen de laboratorio del médico.
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

        $delCatalogo = $this->catalogo->resolver($regMedico, false, $codigos);

        foreach ($codigos as $codigo) {
            if (! isset($delCatalogo[$codigo])) {
                throw new InvalidArgumentException('El examen «' . $codigo . '» no está en tu catálogo de laboratorio.');
            }
        }
    }
}
