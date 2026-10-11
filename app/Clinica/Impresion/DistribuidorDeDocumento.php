<?php

namespace App\Clinica\Impresion;

/**
 * **Reparte un documento en mitades de hoja** (WEB-5.3a): decide el tamaño de la letra y, si el texto no entra en una mitad,
 * **dónde sigue en la otra** —y en las siguientes si hiciera falta—, cortando por palabra.
 *
 * Es la regla del móvil (`DoctorisimoMobile/lib/features/reportes/pdf/reporte_pdf.dart`, decisión del usuario del
 * 2026-09-27) llevada a la web, con las medidas de la hoja del récipe (`MedidasDeLaMitad`, `MedidorDeTexto`):
 *
 *  1. Un documento es **media hoja**: la mitad izquierda del A4 horizontal. La derecha queda en blanco.
 *  2. **Si el texto no entra, sigue en la otra mitad.** La primera se llena con todo lo que entra y lleva al pie la línea
 *     «Continúa →»; la segunda es la **continuación**: sin membrete, con un encabezado corto (`PACIENTE` y `FECHA`), el título
 *     «… (continuación)», el resto del texto y **la firma**. La firma va sólo en la última mitad.
 *  3. **La letra baja por escalones**: 10, 9 y 8 pt (los mismos de la hoja del récipe, `DistribuidorDeRecipe`). Gana el
 *     escalón que necesite **menos hojas** y, a igualdad, la letra más grande: un texto que cabe en las dos mitades de una hoja
 *     a 10 pt sale a 10 pt aunque a 8 cupiera en una sola mitad. Es lo que hace el móvil (usa la letra más grande con la que
 *     el documento cabe en las dos mitades).
 *  4. **Nunca se corta una palabra ni se pierde texto.** El móvil, si ni a la letra más chica entra en las dos mitades,
 *     fuerza el corte y puede recortar; acá, si a 8 pt todavía no entra, el documento **sigue en una hoja más** (otro par de
 *     mitades, con la misma continuación) hasta que quepa todo.
 *  5. Los **datos fijos** (los rótulos con su valor: `REPOSO DURANTE`, `REFERIDO A`…) no se parten: van en la primera mitad.
 *  6. Si todo el texto entra en la mitad sin firma pero no con ella, la firma **no queda sola** en la siguiente: pasa con ella
 *     el último párrafo (o, si hay uno solo, sus últimas palabras), como en el móvil.
 *
 * Cada constante de la cuenta tiene su gemela en `public/css/clinica-documento.css` (el comentario de cada regla dice
 * cuál): el cuerpo se mide por el servidor y se dibuja por el navegador, y si una cambia sola el texto se desborda. Lo ata el
 * verificador de Chrome (`.agent-tools/verificar-solicitudes-documentos-web.js`).
 *
 * Es una clase **sin estado y sin base de datos**.
 */
final class DistribuidorDeDocumento
{
    /** Los tamaños de letra del cuerpo, del más grande al más chico, en puntos (los de `DistribuidorDeRecipe`). */
    public const ESCALONES = [10, 9, 8];

    /** El ancho del cuerpo: el útil menos la sangría (`.recipe-cuerpo`). */
    private const ANCHO_DEL_CUERPO_MM = MedidasDeLaMitad::ANCHO_UTIL_MM - MedidasDeLaMitad::SANGRIA_DEL_CUERPO_MM;

    /** El aire debajo de cada párrafo (`.documento-parrafo`). */
    private const AIRE_DE_UN_PARRAFO_MM = 2.6;

    /** El aire debajo de cada dato fijo (`.documento-fijo`) y debajo del bloque de datos fijos (`.documento-fijos`). */
    private const AIRE_DE_UN_FIJO_MM = 1.2;

    private const AIRE_DE_LOS_FIJOS_MM = 2.4;

    /** Un párrafo con rótulo en su propia línea suma el aire entre el rótulo y el texto (`.documento-rotulo-aparte`). */
    private const AIRE_DEL_ROTULO_MM = 0.8;

    /** Las palabras que pasan con la firma cuando todo el texto es un solo párrafo (`_palabrasMinimas` del móvil). */
    private const PALABRAS_CON_LA_FIRMA = 20;

    /** Un tope de mitades: un documento real no pasa de 3 o 4; esto es sólo para no entrar en un ciclo con una medida absurda. */
    private const MAXIMO_DE_MITADES = 60;

    public function __construct(
        private MedidasDeLaMitad $medidas = new MedidasDeLaMitad(),
        private MedidorDeTexto $medidor = new MedidorDeTexto(),
    ) {
    }

    /**
     * @return array{puntos:int, mitades:array<int,array{primera:bool, sigue:bool, firma:bool, fijos:array<int,array{etiqueta:string, valor:string}>, parrafos:array<int,array{texto:string, etiqueta:?string, aparte:bool}>}>}
     */
    public function distribuir(Membrete $membrete, DocumentoImpreso $documento): array
    {
        $mejor = null;

        foreach (self::ESCALONES as $puntos) {
            $mitades = $this->acomodar($membrete, $documento, $puntos);

            // Menos hojas gana (una hoja = dos mitades); a igualdad se queda el primero que se vio, que es la letra más grande.
            if ($mejor === null || (int) ceil(count($mitades) / 2) < (int) ceil(count($mejor['mitades']) / 2)) {
                $mejor = ['puntos' => $puntos, 'mitades' => $mitades];
            }
        }

        return $mejor;
    }

    /**
     * Llena las mitades en orden con el texto que entra en cada una.
     *
     * @return array<int,array{primera:bool, sigue:bool, firma:bool, fijos:array, parrafos:array}>
     */
    private function acomodar(Membrete $membrete, DocumentoImpreso $documento, int $puntos): array
    {
        $nombre = $documento->persona['nombre'];
        $restantes = $documento->parrafos;
        $primera = true;
        $mitades = [];

        while (count($mitades) < self::MAXIMO_DE_MITADES) {
            $fijos = $primera ? $documento->fijos : [];

            // ¿Lo que queda entra en esta mitad **con la firma**? Entonces es la última.
            if ($this->alto($fijos, $restantes, $puntos) <= $this->medidas->altoDelCuerpoDeDocumento($membrete, $nombre, $primera, true)) {
                $mitades[] = ['primera' => $primera, 'sigue' => false, 'firma' => true, 'fijos' => $fijos, 'parrafos' => $restantes];

                return $mitades;
            }

            // Si no, se llena esta mitad (sin firma, con el «Continúa →») con todo lo que entra.
            $capacidad = $this->medidas->altoDelCuerpoDeDocumento($membrete, $nombre, $primera, false);
            $total = $this->palabras($restantes);
            $cuantas = $this->mayorCorteQueEntra($fijos, $restantes, $total, $puntos, $capacidad);

            // Todo el texto entra sin firma pero no con ella: la firma no puede quedar sola en la mitad siguiente.
            if ($cuantas >= $total) {
                $cuantas = $this->corteSinDejarLaFirmaSola($restantes, $total);
            }

            // Una mitad que no avanza (una medida absurda) se lleva al menos una palabra: así siempre termina.
            if ($cuantas <= 0 && $total > 0 && ! $primera) {
                $cuantas = 1;
            }

            [$dentro, $resto] = $this->partir($restantes, $cuantas);

            $mitades[] = ['primera' => $primera, 'sigue' => true, 'firma' => false, 'fijos' => $fijos, 'parrafos' => $dentro];

            if ($resto === []) {
                // No queda texto pero sí falta la firma: va sola en una mitad de continuación (no debería pasar).
                $mitades[] = ['primera' => false, 'sigue' => false, 'firma' => true, 'fijos' => [], 'parrafos' => []];

                return $mitades;
            }

            $restantes = $resto;
            $primera = false;
        }

        return $mitades;
    }

    /** La mayor cantidad de palabras (de 0 a `$total`) que entra en `$capacidad` mm junto a los datos fijos. */
    private function mayorCorteQueEntra(array $fijos, array $parrafos, int $total, int $puntos, float $capacidad): int
    {
        $entra = function (int $palabras) use ($fijos, $parrafos, $puntos, $capacidad) {
            [$dentro] = $this->partir($parrafos, $palabras);

            return $this->alto($fijos, $dentro, $puntos) <= $capacidad;
        };

        if (! $entra(0)) {
            return 0;
        }

        $bajo = 0;
        $alto = $total;

        while ($bajo < $alto) {
            $medio = intdiv($bajo + $alto + 1, 2);

            if ($entra($medio)) {
                $bajo = $medio;
            } else {
                $alto = $medio - 1;
            }
        }

        return $bajo;
    }

    /** Dónde cortar para que el último párrafo (o sus últimas palabras, si hay uno solo) acompañe a la firma. */
    private function corteSinDejarLaFirmaSola(array $parrafos, int $total): int
    {
        if ($parrafos === []) {
            return $total;
        }

        if (count($parrafos) > 1) {
            return $total - $this->palabrasDe($parrafos[array_key_last($parrafos)]['texto']);
        }

        return max(1, $total - min(self::PALABRAS_CON_LA_FIRMA, intdiv($total, 2)));
    }

    // ----------------------------------------------------------------------------------------------- Alturas

    /**
     * El alto del cuerpo con esos datos fijos y esos párrafos a `$puntos` pt, en milímetros.
     *
     * @param  array<int,array{etiqueta:string, valor:string}>  $fijos
     * @param  array<int,array{texto:string, etiqueta:?string, aparte:bool}>  $parrafos
     */
    private function alto(array $fijos, array $parrafos, int $puntos): float
    {
        $linea = $puntos * MedidasDeLaMitad::INTERLINEA * MedidorDeTexto::MM_POR_PUNTO;
        $alto = 0.0;

        foreach ($fijos as $fijo) {
            $alto += max($this->medidor->lineas($fijo['etiqueta'] . ' ' . $fijo['valor'], self::ANCHO_DEL_CUERPO_MM, $puntos, true), 1) * $linea
                + self::AIRE_DE_UN_FIJO_MM;
        }

        if ($fijos !== []) {
            $alto += self::AIRE_DE_LOS_FIJOS_MM;
        }

        foreach ($parrafos as $parrafo) {
            $alto += $this->altoDeUnParrafo($parrafo, $puntos, $linea) + self::AIRE_DE_UN_PARRAFO_MM;
        }

        return $alto;
    }

    /** @param array{texto:string, etiqueta:?string, aparte:bool} $parrafo */
    private function altoDeUnParrafo(array $parrafo, int $puntos, float $linea): float
    {
        if ($parrafo['etiqueta'] === null) {
            return max($this->medidor->lineas($parrafo['texto'], self::ANCHO_DEL_CUERPO_MM, $puntos), 1) * $linea;
        }

        if ($parrafo['aparte']) {
            return $linea + self::AIRE_DEL_ROTULO_MM
                + max($this->medidor->lineas($parrafo['texto'], self::ANCHO_DEL_CUERPO_MM, $puntos), 1) * $linea;
        }

        // El rótulo va en negrita al lado del texto: se mide todo junto, que con el rótulo en la cuenta es más largo
        // (conservador, como el resto del medidor).
        return max($this->medidor->lineas($parrafo['etiqueta'] . '  ' . $parrafo['texto'], self::ANCHO_DEL_CUERPO_MM, $puntos), 1) * $linea;
    }

    // ----------------------------------------------------------------------------------------------- Palabras

    /** @param array<int,array{texto:string, etiqueta:?string, aparte:bool}> $parrafos */
    private function palabras(array $parrafos): int
    {
        return array_sum(array_map(fn (array $parrafo) => $this->palabrasDe($parrafo['texto']), $parrafos));
    }

    private function palabrasDe(string $texto): int
    {
        return count(preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Las primeras `$n` palabras y el resto, respetando los párrafos. Un párrafo partido deja su rótulo en la primera parte.
     *
     * @param  array<int,array{texto:string, etiqueta:?string, aparte:bool}>  $parrafos
     * @return array{0:array<int,array{texto:string, etiqueta:?string, aparte:bool}>, 1:array<int,array{texto:string, etiqueta:?string, aparte:bool}>}
     */
    private function partir(array $parrafos, int $n): array
    {
        $primera = [];
        $resto = [];
        $quedan = $n;

        foreach ($parrafos as $parrafo) {
            $palabras = preg_split('/\s+/u', trim($parrafo['texto']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($quedan >= count($palabras)) {
                $primera[] = $parrafo;
                $quedan -= count($palabras);
            } elseif ($quedan > 0) {
                $primera[] = ['texto' => implode(' ', array_slice($palabras, 0, $quedan)), 'etiqueta' => $parrafo['etiqueta'], 'aparte' => $parrafo['aparte']];
                $resto[] = ['texto' => implode(' ', array_slice($palabras, $quedan)), 'etiqueta' => null, 'aparte' => false];
                $quedan = 0;
            } else {
                $resto[] = $parrafo;
            }
        }

        return [$primera, $resto];
    }
}
