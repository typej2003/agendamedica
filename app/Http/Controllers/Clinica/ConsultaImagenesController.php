<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\ElegirLaImagenDelExamen;
use App\Actions\Consulta\GuardarImagenDeLaConsulta;
use App\Actions\Consulta\OlvidarElTrazoDeLaFoto;
use App\Actions\Consulta\QuitarImagenDeLaConsulta;
use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\ImagenDelExamen;
use App\Clinica\Consulta\ImagenesDeLaConsulta;
use App\Clinica\Consulta\TagDeImagen;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **Las imágenes de una consulta** (WEB-4.7, ítem 7 del panel del legado hasta O-22): subir una imagen con su
 * descripción, quitarla y **abrirla**.
 *
 * La galería de la consulta vive desde O-22 (2026-10-17) en la solapa *Imágenes* de *Evaluación física*
 * (`?seccion=evaluacion&pestana=imagenes`): la sección *Exámenes* dejó de existir y `?seccion=examenes`
 * redirige ahí. La lectura la arma `ConsultaController` como todas las secciones del shell; acá están las tres
 * cosas que **escriben o sirven un archivo**, y viven juntas porque son la misma responsabilidad: el ciclo de
 * vida de una imagen.
 *
 * ## Las tres reglas de la subida (y dónde están)
 *
 * Lo que se acepta, con qué nombre se guarda y en qué disco vive **no** se decide acá: es
 * `App\Clinica\Archivos\ArchivosClinicos`, una clase sin estado que es la única fuente de esa política
 * (PLAN-WEB.md, R3). Este controlador la aplica:
 *
 *  - **Validación de verdad**: el tipo **real** (el que detecta el contenido, no el que declara el
 *    cliente) contra una lista blanca de imágenes, y un tope de tamaño. El nombre que manda el navegador
 *    no se usa para nada.
 *  - **Disco privado**: el archivo va a `storage/app/clinica` (`config/filesystems.php` → `clinica`),
 *    fuera de `public/` y sin enlace simbólico. El servidor web no puede alcanzarlo.
 *  - **Autorización al servir**: `descargar()` resuelve la fila contra el `reg_medico` del contexto **y**
 *    contra la consulta que se está mirando. Una imagen de otro médico —o de otra consulta— da **404**,
 *    que es la misma vara que la consulta ajena: no se confirma que exista.
 *
 * El archivo se sirve con `Storage::response()`, que arma el `Content-Type` **real** desde el archivo y
 * un `Content-Disposition` con el nombre; la ruta del sistema (`storage/app/clinica/...`) **nunca** sale
 * en la respuesta.
 */
class ConsultaImagenesController extends Controller
{
    /**
     * Los exámenes que llevan **una sola** imagen: la nueva reemplaza a la anterior (WEB-11.11). Son los mismos que
     * tienen la tarjeta de la imagen con dos modos (O-09): la foto del estudio es una y es la que se marca.
     */
    private const DE_UNA_SOLA_IMAGEN = ImagenDelExamen::EXAMS;

    use ResuelveLosRegistrosDelMedico;

    public function __construct(
        private ConsultasDelContexto $consultas,
        private ImagenesDeLaConsulta $imagenes,
    ) {
    }

    /**
     * **Subir una imagen a la consulta.** El `<form>` manda el archivo y su descripción; el archivo va al
     * disco privado con el nombre que arma el servidor y la fila cuelga de la consulta.
     *
     * Es seguro reenviar el formulario (el médico aprieta dos veces): cada subida es una fila nueva con su
     * propio archivo, igual que el escritorio —que avisa cuando la **misma ruta** ya está en la lista,
     * `w_consulta_principal.srw:10727-10732`, y ahí sí la rechaza—. Dos archivos distintos con la misma
     * descripción son dos estudios distintos.
     */
    public function subir(Request $request, Consulta $consulta, GuardarImagenDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            // La política del archivo (tipo real, extensión y tamaño) vive en `ArchivosClinicos`; acá se
            // dice el texto humano del error, que es el mismo de la ayuda del campo.
            'imagen' => ArchivosClinicos::reglasDeSubida(),
            // La **solapa del examen** desde la que se sube (WEB-6.5): manda la etiqueta de la fila. En la
            // sección genérica de exámenes no viene y la columna lleva la descripción que teclea el médico.
            'examen' => ['nullable', 'string', Rule::in(TagDeImagen::solapas())],
            'descripcion' => [
                'nullable', 'string', 'max:' . GuardarImagenDeLaConsulta::LARGO_DESCRIPCION,
                // La columna `observacion` es la etiqueta del examen (trampa 116): una descripción que fuera
                // una etiqueta haría que la imagen apareciera en un examen que no es el suyo.
                function (string $atributo, $valor, callable $fallar) {
                    if (TagDeImagen::esEtiqueta($valor)) {
                        $fallar('Esa descripción es la etiqueta de un examen. Escribe qué es la imagen.');
                    }
                },
            ],
        ], [
            'imagen.required' => 'Elige la imagen que quieres subir.',
            'imagen.max' => 'La imagen no puede pesar más de ' . ArchivosClinicos::textoMaximo() . '.',
        ]);

        $examen = $datos['examen'] ?? null;
        $etiqueta = $examen === null ? null : TagDeImagen::deLaSolapa($examen);

        try {
            $imagen = $guardar->ejecutar($consulta, $request->file('imagen'), $etiqueta ?? ($datos['descripcion'] ?? null));
        } catch (RuntimeException $e) {
            // Un disco lleno o sin permisos es un problema del servidor, no de lo que escribió el médico:
            // se dice sin lenguaje técnico y se deja lo escrito en el formulario.
            return $this->volver($consulta, $examen)->withInput()
                ->with('error', 'No se pudo guardar la imagen en el servidor. Vuelve a intentarlo; si sigue igual, avísale al administrador.');
        }

        // Colposcopía e histeroscopía llevan **una sola imagen** (Alexander, WEB-11.11): la nueva reemplaza a la anterior.
        // La que se va se lleva su **trazo** (O-09): la marca sobre una foto no puede quedar huérfana.
        if (in_array($examen, self::DE_UNA_SOLA_IMAGEN, true)) {
            $contexto = $request->attributes->get('contexto_clinico');
            $anteriores = $this->imagenes->deUnExamen($contexto['reg_medico'], (int) $consulta->numhistoria, (int) $consulta->nroconsulta, $examen);

            foreach ($anteriores as $anterior) {
                if ((int) $anterior->id !== (int) $imagen->id) {
                    app(OlvidarElTrazoDeLaFoto::class)->ejecutar((int) $anterior->id);
                    app(QuitarImagenDeLaConsulta::class)->ejecutar($anterior->modelo);
                }
            }

            // La foto que se acaba de subir pasa a ser **la del examen**, y el modo, el de la foto: es lo que el médico
            // está mirando cuando sube desde la tarjeta (O-09).
            app(ElegirLaImagenDelExamen::class)->ejecutar($consulta, $examen, ImagenDelExamen::MODO_FOTO, (int) $imagen->id);
        }

        $texto = trim((string) $imagen->observacion);
        $descripcion = TagDeImagen::esEtiqueta($texto) ? null : $texto;

        return $this->volver($consulta, $examen)->with('estado', $descripcion === null
            ? 'Imagen subida a ' . ($etiqueta === null ? 'esta consulta' : 'este examen') . '.'
            : 'Imagen subida: «' . $descripcion . '».');
    }

    /**
     * **Quitar una imagen de la consulta.** Borra la fila y, si el archivo es del disco privado, el
     * archivo (ver `QuitarImagenDeLaConsulta`). Una imagen del escritorio se quita de la lista y su
     * archivo queda donde está: en la PC del consultorio.
     */
    public function quitar(Request $request, Consulta $consulta, int $imagen, QuitarImagenDeLaConsulta $quitar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        // La solapa (WEB-6.5) sólo decide a dónde se vuelve: el borrado es el mismo y la autorización
        // también (la fila tiene que ser de esta consulta y de este médico).
        $examen = $request->input('examen');
        $examen = is_string($examen) && TagDeImagen::deLaSolapa($examen) !== null ? $examen : null;

        $fila = $this->imagenes->delContexto(
            $this->registrosDelMedico($contexto['medico']),
            $imagen,
            (int) $consulta->numhistoria,
            (int) $consulta->nroconsulta,
        );

        abort_unless($fila !== null, 404);

        // Si la foto tenía marcas, se van con ella (O-09, regla 5): la pantalla ya lo avisó antes de borrar.
        app(OlvidarElTrazoDeLaFoto::class)->ejecutar((int) $fila->id);

        $quitar->ejecutar($fila->modelo);

        return $this->volver($consulta, $examen)->with('estado', $fila->descripcion === null
            ? 'Imagen quitada de la consulta.'
            : 'Imagen quitada: «' . $fila->descripcion . '».');
    }

    /**
     * **Servir el archivo de una imagen**, sólo al médico dueño de la historia.
     *
     * Es el único camino al archivo: no hay una URL pública que lo alcance porque el disco está fuera de
     * `public/`. La autorización son dos comprobaciones juntas —el `reg_medico` del contexto y la consulta
     * que se está mirando— y las dos las resuelve `ImagenesDeLaConsulta::delContexto()`. Si la fila no es
     * de este médico, o no cuelga de esta consulta, o su archivo quedó en el disco del consultorio, la
     * respuesta es **404**: no se confirma nada de un dato ajeno, que es la regla de toda la web clínica.
     *
     * `Storage::response()` pone el `Content-Type` del archivo de verdad y `inline` para que la imagen se
     * vea en el navegador. La ruta del sistema no viaja en la respuesta ni en un encabezado.
     */
    public function descargar(Request $request, Consulta $consulta, int $imagen): StreamedResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->delContexto($request, $consulta);

        $fila = $this->imagenes->delContexto(
            $this->registrosDelMedico($contexto['medico']),
            $imagen,
            (int) $consulta->numhistoria,
            (int) $consulta->nroconsulta,
            $request->query('alcance') === 'todas',
        );

        // Sin fila, sin archivo en el servidor o archivo que ya no está: 404. Los tres casos son "no hay
        // nada que servir" y no se distinguen hacia afuera.
        abort_if($fila === null || ! $fila->enElServidor, 404);

        $disco = Storage::disk($fila->disco);

        abort_unless($disco->exists($fila->archivo), 404);

        // Un HEIC/HEIF (WEB-T.1) no se muestra en el navegador: va como descarga y con su Content-Type real (finfo,
        // según la libmagic, lo daría como `application/octet-stream`). El resto se ve en la pestaña, como siempre.
        $encabezados = $fila->descarga ? ['Content-Type' => $fila->tipo ?: 'application/octet-stream'] : [];

        // Laravel arma el `Content-Disposition` con el nombre ya limpio y su versión ASCII de respaldo (un nombre con
        // `ñ` o acentos, como el que tenía el archivo en la PC, no rompe el encabezado).
        return $disco->response($fila->archivo, $fila->nombre, $encabezados + [
            // Una imagen clínica no se cachea en un proxy compartido ni se indexa.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $fila->descarga ? 'attachment' : 'inline');
    }

    /**
     * La vuelta: a la **galería de la solapa *Imágenes* de Evaluación física** (O-22: era la sección genérica
     * de exámenes) o, si la subida vino de la solapa de un examen, a **esa** solapa (con la consulta adentro,
     * como arma el shell todas las secciones).
     */
    private function volver(Consulta $consulta, ?string $examen = null)
    {
        // Las imágenes de un estudio ecográfico (WEB-11.12) vuelven a su estudio (`?seccion=estudio&tipo=`).
        $tipoDeEco = TagDeImagen::tipoDeEco($examen);

        if ($tipoDeEco !== null) {
            return redirect()->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipoDeEco]);
        }

        // La galería general vuelve a su lugar, *Solicitudes → Imágenes* (2026-10-23); la imagen de una solapa de examen, a esa solapa.
        $parametros = $examen === null
            ? [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_SOLICITUDES, 'solicitud' => PestanasDeSolicitudes::IMAGENES]
            : [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => $examen];

        return redirect()->route('clinica.consulta', $parametros);
    }

    /** La consulta del contexto (404 si es de otro médico). */
    private function delContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }
}
