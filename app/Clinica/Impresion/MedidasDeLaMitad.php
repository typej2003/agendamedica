<?php

namespace App\Clinica\Impresion;

/**
 * **Las medidas de una mitad de la hoja del récipe** (WEB-5.2) y cuánto alto le queda al cuerpo.
 *
 * La hoja es un A4 horizontal (297 × 210 mm) partido en dos mitades de 148,5 × 210 mm. Cada mitad es un
 * récipe completo, con su membrete arriba y su firma y pie abajo, y **lo que sobra en el medio es el cuerpo**:
 * la lista de medicamentos. El navegador no sabe pasar a una segunda hoja repitiendo el membrete y la firma, así
 * que quien decide cuántos medicamentos entran es el servidor, y para eso tiene que saber cuánto alto queda.
 *
 * Esta clase es esa cuenta. **Cada constante tiene su gemela en `public/css/clinica-recipe.css`** (el comentario
 * de cada regla dice cuál) y tienen que cambiar juntas; la prueba de navegador del verificador
 * (`.agent-tools/verificar-recipe-impresion-web.js`) comprueba que el cuerpo real no se desborda con las
 * combinaciones de membrete más pesadas.
 *
 * ## La cuenta
 *
 * ```
 * 210,0  alto de la hoja
 *  − 17,0  márgenes (9 arriba, 8 abajo)                       = 193,0 mm útiles
 *  −  M    membrete (logo y textos, ver `altoDelMembrete`)
 *  −  5,0  línea separadora y su aire
 *  −  D    datos del paciente (ver `altoDeLosDatos`) + 4,0 de aire
 *  −  8,7  título "Rp." / "Indicaciones:" y su aire
 *  − 24,0  firma, sello, próxima cita y el lugar reservado para el QR (22) + 2 de aire
 *  −  P    pie de página: 1,5 de aire + 3,1 por línea
 *  −  4,0  reserva de seguridad
 * ```
 *
 * Para el médico típico (logo mediano a la izquierda, tres líneas de pie) el cuerpo queda en ~102 mm: seis o siete
 * medicamentos con dos líneas de indicación.
 *
 * Es una clase **sin estado y sin base de datos**.
 */
final class MedidasDeLaMitad
{
    /** Ancho útil de la mitad: 148,5 − 10 − 10 de márgenes laterales (`.recipe-mitad` en el CSS). */
    public const ANCHO_UTIL_MM = 128.5;

    /** Alto útil de la mitad: 210 − 9 − 8 de márgenes (`.recipe-mitad` en el CSS). */
    public const ALTO_UTIL_MM = 193.0;

    /** La sangría del cuerpo respecto de los márgenes (`.recipe-cuerpo` en el CSS). */
    public const SANGRIA_DEL_CUERPO_MM = 3.0;

    /** Interlínea de todo el documento (`line-height` del CSS). */
    public const INTERLINEA = 1.25;

    /** Línea separadora y su aire: `.recipe-linea` (0,5 + 2 arriba + 2,5 abajo). */
    private const LINEA_MM = 5.0;

    /** El aire entre los datos del paciente y el título (`.recipe-datos`). */
    private const AIRE_DE_LOS_DATOS_MM = 4.0;

    /** El título ("Rp.", 14 pt) y su aire (`.recipe-titulo`). */
    private const TITULO_MM = 8.7;

    /** La franja de firma (22) y su aire (2): `.recipe-firmas`. */
    private const FIRMA_MM = 24.0;

    /** El aire encima del pie de página: `.recipe-pie`. */
    private const AIRE_DEL_PIE_MM = 1.5;

    private const RESERVA_MM = 4.0;

    /**
     * La línea «Continúa →» al pie del cuerpo de una mitad que sigue en la otra (WEB-5.3a): 8 pt itálica y 1,5 de aire
     * (`.documento-continua`). Reemplaza a la franja de firma, que sólo lleva la última mitad de un documento.
     */
    private const CONTINUA_MM = 5.2;

    /** El título de una mitad de continuación (11 pt) y su aire: `.documento-titulo-continuacion` (4,85 + 2,5). */
    private const TITULO_CONTINUACION_MM = 7.4;

    /**
     * Lo que ocupan la fecha y el «PACIENTE:» en el encabezado corto de una mitad de continuación
     * (`.documento-continuacion-datos`): «PACIENTE:» ~17 mm y «FECHA: dd/mm/aaaa» ~30 mm.
     */
    private const ETIQUETA_DEL_PACIENTE_MM = 17.0;

    private const FECHA_DE_CONTINUACION_MM = 30.0;

    /** La separación entre el logo y los textos, cuando el logo va al lado (`.membrete-con-logo`). */
    private const SEPARACION_DEL_LOGO_MM = 3.0;

    /**
     * El ancho máximo que se le reserva al logo (`.membrete-logo` → `max-width`): una imagen más ancha se
     * achica. Se usa para saber cuánto ancho tienen los textos cuando el logo va a un costado.
     */
    private const ANCHO_DEL_LOGO_MM = 40.0;

    /** El borde de un bloque con "mostrar borde": relleno y raya (`.membrete-con-borde`). */
    private const BORDE_MM = 1.4;

    /**
     * Cuánto más (o menos) ancha es cada fuente que Arimo, que es la que mide `MedidorDeTexto`. Es un
     * factor **conservador**: Times es más angosta, pero se la trata como Arimo para no quedarse corto.
     */
    private const FACTOR_DE_FUENTE = [
        'arimo' => 1.0, 'tinos' => 1.0, 'cousine' => 1.18, 'comic_neue' => 1.06, 'dejavu_sans' => 1.2,
    ];

    /** Tamaño de cada texto del membrete, en puntos (`.membrete-medico`, `-especialidad`, `-rif`, `-pie`). */
    private const PUNTOS = ['medico' => 16.0, 'especialidad' => 9.0, 'rif' => 9.0, 'pie' => 7.0];

    public function __construct(private MedidorDeTexto $medidor = new MedidorDeTexto())
    {
    }

    /**
     * El alto que queda para la lista de medicamentos, en milímetros.
     *
     * @param  string  $paciente  el nombre que se imprime en `PACIENTE`
     * @param  string|null  $direccion  la dirección, sólo en la mitad "Rp." (en la de indicaciones es `null`)
     */
    public function altoDelCuerpo(Membrete $membrete, string $paciente, ?string $direccion): float
    {
        $ocupado = $this->altoDelMembrete($membrete)
            + self::LINEA_MM
            + $this->altoDeLosDatos($paciente, $direccion) + self::AIRE_DE_LOS_DATOS_MM
            + self::TITULO_MM
            + self::FIRMA_MM
            + $this->altoDelPie($membrete)
            + self::RESERVA_MM;

        // Un membrete absurdo (un logo enorme y textos larguísimos) no puede dejar el cuerpo sin lugar: queda
        // una lista mínima y es el verificador el que avisa del desborde.
        return max(self::ALTO_UTIL_MM - $ocupado, 30.0);
    }

    /**
     * El alto que queda para el cuerpo de **una mitad de un documento** (constancia, reposo, referencia, informe), en
     * milímetros (WEB-5.3a).
     *
     * Un documento ocupa una mitad; si el texto no entra, **sigue en la otra** y, si hace falta, en las siguientes. Hay dos
     * clases de mitad:
     *
     *  - la **primera**: membrete, línea, datos del paciente y el título (14 pt), como la del récipe;
     *  - las de **continuación**: sin membrete, un encabezado corto (`PACIENTE:` y `FECHA:`), la línea y un título más chico
     *    (11 pt, «… (continuación)»), como el móvil.
     *
     * Y al pie, **una sola** lleva la firma —la última— y las demás la línea «Continúa →». El pie de página (las líneas de
     * dirección del consultorio) va en todas.
     *
     * @param  string  $paciente  el nombre que se imprime en `PACIENTE`
     * @param  bool  $primera  `true` para la primera mitad, `false` para una de continuación
     * @param  bool  $conFirma  `true` si es la última mitad del documento (lleva la firma), `false` si sigue en otra
     */
    public function altoDelCuerpoDeDocumento(Membrete $membrete, string $paciente, bool $primera, bool $conFirma): float
    {
        $arriba = $primera
            ? $this->altoDelMembrete($membrete)
                + self::LINEA_MM
                + $this->altoDeLosDatos($paciente, null) + self::AIRE_DE_LOS_DATOS_MM
                + self::TITULO_MM
            : $this->altoDelEncabezadoDeContinuacion($paciente)
                + self::LINEA_MM
                + self::TITULO_CONTINUACION_MM;

        $ocupado = $arriba
            + ($conFirma ? self::FIRMA_MM : self::CONTINUA_MM)
            + $this->altoDelPie($membrete)
            + self::RESERVA_MM;

        return max(self::ALTO_UTIL_MM - $ocupado, 30.0);
    }

    /**
     * El encabezado corto de una mitad de continuación: `PACIENTE: nombre` a la izquierda y `FECHA: …` a la derecha, en
     * una línea de 9 pt (el nombre se parte en más si no entra).
     */
    public function altoDelEncabezadoDeContinuacion(string $paciente): float
    {
        $linea = 9.0 * self::INTERLINEA * MedidorDeTexto::MM_POR_PUNTO;
        $ancho = self::ANCHO_UTIL_MM - self::ETIQUETA_DEL_PACIENTE_MM - self::FECHA_DE_CONTINUACION_MM;

        return max($this->medidor->lineas($paciente, $ancho, 9.0), 1) * $linea;
    }

    /**
     * El alto del membrete: el logo y los tres textos (médico, especialidad y RIF). Con el logo a un costado
     * pesa lo que más mida de los dos; con el logo centrado, el logo **encima** de los textos.
     */
    public function altoDelMembrete(Membrete $membrete): float
    {
        $logo = $membrete->conLogo();
        $alineacion = $membrete->estiloDe('logo')['alineacion'] ?? 'izquierda';
        $centrado = $logo && $alineacion === 'centro';

        // Con el logo al lado, los textos disponen de lo que el logo deja; si no, de todo el ancho.
        $anchoDeLosTextos = $logo && ! $centrado
            ? self::ANCHO_UTIL_MM - self::ANCHO_DEL_LOGO_MM - self::SEPARACION_DEL_LOGO_MM
            : self::ANCHO_UTIL_MM;

        $textos = 0.0;
        foreach ([
            'medico' => $membrete->medico,
            'especialidad' => $membrete->especialidad,
            'rif' => $membrete->rif,
        ] as $elemento => $texto) {
            $textos += $this->altoDeUnTexto($membrete, $elemento, $texto, $anchoDeLosTextos);
        }

        if (! $logo) {
            return $textos;
        }

        $altoDelLogo = $membrete->altoDelLogoMm() + (($membrete->estiloDe('logo')['borde'] ?? false) ? self::BORDE_MM : 0.0);

        return $centrado ? $altoDelLogo + 1.4 + $textos : max($altoDelLogo, $textos);
    }

    /**
     * Los datos del paciente: `PACIENTE`, luego `C.I.` / `EDAD` / `FECHA` en una sola línea y, en la mitad
     * "Rp.", la `DIRECCIÓN` (que puede ocupar más de una línea).
     */
    public function altoDeLosDatos(string $paciente, ?string $direccion): float
    {
        $linea = 9.0 * self::INTERLINEA * MedidorDeTexto::MM_POR_PUNTO;

        // "PACIENTE:" en negrita ocupa ~17 mm antes del nombre.
        $lineasDelNombre = max($this->medidor->lineas($paciente, self::ANCHO_UTIL_MM - 17.0, 9.0), 1);
        $alto = $lineasDelNombre * $linea + 1.0 + $linea;

        if ($direccion !== null && trim($direccion) !== '') {
            // "DIRECCIÓN:" ocupa ~21 mm.
            $alto += 1.0 + $this->medidor->lineas($direccion, self::ANCHO_UTIL_MM - 21.0, 9.0) * $linea;
        }

        return $alto;
    }

    /** El pie de página: las líneas que tienen texto, a 7 pt, con la fuente que eligió el médico. */
    public function altoDelPie(Membrete $membrete): float
    {
        if ($membrete->pie === [] || ($membrete->estiloDe('pie')['visible'] ?? true) === false) {
            return 0.0;
        }

        $lineas = 0;
        foreach ($membrete->pie as $linea) {
            $lineas += max($this->lineasDe($membrete, 'pie', $linea, self::ANCHO_UTIL_MM), 1);
        }

        $borde = ($membrete->estiloDe('pie')['borde'] ?? false) ? self::BORDE_MM * count($membrete->pie) : 0.0;

        return self::AIRE_DEL_PIE_MM + $lineas * 7.0 * self::INTERLINEA * MedidorDeTexto::MM_POR_PUNTO + $borde;
    }

    private function altoDeUnTexto(Membrete $membrete, string $elemento, ?string $texto, float $ancho): float
    {
        $estilo = $membrete->estiloDe($elemento);

        if ($texto === null || trim($texto) === '' || ($estilo['visible'] ?? true) === false) {
            return 0.0;
        }

        $lineas = max($this->lineasDe($membrete, $elemento, $texto, $ancho), 1);
        $borde = ($estilo['borde'] ?? false) ? self::BORDE_MM : 0.0;

        return $lineas * self::PUNTOS[$elemento] * self::INTERLINEA * MedidorDeTexto::MM_POR_PUNTO + $borde;
    }

    private function lineasDe(Membrete $membrete, string $elemento, string $texto, float $ancho): int
    {
        $estilo = $membrete->estiloDe($elemento);
        $factor = self::FACTOR_DE_FUENTE[$estilo['fuente'] ?? 'arimo'] ?? 1.0;

        return $this->medidor->lineas(
            $texto,
            $ancho / $factor,
            self::PUNTOS[$elemento],
            (bool) ($estilo['negrita'] ?? false),
        );
    }
}
