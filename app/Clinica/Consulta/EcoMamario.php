<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\UltraMama as UltraMamaModelo;

/**
 * **El eco mamario de una consulta** (WEB-6.4): su mapa del dato y su lectura.
 *
 * Es el sexto tipo de la sección de estudio (ítem 5 del panel del legado, `TiposDeEstudio::MAMARIO`) y el
 * hermano de los otros ecos (WEB-6.1 a WEB-6.3): comparte con ellos la forma —**una fila por consulta**, el
 * mismo objeto de valores `EstudioEcografico`, la misma regla de "esto es un documento o no"
 * (`QueEsUnDocumento::ecografia`) y el mismo camino de guardado (`GuardarEcoObstetricoBase`)— y es **el más
 * simple** de los siete:
 *
 *  - **No tiene ningún desplegable con códigos.** El único es `referido`, que guarda la **cédula de un médico**
 *    del catálogo `doctores` (`DoctoresDelMedico`, la misma lectura de la pestaña *Referencias*): no es un
 *    código del legado sino una clave ajena, así que el mapa lo declara como `opciones` **vacías** y el
 *    ViewModel (`EcoMamarioVista`) las completa con la libreta del médico. La opción vacía va adelante
 *    (trampa 112), como en los demás desplegables.
 *  - **Los cuatro campos cortos son texto libre**, aunque en el dump traigan números: `tranductor`
 *    («Transductor», **con el error de tipeo del legado** por *transductor*: así se llama la columna y **no se
 *    renombra**, regla 2 de `AGENTS.md`), `menarquia`, `quirurgicos` y `hormonas`. Sus topes salen del
 *    DataWindow (`edit.limit`): 100, 20, 60 y 100.
 *  - **La ficha es sólo la fecha**: este estudio **no tiene `fur` ni `gesta`**, así que `COLUMNAS_QUE_NO_CUENTAN`
 *    declara nada más la fecha (que completa el servidor con hoy si viene vacía).
 *
 * ⚠️ **El DataWindow no se llama como la tabla** (trampas 102, 123 y 124): el vivo es **`d_ultasonido_mamas`**
 * —con la «n» de menos—, está en `fuentes/ventana_consulta/` y **no** en `datawin_procesos/`, y escribe en
 * `ultra_mama`. La pestaña viva de la consulta lo usa (`w_consulta_principal.srw:23018`, el informe en
 * `:22977` con `d_ultasonido_mamas_print`, rótulo *Mamario* en `:20814`) y el propio estudio lo abre con
 * `w_cargar_visualizar_eco_mamario`.
 *
 * ⚠️ **`para` está oculto** en el DataWindow (`visible="0"`, con su rótulo «Para:» también oculto): nunca se
 * imprime y en el dump está siempre vacío, así que no entra en el mapa y la web **no lo toca** al guardar.
 * `puro_texto` es el informe en texto libre del modo «Plantillas» del escritorio (sus plantillas son
 * `formato_print` con `tipo = 'UM'`): se **muestra y no se edita**, como el del eco pélvico y el del Doppler,
 * pero **no se imprime**: ese modo es **otro** DataWindow (`d_ultasonido_mamas_print_puro`, que la web no
 * imprime), así que tampoco cuenta como un documento del estudio (a diferencia del pélvico y el Doppler).
 *
 * ⚠️ **La fecha la completa la ventana del escritorio con hoy** cuando inserta la fila
 * (`w_cargar_visualizar_eco_mamario.srw:215`, `dw_1.object.data[1,3] = today()`): la web la propone con hoy y
 * deja editarla, como los otros ecos.
 *
 * Sus fotos van a `imagen_consulta` con `observacion = 'm'` (`TagDeImagen::MAMAS`). La web **no** ofrece subir
 * imágenes desde esta sección, como en los otros ecos; y usa la etiqueta correcta, sin replicar el defecto del
 * escritorio, cuyas copias embebidas de la pestaña apuntan por error a la grilla del eco pélvico
 * (`w_consulta_principal.srw:23007`, `d_imagenes_pelvico`).
 */
final class EcoMamario
{
    /** La tabla del eco mamario. */
    public const TABLA = 'ultra_mama';

    /** La columna de la historia, como en el DataWindow (`key=yes`): **es `historia`**, no `numhistoria`. */
    public const COLUMNA_HISTORIA = 'historia';

    /** La columna de la consulta: **es parte de la clave** (`consulta`, `NOT NULL`). */
    public const COLUMNA_CONSULTA = 'consulta';

    /** La columna del **médico referente**: guarda su **cédula** del catálogo `doctores`. */
    public const COLUMNA_REFERIDO = 'referido';

    /**
     * El informe en texto libre del escritorio (su modo «Plantillas», `formato_print` tipo `UM`): se muestra
     * y **no** se edita.
     */
    public const COLUMNA_PURO_TEXTO = 'puro_texto';

    /**
     * Las columnas de la **ficha** que no cuentan para decidir si el estudio dice algo: acá es **sólo la
     * fecha**, la que el servidor completa con hoy. Los cuatro campos cortos (el transductor, la menarquia, los
     * quirúrgicos y las hormonas) **sí** cuentan: son el encabezado del informe, no datos del embarazo.
     *
     * Es la lista que comparten la Action (`GuardarEcoMamario`), la pantalla (`EcoMamarioVista`), la impresión
     * y la historia del paciente.
     *
     * @var array<int,string>
     */
    public const COLUMNAS_QUE_NO_CUENTAN = ['fecha'];

    /**
     * **El mapa del dato del eco mamario**, en los tres bloques del formulario y en el orden del DataWindow
     * vivo.
     *
     * Los rótulos son los del DataWindow (`Referido por:`, `Transductor:`, `Menarquia:`, `Quirúrgicos:`,
     * `Hormonas:`, `Mama derecha:`, `Axila derecha:`, `Mama izquierda:`, `Axila izquierda:` y `Conclusión:`),
     * sin los dos puntos que el escritorio escribe en su texto: la pantalla y la hoja los agregan.
     *
     * El desplegable de `referido` sale con sus `opciones` **vacías**: las completa el ViewModel con la libreta
     * de médicos (`DoctoresDelMedico`). `para` **no** está —va oculto en el DataWindow y la web no lo toca— y
     * `puro_texto` tampoco —se muestra y no se edita—.
     *
     * @return array<int,array{titulo:string, icono:string, campos:array<int,array<string,mixed>>}>
     */
    public static function grupos(): array
    {
        return [
            ['titulo' => 'El encabezado', 'icono' => 'bi-calendar-heart', 'campos' => [
                ['columna' => 'fecha', 'etiqueta' => 'Fecha', 'tipo' => 'fecha',
                    'ayuda' => 'La fecha del estudio: abre con la de hoy y se puede cambiar.'],
                // El desplegable de médicos: guarda la cédula y muestra el nombre (su `dddw` del escritorio,
                // `d_dwww_medicos`, tiene `cedula` como data column y `nombres + ' ' + apellidos` como display).
                // Las opciones las completa `EcoMamarioVista` con la libreta del médico.
                ['columna' => self::COLUMNA_REFERIDO, 'etiqueta' => 'Referido por', 'tipo' => 'opciones',
                    'opciones' => [], 'ayuda' => 'El médico que refirió a la paciente, de tu catálogo de colegas.'],
                // ⚠️ `tranductor` es el nombre real de la columna (typo del legado): no se renombra.
                ['columna' => 'tranductor', 'etiqueta' => 'Transductor', 'tipo' => 'texto', 'tope' => 100,
                    'ayuda' => 'El transductor con el que se hizo el estudio, como lo escriba el médico.'],
                ['columna' => 'menarquia', 'etiqueta' => 'Menarquia', 'tipo' => 'texto', 'tope' => 20,
                    'ayuda' => ''],
                ['columna' => 'quirurgicos', 'etiqueta' => 'Quirúrgicos', 'tipo' => 'texto', 'tope' => 60,
                    'ayuda' => 'Los antecedentes quirúrgicos que el médico quiera dejar escritos.'],
                ['columna' => 'hormonas', 'etiqueta' => 'Hormonas', 'tipo' => 'texto', 'tope' => 100,
                    'ayuda' => 'El tratamiento hormonal, como lo escriba el médico.'],
            ]],

            ['titulo' => 'Las mamas y las axilas', 'icono' => 'bi-clipboard2-pulse', 'campos' => [
                ['columna' => 'mama_derecha', 'etiqueta' => 'Mama derecha', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos de la mama derecha.'],
                ['columna' => 'axila_derecha', 'etiqueta' => 'Axila derecha', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos de la axila derecha.'],
                ['columna' => 'mama_izquierda', 'etiqueta' => 'Mama izquierda', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos de la mama izquierda.'],
                ['columna' => 'axila_izquierda', 'etiqueta' => 'Axila izquierda', 'tipo' => 'area',
                    'ayuda' => 'Los hallazgos de la axila izquierda.'],
            ]],

            ['titulo' => 'La conclusión', 'icono' => 'bi-file-earmark-text', 'campos' => [
                ['columna' => 'conclusion', 'etiqueta' => 'Conclusión', 'tipo' => 'area',
                    'ayuda' => 'La conclusión del estudio, en texto libre.'],
            ]],
        ];
    }

    /**
     * Las columnas que la pantalla edita, en el orden del mapa: es lo que la Action guarda.
     *
     * `para` y `puro_texto` **no** están: el primero va oculto en el DataWindow y el segundo es el informe en
     * texto libre del escritorio, que se muestra y no se edita.
     *
     * @return array<int,string>
     */
    public static function columnas(): array
    {
        $columnas = [];

        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $columnas[] = $campo['columna'];
            }
        }

        return $columnas;
    }

    /** El rótulo de pantalla de una columna, o la columna si no está en el mapa. */
    public static function etiquetaDe(string $columna): string
    {
        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                if ($campo['columna'] === $columna) {
                    return (string) $campo['etiqueta'];
                }
            }
        }

        return $columna;
    }

    /**
     * Las opciones de una columna con lista, del mapa (`[]` si esa columna no es un desplegable).
     *
     * Para `referido` devuelve `[]` a propósito: sus opciones son la libreta del médico y las arma el
     * ViewModel, no el mapa.
     *
     * @return array<int,array{valor:string,texto:string}>
     */
    public static function opcionesDe(string $columna): array
    {
        foreach (self::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                if ($campo['columna'] === $columna && ($campo['tipo'] ?? null) === 'opciones') {
                    return $campo['opciones'];
                }
            }
        }

        return [];
    }

    /**
     * El texto que se muestra para un valor guardado: el rótulo de la opción, o **el valor tal cual** si no
     * está en la lista (el referido, que guarda una cédula, se resuelve con `DoctoresDelMedico` donde se
     * imprime). Es la misma regla que usan los otros ecos.
     */
    public static function textoDe(string $columna, $valor): ?string
    {
        $limpio = EstudioEcografico::limpiar($valor);

        if ($limpio === null) {
            return null;
        }

        foreach (self::opcionesDe($columna) as $opcion) {
            if ($opcion['valor'] === $limpio) {
                return $opcion['texto'];
            }
        }

        return $limpio;
    }

    /**
     * El estudio de una consulta: sus valores, normalizados, o el objeto **vacío** si esa consulta no tiene eco
     * mamario (la pantalla lo muestra en blanco y la hoja no se ofrece).
     */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /**
     * La fila del eco mamario de una consulta, o `null`.
     *
     * Se busca por la clave exacta del DataWindow —`reg_medico` (del contexto) + **`historia`** +
     * **`consulta`**—: una fila por consulta.
     */
    public function filaDe(Consulta $consulta): ?UltraMamaModelo
    {
        return UltraMamaModelo::query()
            ->where('reg_medico', $consulta->reg_medico)
            ->where(self::COLUMNA_HISTORIA, (int) $consulta->numhistoria)
            ->where(self::COLUMNA_CONSULTA, (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * La fila del estudio de una consulta **para escribir**: la que ya existe o una nueva con su clave puesta
     * (`reg_medico`, `historia` y `consulta`) para que la Action sólo tenga que completar lo que el médico
     * cargó. La clave la arma el mapa del dato y no el formulario.
     */
    public function filaParaEscribir(Consulta $consulta): UltraMamaModelo
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new UltraMamaModelo([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
        ]);
    }
}
