<?php

namespace App\Clinica\Consulta;

/**
 * **La etiqueta del tipo de imagen en `imagen_consulta.observacion`** (WEB-6.5): cada examen del panel tiene la
 * suya y así el escritorio sabe a qué examen pertenece cada foto.
 *
 * ## Qué dice la fuente (verificado el 2026-10-13)
 *
 * Las grillas de imágenes de `w_consulta_principal` son todos DataWindows sobre la **misma** tabla
 * (`imagen_consulta`, clave `(nrohistoria, nroconsulta, imagen)`) y cada una **filtra por `observacion`**:
 *
 *  - `d_imagenes_consul_pac_colposco` (`dw_53` de la solapa *Colposcopía*, `tabpage_31`) → `'@'`;
 *  - `d_imagenes_consul_pac_histero` (`dw_93` de *Histeroscopía*, `tabpage_49`) → `'H'`;
 *  - `d_imagenes_consul_pac_pelvico`, `…_mamas`, `…_abdominal`, `…_obstetrico_1` y `…_obstetrico_3` → `'p'`,
 *    `'m'`, `'a'`, `'o1'` y `'o3'`.
 *
 * ⚠️ **El escritorio se contradice a sí mismo en la colposcopía**: su botón de subir guarda
 * `observacion = 'colpo'` (`w_consulta_principal.srw:10216-10224`) mientras su propia grilla filtra `'@'`, así
 * que lo que ese botón carga **no se ve en esa grilla**. Por eso la colposcopía acepta **las dos** al leer
 * (la canónica `'@'` y el alias `'colpo'`) y la web escribe la **canónica**, que es la que el escritorio
 * muestra.
 *
 * La columna es una etiqueta, **no una descripción** (ver la trampa 116): la sección genérica de exámenes de
 * la web aprovecha el espacio de las filas que no son de ningún examen para su descripción, y por eso
 * `esEtiqueta()` existe: para no confundir una descripción con una etiqueta.
 *
 * Sin estado y sin base de datos: es el vocabulario del legado y una traducción.
 */
final class TagDeImagen
{
    /** La etiqueta de la **colposcopía**: la de su grilla (`d_imagenes_consul_pac_colposco`). */
    public const COLPOSCOPIA = '@';

    /** El alias con el que el **botón** de la colposcopía del escritorio marcó sus fotos (`:10223`). */
    public const COLPOSCOPIA_DEL_ESCRITORIO = 'colpo';

    /** La etiqueta de la **histeroscopía** (su grilla y su informe, WEB-6.5). */
    public const HISTEROSCOPIA = 'H';

    /** La del **eco pélvico** (WEB-6.1), la de **mamas**, la **abdominal** y las de los **obstétricos**. */
    public const PELVICO = 'p';

    public const MAMAS = 'm';

    public const ABDOMINAL = 'a';

    public const OBSTETRICO_1 = 'o1';

    public const OBSTETRICO_3 = 'o3';

    /**
     * La del **ecocardiograma fetal** (WEB-6.3): no es una solapa del examen sino un tipo de estudio, y su
     * grilla de imágenes es la del propio estudio (`d_imagenes_e_fetal` de
     * `w_cargar_visualizar_ecocardiograma_fetal.srw:433-434`).
     */
    public const FETAL = 'f';

    /**
     * La del **eco Doppler perinatal** (WEB-6.3): también es un tipo de estudio y su grilla es la suya
     * (`d_imagenes_e_doppler.srd:11`, que filtra por esta etiqueta; la escribe
     * `w_cargar_visualizar_eco_doppler.srw:671`).
     */
    public const DOPPLER = 'ed';

    /**
     * La etiqueta **canónica** de cada solapa del examen: es la que la web escribe al subir una foto desde esa
     * solapa. Una solapa que todavía no existe no está en la lista.
     *
     * @var array<string,string>
     */
    private const POR_SOLAPA = [
        'colposcopia' => self::COLPOSCOPIA,
        'histeroscopia' => self::HISTEROSCOPIA,
        // Los siete estudios ecográficos (WEB-11.12): la clave es `eco-<tipo del estudio>` y el escritorio las llama por su etiqueta.
        'eco-ginecologico' => self::PELVICO,
        'eco-obstetrico' => self::OBSTETRICO_1,
        'eco-tercero' => self::OBSTETRICO_3,
        'eco-doppler' => self::DOPPLER,
        'eco-fetal' => self::FETAL,
        'eco-mamario' => self::MAMAS,
        'eco-abdominal' => self::ABDOMINAL,
    ];

    /**
     * Las etiquetas con las que una imagen cuenta como de esa solapa al **leer**: la canónica y, si el legado
     * usó otra en el mismo examen, su alias.
     *
     * @var array<string,array<int,string>>
     */
    private const ACEPTADAS = [
        'colposcopia' => [self::COLPOSCOPIA, self::COLPOSCOPIA_DEL_ESCRITORIO],
        'histeroscopia' => [self::HISTEROSCOPIA],
        'eco-ginecologico' => [self::PELVICO],
        'eco-obstetrico' => [self::OBSTETRICO_1],
        'eco-tercero' => [self::OBSTETRICO_3],
        'eco-doppler' => [self::DOPPLER],
        'eco-fetal' => [self::FETAL],
        'eco-mamario' => [self::MAMAS],
        'eco-abdominal' => [self::ABDOMINAL],
    ];

    /**
     * Cómo se llama cada etiqueta en la lista de imágenes de la sección genérica: es lo que se muestra en lugar
     * del código pelado (`@`, `H`), que no le dice nada al médico.
     *
     * @var array<string,string>
     */
    private const NOMBRES = [
        self::COLPOSCOPIA => 'colposcopía',
        self::COLPOSCOPIA_DEL_ESCRITORIO => 'colposcopía',
        self::HISTEROSCOPIA => 'histeroscopía',
        self::PELVICO => 'eco pélvico',
        self::MAMAS => 'eco de mamas',
        self::ABDOMINAL => 'eco abdominal',
        self::OBSTETRICO_1 => 'eco obstétrico (1.er trimestre)',
        self::OBSTETRICO_3 => 'eco obstétrico (2.º/3.er trimestre)',
        self::FETAL => 'ecocardiograma fetal',
        self::DOPPLER => 'eco Doppler perinatal',
    ];

    /** La clave con la que un estudio ecográfico sube y lee sus imágenes (`eco-ginecologico`…). */
    public static function deUnEco(string $tipo): string
    {
        return 'eco-' . $tipo;
    }

    /** Si la clave es la de un estudio ecográfico, el tipo del estudio (`eco-mamario` → `mamario`); si no, `null`. */
    public static function tipoDeEco(?string $clave): ?string
    {
        return is_string($clave) && str_starts_with($clave, 'eco-') && isset(self::POR_SOLAPA[$clave]) ? substr($clave, 4) : null;
    }

    /** ¿La solapa existe y tiene etiqueta? Es `null` para una solapa que no sube fotos. */
    public static function deLaSolapa(string $solapa): ?string
    {
        return self::POR_SOLAPA[$solapa] ?? null;
    }

    /** Las solapas del examen que **suben imágenes** (las que tienen etiqueta). */
    public static function solapas(): array
    {
        return array_keys(self::POR_SOLAPA);
    }

    /**
     * Las etiquetas que cuentan como esa solapa al leer (la canónica primero).
     *
     * @return array<int,string>
     */
    public static function aceptadasDe(string $solapa): array
    {
        return self::ACEPTADAS[$solapa] ?? [];
    }

    /**
     * El nombre del examen de una etiqueta (`'@'` → «colposcopía»), o `null` si el valor **no** es una etiqueta
     * conocida (es una descripción libre de la sección genérica, o un código del legado que no conocemos).
     */
    public static function nombre(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : (self::NOMBRES[$valor] ?? null);
    }

    /** ¿El valor es una etiqueta conocida? (Lo usa la subida genérica para no pisar una etiqueta con una descripción.) */
    public static function esEtiqueta(?string $valor): bool
    {
        return self::nombre($valor) !== null;
    }
}
