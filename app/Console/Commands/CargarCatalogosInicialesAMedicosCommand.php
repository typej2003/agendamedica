<?php

namespace App\Console\Commands;

use App\Actions\Catalogos\CargarCatalogosInicialesAlMedico;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use App\Models\Medico;
use Illuminate\Console\Command;

/**
 * **Completar los catálogos iniciales de los médicos que ya tienen cuenta** (2026-10-28, pedido de la doctora Olivia): cuando un catálogo inicial se agrega
 * después —los motivos de consulta y los antecedentes de ginecología—, los médicos que ya existían no lo tienen. Este comando recorre los médicos (no los centros)
 * y les copia **sólo los catálogos pedidos y sólo donde están vacíos**, con la misma regla que la copia automática al asignar una especialidad
 * (`CargarCatalogosInicialesAlMedico`): no toca lo que el médico ya cargó y salta al que trabaja con el escritorio.
 *
 * Es **idempotente**: una segunda corrida no copia nada. `--simular` informa qué haría sin escribir (hace el trabajo y lo revierte).
 * Necesita el catálogo inicial ya sembrado (`php artisan catalogos:sembrar-iniciales`).
 */
class CargarCatalogosInicialesAMedicosCommand extends Command
{
    protected $signature = 'catalogos:cargar-a-medicos
        {--catalogo=* : catálogo a completar (por defecto motivos-consulta y antecedentes)}
        {--simular : informa qué copiaría, sin escribir nada}';

    protected $description = 'Completa los catálogos iniciales que les faltan a los médicos que ya tienen cuenta (sólo en los catálogos vacíos)';

    public function handle(CargarCatalogosInicialesAlMedico $cargar): int
    {
        $simular = (bool) $this->option('simular');
        $catalogos = array_values(array_filter((array) $this->option('catalogo'))) ?: ['motivos-consulta', 'antecedentes'];

        foreach ($catalogos as $slug) {
            if (! CatalogosIniciales::existe($slug)) {
                $this->error('«' . $slug . '» no es un catálogo con versión inicial. Los que se pueden cargar: ' . implode(', ', array_keys(CatalogosIniciales::todos())) . '.');

                return self::FAILURE;
            }
        }

        $this->line(($simular ? '<comment>Simulación</comment> — ' : '') . 'Catálogos: <info>' . implode(', ', $catalogos) . '</info>');

        $renglones = [];
        $medicos = 0;
        $filas = 0;

        Medico::query()
            ->where('es_centro', false)
            ->whereNotNull('reg_medico')
            ->where('reg_medico', '<>', '')
            ->chunkById(100, function ($lote) use ($cargar, $catalogos, $simular, &$renglones, &$medicos, &$filas) {
                foreach ($lote as $medico) {
                    $nombre = trim($medico->name . ' ' . $medico->lastname) . ' (' . $medico->reg_medico . ')';
                    $r = $cargar->ejecutar($medico, $catalogos, $simular);

                    if ($r['saltado'] !== null) {
                        $renglones[] = [$nombre, '—', 'saltado: ' . $r['saltado']];

                        continue;
                    }

                    $medicos++;
                    $filas += $r['filas'];

                    foreach ($catalogos as $slug) {
                        $titulo = CatalogosIniciales::de($slug)['titulo'];

                        if (isset($r['catalogos'][$slug])) {
                            $renglones[] = [$nombre, $titulo, ($simular ? 'copiaría ' : 'copió ') . $r['catalogos'][$slug] . ' fila(s)'];
                        } elseif (in_array($slug, $r['ya_tenia'], true)) {
                            $renglones[] = [$nombre, $titulo, 'ya tiene filas: no se toca'];
                        } else {
                            $renglones[] = [$nombre, $titulo, 'nada que copiar: el catálogo inicial no tiene filas para sus especialidades'];
                        }
                    }
                }
            });

        $this->table(['Médico', 'Catálogo', 'Resultado'], $renglones);
        $this->line(($simular ? 'Se copiarían' : 'Se copiaron') . ' <info>' . $filas . '</info> fila(s) a <info>' . $medicos . '</info> médico(s).');

        if ($simular) {
            $this->line('--simular: no se escribió nada.');
        }

        return self::SUCCESS;
    }
}
