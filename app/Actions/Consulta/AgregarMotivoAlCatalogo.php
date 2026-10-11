<?php

namespace App\Actions\Consulta;

use App\Models\Medico;
use App\Models\MotivoConsulta;
use InvalidArgumentException;

/**
 * **Alta de un motivo en el catálogo de motivos de consulta del médico** (ROADMAP.md Paso 18.B2).
 *
 * Es la regla que estaba adentro de `App\Services\CatalogoMotivosConsulta` y que usaba **sólo** el
 * endpoint online-only del app (`POST /app/motivos-consulta`). Se extrajo acá al hacer **WEB-4.2**,
 * cuando el buscador de motivos de la consulta pasó a ofrecer "crear el que falta" (PLAN-WEB.md §3.15,
 * decidido el 2026-10-10): la regla es la misma para el teléfono y para la web —el código correlativo de
 * cuatro dígitos del legado, la descripción en mayúsculas y **sin duplicar descripciones**—, y una regla
 * de negocio no se escribe dos veces (PLAN-WEB.md, R3).
 *
 * **Online-only por decisión de producto**: crear un motivo es poco frecuente (al inicio se cargan
 * varios y después casi siempre se reusan), así que no viaja por la cola de sync: la superficie espera
 * la respuesta y guarda el motivo con su id y su código reales. Agregar un motivo **a una consulta** sí
 * es offline y es otra Action (`AgregarMotivoALaConsulta`).
 *
 * Límite conocido (heredado del sync): dos altas *distintas* en el mismo instante podrían calcular el
 * mismo código —la tabla no tiene unicidad por `(reg_medico, codemotivo)` y el legado podría tener
 * repetidos que impidan agregarla—. Crear motivos es poco frecuente, así que el riesgo es muy bajo.
 */
final class AgregarMotivoAlCatalogo
{
    /** El largo del `descripcion` del catálogo del legado (`motivos_consulta.descripcion`). */
    public const LARGO_MAXIMO = 40;

    /**
     * @param  Medico  $medico  el médico **de la cuenta** que está creando el motivo (de ahí sale el
     *                          `reg_medico` principal con el que se guarda la fila)
     * @param  array<int,string>  $registrosMedicos  los registros del tenant, para no duplicar un motivo
     *                                               que ya existe en cualquiera de ellos
     * @return array{0: MotivoConsulta, 1: bool} el motivo y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si la descripción está vacía o es muy larga, o si el catálogo está lleno
     */
    public function ejecutar(Medico $medico, array $registrosMedicos, string $descripcion): array
    {
        $descripcion = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $descripcion)));

        if ($descripcion === '') {
            throw new InvalidArgumentException('El motivo no tiene descripción.');
        }
        if (mb_strlen($descripcion) > self::LARGO_MAXIMO) {
            throw new InvalidArgumentException('La descripción del motivo no puede pasar de ' . self::LARGO_MAXIMO . ' caracteres.');
        }

        $catalogo = MotivoConsulta::whereIn('reg_medico', $registrosMedicos)->get();

        $existente = $catalogo->first(fn (MotivoConsulta $m) => mb_strtoupper(trim($m->descripcion)) === $descripcion);
        if ($existente) {
            return [$existente, false];
        }

        $siguiente = ((int) $catalogo->pluck('codemotivo')
            ->filter(fn ($c) => is_numeric($c))
            ->map(fn ($c) => (int) $c)
            ->max()) + 1;
        if ($siguiente > 9999) {
            throw new InvalidArgumentException('El catálogo de motivos está lleno.');
        }

        $motivo = MotivoConsulta::create([
            'reg_medico' => $medico->regMedicoPrincipal(),
            'codemotivo' => str_pad((string) $siguiente, 4, '0', STR_PAD_LEFT),
            'descripcion' => $descripcion,
        ]);

        return [$motivo, true];
    }
}
