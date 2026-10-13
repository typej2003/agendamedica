<?php

namespace App\Clinica\Consulta\Vistas;

use App\Models\MotivoConsulta;
use Illuminate\Support\Collection;

/**
 * **Los motivos de consulta de una consulta** (WEB-4.2, ítem 1 del panel del legado): lo que la pantalla
 * muestra —los motivos cargados, el buscador del catálogo y el alta online del que falta—, ya resuelto.
 *
 * Como todo ViewModel de la web clínica, **no consulta la base**: recibe el catálogo, los motivos de la
 * consulta y las URLs ya leídos por `App\Clinica\Consulta\MotivosDeLaConsulta` y el controlador, y
 * devuelve los textos y banderas que la vista pinta. Se prueba sin base de datos
 * (`tests/Unit/Clinica/`).
 *
 * Tres cosas que decide acá y no en la vista:
 *
 *  - **Cuál de los motivos buscados ya está en la consulta** (`yaEsta`): la fila de un motivo que la
 *    consulta ya tiene se muestra como "Ya está en la consulta" y no ofrece agregarlo. Es la misma
 *    comparación por **código** que hace la Action al agregar, así que la pantalla no promete una fila
 *    que el servidor va a rechazar como repetida.
 *  - **El aviso de conexión del alta** (ver `avisoDeConexion()`): crear un motivo nuevo es online-only
 *    (decisión de producto: es poco frecuente y el servidor tiene que darle el código del legado).
 *  - **La ayuda del buscador**, que cambia según lo que haya en el catálogo y en la consulta.
 */
final class SeccionDeMotivosVista
{
    /** El mismo tope que valida `App\Actions\Consulta\AgregarMotivoAlCatalogo` (la columna del legado). */
    public const LARGO_MAXIMO = 40;

    /**
     * @param  Collection<int,MotivoConsulta>  $catalogo  un motivo por nombre, ya filtrado por la búsqueda
     * @param  Collection<int,object>  $cargados  los motivos de la consulta, ya resueltos por catálogo
     * @param  string  $buscar  lo que se escribió en el buscador (llega por `?buscar=` para que el
     *                          resultado se pueda recargar y el botón "Volver" funcione)
     * @param  string|null  $urlBuscar  el endpoint JSON del buscador
     * @param  string  $urlAgregar  a dónde postea "Agregar" (el código va como `codemotivo`)
     * @param  callable(int):string  $urlQuitar  arma la URL de "Quitar" para el id de una fila: la ruta
     *                                           lleva **dos** parámetros (la consulta y el motivo) y el
     *                                           id de la fila no está en la URL del panel, así que no se
     *                                           puede concatenar (`route('…', [$consulta, 0])` deja un
     *                                           `0` que después hay que reemplazar, y eso rompe la ruta)
     * @param  string  $urlCrear  a dónde postea el alta del motivo nuevo
     * @param  bool  $crearAbierto  el diálogo de "crear" se abre solo (un error de validación deja abierto
     *                              el formulario en vez de mandar al médico a buscarlo de nuevo)
     */
    public function __construct(
        private Collection $catalogo,
        private Collection $cargados,
        private string $buscar = '',
        private ?string $urlBuscar = null,
        private string $urlAgregar = '',
        private $urlQuitar = null,
        private string $urlCrear = '',
        private bool $crearAbierto = false,
    ) {
    }

    // ------------------------------------------------------------------------------------ La consulta

    /** ¿La consulta todavía no tiene ningún motivo? Es el estado vacío de la tarjeta. */
    public function estaVacio(): bool
    {
        return $this->cargados->isEmpty();
    }

    public function total(): int
    {
        return $this->cargados->count();
    }

    /** El encabezado de la tarjeta: "2 motivos" o "Todavía sin motivos". */
    public function resumen(): string
    {
        if ($this->estaVacio()) {
            return 'Todavía sin motivos';
        }

        return $this->total() . ($this->total() === 1 ? ' motivo' : ' motivos');
    }

    /**
     * Los motivos que la consulta ya tiene, con su "Quitar" resuelto (la URL de la fila, que es lo que
     * no puede armar la vista).
     *
     * @return array<int,array{id:int, codigo:string, descripcion:string, enCatalogo:bool, urlQuitar:string, avisoCatalogo:?string}>
     */
    public function cargados(): array
    {
        $urlQuitar = $this->urlQuitar;

        return $this->cargados->map(fn (object $motivo) => [
            'id' => (int) $motivo->id,
            'codigo' => (string) $motivo->codigo,
            'descripcion' => (string) $motivo->descripcion,
            'enCatalogo' => (bool) $motivo->enCatalogo,
            'urlQuitar' => $urlQuitar === null ? '' : (string) $urlQuitar((int) $motivo->id),
            // Un código que ya no está en el catálogo no se puede volver a elegir: se avisa en vez de
            // dejar la fila como si fuera una más (el legado no borra motivos, pero los datos son datos).
            'avisoCatalogo' => $motivo->enCatalogo ? null : 'Ya no está en tu catálogo',
        ])->all();
    }

    // ----------------------------------------------------------------------------------- El buscador

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function urlBuscar(): ?string
    {
        return $this->urlBuscar;
    }

    public function urlAgregar(): string
    {
        return $this->urlAgregar;
    }

    public function urlCrear(): string
    {
        return $this->urlCrear;
    }

    /** El diálogo de alta del motivo nuevo se abre solo (después de un error de validación). */
    public function crearAbierto(): bool
    {
        return $this->crearAbierto;
    }

    public function largoMaximo(): int
    {
        return self::LARGO_MAXIMO;
    }

    /** ¿El buscador encontró algo? Distingue "el catálogo está vacío" de "tu búsqueda no encontró nada". */
    public function hayResultados(): bool
    {
        return $this->catalogo->isNotEmpty();
    }

    /**
     * Los resultados del buscador, con la bandera que decide qué ofrece cada fila: si el motivo ya está
     * en la consulta, no se ofrece agregarlo (el servidor tampoco lo duplicaría).
     *
     * @return array<int,array{codigo:string, descripcion:string, yaEsta:bool, urlAgregar:string}>
     */
    public function resultados(): array
    {
        return $this->catalogo->map(function (MotivoConsulta $motivo) {
            $codigo = trim((string) $motivo->codemotivo);

            return [
                'codigo' => $codigo,
                'descripcion' => (string) $motivo->descripcion,
                'yaEsta' => $this->cargados->contains(fn (object $cargado) => $cargado->codigo === $codigo),
                'urlAgregar' => $this->urlAgregar,
            ];
        })->all();
    }

    /**
     * La ayuda del buscador, que es lo que hace entendible la pantalla sin un manual: cuando la consulta
     * está vacía dice por dónde empezar; cuando ya tiene motivos, qué falta; y cuando la búsqueda no
     * encontró nada, ofrece crearlo.
     */
    public function ayuda(): string
    {
        if (! $this->hayResultados()) {
            return $this->buscar === ''
                ? 'Tu catálogo de motivos está vacío: escribe el primero y créalo.'
                : 'Ningún motivo de tu catálogo coincide con «' . $this->buscar . '». Puedes crearlo.';
        }

        if ($this->estaVacio()) {
            return 'Busca en tu catálogo y agrega los motivos de esta consulta.';
        }

        return 'Los motivos que ya están en la consulta aparecen marcados.';
    }

    /**
     * **El alta de un motivo nuevo necesita conexión.**
     *
     * El aviso lo lleva el móvil porque trabaja sin conexión (`selector_de_motivos.dart`: "Necesita
     * conexión" en la fila de crear); en la web, que es online-only (PLAN-WEB.md §3.4), igual **se dice**,
     * porque explica por qué la lista de motivos no se puede ampliar desde acá sin que el servidor
     * conteste —el código de cuatro dígitos lo da la nube, no el navegador— y porque es la única parte
     * de esta pantalla que no funciona si se cae la red.
     */
    public function avisoDeConexion(): string
    {
        return 'Crear un motivo nuevo necesita conexión: el servidor le da su código y no duplica uno que ya exista.';
    }
}
