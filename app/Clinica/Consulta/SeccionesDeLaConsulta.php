<?php

namespace App\Clinica\Consulta;

/**
 * **Las secciones de la consulta** (la revisión de Alexander, 2026-10-17): la **fuente única** del catálogo de ítems
 * del legado —la lista de `w_consulta_principal` **de ginecología** (wiki `04-historia-clinica.md`, "Ítems de la historia
 * clínica"), **13 desde O-22** porque *Exámenes* se retiró— y de cómo se navega a cada una.
 *
 * **El catálogo es el de ginecología**: pediatría no tiene su propia lista todavía (su árbol de la consulta va de
 * *Indicadores incidentes* a *Pre-factura*, sin estudio ecográfico y con ítems propios: ver
 * `Docs/Web/observaciones-pendientes.md`, O-35). Mientras tanto se le quitan los dos ítems que no aplican —el
 * **estudio ecográfico** y el **control de embarazo**— con `AlcanceDeLaEspecialidad`.
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

    /**
     * La clave de la sección de exámenes e imágenes en `?seccion=`, **retirada en O-22 (2026-10-17)**: la
     * galería de imágenes vive ahora como solapa de *Evaluación física* y los exámenes informados, dentro de
     * la solapa *Resultado de exámenes*. La clave se conserva **sólo** para redirigir los enlaces viejos
     * (`ConsultaController::ver`); ya no es una sección del catálogo y `normalizar()` la rechaza.
     */
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

    /*
    |--------------------------------------------------------------------------
    | Las secciones propias de la consulta PEDIÁTRICA (2026-10-19)
    |--------------------------------------------------------------------------
    |
    | La consulta de pediatría no es la de ginecología con dos ítems menos: su árbol tiene 15 ítems, con
    | nombres propios (medido en `w_consulta_principal.srw:2634-2715`). Estas son las claves de los que no
    | existían. Las que ya tienen pantalla, la abren; las que son de un postgrado, las enciende el postgrado.
    */

    /** «Exámenes/Estudios»: la solapa *Resultado de exámenes* de la evaluación física (WEB-6.5). */
    public const SECCION_EXAMENES_ESTUDIOS = 'examenes-estudios';

    /** «Inmunizaciones»: el carnet de vacunas del paciente, que es una pantalla propia (WEB-7.4.4). */
    public const SECCION_INMUNIZACIONES = 'inmunizaciones';

    /** «Alimentación»: la dieta / recomendaciones de la consulta (la pestaña *Dieta* de Solicitudes). */
    public const SECCION_ALIMENTACION = 'alimentacion';

    /** «Representante»: el adulto que responde por el paciente, que se edita en su ficha (F3). */
    public const SECCION_REPRESENTANTE = 'representante';

    /** «Indicadores incidentes»: el panel de **nefrología pediátrica** (`tabpage_35` del escritorio). */
    public const SECCION_INDICADORES = 'indicadores';

    /** «Pre-factura»: llega con F9. */
    public const SECCION_PREFACTURA = 'prefactura';

    /** «Neumología»: la pestaña de neumología pediátrica (R.X. de tórax, espirometría, broncoscopía). */
    public const SECCION_NEUMOLOGIA = 'neumologia';

    /** «Procedimientos»: las endoscopías digestivas de gastroenterología pediátrica. */
    public const SECCION_ENDOSCOPIAS = 'endoscopias';

    /** «I.Q.»: las intervenciones y la evaluación pre-operatoria de cirugía pediátrica. */
    public const SECCION_QUIRURGICO = 'quirurgico';

    /** «Ecografía»: las ecografías abdominal y renal de pediatría (no son el estudio del ítem 5 de ginecología). */
    public const SECCION_ECOGRAFIA = 'ecografia';

    /**
     * Los **13 ítems** del panel, en el orden del legado (O-22 quitó *Exámenes*, el ítem 7: su galería de
     * imágenes pasó a *Evaluación física* y los exámenes informados a la solapa *Resultado de exámenes*).
     *
     * El `estado` es `listo` (ya hay pantalla a la que entrar) o `proximamente`; `seccion` es la clave del contenido que
     * se abre **dentro de la misma consulta** (`?seccion=…`) y falta sólo en el ítem que todavía no tiene pantalla.
     *
     * La **numeración no se renumera** al quitar un ítem: `numero` es el del legado y es lo que lo ata a su
     * fuente (quedan el 1 a 6 y el 8 a 14).
     */
    private const ITEMS_GINECOLOGIA = [
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
     * **El catálogo de la consulta pediátrica** (2026-10-19): los ítems del árbol de PediaReport, en su orden y
     * con su numeración (`fuentes-pediatria/ventana_consulta/w_consulta_principal.srw:2634-2715`).
     *
     * No está el estudio ecográfico ni el control de embarazo (pediatría no los tiene), y el **ítem 5
     * «Indicadores incidentes» tampoco está acá**: es el panel de nefrología pediátrica y lo agrega ese
     * postgrado (`EXTRA['indicadores']`). Un pediatra general tiene 14 ítems; uno con nefrología, 15.
     *
     * Los ítems que ya tienen pantalla la abren con su clave; los que la tienen en otra pantalla (el carnet
     * de vacunas, la dieta, la ficha del representante) los redirige el controlador a donde corresponde.
     */
    private const ITEMS_PEDIATRIA = [
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
            'detalle' => 'Mediciones, examen físico, cuestionario y la galería de imágenes.', 'tarea' => 'WEB-7.4.3', 'estado' => 'listo',
            'seccion' => self::SECCION_EVALUACION],
        ['numero' => 6, 'grupo' => 'Consulta', 'titulo' => 'Exámenes/Estudios', 'icono' => 'bi-clipboard2-data',
            'detalle' => 'Los resultados de laboratorio, imágenes y estudios exploratorios.', 'tarea' => 'WEB-6.5', 'estado' => 'listo',
            'seccion' => self::SECCION_EXAMENES_ESTUDIOS],
        ['numero' => 7, 'grupo' => 'Consulta', 'titulo' => 'Inmunizaciones', 'icono' => 'bi-shield-plus',
            'detalle' => 'El carnet de vacunas del paciente y lo que le toca.', 'tarea' => 'WEB-7.4.4', 'estado' => 'listo',
            'seccion' => self::SECCION_INMUNIZACIONES],
        ['numero' => 8, 'grupo' => 'Consulta', 'titulo' => 'Diagnóstico', 'icono' => 'bi-clipboard2-check',
            'detalle' => 'Catálogo del médico; se propaga a constancias y presupuesto.', 'tarea' => 'WEB-4.5', 'estado' => 'listo',
            'seccion' => self::SECCION_DIAGNOSTICO],
        ['numero' => 9, 'grupo' => 'Consulta', 'titulo' => 'Alimentación', 'icono' => 'bi-egg-fried',
            'detalle' => 'La dieta y las recomendaciones del paciente.', 'tarea' => 'WEB-5.3b', 'estado' => 'listo',
            'seccion' => self::SECCION_ALIMENTACION],
        ['numero' => 10, 'grupo' => 'Consulta', 'titulo' => 'Evolución', 'icono' => 'bi-activity',
            'detalle' => 'Texto libre o por plantilla.', 'tarea' => 'WEB-4.6', 'estado' => 'listo',
            'seccion' => self::SECCION_EVOLUCION],
        ['numero' => 11, 'grupo' => 'Documentos', 'titulo' => 'Récipes', 'icono' => 'bi-prescription2',
            'detalle' => 'Varios por consulta, por medicamento o por tratamiento.', 'tarea' => 'WEB-5.1', 'estado' => 'listo',
            'seccion' => self::SECCION_RECIPES],
        ['numero' => 12, 'grupo' => 'Documentos', 'titulo' => 'Solicitudes', 'icono' => 'bi-send',
            'detalle' => 'Laboratorio, estudios, constancia, reposo, referencia, hospitalización, informe médico y dieta / recomendaciones.',
            'tarea' => 'WEB-5.3a', 'estado' => 'listo', 'seccion' => self::SECCION_SOLICITUDES],
        ['numero' => 13, 'grupo' => 'Documentos', 'titulo' => 'Datos del paciente', 'icono' => 'bi-person-vcard',
            'detalle' => 'La filiación y el contacto del paciente, para completar sin salir de la consulta.', 'tarea' => 'WEB-11.25', 'estado' => 'listo',
            'seccion' => self::SECCION_DATOS],
        ['numero' => 14, 'grupo' => 'Documentos', 'titulo' => 'Representante', 'icono' => 'bi-person-hearts',
            'detalle' => 'El adulto que responde por el paciente: quién lo trae y a quién se le avisa.', 'tarea' => 'F3', 'estado' => 'listo',
            'seccion' => self::SECCION_REPRESENTANTE],
        ['numero' => 15, 'grupo' => 'Documentos', 'titulo' => 'Pre-factura', 'icono' => 'bi-receipt',
            'detalle' => 'Los ítems de facturación de la consulta.', 'tarea' => 'F9', 'estado' => 'proximamente',
            'seccion' => null],
    ];

    /**
     * **Las secciones que agrega un postgrado** cuando el médico lo tiene (2026-10-19). No están en el catálogo
     * base de ninguna especialidad: se insertan después del ítem que dice `despues_de`, con la numeración del
     * legado.
     *
     * Hoy sólo está la de nefrología pediátrica, que es el ítem 5 del árbol de PediaReport. Las otras
     * (`neumologia`, `endoscopias`, `quirurgico`, `ecografia`) están declaradas en el manifiesto de pediatría
     * pero todavía no tienen pantalla: en cuanto la tengan, se agrega su fila acá y el postgrado la enciende.
     */
    private const EXTRA = [
        self::SECCION_INDICADORES => ['numero' => 5, 'grupo' => 'Consulta', 'titulo' => 'Indicadores incidentes',
            'icono' => 'bi-droplet-half', 'detalle' => 'Función renal y electrolitos urinarios (nefrología pediátrica).',
            'tarea' => 'F7', 'estado' => 'proximamente', 'seccion' => self::SECCION_INDICADORES, 'despues_de' => self::SECCION_EVALUACION],
    ];

    /** ¿La especialidad es pediatría? Su catálogo es el suyo, no el de ginecología. */
    private static function esPediatria(?string $especialidad): bool
    {
        return $especialidad !== null && str_contains($especialidad, 'pediatr');
    }

    /**
     * El catálogo completo, con la bandera `disponible` derivada del estado.
     *
     * @param  array<int,string>  $seccionesPostgrados  las secciones que encienden los postgrados del médico
     * @return array<int,array{numero:int, grupo:string, titulo:string, icono:string, detalle:string, tarea:string, estado:string, seccion:?string, disponible:bool}>
     */
    public static function todas(?string $especialidad = null, array $seccionesPostgrados = []): array
    {
        return array_map(
            fn (array $item) => $item + ['disponible' => $item['estado'] === 'listo'],
            self::delaEspecialidad($especialidad, $seccionesPostgrados),
        );
    }

    /**
     * Los ítems que aplican a la especialidad (WEB-12.1).
     *
     * - **Pediatría** tiene su propio catálogo (`ITEMS_PEDIATRIA`), más las secciones de los postgrados que el
     *   médico tenga (`$seccionesPostgrados`, resueltas del manifiesto por el contexto).
     * - **Ginecología** (y cualquier especialidad sin manifiesto) sigue con el catálogo de siempre: en pediatría se
     *   quitan el estudio ecográfico y el control de embarazo; en las demás no se toca nada.
     *
     * La numeración no se toca: un ítem que no aplica deja su hueco (el 5 de pediatría es el de nefrología).
     *
     * @param  array<int,string>  $seccionesPostgrados
     * @return array<int,array<string,mixed>>
     */
    private static function delaEspecialidad(?string $especialidad, array $seccionesPostgrados = []): array
    {
        $alcance = AlcanceDeLaEspecialidad::de($especialidad);

        if (self::esPediatria($especialidad)) {
            $base = self::ITEMS_PEDIATRIA;
            $extras = array_values(array_filter(array_map(
                fn (string $clave) => self::EXTRA[$clave] ?? null,
                $seccionesPostgrados,
            )));
        } else {
            $base = array_filter(
                self::ITEMS_GINECOLOGIA,
                fn (array $item) => match ($item['seccion']) {
                    self::SECCION_EMBARAZO => $alcance->muestraElEmbarazo(),
                    self::SECCION_ESTUDIO => $alcance->muestraElEstudio(),
                    default => true,
                },
            );
            $extras = [];
        }

        // Las secciones del postgrado se insertan detrás de su ítem ancla, en el orden en que las declara.
        $salida = [];
        foreach ($base as $item) {
            $salida[] = $item;

            foreach ($extras as $indice => $extra) {
                if ($extra['despues_de'] === $item['seccion']) {
                    $salida[] = $extra;
                    unset($extras[$indice]);
                }
            }
        }

        return $salida;
    }

    public static function total(?string $especialidad = null, array $seccionesPostgrados = []): int
    {
        return count(self::delaEspecialidad($especialidad, $seccionesPostgrados));
    }

    /** Cuántos ítems ya tienen pantalla: 12 de 13 en ginecología (sólo falta la pre-factura → F9) y 13 de 14 en pediatría. */
    public static function disponibles(?string $especialidad = null, array $seccionesPostgrados = []): int
    {
        return count(array_filter(self::delaEspecialidad($especialidad, $seccionesPostgrados), fn (array $item) => $item['estado'] === 'listo'));
    }

    /**
     * Una sección por su clave, o `null` si la clave no existe (o es la de un ítem sin pantalla).
     *
     * Es la normalización que comparten el shell y el menú: un `?seccion=` que no se entiende se trata como "ninguna".
     *
     * @return array{numero:int, grupo:string, titulo:string, icono:string, detalle:string, tarea:string, estado:string, seccion:?string, disponible:bool}|null
     */
    public static function porSeccion(?string $seccion, ?string $especialidad = null, array $seccionesPostgrados = []): ?array
    {
        if ($seccion === null || $seccion === '') {
            return null;
        }

        foreach (self::todas($especialidad, $seccionesPostgrados) as $item) {
            if ($item['seccion'] === $seccion) {
                return $item;
            }
        }

        return null;
    }

    /** ¿La clave corresponde a una sección con pantalla dentro de la consulta? */
    public static function existeSeccion(?string $seccion, ?string $especialidad = null, array $seccionesPostgrados = []): bool
    {
        return self::porSeccion($seccion, $especialidad, $seccionesPostgrados) !== null;
    }

    /**
     * **La sección con la que abre la consulta** cuando la URL no pide ninguna (O-06, 2026-10-17): sin
     * `?seccion=` la consulta abre en **Motivos de consulta**, que es el ítem 1 del catálogo.
     *
     * Se resuelve en el controlador —que es el que arma la sección— y **sin redirect**: la URL queda limpia.
     * Una sección **pedida** se devuelve tal cual, exista o no, para que un `?seccion=` desconocido siga
     * cayendo en el estado vacío del shell como siempre: acá sólo se decide el punto de entrada, no se
     * valida la sección.
     */
    public static function deEntrada(?string $seccion): string
    {
        return $seccion === null || $seccion === '' ? self::SECCION_MOTIVOS : $seccion;
    }

    /** La clave normalizada: la pedida si es una sección con pantalla, `null` en cualquier otro caso. */
    public static function normalizar(?string $seccion, ?string $especialidad = null, array $seccionesPostgrados = []): ?string
    {
        return self::porSeccion($seccion, $especialidad, $seccionesPostgrados)['seccion'] ?? null;
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
    public static function submenu(int $consultaId, ?string $seccionActual, ?string $especialidad = null, array $seccionesPostgrados = []): array
    {
        $actual = self::normalizar($seccionActual, $especialidad, $seccionesPostgrados);

        return array_map(fn (array $item) => [
            'texto' => $item['titulo'],
            'icono' => $item['icono'],
            'url' => $item['seccion'] !== null
                ? route('clinica.consulta', ['consulta' => $consultaId, 'seccion' => $item['seccion']])
                : null,
            'activo' => $actual !== null && $item['seccion'] === $actual,
            'deshabilitado' => $item['estado'] !== 'listo',
        ], self::todas($especialidad, $seccionesPostgrados));
    }
}
