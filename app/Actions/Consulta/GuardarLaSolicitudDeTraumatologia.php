<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\Traumatologia\MapaDeLaOrdenDeIngreso;
use App\Clinica\Consulta\Traumatologia\MapaDelImplante;
use App\Models\Consulta;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * **Guardar la solicitud de implante y la orden de ingreso** (Traumatología, T3, 2026-11-10): una fila por consulta en
 * `implante` y otra en `orden_ingreso` (clave `historia` + `consulta`), **sólo lo que cambió** y con el rastro por columna
 * en `sync_changes` (el trait `EscribeConRastro`, el mismo de las demás solicitudes).
 *
 * Las casillas se guardan como **`1`/`0`**, que es lo que escribe el escritorio (son `char(1)`); los textos se recortan a
 * su ancho y una fila **sin nada cargado no se crea** (la regla de la plantilla ortopédica y de la web).
 */
final class GuardarLaSolicitudDeTraumatologia
{
    use EscribeConRastro;

    /**
     * La solicitud de implante (`implante`).
     *
     * @param  array<string,?string>  $datos  clave del mapa => valor del formulario
     * @return array{escribio:bool, fila:?Model}
     */
    public function implante(Consulta $consulta, array $datos, ?string $origen = 'web'): array
    {
        return $this->ejecutar(\App\Models\Implante::class, MapaDelImplante::TABLA, 'historia', MapaDelImplante::campos(), $consulta, $datos, $origen);
    }

    /**
     * La orden de ingreso (`orden_ingreso`).
     *
     * @param  array<string,?string>  $datos  clave del mapa => valor del formulario
     * @return array{escribio:bool, fila:?Model}
     */
    public function ordenDeIngreso(Consulta $consulta, array $datos, ?string $origen = 'web'): array
    {
        return $this->ejecutar(\App\Models\OrdenDeIngreso::class, MapaDeLaOrdenDeIngreso::TABLA, 'historia', MapaDeLaOrdenDeIngreso::campos(), $consulta, $datos, $origen);
    }

    /**
     * @param  class-string<Model>  $modelo
     * @param  array<int,array{clave:string, etiqueta:string, columna:string, tipo:string, max?:?int}>  $campos
     * @param  array<string,?string>  $datos
     * @return array{escribio:bool, fila:?Model}
     */
    private function ejecutar(string $modelo, string $tabla, string $columnaDeLaHistoria, array $campos, Consulta $consulta, array $datos, ?string $origen): array
    {
        $fila = $modelo::where('reg_medico', $consulta->reg_medico)
            ->where($columnaDeLaHistoria, (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->first();

        $nueva = $fila === null;

        $nuevos = [];

        foreach ($campos as $campo) {
            if (! array_key_exists($campo['clave'], $datos)) {
                continue;
            }

            $nuevos[$campo['columna']] = $this->normalizar($campo, $datos[$campo['clave']]);
        }

        // Sin nada cargado no se crea la fila vacía (la regla de la plantilla ortopédica): todas las casillas en `0`, los
        // textos vacíos y las fechas nulas es un formulario en blanco, no un dato.
        if ($nueva && $this->vacia($nuevos)) {
            return ['escribio' => false, 'fila' => null];
        }

        if ($nueva) {
            $datosDeLaFila = array_merge([
                'reg_medico' => $consulta->reg_medico,
                'historia' => (int) $consulta->numhistoria,
                'consulta' => (int) $consulta->nroconsulta,
            ], $nuevos);

            $fila = $origen === null
                ? $modelo::create($datosDeLaFila)
                : $this->crearFila($modelo, $tabla, $datosDeLaFila, Carbon::now(), $origen);

            return ['escribio' => true, 'fila' => $fila];
        }

        [$escritas] = $this->corregirFila($fila, $tabla, $nuevos, Carbon::now(), $origen);

        return ['escribio' => $escritas !== [], 'fila' => $fila];
    }

    /** ¿La fila quedó en blanco? (casillas en `0`, textos vacíos y fechas nulas). */
    private function vacia(array $valores): bool
    {
        foreach ($valores as $valor) {
            if ($valor !== null && $valor !== '' && $valor !== '0') {
                return false;
            }

            if ($valor === '1') {
                return false;
            }
        }

        return true;
    }

    private function normalizar(array $campo, ?string $valor): ?string
    {
        if ($campo['tipo'] === 'marca') {
            return in_array($valor, ['1', 'S', 's', 'on'], true) ? '1' : '0';
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        if ($campo['tipo'] === 'fecha') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) !== 1) {
                throw new InvalidArgumentException('La fecha «' . $texto . '» no tiene el formato AAAA-MM-DD.');
            }

            return $texto;
        }

        return mb_substr($texto, 0, (int) ($campo['max'] ?? 65000));
    }
}
