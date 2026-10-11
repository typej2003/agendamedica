<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarImagenDeLaConsulta;
use App\Actions\Consulta\QuitarImagenDeLaConsulta;
use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\ImagenesDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **Las imágenes de una consulta** (WEB-4.7, ítem 7 del panel del legado): subir una imagen con su
 * descripción, quitarla y **abrirla**.
 *
 * Es el controlador de la parte nueva del ítem *Exámenes*. La lectura de la sección (los exámenes de
 * laboratorio de la consulta y la lista de sus imágenes) la arma `ConsultaController::ver`
 * (`?seccion=examenes`), como todas las secciones del shell; acá están las tres cosas que **escriben o
 * sirven un archivo**, y viven juntas porque son la misma responsabilidad: el ciclo de vida de una imagen.
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
            'descripcion' => 'nullable|string|max:' . GuardarImagenDeLaConsulta::LARGO_DESCRIPCION,
        ], [
            'imagen.required' => 'Elige la imagen que quieres subir.',
            'imagen.max' => 'La imagen no puede pesar más de ' . ArchivosClinicos::textoMaximo() . '.',
        ]);

        try {
            $imagen = $guardar->ejecutar($consulta, $request->file('imagen'), $datos['descripcion'] ?? null);
        } catch (RuntimeException $e) {
            // Un disco lleno o sin permisos es un problema del servidor, no de lo que escribió el médico:
            // se dice sin lenguaje técnico y se deja lo escrito en el formulario.
            return $this->volver($consulta)->withInput()
                ->with('error', 'No se pudo guardar la imagen en el servidor. Vuelve a intentarlo; si sigue igual, avísale al administrador.');
        }

        $descripcion = trim((string) $imagen->observacion);

        return $this->volver($consulta)->with('estado', $descripcion === ''
            ? 'Imagen subida a esta consulta.'
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

        $fila = $this->imagenes->delContexto(
            $this->registrosDelMedico($contexto['medico']),
            $imagen,
            (int) $consulta->numhistoria,
            (int) $consulta->nroconsulta,
        );

        abort_unless($fila !== null, 404);

        $quitar->ejecutar($fila->modelo);

        return $this->volver($consulta)->with('estado', $fila->descripcion === null
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
        );

        // Sin fila, sin archivo en el servidor o archivo que ya no está: 404. Los tres casos son "no hay
        // nada que servir" y no se distinguen hacia afuera.
        abort_if($fila === null || ! $fila->enElServidor, 404);

        $disco = Storage::disk($fila->disco);

        abort_unless($disco->exists($fila->archivo), 404);

        return $disco->response($fila->archivo, $fila->nombre, [
            'Content-Disposition' => 'inline; filename="' . $fila->nombre . '"',
            // Una imagen clínica no se cachea en un proxy compartido ni se indexa.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** La vuelta a la sección, que es donde vive todo esto. */
    private function volver(Consulta $consulta)
    {
        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EXAMENES,
        ]);
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
