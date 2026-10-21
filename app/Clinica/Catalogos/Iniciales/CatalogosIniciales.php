<?php

namespace App\Clinica\Catalogos\Iniciales;

/**
 * **El registro único de los catálogos iniciales** (WEB-12.8): qué catálogos del consultorio tienen una versión inicial (filas con `reg_medico` NULL) que el
 * administrador arma y de la que cada médico recibe una **copia** («Cargar el catálogo inicial»). Declara, por catálogo, **cómo se guarda** (tabla, columna del
 * código y sus dígitos), **qué lo identifica** (para copiar sólo lo que el médico no tiene), **qué columnas se copian**, **de qué otro catálogo depende**
 * (`refs`: el examen apunta a su tipo, el medicamento de un tratamiento a su vademécum) y **qué campos edita el administrador** (`campos`, de donde sale el
 * formulario de «Catálogos iniciales»). La copia (`CopiarCatalogoInicial`), las novedades, el ABM del administrador y la tarjeta de las pantallas del médico
 * leen **todos** de acá: agregar un catálogo es agregar una entrada.
 *
 * Los slugs son los de `CatalogosDisponibles` (`medicamentos`, `tratamientos`…). Las vacunas y la referencia de crecimiento tienen su propio camino
 * (`CopiarVacunasIniciales`, `GuardarPercentilInicial`): sus códigos significan algo —las dosis y los niños los citan— y no se renumeran al copiar.
 *
 * Forma de una entrada:
 *  - `titulo`, `singular`: cómo se nombra en pantalla.
 *  - `tabla`, `codigo`, `digitos`: dónde vive y cómo se numera (el mayor numérico del médico + 1, relleno de ceros a `digitos`).
 *  - `etiqueta`: la columna que nombra una fila (la lista y los avisos).
 *  - `identidad`: columnas que, normalizadas (sin tildes ni mayúsculas), dicen «es la misma fila»; `ref:<columna>` suma la identidad de la fila a la que apunta.
 *  - `columnas`: lo que se copia (nunca `id`, `reg_medico`, el código ni las marcas de tiempo).
 *  - `refs`: `columna => catálogo` al que apunta por código; al copiar se mapea por identidad (y se copia la dependencia si falta).
 *  - `hijos`: tablas que cuelgan de la fila por su código (`tabla`, `enlace`, `columnas`, `refs`, `orden`).
 *  - `campos`: el formulario del administrador: `columna => [rotulo, tipo (texto|area|numero|decimal|marca|porkilo|ref), max, requerido, mayusculas, grupo?, ayuda?]`.
 *    `decimal` es un número opcional con tres decimales (la dosis por kilo); `porkilo` es una casilla que guarda `SI` en `vademecum.dosificacion` (lo que lee
 *    `DosisPediatrica`); `grupo` junta campos en una sección del formulario (`dosis` = «Dosis por kilo»).
 */
final class CatalogosIniciales
{
    /** @var array<string,array<string,mixed>> */
    private const CATALOGOS = [
        'medicamentos' => [
            'titulo' => 'Medicamentos', 'singular' => 'medicamento', 'tabla' => 'vademecum', 'codigo' => 'codemedicina', 'digitos' => 4,
            'etiqueta' => 'nombrecomercial', 'identidad' => ['nombrecomercial', 'presentacion'],
            'columnas' => [
                'nombregenerico', 'nombrecomercial', 'dosificacion', 'uso', 'presentacion', 'concentracion', 'cada', 'durante', 'pvc', 'pvs', 'dosis', 'sico',
                'nombrecomercial1', 'nombrecomercial2', 'nombrecomercial3', 'sicome', 'sicome1', 'sicome2', 'sicome3',
            ],
            'campos' => [
                'nombrecomercial' => ['Nombre comercial', 'texto', 35, true, true],
                'nombregenerico' => ['Principio activo', 'texto', 35, false, true],
                'presentacion' => ['Presentación', 'texto', 50, false, false],
                'nombrecomercial1' => ['Segunda marca', 'texto', 40, false, true],
                'nombrecomercial2' => ['Tercera marca', 'texto', 40, false, true],
                'nombrecomercial3' => ['Cuarta marca', 'texto', 40, false, true],
                'uso' => ['Indicaciones', 'area', 2000, false, false],
                // La dosis por peso del récipe pediátrico (`DosisPediatrica`), con los nombres y unidades del ABM del médico (`CamposDelMedicamento`).
                'dosificacion' => ['La dosis es por kilo', 'porkilo', 0, false, false, 'dosis'],
                'dosis' => ['Dosis (mg/kg por toma)', 'decimal', 0, false, false, 'dosis'],
                'cada' => ['Cada (veces al día)', 'decimal', 0, false, false, 'dosis'],
                'durante' => ['Durante (días)', 'decimal', 0, false, false, 'dosis'],
                'pvc' => ['Envase: mg', 'decimal', 0, false, false, 'dosis', 'Los mg que trae la presentación (250 en «250 mg / 5 ml»).'],
                'pvs' => ['Envase: ml', 'decimal', 0, false, false, 'dosis', 'Los ml de esa presentación (5 en «250 mg / 5 ml»).'],
                'concentracion' => ['Gotas por ml', 'decimal', 0, false, false, 'dosis', 'Sólo si el récipe tiene que decir gotas.'],
            ],
        ],
        'tratamientos' => [
            'titulo' => 'Tratamientos', 'singular' => 'tratamiento', 'tabla' => 'recipe_grupo', 'codigo' => 'codigo', 'digitos' => 4,
            'etiqueta' => 'tratamiento', 'identidad' => ['tratamiento'], 'columnas' => ['tratamiento'],
            'hijos' => [[
                'tabla' => 'recipe_grupo_detalle', 'enlace' => 'codigo', 'columnas' => ['descripcion', 'indicaciones', 'cantidad', 'orden'],
                'refs' => ['codemedicina' => 'medicamentos'], 'orden' => 'orden',
            ]],
            'campos' => ['tratamiento' => ['Nombre del tratamiento', 'texto', 100, true, true]],
        ],
        'diagnosticos' => [
            'titulo' => 'Diagnósticos', 'singular' => 'diagnóstico', 'tabla' => 'diagnosticos', 'codigo' => 'codediagnostico', 'digitos' => 4,
            'etiqueta' => 'descripcion', 'identidad' => ['descripcion'], 'columnas' => ['descripcion'],
            'campos' => ['descripcion' => ['Diagnóstico', 'texto', 50, true, true]],
        ],
        'motivos-consulta' => [
            'titulo' => 'Motivos de consulta', 'singular' => 'motivo de consulta', 'tabla' => 'motivos_consulta', 'codigo' => 'codemotivo', 'digitos' => 4,
            'etiqueta' => 'descripcion', 'identidad' => ['descripcion'], 'columnas' => ['descripcion'],
            'campos' => ['descripcion' => ['Motivo de consulta', 'texto', 40, true, true]],
        ],
        'motivos-cita' => [
            'titulo' => 'Motivos de cita', 'singular' => 'motivo de cita', 'tabla' => 'motivo_cita', 'codigo' => 'codigo', 'digitos' => 2,
            'etiqueta' => 'tipo_atencion', 'identidad' => ['tipo_atencion'], 'columnas' => ['tipo_atencion', 'precio', 'admite_varias_citas_pendientes'],
            'campos' => [
                'tipo_atencion' => ['Motivo de cita', 'texto', 100, true, true],
                'precio' => ['Precio', 'numero', 10, false, false],
                'admite_varias_citas_pendientes' => ['Admite varias citas pendientes', 'marca', 1, false, false],
            ],
        ],
        'tipos-examen' => [
            'titulo' => 'Tipos de examen', 'singular' => 'tipo de examen', 'tabla' => 'tipos_examenes', 'codigo' => 'codetipo', 'digitos' => 3,
            'etiqueta' => 'tipo', 'identidad' => ['tipo'], 'columnas' => ['tipo'],
            'campos' => ['tipo' => ['Tipo de examen', 'texto', 40, true, true]],
        ],
        'examenes' => [
            'titulo' => 'Exámenes', 'singular' => 'examen', 'tabla' => 'examenes', 'codigo' => 'codeexamen', 'digitos' => 3,
            'etiqueta' => 'examen', 'identidad' => ['examen', 'ref:codetipo'], 'columnas' => ['examen'], 'refs' => ['codetipo' => 'tipos-examen'],
            'campos' => [
                'examen' => ['Examen', 'texto', 45, true, true],
                'codetipo' => ['Tipo de examen', 'ref', 0, true, false],
            ],
        ],
        'plantillas-texto' => [
            'titulo' => 'Plantillas de texto', 'singular' => 'plantilla', 'tabla' => 'formato_print', 'codigo' => 'codigo', 'digitos' => 4,
            'etiqueta' => 'titulo', 'identidad' => ['tipo', 'titulo'], 'columnas' => ['titulo', 'descripcion', 'tipo'],
            'campos' => [
                'tipo' => ['Tipo', 'tipo', 10, true, false],
                'titulo' => ['Título', 'texto', 200, true, true],
                'descripcion' => ['Texto', 'area', 20000, true, false],
            ],
        ],
    ];

    /** @return array<string,array<string,mixed>> `slug => entrada` en el orden en que se ofrecen */
    public static function todos(): array
    {
        return self::CATALOGOS;
    }

    public static function existe(string $slug): bool
    {
        return isset(self::CATALOGOS[$slug]);
    }

    /** @return array<string,mixed> */
    public static function de(string $slug): array
    {
        return self::CATALOGOS[$slug] ?? throw new \InvalidArgumentException('Ese catálogo no tiene versión inicial.');
    }
}
