<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EstudioEcografico;

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
 */
class EcoObstetricoVista
{
    public function __construct(
        private EstudioEcografico $estudio,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private string $urlImprimir = '',
        private ?string $furSugerida = null,
        private ?int $gestaSugerida = null,
        private array $old = [],
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
                $campo['valor'] = (string) ($this->valorDe($campo['columna']) ?? '');
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

    /** La ayuda de la pantalla: qué es el estudio y de dónde salen la FUR y la gesta. */
    public function ayuda(): string
    {
        $ayuda = 'La ecografía del embarazo temprano: el saco, la vitalidad del embrión, las medidas (LCC, DBP, '
            . 'translucencia nucal) y los marcadores de riesgo. Se guarda con el botón.';

        if ($this->furSugerida !== null) {
            $ayuda .= ' La FUR y la gesta se completan con las del control prenatal de la historia ('
                . 'FUR ' . $this->furSugerida . ($this->gestaSugerida !== null ? ', gesta ' . $this->gestaSugerida : '')
                . ') si no las escribís acá.';
        }

        return $ayuda;
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el eco obstétrico. Si sales ahora, se pierden.';
    }

    /** Cuántos campos del estudio tienen algo cargado (sin contar la fecha, la FUR ni la gesta). */
    public function cargados(): int
    {
        $cuenta = 0;

        foreach (array_diff($this->columnasDelEstudio(), ['fecha', 'fur', 'gesta']) as $columna) {
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
        return count($this->columnasDelEstudio()) - 3;
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
}
