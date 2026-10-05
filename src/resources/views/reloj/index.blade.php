@extends('layouts.app')

@section('title', 'Reloj')

@section('content')
    <header class="gei-page-heading d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Reloj Biométrico</h1>
            <p class="text-muted mb-0">Marcaciones, usuarios y estado del reloj Anviz.</p>
        </div>
        <div class="text-end">
            <span id="relojEstado" class="badge text-bg-secondary">consultando…</span>
            <div class="small text-muted mt-1">GeI Reloj Biométrico</div>
        </div>
    </header>

    <section class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Equipo</div>
                <div id="relojIp" class="fs-5 fw-semibold">-</div>
                <div id="relojPuerto" class="small text-muted">-</div>
            </div></div>
        </div>
        <div class="col-12 col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Usuarios</div>
                <div id="relojUsuarios" class="fs-5 fw-semibold">-</div>
                <div id="relojUsuariosDb" class="small text-muted">DB local: -</div>
            </div></div>
        </div>
        <div class="col-12 col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Huellas</div>
                <div id="relojHuellas" class="fs-5 fw-semibold">-</div>
                <div class="small text-muted">en el reloj</div>
            </div></div>
        </div>
        <div class="col-12 col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Marcaciones</div>
                <div id="relojMarcaciones" class="fs-5 fw-semibold">-</div>
                <div id="relojMarcacionesDb" class="small text-muted">DB local: -</div>
            </div></div>
        </div>
        <div class="col-12 col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="small text-muted">Nuevas</div>
                <div id="relojNuevas" class="fs-5 fw-semibold">-</div>
                <div class="small text-muted">bandera del reloj</div>
            </div></div>
        </div>
    </section>

    <section class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="fw-semibold">Sincronización</div>
                <div class="small text-muted">Lectura de usuarios y nuevas marcaciones desde el equipo.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary btn-sm js-reloj-accion" id="btnSyncUsuarios">Sincronizar usuarios</button>
                <button type="button" class="btn btn-primary btn-sm js-reloj-accion" id="btnSyncNuevas">Sincronizar nuevas</button>
                <button type="button" class="btn btn-outline-secondary btn-sm js-reloj-accion" id="btnActualizar">Actualizar estado</button>
            </div>
        </div>
        <div id="relojMensaje" class="px-3 pb-3 d-none"></div>
    </section>

    <ul class="nav nav-tabs mb-3" id="relojTabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabMarcaciones" type="button">Marcaciones</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabUsuarios" type="button">Usuarios</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDiagnostico" type="button">Diagnóstico</button></li>
    </ul>

    <div class="tab-content">
        <section class="tab-pane fade show active" id="tabMarcaciones">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pt-3">
                    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3">
                        <div><div class="fw-semibold">Marcaciones almacenadas</div><div class="small text-muted">Por defecto muestra solamente el día actual.</div></div>
                        <div class="d-flex flex-wrap align-items-end gap-2">
                            <div><label class="form-label small mb-1" for="recUser">Código</label><input id="recUser" class="form-control form-control-sm" style="width:120px" placeholder="Usuario"></div>
                            <div><label class="form-label small mb-1" for="recFrom">Desde</label><input id="recFrom" type="date" class="form-control form-control-sm"></div>
                            <div><label class="form-label small mb-1" for="recTo">Hasta</label><input id="recTo" type="date" class="form-control form-control-sm"></div>
                            <button type="button" id="btnBuscarMarcaciones" class="btn btn-outline-secondary btn-sm">Buscar</button>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Fecha / hora</th><th>Código</th><th>Nombre local</th><th>Backup</th><th>Tipo</th></tr></thead>
                        <tbody id="relojMarcacionesFilas"><tr><td colspan="5" class="text-muted">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="tab-pane fade" id="tabUsuarios">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pt-3">
                    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3">
                        <div><div class="fw-semibold">Usuarios del reloj</div><div class="small text-muted">La huella se enrola físicamente en el equipo.</div></div>
                        <div class="d-flex align-items-end gap-2">
                            <div><label class="form-label small mb-1" for="userSearch">Buscar</label><input id="userSearch" class="form-control form-control-sm" placeholder="Código o nombre"></div>
                            <button type="button" id="btnBuscarUsuarios" class="btn btn-outline-secondary btn-sm">Buscar</button>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Código</th><th>Nombre dispositivo</th><th style="min-width:240px">Nombre local</th><th>Huellas</th><th>Tarjeta</th></tr></thead>
                        <tbody id="relojUsuariosFilas"><tr><td colspan="5" class="text-muted">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="tab-pane fade" id="tabDiagnostico">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <div class="fw-semibold mb-2">Diagnóstico</div>
                <pre id="relojDiagnostico" class="bg-light border rounded p-3 mb-0 small" style="max-height:520px;overflow:auto">-</pre>
            </div></div>
        </section>
    </div>

    <script>
        (() => {
            const urls = {
                status: @json(route('reloj.status')),
                usuarios: @json(route('reloj.usuarios')),
                marcaciones: @json(route('reloj.marcaciones')),
                nombreBase: @json(url('/reloj/usuarios')),
                syncUsuarios: @json(route('reloj.sincronizar.usuarios')),
                syncNuevas: @json(route('reloj.sincronizar.nuevas')),
            };
            const csrf = @json(csrf_token());
            const formato = new Intl.NumberFormat('es-AR');

            const esc = (valor) => String(valor ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
            const formatDateTime = (valor) => {
                const m = String(valor ?? '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2}:\d{2})$/);
                return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}` : String(valor ?? '');
            };

            const mostrarMensaje = (texto, error = false) => {
                const e = document.getElementById('relojMensaje');
                e.className = `px-3 pb-3 ${error ? 'text-danger' : 'text-success'}`;
                e.textContent = texto;
            };

            const leerJson = async (respuesta) => {
                const j = await respuesta.json().catch(() => ({}));
                if (!respuesta.ok || j.ok === false) throw new Error(j.error || `Error HTTP ${respuesta.status}`);
                return j;
            };

            const get = async (url, params = {}) => {
                const u = new URL(url, window.location.origin);
                Object.entries(params).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) u.searchParams.set(k, v); });
                return leerJson(await fetch(u, {headers: {'Accept': 'application/json'}}));
            };

            const post = async (url, body = {}, method = 'POST') => leerJson(await fetch(url, {
                method,
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify(body),
            }));

            const cargarEstado = async () => {
                const respuesta = await fetch(urls.status, {headers: {'Accept': 'application/json'}});
                const j = await respuesta.json().catch(() => ({}));
                if (!respuesta.ok) throw new Error(j.error || `Error HTTP ${respuesta.status}`);
                const badge = document.getElementById('relojEstado');
                badge.textContent = j.ok ? 'ONLINE' : 'OFFLINE';
                badge.className = `badge ${j.ok ? 'text-bg-success' : 'text-bg-danger'}`;
                const live = j.live || {};
                const info = live.record_info || {};
                const net = live.network || {};
                document.getElementById('relojIp').textContent = net.ip_address || live.device_ip || '-';
                document.getElementById('relojPuerto').textContent = `Puerto ${net.port || live.device_port || '-'} · ID ${live.device_id || '-'}`;
                document.getElementById('relojUsuarios').textContent = info.users !== undefined ? formato.format(info.users) : '-';
                document.getElementById('relojHuellas').textContent = info.fingerprints !== undefined ? formato.format(info.fingerprints) : '-';
                document.getElementById('relojMarcaciones').textContent = info.total_records !== undefined ? formato.format(info.total_records) : '-';
                document.getElementById('relojNuevas').textContent = info.new_records !== undefined ? formato.format(info.new_records) : '-';
                document.getElementById('relojUsuariosDb').textContent = `DB local: ${formato.format(j.db_counts?.users ?? 0)}`;
                document.getElementById('relojMarcacionesDb').textContent = `DB local: ${formato.format(j.db_counts?.records ?? 0)}`;
                document.getElementById('relojDiagnostico').textContent = JSON.stringify(j, null, 2);
            };

            const cargarUsuarios = async () => {
                const j = await get(urls.usuarios, {q: document.getElementById('userSearch').value || ''});
                const filas = (j.users || []).map((u) => `<tr>
                    <td><strong>${esc(u.user_code)}</strong></td>
                    <td>${esc(u.device_name || '')}</td>
                    <td><input class="form-control form-control-sm js-nombre-local" data-codigo="${Number(u.user_code)}" value="${esc(u.local_name || '')}"></td>
                    <td>${esc(u.fp_enroll_state || '')}</td>
                    <td>${Number(u.has_card) ? esc(u.card_id) : '—'}</td>
                </tr>`).join('');
                document.getElementById('relojUsuariosFilas').innerHTML = filas || '<tr><td colspan="5" class="text-muted">Sin datos.</td></tr>';
            };

            const cargarMarcaciones = async () => {
                const j = await get(urls.marcaciones, {
                    limit: 200,
                    user: document.getElementById('recUser').value || '',
                    from: document.getElementById('recFrom').value || '',
                    to: document.getElementById('recTo').value || '',
                });
                const filas = (j.records || []).map((r) => `<tr>
                    <td>${esc(formatDateTime(r.datetime))}</td><td><strong>${esc(r.user_code)}</strong></td>
                    <td>${esc(r.user_name || '')}</td><td>${esc(r.backup_code)}</td><td>${esc(r.record_type)}</td>
                </tr>`).join('');
                document.getElementById('relojMarcacionesFilas').innerHTML = filas || '<tr><td colspan="5" class="text-muted">Sin datos.</td></tr>';
            };

            const refrescarTodo = async () => {
                try {
                    await Promise.all([cargarEstado(), cargarUsuarios(), cargarMarcaciones()]);
                    mostrarMensaje('Estado actualizado.');
                } catch (e) {
                    mostrarMensaje(e.message, true);
                    document.getElementById('relojEstado').textContent = 'OFFLINE';
                    document.getElementById('relojEstado').className = 'badge text-bg-danger';
                }
            };

            const sincronizar = async (url, etiqueta, body = {}) => {
                document.querySelectorAll('.js-reloj-accion').forEach((b) => b.disabled = true);
                mostrarMensaje(`${etiqueta}…`);
                try {
                    const j = await post(url, body);
                    mostrarMensaje(`${etiqueta}: recibidos ${j.received ?? 0}, incorporados ${j.inserted ?? j.saved ?? 0}.`);
                    await Promise.all([cargarEstado(), cargarUsuarios(), cargarMarcaciones()]);
                } catch (e) {
                    mostrarMensaje(e.message, true);
                } finally {
                    document.querySelectorAll('.js-reloj-accion').forEach((b) => b.disabled = false);
                }
            };

            const actualizarAlEntrar = async () => {
                document.querySelectorAll('.js-reloj-accion').forEach((b) => b.disabled = true);
                mostrarMensaje('Actualizando marcaciones desde el reloj…');

                let errorSincronizacion = null;

                try {
                    await post(urls.syncNuevas, {max: 1000});
                } catch (e) {
                    errorSincronizacion = e;
                }

                try {
                    await Promise.all([cargarEstado(), cargarUsuarios(), cargarMarcaciones()]);

                    if (errorSincronizacion) {
                        mostrarMensaje(`No se pudo sincronizar automáticamente: ${errorSincronizacion.message}`, true);
                    } else {
                        mostrarMensaje('Marcaciones actualizadas automáticamente.');
                    }
                } catch (e) {
                    mostrarMensaje(e.message, true);
                    document.getElementById('relojEstado').textContent = 'OFFLINE';
                    document.getElementById('relojEstado').className = 'badge text-bg-danger';
                } finally {
                    document.querySelectorAll('.js-reloj-accion').forEach((b) => b.disabled = false);
                }
            };

            const hoy = new Date();
            const fechaHoy = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;
            document.getElementById('recFrom').value = fechaHoy;
            document.getElementById('recTo').value = fechaHoy;

            document.getElementById('btnActualizar').addEventListener('click', refrescarTodo);
            document.getElementById('btnSyncUsuarios').addEventListener('click', () => sincronizar(urls.syncUsuarios, 'Sincronizando usuarios'));
            document.getElementById('btnSyncNuevas').addEventListener('click', () => sincronizar(urls.syncNuevas, 'Sincronizando marcaciones nuevas', {max: 1000}));
            document.getElementById('btnBuscarUsuarios').addEventListener('click', () => cargarUsuarios().catch((e) => mostrarMensaje(e.message, true)));
            document.getElementById('btnBuscarMarcaciones').addEventListener('click', () => cargarMarcaciones().catch((e) => mostrarMensaje(e.message, true)));
            document.getElementById('relojUsuariosFilas').addEventListener('change', async (event) => {
                const input = event.target.closest('.js-nombre-local');
                if (!input) return;
                try {
                    await post(`${urls.nombreBase}/${encodeURIComponent(input.dataset.codigo)}/nombre`, {name: input.value}, 'PUT');
                    mostrarMensaje('Nombre local guardado.');
                } catch (e) {
                    mostrarMensaje(e.message, true);
                }
            });

            actualizarAlEntrar();
        })();
    </script>
@endsection
