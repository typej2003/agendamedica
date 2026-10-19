<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\CamposDelMedicamento;
use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Vistas\ConValoresDeFormulario;
use App\Models\Vademecum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * La pantalla de **medicamentos** del catálogo (`/clinica/catalogos/medicamentos`, WEB-8.1a): el vademécum del
 * médico paginado, con su buscador, y el diálogo de alta y edición. Como todo ViewModel de la web clínica no consulta
 * la base: recibe la página ya leída y las URLs que resolvió el controlador.
 *
 * El diálogo es **uno** para alta y edición, y el formulario toma sus valores de `old()` (un `POST` que falló) o del
 * medicamento que se edita (`?editar=`). La baja es "quitar": el servidor decide si se puede (si el medicamento está
 * en uso no se borra y el aviso dice dónde), así que la pantalla sólo pide la confirmación.
 */
final class MedicamentosVista
{
    use ConValoresDeFormulario;

    /**
     * @param  string  $base  la URL de la pantalla sin parámetros
     * @param  int  $total  cuántos medicamentos tiene el vademécum (sin búsqueda): decide el estado vacío
     * @param  array<string,string>  $urls  `guardar` y `quitar` (una función de id, que arma el controlador con `route()`)
     */
    public function __construct(
        private LengthAwarePaginator $medicamentos,
        private string $buscar,
        private int $total,
        private ?Vademecum $editando,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    /** ¿El diálogo nace abierto? Sí al editar un medicamento o al venir de «Nuevo medicamento» (`?nuevo=1`). */
    public function abrirAlCargar(): bool
    {
        return $this->editando() || $this->abrirNuevo;
    }

    /** «Nuevo medicamento»: la misma lista, con el diálogo abierto y vacío (la búsqueda y la página se conservan). */
    public function urlNuevo(): string
    {
        return $this->url(['nuevo' => 1]);
    }

    // ----------------------------------------------------------------------------------------- Encabezado

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
        return $this->base;
    }

    /** La lista sin búsqueda: a dónde vuelve «Limpiar». */
    public function urlLimpiar(): string
    {
        return $this->base;
    }

    public function subtitulo(): string
    {
        if ($this->hayBusqueda()) {
            $total = $this->medicamentos->total();

            return ($total === 0 ? 'Sin resultados' : $total . ($total === 1 ? ' medicamento' : ' medicamentos'))
                . ' para «' . $this->buscar . '».';
        }

        return $this->total . ($this->total === 1 ? ' medicamento' : ' medicamentos')
            . ' en tu vademécum. Son los que aparecen al armar un récipe y en los tratamientos.';
    }

    // ------------------------------------------------------------------------------------------- La lista

    public function paginador(): LengthAwarePaginator
    {
        return $this->medicamentos;
    }

    public function haySobrantes(): bool
    {
        return $this->medicamentos->hasPages();
    }

    /** "Mostrando 1–25 de 870", o vacío cuando no hay filas. */
    public function rango(): string
    {
        if ($this->medicamentos->total() === 0) {
            return '';
        }

        return 'Mostrando ' . $this->medicamentos->firstItem() . '–' . $this->medicamentos->lastItem()
            . ' de ' . $this->medicamentos->total();
    }

    public function estaVacio(): bool
    {
        return $this->medicamentos->total() === 0;
    }

    /**
     * El estado vacío distingue "no hay nada todavía" de "tu búsqueda no encontró nada" (guía de UI §4).
     *
     * @return array{icono:string, titulo:string, texto:string}
     */
    public function vacio(): array
    {
        if ($this->hayBusqueda()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ningún medicamento coincide con «' . $this->buscar . '»',
                'texto' => 'Prueba con otro nombre comercial, el principio activo o la presentación. No importan las tildes.',
            ];
        }

        return [
            'icono' => 'bi-capsule',
            'titulo' => 'Tu vademécum todavía no tiene medicamentos',
            'texto' => 'Crea el primero: es el que vas a elegir al armar un récipe o un tratamiento.',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->medicamentos->items())->map(fn (Vademecum $fila) => $this->fila($fila))->all();
    }

    /** @return array<string,mixed> */
    private function fila(Vademecum $fila): array
    {
        $comercial = CamposDePaciente::texto($fila->nombrecomercial);
        $generico = CamposDePaciente::texto($fila->nombregenerico);
        $nombre = $comercial ?? $generico ?? 'Sin nombre';
        $codigo = trim((string) $fila->codemedicina);
        $uso = CamposDePaciente::texto($fila->uso);

        $marcas = array_values(array_filter([
            CamposDePaciente::texto($fila->nombrecomercial1),
            CamposDePaciente::texto($fila->nombrecomercial2),
            CamposDePaciente::texto($fila->nombrecomercial3),
        ], fn ($marca) => $marca !== null && $marca !== '-'));

        return [
            'id' => (int) $fila->id,
            'nombre' => $nombre,
            'presentacion' => CamposDePaciente::texto($fila->presentacion),
            'codigo' => $codigo,
            'generico' => $comercial !== null ? $generico : null,
            'marcas' => $marcas,
            'psicotropico' => $fila->sico === 'S',
            'uso' => $uso === null ? null : Str::limit(preg_replace('/\s+/u', ' ', $uso), 220),
            'urlEditar' => $this->url(['editar' => $fila->id]),
            'urlQuitar' => ($this->urls['quitar'])((int) $fila->id),
            'avisoQuitar' => '¿Quitar «' . $nombre . ($this->soloPresentacion($fila) ? '/' . $this->soloPresentacion($fila) : '') . '» '
                . 'del vademécum? Si está en algún récipe o tratamiento no se borra y te digo dónde.',
        ];
    }

    private function soloPresentacion(Vademecum $fila): ?string
    {
        return CamposDePaciente::texto($fila->presentacion);
    }

    /**
     * La lista con lo que hay que conservar (la búsqueda y la página) y lo que pida `$query` encima.
     *
     * @param  array<string,mixed>  $query
     */
    private function url(array $query = []): string
    {
        $parametros = array_filter(array_merge([
            'buscar' => $this->buscar,
            'page' => $this->medicamentos->currentPage() > 1 ? $this->medicamentos->currentPage() : null,
        ], $query), fn ($valor) => $valor !== null && $valor !== '');

        return $parametros === [] ? $this->base : $this->base . '?' . http_build_query($parametros);
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function urlGuardar(): string
    {
        return $this->urls['guardar'];
    }

    /** Cancelar vuelve a la misma lista (la búsqueda y la página se conservan). */
    public function urlCancelar(): string
    {
        return $this->url();
    }

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el medicamento' : 'Nuevo medicamento';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear el medicamento';
    }

    /** El código del medicamento que se edita (el alta lo da el servidor y no se muestra). */
    public function codigoEditando(): ?string
    {
        return $this->editando() ? trim((string) $this->editando->codemedicina) : null;
    }

    /** ¿Se tilda *Psicotrópico*? Lo que quedó de un `POST` que falló o lo que tiene el medicamento. */
    public function esPsicotropico(): bool
    {
        if (old('_catalogo') !== null) {
            return CamposDelMedicamento::psicotropico(old('sico')) === 'S';
        }

        return $this->editando() && $this->editando->sico === 'S';
    }

    /** El valor de un campo de texto del formulario: el del `old()` o el del medicamento que se edita. */
    public function texto(string $campo): string
    {
        $valor = $this->valor($campo);

        return $valor === null ? '' : (string) $valor;
    }

    /** ¿Hay otras marcas cargadas (o escritas)? Si no, el bloque de marcas arranca cerrado. */
    public function hayMarcas(): bool
    {
        return $this->texto('nombrecomercial1') !== '' || $this->texto('nombrecomercial2') !== '' || $this->texto('nombrecomercial3') !== '';
    }

    public function largoNombre(): int
    {
        return AgregarMedicamentoAlVademecum::LARGO_NOMBRE;
    }

    public function largoMarca(): int
    {
        return CamposDelMedicamento::LARGO_MARCA;
    }

    public function largoPresentacion(): int
    {
        return AgregarMedicamentoAlVademecum::LARGO_PRESENTACION;
    }

    public function largoTexto(): int
    {
        return AgregarMedicamentoAlVademecum::LARGO_USO;
    }

    /** ¿Hay algo cargado en la dosis por peso? Si sí, el bloque de la fórmula arranca abierto. */
    public function hayDosis(): bool
    {
        foreach (CamposDelMedicamento::NUMEROS as $campo) {
            if ($this->texto($campo) !== '' && $this->texto($campo) !== '0') {
                return true;
            }
        }

        return false;
    }

    /** ¿La dosificación dice `SI` (dosis por kilo, la marca que lee el récipe)? */
    public function esPorKilo(): bool
    {
        return mb_strtoupper(trim($this->texto('dosificacion'))) === 'SI';
    }
}
