<?php

namespace App\Clinica\Catalogos;

use App\Actions\Catalogos\EditarTipoDeExamenDelCatalogo;
use App\Actions\Consulta\AgregarDiagnosticoAlCatalogo;
use App\Actions\Consulta\AgregarMotivoAlCatalogo;
use App\Models\Diagnostico;
use App\Models\MotivoConsulta;
use App\Models\TipoExamen;

/**
 * **Cómo se llama y qué columnas tiene un catálogo de "código + texto"** (WEB-8.1b): diagnósticos, motivos de consulta
 * y tipos de examen. Los tres tienen la misma forma (un código que da el correlativo y un texto en mayúsculas), así que
 * comparten lectura (`CatalogoSimpleDelCatalogo`), ViewModel (`CatalogoSimpleVista`) y vista Blade; lo único que cambia es
 * esto: el modelo, las columnas, el tope y los textos de la pantalla.
 */
final class DefinicionDeCatalogoSimple
{
    private function __construct(
        public readonly string $slug,
        public readonly string $modelo,
        public readonly string $columnaDelCodigo,
        public readonly string $columnaDelTexto,
        public readonly int $largo,
        public readonly string $titulo,
        public readonly string $singular,
        public readonly string $plural,
        public readonly string $nuevo,
        public readonly string $icono,
        public readonly string $paraQueSirve,
        public readonly string $placeholder,
        public readonly string $etiquetaDelTexto,
        public readonly string $ayudaDelTexto,
        public readonly string $avisoDeUso,
    ) {
    }

    public static function diagnosticos(): self
    {
        return new self(
            'diagnosticos', Diagnostico::class, 'codediagnostico', 'descripcion', AgregarDiagnosticoAlCatalogo::LARGO_MAXIMO,
            'Diagnósticos', 'diagnóstico', 'diagnósticos', 'Nuevo diagnóstico', 'bi-clipboard2-pulse',
            'Son los que se eligen al diagnosticar una consulta.',
            'Buscar por nombre o código',
            'Diagnóstico',
            'Se guarda en mayúsculas. Si lo renombras, cambia también en las consultas que ya lo tienen.',
            'Si alguna consulta lo tiene, no se borra y te digo dónde.',
        );
    }

    public static function motivosDeConsulta(): self
    {
        return new self(
            'motivos-consulta', MotivoConsulta::class, 'codemotivo', 'descripcion', AgregarMotivoAlCatalogo::LARGO_MAXIMO,
            'Motivos de consulta', 'motivo de consulta', 'motivos de consulta', 'Nuevo motivo de consulta', 'bi-chat-left-text',
            'Son los que se eligen al abrir una consulta (no los motivos de la cita).',
            'Buscar por nombre o código',
            'Motivo de consulta',
            'Se guarda en mayúsculas. Si lo renombras, cambia también en las consultas que ya lo tienen.',
            'Si alguna consulta lo tiene, no se borra y te digo dónde.',
        );
    }

    public static function tiposDeExamen(): self
    {
        return new self(
            'tipos-examen', TipoExamen::class, 'codetipo', 'tipo', EditarTipoDeExamenDelCatalogo::LARGO,
            'Tipos de examen', 'tipo de examen', 'tipos de examen', 'Nuevo tipo de examen', 'bi-tags',
            'Agrupan los exámenes y estudios: laboratorio, imágenes, preoperatorios…',
            'Buscar por nombre o código',
            'Tipo de examen',
            'Se guarda en mayúsculas. Un tipo se puede quitar cuando ningún examen cuelga de él.',
            'Si algún examen cuelga de él, no se borra y te digo cuáles.',
        );
    }

    /** El nombre de la ruta del listado (`clinica.catalogos.<slug>`), de donde salen también las de guardar y quitar. */
    public function ruta(string $accion = ''): string
    {
        return 'clinica.catalogos.' . $this->slug . ($accion === '' ? '' : '.' . $accion);
    }
}
