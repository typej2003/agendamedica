{{-- **«Ver otra historia»** (Fase 2 del cambio de `reg_medico`): un paciente del doctor puede tener historia en
     más de uno de sus consultorios —el personal y los centros donde trabaja— y cada una es **otra** historia,
     con su número. Esto es un selector de **sólo lectura**: la del contexto está marcada y las demás llevan a
     la hoja de ese consultorio (`?reg_medico=`). No se pregunta al abrir: sin otra historia con número no se
     pinta nada.

     Variables: `$historias`, el arreglo ya armado por el ViewModel (`otrasHistorias()`), con `nombre`,
     `numhistoria`, `actual` y `url`. --}}
@php
    // Sólo hay algo que ofrecer si el paciente tiene historia en **otro** consultorio con su número.
    $otras = collect($historias)->filter(fn ($historia) => ! $historia['actual'] && ! empty($historia['numhistoria']));
@endphp

@if ($otras->isNotEmpty())
    <x-ui.tarjeta id="otras-historias" titulo="Ver otra historia" icono="bi-journals" class="mb-3">
        <p class="texto-tenue small mb-2">
            Sus historias en los consultorios del doctor: cada una es otra historia, con su número.
        </p>
        <ul class="list-unstyled mb-0 d-grid gap-2">
            @foreach ($historias as $historia)
                <li class="d-flex flex-wrap gap-2 align-items-center">
                    @if ($historia['actual'] && $historia['numhistoria'])
                        <x-ui.estado tono="marca" icono="bi-journal-medical">Historia {{ $historia['numhistoria'] }}</x-ui.estado>
                        <span class="fw-semibold">{{ $historia['nombre'] }}</span>
                        <span class="texto-tenue small">· el consultorio que estás mirando</span>
                    @elseif ($historia['url'])
                        <a href="{{ $historia['url'] }}">
                            <i class="bi bi-journal-medical" aria-hidden="true"></i>
                            Ver la historia N.º {{ $historia['numhistoria'] }}
                        </a>
                        <span class="texto-tenue small">· {{ $historia['nombre'] }}</span>
                    @else
                        <span class="texto-tenue">
                            {{ $historia['nombre'] }} · sin historia en este consultorio
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-ui.tarjeta>
@endif
