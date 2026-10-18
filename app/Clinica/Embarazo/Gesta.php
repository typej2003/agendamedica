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
     * **Los valores del tipiaje**, los del DataWindow del escritorio (`d_pre_natal_observa.srd:11` y `:13`,
     * el `values=` de `tipiaje` y `tipiaje_cony`): ocho, **en ese orden**, con **`AB` sin Rh** y
     * **«Incógnito»** como un valor elegible (en el escritorio no es "no se sabe": es un dato).
     *
     * ⚠️ **La lista no se inventa ni se moderniza** (O-24): el valor viaja al escritorio por el sync y se
     * compara con lo que él escribió, así que `A+` tiene que ser `A+` y no `A positivo` ni `Rh+`. La columna
     * es `char(10)` y «Incógnito» entra sin tocar el esquema.
     *
     * El DataWindow **vivo** de la pestaña (`d_pre_natal_observa_2.srd:11`) declara **nueve**: muestra
     * `AB+` en lugar de `AB` y agrega `AB-`. La lista de acá es la que la observación fijó (los ocho del
     * `d_pre_natal_observa.srd`) y **un valor viejo con cualquiera de esas dos formas no se pierde**: se
     * muestra como la opción elegida (`OpcionesDelDesplegable`). Cambiar la lista es cambiar esta constante.
     *
     * @var array<int,string>
     */
    public const TIPIAJES = ['A+', 'A-', 'B-', 'AB', 'O+', 'O-', 'B+', 'Incógnito'];

    /** El bloque de la ficha: los datos clínicos de los dos renglones del escritorio. */
    public const DATOS = 'datos';

    /** El bloque del memo: las observaciones, en su campo grande debajo de los dos renglones. */
    public const MEMO = 'memo';

    /** El bloque del estado del embarazo: los tres controles chicos que el escritorio tiene en su barra lateral. */
    public const ESTADO = 'estado';

    /**
     * **El mapa de los campos de la gesta**: qué columna, con qué rótulo, con qué control y en qué lugar de la
     * pantalla se pinta.
     *
     * Los rótulos son los del DataWindow **vivo** de la pestaña (`d_pre_natal_observa_2.srd`, el que asigna
     * `w_consulta_principal.srw:24725`): *FUR*, *Menarquia*, *Gestas*, *Partos*, *Cesareas*, *Abortos*,
     * *Otros*, *Tipiaje* (con *Madre:* y *Padre:*: `tipiaje` es el de la madre y `tipiaje_cony` el del
     * padre), *Observaciones*, y los tres controles de la ventana que viven en columnas ocultas del
     * DataWindow: **Final de Embarazo** (`final`, `S`/`N`), **Embarazo multiple** (`multiple`, `S`/`N`) y
     * **Cantidad** (`cantidad`).
     *
     * **El orden y el agrupamiento son los del escritorio** (O-21, ver la captura
     * `24-control-de-embarazo-prenatal-con-gesta.png`): el DataWindow pone en el primer renglón
     * *FUR · FPP · EG · Menarquia · Gestas* (la FPP y la EG son `compute`, no columnas) y en el segundo
     * *Partos · Cesareas · Abortos · Otros · Tipiaje* (madre y padre); abajo va el **memo** de observaciones
     * y en su barra lateral, chiquitos, *Embarazo multiple*, *Cantidad* y *Final de Embarazo*. De ahí sale
     * `bloque`: **`DATOS`** (los dos renglones), **`MEMO`** (el memo grande) y **`ESTADO`** (los tres del
     * final, que en la web —sin barra lateral— van juntos y discretos al pie del bloque).
     *
     * `ancho` es la columna de la grilla de Bootstrap de cada campo, para que los renglones se lean como los
     * del escritorio y no como una lista de campos anchos iguales.
     *
     * ⚠️ **`parto` no está acá**: es la columna del rótulo *Parto:* del **otro** DataWindow
     * (`d_pre_natal_observa.srd`, el huérfano), y en la pestaña viva está `visible="0"`. La web no la toca.
     * **`otros` es un número** en la tabla (`integer`) y en el DataWindow (`long`): cuenta los embarazos que
     * no fueron parto, cesárea ni aborto.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function campos(): array
    {
        return [
            ['columna' => 'fur', 'etiqueta' => 'FUR', 'tipo' => 'fecha', 'fila' => 1,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-3'],
            ['columna' => 'menarquia', 'etiqueta' => 'Menarquia', 'tipo' => 'texto', 'fila' => 1,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-2'],
            ['columna' => 'gestas', 'etiqueta' => 'Gestas', 'tipo' => 'numero', 'fila' => 1,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-2'],
            ['columna' => 'partos', 'etiqueta' => 'Partos', 'tipo' => 'numero', 'fila' => 2,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-2'],
            ['columna' => 'cesarea', 'etiqueta' => 'Cesáreas', 'tipo' => 'numero', 'fila' => 2,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-2'],
            ['columna' => 'abortos', 'etiqueta' => 'Abortos', 'tipo' => 'numero', 'fila' => 2,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-2'],
            ['columna' => 'otros', 'etiqueta' => 'Otros', 'tipo' => 'numero', 'fila' => 2,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-2'],
            ['columna' => 'tipiaje', 'etiqueta' => 'Tipiaje (madre)', 'tipo' => 'opciones', 'fila' => 2,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-2',
                'opciones' => self::opcionesDelTipiaje()],
            ['columna' => 'tipiaje_cony', 'etiqueta' => 'Tipiaje (padre)', 'tipo' => 'opciones', 'fila' => 2,
                'bloque' => self::DATOS, 'ancho' => 'col-6 col-md-2',
                'opciones' => self::opcionesDelTipiaje()],
            ['columna' => 'observaciones', 'etiqueta' => 'Observaciones', 'tipo' => 'area',
                'bloque' => self::MEMO, 'ancho' => 'col-12', 'filas' => 6],
            // La barra lateral del escritorio, en su orden: la casilla del múltiple, la cantidad y la casilla
            // del final. En la web no hay columna derecha: van juntas al pie del bloque, discretas.
            ['columna' => 'multiple', 'etiqueta' => 'Embarazo múltiple', 'tipo' => 'casilla',
                'bloque' => self::ESTADO],
            ['columna' => 'cantidad', 'etiqueta' => 'Cantidad', 'tipo' => 'numero',
                'bloque' => self::ESTADO],
            ['columna' => 'final', 'etiqueta' => 'Final de embarazo', 'tipo' => 'casilla',
                'bloque' => self::ESTADO],
        ];
    }

    /**
     * Los **ocho** valores del tipiaje como opciones de un desplegable (`valor` = `texto`: lo que se guarda es
     * lo que se ve, que es lo que el escritorio escribe en su columna).
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    public static function opcionesDelTipiaje(): array
    {
        return array_map(fn (string $valor) => ['valor' => $valor, 'texto' => $valor], self::TIPIAJES);
    }
}
