<?php

namespace App\Clinica\Pacientes\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use Illuminate\Support\Collection;

/**
 * El bloque de **representante del paciente** de la ficha (`/clinica/pacientes/{id}`, WEB-3.4): quién
 * responde por el paciente —para pediatría y para los casos generales— con su parentesco y su contacto.
 *
 * El representante es **el que presta el teléfono para el recordatorio** (PLAN-WEB.md §6), así que el
 * bloque no es una fila más de la rejilla de datos: muestra el teléfono como el dato principal y permite
 * cargarlo o corregirlo sin salir de la ficha.
 *
 * **Es uno por paciente, no una lista.** En el legado `representante` tiene `PRIMARY KEY (numhistoria)`
 * (los siete `esquemas-asa/*.sql`): la pantalla muestra **el** representante de la historia, y se guarda
 * con `numhistoria` como clave. Por eso también **sin historia no hay formulario**: no habría con qué
 * guardarlo, y un formulario que va a fallar es peor que decir por qué. La ficha de un paciente sin
 * número muestra el bloque con el motivo y **el camino** —"Asignar el número de historia", WEB-3.3—, que
 * es la acción primaria de la pantalla en ese estado.
 *
 * El ViewModel no consulta nada (guia-arquitectura-web.md): recibe el representante ya leído y el
 * catálogo de parentescos ya resuelto; las URLs las pone el controlador, porque el ViewModel se prueba sin
 * contenedor de Laravel.
 */
final class RepresentanteDePacienteVista
{
    /** @var Collection<int,object> */
    private Collection $catalogoDeParentescos;

    /**
     * @param  Collection<int,object>|null  $catalogoParentescos  filas con `codigo` y `nombre`
     */
    public function __construct(
        private bool $tieneHistoria,
        private ?object $representante = null,
        private ?string $parentesco = null,
        ?Collection $catalogoParentescos = null,
        private ?string $urlGuardar = null,
    ) {
        $this->catalogoDeParentescos = $catalogoParentescos ?? new Collection();
    }

    /**
     * El mismo ViewModel con la **URL con la que se guarda**. En la pantalla la pasa el constructor —el
     * controlador la resuelve y no la pasa cuando el paciente no tiene historia—, pero se conserva para
     * poder probar el bloque con una URL fija sin levantar el contenedor de Laravel.
     */
    public function conUrlGuardar(string $url): self
    {
        return new self(
            $this->tieneHistoria,
            $this->representante,
            $this->parentesco,
            $this->catalogoDeParentescos,
            $url,
        );
    }

    /** ¿Hay un representante cargado? Es lo que decide entre el bloque con datos y el vacío. */
    public function cargado(): bool
    {
        return $this->representante !== null;
    }

    /**
     * ¿Se puede cargar o editar? Hacen falta las dos cosas: que el paciente tenga **número de historia**
     * (es la clave de la tabla y sin él no hay dónde guardar) y que el controlador haya pasado la URL.
     * Sin historia el bloque no ofrece el formulario: explica el motivo y el camino.
     */
    public function puedeEditar(): bool
    {
        return $this->tieneHistoria && $this->urlGuardar !== null;
    }

    public function urlGuardar(): ?string
    {
        return $this->urlGuardar;
    }

    /** El nombre del representante, o `null` si la fila del legado lo tiene vacío. */
    public function nombre(): ?string
    {
        return CamposDePaciente::texto($this->representante->nombre ?? null);
    }

    /**
     * El parentesco para mostrar: el **nombre** del catálogo, o el código tal cual si no está en él.
     *
     * Un código que no está se muestra sin traducir —igual que hace la ficha con un `codesegemp` que no
     * está en `seg_emp`— porque los códigos del legado son **desconocidos**: el catálogo es nuevo de
     * AppDDR y decir "Madre" por un `codeparentesco` heredado sería afirmar una equivalencia que nadie
     * verificó. El código crudo al menos deja ver el dato.
     */
    public function parentesco(): ?string
    {
        $codigo = $this->codigoParentesco();

        if ($codigo === null) {
            return null;
        }

        return $this->parentesco ?? $codigo;
    }

    /** El código guardado, limpio (`null` si la fila no lo trae). */
    public function codigoParentesco(): ?string
    {
        return CamposDePaciente::texto($this->representante->codeparentesco ?? null);
    }

    public function cedula(): ?string
    {
        return CamposDePaciente::cedula($this->representante->cedula ?? null);
    }

    public function telefono(): ?string
    {
        return CamposDePaciente::texto($this->representante->telefono ?? null);
    }

    public function correo(): ?string
    {
        return CamposDePaciente::texto($this->representante->email ?? null);
    }

    public function direccion(): ?string
    {
        return CamposDePaciente::texto($this->representante->direccion ?? null);
    }

    /**
     * El estado vacío del bloque: no hay representante cargado. Distingue los dos motivos, porque no son
     * lo mismo: sin historia no se puede guardar todavía (y hay que asignarla), y con historia es que
     * nadie lo cargó.
     *
     * @return array{titulo:string, texto:string}
     */
    public function vacio(): array
    {
        if (! $this->tieneHistoria) {
            return [
                'titulo' => 'Todavía no se puede cargar el representante',
                'texto'  => 'El representante se guarda con el número de historia del paciente, y este '
                    . 'todavía no lo tiene. Asígnale el número y después se carga acá.',
            ];
        }

        return [
            'titulo' => 'Sin representante cargado',
            'texto'  => 'Es el adulto que responde por el paciente y el que presta su teléfono para el '
                . 'recordatorio de la cita.',
        ];
    }

    /** El texto del botón que abre el formulario, según haya algo cargado o no. */
    public function botonEditar(): string
    {
        return $this->cargado() ? 'Editar el representante' : 'Cargar el representante';
    }

    /** El título del formulario: el mismo bloque sirve para el alta y para la edición. */
    public function tituloFormulario(): string
    {
        return $this->cargado() ? 'Editar el representante' : 'Cargar el representante';
    }

    /**
     * Las opciones del `<select>` de parentesco, con la elegida marcada. La primera es "— Sin dato —"
     * (`null`), que es un estado válido: el legado no siempre lo dice.
     *
     * @return array<int, array{valor:?string, texto:string, elegido:bool}>
     */
    public function parentescos(): array
    {
        $elegido = $this->valor('codeparentesco');

        $opciones = [[
            'valor'   => null,
            'texto'   => '— Sin dato —',
            'elegido' => $elegido === null || $elegido === '',
        ]];

        foreach ($this->catalogoDeParentescos as $parentesco) {
            $opciones[] = [
                'valor'   => (string) $parentesco->codigo,
                'texto'   => (string) $parentesco->nombre,
                'elegido' => (string) $parentesco->codigo === (string) $elegido,
            ];
        }

        // Un código que no está en el catálogo (una fila del escritorio con su propio código) se ofrece
        // igual: si no, al guardar cualquier otro dato se perdería sin que nadie lo haya pedido.
        if ($elegido !== null && ! $this->catalogoDeParentescos->contains('codigo', $elegido)) {
            $opciones[] = ['valor' => $elegido, 'texto' => $elegido . ' (código del escritorio)', 'elegido' => true];
        }

        return $opciones;
    }

    /**
     * El valor de un campo del formulario: lo que el usuario escribió (`old()`, para no perder nada cuando
     * la validación falla, guía de UI §3) o lo que tiene el representante. Mismo criterio que
     * `ConValoresDeFormulario`, escrito acá para no traerle el `editando` de las pantallas de ABM.
     *
     * El `old()` se pide **sólo si hay petición**: este ViewModel se prueba en `tests/Unit/Clinica/` sin
     * levantar Laravel, y ahí `old()` no tiene de dónde sacar la sesión (tira
     * `Target class [request] does not exist`). Sin petición no hay nada escrito que recuperar, así que el
     * dato del representante es la respuesta correcta.
     */
    public function valor(string $campo): ?string
    {
        $escrito = function_exists('app') && app()->bound('request') ? old($campo) : null;

        return CamposDePaciente::texto($escrito ?? $this->representante->{$campo} ?? null);
    }
}
