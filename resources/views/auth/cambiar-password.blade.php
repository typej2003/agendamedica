<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cambiar contraseña</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container d-flex justify-content-center align-items-center py-5">
        <div class="card shadow-sm border-0 p-4" style="max-width: 440px; width: 100%;">
            <h4 class="fw-bold mb-1">Cambiar contraseña</h4>

            @if ($obligatorio)
                <div class="alert alert-warning small mt-3 mb-3">
                    Tu contraseña es temporal. Elige una nueva para continuar.
                </div>
            @else
                <p class="text-muted small mt-1 mb-3">Elige una contraseña nueva.</p>
            @endif

            <form method="POST" action="{{ route('cuenta.password.update') }}">
                @csrf

                <div class="mb-3">
                    <label for="password_actual" class="form-label small fw-bold">Contraseña actual</label>
                    <input type="password" name="password_actual" id="password_actual"
                           class="form-control @error('password_actual') is-invalid @enderror"
                           required autocomplete="current-password" autofocus>
                    @error('password_actual') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label for="password_nueva" class="form-label small fw-bold">Contraseña nueva</label>
                    <input type="password" name="password_nueva" id="password_nueva"
                           class="form-control @error('password_nueva') is-invalid @enderror"
                           required minlength="8" autocomplete="new-password">
                    <div class="form-text">Mínimo 8 caracteres.</div>
                    @error('password_nueva') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="mb-4">
                    <label for="password_nueva_confirmation" class="form-label small fw-bold">Repite la contraseña nueva</label>
                    <input type="password" name="password_nueva_confirmation" id="password_nueva_confirmation"
                           class="form-control" required minlength="8" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-100">Guardar contraseña</button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="text-center mt-3" onsubmit="return confirm('¿Cerrar sesión?');">
                @csrf
                <button type="submit" class="btn btn-link btn-sm text-muted">Cerrar sesión</button>
            </form>
        </div>
    </div>
</body>
</html>
