<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\CatalogoParaPedirExamenes;
use App\Clinica\Consulta\LaboratorioDeLaPareja;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\ExamenPareja;
use App\Models\ExamenParejaDatos;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar la orden de laboratorio de la pareja** (WEB-11.21): la C.I. y el nombre de la pareja, los exámenes que se le piden y el
 * «Resumen clínico». Se guarda **entera** como la orden de la paciente (`GuardarOrdenDeLaboratorio`): los exámenes que ya estaban se dejan,
 * los que faltan se crean y los que sobran se borran; la C.I. y el nombre se copian en cada fila (como el escritorio, que los repite en
 * `examen_pareja`) y viven además en `examen_pareja_datos`, para que la orden se recargue completa aunque todavía no tenga exámenes.
 *
 * Reglas (las del escritorio, `w_consulta_examen_pareja.srw`): **sin C.I. y nombre no hay orden** («Cargue los datos de la pareja») y un
 * examen nuevo tiene que estar en el catálogo de laboratorio del médico. Lo comparte el móvil (R3 de PLAN-WEB.md).
 *
 * Deja su rastro en `sync_changes` (`source = web`) por cada fila creada, corregida o borrada.
 */
final class GuardarOrdenDeLaPareja
{
    use EscribeConRastro;

    public function __construct(private LaboratorioDeLaPareja $ordenes, private CatalogoParaPedirExamenes $catalogo)
    {
    }

    /**
     * @param  array<int,string>  $codigos  los `codeexamen` que debe tener la orden
     *
     * @throws InvalidArgumentException si faltan los datos de la pareja, un campo es demasiado largo o un examen nuevo no es del catálogo
     */
    public function ejecutar(
        Consulta $consulta,
        array $codigos,
        ?string $cedula,
        ?string $nombre,
        ?string $observacion,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeOrden {
        $codigos = array_values(array_unique(array_filter(array_map(fn ($codigo) => trim((string) $codigo), $codigos), fn (string $codigo) => $codigo !== '')));
        $cedula = TextoDeLaConsulta::limpio($cedula);
        $nombre = TextoDeLaConsulta::limpio($nombre);
        $observacion = TextoDeLaConsulta::limpio($observacion);

        if ($cedula === null || $nombre === null) {
            throw new InvalidArgumentException('Carga los datos de la pareja: su cédula y su nombre.');
        }

        if (mb_strlen($cedula) > LaboratorioDeLaPareja::LARGO_CEDULA || mb_strlen($nombre) > LaboratorioDeLaPareja::LARGO_NOMBRE) {
            throw new InvalidArgumentException('La cédula o el nombre de la pareja son demasiado largos.');
        }

        if ($observacion !== null && mb_strlen($observacion) > LaboratorioDeLaPareja::LARGO_OBSERVACION) {
            throw new InvalidArgumentException('El resumen clínico es demasiado largo.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $codigos, $cedula, $nombre, $observacion, $cuando, $origen) {
            $regMedico = (string) $consulta->reg_medico;
            $historia = (int) $consulta->numhistoria;
            $nroconsulta = (int) $consulta->nroconsulta;

            $filas = ExamenPareja::where('reg_medico', $regMedico)
                ->where('nrohistoria', $historia)->where('nroconsulta', $nroconsulta)
                ->orderBy('id')->get();
            $estaban = $filas->map(fn (ExamenPareja $fila) => trim((string) $fila->codeexamen))->unique()->all();

            $nuevos = array_values(array_diff($codigos, $estaban));
            $delCatalogo = $this->catalogo->resolver($regMedico, false, $nuevos);

            foreach ($nuevos as $codigo) {
                if (! isset($delCatalogo[$codigo])) {
                    throw new InvalidArgumentException('El examen «' . $codigo . '» no está en tu catálogo de laboratorio.');
                }
            }

            foreach ($nuevos as $codigo) {
                $this->crearFila(ExamenPareja::class, 'examen_pareja', [
                    'reg_medico' => $regMedico,
                    'nrohistoria' => $historia,
                    'nroconsulta' => $nroconsulta,
                    'codeexamen' => $codigo,
                    'cedula' => $cedula,
                    'nombre' => $nombre,
                ], $cuando, $origen);
            }

            $quitados = 0;
            $columnas = [];
            $bloqueadas = [];

            foreach ($filas as $fila) {
                if (! in_array(trim((string) $fila->codeexamen), $codigos, true)) {
                    $this->borrarFila($fila, 'examen_pareja', $cuando, $origen);
                    $quitados++;

                    continue;
                }

                // Los que se quedan llevan la C.I. y el nombre vigentes (el escritorio los repite en cada fila).
                [$escritas, $noEscritas] = $this->corregirFila($fila, 'examen_pareja', ['cedula' => $cedula, 'nombre' => $nombre], $cuando, $origen);
                $columnas = array_merge($columnas, $escritas);
                $bloqueadas = array_merge($bloqueadas, $noEscritas);
            }

            $datos = $this->ordenes->filaDeDatos($consulta);

            if ($datos === null) {
                $this->crearFila(ExamenParejaDatos::class, 'examen_pareja_datos', [
                    'reg_medico' => $regMedico,
                    'nrohistoria' => $historia,
                    'nroconsulta' => $nroconsulta,
                    'cedula' => $cedula,
                    'nombre' => $nombre,
                    'observacion' => $observacion,
                ], $cuando, $origen);
                $creada = true;
            } else {
                [$escritas, $noEscritas] = $this->corregirFila($datos, 'examen_pareja_datos', [
                    'cedula' => $cedula, 'nombre' => $nombre, 'observacion' => $observacion,
                ], $cuando, $origen);
                $columnas = array_merge($columnas, $escritas);
                $bloqueadas = array_merge($bloqueadas, $noEscritas);
                $creada = false;
            }

            return new ResultadoDeOrden(count($nuevos), $quitados, $creada, array_values(array_unique($columnas)), array_values(array_unique($bloqueadas)));
        });
    }
}
