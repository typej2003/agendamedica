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
 * se recarga con el filtro y "Agregar" es un enlace que vuelve con ese antecedente **tildado como
 * pendiente**. Así la sección entera funciona sin JavaScript y el agregado no se guarda hasta que el médico
 * aprieta el botón de la tarjeta de arriba, que es la única acción que escribe.
 */
final class SeccionDeAntecedentesCatalogoVista
{
    /**
     * @param  Collection<int,object>  $resultados  lo que devolvió `AntecedentesDelPaciente::catalogo()`
     * @param  array<int,string>  $codigosDelPaciente  los `codeantecedente` que el paciente ya tiene
     * @param  array<int,array{codigo:string, nombre:string}>  $tipos  el catálogo de tipos (`tipo_antecedente`)
     * @param  string  $urlAgregar  la base del enlace "Agregar" de cada fila (recibe el código)
     * @param  string  $urlCrear  a dónde postea el alta del catálogo
     * @param  string  $urlGuardar  la base del `action` del formulario de guardado (los pendientes se le
     *                              agregan como query para que el `POST` no los pierda)
     * @param  array<string,mixed>  $consulta  el `?…` de la URL (para saber qué quedó tildado para agregar)
     */
    public function __construct(
        private Collection $resultados,
        private array $codigosDelPaciente = [],
        private array $tipos = [],
        private string $urlAgregar = '',
        private string $urlCrear = '',
        private string $buscar = '',
        private string $tipo = '',
        private array $consulta = [],
        private string $urlGuardar = '',
    ) {
    }

    /**
     * La URL del "Agregar" de una fila: vuelve a esta misma sección con **ese** código tildado además de
     * los que ya estaban. Se arma acá y no en la vista porque es una URL con parámetros (y la vista no
     * arma URLs en la web clínica).
     */
    public function urlAgregar(string $codigo): string
    {
        $query = ['seccion=' . ConsultaShellVista::SECCION_ANTECEDENTES];

        foreach (array_merge($this->pendientesDelBuscador(), [$codigo]) as $pendiente) {
            $query[] = 'agregar[]=' . urlencode($pendiente);
        }

        return $this->urlAgregar . '?' . implode('&', $query);
    }

    public function urlCrear(): string
    {
        return $this->urlCrear;
    }

    /**
     * La URL del botón de guardar de la tarjeta de antecedentes, **con la sección y los pendientes del
     * buscador**.
     *
     * Existe por una razón concreta: los `hidden` de `agregar[…]` que el buscador dejó tildados no
     * sobreviven a una recarga de la página, pero la query del `action` sí, así que el `POST` del guardado
     * no pierde lo tildado (el controlador junta el `POST` y la query). El `seccion` va porque la vuelta
     * del guardado tiene que caer en esta misma sección del shell.
     */
    public function urlGuardar(): string
    {
        $query = [];
        foreach ($this->pendientesDelBuscador() as $codigo) {
            $query[] = 'agregar[]=' . urlencode($codigo);
        }
        $query[] = 'seccion=' . ConsultaShellVista::SECCION_ANTECEDENTES;

        $url = $this->urlGuardar;

        return $url . (str_contains($url, '?') ? '&' : '?') . implode('&', $query);
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function tipo(): string
    {
        return $this->tipo;
    }

    /**
     * Los códigos que el buscador dejó tildados para agregar (`?agregar=…`, y también `?agregar[]=…`): la
     * tarjeta de arriba los manda como pendientes y el médico los confirma con el botón de guardar.
     *
     * @return array<int,string>
     */
    public function pendientesDelBuscador(): array
    {
        $codigos = [];

        foreach ((array) ($this->consulta['agregar'] ?? []) as $codigo) {
            $codigo = trim((string) $codigo);

            if ($codigo !== '') {
                $codigos[$codigo] = true;
            }
        }

        return array_keys($codigos);
    }

    /** El aviso de la tarjeta cuando hay algo tildado: qué va a pasar y dónde se confirma. */
    public function avisoDePendientes(): string
    {
        $pendientes = $this->pendientesDelBuscador();

        return count($pendientes) === 1
            ? 'Tildaste un antecedente: se agrega al paciente cuando aprietes «Guardar los antecedentes», arriba.'
            : 'Tildaste ' . count($pendientes) . ' antecedentes: se agregan al paciente cuando aprietes «Guardar los antecedentes», arriba.';
    }

    /**
     * Las filas del buscador, ya listas para pintar.
     *
     * @return array<int,array{codigo:string, descripcion:string, tipo:?string, yaEsta:bool, pendiente:bool}>
     */
    public function resultados(): array
    {
        $pendientes = $this->pendientesDelBuscador();

        return $this->resultados->map(fn ($antecedente) => [
            'codigo' => (string) $antecedente->codigo,
            'descripcion' => (string) $antecedente->nombre,
            'tipo' => $antecedente->tipoNombre,
            'yaEsta' => in_array((string) $antecedente->codigo, $this->codigosDelPaciente, true),
            'pendiente' => in_array((string) $antecedente->codigo, $pendientes, true),
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

    /** El estado vacío del buscador, con el nombre que falta en el catálogo. */
    public function sinResultados(): array
    {
        return [
            'icono' => 'bi-search',
            'titulo' => 'Tu catálogo no tiene antecedentes que coincidan',
            'texto' => $this->buscar === ''
                ? 'Tu catálogo está vacío: crea el primero abajo.'
                : 'Prueba con otra palabra o créalo abajo: se agrega a tu catálogo y al paciente.',
        ];
    }
}
