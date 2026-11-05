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
 *  - `guardarResultados($consulta, $resultados, $reportes)` (O-47, 2026-11-02): el **resultado** de cada examen y cuáles
 *    **salen en el informe** (`examen_paciente.resultado` y `.vinculante`), también con su botón y también sólo lo que cambió.
 *    La casilla «Reporte» viene **marcada por defecto** (2026-11-05): lo que se destilda se guarda como `'N'` y lo que queda
 *    marcado, como la `'S'` del escritorio.
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

    /** La letra con la que `examen_paciente.vinculante` dice que el examen **sale en el informe** (O-47). */
    public const REPORTE = 'S';

    /**
     * La letra con la que la casilla **destildada** dice que el examen **no sale en el informe** (2026-11-05). La casilla
     * viene marcada por defecto, así que hay que poder guardar el «no»: `NULL` ya significa «nunca se marcó» y el
     * escritorio sólo busca la `'S'`, así que `'N'` es inocuo para él.
     */
    public const NO_REPORTE = 'N';

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
     * **Guardar el resultado de los exámenes y cuáles salen en el informe** (O-47, 2026-11-02).
     *
     * Son las dos columnas que el escritorio usa para los resultados y que hasta ahora la web no tocaba:
     * `examen_paciente.resultado` (el texto corto, `varchar(30)`) y `vinculante` (`'S'` = sale en el informe; las cinco
     * hojas del escritorio filtran por esa letra). La pantalla del escritorio es
     * `ventana_consulta/d_resultados_examenes.srd`, con `update="examen_paciente"`.
     *
     * El formulario manda el mapa `codeexamen => resultado` y la lista de los códigos marcados. **Una fila que no viene en
     * el mapa no se toca**: así un envío parcial no borra resultados, y vaciar el campo (que llega como `''`) sí los deja
     * en `NULL`.
     *
     * Escribe **sólo lo que cambió**, columna por columna, con el rastro de siempre (`source = web` y el last-write-wins
     * del sync). Un código que no es de la orden de esta consulta se ignora: el formulario no puede escribir filas ajenas.
     *
     * @param  array<string,?string>  $resultados  `codeexamen` => texto del resultado
     * @param  array<int,string>  $reportes  los `codeexamen` marcados como «sale en el informe»
     *
     * @throws InvalidArgumentException si un resultado no entra en la columna
     */
    public function guardarResultados(
        Consulta $consulta,
        array $resultados,
        array $reportes = [],
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeOrden {
        $resultados = $this->resultadosNormalizados($resultados);
        $marcados = array_flip($this->normalizar($reportes));

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $resultados, $marcados, $cuando, $origen) {
            $filas = ExamenPaciente::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->orderBy('id')
                ->get();

            $columnas = [];
            $bloqueadas = [];

            foreach ($filas as $fila) {
                $codigo = trim((string) $fila->codeexamen);

                if (! array_key_exists($codigo, $resultados)) {
                    continue;
                }

                [$escritas, $sinEscribir] = $this->corregirFila($fila, 'examen_paciente', [
                    'resultado' => $resultados[$codigo],
                    // `'S'` o `'N'`: la casilla viene marcada por defecto (2026-11-05), así que destildarla se guarda
                    // como `'N'`. El `NULL` queda para lo que nunca se tocó —el escritorio y las órdenes viejas—, que la
                    // pantalla muestra marcado; el escritorio sólo busca la `'S'` y `NULL`/`'N'` le dan igual.
                    'vinculante' => isset($marcados[$codigo]) ? self::REPORTE : self::NO_REPORTE,
                ], $cuando, $origen);

                $columnas = array_merge($columnas, $escritas);
                $bloqueadas = array_merge($bloqueadas, $sinEscribir);
            }

            return new ResultadoDeOrden(
                0,
                0,
                false,
                array_values(array_unique($columnas)),
                array_values(array_unique($bloqueadas)),
            );
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

    /**
     * Los resultados que llegaron del formulario, recortados y con `''` → `null` (vaciar el campo borra el dato).
     *
     * La clave se conserva aunque el texto quede vacío: es lo que distingue «esta fila la mandó el formulario» de «esta
     * fila no vino» (y por eso una fila ausente no se toca).
     *
     * @param  array<string,?string>  $resultados
     * @return array<string,?string>
     *
     * @throws InvalidArgumentException
     */
    private function resultadosNormalizados(array $resultados): array
    {
        $limpios = [];

        foreach ($resultados as $codigo => $texto) {
            $codigo = trim((string) $codigo);

            if ($codigo === '') {
                continue;
            }

            $texto = trim((string) $texto);

            if (mb_strlen($texto) > LaboratorioDeLaConsulta::LARGO_RESULTADO) {
                throw new InvalidArgumentException(
                    'El resultado de un examen no puede tener más de ' . LaboratorioDeLaConsulta::LARGO_RESULTADO . ' caracteres.',
                );
            }

            $limpios[$codigo] = $texto === '' ? null : $texto;
        }

        return $limpios;
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
