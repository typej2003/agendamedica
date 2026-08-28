<div class="container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800 fw-bold">
            <i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard del Administrador (Root)
        </h1>
    </div>

    <!-- Indicadores / Cards Estadísticas -->
    <div class="row g-3">
        <!-- Usuarios Registrados -->
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card border-0 shadow-sm rounded-3 bg-primary text-white h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Usuarios Registrados</span>
                            <h2 class="display-6 fw-bold mb-0">{{ number_format($totalUsuarios) }}</h2>
                        </div>
                        <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                            <i class="bi bi-people-fill fs-3 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                    <small class="text-white-50">Total de cuentas en la plataforma</small>
                    <i class="bi bi-arrow-right-short text-white fs-5"></i>
                </div>
            </div>
        </div>

        <!-- Pacientes Registrados -->
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card border-0 shadow-sm rounded-3 bg-success text-white h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Pacientes</span>
                            <h2 class="display-6 fw-bold mb-0">{{ number_format($totalPacientes) }}</h2>
                        </div>
                        <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                            <i class="bi bi-person-heart fs-3 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                    <small class="text-white-50">Pacientes registrados en el sistema</small>
                    <i class="bi bi-arrow-right-short text-white fs-5"></i>
                </div>
            </div>
        </div>

        <!-- Médicos Registrados -->
        <div class="col-12 col-sm-6 col-xl-4">
            <div class="card border-0 shadow-sm rounded-3 bg-info text-white h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Médicos</span>
                            <h2 class="display-6 fw-bold mb-0">{{ number_format($totalMedicos) }}</h2>
                        </div>
                        <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                            <i class="bi bi-hospital fs-3 text-white"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                    <small class="text-white-50">Personal médico registrado</small>
                    <i class="bi bi-arrow-right-short text-white fs-5"></i>
                </div>
            </div>
        </div>
    </div>
</div>