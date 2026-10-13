<?php

namespace App\Clinica\Impresion\Vistas;

use App\Clinica\Impresion\DistribuidorDeDocumento;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\Membrete;
use App\Support\FechaClinica;

/**
 * **Las hojas de un documento de la consulta** (WEB-5.3a): constancia, reposo, referencia o informe tal como los imprime el
 * navegador, ya repartidos en mitades y con todos los textos formateados.
 *
 * Una hoja es una página A4 horizontal con dos mitades. Un documento es **media hoja**: va en la mitad izquierda y la derecha
 * queda en blanco. Si el texto no entra, **sigue en la otra mitad** (`DistribuidorDeDocumento`): la primera lleva «Continúa →»
 * y la segunda es la continuación, con la firma. Si ni así entra, sigue en una hoja más.
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe los documentos y el membrete ya leídos. Se prueba sin
 * base de datos.
 */
final class HojasDeDocumentoVista
{
    /**
     * @param  array<int,DocumentoImpreso>  $documentos
     */
    public function __construct(
        private Membrete $membrete,
        private array $documentos,
        private DistribuidorDeDocumento $distribuidor = new DistribuidorDeDocumento(),
    ) {
    }

    /**
     * Las hojas, en el orden en que salen de la impresora.
     *
     * Cada hoja trae el `tamano` de la letra del cuerpo en puntos y sus `mitades`: una o dos, cada una con su título, los datos
     * del paciente (en la primera) o el encabezado corto (en la continuación), los datos fijos, los párrafos y si lleva la
     * firma o el «Continúa →».
     *
     * @return array<int,array{tipo:string, etiqueta:?string, tamano:int, mitades:array<int,array<string,mixed>>}>
     */
    public function hojas(): array
    {
        $hojas = [];

        foreach ($this->documentos as $documento) {
            $reparto = $this->distribuidor->distribuir($this->membrete, $documento);

            foreach (array_chunk($reparto['mitades'], 2) as $par) {
                $hojas[] = [
                    'tipo' => 'documento',
                    'etiqueta' => null,
                    'tamano' => $reparto['puntos'],
                    'mitades' => array_map(fn (array $mitad) => $this->mitad($documento, $mitad), $par),
                ];
            }
        }

        return $hojas;
    }

    /** Cuántos documentos hay. */
    public function total(): int
    {
        return count($this->documentos);
    }

    /**
     * @param  array{primera:bool, sigue:bool, firma:bool, fijos:array, parrafos:array}  $mitad
     * @return array<string,mixed>
     */
    private function mitad(DocumentoImpreso $documento, array $mitad): array
    {
        $persona = $documento->persona;

        return [
            'clase' => 'documento',
            'primera' => $mitad['primera'],
            'titulo' => $mitad['primera'] ? $documento->titulo : $documento->titulo . ' (continuación)',
            'paciente' => $persona['nombre'],
            'cedula' => $persona['cedula'] ?? '',
            'edad' => $this->edad($persona['edad'] ?? null),
            'fecha' => FechaClinica::formato($documento->fecha),
            'fijos' => $mitad['fijos'],
            'parrafos' => $mitad['parrafos'],
            'sigue' => $mitad['sigue'],
            'firma' => $mitad['firma'],
        ];
    }

    private function edad(?int $edad): string
    {
        return $edad === null ? '' : $edad . ($edad === 1 ? ' año' : ' años');
    }
}
