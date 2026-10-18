<?php

namespace App\Clinica\Consulta;

/**
 * **Las secciones de la consulta** (la revisión de Alexander, 2026-10-17): la **fuente única** del catálogo de ítems
 * del legado —la lista de 14 de `w_consulta_principal` (wiki `04-historia-clinica.md`, "Ítems de la historia
 * clínica")— y de cómo se navega a cada una.
 *
 * Antes vivía dentro de `Vistas\ConsultaShellVista`, que la usaba para dibujar el panel lateral de la pantalla. El
 * panel se fue: los ítems ahora son un **grupo plegable del menú lateral** («Consulta», sólo visible dentro de una
 * consulta), y el shell sigue usando el mismo catálogo para saber qué sección abrir y cuál es su parcial. Si estuviera
 * en los dos lados, agregar una sección dejaría una de las dos navegaciones mintiendo; por eso el catálogo vive acá.
 *
 * Cada ítem dice:
 *
 *  - `numero`: el número del ítem en la wiki, que es lo que lo ata a su fuente y lo que hace legible el catálogo.
 *  - `grupo`: `Consulta` (lo de la atención) o `Documentos`; es cómo el legado los separa en el panel.
 *  - `titulo`, `icono` y `detalle`: lo que se muestra en la pantalla.
 *  - `tarea`: la tarea de la web que lo completa (`WEB-4.2`… y `F9`).
 *  - `estado`: `listo` (ya tiene pantalla) o `proximamente`.
 *  - `seccion`: la clave de `?seccion=` que lo abre **dentro de la misma consulta**; `null` en el que todavía no
 *    tiene pantalla (la pre-factura, que llega con F9).
 *
 * El orden del catálogo es el del legado y no se reordena.
 */
final class SeccionesDeLaConsulta
{
    /** La clave de la sección de motivos en `?seccion=`. */
    public const SECCION_MOTIVOS = 'motivos';

    /** La clave de la sección de enfermedad actual en `?seccion=`. */
    public const SECCION_ENFERMEDAD = 'enfermedad';

    /** La clave de la sección de evaluación física en `?seccion=` (WEB-4.3). */
    public const SECCION_EVALUACION = 'evaluacion';

    /** La clave de la sección de antecedentes en `?seccion=` (WEB-4.4). */
    public const SECCION_ANTECEDENTES = 'antecedentes';

    /** La clave de la sección de diagnósticos en `?seccion=` (WEB-4.5). */
    public const SECCION_DIAGNOSTICO = 'diagnostico';

    /** La clave de la sección de evolución en `?seccion=` (WEB-4.6). */
    public const SECCION_EVOLUCION = 'evolucion';

    /** La clave de la sección de exámenes e imágenes en `?seccion=` (WEB-4.7). */
    public const SECCION_EXAMENES = 'examenes';

    /** La clave de la sección de récipes en `?seccion=` (WEB-5.1). */
    public const SECCION_RECIPES = 'recipes';

    /** La clave de la sección de solicitudes (laboratorio, estudios, constancia, reposo, referencia, hospitalización, informe, dieta) en `?seccion=` (WEB-5.3a, WEB-5.3b y WEB-5.4). */
    public const SECCION_SOLICITUDES = 'solicitudes';

    /** El tratamiento: un texto libre de la consulta (`consultas.laringe`), el ítem TRATAMIENTO del panel del legado. */
    public const SECCION_TRATAMIENTO = 'tratamiento';

    /** Los datos personales del paciente, editables dentro de la consulta (WEB-11.25). */
    public const SECCION_DATOS = 'datos';

    /**
     * La clave de la sección del **estudio ecográfico** (ítem 5 del panel) en `?seccion=` (WEB-6.1).
     *
     * La sección agrupa los siete estudios del ítem; **cuál se abre** se elige con `&tipo=` entre los que ya
     * existen, que son los de `TiposDeEstudio`.
     */
    public const SECCION_ESTUDIO = 'estudio';

    /**
     * La clave de la sección del **control de embarazo** (ítem 6 del panel) en `?seccion=` (WEB-6.6).
     *
     * Como la del estudio, agrupa las pestañas del ítem: **cuál se abre** se elige con `&pestana=`.
     */
    public const SECCION_EMBARAZO = 'embarazo';

    /** El valor de `?recipe=` que abre el récipe de la pareja (WEB-5.1b): la segunda pestaña de la sección de récipes. */
    public const RECIPE_PAREJA = 'pareja';

    /**
     * Los 14 ítems, en el orden del legado.
     *
     * El `estado` es `listo` (ya hay pantalla a la que entrar) o `proximamente`; `seccion` es la clave del contenido que
     * se abre **dentro de la misma consulta** (`?seccion=…`) y falta sólo en el ítem que todavía no tiene pantalla.
     */
    private const ITEMS = [
        ['numero' => 1, 'grupo' => 'Consulta', 'titulo' => 'Motivos de consulta', 'icono' => 'bi-list-check',
            'detalle' => 'Catálogo del médico, varios por consulta.', 'tarea' => 'WEB-4.2', 'estado' => 'listo',
            'seccion' => self::SECCION_MOTIVOS],
        ['numero' => 2, 'grupo' => 'Consulta', 'titulo' => 'Enfermedad actual', 'icono' => 'bi-file-text',
            'detalle' => 'Texto libre o por plantilla.', 'tarea' => 'WEB-4.2', 'estado' => 'listo',
            'seccion' => self::SECCION_ENFERMEDAD],
        ['numero' => 3, 'grupo' => 'Consulta', 'titulo' => 'Antecedentes', 'icono' => 'bi-clipboard2-pulse',
            'detalle' => 'Del paciente, por categorías, con la casilla de alerta.', 'tarea' => 'WEB-4.4', 'estado' => 'listo',
            'seccion' => self::SECCION_ANTECEDENTES],

        ['numero' => 4, 'grupo' => 'Consulta', 'titulo' => 'Evaluación física', 'icono' => 'bi-heart-pulse',
            'detalle' => 'Antropometría, examen por aparatos y ginecológico.', 'tarea' => 'WEB-4.3', 'estado' => 'listo',
            'seccion' => self::SECCION_EVALUACION],
        ['numero' => 5, 'grupo' => 'Consulta', 'titulo' => 'Estudio ecográfico', 'icono' => 'bi-activity',
            'detalle' => 'Ecos ginecológicos, obstétricos, doppler y ecocardiograma fetal.', 'tarea' => 'WEB-6.1', 'estado' => 'listo',
            'seccion' => self::SECCION_ESTUDIO],
        ['numero' => 6, 'grupo' => 'Consulta', 'titulo' => 'Control de embarazo', 'icono' => 'bi-gender-female',
            'detalle' => 'Prenatal, laboratorio, controles y reporte.', 'tarea' => 'WEB-6.6', 'estado' => 'listo',
            'seccion' => self::SECCION_EMBARAZO],
        ['numero' => 7, 'grupo' => 'Consulta', 'titulo' => 'Exámenes', 'icono' => 'bi-droplet',
            'detalle' => 'Laboratorio de rutina de la consulta y las imágenes de sus estudios.',
            'tarea' => 'WEB-4.7', 'estado' => 'listo', 'seccion' => self::SECCION_EXAMENES],
        ['numero' => 8, 'grupo' => 'Consulta', 'titulo' => 'Diagnóstico', 'icono' => 'bi-clipboard2-check',
            'detalle' => 'Catálogo del médico; se propaga a constancias y presupuesto.', 'tarea' => 'WEB-4.5', 'estado' => 'listo',
            'seccion' => self::SECCION_DIAGNOSTICO],
        ['numero' => 9, 'grupo' => 'Consulta', 'titulo' => 'Evolución', 'icono' => 'bi-activity',
            'detalle' => 'Texto libre o por plantilla.', 'tarea' => 'WEB-4.6', 'estado' => 'listo',
            'seccion' => self::SECCION_EVOLUCION],
        ['numero' => 10, 'grupo' => 'Documentos', 'titulo' => 'Récipes', 'icono' => 'bi-prescription2',
            'detalle' => 'Varios por consulta, por medicamento o por tratamiento, y el de la pareja; se pueden corregir.',
            'tarea' => 'WEB-5.1', 'estado' => 'listo', 'seccion' => self::SECCION_RECIPES],
        ['numero' => 11, 'grupo' => 'Documentos', 'titulo' => 'Tratamiento', 'icono' => 'bi-capsule',
            'detalle' => 'El plan de tratamiento en texto libre o por plantilla; sale en el resumen médico.',
            'tarea' => 'WEB-5.7', 'estado' => 'listo', 'seccion' => self::SECCION_TRATAMIENTO],
        ['numero' => 12, 'grupo' => 'Documentos', 'titulo' => 'Solicitudes', 'icono' => 'bi-send',
            'detalle' => 'Laboratorio, estudios, constancia, reposo, referencia, hospitalización, informe médico y dieta / recomendaciones.',
            'tarea' => 'WEB-5.3a', 'estado' => 'listo', 'seccion' => self::SECCION_SOLICITUDES],
        ['numero' => 13, 'grupo' => 'Documentos', 'titulo' => 'Datos personales', 'icono' => 'bi-person-vcard',
            'detalle' => 'La filiación y el contacto del paciente, para completar sin salir de la consulta.', 'tarea' => 'WEB-11.25', 'estado' => 'listo',
            'seccion' => self::SECCION_DATOS],
        ['numero' => 14, 'grupo' => 'Documentos', 'titulo' => 'Pre-factura', 'icono' => 'bi-receipt',
            'detalle' => 'Los ítems de facturación de la consulta.', 'tarea' => 'F9', 'estado' => 'proximamente',
            'seccion' => null],
    ];

    /**
     * El catálogo completo, con la bandera `disponible` derivada del estado.
     *
     * @return array<int,array{numero:int, grupo:string, titulo:string, icono:string, detalle:string, tarea:string, estado:string, seccion:?string, disponible:bool}>
     */
    public static function todas(?string $especialidad = null): array
    {
        return array_map(fn (array $item) => $item + ['disponible' => $item['estado'] === 'listo'], self::delaEspecialidad($especialidad));
    }

    /**
     * Los ítems que aplican a la especialidad (WEB-12.1): el catálogo es el de ginecología y, en pediatría, se quita el control de embarazo.
     * Sin especialidad (o en cualquier otra) sale el catálogo completo. La numeración no se toca.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function delaEspecialidad(?string $especialidad): array
    {
        $alcance = AlcanceDeLaEspecialidad::de($especialidad);

        return array_values(array_filter(
            self::ITEMS,
            fn (array $item) => $item['seccion'] !== self::SECCION_EMBARAZO || $alcance->muestraElEmbarazo(),
        ));
    }

    public static function total(?string $especialidad = null): int
    {
        return count(self::delaEspecialidad($especialidad));
    }

    /** Cuántos ítems ya tienen pantalla: hoy 13 de 14 (sólo falta la pre-factura → F9). */
    public static function disponibles(?string $especialidad = null): int
    {
        return count(array_filter(self::delaEspecialidad($especialidad), fn (array $item) => $item['estado'] === 'listo'));
    }

    /**
     * Una sección por su clave, o `null` si la clave no existe (o es la de un ítem sin pantalla).
     *
     * Es la normalización que comparten el shell y el menú: un `?seccion=` que no se entiende se trata como "ninguna".
     *
     * @return array{numero:int, grupo:string, titulo:string, icono:string, detalle:string, tarea:string, estado:string, seccion:?string, disponible:bool}|null
     */
    public static function porSeccion(?string $seccion, ?string $especialidad = null): ?array
    {
        if ($seccion === null || $seccion === '') {
            return null;
        }

        foreach (self::todas($especialidad) as $item) {
            if ($item['seccion'] === $seccion) {
                return $item;
            }
        }

        return null;
    }

    /** ¿La clave corresponde a una sección con pantalla dentro de la consulta? */
    public static function existeSeccion(?string $seccion, ?string $especialidad = null): bool
    {
        return self::porSeccion($seccion, $especialidad) !== null;
    }

    /** La clave normalizada: la pedida si es una sección con pantalla, `null` en cualquier otro caso. */
    public static function normalizar(?string $seccion, ?string $especialidad = null): ?string
    {
        return self::porSeccion($seccion, $especialidad)['seccion'] ?? null;
    }

    /**
     * **Los hijos del grupo «Consulta» del menú lateral**: un ítem por sección, con el activo marcado.
     *
     * Sólo el nombre y el icono (nada de descripciones, contadores ni la pastilla "Próximamente"). El ítem que todavía
     * no tiene pantalla (la pre-factura) va **sin enlace** y con `deshabilitado`, para que la lista no se rompa cuando
     * F9 le agregue su sección: alcanza con cambiar su `estado` en el catálogo.
     *
     * El enlace es el de la consulta que se está atendiendo, con la sección en la query —la misma dirección que el
     * shell usaba en su panel—, así que navegar por el menú no cambia de pantalla.
     *
     * @param  int  $consultaId  la consulta abierta
     * @param  string|null  $seccionActual  el `?seccion=` de la petición (se normaliza)
     * @return array<int,array{texto:string, icono:string, url:?string, activo:bool, deshabilitado:bool}>
     */
    public static function submenu(int $consultaId, ?string $seccionActual, ?string $especialidad = null): array
    {
        $actual = self::normalizar($seccionActual, $especialidad);

        return array_map(fn (array $item) => [
            'texto' => $item['titulo'],
            'icono' => $item['icono'],
            'url' => $item['seccion'] !== null
                ? route('clinica.consulta', ['consulta' => $consultaId, 'seccion' => $item['seccion']])
                : null,
            'activo' => $actual !== null && $item['seccion'] === $actual,
            'deshabilitado' => $item['estado'] !== 'listo',
        ], self::todas($especialidad));
    }
}
