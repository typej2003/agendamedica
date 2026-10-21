<?php

namespace App\Clinica\Embarazo\Vistas;

use App\Clinica\Consulta\Vistas\OpcionesDelDesplegable;
use App\Clinica\Embarazo\Gesta;
use Closure;

/**
 * **Una pestaña del control de embarazo cuyas filas son fechas** (WEB-6.6): *Laboratorio*
 * (`prena_exames_b`) y *Controles* (`pre_natal_desarrollo_fino`).
 *
 * Las dos tienen la misma forma —una fila por **fecha** dentro de una gesta, con su clave `historia` +
 * `gesta_clave` + `fecha`— y sólo cambian el título, los campos y de dónde salen las filas. Así que hay
 * **un solo ViewModel** para las dos, en vez de dos que se diferencian en el nombre de la tabla: lo que
 * cambia entra por el constructor (los `$campos` salen del mapa del dato de cada modelo, `PrenaExamenB::campos()`
 * y `PreNatalDesarrolloFino::campos()`).
 *
 * Como en la pestaña *Prenatal*, la fecha que se está mirando se elige con un enlace —así se puede
 * compartir y recargar—, y los valores del formulario son los de `old()` si el POST falló y los guardados
 * si no.
 *
 * **Una fecha nueva no lleva ninguna marca**: es esta misma pestaña **sin `&fecha=`** (ver `esNueva()`), y
 * el formulario abre en blanco con la fecha de hoy. La **gesta no cambia**: agregar una fecha suma una fila
 * a la gesta en curso, no crea una gesta (el `gesta_clave` viaja oculto y es el de la pestaña).
 */
final class FechasDelEmbarazoVista
{
    /**
     * @param  array<int,array<string,mixed>>  $campos  el mapa del dato (columna, etiqueta, tipo, ayuda…)
     * @param  array<int,array{fecha:string,valores:array<string,string|null>}>  $filas  las filas guardadas
     * @param  Closure|null  $urlFecha  `fn(string $fecha): string` — la URL de cada fecha
     * @param  array<string,mixed>  $old  el `old()` del formulario
     * @param  string  $fechaPorDefecto  la fecha que abre un laboratorio o un control **nuevo** (`AAAA-MM-DD`):
     *                                   hoy del consultorio (O-13). Lo guardado y lo elegido mandan.
     */
    public function __construct(
        private string $titulo = '',
        private string $icono = 'bi-clipboard2-pulse',
        private array $campos = [],
        private array $filas = [],
        private string $fechaElegida = '',
        private int $gestaClave = 1,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private string $urlNueva = '',
        private ?Closure $urlFecha = null,
        private string $ayuda = '',
        private string $aviso = '',
        private array $old = [],
        private string $fechaPorDefecto = '',
    ) {
    }

    /** El número de la gesta a la que pertenecen estas filas: viaja oculto en el formulario. */
    public function gestaClave(): int
    {
        return $this->gestaClave;
    }

    public function titulo(): string
    {
        return $this->titulo;
    }

    public function icono(): string
    {
        return $this->icono;
    }

    public function ayuda(): string
    {
        return $this->ayuda;
    }

    public function avisoSinGuardar(): string
    {
        return $this->aviso;
    }

    /** ¿La gesta todavía no tiene ninguna fila? */
    public function sinFilas(): bool
    {
        return $this->filas === [];
    }

    /**
     * **¿El formulario está agregando una fecha a la gesta?** Es la pestaña con la gesta y **sin `&fecha=`**:
     * no hace falta —ni se usa— una marca aparte, y así una fecha nueva nunca cambia de gesta.
     */
    public function esNueva(): bool
    {
        return $this->fechaElegida === '' || ! array_key_exists($this->fechaElegida, $this->porFecha());
    }

    /**
     * El aviso corto de la fecha nueva, cuando la gesta ya tiene filas: el formulario en blanco es una fila
     * **más**, no la primera, y «Agregar una fecha» tiene que decir que se está sumando.
     */
    public function avisoDeFechaNueva(): ?string
    {
        return $this->esNueva() && ! $this->sinFilas()
            ? 'Fecha nueva: completa y guarda para agregarla a la gesta.'
            : null;
    }

    /** La fecha que se está mirando, como la manda el formulario (vacía si es una fecha nueva). */
    public function fecha(): string
    {
        return $this->valorDeLaFecha();
    }

    /**
     * Las filas como opciones para `<x-ui.segmentado>`: la fecha de cada laboratorio o control.
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function fechas(): array
    {
        $opciones = [];

        foreach ($this->filas as $fila) {
            $opciones[] = [
                'texto' => $this->fechaCorta($fila['fecha']),
                'url' => $this->urlFecha === null ? '' : ($this->urlFecha)($fila['fecha']),
                'activa' => $fila['fecha'] === $this->fechaElegida,
            ];
        }

        return $opciones;
    }

    /**
     * Los campos del formulario, con su valor ya resuelto.
     *
     * Un desplegable recibe sus **opciones resueltas** (`OpcionesDelDesplegable`): la opción vacía adelante
     * —sin ella un `<select>` mandaría siempre la primera, y una serología quedaría escrita sin que nadie la
     * tocara— y el código desconocido del escritorio al final, sin perderlo. Antes se pasa el valor por
     * `valorConocido`: un rótulo viejo («Negativo», de antes de la trampa 140) vuelve a ser el código que la
     * columna guarda.
     *
     * @return array<int,array<string,mixed>>
     */
    public function campos(): array
    {
        return array_map(function (array $campo) {
            $campo['valor'] = (string) ($this->valorDe($campo['columna']) ?? '');

            if (($campo['tipo'] ?? 'texto') === 'opciones') {
                $campo['valor'] = OpcionesDelDesplegable::valorConocido($campo, $campo['valor']);
                $campo['opciones'] = OpcionesDelDesplegable::de($campo, $campo['valor']);
            }

            return $campo;
        }, $this->campos);
    }

    /**
     * **Los campos agrupados como en la grilla del escritorio** (O-21): el mapa del dato marca con `bloque`
     * qué campos van juntos y el `rotulo` del bloque, si lleva uno. El laboratorio tiene dos —su panel de
     * exámenes y «Otros estudios», el segundo renglón del DataWindow—; los controles, uno solo.
     *
     * @return array<int,array{rotulo:string|null,campos:array<int,array<string,mixed>>}>
     */
    public function bloques(): array
    {
        $bloques = [];

        foreach ($this->campos() as $campo) {
            $clave = $campo['bloque'] ?? 'panel';

            $bloques[$clave]['rotulo'] = $bloques[$clave]['rotulo'] ?? ($campo['rotulo'] ?? null);
            $bloques[$clave]['campos'][] = $campo;
        }

        return array_values($bloques);
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function urlNueva(): string
    {
        return $this->urlNueva;
    }

    /** El estado de la pestaña: cuántas fechas tiene la gesta. */
    public function estado(): array
    {
        $cuantas = count($this->filas);

        return $cuantas === 0
            ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Esta gesta todavía no tiene nada cargado acá']
            : ['tono' => 'exito', 'icono' => 'bi-check2-circle',
                'texto' => $cuantas . ($cuantas === 1 ? ' fecha' : ' fechas'),
                'titulo' => 'Las fechas cargadas en esta gesta'];
    }

    /** Las filas guardadas, por fecha (para saber si la que se mira existe). */
    private function porFecha(): array
    {
        return array_column($this->filas, null, 'fecha');
    }

    /** La fecha del formulario: lo que se mandó, lo que se eligió o —si es nueva— la de hoy (O-13). */
    private function valorDeLaFecha(): string
    {
        if (array_key_exists('fecha', $this->old) && $this->old['fecha'] !== null) {
            return (string) $this->old['fecha'];
        }

        return $this->fechaElegida !== '' ? $this->fechaElegida : $this->fechaPorDefecto;
    }

    /** Lo escrito en el POST que falló, si no lo guardado en esa fecha. */
    private function valorDe(string $columna): ?string
    {
        if (array_key_exists($columna, $this->old) && $this->old[$columna] !== null) {
            return (string) $this->old[$columna];
        }

        return $this->porFecha()[$this->fechaElegida]['valores'][$columna] ?? null;
    }

    /** `2026-08-14` → `14/08/2026`, como se lee en la pantalla. */
    private function fechaCorta(string $fecha): string
    {
        $partes = explode('-', $fecha);

        return count($partes) === 3 ? $partes[2] . '/' . $partes[1] . '/' . $partes[0] : $fecha;
    }
}
