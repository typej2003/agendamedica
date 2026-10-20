<?php

namespace App\Clinica\Impresion\Vistas;

use App\Clinica\Impresion\DistribuidorDeDocumento;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\ModoDeLaHoja;
use App\Support\FechaClinica;

/**
 * **Las hojas de un documento de la consulta** (WEB-5.3a): constancia, reposo, referencia o informe tal como los imprime el
 * navegador, ya repartidos en mitades y con todos los textos formateados.
 *
 * Una hoja es una página A4 horizontal con dos mitades. Un documento es **media hoja**: va en la mitad izquierda y la derecha
 * queda en blanco. Si el texto no entra, **sigue en la otra mitad** (`DistribuidorDeDocumento`): la primera lleva «Continúa →»
 * y la segunda es la continuación, con la firma. Si ni así entra, sigue en una hoja más.
 *
 * **Los dos modos de hoja (O-26):** el documento declara el suyo (`DocumentoImpreso::modo()`, que lo lee de `ModoDeLaHoja`) y
 * esta vista lo respeta: en **hoja completa vertical** el documento ocupa una página entera, el reparto se mide con las medidas
 * de la hoja completa y cada trozo va en su propia página (`ModoDeLaHoja::porPagina()`). La cuenta del reparto es la misma
 * clase; lo único que cambia son las medidas con las que mide. Es el caso del **informe del control de embarazo**, de los
 * **formatos en blanco** de los ecos y, desde el 2026-10-18, de la **colposcopía y la histeroscopía**, cuyo informe lleva la
 * imagen del examen **en la misma página** (debajo del texto: el reparto reserva su alto, ver `DistribuidorDeDocumento`).
 *
 * Como todo ViewModel de la web clínica **no consulta la base**: recibe los documentos y el membrete ya leídos. Se prueba sin
 * base de datos.
 */
final class HojasDeDocumentoVista
{
    /**
     * @param  array<int,DocumentoImpreso>  $documentos
     * @param  DistribuidorDeDocumento|null  $distribuidor  el reparto de la media hoja; `null` usa el de siempre (el que inyecta
     *                                                      el contenedor)
     * @param  bool  $pediatria  si el contexto de impresión es pediatría (lo decide el controlador con
     *                           `AlcanceDeLaEspecialidad`, no la edad): la cabecera lleva además sexo, representante, historia y
     *                           la edad compacta (2026-10-20)
     */
    public function __construct(
        private Membrete $membrete,
        private array $documentos,
        private ?DistribuidorDeDocumento $distribuidor = null,
        private bool $pediatria = false,
    ) {
        $this->distribuidor ??= new DistribuidorDeDocumento();
    }

    /**
     * Las hojas, en el orden en que salen de la impresora.
     *
     * Cada hoja trae su `modo` (`mitad` o `completa`, que decide el tamaño y la orientación de la página), el `tamano` de la
     * letra del cuerpo en puntos y sus `mitades`: una o dos, cada una con su título, los datos del paciente (en la primera) o
     * el encabezado corto (en la continuación), los datos fijos, los párrafos y si lleva la firma o el «Continúa →». La
     * última mitad lleva además sus `imagenes` (la colposcopía y la histeroscopía), que salen **en la misma página**.
     *
     * @return array<int,array{modo:string, tipo:string, etiqueta:?string, tamano:int, mitades:array<int,array<string,mixed>>}>
     */
    public function hojas(): array
    {
        $hojas = [];

        foreach ($this->documentos as $documento) {
            $modo = $documento->modo();
            // La media hoja horizontal usa el distribuidor de siempre; las dos hojas verticales (la completa y su mitad
            // superior) miden con las medidas de su modo.
            $distribuidor = $modo === ModoDeLaHoja::MITAD
                ? $this->distribuidor
                : new DistribuidorDeDocumento(ModoDeLaHoja::medidas($modo));

            $reparto = $distribuidor->distribuir($this->membrete, $documento);

            foreach (array_chunk($reparto['mitades'], ModoDeLaHoja::porPagina($modo)) as $par) {
                $hojas[] = [
                    'modo' => $modo,
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
     * @param  array{primera:bool, sigue:bool, firma:bool, fijos:array, parrafos:array, imagenes:array}  $mitad
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
            'edad' => $this->edad($persona['edad'] ?? null, $this->pediatria ? ($persona['edadCompacta'] ?? null) : null),
            // La cabecera de pediatría (2026-10-20): el sexo, el representante y la historia. Fuera de pediatría los tres
            // salen `null` y la vista no imprime sus rótulos.
            'sexo' => $this->pediatria ? ($persona['sexo'] ?? null) : null,
            'representante' => $this->pediatria ? ($persona['representante'] ?? null) : null,
            'historia' => $this->pediatria ? ($persona['historia'] ?? null) : null,
            'fecha' => FechaClinica::formato($documento->fecha),
            // El destinatario del resumen médico (WEB-5.3c, `informe.para`): sale en la cabecera, en una
            // caja con borde. En los demás documentos va en el cuerpo y esto es `null`.
            'para' => $mitad['primera'] ? $documento->para : null,
            'fijos' => $mitad['fijos'],
            'parrafos' => $mitad['parrafos'],
            // Las imágenes del informe, en la misma página y debajo del texto (la colposcopía y la histeroscopía): el
            // reparto se las da a la última mitad y le descuenta su alto (`DistribuidorDeDocumento`).
            'imagenes' => $mitad['imagenes'] ?? [],
            'sigue' => $mitad['sigue'],
            'firma' => $mitad['firma'],
        ];
    }

    /** La edad en años o, en pediatría, la compacta del escritorio («21A 10M 16D»); `''` si no se conoce. */
    private function edad(?int $edad, ?string $compacta = null): string
    {
        if ($compacta !== null) {
            return $compacta;
        }

        return $edad === null ? '' : $edad . ($edad === 1 ? ' año' : ' años');
    }
}
