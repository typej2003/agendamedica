<?php

namespace App\Clinica\Embarazo\Vistas;

use App\Clinica\Consulta\Vistas\OpcionesDelDesplegable;
use App\Clinica\Embarazo\EdadGestacional;
use App\Clinica\Embarazo\Gesta;
use App\Support\FechaClinica;
use Carbon\CarbonInterface;
use Closure;

/**
 * **La pestaña *Prenatal* del control de embarazo** (WEB-6.6): las gestas de la historia, con la FUR, la
 * menarquia, el tipiaje y las dos cuentas que el escritorio muestra calculadas —la **edad gestacional** y
 * la **FPP**—, con guardado explícito.
 *
 * El ViewModel no sabe de base de datos: recibe las gestas ya leídas (`GestasDeLaHistoria`) y el `old()` de
 * un POST que falló. El **mapa de los campos** vive en `Gesta::campos()`, que es el mapa del dato.
 *
 * Tres cosas que decide esta clase y no la vista:
 *
 *  - **La gesta que se está mirando**: la que pide la URL (`?gesta=2`) o, si no, la **actual** (la de mayor
 *    número). Las gestas se ofrecen como enlaces, así que la que se mira se puede compartir y recargar.
 *  - **La EG y la FPP se calculan con lo que hay en el formulario**, no con lo guardado: si el POST falló y
 *    el médico escribió otra FUR, las dos cuentas son las de esa FUR —es lo que hace el `compute` del
 *    DataWindow, que se recalcula al escribir—. La FPP de esta pestaña suma **270** días, no 283.
 *  - **El aviso de la clave**: cuando la gesta es nueva, el número todavía no existe; lo asigna el servidor.
 */
final class PrenatalVista
{
    /**
     * @param  array<int,Gesta>  $gestas  las gestas de la historia, de la primera a la última
     * @param  Gesta|null  $gesta  la que se está mirando (o `null` si la historia no tiene ninguna todavía)
     * @param  Closure  $urlGesta  `fn(int $clave): string` — la URL de cada gesta
     * @param  array<string,mixed>  $old  el `old()` del formulario (`[]` en la primera carga)
     */
    public function __construct(
        private array $gestas = [],
        private ?Gesta $gesta = null,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private string $urlNueva = '',
        private ?Closure $urlGesta = null,
        private ?CarbonInterface $hoy = null,
        private array $old = [],
    ) {
    }

    /** El nombre de la pestaña, para el encabezado de la tarjeta. */
    public function titulo(): string
    {
        return 'Control prenatal';
    }

    /**
     * Las gestas como opciones para `<x-ui.segmentado>`: "Gesta 1", "Gesta 2"…
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function gestas(): array
    {
        $opciones = [];

        foreach ($this->gestas as $gesta) {
            $opciones[] = [
                'texto' => 'Gesta ' . $gesta->clave(),
                'url' => $this->urlGesta === null ? '' : ($this->urlGesta)($gesta->clave()),
                'activa' => $this->esLaQueSeMira($gesta),
            ];
        }

        return $opciones;
    }

    /** ¿La historia no tiene ninguna gesta todavía? */
    public function sinGestas(): bool
    {
        return $this->gestas === [];
    }

    /** ¿El formulario está creando una gesta nueva? (la que se mira no existe todavía). */
    public function esNueva(): bool
    {
        return $this->gesta === null;
    }

    /** El número de la gesta que se está mirando, o el que le va a tocar si es nueva. */
    public function clave(): ?int
    {
        return $this->gesta?->clave();
    }

    /** La FUR con la que se calculan las dos cuentas: la del formulario. */
    public function fur(): ?string
    {
        return $this->valor('fur');
    }

    /**
     * La **edad gestacional** ("20 semanas y 6 días"), o `null` si la FUR del formulario no sirve.
     */
    public function edadGestacional(): ?string
    {
        $eg = EdadGestacional::de($this->fur(), $this->hoy);

        return $eg?->texto();
    }

    /** La **FPP** de esta pestaña (la FUR más 270 días), o `null` si la FUR no sirve. */
    public function fpp(): ?string
    {
        return EdadGestacional::fpp($this->fur(), EdadGestacional::DIAS_DE_LA_FPP_DEL_CONTROL_PRENATAL);
    }

    /**
     * **Los datos de la ficha**, en el orden de la pantalla del escritorio (O-21): los dos renglones
     * —*FUR · Menarquia · Gestas* y *Partos · Césareas · Abortos · Otros · Tipiaje*—, cada uno con su valor
     * ya resuelto, su ancho de grilla y, en los tipajes, las opciones del desplegable.
     *
     * @return array<int,array<string,mixed>>
     */
    public function campos(): array
    {
        return $this->camposDelBloque(Gesta::DATOS);
    }

    /**
     * **Los renglones de la ficha, en el orden del escritorio** (O-21): una lista de renglones y, en cada uno,
     * las piezas que van de izquierda a derecha.
     *
     * El primer renglón del DataWindow es `FUR · FPP · EG · Menarquia · Gestas`, y la FPP y la EG no son
     * columnas: son los dos `compute` que el escritorio calcula de la FUR y muestra **entre la FUR y la
     * menarquia**. Por eso esta clase arma el renglón con las dos cuentas en su lugar, como piezas de tipo
     * `calculo` (se pintan como `output`, no viajan en el formulario). El segundo renglón es
     * `Partos · Césareas · Abortos · Otros · Tipiaje (madre) · Tipiaje (padre)`.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    public function renglones(): array
    {
        $renglones = [];

        foreach ($this->campos() as $campo) {
            $fila = $campo['fila'] ?? 1;
            $renglones[$fila][] = $campo;

            if ($campo['columna'] === 'fur') {
                // Las dos cuentas del escritorio, en el lugar que les da el DataWindow.
                $renglones[$fila][] = $this->calculo('prenatal-fpp', 'FPP', 'col-6 col-md-2',
                    'FUR + 280 días.', $this->fpp() ? FechaClinica::formato($this->fpp()) : null);
                $renglones[$fila][] = $this->calculo('prenatal-eg', 'Edad gestacional', 'col-6 col-md-3',
                    'Calculada desde la FUR.', $this->edadGestacional());
            }
        }

        ksort($renglones);

        return array_values($renglones);
    }

    /**
     * El **memo** de observaciones, que en el escritorio va debajo de los dos renglones y ocupa el ancho
     * entero.
     *
     * @return array<string,mixed>
     */
    public function observaciones(): array
    {
        return $this->camposDelBloque(Gesta::MEMO)[0] ?? [];
    }

    /**
     * **El estado del embarazo**: `Embarazo múltiple` (casilla), `Cantidad` (un número corto) y
     * `Final de embarazo` (casilla), en el orden de la barra lateral del escritorio. En la web no hay barra
     * lateral: van juntos y discretos al final del bloque, no como una fila de campos anchos (O-21).
     *
     * `marcada` es lo que pinta la casilla; la columna guarda `S`/`N`, como la escribe el escritorio.
     *
     * @return array<int,array<string,mixed>>
     */
    public function estadoDelEmbarazo(): array
    {
        return $this->camposDelBloque(Gesta::ESTADO);
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    /** A dónde postea el diálogo de «Nueva gesta» (sólo pregunta cuántos bebés son, WEB-11.15). */
    public function urlCrearGesta(): string
    {
        return str_replace('/embarazo/prenatal', '/embarazo/gesta', $this->urlGuardar);
    }

    /** La URL de "Nueva gesta" (la que crea la siguiente). */
    public function urlNueva(): string
    {
        return $this->urlNueva;
    }

    /**
     * El estado de la pestaña como pastilla: la edad gestacional cuando se puede calcular, y "Sin FUR"
     * cuando no —que es lo que impide guardar, porque sin FUR no hay nada que contar—.
     */
    public function estado(): array
    {
        $eg = $this->edadGestacional();

        return $eg === null
            ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin FUR',
                'titulo' => 'Sin la FUR no hay edad gestacional ni FPP']
            : ['tono' => 'exito', 'icono' => 'bi-calendar-heart', 'texto' => $eg,
                'titulo' => 'La edad gestacional de hoy, calculada desde la FUR'];
    }

    /**
     * **La ayuda del cuerpo: una sola línea** (O-23). Lo que antes era un párrafo explicativo más la lista de
     * tres pasos —media pantalla de lectura antes del primer campo— está detrás del «¿Cómo funciona?» del
     * encabezado (`comoFunciona()` y `pasosDelUso()`).
     */
    public function ayuda(): string
    {
        return 'Cada gesta guarda su FUR, sus antecedentes y su tipiaje; la edad gestacional y la FPP salen de la FUR.';
    }

    /** El párrafo del diálogo «¿Cómo funciona?»: qué guarda la gesta y qué se calcula. */
    public function comoFunciona(): string
    {
        return 'Cada gesta guarda su FUR, la menarquia, cuántas gestas, partos, cesáreas y abortos, el tipiaje '
            . 'de la madre y del padre y sus observaciones. La edad gestacional y la FPP se calculan de la FUR '
            . '(la FPP suma 280 días) y no se guardan: se recalculan al escribir.';
    }

    /**
     * Los **tres pasos** del diálogo (WEB-11.15), los mismos que antes eran la lista del cuerpo.
     *
     * @return array<int,array{titulo:string,texto:string}>
     */
    public function pasosDelUso(): array
    {
        return [
            ['titulo' => 'Crea la gesta',
                'texto' => 'con «Nueva gesta», que sólo pregunta cuántos bebés son, y completa su FUR.'],
            ['titulo' => 'Registra sus laboratorios y controles',
                'texto' => 'en las otras pestañas del control de embarazo.'],
            ['titulo' => 'Al terminar el embarazo, marca «Final de embarazo»',
                'texto' => 'sin eso no se puede crear la gesta siguiente.'],
        ];
    }

    /** Lo que el diálogo dice del guardado: es explícito y el navegador avisa al salir sin guardar. */
    public function comoSeGuarda(): string
    {
        return 'Lo cargado se guarda con el botón; si sales sin guardar, el navegador te avisa antes de perderlo.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el control prenatal. Si sales ahora, se pierden.';
    }

    /** ¿La gesta que se mira es la que se está ofreciendo? */
    private function esLaQueSeMira(Gesta $gesta): bool
    {
        return $this->gesta !== null && $gesta->clave() === $this->gesta->clave();
    }

    /**
     * Los campos de un bloque del mapa (`Gesta::DATOS`, `MEMO` o `ESTADO`) con su valor ya resuelto.
     *
     * Un desplegable recibe sus **opciones resueltas** (`OpcionesDelDesplegable`): la opción vacía adelante y,
     * si la fila trae un valor que no está en la lista del escritorio —un tipiaje viejo del escritorio, por
     * ejemplo—, **ese valor como última opción y elegido** (O-24): no se pierde ni se cambia sin que el
     * médico elija otra cosa.
     *
     * @return array<int,array<string,mixed>>
     */
    private function camposDelBloque(string $bloque): array
    {
        $salida = [];

        foreach (Gesta::campos() as $campo) {
            if (($campo['bloque'] ?? Gesta::DATOS) !== $bloque) {
                continue;
            }

            $valor = (string) ($this->valor($campo['columna']) ?? '');
            $campo['valor'] = $valor;
            $campo['marcada'] = $campo['tipo'] === 'casilla' && $valor === 'S';

            if ($campo['tipo'] === 'opciones') {
                $campo['opciones'] = OpcionesDelDesplegable::de($campo, $valor);
            }

            $salida[] = $campo;
        }

        return $salida;
    }

    /**
     * Una de las **dos cuentas** del escritorio como una pieza más de su renglón: se pinta como `output` —no
     * tiene `name` y no se manda— y su rótulo y su ayuda son los del DataWindow.
     *
     * @return array<string,mixed>
     */
    private function calculo(string $columna, string $etiqueta, string $ancho, string $ayuda, ?string $valor): array
    {
        return [
            'columna' => $columna, 'etiqueta' => $etiqueta, 'tipo' => 'calculo',
            'ancho' => $ancho, 'info' => $ayuda, 'valor' => $valor ?? '—',
        ];
    }

    /** Lo escrito en el POST que falló, si no lo guardado. */
    private function valor(string $columna): ?string
    {
        if (array_key_exists($columna, $this->old) && $this->old[$columna] !== null) {
            return (string) $this->old[$columna];
        }

        return $this->gesta?->valores()[$columna] ?? null;
    }
}
