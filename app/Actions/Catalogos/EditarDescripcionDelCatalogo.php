<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Pacientes\BusquedaDePacientes;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Editar la descripción de un catálogo de "código + texto"** (WEB-8.1b): diagnósticos, motivos de consulta y tipos
 * de examen. Es el guardado de `w_agregar_dianostico` / `w_agregar_motivos_consul` / `w_agregar_tipo_exameen` sobre un
 * código que ya existe: **el código no se edita** (en el escritorio el campo del código se deshabilita al cargar la fila,
 * `em_1.enabled = false`) y la descripción va en mayúsculas (`edit.case=upper` de los tres DataWindows).
 *
 * Escribe **sólo lo que cambió**, con el last-write-wins por columna (`RegistroDeCambios`) y un `updated` por columna en
 * `sync_changes` (`source = web`); la fila cambia su `updated_at`, que es lo que el delta del teléfono mira.
 *
 * ## Qué pasa con lo que ya se usó
 *
 * Una consulta resuelve el nombre **por el código** contra el catálogo: cambiar la descripción **cambia lo que ven el
 * historial y las impresiones** de las consultas viejas. Es lo esperado —es la misma cosa corregida— y no se hace nada
 * para impedirlo.
 *
 * ## No duplicar
 *
 * La descripción no puede ser la de **otra** fila del catálogo (sin distinguir tildes ni mayúsculas) en los registros
 * del médico. Un duplicado **histórico** que ya existe se puede editar mientras no se le cambie el nombre a otro que
 * exista, y quitarlo (si no tiene uso) es justamente cómo se limpia.
 */
abstract class EditarDescripcionDelCatalogo
{
    use EscribeConRastro;

    /** @return class-string<Model> */
    abstract protected function modelo(): string;

    abstract protected function tabla(): string;

    /** La columna del código (`codediagnostico`, `codemotivo`, `codetipo`). */
    abstract protected function columnaDelCodigo(): string;

    /** La columna del texto (`descripcion` o `tipo`). */
    abstract protected function columna(): string;

    abstract protected function largo(): int;

    /** Cómo se llama el ítem en los avisos: «diagnóstico», «motivo», «tipo de examen». */
    abstract protected function singular(): string;

    /**
     * @param  array<int,string>  $registros  los registros del médico, para comparar contra todo su catálogo
     *
     * @throws InvalidArgumentException si falta la descripción, pasa del largo de su columna o ya hay otra igual
     */
    public function ejecutar(
        Model $fila,
        array $registros,
        string $texto,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeEdicion {
        $nuevo = ReglasDelCatalogo::descripcion($texto, $this->largo(), $this->singular());
        $cuando = $this->ahora($cuando);
        $modelo = $this->modelo();

        return DB::transaction(function () use ($fila, $registros, $nuevo, $cuando, $origen, $modelo) {
            // Se relee la fila bajo candado: dos ediciones o una baja al mismo tiempo se turnan.
            $bloqueada = $modelo::whereKey($fila->getKey())->lockForUpdate()->firstOrFail();

            $this->noDuplicar($bloqueada, $registros, $nuevo);

            [$escritas, $bloqueadas] = $this->corregirFila($bloqueada, $this->tabla(), [$this->columna() => $nuevo], $cuando, $origen);

            return new ResultadoDeEdicion($bloqueada, $escritas, $bloqueadas);
        });
    }

    /** @param  array<int,string>  $registros */
    private function noDuplicar(Model $fila, array $registros, string $nuevo): void
    {
        $clave = fn ($texto) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $texto)));

        // El nombre no cambió (salvo mayúsculas o tildes de más): no hay duplicado nuevo que evitar.
        if ($clave($fila->getAttribute($this->columna())) === $clave($nuevo)) {
            return;
        }

        $modelo = $this->modelo();
        $otro = $modelo::whereIn('reg_medico', array_values(array_unique(array_merge($registros, [(string) $fila->reg_medico]))))
            ->where('id', '<>', $fila->getKey())
            ->get()
            ->first(fn (Model $candidato) => $clave($candidato->getAttribute($this->columna())) === $clave($nuevo));

        if ($otro !== null) {
            throw new InvalidArgumentException(
                'Ya tienes «' . trim((string) $otro->getAttribute($this->columna())) . '» en el catálogo (código '
                . trim((string) $otro->getAttribute($this->columnaDelCodigo())) . '). Edita ese o escribe otro nombre.'
            );
        }
    }
}
