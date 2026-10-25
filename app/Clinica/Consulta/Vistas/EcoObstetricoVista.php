<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Embarazo\EdadGestacional;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Support\FechaClinica;
use App\Support\FechaDeFormulario;

/**
 * **El eco obstétrico del primer trimestre en la sección de estudio** (WEB-6.2): el formulario de sus seis
 * bloques y el estado de lo que tiene cargado.
 *
 * Igual que `EcoGinecologicoVista` (el eco pélvico), no sabe de base de datos: recibe el **objeto de
 * valores** ya leído y el `old()` de un POST que falló, y devuelve lo que la vista pinta. Los campos salen
 * del **mapa del dato** (`EcoObstetrico::grupos()`), así que un campo nuevo del mapa aparece en la pantalla
 * —y en la hoja— sin tocar esto.
 *
 * Dos cosas propias de este estudio:
 *
 *  - **La FUR y la gesta se sugieren** del embarazo que el médico está siguiendo (el control prenatal): si el
 *    eco no las tiene, el campo lo dice y la Action las completa igual. La sugerencia es informativa.
 *  - El "¿esto es un documento?" cuenta los campos **del estudio**, sin la fecha, la FUR ni la gesta (las
 *    completa el servidor): un eco que sólo trae su FUR no ofrece hoja.
 *  - **La EG y la FPP no son columnas**: el escritorio las muestra con dos `compute` de su DataWindow
 *    (`int(daysafter(fur, today()) / 7) + ' Semanas y ' + mod(…, 7) + ' días'` y `relativedate(fur, 283)`,
 *    `d_eco_obstetrico_nueva.srd:123-124` y `d_eco_obstetrico_trimestral_nueva.srd:181-183`) y acá se
 *    calculan con `EdadGestacional` —la regla escrita una sola vez— porque la pantalla las muestra y
 *    `consulta.js` las rehace al teclear la FUR, **sin guardar**.
 */
class EcoObstetricoVista
{
    public function __construct(
        // `protected` para que el eco Doppler (WEB-6.3), que hereda de esta clase, pueda mostrar su informe
        // en texto libre (`puro_texto`), que los obstétricos todavía no muestran.
        protected EstudioEcografico $estudio,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private string $urlImprimir = '',
        private ?string $furSugerida = null,
        private ?int $gestaSugerida = null,
        private array $old = [],
        // La fecha con la que abre el estudio si todavía no tiene una guardada (O-13). La pasa el
        // controlador —hoy del consultorio, `FechaDelConsultorio::hoy()`— para que el ViewModel no consulte
        // el reloj ni la configuración: así se puede probar sin arrancar la app.
        private ?string $fechaPorDefecto = null,
    ) {
    }

    /** El mapa del dato de ese estudio (sus bloques). */
    protected function mapa(): array
    {
        return EcoObstetrico::grupos();
    }

    /** Las columnas que edita ese estudio, para contar lo cargado. */
    protected function columnasDelEstudio(): array
    {
        return EcoObstetrico::columnas();
    }

    /**
     * Las columnas de la **ficha** de ese estudio: no cuentan como "campos cargados" porque las completa el
     * servidor o son del embarazo, no hallazgos. Los dos obstétricos excluyen la fecha, la FUR y la gesta; el
     * **eco Doppler** (WEB-6.3) reemplaza esta lista por la suya, que suma el `para`.
     *
     * @return array<int,string>
     */
    protected function columnasQueNoCuentan(): array
    {
        return ['fecha', 'fur', 'gesta'];
    }

    public function titulo(): string
    {
        return 'Eco obstétrico (1.er trimestre)';
    }

    /** El icono del tipo, para la tarjeta. */
    public function icono(): string
    {
        return TiposDeEstudio::icono(TiposDeEstudio::OBSTETRICO);
    }

    /**
     * Los bloques del formulario, con el valor de cada campo ya resuelto (lo escrito si el POST falló, si no
     * lo guardado).
     *
     * @return array<int,array<string,mixed>>
     */
    public function grupos(): array
    {
        $grupos = [];

        foreach ($this->mapa() as $grupo) {
            $campos = [];
            foreach ($grupo['campos'] as $campo) {
                $campo['valor'] = $this->valorDelCampo($campo);
                // Un desplegable necesita sus opciones con la vacía adelante —y el código desconocido del
                // escritorio al final—: sin eso el navegador manda su primera opción y un eco recién abierto
                // guardaba «Sí» en todas sus casillas (la misma regla del eco pélvico).
                $campo['opciones'] = ($campo['tipo'] ?? 'texto') === 'opciones'
                    ? OpcionesDelDesplegable::de($campo, $campo['valor'])
                    : [];
                $campos[] = $campo;
            }

            $grupos[] = ['titulo' => $grupo['titulo'], 'icono' => $grupo['icono'], 'campos' => $campos];
        }

        return $grupos;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    /**
     * La **FPP** del estudio, calculada de su FUR y formateada `dd/mm/aaaa`, o `null` si no hay FUR.
     *
     * Es el `relativedate(fur, …)` del escritorio, con la **constante única** de {@see EdadGestacional}
     * (280 días): la misma fecha que muestran el control prenatal y la hoja del estudio.
     */
    public function fpp(): ?string
    {
        $fpp = EdadGestacional::fpp(
            $this->furVigente(),
            EdadGestacional::DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO,
        );

        return $fpp === null ? null : FechaClinica::formato($fpp);
    }

    /**
     * La **edad gestacional** de la FUR, como la lee el médico ("36 semanas y 2 días"), o `null` si la FUR no
     * sirve (vacía, ilegible o futura). Es el `compute` del DataWindow, con la cuenta de siempre.
     */
    public function edadGestacional(): ?string
    {
        return EdadGestacional::de($this->furVigente())?->texto();
    }

    /** El día de la cuenta (`AAAA-MM-DD`) para la EG en vivo del JS: el mismo que usa el servidor. */
    public function hoyIso(): string
    {
        return \App\Clinica\FechaDelConsultorio::hoyComoFecha()->toDateString();
    }

    /**
     * **La FUR con la que se calculan la EG y la FPP**: la que se ve en el campo. Es la que volvió en un POST
     * que falló, si no la guardada y, si el estudio todavía no tiene ninguna, **la de la gesta en curso** del
     * control prenatal (la misma que el campo muestra prellenada): las dos cuentas tienen que decir lo mismo
     * que el campo, también en la primera carga.
     */
    private function furVigente(): ?string
    {
        if (array_key_exists('fur', $this->old)) {
            return FechaDeFormulario::aBase($this->old['fur'], $this->old[FechaDeFormulario::CAMPO_ORDEN] ?? null);
        }

        return $this->estudio->fur() ?? $this->furSugerida;
    }

    /**
     * La **clave del documento** que imprime el botón de la sección (`eco-obstetrico`, `eco-doppler`…): la que el botón
     * manda para guardar y después imprimir (O-16). Sale de la URL de la hoja, que ya la lleva, así que un tipo nuevo no
     * tiene que declararla dos veces.
     */
    public function documento(): string
    {
        return DocumentosParaImprimir::claveDeLaUrl($this->urlImprimir);
    }

    /** La ayuda de la pantalla: qué es el estudio y de dónde salen la FUR y la gesta. */
    public function ayuda(): string
    {
        $ayuda = 'La ecografía del embarazo temprano: el saco, la vitalidad del embrión, las medidas (LCC, DBP, '
            . 'translucencia nucal) y los marcadores de riesgo. Se guarda con el botón.';

        return $ayuda;
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el eco obstétrico. Si sales ahora, se pierden.';
    }

    /** Cuántos campos del estudio tienen algo cargado (sin contar la ficha del embarazo). */
    public function cargados(): int
    {
        $cuenta = 0;

        foreach (array_diff($this->columnasDelEstudio(), $this->columnasQueNoCuentan()) as $columna) {
            $valor = $this->valorDe($columna);

            if ($valor !== null && $valor !== '') {
                $cuenta++;
            }
        }

        return $cuenta;
    }

    /** Cuántos campos del estudio se pueden cargar. */
    public function totalCampos(): int
    {
        return count($this->columnasDelEstudio()) - count($this->columnasQueNoCuentan());
    }

    /** ¿El estudio dice algo? Es la regla de la Action, dicha en pantalla. */
    public function esUnDocumento(): bool
    {
        return $this->cargados() > 0;
    }

    /**
     * El estado de la pestaña como pastilla: cuántos campos del estudio están cargados.
     *
     * @return array{tono:string, icono:string, texto:string, titulo:string}
     */
    public function estado(): array
    {
        if (! $this->esUnDocumento()) {
            return ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Un eco sin datos del estudio no se guarda ni se ofrece su informe'];
        }

        $cargados = $this->cargados();

        return ['tono' => 'exito', 'icono' => 'bi-check2-circle',
            'texto' => $cargados . ' de ' . $this->totalCampos() . ' campos',
            'titulo' => 'Lo que tiene cargado el estudio'];
    }

    /** Lo escrito en el POST que falló, si no lo guardado. */
    private function valorDe(string $columna): ?string
    {
        if (array_key_exists($columna, $this->old) && $this->old[$columna] !== null) {
            return (string) $this->old[$columna];
        }

        return $this->estudio->valor($columna);
    }

    /**
     * El valor que se pinta en un campo del mapa.
     *
     * Una **fecha tecleada** (la FUR de los obstétricos, el Doppler y el cardiograma fetal) se muestra
     * `dd/mm/aaaa`, que es como se escribe en la pantalla, aunque en la columna esté en `AAAA-MM-DD`
     * (O-18 y O-19). Lo que hay en la columna y **no** es una fecha —un dato viejo escrito a mano, el
     * literal `none` del legado— se muestra **vacío**: no se inventa una fecha.
     *
     * Si el estudio **no tiene FUR** guardada ni escrita, el campo abre con la **FUR de la gesta en curso**
     * del control prenatal (`furSugerida`), sin preguntar: es la misma FUR del mismo embarazo y el médico no
     * la teclea dos veces. La guardada y la que volvió en un POST que falló mandan siempre.
     *
     * Los demás campos salen tal cual, como siempre.
     */
    private function valorDelCampo(array $campo): string
    {
        $columna = $campo['columna'];
        $tipo = $campo['tipo'] ?? 'texto';

        // O-13: **la fecha del estudio abre con hoy** (la del consultorio) cuando todavía no hay una guardada;
        // es lo que hace el escritorio al abrir la consulta sin fila (`fecha = today()`). La guardada manda y
        // lo que volvió en un POST que falló también: el default es sólo para el campo que estaba vacío.
        if ($tipo === 'fecha') {
            if (array_key_exists($columna, $this->old)) {
                return (string) ($this->old[$columna] ?? '');
            }

            return $this->estudio->valor($columna) ?? (string) $this->fechaPorDefecto;
        }

        if ($tipo !== 'fecha-tecleada') {
            return (string) ($this->valorDe($columna) ?? '');
        }

        // Lo que se acaba de teclear se muestra como se escribió (aunque no sea una fecha: el error está
        // abajo y así el médico lo corrige); lo guardado, sólo si es una fecha.
        if (array_key_exists($columna, $this->old) && $this->old[$columna] !== null) {
            return FechaDeFormulario::comoSeTecleo($this->old[$columna]);
        }

        $guardado = $this->estudio->valor($columna);

        if ($guardado !== null) {
            return FechaDeFormulario::paraTeclear($guardado);
        }

        // La FUR de la gesta en curso del control prenatal, sólo para la FUR y sólo si el estudio no la tiene.
        if ($columna === 'fur' && $this->furSugerida !== null) {
            return FechaDeFormulario::paraTeclear($this->furSugerida);
        }

        return '';
    }
}
