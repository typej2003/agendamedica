<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * **Quitar un ítem de un catálogo clínico** (WEB-8.1b): se borra **si no tiene uso**; si lo tiene, no se borra y el
 * resultado dice dónde se usa. Es la regla de `QuitarMedicamentoDelCatalogo` (8.1a) escrita una vez para los cinco
 * catálogos nuevos (diagnósticos, motivos de consulta, exámenes, tipos de examen y médicos referidos); cada uno
 * sólo dice cuál es su modelo, su tabla y qué es "estar en uso" (`UsoDeUnItemDelCatalogo`).
 *
 * El escritorio borraba con un `DELETE` directo y sin comprobar nada (`ue_eliminar` de cada `w_agregar_*`): la consulta
 * seguía nombrando un código que ya no existía. La web no lo permite (decisión del usuario, 2026-10-11).
 *
 * ## La comprobación y el borrado van juntos
 *
 * Todo ocurre en **una transacción** y sobre la fila **bajo candado** (`lockForUpdate`): dos bajas o una edición al
 * mismo tiempo se turnan y la segunda ya no encuentra la fila. Lo que ningún candado de esta tabla puede impedir es que,
 * entre la comprobación y el `COMMIT`, otra pantalla guarde una fila clínica que nombre el código (esas tablas no
 * leen el catálogo bajo candado): esa fila **no pierde el dato**, porque el diagnóstico, el motivo y el examen de una
 * consulta guardan además su texto (o se muestran con su código), pero el catálogo ya no lo ofrecería. Es el mismo
 * límite que `QuitarMedicamentoDelCatalogo` y se acepta por la misma razón: quitar es poco frecuente.
 *
 * ## El rastro
 *
 * Un `deleted` con `table_name` = la tabla de la fila y su id: el pull del móvil (`eliminados`) lo usa para soltarla de
 * su copia local, que se llama igual en `motivos_consulta`, `diagnosticos` y `doctores` (el móvil no guarda `examenes` ni
 * `tipos_examenes`: ahí el `deleted` es sólo el rastro).
 */
abstract class QuitarDelCatalogo
{
    use EscribeConRastro;

    /** @return class-string<Model> */
    abstract protected function modelo(): string;

    abstract protected function tabla(): string;

    /** Dónde se usa la fila. @param  array<int,string>  $registros  los registros del médico */
    abstract protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo;

    /** Cómo se llama la fila en el aviso («DOLOR PELVICO», «PEREZ LUIS»). */
    abstract protected function nombre(Model $fila): string;

    /**
     * @param  array<int,string>  $registros  los registros del médico (para los usos que se miden por registros)
     */
    public function ejecutar(Model $fila, array $registros, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeBaja
    {
        $cuando = $this->ahora($cuando);
        $modelo = $this->modelo();

        return DB::transaction(function () use ($fila, $registros, $cuando, $origen, $modelo) {
            $bloqueada = $modelo::whereKey($fila->getKey())->lockForUpdate()->firstOrFail();
            $nombre = $this->nombre($bloqueada);
            $uso = $this->uso($bloqueada, $registros);

            if ($uso->enUso()) {
                return ResultadoDeBaja::enUso($nombre, $uso->descripcion());
            }

            $this->borrarFila($bloqueada, $this->tabla(), $cuando, $origen);

            return ResultadoDeBaja::quitado($nombre);
        });
    }
}
