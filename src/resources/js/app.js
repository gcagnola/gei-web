import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

document.addEventListener('DOMContentLoaded', () => {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('clave');

    if (!togglePassword || !passwordInput) {
        return;
    }

    togglePassword.addEventListener('click', () => {
        const passwordIsVisible = passwordInput.type === 'text';

        passwordInput.type = passwordIsVisible
            ? 'password'
            : 'text';

        togglePassword.classList.toggle(
            'is-visible',
            !passwordIsVisible
        );

        togglePassword.setAttribute(
            'aria-pressed',
            String(!passwordIsVisible)
        );

        togglePassword.setAttribute(
            'aria-label',
            passwordIsVisible
                ? 'Mostrar contraseña'
                : 'Ocultar contraseña'
        );

        passwordInput.focus();
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-client-form]');

    if (!form) {
        return;
    }

    const personeria = form.querySelector('#personeria');
    const camposFisicos = form.querySelectorAll('[data-persona-fisica], [data-documento-personal]');
    const camposJuridicos = form.querySelectorAll('[data-persona-juridica]');
    const cuitRequired = form.querySelector('[data-cuit-required]');
    const provincia = form.querySelector('#provincia');
    const localidad = form.querySelector('#localidad');
    const caractel = form.querySelector('#caractel');
    const cp = form.querySelector('#cp');

    const actualizarPersoneria = () => {
        const esFisica = personeria?.value === 'Física';

        camposFisicos.forEach((campo) => {
            campo.hidden = !esFisica;
        });

        camposJuridicos.forEach((campo) => {
            campo.hidden = esFisica;
        });

        if (cuitRequired) {
            cuitRequired.hidden = esFisica;
        }
    };

    const cargarLocalidades = async (preservarSeleccion = true) => {
        if (!provincia || !localidad) {
            return;
        }

        const seleccion = preservarSeleccion
            ? localidad.dataset.selected || localidad.value
            : '';
        const url = new URL(provincia.dataset.localidadesUrl, window.location.origin);
        url.searchParams.set('provincia', provincia.value);

        localidad.disabled = true;

        try {
            const respuesta = await fetch(url, {
                headers: { Accept: 'application/json' },
            });
            const opciones = await respuesta.json();

            localidad.replaceChildren();

            opciones.forEach((opcion) => {
                const elemento = new Option(
                    opcion.nombre,
                    opcion.nombre,
                    false,
                    opcion.nombre === seleccion
                );
                elemento.dataset.caractel = opcion.caractel || '';
                elemento.dataset.cp = opcion.cp || '';
                localidad.add(elemento);
            });

            if (!localidad.value && localidad.options.length > 0) {
                localidad.selectedIndex = 0;
            }
        } finally {
            localidad.disabled = false;
        }
    };

    const completarUbicacion = () => {
        const opcion = localidad?.selectedOptions[0];

        if (!opcion) {
            return;
        }

        if (caractel) {
            caractel.value = opcion.dataset.caractel || '';
        }

        if (cp) {
            cp.value = opcion.dataset.cp || '';
        }
    };

    personeria?.addEventListener('change', actualizarPersoneria);
    provincia?.addEventListener('change', async () => {
        await cargarLocalidades(false);
        completarUbicacion();
    });
    localidad?.addEventListener('change', completarUbicacion);

    actualizarPersoneria();
    cargarLocalidades();
});

document.addEventListener('DOMContentLoaded', () => {
    const selectedClient = document.querySelector('[data-selected-client]');

    if (!selectedClient) {
        return;
    }

    selectedClient.scrollIntoView({
        block: 'center',
        inline: 'nearest',
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-import-form]');
    const modalElement = document.getElementById('importProgressModal');

    if (!form || !modalElement) {
        return;
    }

    const input = form.querySelector('input[type="file"]');
    const submit = form.querySelector('[data-import-submit]');
    const count = modalElement.querySelector('[data-import-progress-count]');
    const fileText = modalElement.querySelector('[data-import-progress-file]');
    const detail = modalElement.querySelector('[data-import-progress-detail]');
    const bar = modalElement.querySelector('[data-import-progress-bar]');
    const modal = new bootstrap.Modal(modalElement);

    const setProgress = (percent, message = null) => {
        const cleanPercent = Math.max(0, Math.min(100, Math.round(percent)));

        if (bar) {
            bar.style.width = `${cleanPercent}%`;
            bar.textContent = `${cleanPercent}%`;
        }

        if (detail && message) {
            detail.textContent = message;
        }
    };

    const currentFileName = (files, loaded, totalSize) => {
        if (!files.length || totalSize <= 0) {
            return '';
        }

        const loadedFiles = Math.min(loaded, totalSize);
        let accumulated = 0;

        for (const file of files) {
            accumulated += file.size;

            if (loadedFiles <= accumulated) {
                return file.name;
            }
        }

        return files[files.length - 1].name;
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        const files = Array.from(input?.files || []);
        const total = files.length;
        const totalSize = files.reduce((sum, file) => sum + file.size, 0);

        if (count) {
            count.textContent = total === 1
                ? 'Subiendo 1 archivo...'
                : `Subiendo ${total} archivos...`;
        }

        if (fileText) {
            fileText.textContent = total > 0
                ? `Subiendo archivo: ${files[0].name}`
                : 'Preparando archivos...';
        }

        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Subiendo...';
        }

        setProgress(0, 'Iniciando carga.');
        modal.show();

        const request = new XMLHttpRequest();
        const data = new FormData(form);

        request.open(form.method || 'POST', form.action);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        request.upload.addEventListener('progress', (progressEvent) => {
            if (!progressEvent.lengthComputable) {
                setProgress(15, 'Subiendo archivos...');
                return;
            }

            const percent = (progressEvent.loaded / progressEvent.total) * 100;
            const approximateLoaded = totalSize * (progressEvent.loaded / progressEvent.total);
            const name = currentFileName(files, approximateLoaded, totalSize);

            if (fileText && name) {
                fileText.textContent = `Subiendo archivo: ${name}`;
            }

            setProgress(percent, `${progressEvent.loaded.toLocaleString('es-AR')} de ${progressEvent.total.toLocaleString('es-AR')} bytes enviados.`);
        });

        request.addEventListener('load', () => {
            let response = {};

            try {
                response = JSON.parse(request.responseText || '{}');
            } catch {
                response = {};
            }

            if (request.status >= 200 && request.status < 300) {
                if (fileText) {
                    fileText.textContent = 'Carga finalizada. Actualizando pantalla...';
                }

                setProgress(100, response.message || 'Archivos cargados correctamente.');
                window.location.href = response.redirect || window.location.href;

                return;
            }

            if (count) {
                count.textContent = 'No se pudo completar la importación.';
            }

            if (fileText) {
                fileText.textContent = response.message || 'Revisá los archivos seleccionados e intentá nuevamente.';
            }

            setProgress(0, 'La carga fue rechazada.');

            if (submit) {
                submit.disabled = false;
                submit.textContent = 'Subir archivos';
            }
        });

        request.addEventListener('error', () => {
            if (count) {
                count.textContent = 'Error de conexión durante la carga.';
            }

            if (fileText) {
                fileText.textContent = 'No se pudo enviar la solicitud al servidor.';
            }

            setProgress(0, 'La carga no fue completada.');

            if (submit) {
                submit.disabled = false;
                submit.textContent = 'Subir archivos';
            }
        });

        request.send(data);
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const forms = document.querySelectorAll('[data-migration-form]');
    const overlay = document.getElementById('migrationProgressOverlay');

    if (!forms.length || !overlay) {
        return;
    }

    const periodText = overlay.querySelector('[data-migration-period]');
    const elapsedText = overlay.querySelector('[data-migration-elapsed]');
    let migrationStarted = false;
    let elapsedTimer = null;

    const setOverlayVisible = (visible) => {
        overlay.classList.toggle('gei-visible', visible);
        document.body.style.overflow = visible ? 'hidden' : '';
    };

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (migrationStarted) {
                return;
            }

            migrationStarted = true;

            const button = form.querySelector('[data-migration-submit]');
            const originalButtonText = button?.textContent.trim() || 'Migrar';
            const label = form.dataset.etiqueta || form.dataset.periodo || '';
            const startedAt = Date.now();

            if (button) {
                button.disabled = true;
                button.textContent = 'Migrando...';
            }

            if (periodText) {
                periodText.textContent = `Período: ${label}`;
            }

            if (elapsedText) {
                elapsedText.textContent = '0 s';
            }

            setOverlayVisible(true);

            elapsedTimer = window.setInterval(() => {
                if (elapsedText) {
                    const seconds = Math.floor((Date.now() - startedAt) / 1000);
                    elapsedText.textContent = `${seconds} s`;
                }
            }, 1000);

            try {
                const response = await fetch(form.action, {
                    method: form.method || 'POST',
                    body: new FormData(form),
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                let result = {};

                try {
                    result = await response.json();
                } catch {
                    result = {};
                }

                if (!response.ok) {
                    throw new Error(
                        result.message
                        || `La migración fue rechazada por el servidor (${response.status}).`
                    );
                }

                const minimumVisibleTime = 700;
                const elapsed = Date.now() - startedAt;

                if (elapsed < minimumVisibleTime) {
                    await new Promise((resolve) => {
                        window.setTimeout(resolve, minimumVisibleTime - elapsed);
                    });
                }

                window.location.href = result.redirect || window.location.href;
            } catch (error) {
                window.clearInterval(elapsedTimer);
                elapsedTimer = null;
                migrationStarted = false;
                setOverlayVisible(false);

                if (button) {
                    button.disabled = false;
                    button.textContent = originalButtonText;
                }

                window.alert(error.message || 'No se pudo completar la migración.');
            }
        });
    });
});
