<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\InformeDeLaConsulta;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Clinica\FechaDelConsultorio;
use App\Models\Consulta;
use App\Models\Informe;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * **Guardar el informe médico de una consulta** (WEB-5.3a, pestaña *Informe médico*): el *Para*, el texto y la fecha.
 *
 * **Un informe por consulta** (`informe` se llavea por `nrohistoria` + `nroconsulta`): si la consulta ya tiene fila —casi
 * siempre, la plantilla `.` que el escritorio crea al abrirla— se **corrige en el lugar**, columna por columna y sólo lo
 * que cambió; si no, se crea. El texto es **obligatorio** (`informe.descripcion` es `NOT NULL`) y no puede ser el `.` de
 * la plantilla ni quedar vacío: sería un informe que ni el móvil ni la historia cuentan como documento
 * (`QueEsUnDocumento::informe`). Para quitarlo, `EliminarInformeDeLaConsulta`.
 *
 * Las tres formas de escribirlo —libre, desde una plantilla o copiando un informe anterior— terminan acá con el mismo
 * texto en la mano: copiar y elegir una plantilla **llenan el editor** (lo hace `consulta-solicitudes.js`) y el guardado
 * sigue siendo el botón. La fecha, si no viene, es **hoy en el consultorio** (la plantilla del escritorio también se fecha
 * con el día en que se abre, `:3349`).
 *
 * Deja su rastro en `sync_changes` (`source = web`): `created` si crea, `updated` por columna con el last-write-wins del
 * sync, y cambia `updated_at` (el móvil baja `informe` por delta).
 */
final class GuardarInformeDeLaConsulta
{
    use EscribeConRastro;

    public const LARGO_MAXIMO = 20000;

    public function __construct(private InformeDeLaConsulta $informes)
    {
    }

    /**
     * @param  string|CarbonInterface|null  $fecha  el día del informe (`AAAA-MM-DD`); `null` = hoy en el consultorio
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si falta el texto, es el `.` de la plantilla, el *Para* pasa de 50 o la fecha no sirve
     */
    public function ejecutar(
        Consulta $consulta,
        ?string $para,
        ?string $descripcion,
        $fecha = null,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        $texto = TextoDeLaConsulta::limpio($descripcion);

        if (! QueEsUnDocumento::informe($texto)) {
            throw new InvalidArgumentException('El informe necesita su texto: escríbelo, elige una plantilla o copia un informe anterior.');
        }

        $para = TextoDeLaConsulta::limpio($para);

        if ($para !== null && mb_strlen($para) > InformeDeLaConsulta::LARGO_PARA) {
            throw new InvalidArgumentException('El «Para» admite hasta ' . InformeDeLaConsulta::LARGO_PARA . ' caracteres.');
        }

        $dia = $this->fecha($fecha);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $para, $texto, $dia, $cuando, $origen) {
            $nuevos = ['para' => $para, 'descripcion' => $texto, 'fe_cha' => $dia->toDateString()];
            $fila = $this->informes->fila($consulta);

            if ($fila === null) {
                $this->crearFila(Informe::class, 'informe', array_merge($nuevos, [
                    'reg_medico' => (string) $consulta->reg_medico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'informe', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /** @param string|CarbonInterface|null $fecha */
    private function fecha($fecha): Carbon
    {
        if ($fecha === null || $fecha === '') {
            return Carbon::instance(FechaDelConsultorio::hoy())->startOfDay();
        }

        try {
            $dia = Carbon::parse($fecha instanceof CarbonInterface ? $fecha->toDateString() : (string) $fecha)->startOfDay();
        } catch (Throwable $e) {
            throw new InvalidArgumentException('La fecha del informe no es válida.');
        }

        if ($dia->year < 1900 || $dia->year > 2200) {
            throw new InvalidArgumentException('La fecha del informe no es válida.');
        }

        return $dia;
    }
}
