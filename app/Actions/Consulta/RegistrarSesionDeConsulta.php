<?php

namespace App\Actions\Consulta;

use App\Models\Consulta;
use App\Models\ConsultaSesion;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * **El candado suave de la consulta**: registra o renueva que un dispositivo tiene la consulta abierta.
 *
 * Lo llama el navegador al abrir la consulta y luego cada ~60 s mientras la pantalla está a la vista
 * (`public/js/clinica/consulta-candado.js`). Es un **aviso, no un bloqueo**: la consulta sigue pudiéndose
 * editar desde cualquier dispositivo; esto sólo permite avisar «está activa en otro dispositivo» antes de que
 * dos pantallas se pisen lo escrito. Offline-first: si el navegador no llega al servidor, el candado no existe y
 * nada se bloquea.
 *
 * Reglas (una fila vigente por consulta):
 *  - Sin fila, o con la fila **vencida** (sin actividad en `VENTANA_SEGUNDOS`): este dispositivo queda como dueño.
 *  - Fila del **mismo dispositivo**: se renueva la actividad.
 *  - Fila **vigente de otro dispositivo**: sin `$tomar` se devuelve `conflicto` y no se cambia nada; con `$tomar`
 *    («Abrir aquí») este dispositivo toma el control. El anterior lo descubre en su siguiente latido (recibe
 *    `conflicto`) y avisa «Esta consulta se abrió en otro dispositivo».
 *
 * Quién puede registrar (sólo el médico del consultorio de la consulta) lo decide el controlador; esta Action no
 * toca nada fuera de `consulta_sesiones`.
 */
final class RegistrarSesionDeConsulta
{
    /** Cuánto tiempo sin latidos hasta que el candado deja de contar (3 minutos; el latido es de ~60 s). */
    public const VENTANA_SEGUNDOS = 180;

    public const OK = 'ok';
    public const CONFLICTO = 'conflicto';

    /**
     * @return array{estado:string, abierta_en:?string, ultima_actividad_en:?string}
     */
    public function ejecutar(
        Consulta $consulta,
        ?int $userId,
        string $dispositivoId,
        bool $tomar,
        CarbonInterface $ahora,
    ): array {
        try {
            $sesion = DB::transaction(fn () => $this->resolver($consulta, $userId, $dispositivoId, $tomar, $ahora));
        } catch (QueryException $excepcion) {
            // Dos pestañas abrieron la consulta a la vez y las dos intentaron crear la fila (índice único): la segunda
            // reintenta y ya encuentra la primera.
            $sesion = DB::transaction(fn () => $this->resolver($consulta, $userId, $dispositivoId, $tomar, $ahora));
        }

        return [
            'estado' => $sesion['estado'],
            'abierta_en' => $sesion['fila']->abierta_en?->toIso8601String(),
            'ultima_actividad_en' => $sesion['fila']->ultima_actividad_en?->toIso8601String(),
        ];
    }

    /**
     * @return array{estado:string, fila:ConsultaSesion}
     */
    private function resolver(Consulta $consulta, ?int $userId, string $dispositivoId, bool $tomar, CarbonInterface $ahora): array
    {
        $fila = ConsultaSesion::where('consulta_id', $consulta->id)->lockForUpdate()->first();

        if ($fila === null) {
            return ['estado' => self::OK, 'fila' => ConsultaSesion::create([
                'consulta_id' => $consulta->id,
                'reg_medico' => $consulta->reg_medico,
                'user_id' => $userId,
                'dispositivo_id' => $dispositivoId,
                'abierta_en' => $ahora,
                'ultima_actividad_en' => $ahora,
            ])];
        }

        $vigente = $fila->ultima_actividad_en !== null
            && $fila->ultima_actividad_en->gt($ahora->copy()->subSeconds(self::VENTANA_SEGUNDOS));

        if ($fila->dispositivo_id === $dispositivoId) {
            $fila->fill([
                'user_id' => $userId,
                'ultima_actividad_en' => $ahora,
                // Si el candado había vencido, esta es una apertura nueva.
                'abierta_en' => $vigente ? $fila->abierta_en : $ahora,
            ])->save();

            return ['estado' => self::OK, 'fila' => $fila];
        }

        if (! $vigente || $tomar) {
            $fila->fill([
                'reg_medico' => $consulta->reg_medico,
                'user_id' => $userId,
                'dispositivo_id' => $dispositivoId,
                'abierta_en' => $ahora,
                'ultima_actividad_en' => $ahora,
            ])->save();

            return ['estado' => self::OK, 'fila' => $fila];
        }

        return ['estado' => self::CONFLICTO, 'fila' => $fila];
    }
}
