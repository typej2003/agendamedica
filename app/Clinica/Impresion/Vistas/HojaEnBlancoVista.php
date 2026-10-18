<?php

namespace App\Clinica\Impresion\Vistas;

use App\Clinica\Impresion\FormatosEnBlanco;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\ModoDeLaHoja;
use App\Clinica\Impresion\OpcionesDeRecipe;
use App\Models\RecipeFormato;

/**
 * **Un formato en blanco** (WEB-5.5b): la hoja de un documento sin datos de paciente, con el membrete, la firma, el sello y el pie del
 * médico, lista para imprimir y rellenar a mano.
 *
 * No diseña una hoja paralela: arma las mismas «mitades» que pintan `clinica.imprimir._recipe-mitad` y `_documento-mitad` (las de la
 * hoja real de WEB-5.2 / 5.3 / 5.4), con el bloque del paciente y los datos cortos **vacíos** (`enBlanco`) y renglones en lugar del
 * texto. Una hoja por documento: no continúa en otra.
 *
 * El récipe respeta el modo guardado por el médico (*Rp. + Indicaciones* o *solo Indicaciones*); la propia página deja elegir el otro
 * con `?modo=` **sin guardar nada** (es un enlace GET).
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe el membrete y las opciones ya leídos.
 */
final class HojaEnBlancoVista
{
    /**
     * @param  string|null  $modoElegido  el `?modo=` de la página (uno de `RecipeFormato::MODOS_IMPRESION`); `null` usa el guardado
     * @param  array{volver:string, hoja:string}  $urls  adónde vuelve la barra y la dirección de esta misma hoja (para los enlaces del modo)
     */
    public function __construct(
        private string $clave,
        private Membrete $membrete,
        private OpcionesDeRecipe $opciones,
        private ?string $modoElegido,
        private array $urls,
    ) {
    }

    public function clave(): string
    {
        return $this->clave;
    }

    public function titulo(): string
    {
        return 'Formato en blanco · ' . FormatosEnBlanco::nombre($this->clave);
    }

    public function membrete(): Membrete
    {
        return $this->membrete;
    }

    public function urlVolver(): string
    {
        return $this->urls['volver'];
    }

    public function textoVolver(): string
    {
        return 'Volver al formato de impresión';
    }

    public function esRecipe(): bool
    {
        return FormatosEnBlanco::esRecipe($this->clave);
    }

    /** El modo con que sale el récipe: el que se eligió en la página o, si no, el guardado por el médico. */
    public function modo(): string
    {
        return in_array($this->modoElegido, RecipeFormato::MODOS_IMPRESION, true)
            ? $this->modoElegido
            : $this->opciones->modo;
    }

    /** "Sin datos de paciente · Rp. + Indicaciones · 1 hoja": lo que dice la barra. */
    public function resumen(): string
    {
        $resumen = 'Sin datos de paciente';

        if ($this->esRecipe()) {
            $resumen .= $this->modo() === RecipeFormato::MODO_RP_E_INDICACIONES ? ' · Rp. + Indicaciones' : ' · Solo indicaciones';
        }

        return $resumen . ' · 1 hoja';
    }

    /**
     * Los dos modos del récipe como enlaces (`[texto, url, activa]`) para el segmentado de la barra; vacío si no es el récipe.
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function modos(): array
    {
        if (! $this->esRecipe()) {
            return [];
        }

        return [
            ['texto' => 'Rp. + Indicaciones', 'url' => $this->urls['hoja'] . '?modo=' . RecipeFormato::MODO_RP_E_INDICACIONES, 'activa' => $this->modo() === RecipeFormato::MODO_RP_E_INDICACIONES],
            ['texto' => 'Solo indicaciones', 'url' => $this->urls['hoja'] . '?modo=' . RecipeFormato::MODO_SOLO_INDICACIONES, 'activa' => $this->modo() === RecipeFormato::MODO_SOLO_INDICACIONES],
        ];
    }

    /**
     * La hoja (siempre una). Trae el `modo` de la hoja —media o completa vertical, el mismo de la hoja real de ese documento
     * (O-26: `ModoDeLaHoja`, que es la única regla)—, el `tipo` (`recipe` o `documento`, que decide el parcial de cada mitad), el
     * `tamano` de la letra del cuerpo y sus `mitades`: una o dos.
     *
     * @return array<int,array{modo:string, tipo:string, etiqueta:?string, tamano:int, mitades:array<int,array<string,mixed>>}>
     */
    public function hojas(): array
    {
        return [[
            'modo' => ModoDeLaHoja::de($this->clave),
            'tipo' => $this->esRecipe() ? 'recipe' : 'documento',
            'etiqueta' => null,
            'tamano' => 10,
            'mitades' => $this->esRecipe() ? $this->mitadesDelRecipe() : [$this->mitadDelDocumento()],
        ]];
    }

    /** @return array<int,array<string,mixed>> */
    private function mitadesDelRecipe(): array
    {
        $mitades = [];

        if ($this->modo() === RecipeFormato::MODO_RP_E_INDICACIONES) {
            $mitades[] = $this->mitadDelRecipe('rp', 'Rp.');
        }

        $mitades[] = $this->mitadDelRecipe('indicaciones', 'Indicaciones:');

        return $mitades;
    }

    /** @return array<string,mixed> */
    private function mitadDelRecipe(string $clase, string $titulo): array
    {
        return [
            'clase' => $clase,
            'titulo' => $titulo,
            'etiqueta' => null,
            'paciente' => '',
            'cedula' => '',
            // La edad va en blanco (no `null`): para que salga el rótulo «EDAD:» con su línea.
            'edad' => '',
            'fecha' => '',
            'direccion' => null,
            'filas' => [],
            'proximaCita' => null,
            // Una hoja en blanco no crea ningún token de verificación: sin QR.
            'qr' => null,
            'enBlanco' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function mitadDelDocumento(): array
    {
        $plano = FormatosEnBlanco::plano($this->clave);

        return [
            'clase' => 'documento',
            'primera' => true,
            'titulo' => FormatosEnBlanco::titulo($this->clave),
            'paciente' => '',
            'cedula' => '',
            'edad' => '',
            'fecha' => '',
            'fijos' => array_map(fn (string $etiqueta) => ['etiqueta' => $etiqueta, 'valor' => ''], $plano['fijos']),
            'parrafos' => [],
            'secciones' => $plano['secciones'],
            'sigue' => false,
            'firma' => true,
            'enBlanco' => true,
        ];
    }
}
