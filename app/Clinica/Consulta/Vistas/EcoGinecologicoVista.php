<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Impresion\DocumentosParaImprimir;

/**
 * **El eco pélvico de una consulta** (WEB-6.1, ítem 5 del panel del legado): el formulario con sus nueve
 * bloques, con guardado explícito.
 *
 * El ViewModel no sabe de base de datos ni de `Request`: recibe el estudio ya leído
 * (`App\Clinica\Consulta\EstudioEcografico`) y el `old()` de un POST que falló. El **mapa de campos** —qué
 * columna, con qué rótulo, con qué códigos— no vive acá sino en `EcoGinecologico`, que es el mapa del
 * dato, y lo comparten la pantalla, la Action y el informe.
 *
 * Tres cosas que decide esta clase y no la vista:
 *
 *  - **La FUR que se propone.** El escritorio, al entrar a la pestaña *Ginecologico*, busca la FUR del
 *    **control prenatal** de esa historia y la pone en el campo si el eco no tenía ninguna
 *    (`w_consulta_principal.srw:21391-21405`: `SELECT "pre_natal_observaciones"."fur" … WHERE historia =
 *    :historia`). La web hace lo mismo: si el estudio no tiene FUR y el control prenatal sí, el campo
 *    llega con ese valor y se dice de dónde salió. Es lo que evita que el médico la vuelva a teclear.
 *  - **Los códigos desconocidos del escritorio.** Cada desplegable ofrece los códigos del legado **más el
 *    valor guardado** si no está en la lista (igual que el `codeparentesco` de WEB-3.4): los datos reales
 *    pueden traer cualquier código y no se inventa una traducción ni se cambia el valor sin querer.
 *  - **El modo texto libre del escritorio** (`puro_texto`): se **muestra** si está escrito y no se edita
 *    (la web todavía no implementa ese modo; ver `EcoGinecologico::columnasQueNoSeTocan()`).
 */
final class EcoGinecologicoVista
{
    /**
     * @param  string  $urlGuardar  a dónde postea "Guardar el estudio"
     * @param  string  $urlVolver  la vuelta a la sección (el "Cancelar" de la guía de UI)
     * @param  string  $urlImprimir  la hoja del informe (en blanco no: el estudio tiene que existir)
     * @param  string|null  $furSugerida  la FUR del control prenatal de la historia, si la hay
     * @param  array<string,mixed>  $old  el `old()` del formulario (`[]` en la primera carga)
     */
    public function __construct(
        private EstudioEcografico $estudio,
        private string $urlGuardar = '',
        private string $urlVolver = '',
        private string $urlImprimir = '',
        private ?string $furSugerida = null,
        private array $old = [],
        // La fecha con la que abre el estudio si todavía no tiene una guardada (O-13): la pasa el controlador
        // —hoy del consultorio— para que el ViewModel no consulte el reloj ni la configuración.
        private ?string $fechaPorDefecto = null,
    ) {
    }

    /** El nombre del estudio, para el encabezado de la tarjeta. */
    public function titulo(): string
    {
        return EcoGinecologico::TITULO;
    }

    /**
     * Los bloques del formulario, en el orden de la pantalla, con el valor de cada campo ya resuelto.
     *
     * @return array<int,array<string,mixed>>
     */
    public function grupos(): array
    {
        $grupos = [];

        foreach (EcoGinecologico::grupos() as $grupo) {
            $campos = [];
            foreach ($grupo['campos'] as $campo) {
                $campos[] = $this->campo($campo);
            }

            $grupos[] = [
                'titulo' => $grupo['titulo'],
                'icono' => $grupo['icono'],
                'ayuda' => $grupo['ayuda'],
                'campos' => $campos,
            ];
        }

        return $grupos;
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    /** La clave del documento que imprime el botón (la de su hoja): la manda el botón para guardar e imprimir (O-16). */
    public function documento(): string
    {
        return DocumentosParaImprimir::claveDeLaUrl($this->urlImprimir);
    }

    /** La fecha del estudio (`AAAA-MM-DD`) que se está mostrando: la del formulario o la guardada. */
    public function fecha(): string
    {
        $fecha = $this->valor(EcoGinecologico::COLUMNA_FECHA);

        // O-13: sin fecha guardada ni escrita, la del estudio es **hoy** (la misma que abre el campo).
        return $fecha !== '' ? $fecha : (string) $this->fechaPorDefecto;
    }

    /**
     * El estado del estudio como pastilla: "Sin cargar" mientras no haya ningún dato y "Cargado" cuando ya
     * hay algo. La fecha sola no cuenta, pero el informe en texto libre del escritorio **sí**: es la misma
     * regla que `QueEsUnDocumento::ecografia`, la que decide qué se ofrece imprimir.
     */
    public function estado(): array
    {
        return ! $this->esUnDocumento()
            ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay nada cargado en el estudio de esta consulta']
            : ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Cargado',
                'titulo' => 'El estudio de esta consulta ya tiene datos'];
    }

    /**
     * Cuántos de los campos que la web edita tienen dato (la fecha incluida). Es lo que la pastilla del
     * encabezado cuenta ("12 de 35 campos con dato") y mira lo **guardado**, no el formulario: el aviso de
     * cambios sin guardar es otra cosa.
     */
    public function cargados(): int
    {
        $total = 0;
        foreach (array_keys(EcoGinecologico::columnas()) as $columna) {
            if ($this->estudio->valor($columna) !== null) {
                $total++;
            }
        }

        return $total;
    }

    /** Cuántos campos edita la web: el denominador de la pastilla del encabezado. */
    public function totalCampos(): int
    {
        return count(EcoGinecologico::columnas());
    }

    /**
     * El informe en texto libre que dejó el escritorio (`puro_texto`), o `null`. La web lo muestra y **no**
     * lo edita: el modo "puro texto" del escritorio (`mle_1`, `w_cargar_visualizar_ecografia.srw:268-277`)
     * llega en una tarea siguiente.
     */
    public function puroTexto(): ?string
    {
        return $this->estudio->valor(EcoGinecologico::COLUMNA_PURO_TEXTO);
    }

    /** ¿El escritorio dejó un informe en texto libre? */
    public function hayPuroTexto(): bool
    {
        return $this->puroTexto() !== null;
    }

    /** La ayuda del formulario: qué es la sección y qué pasa al salir sin guardar. */
    public function ayuda(): string
    {
        return 'El informe del eco pélvico: los datos del estudio, el útero, los ovarios y las '
            . 'conclusiones. Se guarda con el botón: si sales sin guardar, el navegador te avisa antes de '
            . 'perder lo escrito.';
    }

    /** El aviso que `consulta.js` muestra y usa para el `beforeunload`. */
    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el eco pélvico. Si sales ahora, se pierden.';
    }

    /**
     * Un campo listo para pintar: rótulo, columna (el `name` del control), valor y —si es un desplegable—
     * sus opciones.
     *
     * @param  array<string,mixed>  $campo
     * @return array<string,mixed>
     */
    private function campo(array $campo): array
    {
        $valor = $this->valor($campo['columna']);

        // O-13: **la fecha del estudio abre con hoy** (la del consultorio, no la del servidor) cuando el
        // estudio todavía no tiene fecha guardada. Es lo que hace el escritorio al crear la fila
        // (`w_cargar_visualizar_ecografia.srw:223`, `fecha = today()`), y lo que la ayuda del campo prometía
        // mientras el formulario abría el campo vacío. Una fecha ya guardada —o la que volvió en un POST que
        // falló— manda: el default es sólo para el campo vacío.
        if (($campo['tipo'] ?? 'texto') === 'fecha' && $valor === ''
            && ! array_key_exists($campo['columna'], $this->old)) {
            $valor = (string) $this->fechaPorDefecto;
        }

        return [
            'columna' => $campo['columna'],
            'etiqueta' => $campo['etiqueta'],
            'unidad' => $campo['unidad'],
            'tipo' => $campo['tipo'],
            'ayuda' => $this->ayudaDelCampo($campo),
            'valor' => $valor,
            'opciones' => $campo['tipo'] === 'opciones' ? $this->opciones($campo, $valor) : [],
        ];
    }

    /**
     * Las opciones del desplegable: una vacía para poder dejarlo en blanco, las del DataWindow, y **el
     * valor guardado si no está entre ellas** (los datos del escritorio pueden traer cualquier código).
     *
     * La regla es la misma en los tres estudios del ítem 5 y vive en `OpcionesDelDesplegable`.
     *
     * @param  array<string,mixed>  $campo
     * @return array<int,array{valor:string,texto:string}>
     */
    private function opciones(array $campo, string $valor): array
    {
        return OpcionesDelDesplegable::de($campo, $valor);
    }

    /** La ayuda del campo, con el agregado de la FUR cuando el valor lo puso el control prenatal. */
    private function ayudaDelCampo(array $campo): string
    {
        if ($campo['columna'] === EcoGinecologico::COLUMNA_FUR
            && $this->furSugerida !== null
            && $this->estudio->valor(EcoGinecologico::COLUMNA_FUR) === null) {
            return $campo['ayuda'] . ' La trajimos del control prenatal de esta historia.';
        }

        return $campo['ayuda'];
    }

    /**
     * Lo escrito en el POST que falló, si no lo guardado, si no —sólo para la FUR— lo que trae el control
     * prenatal de la historia.
     */
    private function valor(string $columna): string
    {
        if (array_key_exists($columna, $this->old) && $this->old[$columna] !== null) {
            return (string) $this->old[$columna];
        }

        $guardado = $this->estudio->valor($columna);

        if ($guardado !== null) {
            return $guardado;
        }

        return $columna === EcoGinecologico::COLUMNA_FUR ? (string) ($this->furSugerida ?? '') : '';
    }

    /**
     * ¿El estudio es un documento? Es la regla de `QueEsUnDocumento::ecografia`, con el informe en texto
     * libre del escritorio incluido: la usan la pastilla de estado y el botón de imprimir, para que la
     * pantalla y la impresión no digan cosas distintas.
     */
    public function esUnDocumento(): bool
    {
        return QueEsUnDocumento::ecografia($this->camposConDato(), $this->hayPuroTexto());
    }

    /**
     * Cuántos campos traen dato **sin contar la fecha**: es la cuenta que decide si hay algo que guardar.
     * La comparten la Action (`GuardarEcoGinecologico::conDatos`) y `QueEsUnDocumento::ecografia`, así que
     * las tres miran lo mismo.
     */
    private function camposConDato(): int
    {
        $conDato = 0;
        foreach (array_keys(EcoGinecologico::columnas()) as $columna) {
            if ($columna !== EcoGinecologico::COLUMNA_FECHA && $this->estudio->valor($columna) !== null) {
                $conDato++;
            }
        }

        return $conDato;
    }
}
