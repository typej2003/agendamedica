<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\TipoDeAntecedentes;
use Illuminate\Support\Collection;

/**
 * **Los antecedentes del paciente en la consulta** (WEB-4.4, ítem 3 del panel del legado), en la columna
 * izquierda de la sección de dos columnas de A-06.
 *
 * El ViewModel no sabe de base de datos ni de `Request`: recibe los antecedentes **ya leídos**
 * (`App\Clinica\Consulta\AntecedentesDelPaciente`), el catálogo de tipos, el tipo elegido ya resuelto
 * (`TipoDeAntecedentes`) y las URLs. La lectura resuelve el nombre del antecedente y el de su tipo; acá sólo se
 * arma lo que la pantalla pinta.
 *
 * Lo que decide esta clase y no la vista:
 *
 *  - **Los chips de tipo** (`chips()`): los siete tipos del catálogo en el orden de `codetipo`, **siempre uno
 *    elegido** —no hay «todos»— y el `?tipo=` de cada uno, para que cambiar de chip no pierda el filtro. Si el
 *    paciente tiene antecedentes sin tipo, se agrega el chip «Sin clasificar» al final: son datos clínicos y no
 *    pueden quedar sin puerta de entrada.
 *  - **El filtro es el del tipo elegido** (`filas()`): la columna muestra sólo los antecedentes de ese tipo,
 *    igual que la solapa del escritorio. El **total** del paciente (`total()`) sigue siendo el de todos, que es
 *    lo que dice el contador de la cabecera.
 *  - **Los estados vacíos son dos**: el paciente no tiene **nada** («Este paciente no tiene antecedentes
 *    cargados») o no tiene **de este tipo** («Sin antecedentes de este tipo»). El segundo no puede decir lo
 *    primero: sería falso y el médico concluiría que la historia está vacía.
 *  - **Los "vacíos" del legado** (`NULL`, `''` y el literal `none`) se muestran como `—` en el detalle, y el
 *    recorte de la fila lo hace el ViewModel para que el listado se lea de un vistazo: el `<input>` del
 *    formulario lleva el texto **completo**, porque es el dato que se edita.
 *  - **De quién son los antecedentes**: del **paciente**, no de la consulta. La pantalla lo dice (`ayuda()`),
 *    porque quien los carga desde acá los está cargando para su historia.
 */
final class SeccionDeAntecedentesVista
{
    /**
     * @param  Collection<int,object>  $antecedentes  lo que devolvió `AntecedentesDelPaciente::delPaciente()`
     * @param  array<int,array{codigo:string, nombre:string}>  $tipos  el catálogo de tipos, en el orden de `codetipo`
     * @param  string  $tipo  el tipo elegido, ya resuelto (`TipoDeAntecedentes::elegido`)
     * @param  string  $urlGuardar  a dónde postea "Guardar los antecedentes" (detalle y alerta)
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de la guía de UI)
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación (`[]` en la primera carga)
     * @param  string  $urlQuitar  a dónde postea el "Quitar" de cada fila (O-07: es inmediato, el código va en el
     *                              cuerpo del formulario de la fila)
     */
    public function __construct(
        private Collection $antecedentes,
        private array $tipos = [],
        private string $tipo = '',
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private array $old = [],
        private string $urlQuitar = '',
        // El registro de movimientos (2026-10-20): `codeantecedente` => fecha del alta vigente, y el historial.
        private array $desde = [],
        private ?HistorialDeAntecedentesVista $historial = null,
    ) {
    }

    /** El «Historial» de movimientos del paciente (cajón lateral), o `null` si la pantalla no lo ofrece. */
    public function historial(): ?HistorialDeAntecedentesVista
    {
        return $this->historial;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    /** A dónde postea el "Quitar" de una fila (el código viaja como campo oculto). */
    public function urlQuitar(): string
    {
        return $this->urlQuitar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** El tipo elegido: el que filtra las dos columnas. */
    public function tipo(): string
    {
        return $this->tipo;
    }

    /**
     * Los **chips de tipo**: el filtro de las dos columnas de la sección. Siete como máximo, en el orden del
     * catálogo, más «Sin clasificar» si el paciente tiene antecedentes que no caen en ninguno; siempre uno
     * activo (el pedido fue explícito: no hay «todos los tipos»).
     *
     * La `url` de cada chip conserva la sección y el tipo. Ya no hay pendientes del buscador que conservar:
     * desde O-07 «Agregar» es un `POST` inmediato y no deja códigos tildados en la URL.
     *
     * @return array<int,array{codigo:string, nombre:string, activa:bool, url:string}>
     */
    public function chips(): array
    {
        $chips = [];

        foreach ($this->tipos as $tipo) {
            $chips[] = $this->chip($tipo['codigo'], $tipo['nombre']);
        }

        if ($this->haySinClasificar()) {
            $chips[] = $this->chip(TipoDeAntecedentes::SIN_CLASIFICAR, 'Sin clasificar');
        }

        return $chips;
    }

    /** El nombre del tipo elegido (para los textos vacíos y el encabezado); `null` si no hay catálogo. */
    public function nombreDelTipo(): ?string
    {
        if ($this->tipo === '') {
            return null;
        }

        if ($this->tipo === TipoDeAntecedentes::SIN_CLASIFICAR) {
            return 'Sin clasificar';
        }

        foreach ($this->tipos as $tipo) {
            if ((string) $tipo['codigo'] === $this->tipo) {
                return $tipo['nombre'];
            }
        }

        return null;
    }

    /** ¿El paciente todavía no tiene **ningún** antecedente cargado? */
    public function estaVacio(): bool
    {
        return $this->antecedentes->isEmpty();
    }

    /** ¿Tiene antecedentes, pero ninguno **de este tipo**? Es el otro estado vacío. */
    public function sinAntecedentesDeEsteTipo(): bool
    {
        return ! $this->estaVacio() && $this->filas() === [];
    }

    /** Cuántos antecedentes tiene el paciente: lo que muestra el contador de la cabecera (el total, no el filtro). */
    public function total(): int
    {
        return $this->antecedentes->count();
    }

    /** Cuántos están marcados como **alerta** (`tipo2 = 'A'`), que es lo que el escritorio resalta. */
    public function alertas(): int
    {
        return $this->antecedentes->filter(fn ($antecedente) => (bool) $antecedente->alerta)->count();
    }

    /** "3 antecedentes" / "1 antecedente": el resumen del encabezado de la columna. */
    public function resumen(): string
    {
        return $this->total() . ($this->total() === 1 ? ' antecedente' : ' antecedentes');
    }

    /**
     * Los antecedentes **del tipo elegido**, ya listos para pintar (la columna izquierda).
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(): array
    {
        return $this->antecedentes
            ->filter(fn ($antecedente) => $this->esDelTipo($antecedente))
            ->map(fn ($antecedente) => $this->fila($antecedente))
            ->values()
            ->all();
    }

    /**
     * El estado de la sección como pastilla: "Sin cargar" mientras no haya nada y "Cargados" cuando ya
     * hay algo. No dice "guardado" (de eso se encarga el aviso de cambios sin guardar).
     */
    public function estado(): array
    {
        if ($this->estaVacio()) {
            return ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Este paciente todavía no tiene antecedentes cargados'];
        }

        $alertas = $this->alertas();

        return ['tono' => 'exito', 'icono' => 'bi-check2-circle',
            'texto' => $alertas === 0 ? 'Cargados' : 'Con alerta',
            'titulo' => $alertas === 0
                ? 'Los antecedentes de este paciente están cargados en su historia'
                : $alertas . ($alertas === 1 ? ' antecedente marcado' : ' antecedentes marcados') . ' como alerta'];
    }

    /**
     * La ayuda del formulario, que dice lo más importante de la sección: **de quién son** los antecedentes y
     * qué se guarda con el botón (O-07: el agregado y la baja son inmediatos).
     *
     * Es una decisión clínica y de la fuente (ver `AntecedentesDelPaciente`): no son de esta consulta, son
     * del paciente, así que lo que se cargue acá se ve en su historia y en su próxima consulta.
     */
    public function ayuda(): string
    {
        return 'Estos antecedentes son del paciente, no de esta consulta: los que ves —y los que agregues— '
            . 'quedan en su historia clínica y los verás en sus próximas consultas. Agregar y Quitar se aplican '
            . 'al instante; el detalle y la casilla de Alerta se guardan con el botón.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en los antecedentes. Si sales ahora, se pierden.';
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * ¿Ese antecedente es del tipo elegido? «Sin clasificar» es el grupo de los que **no** caen en ninguno de
     * los tipos del catálogo (la fila no tiene `tipo` y el catálogo tampoco lo resuelve).
     */
    private function esDelTipo(object $antecedente): bool
    {
        $codigos = array_map('strval', array_column($this->tipos, 'codigo'));
        $suyo = (string) $antecedente->tipoCodigo;

        if ($this->tipo === TipoDeAntecedentes::SIN_CLASIFICAR) {
            return ! in_array($suyo, $codigos, true);
        }

        return $suyo === $this->tipo;
    }

    /** ¿Hay antecedentes del paciente fuera de los tipos del catálogo? (los del chip «Sin clasificar») */
    private function haySinClasificar(): bool
    {
        return TipoDeAntecedentes::haySinClasificar($this->antecedentes, array_column($this->tipos, 'codigo'));
    }

    /** @return array{codigo:string, nombre:string, activa:bool, url:string} */
    private function chip(string $codigo, string $nombre): array
    {
        return [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'activa' => $this->tipo === $codigo,
            'url' => $this->urlConTipo($codigo),
        ];
    }

    /**
     * La URL de un chip: la sección y el tipo. Se arma acá y no en la vista porque es una URL con parámetros
     * (la vista no arma URLs en la web clínica).
     */
    private function urlConTipo(string $codigo): string
    {
        $query = [];

        // La base ya es la sección (con su `?seccion=`): no se repite el parámetro.
        if (! str_contains($this->urlVolver, 'seccion=')) {
            $query[] = 'seccion=' . ConsultaShellVista::SECCION_ANTECEDENTES;
        }

        $query[] = 'tipo=' . urlencode($codigo);

        return $this->urlVolver . (str_contains($this->urlVolver, '?') ? '&' : '?') . implode('&', $query);
    }

    /**
     * Una fila del formulario: el nombre, el código, el detalle y la casilla de alerta, con el `old()` de
     * un POST que falló para no borrar lo escrito (guía de UI §3).
     *
     * @return array<string,mixed>
     */
    private function fila(object $antecedente): array
    {
        $id = (int) $antecedente->id;
        $viejo = is_array($this->old['filas'][$id] ?? null) ? $this->old['filas'][$id] : null;

        $detalle = $viejo !== null && array_key_exists('detalle', $viejo)
            ? trim((string) $viejo['detalle'])
            : (string) ($antecedente->detalle ?? '');

        $alerta = $viejo !== null && array_key_exists('alerta', $viejo)
            ? in_array($viejo['alerta'], ['A', 'a', '1', 1, true, 'on'], true)
            : (bool) $antecedente->alerta;

        return [
            'id' => $id,
            'codigo' => (string) $antecedente->codigo,
            'nombre' => (string) $antecedente->nombre,
            'detalle' => $detalle,
            'detalleCorto' => $this->recortar($detalle),
            'alerta' => $alerta,
            // Un antecedente cuyo código ya no está en el catálogo se muestra igual (el legado no borra
            // antecedentes, pero los datos son datos): se avisa para que el médico sepa de dónde sale.
            'avisoCatalogo' => $antecedente->enCatalogo ? null : 'ya no está en tu catálogo',
            // «desde dd/mm/aaaa»: el alta registrada en `antecedente_movimientos`; sin registro, nada.
            'desde' => isset($this->desde[trim((string) $antecedente->codigo)])
                ? 'desde ' . HistorialDeAntecedentesVista::local($this->desde[trim((string) $antecedente->codigo)])->format('d/m/Y')
                : null,
        ];
    }

    /** El texto de una fila: espacios colapsados y, si no entra, cortado con `…`. */
    private function recortar(?string $texto): ?string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', (string) $texto));

        if ($limpio === '') {
            return null;
        }

        return mb_strlen($limpio) <= 160 ? $limpio : mb_substr($limpio, 0, 157) . '…';
    }
}
