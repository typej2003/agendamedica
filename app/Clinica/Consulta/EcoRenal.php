<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\UltraRenal;

/**
 * **Eco renal** (nefrología pediátrica, 2026-10-19): la ventana `w_cargar_visualizar_renal.srw` con su DataWindow
 * `d_ultra_rinom.srd`, que el escritorio abre desde el panel de **Indicadores incidentes** (`tabpage_35`).
 *
 * Una fila por consulta en **`ultra_renal`** (clave `historia` + `consulta`), con el mismo juego de medidas para los
 * dos riñones: `rd_*` el derecho y `ri_*` el izquierdo (longitud, transverso, anteroposterior, características y
 * medidas del complejo, hallazgos —LOE, litiasis, dilatación—), más el texto básico, la conclusión, la sugerencia,
 * los dos médicos, el motivo y los datos del equipo.
 */
final class EcoRenal
{
    public const TABLA = 'ultra_renal';

    /** Los datos del equipo y del estudio, que van al principio. */
    private const CABECERA = [
        'fecha'          => ['etiqueta' => 'Fecha', 'clase' => 'fecha'],
        'tex_basico'     => ['etiqueta' => 'Texto básico', 'clase' => 'parrafo'],
        'motivo_estudio' => ['etiqueta' => 'Motivo del estudio', 'clase' => 'texto'],
        'equipo'         => ['etiqueta' => 'Equipo', 'clase' => 'texto'],
        'transductor'    => ['etiqueta' => 'Transductor', 'clase' => 'texto'],
        'frecuencia'     => ['etiqueta' => 'Frecuencia', 'clase' => 'texto'],
    ];

    /** El cierre del informe. */
    private const CIERRE = [
        'conlusion'   => ['etiqueta' => 'Conclusión', 'clase' => 'parrafo'],
        'sugerencia'  => ['etiqueta' => 'Sugerencia', 'clase' => 'parrafo'],
        'medico_1'    => ['etiqueta' => 'Médico 1', 'clase' => 'texto'],
        'medico_2'    => ['etiqueta' => 'Médico 2', 'clase' => 'texto'],
    ];

    /** Las medidas de cada riñón: el sufijo de la columna y su rótulo (las dos usan el mismo juego). */
    private const POR_RINON = [
        'longitud'            => 'Longitud',
        'transverso'          => 'Transverso',
        'anteroporteior'      => 'Anteroposterior',
        'c_caracteristicas'   => 'Características del complejo',
        'c_medidas'           => 'Medidas del complejo',
        'ha_loe_ubicacion'    => 'LOE: ubicación',
        'ha_loe_medida'       => 'LOE: medida',
        'ha_loe_medida_o'     => 'LOE: otra medida',
        'ha_litiasis_numero'  => 'Litiasis: número',
        'ha_litiasis_ubicacion' => 'Litiasis: ubicación',
        'ha_litiasis_medida'  => 'Litiasis: medida',
        'ha_litiasis_medida_o' => 'Litiasis: otra medida',
        'ha_dilatacion_sc'    => 'Dilatación (s/c)',
    ];

    /** Los dos riñones: prefijo de la columna y cómo se llama. */
    private const RINONES = ['rd_' => 'Riñón derecho', 'ri_' => 'Riñón izquierdo'];

    /**
     * Los campos, con su grupo: `clave => [etiqueta, columna, clase, grupo]`. El orden es el de la pantalla.
     *
     * @var array<string,array{etiqueta:string, columna:string, clase:string, grupo:string}>|null
     */
    private static ?array $campos = null;

    /** @return array<string,array{etiqueta:string, columna:string, clase:string, grupo:string}> */
    public static function campos(): array
    {
        if (self::$campos !== null) {
            return self::$campos;
        }

        $campos = [];

        foreach (self::CABECERA as $columna => $meta) {
            $campos[$columna] = $meta + ['columna' => $columna, 'grupo' => 'Estudio'];
        }

        foreach (self::RINONES as $prefijo => $rinon) {
            foreach (self::POR_RINON as $sufijo => $etiqueta) {
                $columna = $prefijo . $sufijo;
                $campos[$columna] = [
                    'etiqueta' => $etiqueta,
                    'columna'  => $columna,
                    'clase'    => 'texto',
                    'grupo'    => $rinon,
                ];
            }
        }

        foreach (self::CIERRE as $columna => $meta) {
            $campos[$columna] = $meta + ['columna' => $columna, 'grupo' => 'Conclusiones'];
        }

        return self::$campos = $campos;
    }

    /** @return array<int,string> */
    public static function columnas(): array
    {
        return array_values(array_column(self::campos(), 'columna'));
    }

    /** Los grupos del formulario, en orden: `grupo => [clave => etiqueta]`. @return array<string,array<string,string>> */
    public static function grupos(): array
    {
        $grupos = [];

        foreach (self::campos() as $clave => $campo) {
            $grupos[$campo['grupo']][$clave] = $campo['etiqueta'];
        }

        return $grupos;
    }

    /**
     * Los valores guardados, por clave (la fecha en `Y-m-d`).
     *
     * @return array<string,?string>
     */
    public function de(Consulta $consulta): array
    {
        $fila = $this->fila($consulta);
        $valores = [];

        foreach (self::campos() as $clave => $campo) {
            if ($campo['clase'] === 'fecha') {
                $valores[$clave] = $fila?->fecha?->format('Y-m-d');

                continue;
            }

            $valor = $fila?->{$campo['columna']};
            $valores[$clave] = $valor === null ? null : (string) $valor;
        }

        return $valores;
    }

    public function fila(Consulta $consulta): ?UltraRenal
    {
        return UltraRenal::where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->first();
    }

    public function filaParaEscribir(Consulta $consulta): UltraRenal
    {
        return $this->fila($consulta) ?? UltraRenal::create([
            'reg_medico' => $consulta->reg_medico,
            'historia'   => (int) $consulta->numhistoria,
            'consulta'   => (int) $consulta->nroconsulta,
        ]);
    }

    public function tieneDatos(Consulta $consulta): bool
    {
        return array_filter($this->de($consulta), fn ($valor) => $valor !== null && $valor !== '') !== [];
    }
}
