<?php

namespace App\Clinica\Consulta\Vistas;

use Closure;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * **La pestaña Laboratorio y la pestaña Estudios cuando se pintan como Motivos de consulta** (O-29): a la izquierda lo que la
 * consulta ya pidió —cada fila con su «Quitar», que escribe al instante— y a la derecha **el catálogo del médico siempre a la
 * vista**, con su buscador, su filtro por tipo y su «Pasar» por fila, que también escribe al instante.
 *
 * Envuelve el `OrdenVista` de siempre (los textos de la sección y las filas de la orden) y le suma lo que trae el catálogo a la
 * vista. Se separó en vez de crecer `OrdenVista` porque la orden de la **pareja** (WEB-11.21) sigue siendo un editor que se guarda
 * entero con su botón y usa el mismo ViewModel: así cada pantalla conserva el suyo y no hay banderas de modo.
 *
 * Lo que queda para el botón (y por eso lo dice la pantalla): el **«Resumen clínico»** y nada más. Las altas y las bajas de los
 * exámenes ya ocurrieron cuando el médico hizo clic. Sin base de datos: recibe lo ya leído.
 */
final class OrdenDeCatalogoVista
{
    /**
     * @param  OrdenVista  $orden  la orden en sí: sus filas, su estado y sus textos
     * @param  array<int,array{codigo:string, nombre:string, tipo:?string}>  $catalogo  la página del catálogo que se está mirando
     * @param  string  $buscar  lo escrito en el buscador del catálogo (`?buscar=`)
     * @param  string  $tipoElegido  el tipo del filtro (`?tipo=`; vacío = todos)
     * @param  array<string,string>  $tipos  los tipos de la lista, `código => nombre`
     * @param  array<int,array{codigo:string, nombre:string, cantidad:int}>  $rutinas  los tipos completos que se pueden pasar de una vez
     * @param  array<string,string>  $urls  `agregar`, `resumen`, `rutina` (con `__TIPO__`), `seccion`, `administrar`
     * @param  Closure  $urlQuitar  `fn(string $codigo): string` — la baja inmediata de ese examen o estudio
     * @param  LengthAwarePaginator|null  $paginador  las páginas del catálogo
     */
    public function __construct(
        private OrdenVista $orden,
        private array $catalogo,
        private string $buscar,
        private string $tipoElegido,
        private array $tipos,
        private array $rutinas,
        private array $urls,
        private Closure $urlQuitar,
        private bool $hayCatalogo = true,
        private ?LengthAwarePaginator $paginador = null,
    ) {
    }

    // ------------------------------------------------------------------------------------------ La orden

    public function titulo(): string
    {
        return $this->orden->titulo();
    }

    public function cosas(): string
    {
        return $this->orden->cosas();
    }

    public function esLaboratorio(): bool
    {
        return $this->orden->esLaboratorio();
    }

    public function esReal(): bool
    {
        return $this->orden->esReal();
    }

    public function puedeEliminar(): bool
    {
        return $this->orden->puedeEliminar();
    }

    public function items(): array
    {
        return $this->orden->items();
    }

    public function total(): int
    {
        return $this->orden->total();
    }

    public function cuenta(): string
    {
        return $this->orden->cuenta();
    }

    public function numerada(): bool
    {
        return $this->orden->numerada();
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        return $this->orden->estado();
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        return $this->orden->vacio();
    }

    public function ayuda(): string
    {
        return $this->orden->ayuda();
    }

    public function observacion(): string
    {
        return $this->orden->observacion();
    }

    public function largoDeLaObservacion(): int
    {
        return $this->orden->largoDeLaObservacion();
    }

    public function etiquetaDelResumen(): string
    {
        return $this->orden->etiquetaDelResumen();
    }

    public function ayudaDelResumen(): string
    {
        return $this->orden->ayudaDelResumen();
    }

    public function avisoSinGuardar(): string
    {
        return $this->orden->avisoSinGuardar();
    }

    public function avisoEliminar(): string
    {
        return $this->orden->avisoEliminar();
    }

    public function textoDeGuardar(): string
    {
        return 'Guardar el resumen clínico';
    }

    public function textoDeEliminar(): string
    {
        return $this->orden->textoDeEliminar();
    }

    public function urlEliminar(): string
    {
        return $this->orden->urlEliminar();
    }

    public function urlImprimir(): string
    {
        return $this->orden->urlImprimir();
    }

    public function documento(): string
    {
        return $this->orden->documento();
    }

    public function urlPareja(): string
    {
        return $this->orden->urlPareja();
    }

    /** A dónde postea el «Resumen clínico» (lo único que se guarda con el botón). */
    public function urlResumen(): string
    {
        return $this->urls['resumen'];
    }

    /** La baja inmediata de un examen o estudio: la URL de esa fila (no se puede concatenar: lleva el código). */
    public function urlQuitarDe(string $codigo): string
    {
        return ($this->urlQuitar)($codigo);
    }

    // ---------------------------------------------------------------------------------------- El catálogo

    /** A dónde postea el «Pasar» de una fila del catálogo. */
    public function urlAgregar(): string
    {
        return $this->urls['agregar'];
    }

    /** A dónde postea «Pasar la rutina» (un tipo de examen completo); `__TIPO__` se reemplaza en el controlador. */
    public function urlRutina(): string
    {
        return $this->urls['rutina'] ?? '';
    }

    /** La URL del JSON de una rutina, ya con su tipo (la ruta lleva el código en el camino). */
    public function urlDeLaRutina(string $tipo): string
    {
        return str_replace('__TIPO__', $tipo, $this->urlRutina());
    }

    /** El catálogo de exámenes del médico (el ABM), donde se crea lo que falta. */
    public function urlAdministrar(): string
    {
        return $this->urls['administrar'];
    }

    /** La sección misma, con su `?solicitud=` y sin el buscador: a donde va el filtro. */
    public function urlDelFiltro(): string
    {
        return (string) strtok($this->urls['seccion'], '?');
    }

    /** A donde vuelve «Limpiar». */
    public function urlSeccion(): string
    {
        return $this->urls['seccion'];
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function tipoElegido(): string
    {
        return $this->tipoElegido;
    }

    /** @return array<string,string> */
    public function tipos(): array
    {
        return $this->tipos;
    }

    /** ¿El médico tiene algo en el catálogo de esta lista? Distingue «tu catálogo está vacío» de «tu búsqueda no encontró nada». */
    public function hayCatalogo(): bool
    {
        return $this->hayCatalogo;
    }

    /** ¿Hubo algún resultado en el catálogo (con o sin búsqueda)? */
    public function hayResultados(): bool
    {
        return $this->catalogo !== [];
    }

    /**
     * Las filas del catálogo, con su bandera: la que ya está en la orden se muestra marcada y sin «Pasar» (el servidor tampoco la
     * duplicaría).
     *
     * @return array<int,array{codigo:string, nombre:string, tipo:?string, yaEsta:bool, urlAgregar:string, urlQuitar:string}>
     */
    public function filasDelCatalogo(): array
    {
        $enLaOrden = array_map(fn (array $item) => $item['codigo'], $this->orden->items());

        return array_map(fn (array $examen) => [
            'codigo' => $examen['codigo'],
            'nombre' => $examen['nombre'],
            'tipo' => $examen['tipo'],
            'yaEsta' => in_array($examen['codigo'], $enLaOrden, true),
            'urlAgregar' => $this->urlAgregar(),
            'urlQuitar' => $this->urlQuitarDe($examen['codigo']),
        ], $this->catalogo);
    }

    public function paginador(): ?LengthAwarePaginator
    {
        return $this->paginador;
    }

    /** ¿Ofrece las rutinas (los tipos de examen completos)? Sólo el laboratorio. */
    public function ofreceRutinas(): bool
    {
        return $this->orden->esLaboratorio() && $this->rutinas !== [];
    }

    /** @return array<int,array{codigo:string, nombre:string, cantidad:int}> */
    public function rutinas(): array
    {
        return $this->rutinas;
    }

    public function etiquetaDelBuscador(): string
    {
        return $this->orden->esLaboratorio() ? 'Busca en tu catálogo de exámenes' : 'Busca en tu catálogo de estudios';
    }

    public function placeholderDelBuscador(): string
    {
        return $this->orden->esLaboratorio() ? 'Escribe el nombre del examen' : 'Escribe el nombre del estudio';
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function sinCatalogo(): array
    {
        return $this->orden->sinCatalogo();
    }

    public function textoDelEnlaceAlCatalogo(): string
    {
        return $this->orden->textoDelEnlaceAlCatalogo();
    }

    /** La ayuda del catálogo: qué se hace con un clic y qué queda para el botón (es lo que cambió en O-29). */
    public function ayudaDelCatalogo(): string
    {
        if (! $this->hayResultados()) {
            return $this->buscar === '' && $this->tipoElegido === ''
                ? 'Tu catálogo no tiene ' . $this->cosas() . ': créalos en Catálogos → Exámenes.'
                : 'Ningún resultado con «' . $this->buscar . '». Prueba con otra palabra o quita el filtro.';
        }

        return '«' . $this->verbo() . '» y «Quitar» se aplican al instante; el resumen clínico, con su botón.';
    }

    /** El rótulo del botón de la fila: «Pasar» en exámenes, «Pasar» en estudios (el del escritorio). */
    public function verbo(): string
    {
        return 'Pasar';
    }

    private function documentoEnMinuscula(): string
    {
        return $this->orden->esLaboratorio() ? 'orden' : 'solicitud';
    }
}
