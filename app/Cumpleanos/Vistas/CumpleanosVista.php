<?php

namespace App\Cumpleanos\Vistas;

use App\Clinica\FechaDelConsultorio;
use App\Cumpleanos\ConfiguracionDeCumpleanos;
use App\Cumpleanos\Cumpleano;
use App\Cumpleanos\MensajeDeCumpleanos;
use App\Notificaciones\Canal;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * La pantalla **"Cumpleaños"** (`/clinica/cumpleanos`, WEB-2.9): los pacientes del médico del contexto
 * que cumplen años el día que se está mirando, con el envío manual por fila y la configuración del
 * envío automático (interruptor, canal y hora).
 *
 * Es **provisional hasta WEB-8.2**, que es la pantalla de configuración de notificaciones: el día que
 * exista, esta configuración se muda allá y acá queda sólo el listado del día. Por eso la pantalla
 * **no** es un módulo del menú: es una herramienta que se alcanza desde la agenda y desde el inicio.
 *
 * Todo lo que la vista muestra sale de acá: los textos ya formateados, los estados con su tono, el
 * mensaje de cada fila y las URLs. **El ViewModel no consulta la base**: recibe la lista de cumpleaños,
 * la configuración y el registro de lo ya enviado, que los leyó el controlador. Lo único que trae es
 * `MensajeDeCumpleanos`, que es una regla sin estado (armar el texto con las etiquetas sustituidas),
 * igual que `RecordatoriosDeAgenda`.
 */
final class CumpleanosVista
{
    /**
     * @param  Collection<int,Cumpleano>  $cumpleanos  los del médico del contexto que cumplen hoy
     * @param  array<int,string>  $enviadosEl  `paciente_id` => `created_at` del saludo ya mandado
     */
    public function __construct(
        private CarbonInterface $dia,
        private Collection $cumpleanos,
        private ConfiguracionDeCumpleanos $configuracion,
        private string $canalElegido,
        private string $plantilla,
        private string $doctor,
        private MensajeDeCumpleanos $mensajes,
        private array $enviadosEl = [],
        private ?string $urlEditarElMensaje = null,
    ) {
    }

    /**
     * El enlace a «Mensajes a pacientes» (WEB-8.4), donde se escribe el saludo con sus etiquetas, el contador de SMS y la vista previa.
     * `null` para quien no puede entrar (la secretaría): el campo de acá sigue editando la misma plantilla.
     */
    public function urlEditarElMensaje(): ?string
    {
        return $this->urlEditarElMensaje;
    }

    public function titulo(): string
    {
        return 'Cumpleaños';
    }

    /** El nombre del médico del contexto: el `{doctor}` de la plantilla y el de la vista previa. */
    public function doctor(): string
    {
        return $this->doctor;
    }

    public function subtitulo(): string
    {
        return ucfirst(FechaClinica::tituloDia($this->dia)) . ' · ' . $this->conteo()
            . '. Los pacientes de ' . ($this->doctor !== '' ? $this->doctor : 'este médico') . ' que cumplen años hoy.';
    }

    public function esHoy(): bool
    {
        return $this->dia->isSameDay(FechaDelConsultorio::hoy());
    }

    public function fechaParaElFormulario(): string
    {
        return $this->dia->toDateString();
    }

    // ----------------------------------------------------------------------------------------- Listado

    public function estaVacio(): bool
    {
        return $this->cumpleanos->isEmpty();
    }

    public function textoVacio(): string
    {
        return 'Hoy no cumple años ningún paciente.';
    }

    public function ayudaVacio(): string
    {
        return 'Cuando alguien del consultorio cumpla años, aparece acá el día de su cumpleaños.';
    }

    public function conteo(): string
    {
        $total = $this->cumpleanos->count();

        return $total . ($total === 1 ? ' paciente' : ' pacientes');
    }

    public function urlVolver(): string
    {
        return route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->dia->toDateString()]);
    }

    public function urlEnviar(): string
    {
        return route('clinica.cumpleanos.enviar');
    }

    // ------------------------------------------------------------------------------------ Configuración

    public function urlGuardarConfiguracion(): string
    {
        return route('clinica.cumpleanos.configuracion');
    }

    public function envioActivo(): bool
    {
        return $this->configuracion->activo();
    }

    /** La hora configurada, `HH:MM` (con la de por defecto aplicada si no había ninguna). */
    public function hora(): string
    {
        return $this->configuracion->hora();
    }

    /** Si el médico ya guardó la configuración alguna vez, o lo que se ve son los valores por defecto. */
    public function configurado(): bool
    {
        return $this->configuracion->evolucion() !== null;
    }

    /** @return array<int,array{valor:string, texto:string, elegido:bool, porDefecto:bool}> */
    public function opcionesDeCanal(): array
    {
        $opciones = [];

        foreach (Canal::etiquetas() as $clave => $etiqueta) {
            $opciones[] = [
                'valor'      => $clave,
                'texto'      => $etiqueta,
                'elegido'    => $this->canalElegido === $clave,
                'porDefecto' => $clave === ConfiguracionDeCumpleanos::CANAL_POR_DEFECTO,
            ];
        }

        return $opciones;
    }

    /** La ayuda de cada canal: el costo del SMS y la limitación de WhatsApp están a la vista al elegir. */
    public function ayudaDelCanal(string $canal): string
    {
        return match ($canal) {
            Canal::SMS      => 'Se cobra por mensaje y sale por el servidor: es el más caro.',
            Canal::WHATSAPP => 'Fuera de la ventana de 24 h Meta solo deja mandar plantillas aprobadas, así que el texto del médico no viaja tal cual.',
            default         => 'Sin costo por mensaje, y lleva el texto del médico tal cual.',
        };
    }

    /** El texto que se manda: la plantilla del médico, o la de por defecto si nunca escribió ninguna. */
    public function plantilla(): string
    {
        return $this->plantilla;
    }

    /** La vista previa: el saludo del primer cumpleañero de hoy (o un ejemplo si hoy no cumple nadie). */
    public function vistaPrevia(): string
    {
        return $this->mensajes->ejemplo($this->plantilla, $this->cumpleanos->first(), $this->doctor);
    }

    /**
     * El nombre con el que la vista previa saluda: el del primer cumpleañero de hoy, o `''` si hoy no
     * cumple nadie (la vista previa usa entonces un nombre de ejemplo). Lo necesita su JS, que rearma la
     * vista previa mientras el médico escribe y tiene que sustituir `{paciente}` con el mismo nombre que
     * pondría el servidor.
     */
    public function primerCumpleanero(): string
    {
        return $this->cumpleanos->first()?->nombreParaElSaludo() ?? '';
    }

    /** El mensaje de un paciente: la plantilla con SUS datos, que es lo que el diálogo pone. */
    public function mensajeDe(Cumpleano $cumpleano): string
    {
        return $this->mensajes->armar($this->plantilla, [
            'paciente' => $cumpleano->nombreParaElSaludo(),
            'doctor'   => $this->doctor,
        ]);
    }

    // -------------------------------------------------------------------------------------------- Filas

    /**
     * Una fila por paciente que cumple años hoy, con lo que la tabla muestra ya resuelto.
     *
     * `puedeEnviar` mira el **canal configurado**: al que le falta el dato de ese canal se lo saltea —el
     * botón queda deshabilitado y la fila dice qué falta—, que es la decisión del 2026-10-10. La
     * columna de estado dice si ya se le mandó (lo hizo el comando o alguien a mano) para que nadie
     * mande el saludo dos veces sin darse cuenta.
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(): array
    {
        return $this->cumpleanos->map(function (Cumpleano $cumpleano) {
            $edad = $cumpleano->edad();
            $enviadoEl = $this->enviadosEl[$cumpleano->pacienteId()] ?? null;
            $falta = $cumpleano->datoQueFalta($this->canalElegido);

            return [
                'pacienteId'    => $cumpleano->pacienteId(),
                'paciente'      => $cumpleano->nombre(),
                'edad'          => $edad === null ? null : $edad . ' años',
                // Solo el paciente que cumple el 29/02 en un año no bisiesto lleva la marca.
                'adelantado'    => $cumpleano->esAdelantado(),
                'telefono'      => $cumpleano->telefono(),
                'correo'        => $cumpleano->correo(),
                'tieneTelefono' => $cumpleano->tieneTelefono(),
                'tieneCorreo'   => $cumpleano->tieneCorreo(),
                'puedeEnviar'   => $cumpleano->puedeRecibirPor($this->canalElegido),
                'falta'         => $falta,
                'mensaje'       => $this->mensajeDe($cumpleano),
                'estado'        => $enviadoEl === null
                    ? ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin enviar']
                    : ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Enviado ' . $this->horaDe($enviadoEl)],
                'enviado'       => $enviadoEl !== null,
            ];
        })->all();
    }

    /**
     * La hora del envío en la zona del consultorio. `created_at` se guarda en UTC (`config/app.php`), así
     * que se convierte: mostrar la hora UTC en una pantalla de Caracas diría cuatro horas de más.
     */
    private function horaDe(string $momento): string
    {
        return Carbon::parse($momento)->setTimezone(FechaDelConsultorio::zona())->format('H:i');
    }
}
