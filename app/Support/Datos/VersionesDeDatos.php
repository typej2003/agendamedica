<?php

namespace App\Support\Datos;

/**
 * **El registro de los comandos de datos**, versionado como las migraciones.
 *
 * Hay pasos que no pueden vivir en una migración porque son comandos de datos (sembrar el catálogo
 * inicial, copiarlo a los médicos que ya existen) y hasta ahora se corrían a mano después de desplegar,
 * con el riesgo de olvidarlos. Acá se declaran en orden, cada paso nuevo es **una entrada más** y el
 * comando `datos:actualizar` corre sólo lo que le falta a la base.
 *
 * **Regla de oro: una versión publicada no se edita.** Si un comando cambió o hay un paso nuevo, va en la
 * versión siguiente. Editarla cambiaría lo que ya se corrió en los servidores, igual que editar una
 * migración desplegada.
 *
 * La versión alcanzada vive en `configuracion_api` con la clave `version_datos` (empieza en 0); este
 * registro no toca la base: `pendientes()` es una cuenta pura y se puede probar sin migrar nada.
 */
class VersionesDeDatos
{
    /**
     * Las versiones de datos publicadas, de menor a mayor, con sus comandos en el orden en que se corren.
     *
     * Cada entrada es una línea de comando de Artisan y admite argumentos y opciones:
     * `'pediatria:sembrar-referencia --reemplazar-vacunas'`, `'algo:comando --clave=valor posicional'`.
     */
    public const VERSIONES = [
        1 => [
            'catalogos:sembrar-iniciales',
            'catalogos:cargar-a-medicos',
        ],
        // Medicina interna (MI-1, 2026-10-28): la especialidad y sus módulos, y su catálogo inicial. Con `--reemplazar` la línea
        // es otra que la de la versión 1 (si fuera igual, el dedupe no la correría) y deja el catálogo de medicina interna
        // exactamente como su carpeta, que es nueva.
        2 => [
            'especialidades:sembrar',
            'catalogos:sembrar-iniciales --reemplazar=medicina-interna',
        ],
        // Motivos de cita generales (2026-10-29): «Primera vez» y «Consulta» para todas las especialidades, desde la carpeta `general`. Sólo ese catálogo, para no
        // volver a crear lo que el administrador haya quitado de los otros.
        3 => [
            'catalogos:sembrar-iniciales --solo=motivos-cita',
        ],
    ];

    /** Todas las versiones declaradas, con sus comandos en orden. */
    public function todas(): array
    {
        return static::VERSIONES;
    }

    /**
     * Las versiones que le faltan a una base que está en `$actual`, de menor a mayor.
     *
     * Devuelve `[version => [comandos]]` con los comandos **ya deduplicados**: uno que aparece en dos
     * versiones se corre una sola vez, en la primera. Una versión que sólo traía comandos repetidos queda
     * con `[]`, y eso es a propósito: el comando la marca como completada aunque no ejecute nada.
     *
     * @return array<int, list<string>>
     */
    public function pendientes(int $actual): array
    {
        $versiones = $this->todas();
        ksort($versiones, SORT_NUMERIC);

        $vistos = [];
        $pendientes = [];

        foreach ($versiones as $version => $comandos) {
            $version = (int) $version;

            $propios = [];

            foreach ((array) $comandos as $linea) {
                $linea = trim((string) $linea);

                if ($linea === '' || in_array($linea, $vistos, true)) {
                    continue;
                }

                $vistos[] = $linea;

                // Las versiones ya cumplidas no se devuelven, pero sus comandos sí cuentan para el
                // dedupe: si la versión 2 repite uno de la 1, ése ya se corrió y no se vuelve a correr.
                if ($version > $actual) {
                    $propios[] = $linea;
                }
            }

            if ($version > $actual) {
                $pendientes[$version] = $propios;
            }
        }

        return $pendientes;
    }

    /**
     * Separa una entrada del registro en el nombre del comando y los parámetros que espera `$this->call()`.
     *
     * `--bandera` queda en `true`; `--clave=valor`, con el valor; y los tokens sueltos son argumentos
     * posicionales, en orden (claves numéricas, que es como `ArrayInput` los recibe).
     *
     * @return array{comando: string, parametros: array<int|string, mixed>}
     */
    public function desglosar(string $linea): array
    {
        $partes = array_values(array_filter(
            array_map(fn ($parte) => trim((string) $parte), str_getcsv(trim($linea), ' ')),
            fn ($parte) => $parte !== ''
        ));

        $comando = (string) array_shift($partes);
        $parametros = [];
        $posicional = 0;

        foreach ($partes as $parte) {
            if (str_starts_with($parte, '--')) {
                $opcion = substr($parte, 2);

                if (str_contains($opcion, '=')) {
                    [$nombre, $valor] = explode('=', $opcion, 2);
                    $parametros['--' . $nombre] = $valor;
                } else {
                    $parametros['--' . $opcion] = true;
                }

                continue;
            }

            $parametros[$posicional++] = $parte;
        }

        return ['comando' => $comando, 'parametros' => $parametros];
    }
}
