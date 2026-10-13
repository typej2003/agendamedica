<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\HospitalizacionDeLaConsulta;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\Hospitalizacion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar la orden de hospitalización de una consulta** (WEB-5.3b, pestaña *Hospitalización*): el lugar a hospitalizar, el
 * diagnóstico provisional y las recomendaciones.
 *
 * **Una por consulta** (`hospitalizacion` se llavea por `nrohistoria` + `nroconsulta`): si la consulta ya tiene fila —casi siempre, la
 * vacía que el escritorio crea al abrirla— se **corrige en el lugar**, columna por columna y sólo lo que cambió; si no, se crea. Una
 * orden **sin ninguno de los tres textos** no es un documento (`QueEsUnDocumento::hospitalizacion`) y no se guarda: sería una fila
 * vacía más, de las que el escritorio ya llena la base. Para quitarla, `EliminarHospitalizacionDeLaConsulta`.
 *
 * **Sólo toca esas tres columnas.** `indiprev` y `habitacion` no las edita ninguna pantalla del escritorio (están vacías en toda la
 * base): quedan como estén.
 *
 * Deja su rastro en `sync_changes` (`source = web`): `created` si crea, `updated` por columna con el last-write-wins del sync, y
 * cambia `updated_at`. El móvil no baja esta tabla; el escritorio la sube fila por fila (tiene clave primaria).
 */
final class GuardarHospitalizacionDeLaConsulta
{
    use EscribeConRastro;

    public function __construct(private HospitalizacionDeLaConsulta $ordenes)
    {
    }

    /**
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si no hay ningún texto o alguno pasa de su tope
     */
    public function ejecutar(
        Consulta $consulta,
        ?string $hospital,
        ?string $diagnostico,
        ?string $recomendaciones,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        $hospital = TextoDeLaConsulta::limpio($hospital);
        $diagnostico = TextoDeLaConsulta::limpio($diagnostico);
        $recomendaciones = TextoDeLaConsulta::limpio($recomendaciones);

        if (! QueEsUnDocumento::hospitalizacion($hospital, $diagnostico, $recomendaciones)) {
            throw new InvalidArgumentException('La orden necesita algo que decir: el lugar a hospitalizar, el diagnóstico o las recomendaciones.');
        }

        if ($hospital !== null && mb_strlen($hospital) > HospitalizacionDeLaConsulta::LARGO_HOSPITAL) {
            throw new InvalidArgumentException('El lugar a hospitalizar admite hasta ' . HospitalizacionDeLaConsulta::LARGO_HOSPITAL . ' caracteres.');
        }

        foreach ([$diagnostico, $recomendaciones] as $texto) {
            if ($texto !== null && mb_strlen($texto) > HospitalizacionDeLaConsulta::LARGO_TEXTO) {
                throw new InvalidArgumentException('El texto de la orden es demasiado largo.');
            }
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $hospital, $diagnostico, $recomendaciones, $cuando, $origen) {
            $nuevos = ['hospital' => $hospital, 'diagprev' => $diagnostico, 'dieta' => $recomendaciones];
            $fila = $this->ordenes->fila($consulta);

            if ($fila === null) {
                $this->crearFila(Hospitalizacion::class, 'hospitalizacion', array_merge($nuevos, [
                    'reg_medico' => (string) $consulta->reg_medico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'hospitalizacion', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }
}
