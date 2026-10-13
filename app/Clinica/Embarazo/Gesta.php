<?php

namespace App\Clinica\Embarazo;

use Carbon\CarbonInterface;

/**
 * **Una gesta de la historia** (WEB-6.6, la pestaña *Prenatal* del control de embarazo): una fila de
 * `pre_natal_observaciones`, con lo que el escritorio guarda y las dos cuentas que muestra.
 *
 * El DataWindow de la pestaña (`d_pre_natal_observa.srd`) tiene **siete columnas editables** —`fur`,
 * `menarquia`, `gestas`, `parto`, `tipiaje`, `tipiaje_cony` y `observaciones`— más su clave
 * (**`historia` + `gesta_clave`**, las dos con `key=yes`) y **dos `compute` que no se guardan**: la *EG* y
 * la *FPP* (`t_10` y `t_2`), que salen de la FUR. Este objeto de valores lleva las dos cosas: lo guardado y
 * lo calculado.
 *
 * Los rótulos del escritorio, para que la pantalla los respete: *FUR*, *Menarquia*, *Gestas*, *Tipiaje*
 * (con *Madre* y *Padre* debajo: `tipiaje` es el de la madre y `tipiaje_cony` el del padre), *Parto* y
 * *Observaciones*. La **FPP** la suma esta pantalla con **270** días (`d_pre_natal_observa.srd:20`), no con
 * los 283 del eco obstétrico: ver `EdadGestacional`.
 */
final class Gesta
{
    /**
     * @param  array<string,mixed>  $valores  las columnas de la fila (clave => valor crudo)
     * @param  int|null  $id  el `id` de la fila (para el rastro y para volver a encontrarla); `null` si es nueva
     */
    public function __construct(
        private array $valores = [],
        private ?int $id = null,
        private ?CarbonInterface $hoy = null,
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /** El número de la gesta (`gesta_clave`): el 1 es la primera, y es lo que ordena la lista. */
    public function clave(): int
    {
        return (int) ($this->valores['gesta_clave'] ?? 1);
    }

    /** ¿Es la gesta en curso? Es la de mayor número: el embarazo que el médico está siguiendo. */
    public function esLaActual(int $claveActual): bool
    {
        return $this->clave() === $claveActual;
    }

    public function fur(): ?string
    {
        return self::texto($this->valores['fur'] ?? null);
    }

    public function menarquia(): ?string
    {
        return self::texto($this->valores['menarquia'] ?? null);
    }

    /** Cuántas gestas declara la paciente (el campo *Gestas*, que es un número). */
    public function gestas(): ?string
    {
        return self::texto($this->valores['gestas'] ?? null);
    }

    public function parto(): ?string
    {
        return self::texto($this->valores['parto'] ?? null);
    }

    /** El tipiaje de la **madre**. */
    public function tipiaje(): ?string
    {
        return self::texto($this->valores['tipiaje'] ?? null);
    }

    /** El tipiaje del **padre** (la columna se llama `tipiaje_cony`). */
    public function tipiajeDeLaPareja(): ?string
    {
        return self::texto($this->valores['tipiaje_cony'] ?? null);
    }

    public function observaciones(): ?string
    {
        return self::texto($this->valores['observaciones'] ?? null);
    }

    /**
     * La **edad gestacional** de esta gesta (las semanas y los días de hoy), o `null` si su FUR no sirve.
     */
    public function edadGestacional(): ?EdadGestacional
    {
        return EdadGestacional::de($this->fur(), $this->hoy);
    }

    /**
     * La **FPP** de esta gesta: la FUR más los **270** días de esta pantalla
     * (`d_pre_natal_observa.srd:20`), o `null` si no hay FUR.
     */
    public function fpp(): ?string
    {
        return EdadGestacional::fpp($this->fur(), EdadGestacional::DIAS_DE_LA_FPP_DEL_CONTROL_PRENATAL);
    }

    /** Todos los valores de la fila, normalizados: es lo que la Action compara contra lo que llega. */
    public function valores(): array
    {
        $salida = [];
        foreach (array_keys($this->valores) as $columna) {
            $salida[$columna] = self::texto($this->valores[$columna]);
        }

        return $salida;
    }

    /** El `""` del formulario, el `null` de la columna y el literal `none` del legado son el mismo estado. */
    public static function texto($crudo): ?string
    {
        if ($crudo === null) {
            return null;
        }

        if (is_float($crudo) || is_int($crudo)) {
            return (string) $crudo;
        }

        $texto = trim((string) $crudo);

        return ($texto === '' || strcasecmp($texto, 'none') === 0) ? null : $texto;
    }

    /**
     * **El mapa de los campos de la gesta**: qué columna, con qué rótulo y con qué control se pinta.
     *
     * Los rótulos son los del DataWindow de la pestaña (`d_pre_natal_observa.srd`): *FUR*, *Menarquia*,
     * *Gestas*, *Tipiaje* (con *Madre* y *Padre* debajo: `tipiaje` es el de la madre y `tipiaje_cony` el del
     * padre), *Parto* y *Observaciones*. El orden es el de la pantalla del escritorio.
     *
     * @return array<int,array{columna:string,etiqueta:string,tipo:string,ayuda:string}>
     */
    public static function campos(): array
    {
        return [
            ['columna' => 'fur', 'etiqueta' => 'FUR', 'tipo' => 'fecha',
                'ayuda' => 'Fecha de la última regla: de ella salen la edad gestacional y la FPP.'],
            ['columna' => 'menarquia', 'etiqueta' => 'Menarquia', 'tipo' => 'texto',
                'ayuda' => 'Cuándo le vino por primera vez.'],
            ['columna' => 'gestas', 'etiqueta' => 'Gestas', 'tipo' => 'numero',
                'ayuda' => 'Cuántos embarazos ha tenido (el campo *Gestas* del escritorio).'],
            ['columna' => 'parto', 'etiqueta' => 'Parto', 'tipo' => 'texto',
                'ayuda' => 'Cómo terminaron: parto, cesárea, cuántos… El escritorio lo deja como texto libre.'],
            ['columna' => 'tipiaje', 'etiqueta' => 'Tipiaje (madre)', 'tipo' => 'texto',
                'ayuda' => 'El grupo sanguíneo de la madre.'],
            ['columna' => 'tipiaje_cony', 'etiqueta' => 'Tipiaje (padre)', 'tipo' => 'texto',
                'ayuda' => 'El grupo sanguíneo de la pareja (la columna se llama `tipiaje_cony`).'],
            ['columna' => 'observaciones', 'etiqueta' => 'Observaciones', 'tipo' => 'area',
                'ayuda' => 'Lo que haya que anotar de este embarazo.'],
        ];
    }
}
