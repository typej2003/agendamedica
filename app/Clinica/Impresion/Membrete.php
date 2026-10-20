<?php

namespace App\Clinica\Impresion;

/**
 * **El membrete, la firma y el pie del médico** tal como los imprime cualquier documento de la web (WEB-5.2):
 * el nombre, la especialidad, el RIF y el logo con la configuración de cada bloque, la línea separadora, la
 * firma y el sello, y las tres líneas de pie de página.
 *
 * Es un objeto de valores ya resuelto —con los defaults del legado aplicados y las imágenes comprobadas—, que
 * arma `MembreteDelMedico` y que reusan los documentos que siguen (constancias, reposos, referencias…).
 * No lee la base ni el disco.
 *
 * **El membrete y la firma pueden venir de dos fichas distintas** (Fase 5 del cambio de `reg_medico`): cuando
 * el centro médico configura `formato_reportes = 'centro'`, el nombre, la especialidad, el RIF, el logo, el pie
 * y el aspecto de los bloques son **del centro**, y la firma, el sello y el nombre de la línea de la firma son
 * **del doctor que atendió** (su propia fila de `recipe_formatos`). En el consultorio personal las dos cosas
 * son del doctor y el objeto sale como siempre.
 */
final class Membrete
{
    /** Los colores de la línea separadora y su valor CSS (los mismos nueve del combo del legado y del móvil). */
    public const COLORES = [
        'negro' => '#000000', 'blanco' => '#ffffff', 'rojo' => '#c62828', 'marron' => '#6d4c41',
        'fucsia' => '#d81b60', 'verde' => '#2e7d32', 'gris' => '#757575', 'amarillo' => '#f9a825', 'azul' => '#1565c0',
    ];

    /** El alto del logo en milímetros (`TamanoLogo` del móvil: 50, 70 y 90 puntos). */
    public const ALTO_DEL_LOGO_MM = ['chico' => 17.6, 'mediano' => 24.7, 'grande' => 31.8];

    /**
     * @param  array<string,array<string,mixed>>  $elementos  `logo`, `medico`, `especialidad`, `rif` y `pie`, completos
     * @param  array<int,string>  $pie  las líneas de pie que tienen texto, en orden (hasta tres)
     * @param  string|null  $firmante  el nombre que va **sobre la línea de la firma** cuando no es el del membrete.
     *                                 Con el formato de un **centro** (Fase 5 del cambio de `reg_medico`) el
     *                                 encabezado es el del centro y firma el doctor que atendió; `null` = firma el
     *                                 mismo del membrete. Los demás campos —la firma, el sello y su posición— ya son
     *                                 los del doctor que firma desde `MembreteDelMedico`.
     */
    public function __construct(
        public readonly string $medico,
        public readonly ?string $especialidad,
        public readonly ?string $rif,
        public readonly ?string $logoUrl,
        public readonly array $pie,
        public readonly array $elementos,
        public readonly string $colorLinea,
        public readonly ?string $firmaUrl,
        public readonly ?string $selloUrl,
        public readonly string $selloPosicion,
        public readonly ?string $firmante = null,
    ) {
    }

    /** ¿El logo se imprime? Hace falta el archivo **y** que el médico no lo haya ocultado. */
    public function conLogo(): bool
    {
        return $this->logoUrl !== null && ($this->elementos['logo']['visible'] ?? true) !== false;
    }

    /** @return array<string,mixed> */
    public function estiloDe(string $elemento): array
    {
        return $this->elementos[$elemento] ?? [];
    }

    public function altoDelLogoMm(): float
    {
        return self::ALTO_DEL_LOGO_MM[$this->elementos['logo']['tamano'] ?? 'mediano'] ?? self::ALTO_DEL_LOGO_MM['mediano'];
    }

    /** El color de la línea separadora como valor CSS. */
    public function colorCss(): string
    {
        return self::COLORES[$this->colorLinea] ?? self::COLORES['negro'];
    }

    /**
     * El nombre que va **sobre la línea de la firma**: el del doctor que atendió o, cuando el formato es el
     * del centro, el del membrete (que es el mismo doctor en el consultorio personal).
     */
    public function nombreDelFirmante(): string
    {
        return $this->firmante === null || trim($this->firmante) === '' ? $this->medico : $this->firmante;
    }

    // ------------------------------------------------------------------ Lo que pinta el componente

    /**
     * Cómo se acomoda el membrete: con el logo a la izquierda, al centro (encima de los textos) o a la derecha,
     * o `sin-logo`. Es una clase CSS: la vista no calcula nada.
     */
    public function distribucion(): string
    {
        return $this->conLogo() ? 'logo-' . ($this->elementos['logo']['alineacion'] ?? 'izquierda') : 'sin-logo';
    }

    /** Las clases del logo: su tamaño y, si el médico lo pidió, el borde. */
    public function clasesDelLogo(): string
    {
        $logo = $this->estiloDe('logo');

        return trim('logo-' . ($logo['tamano'] ?? 'mediano') . (($logo['borde'] ?? false) ? ' con-borde' : ''));
    }

    /**
     * Los tres textos del encabezado que se imprimen —médico, especialidad y RIF—, en ese orden: sólo los que
     * tienen texto y que el médico no ocultó. Cada uno con las clases de su configuración.
     *
     * @return array<int,array{elemento:string, texto:string, clases:string}>
     */
    public function textos(): array
    {
        $textos = [];

        foreach (['medico' => $this->medico, 'especialidad' => $this->especialidad, 'rif' => $this->rif] as $elemento => $texto) {
            if ($texto === null || trim($texto) === '' || ($this->estiloDe($elemento)['visible'] ?? true) === false) {
                continue;
            }

            $textos[] = ['elemento' => $elemento, 'texto' => $texto, 'clases' => $this->clasesDeTexto($elemento)];
        }

        return $textos;
    }

    /** Las líneas del pie de página, o ninguna si el médico ocultó ese bloque. */
    public function lineasDelPie(): array
    {
        return ($this->estiloDe('pie')['visible'] ?? true) === false ? [] : $this->pie;
    }

    /** `alin-centro fuente-arimo negrita con-borde`: la alineación, la fuente y el estilo de un bloque de texto. */
    public function clasesDeTexto(string $elemento): string
    {
        $estilo = $this->estiloDe($elemento);

        return implode(' ', array_filter([
            'alin-' . ($estilo['alineacion'] ?? 'centro'),
            'fuente-' . ($estilo['fuente'] ?? 'arimo'),
            ($estilo['negrita'] ?? false) ? 'negrita' : null,
            ($estilo['italica'] ?? false) ? 'italica' : null,
            ($estilo['subrayado'] ?? false) ? 'subrayado' : null,
            ($estilo['borde'] ?? false) ? 'con-borde' : null,
        ]));
    }
}
