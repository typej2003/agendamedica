<?php

namespace App\Clinica\Consulta\Vistas;

use App\Actions\Pacientes\AgregarAntecedenteAlCatalogo;
use App\Actions\Pacientes\GuardarAntecedentes;
use App\Clinica\Consulta\AntecedentesDelPaciente;
use Illuminate\Support\Collection;

/**
 * **El buscador del catálogo de antecedentes** (WEB-4.4): lo que la sección ofrece para agregarle a un
 * paciente y el alta del antecedente que falta.
 *
 * El catálogo es **del médico** y lo comparte con el escritorio —el botón *Agregar* de la pestaña de
 * antecedentes abre `w_nuevo_antecedente` sobre el mismo `antecedentes` y el mismo `tipo_antecedente`—, así
 * que la pantalla no inventa antecedentes: busca en el catálogo y, si el que hace falta no está, lo crea
 * (`App\Actions\Pacientes\AgregarAntecedenteAlCatalogo`).
 *
 * Es el mismo patrón que el buscador de motivos (WEB-4.2) y por las mismas razones:
 *
 *  - **Un antecedente por nombre** (el de código más bajo): el catálogo del legado trae repetidos (284
 *    filas por médico con `CANCER` tres veces) y ofrecer dos filas idénticas sería pedirle al médico que
 *    adivine.
 *  - **La búsqueda no distingue tildes ni mayúsculas** (`BusquedaDePacientes::normalizar`), porque el
 *    `LIKE` de SQLite no pliega `á`.
 *  - **"Ya está en el paciente" y "existe en el catálogo" son preguntas distintas**: la primera decide qué
 *    fila no se ofrece para agregar y la segunda si el buscador ofrece **crear** lo escrito.
 *
 * El buscador es un **`GET`** (no hay JS que lo haga por `fetch`, a diferencia del de motivos): la pantalla
 * se recarga con el filtro y **«Agregar» es un `POST` inmediato** (O-07, 2026-10-17): el antecedente se
 * escribe en el paciente en el acto y la fila aparece en la lista de la izquierda sin apretar el botón de
 * guardar —el botón sólo aplica el detalle y la casilla de Alerta—. Así la sección entera funciona sin
 * JavaScript.
 *
 * Desde A-06 (revisión de Alexander) el catálogo es la **columna derecha** de la sección, su buscador está
 * **siempre a la vista** (no detrás de un botón) y el filtro por tipo es el **chip elegido**, que comparten las
 * dos columnas: acá no hay `<select>` ni «Todos los tipos», y el catálogo que se lista es el de ese tipo.
 */
final class SeccionDeAntecedentesCatalogoVista
{
    /**
     * @param  Collection<int,object>  $resultados  lo que devolvió `AntecedentesDelPaciente::catalogo()`
     * @param  array<int,string>  $codigosDelPaciente  los `codeantecedente` que el paciente ya tiene
     * @param  array<int,array{codigo:string, nombre:string}>  $tipos  el catálogo de tipos (`tipo_antecedente`)
     * @param  string  $urlAgregar  a dónde postea el "Agregar" de cada fila (el código va en el cuerpo)
     * @param  string  $urlCrear  a dónde postea el alta del catálogo
     */
    public function __construct(
        private Collection $resultados,
        private array $codigosDelPaciente = [],
        private array $tipos = [],
        private string $urlAgregar = '',
        private string $urlCrear = '',
        private string $buscar = '',
        private string $tipo = '',
    ) {
    }

    /**
     * La URL del "Agregar" de una fila (O-07): un `POST` inmediato. El código y el filtro viajan en campos
     * ocultos del formulario de la fila, así que la URL no lleva parámetros.
     */
    public function urlAgregar(): string
    {
        return $this->urlAgregar;
    }

    public function urlCrear(): string
    {
        return $this->urlCrear;
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    /**
     * La URL del "Limpiar" del buscador: esta misma sección **con el tipo elegido** (A-06). Sin el `tipo` la
     * pantalla volvería al tipo por defecto, que no es lo que el médico estaba mirando.
     */
    public function urlLimpiar(): string
    {
        $query = ['seccion=' . ConsultaShellVista::SECCION_ANTECEDENTES];

        if ($this->tipo !== '') {
            $query[] = 'tipo=' . urlencode($this->tipo);
        }

        return $this->urlAgregar . '?' . implode('&', $query);
    }

    public function tipo(): string
    {
        return $this->tipo;
    }

    /**
     * Las filas del buscador, ya listas para pintar. `yaEsta` es lo único que decide si la fila se puede
     * agregar: desde O-07 no hay estado intermedio de «pendiente».
     *
     * @return array<int,array{codigo:string, descripcion:string, tipo:?string, yaEsta:bool}>
     */
    public function resultados(): array
    {
        return $this->resultados->map(fn ($antecedente) => [
            'codigo' => (string) $antecedente->codigo,
            'descripcion' => (string) $antecedente->nombre,
            'tipo' => $antecedente->tipoNombre,
            'yaEsta' => in_array((string) $antecedente->codigo, $this->codigosDelPaciente, true),
        ])->all();
    }

    /**
     * El catálogo de tipos, para el `<select>` del alta: los siete del escritorio, en el orden de sus
     * solapas (Per., Fam., H.P., Obst., Gine., A.S., Otros).
     *
     * @return array<int,array{codigo:string, nombre:string}>
     */
    public function tipos(): array
    {
        return $this->tipos;
    }

    /** El nombre del tipo elegido, para los textos vacíos y la ayuda del alta. `null` si no hay catálogo. */
    public function nombreDelTipo(): ?string
    {
        $tipo = $this->tipoElegido();

        return $tipo['nombre'] ?? null;
    }

    /**
     * El tipo que viene elegido en el alta del catálogo: el chip activo (A-06), para que lo que se cree caiga
     * en el tipo que se está mirando. Con un `old()` manda lo que el médico eligió en el POST que falló.
     */
    public function tipoDelAlta(): string
    {
        return (string) old('tipo', $this->tipo);
    }

    /** @return array{codigo:string, nombre:string}|null */
    private function tipoElegido(): ?array
    {
        foreach ($this->tipos as $tipo) {
            if ((string) $tipo['codigo'] === $this->tipo) {
                return $tipo;
            }
        }

        return null;
    }

    /** El tope del detalle de una fila, para el `maxlength` del control (la columna es un `text`). */
    public function limiteDetalle(): int
    {
        return GuardarAntecedentes::LARGO_DETALLE;
    }

    /** El tope del nombre de un antecedente nuevo (`antecedentes.descripcion` es `varchar(40)`). */
    public function limiteDescripcion(): int
    {
        return AgregarAntecedenteAlCatalogo::LARGO_MAXIMO;
    }

    /** La ayuda del buscador: qué hace y de dónde salen los antecedentes. */
    public function ayuda(): string
    {
        return 'Busca en el catálogo de tu consultorio: es el mismo que usa el escritorio, así que lo que '
            . 'agregues acá lo verás allá. Un antecedente por nombre y sin distinguir tildes.';
    }

    /** La ayuda del alta del catálogo: el código lo da el servidor y la descripción se guarda en mayúsculas. */
    public function ayudaDelAlta(): string
    {
        return 'Si el antecedente no está en tu catálogo, créalo: el código lo asigna el sistema (el '
            . 'correlativo de cuatro dígitos del escritorio) y se guarda en mayúsculas. Necesita conexión.';
    }

    /**
     * El estado vacío del buscador. Distingue lo que **no tiene el catálogo de ese tipo** (A-06: sin «todos»,
     * el catálogo de la columna es el del chip elegido) de lo que no coincide con la búsqueda.
     */
    public function sinResultados(): array
    {
        if ($this->buscar !== '') {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Tu catálogo no tiene antecedentes que coincidan',
                'texto' => 'Prueba con otra palabra o créalo abajo: se agrega a tu catálogo y al paciente.',
            ];
        }

        $delTipo = $this->nombreDelTipo();

        if ($delTipo === null) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Tu catálogo no tiene antecedentes que coincidan',
                'texto' => 'Tu catálogo está vacío: crea el primero abajo.',
            ];
        }

        return [
            'icono' => 'bi-search',
            'titulo' => 'Tu catálogo no tiene antecedentes de ' . $delTipo,
            'texto' => 'Crea el primero abajo —queda en este tipo— o elige otro de los tipos de arriba.',
        ];
    }
}
