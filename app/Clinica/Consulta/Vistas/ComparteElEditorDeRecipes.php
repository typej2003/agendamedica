<?php

namespace App\Clinica\Consulta\Vistas;

use App\Actions\Consulta\AgregarMedicamentoAlVademecum;
use Illuminate\Support\Collection;

/**
 * **Lo que el editor del récipe del paciente y el de la pareja tienen en común** (WEB-5.1b): la caja de
 * herramientas para llenarlo —el buscador del vademécum, los tratamientos y el alta del medicamento que
 * falta—, los topes de cada fila, los avisos y las filas del editor (de la base, o las del `old()` de un
 * `POST` que falló). Los dos ViewModels (`SeccionDeRecipesVista` y `SeccionDeRecipeDeLaParejaVista`) usan los
 * mismos parciales de Blade para eso, así que el texto y la regla de cada cosa viven **una sola vez**.
 *
 * Lo usa quien tiene `$urls` (`medicamentos`, `tratamientos` y `crearMedicamento`), `$old` (el `old()` del
 * formulario) y `editor()` (`null` si no hay editor, o un arreglo con `filas`). Sin base de datos.
 */
trait ComparteElEditorDeRecipes
{
    // ----------------------------------------------------------------------------------------- Filas

    /**
     * Las filas del editor: cada una con su clave dentro del formulario (`items[k]`), el código, el nombre
     * que se ve, la cantidad y las indicaciones. Si el `POST` falló, salen del `old()` (con los nombres que
     * viajaron como campo de pantalla); si no, de la base.
     *
     * @param  Collection<int,object>|null  $items  los medicamentos que el récipe tiene en la base
     * @return array<int,array{clave:int, codigo:string, nombre:string, generico:?string, cantidad:string, indicaciones:string}>
     */
    protected function filasDelEditor(?Collection $items): array
    {
        if (($this->old['editor'] ?? null) !== null) {
            $filas = [];
            $clave = 0;

            foreach ((array) ($this->old['items'] ?? []) as $item) {
                if (! is_array($item) || trim((string) ($item['codemedicina'] ?? '')) === '') {
                    continue;
                }

                $filas[] = [
                    'clave' => $clave++,
                    'codigo' => trim((string) $item['codemedicina']),
                    'nombre' => trim((string) ($item['nombre'] ?? '')) ?: trim((string) $item['codemedicina']),
                    'generico' => null,
                    'cantidad' => trim((string) ($item['cantidad'] ?? '')),
                    'indicaciones' => (string) ($item['indicaciones'] ?? ''),
                ];
            }

            return $filas;
        }

        if ($items === null) {
            return [];
        }

        return $items->values()->map(fn (object $item, int $clave) => [
            'clave' => $clave,
            'codigo' => $item->codigo,
            'nombre' => $item->nombre,
            'generico' => $item->generico,
            'cantidad' => $item->cantidad === null ? '' : (string) $item->cantidad,
            'indicaciones' => (string) ($item->indicaciones ?? ''),
        ])->all();
    }

    /** La clave de la siguiente fila que el script agrega (una por encima de las que ya hay). */
    public function siguienteClave(): int
    {
        $editor = $this->editor();

        return $editor === null ? 0 : count($editor['filas']);
    }

    public function estadoDelEditor(): array
    {
        $editor = $this->editor();
        $total = $editor === null ? 0 : count($editor['filas']);

        return [
            'tono' => $total === 0 ? 'neutro' : 'marca',
            'icono' => $total === 0 ? 'bi-dash-circle' : 'bi-capsule',
            'texto' => $total === 0 ? 'Sin medicamentos' : $total . ($total === 1 ? ' medicamento' : ' medicamentos'),
        ];
    }

    // --------------------------------------------------------------------------------- Herramientas

    public function urlMedicamentos(): string
    {
        return $this->urls['medicamentos'];
    }

    public function urlTratamientos(): string
    {
        return $this->urls['tratamientos'];
    }

    public function urlCrearMedicamento(): string
    {
        return $this->urls['crearMedicamento'];
    }

    /**
     * Lo que la caja de herramientas ofrece, y que el editor de **tratamientos del catálogo** (WEB-8.1a) apaga: el
     * modo "Tratamientos" (un tratamiento no se arma con otros tratamientos), el alta rápida del medicamento (en el
     * catálogo se crea en su propia pantalla, `enlaceDeMedicamentos()`) y los botones de subir y bajar un
     * medicamento (el orden de un tratamiento importa; el de un récipe, no). Los dos editores de récipes quedan
     * como estaban: ofrecen las dos primeras y no la tercera. El alta rápida la ofrecen los tres.
     */
    public function ofreceTratamientos(): bool
    {
        return true;
    }

    public function ofreceCrearMedicamento(): bool
    {
        return true;
    }

    public function ofreceOrdenar(): bool
    {
        return false;
    }

    public function cantidadMaxima(): int
    {
        return SeccionDeRecipesVista::CANTIDAD_MAXIMA;
    }

    public function largoIndicaciones(): int
    {
        return SeccionDeRecipesVista::LARGO_INDICACIONES;
    }

    public function largoNombre(): int
    {
        return AgregarMedicamentoAlVademecum::LARGO_NOMBRE;
    }

    public function largoPresentacion(): int
    {
        return AgregarMedicamentoAlVademecum::LARGO_PRESENTACION;
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el récipe. Si sales ahora, se pierden.';
    }

    /** El botón del diálogo del alta rápida: dice a dónde entra el medicamento creado. */
    public function textoCrearMedicamento(): string
    {
        return 'Crear y agregar al récipe';
    }

    public function avisoDeConexion(): string
    {
        return 'Crear un medicamento necesita conexión con el servidor: se guarda en tu vademécum, '
            . 'el mismo que usa el teléfono.';
    }

    /** La ayuda del buscador: qué se busca y cómo. */
    public function ayudaDelBuscador(): string
    {
        return 'Por nombre comercial o principio activo.';
    }

    public function ayudaDeTratamientos(): string
    {
        return 'Carga todos sus medicamentos; puedes cambiarlos antes de guardar.';
    }

    /** El `placeholder` del cuadro de búsqueda en cada modo. */
    public function placeholderMedicamentos(): string
    {
        return 'Keforal, cefalexina, ácido fólico…';
    }

    public function placeholderTratamientos(): string
    {
        return 'Control prenatal, infección urinaria…';
    }

    // ------------------------------------------------------------------- Imprimir y hacer cita (WEB-5.2)

    /**
     * Lo que la sección ofrece para **imprimir** el récipe y para **hacer la cita** que sale al pie de la hoja.
     * Se pone aparte (y no en el constructor) porque no cambia el modo de la sección: es una franja y unos
     * botones, y el ViewModel que no los recibe se arma igual que antes.
     *
     * @var array{dialogo:\App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista, proximaCita:?string, hacerCita:string, imprimir:\Closure, imprimirPareja:string}|null
     */
    private ?array $impresion = null;

    /**
     * @param  array{dialogo:\App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista, proximaCita:?string, hacerCita:string, imprimir:\Closure, imprimirPareja:string}  $impresion
     *         `dialogo` (las opciones del diálogo), `proximaCita` (la frase de lo que sale al pie, o `null` si el
     *         paciente no tiene), `hacerCita` (a dónde lleva "Hacer cita"), `imprimir` (`fn(int $numero)`: la vista
     *         previa de ese récipe) e `imprimirPareja`
     */
    public function conImpresion(array $impresion): static
    {
        $this->impresion = $impresion;

        return $this;
    }

    public function puedeImprimir(): bool
    {
        return $this->impresion !== null;
    }

    public function dialogoDeImpresion(): ?\App\Clinica\Impresion\Vistas\OpcionesDeImpresionVista
    {
        return $this->impresion['dialogo'] ?? null;
    }

    /** ¿El paciente ya tiene una próxima cita agendada (la que sale al pie de la hoja)? */
    public function tieneProximaCita(): bool
    {
        return ($this->impresion['proximaCita'] ?? null) !== null;
    }

    /** Lo que sale al pie de la hoja, o por qué no sale nada. */
    public function textoDeLaProximaCita(): string
    {
        return $this->impresion['proximaCita'] ?? 'El paciente no tiene próxima cita agendada: la hoja sale sin ella.';
    }

    /** "Hacer cita": el formulario de la agenda con el paciente ya elegido, que vuelve a esta sección al guardar. */
    public function urlHacerCita(): string
    {
        return $this->impresion['hacerCita'] ?? '';
    }

    /** La vista previa de un récipe del paciente. */
    public function urlImprimir(int $numero): string
    {
        return ($this->impresion['imprimir'])($numero);
    }

    /** La vista previa del récipe de la pareja. */
    public function urlImprimirPareja(): string
    {
        return $this->impresion['imprimirPareja'] ?? '';
    }

    // ------------------------------------------------------- Verificación pública del récipe (WEB-5.6)

    /**
     * Lo que la sección ofrece para **el QR de verificación** de cada récipe: en qué estado está (`sin` QR todavía,
     * `activa` o `anulada`) y a dónde van «Anular» y «Reactivar». Aparte del constructor, como `conImpresion`: no cambia el
     * modo de la sección.
     *
     * @var array{paciente:array<int,string>, pareja:?string, anular:\Closure, reactivar:\Closure, anularPareja:string, reactivarPareja:string}|null
     */
    private ?array $verificaciones = null;

    /**
     * @param  array{paciente:array<int,string>, pareja:?string, anular:\Closure, reactivar:\Closure, anularPareja:string, reactivarPareja:string}  $verificaciones
     *         `paciente` (el estado por número de récipe; un récipe sin fila no figura), `pareja` (el estado del de la pareja, o
     *         `null`), `anular`/`reactivar` (`fn(int $numero)`: las URLs de ese récipe) y las dos de la pareja
     */
    public function conVerificaciones(array $verificaciones): static
    {
        $this->verificaciones = $verificaciones;

        return $this;
    }

    /**
     * La pastilla de la verificación de un récipe del paciente y su acción, o `null` si la sección no la ofrece.
     *
     * @return array{tono:string, icono:string, texto:string, titulo:string, accion:?array{texto:string, icono:string, url:string, aviso:string}}|null
     */
    public function verificacionDe(int $numero): ?array
    {
        if ($this->verificaciones === null) {
            return null;
        }

        return $this->verificacion(
            $this->verificaciones['paciente'][$numero] ?? null,
            'el récipe ' . $numero,
            ($this->verificaciones['anular'])($numero),
            ($this->verificaciones['reactivar'])($numero),
        );
    }

    /** Lo mismo para el récipe de la pareja. */
    public function verificacionDeLaPareja(): ?array
    {
        if ($this->verificaciones === null) {
            return null;
        }

        return $this->verificacion(
            $this->verificaciones['pareja'],
            'el récipe de la pareja',
            $this->verificaciones['anularPareja'],
            $this->verificaciones['reactivarPareja'],
        );
    }

    /**
     * @return array{tono:string, icono:string, texto:string, titulo:string, accion:?array{texto:string, icono:string, url:string, aviso:string}}
     */
    private function verificacion(?string $estado, string $cual, string $urlAnular, string $urlReactivar): array
    {
        if ($estado === 'activa') {
            return [
                'tono' => 'exito',
                'icono' => 'bi-qr-code',
                'texto' => 'Verificación activa',
                'titulo' => 'El QR de la hoja impresa verifica este récipe en la página pública.',
                'accion' => [
                    'texto' => 'Anular QR',
                    'icono' => 'bi-slash-circle',
                    'url' => $urlAnular,
                    'aviso' => '¿Anular el QR de ' . $cual . '? Quien escanee la hoja impresa verá «Anulado por el médico». '
                        . 'Puedes reactivarlo cuando quieras.',
                ],
            ];
        }

        if ($estado === 'anulada') {
            return [
                'tono' => 'peligro',
                'icono' => 'bi-slash-circle',
                'texto' => 'QR anulado',
                'titulo' => 'La página pública dice «Anulado por el médico» para este récipe.',
                'accion' => [
                    'texto' => 'Reactivar QR',
                    'icono' => 'bi-arrow-counterclockwise',
                    'url' => $urlReactivar,
                    'aviso' => '¿Reactivar el QR de ' . $cual . '? La hoja impresa vuelve a verificarse en la página pública.',
                ],
            ];
        }

        return [
            'tono' => 'neutro',
            'icono' => 'bi-qr-code',
            'texto' => 'Aún sin QR',
            'titulo' => 'El QR se crea la primera vez que imprimes este récipe.',
            'accion' => null,
        ];
    }
}
