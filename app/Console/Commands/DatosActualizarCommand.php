<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionApi;
use App\Support\Datos\VersionesDeDatos;
use Illuminate\Console\Command;
use Throwable;

/**
 * **Los pasos de datos que se corren una sola vez** (2026-10-28): como las migraciones, pero para comandos
 * (`catalogos:sembrar-iniciales`, `catalogos:cargar-a-medicos`…) que hasta ahora se corrían a mano después
 * de desplegar y se olvidaban.
 *
 * La versión vive en `configuracion_api` con la clave `version_datos` y empieza en 0. El comando lee la
 * versión, junta las entradas del registro (`VersionesDeDatos::pendientes()`) con versión mayor, las corre
 * en orden y deja anotada la última. **La unidad atómica es la versión**: si un comando de la versión N
 * falla, se corta ahí, la versión queda en la última que terminó entera y el comando sale con error; la
 * corrida siguiente reintenta desde esa versión. Los comandos de datos son idempotentes, así que reintentar
 * una versión entera es seguro.
 *
 * `--simular` muestra la versión y los comandos que correrían, sin ejecutar ni escribir nada.
 */
class DatosActualizarCommand extends Command
{
    protected $signature = 'datos:actualizar
        {--simular : muestra qué comandos correrían, sin ejecutar ni escribir nada}';

    protected $description = 'Corre los comandos de datos pendientes y anota la versión de datos alcanzada';

    public function __construct(private VersionesDeDatos $versiones)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $desde = ConfiguracionApi::entero('version_datos', 0);
        $pendientes = $this->versiones->pendientes($desde);

        if ($pendientes === []) {
            $this->line('Versión de datos: ' . $desde . '. No hay nada que actualizar.');

            return self::SUCCESS;
        }

        $simular = (bool) $this->option('simular');
        $total = array_sum(array_map('count', $pendientes));
        $renglones = [];
        $completada = $desde;

        foreach ($pendientes as $version => $comandos) {
            $version = (int) $version;

            foreach ($comandos as $linea) {
                $partes = $this->versiones->desglosar($linea);

                if ($simular) {
                    $renglones[] = [$version, $linea, 'simulado'];

                    continue;
                }

                try {
                    $codigo = (int) $this->call($partes['comando'], $partes['parametros']);
                    $error = null;
                } catch (Throwable $e) {
                    // Un comando que no existe no devuelve código de error: lanza. Se trata igual que un
                    // comando que falla, para cortar la versión y poder decirlo en la tabla.
                    $codigo = self::FAILURE;
                    $error = $e->getMessage();
                }

                if ($codigo !== self::SUCCESS) {
                    $renglones[] = [$version, $linea, 'error'];
                    $this->table(['Versión', 'Comando', 'Resultado'], $renglones);
                    $this->error('Falló «' . $linea . '»'
                        . ($error !== null ? ': ' . $error : ' (código ' . $codigo . ').'));
                    $this->line('Versión de datos: ' . $completada . '. Se cortó en la versión ' . $version
                        . '; la próxima corrida reintenta desde ahí.');

                    return self::FAILURE;
                }

                $renglones[] = [$version, $linea, 'ok'];
            }

            if (! $simular) {
                // Se guarda al cerrar la versión, no al final: si la siguiente falla, ésta ya quedó.
                ConfiguracionApi::fijar('version_datos', $version);
            }

            $completada = $version;
        }

        if ($renglones !== []) {
            $this->table(['Versión', 'Comando', 'Resultado'], $renglones);
        }

        $this->line('Versión de datos: ' . $desde . ' → ' . $completada
            . ' (' . $total . ' ' . ($total === 1 ? 'comando' : 'comandos') . ').');

        if ($simular) {
            $this->line('--simular: no se ejecutó nada ni se escribió la versión.');
        }

        return self::SUCCESS;
    }
}
