<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>GeI Web</title>

    @vite('resources/js/app.js')
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-dark navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">GeI Web</a>
        </div>
    </nav>

    <main class="container py-5">
        <div class="card shadow-sm">
            <div class="card-body p-5">
                <h1 class="display-5">GeI Web</h1>

                <p class="lead">
                    Laravel, Blade, Bootstrap y Docker están funcionando.
                </p>

                <button
                    type="button"
                    class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#modalPrueba"
                >
                    Probar Bootstrap
                </button>
            </div>
        </div>
    </main>

    <div
        class="modal fade"
        id="modalPrueba"
        tabindex="-1"
        aria-labelledby="modalPruebaLabel"
        aria-hidden="true"
    >
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="modalPruebaLabel">
                        Bootstrap funcionando
                    </h1>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Cerrar"
                    ></button>
                </div>

                <div class="modal-body">
                    El CSS y el JavaScript de Bootstrap fueron cargados mediante Vite.
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
