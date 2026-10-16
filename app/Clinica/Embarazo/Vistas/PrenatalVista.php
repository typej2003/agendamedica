<?php

namespace App\Clinica\Embarazo\Vistas;

use App\Clinica\Embarazo\EdadGestacional;
use App\Clinica\Embarazo\Gesta;
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
     * Los campos del formulario, en el orden de la pantalla, con su valor ya resuelto.
     *
     * @return array<int,array<string,mixed>>
     */
    public function campos(): array
    {
        return array_map(fn (array $campo) => $campo + ['valor' => (string) ($this->valor($campo['columna']) ?? '')], Gesta::campos());
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
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

    /** La ayuda de la pestaña: qué es y qué pasa al salir sin guardar. */
    public function ayuda(): string
    {
        return 'Cada gesta guarda su FUR, la menarquia, cuántas gestas, partos, cesáreas y abortos, el tipiaje '
            . 'de la madre y del padre y sus observaciones. La edad gestacional y la FPP se calculan de la FUR '
            . '(la FPP suma 280 días) y no se guardan: se recalculan al '
            . 'escribir. Lo cargado se guarda con el botón; si sales sin guardar, el navegador te avisa antes '
            . 'de perderlo.';
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

    /** Lo escrito en el POST que falló, si no lo guardado. */
    private function valor(string $columna): ?string
    {
        if (array_key_exists($columna, $this->old) && $this->old[$columna] !== null) {
            return (string) $this->old[$columna];
        }

        return $this->gesta?->valores()[$columna] ?? null;
    }
}
