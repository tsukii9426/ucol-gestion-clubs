<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Persona &mdash; Clubes B23</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:       #1b2d54;
            --navy-light: #243567;
            --accent:     #4a7fd4;
            --accent-h:   #3568bf;
            --success:    #2e9e6e;
            --warning:    #d47a20;
            --white:      #ffffff;
            --gray-50:    #f7f8fc;
            --gray-100:   #eef0f6;
            --gray-200:   #e0e4f0;
            --gray-300:   #c5cad8;
            --gray-500:   #7a8099;
            --gray-700:   #3d4260;
            --text:       #1e2340;
            --error:      #d94f4f;
            --radius:     14px;
            --radius-sm:  8px;
            --shadow-lg:  0 16px 48px rgba(27,45,84,.18);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "DM Sans", sans-serif;
            min-height: 100vh; display: flex; flex-direction: column;
            background-color: #f0f3fa;
            background-image:
                radial-gradient(circle at 15% 25%, rgba(74,127,212,.08) 0%, transparent 50%),
                radial-gradient(circle at 85% 75%, rgba(27,45,84,.06) 0%, transparent 50%);
        }

        /* HEADER */
        header {
            background: var(--navy); height: 60px;
            display: flex; align-items: center; padding: 0 2rem; gap: .75rem;
            box-shadow: 0 2px 10px rgba(0,0,0,.2);
        }
        .hb-logo { width:38px; height:38px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:"Outfit",sans-serif; font-weight:700; font-size:.74rem; color:var(--navy); flex-shrink:0; }
        .hb-name { font-family:"Outfit",sans-serif; font-size:1rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.68rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }

        /* MAIN */
        main { flex:1; display:flex; align-items:center; justify-content:center; padding:2.5rem 1rem; }
        .wrap { width:100%; max-width:520px; }

        /* CARD */
        .card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; animation:fadeUp .4s ease both; }

        .card-top {
            background: linear-gradient(135deg,var(--navy) 0%,var(--navy-light) 100%);
            padding: 2rem 2rem 1.75rem;
            position: relative; overflow: hidden;
        }
        .card-top::before { content:""; position:absolute; right:-35px; top:-35px; width:160px; height:160px; border-radius:50%; background:rgba(255,255,255,.06); }
        .card-top::after  { content:""; position:absolute; right:30px; bottom:-55px; width:200px; height:200px; border-radius:50%; background:rgba(255,255,255,.04); }
        .card-top-inner { position:relative; z-index:1; }

        .role-badge { display:inline-flex; align-items:center; gap:.4rem; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); border-radius:20px; padding:.25rem .85rem; font-size:.72rem; font-weight:600; font-family:"Outfit",sans-serif; color:rgba(255,255,255,.9); text-transform:uppercase; letter-spacing:.8px; margin-bottom:1.1rem; }
        .card-icon { width:50px; height:50px; background:rgba(255,255,255,.12); border:1.5px solid rgba(255,255,255,.2); border-radius:13px; display:flex; align-items:center; justify-content:center; margin-bottom:1rem; }
        .card-top h1 { font-family:"Outfit",sans-serif; font-size:1.35rem; font-weight:700; color:#fff; line-height:1.3; margin-bottom:.35rem; }
        .card-top p  { font-size:.82rem; color:rgba(255,255,255,.6); line-height:1.5; }

        .card-body { padding:1.75rem 2rem 2rem; }

        /* FORM */
        .form-grid       { display:grid; grid-template-columns:1fr 1fr;     gap:1rem 1.1rem; }
        .form-grid.cols3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem 1.1rem; }
        .full { grid-column:1/-1; }

        .fg { }

        label { display:block; font-size:.73rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:var(--gray-700); margin-bottom:.4rem; }
        label .req { color:var(--error); margin-left:2px; }

        .iw { position:relative; }
        .iw .icon { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--gray-300); pointer-events:none; transition:color .2s; }
        .iw:focus-within .icon { color:var(--accent); }

        input[type="text"],
        input[type="password"],
        select {
            width:100%; height:46px; padding:0 1rem 0 2.65rem;
            border:1.5px solid var(--gray-100); border-radius:var(--radius-sm);
            font-family:"DM Sans",sans-serif; font-size:.9rem; color:var(--text);
            background:var(--gray-50);
            transition:border-color .2s, box-shadow .2s, background .2s;
            outline:none; appearance:none; -webkit-appearance:none;
        }
        input:focus, select:focus { border-color:var(--accent); background:var(--white); box-shadow:0 0 0 3px rgba(74,127,212,.12); }

        /* flecha del select */
        .sw::after { content:""; position:absolute; right:.85rem; top:50%; transform:translateY(-50%); width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid var(--gray-300); pointer-events:none; transition:border-color .2s; }
        .sw:focus-within::after { border-top-color:var(--accent); }

        /* input ID grande */
        .id-big { font-family:"Outfit",sans-serif !important; font-size:1.15rem !important; font-weight:700 !important; letter-spacing:.06em; }
        .id-big::placeholder { font-size:.86rem; font-weight:400; letter-spacing:0; }

        /* toggle contraseña */
        .btn-eye { position:absolute; right:.85rem; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--gray-300); padding:2px; display:flex; align-items:center; transition:color .2s; }
        .btn-eye:hover { color:var(--accent); }

        /* hint y strength */
        .hint { font-size:.72rem; color:var(--gray-500); margin-top:.3rem; }
        .strength-bar  { height:3px; border-radius:3px; background:var(--gray-100); margin-top:.4rem; overflow:hidden; }
        .strength-fill { height:100%; border-radius:3px; transition:width .3s, background .3s; }
        .strength-txt  { font-size:.7rem; color:var(--gray-500); margin-top:.2rem; }

        /* DIVISOR */
        .sec-div { display:flex; align-items:center; gap:.6rem; margin:1.25rem 0 1.1rem; color:var(--gray-300); font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
        .sec-div::before, .sec-div::after { content:""; flex:1; height:1px; background:var(--gray-100); }

        /* INFO BOX */
        .info-box { background:#f0f6ff; border:1px solid #c8deff; border-radius:var(--radius-sm); padding:.75rem .95rem; font-size:.78rem; color:#2a4a80; display:flex; gap:.45rem; align-items:flex-start; margin-bottom:1.1rem; line-height:1.55; }
        .info-box.info-id { display:none; }
        .info-box.info-id.visible { display:flex; }
        .info-box svg { flex-shrink:0; margin-top:1px; }

        /* BOTON */
        .btn-submit { width:100%; height:50px; margin-top:1.25rem; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.97rem; font-weight:700; letter-spacing:.3px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:.5rem; transition:all .2s; box-shadow:0 4px 14px rgba(74,127,212,.25); }
        .btn-submit:hover { background:var(--accent-h); box-shadow:0 6px 22px rgba(74,127,212,.35); transform:translateY(-1px); }
        .btn-submit:active { transform:translateY(0); }

        /* LINK VOLVER */
        .back-link { display:flex; align-items:center; justify-content:center; gap:.4rem; margin-top:1.25rem; font-size:.8rem; color:var(--gray-500); text-decoration:none; padding:.5rem; border-radius:var(--radius-sm); transition:all .2s; }
        .back-link:hover { color:var(--accent); background:rgba(74,127,212,.05); }

        footer { text-align:center; padding:1.25rem; font-size:.72rem; color:var(--gray-500); }

        @keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }

        @media (max-width:540px) {
            .form-grid, .form-grid.cols3 { grid-template-columns:1fr; }
            .full { grid-column:1; }
            .card-body { padding:1.5rem; }
            .card-top  { padding:1.5rem; }
        }
    </style>
</head>
<body>

<header>
    <div class="hb-logo">UdeC</div>
    <div>
        <div class="hb-name">Clubes Estudiantiles</div>
        <div class="hb-sub">Bachillerato 23 &middot; Universidad de Colima</div>
    </div>
</header>

<main>
<div class="wrap">
<div class="card">

    <!-- ENCABEZADO -->
    <div class="card-top">
        <div class="card-top-inner">
            <div class="role-badge">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                Registro de persona
            </div>
            <div class="card-icon">
                <svg width="22" height="22" fill="none" stroke="white" stroke-width="1.8" viewBox="0 0 24 24">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </div>
            <h1>Crear cuenta</h1>
            <p>Completa tu informaci&oacute;n para acceder al sistema de clubs</p>
        </div>
    </div>

    <!-- FORMULARIO -->
    <div class="card-body">
    <form method="POST" action="#" id="form-registro" novalidate>

        <!-- TIPO -->
        <div class="fg full" style="margin-bottom:1.1rem">
            <label for="tipo">Tipo de usuario <span class="req">*</span></label>
            <div class="iw sw">
                <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <select id="tipo" name="tipo" onchange="cambiarTipo(this)" required>
                    <option value="">Selecciona tu tipo&hellip;</option>
                    <option value="Administrativo">Administrativo</option>
                    <option value="Docente">Docente</option>
                    <option value="Estudiante">Estudiante</option>
                </select>
            </div>
        </div>

        <!-- INFO CONTEXTUAL -->
        <div class="info-box info-id" id="info-trabajador">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Ingresa tu <strong>n&uacute;mero de trabajador</strong> asignado por la instituci&oacute;n. Este ser&aacute; tu ID de acceso al sistema.
        </div>
        <div class="info-box info-id" id="info-cuenta">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Ingresa tu <strong>n&uacute;mero de cuenta</strong> del sistema <strong>SICEUC</strong> de la Universidad de Colima.
        </div>

        <!-- NUMERO ID -->
        <div class="fg full" style="margin-bottom:1.1rem">
            <label for="numero_id" id="lbl-id">N&uacute;mero de trabajador / cuenta <span class="req">*</span></label>
            <div class="iw">
                <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M7 15h2M11 15h6"/></svg>
                <input type="text" id="numero_id" name="numero_id"
                       class="id-big"
                       placeholder="Selecciona tu tipo primero&hellip;"
                       inputmode="numeric"
                       maxlength="10"
                       disabled
                       oninput="soloNumeros(this)"
                       required>
            </div>
        </div>

        <div class="sec-div">Nombre completo</div>

        <!-- NOMBRE -->
        <div class="form-grid cols3">

            <div class="fg">
                <label for="apellido_paterno">Apellido paterno <span class="req">*</span></label>
                <div class="iw">
                    <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" id="apellido_paterno" name="apellido_paterno"
                           placeholder="Flores"
                           style="text-transform:uppercase"
                           required>
                </div>
            </div>

            <div class="fg">
                <label for="apellido_materno">Apellido materno <span class="req">*</span></label>
                <div class="iw">
                    <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" id="apellido_materno" name="apellido_materno"
                           placeholder="Ch&aacute;vez"
                           style="text-transform:uppercase"
                           required>
                </div>
            </div>

            <div class="fg">
                <label for="nombres">Nombre(s) <span class="req">*</span></label>
                <div class="iw">
                    <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" id="nombres" name="nombres"
                           placeholder="Ximena"
                           style="text-transform:uppercase"
                           required>
                </div>
            </div>

        </div>

        <div class="sec-div">Contacto</div>

        <div class="form-grid">

            <!-- CORREO -->
            <div class="fg">
                <label for="correo">Correo electr&oacute;nico <span class="req">*</span></label>
                <div class="iw">
                    <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <input type="text" id="correo" name="correo"
                           placeholder="nombre@ucol.edu.mx"
                           autocomplete="email"
                           required>
                </div>
                <p class="hint">Correo institucional de la Universidad de Colima</p>
            </div>

            <!-- TELEFONO -->
            <div class="fg">
                <label for="telefono">Tel&eacute;fono <span class="req">*</span></label>
                <div class="iw">
                    <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.64 3.5 2 2 0 0 1 3.62 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9a16 16 0 0 0 6 6l.94-.94a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <input type="text" id="telefono" name="telefono"
                           placeholder="3141234567"
                           inputmode="numeric"
                           maxlength="10"
                           oninput="soloNumeros(this)"
                           required>
                </div>
                <p class="hint">10 d&iacute;gitos sin espacios ni guiones</p>
            </div>

        </div>

        <div class="sec-div">Contrase&ntilde;a</div>

        <div class="form-grid">

            <!-- CONTRASENA -->
            <div class="fg">
                <label for="contrasena">Nueva contrase&ntilde;a <span class="req">*</span></label>
                <div class="iw">
                    <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" id="contrasena" name="contrasena"
                           placeholder="M&iacute;nimo 8 caracteres"
                           autocomplete="new-password"
                           oninput="medirFuerza(this.value)"
                           required>
                    <button type="button" class="btn-eye" onclick="toggle('contrasena','eye1')">
                        <svg id="eye1" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="s-fill"></div></div>
                <p class="strength-txt" id="s-txt">Ingresa una contrase&ntilde;a</p>
            </div>

            <!-- CONFIRMAR -->
            <div class="fg">
                <label for="confirmar">Confirmar contrase&ntilde;a <span class="req">*</span></label>
                <div class="iw">
                    <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" id="confirmar" name="confirmar"
                           placeholder="Repite tu contrase&ntilde;a"
                           autocomplete="new-password"
                           oninput="verificarCoincidencia()"
                           required>
                    <button type="button" class="btn-eye" onclick="toggle('confirmar','eye2')">
                        <svg id="eye2" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <p class="hint" id="hint-confirmar">Debe tener al menos 1 n&uacute;mero y 8 caracteres</p>
            </div>

        </div>

        <button type="submit" class="btn-submit" id="btn-enviar">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            Crear cuenta
        </button>

    </form>

    <a href="login_encargado.php" class="back-link">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Ya tengo cuenta &mdash; iniciar sesi&oacute;n
    </a>

    </div><!-- /card-body -->
</div><!-- /card -->
</div>
</main>

<footer>&copy; 2026 Universidad de Colima &middot; Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
// ── Cambiar tipo ──────────────────────────────────
function cambiarTipo(sel) {
    var val     = sel.value;
    var input   = document.getElementById("numero_id");
    var lbl     = document.getElementById("lbl-id");
    var infoT   = document.getElementById("info-trabajador");
    var infoC   = document.getElementById("info-cuenta");

    if (!val) {
        input.disabled = true;
        input.placeholder = "Selecciona tu tipo primero…";
        infoT.classList.remove("visible");
        infoC.classList.remove("visible");
        return;
    }

    input.disabled = false;
    input.value = "";
    input.focus();

    if (val === "Estudiante") {
        lbl.innerHTML = "Número de cuenta <span class='req'>*</span>";
        input.placeholder = "Ej. 20231113";
        infoT.classList.remove("visible");
        infoC.classList.add("visible");
    } else {
        lbl.innerHTML = "Número de trabajador <span class='req'>*</span>";
        input.placeholder = "Ej. 12345";
        infoC.classList.remove("visible");
        infoT.classList.add("visible");
    }
}

// ── Solo números ──────────────────────────────────
function soloNumeros(el) {
    el.value = el.value.replace(/[^0-9]/g, "");
}

// ── Toggle contraseña ─────────────────────────────
function toggle(inputId, iconId) {
    var inp  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    var show = inp.type === "password";
    inp.type = show ? "text" : "password";
    icon.innerHTML = show
        ? "<path d='M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94'/><path d='M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19'/><line x1='1' y1='1' x2='23' y2='23'/>"
        : "<path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/><circle cx='12' cy='12' r='3'/>";
}

// ── Fortaleza contraseña ──────────────────────────
function medirFuerza(val) {
    var fill = document.getElementById("s-fill");
    var txt  = document.getElementById("s-txt");
    var pts  = 0;
    if (val.length >= 8)          pts++;
    if (/[A-Z]/.test(val))        pts++;
    if (/[0-9]/.test(val))        pts++;
    if (/[^A-Za-z0-9]/.test(val)) pts++;
    var niveles = [
        {w:"0%",   c:"#e0e0e0", t:"Ingresa una contraseña"},
        {w:"25%",  c:"#d94f4f", t:"Muy débil"},
        {w:"50%",  c:"#d47a20", t:"Débil"},
        {w:"75%",  c:"#4a7fd4", t:"Buena"},
        {w:"100%", c:"#2e9e6e", t:"Excelente"}
    ];
    var n = val.length === 0 ? niveles[0] : niveles[pts] || niveles[1];
    fill.style.width      = n.w;
    fill.style.background = n.c;
    txt.textContent       = n.t;
    txt.style.color       = n.c === "#e0e0e0" ? "var(--gray-500)" : n.c;
}

// ── Verificar coincidencia ────────────────────────
function verificarCoincidencia() {
    var p1   = document.getElementById("contrasena").value;
    var p2   = document.getElementById("confirmar").value;
    var hint = document.getElementById("hint-confirmar");
    if (!p2) { hint.textContent = "Debe tener al menos 1 número y 8 caracteres"; hint.style.color = ""; return; }
    if (p1 === p2) { hint.textContent = "✓ Las contraseñas coinciden"; hint.style.color = "var(--success)"; }
    else           { hint.textContent = "⚠ Las contraseñas no coinciden"; hint.style.color = "var(--error)"; }
}

// ── Deshabilitar botón al enviar ──────────────────
document.getElementById("form-registro").addEventListener("submit", function() {
    var btn = document.getElementById("btn-enviar");
    btn.disabled = true;
    btn.innerHTML = "<svg width='16' height='16' fill='none' stroke='currentColor' stroke-width='2.5' viewBox='0 0 24 24' style='animation:spin 1s linear infinite'><path d='M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83'/></svg>Guardando…";
});
</script>
<style>@keyframes spin { to { transform: rotate(360deg); } }</style>

</body>
</html>