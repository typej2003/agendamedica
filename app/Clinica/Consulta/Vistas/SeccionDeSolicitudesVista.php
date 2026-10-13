<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **La sección Solicitudes de la consulta** (WEB-5.3a, ítem 11 del panel del legado): el contenedor con sus pestañas y la
 * pestaña que se está mirando.
 *
 * Es deliberadamente delgado: cada pestaña tiene su propio ViewModel (`ConstanciaVista`, `ReposoVista`,
 * `ReferenciasVista`, `InformeVista`, `HospitalizacionVista`, `DietaVista`, y `OrdenVista` para laboratorio y estudios) y su parcial `_solicitudes-<clave>`. Agregar una pestaña es
 * sumar su fila a `PestanasDeSolicitudes`, su ViewModel y su parcial, **sin tocar** ni este contenedor ni el controlador de
 * la consulta más que para armarle el ViewModel. Sin base de datos.
 */
final class SeccionDeSolicitudesVista
{
    public function __construct(
        private string $activa,
        private PestanasDeSolicitudes $pestanas,
        private object $pestana,
    ) {
    }

    /** La clave de la pestaña activa (`laboratorio`, `estudios`, `constancia`, `reposo`, `referencias`, `hospitalizacion`, `informe`, `dieta`). */
    public function activa(): string
    {
        return $this->activa;
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function pestanas(): array
    {
        return $this->pestanas->opciones($this->activa);
    }

    /** El parcial de la pestaña activa. */
    public function parcial(): string
    {
        return 'clinica.consulta._solicitudes-' . $this->activa;
    }

    /** El ViewModel de la pestaña activa. */
    public function pestana(): object
    {
        return $this->pestana;
    }

    public function ayuda(): string
    {
        return 'Los documentos de esta consulta que se entregan al paciente: la orden de laboratorio, la solicitud de estudios, '
            . 'la constancia de asistencia, el reposo, la referencia a otro médico, la orden de hospitalización, el informe y la dieta o '
            . 'recomendaciones. Se guardan con su botón y se imprimen en media hoja, con el membrete de tu consultorio.';
    }
}
