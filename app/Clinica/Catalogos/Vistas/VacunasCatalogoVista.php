<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\GuardarVacunaDelCatalogo;
use App\Clinica\Vacunas\ConvencionDeVacunas;
use Closure;
use Illuminate\Support\Collection;

/**
 * La pantalla **«Vacunas»** del catálogo del médico (`/clinica/catalogos/vacunas`, WEB-7.4.4e): sus vacunas con su edad inicial, su tipo
 * (obligatoria / recomendada) y sus dosis; el alta y la edición en un diálogo; las dosis de una vacuna en otro; y el aviso con el botón
 * **«Cargar el catálogo inicial»** cuando hay vacunas del catálogo inicial que el médico todavía no tiene (las «novedades»).
 *
 * No consulta nada: recibe lo leído y las URLs que resolvió el controlador.
 */
final class VacunasCatalogoVista
{
    /**
     * @param  Collection<int,object>  $vacunas  las del médico (ya filtradas por la búsqueda), con `dosis` y `aplicadas`
     * @param  Collection<int,object>  $novedades  las vacunas iniciales que el médico no tiene (`NovedadesDeVacunas`)
     * @param  array<string,mixed>  $urls  `base`, `guardar`, `cargarInicial` y las funciones de id `quitar`, `dosis`, `guardarDosis`, `quitarDosis`
     */
    public function __construct(
        private Collection $vacunas,
        private int $total,
        private string $buscar,
        private Collection $novedades,
        private ?object $editando,
        private ?object $conDosis,
        private bool $abrirNueva,
        private array $urls,
        private ?int $dosisEditando = null,
    ) {
    }

    public function titulo(): string
    {
        return 'Vacunas';
    }

    public function subtitulo(): string
    {
        if ($this->buscar !== '') {
            $n = $this->vacunas->count();

            return ($n === 0 ? 'Sin resultados' : $n . ($n === 1 ? ' vacuna' : ' vacunas')) . ' para «' . $this->buscar . '».';
        }

        return $this->total . ($this->total === 1 ? ' vacuna' : ' vacunas')
            . '. Con ellas se arma el calendario de cada niño; las dosis de cada una las defines tú.';
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function hayBusqueda(): bool
    {
        return $this->buscar !== '';
    }

    public function urlBase(): string
    {
        return $this->urls['base'];
    }

    public function urlNueva(): string
    {
        return $this->urls['base'] . '?nuevo=1';
    }

    public function urlGuardar(): string
    {
        return $this->urls['guardar'];
    }

    public function urlCargarInicial(): string
    {
        return $this->urls['cargarInicial'];
    }

    public function estaVacio(): bool
    {
        return $this->vacunas->isEmpty();
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayBusqueda()) {
            return ['icono' => 'bi-search', 'titulo' => 'Sin resultados', 'texto' => 'No hay vacunas con esa búsqueda.'];
        }

        return ['icono' => 'bi-capsule', 'titulo' => 'Todavía no tienes vacunas', 'texto' => 'Carga el catálogo inicial para partir de una base, o crea tu primera vacuna.'];
    }

    // ------------------------------------------------------------------------------------------ Novedades

    public function hayNovedades(): bool
    {
        return $this->novedades->isNotEmpty();
    }

    /** El texto del aviso de novedades: distinto si el catálogo está vacío (primera carga) o si el admin sumó vacunas después. */
    public function textoDeNovedades(): string
    {
        $n = $this->novedades->count();
        $nombres = $this->novedades->take(4)->pluck('vacuna')->map(fn ($v) => trim((string) $v))->implode(', ')
            . ($n > 4 ? '…' : '');

        return $this->total === 0
            ? "El catálogo inicial trae {$n} " . ($n === 1 ? 'vacuna' : 'vacunas') . " con sus dosis ({$nombres}). Cárgalo para partir de una base y ajústalo a tu manera."
            : "Hay {$n} " . ($n === 1 ? 'vacuna nueva' : 'vacunas nuevas') . " en el catálogo inicial que todavía no tienes ({$nombres}). Cargarlas no cambia lo que ya tienes.";
    }

    // ------------------------------------------------------------------------------------------ Filas

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->vacunas->map(function ($v) {
            return [
                'id' => (int) $v->id,
                'codigo' => trim((string) $v->codi_vacuna),
                'vacuna' => trim((string) $v->vacuna),
                'cobertura' => trim((string) $v->cobertura) ?: null,
                'edad' => $v->edad_inicial_meses === null ? '—' : $v->edad_inicial_meses . ' ' . ($v->edad_inicial_meses == 1 ? 'mes' : 'meses'),
                'tipo' => ConvencionDeVacunas::fija($v->fija),
                'tipoTono' => strtoupper((string) $v->fija) === 'O' ? 'marca' : 'neutro',
                'sexo' => in_array(strtoupper((string) $v->sexo), ['M', 'F'], true) ? ConvencionDeVacunas::sexo($v->sexo) : null,
                'dosis' => $v->dosis->map(fn ($d) => ($d->dosis_descri ?: $d->dosis . 'ª dosis') . ($d->meses === null ? '' : ' · ' . rtrim(rtrim(number_format((float) $d->meses, 2, '.', ''), '0'), '.') . ' m'))->all(),
                'aplicadas' => (int) $v->aplicadas,
                'urlEditar' => $this->urls['base'] . '?editar=' . (int) $v->id,
                'urlDosis' => ($this->urls['dosis'])((int) $v->id),
                'urlQuitar' => ($this->urls['quitar'])((int) $v->id),
                'avisoQuitar' => '¿Quitar «' . trim((string) $v->vacuna) . '» de tu catálogo' . ($v->dosis->isNotEmpty() ? ' con sus ' . $v->dosis->count() . ' dosis' : '') . '?',
            ];
        })->all();
    }

    // ------------------------------------------------------------------------------------------ Diálogo de la vacuna

    public function abrirVacuna(): bool
    {
        return $this->editando !== null || $this->abrirNueva;
    }

    public function editando(): bool
    {
        return $this->editando !== null;
    }

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar la vacuna' : 'Nueva vacuna';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear la vacuna';
    }

    public function idEditando(): string
    {
        return $this->editando ? (string) $this->editando->id : '';
    }

    public function codigoEditando(): ?string
    {
        return $this->editando ? trim((string) $this->editando->codi_vacuna) : null;
    }

    public function valor(string $campo): string
    {
        $valor = $this->editando->{$campo} ?? null;

        return $valor === null ? '' : trim((string) $valor);
    }

    public function fijaSeleccionada(): string
    {
        return strtoupper($this->valor('fija')) ?: 'O';
    }

    public function sexoSeleccionado(): string
    {
        return strtoupper($this->valor('sexo')) ?: 'A';
    }

    public function largoNombre(): int
    {
        return GuardarVacunaDelCatalogo::LARGO_NOMBRE;
    }

    public function largoCobertura(): int
    {
        return GuardarVacunaDelCatalogo::LARGO_COBERTURA;
    }

    // ------------------------------------------------------------------------------------------ Diálogo de las dosis

    public function abrirDosis(): bool
    {
        return $this->conDosis !== null;
    }

    public function nombreConDosis(): string
    {
        return $this->conDosis ? trim((string) $this->conDosis->vacuna) : '';
    }

    public function urlGuardarDosis(): string
    {
        return $this->conDosis ? ($this->urls['guardarDosis'])((int) $this->conDosis->id) : '';
    }

    public function urlCerrarDosis(): string
    {
        return $this->urls['base'];
    }

    /** @return array<int,array{id:int, numero:int, rotulo:string, meses:string, urlEditar:string, urlQuitar:string}> */
    public function dosis(): array
    {
        if ($this->conDosis === null) {
            return [];
        }

        return $this->conDosis->dosis->map(fn ($d) => [
            'id' => (int) $d->id,
            'numero' => (int) $d->dosis,
            'rotulo' => trim((string) $d->dosis_descri) ?: '—',
            'meses' => $d->meses === null ? '—' : rtrim(rtrim(number_format((float) $d->meses, 2, '.', ''), '0'), '.'),
            'urlEditar' => ($this->urls['dosis'])((int) $this->conDosis->id) . '&dosis=' . (int) $d->id,
            'urlQuitar' => ($this->urls['quitarDosis'])((int) $d->id),
        ])->all();
    }

    /** El valor de un campo de la dosis que se edita (`dosis`, `dosis_descri`, `meses`), o vacío. */
    public function valorDeDosis(string $campo): string
    {
        if ($this->conDosis === null || $this->dosisEditando === null) {
            return '';
        }

        $fila = $this->conDosis->dosis->firstWhere('id', $this->dosisEditando);
        $valor = $fila->{$campo} ?? null;

        if ($valor === null) {
            return '';
        }

        return $campo === 'meses' ? rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.') : trim((string) $valor);
    }

    public function editandoDosis(): bool
    {
        return $this->dosisEditando !== null && $this->conDosis !== null && $this->conDosis->dosis->contains('id', $this->dosisEditando);
    }

    public function idDosisEditando(): string
    {
        return $this->editandoDosis() ? (string) $this->dosisEditando : '';
    }
}
