<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Persona &mdash; Bachillerato 23</title>
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
            --gray-400:   #a0a8c0;
            --gray-500:   #7a8099;
            --gray-700:   #3d4260;
            --text:       #1e2340;
            --error:      #d94f4f;
            --radius:     12px;
            --radius-sm:  8px;
            --shadow:     0 4px 20px rgba(27,45,84,.09);
            --shadow-lg:  0 10px 36px rgba(27,45,84,.14);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "DM Sans", sans-serif; background: var(--gray-50); min-height: 100vh; color: var(--text); }

        /* HEADER */
        header {
            background: var(--navy); height: 64px;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 2rem; box-shadow: 0 2px 10px rgba(0,0,0,.25);
            position: sticky; top: 0; z-index: 50;
        }
        .hb { display: flex; align-items: center; gap: .75rem; }
        .hb-logo { width:40px; height:40px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-family:"Outfit",sans-serif; font-weight:700; font-size:.75rem; color:var(--navy); }
        .hb-name { font-family:"Outfit",sans-serif; font-size:1.05rem; font-weight:600; color:#fff; }
        .hb-sub  { font-size:.7rem; color:rgba(255,255,255,.5); text-transform:uppercase; letter-spacing:.8px; }
        nav { display:flex; align-items:center; gap:.2rem; }
        nav a { color:rgba(255,255,255,.75); text-decoration:none; font-size:.82rem; font-weight:500; padding:.4rem .75rem; border-radius:var(--radius-sm); display:flex; align-items:center; gap:.35rem; transition:all .2s; }
        nav a:hover, nav a.active { color:#fff; background:rgba(255,255,255,.12); }
        .nav-out { color:rgba(255,255,255,.65)!important; border:1px solid rgba(255,255,255,.2)!important; margin-left:.5rem; }
        .nav-out:hover { background:rgba(255,255,255,.1)!important; }

        /* SUBHEADER */
        .subhdr { background:var(--navy-light); padding:.6rem 2rem; display:flex; align-items:center; gap:.75rem; }
        .sub-av { width:34px; height:34px; border-radius:50%; background:var(--accent); display:flex; align-items:center; justify-content:center; font-family:"Outfit",sans-serif; font-weight:700; font-size:.75rem; color:#fff; }
        .sub-name { font-family:"Outfit",sans-serif; font-weight:600; font-size:.9rem; color:#fff; }
        .sub-det  { font-size:.72rem; color:rgba(255,255,255,.6); }
        .sub-badge { margin-left:auto; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); border-radius:20px; padding:.2rem .75rem; font-size:.72rem; color:rgba(255,255,255,.85); font-family:"Outfit",sans-serif; display:flex; align-items:center; gap:.35rem; }

        /* PAGE */
        .page { max-width:860px; margin:0 auto; padding:2rem 1.5rem 4rem; }

        /* BREADCRUMB */
        .breadcrumb { display:flex; align-items:center; gap:.4rem; font-size:.78rem; color:var(--gray-500); margin-bottom:1.5rem; flex-wrap:wrap; }
        .breadcrumb a { color:var(--accent); text-decoration:none; }
        .breadcrumb a:hover { text-decoration:underline; }
        .breadcrumb svg { color:var(--gray-300); }

        /* PAGE TITLE */
        .page-title { display:flex; align-items:center; gap:.75rem; margin-bottom:1.75rem; }
        .page-title-icon { width:44px; height:44px; border-radius:11px; background:var(--accent); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .page-title h1 { font-family:"Outfit",sans-serif; font-size:1.4rem; font-weight:700; color:var(--navy); }
        .page-title p  { font-size:.83rem; color:var(--gray-500); margin-top:.15rem; }

        /* TIPO SELECTOR */
        .tipo-selector { display:grid; grid-template-columns:repeat(3,1fr); gap:.85rem; margin-bottom:1.5rem; }
        .tipo-card {
            background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow);
            padding:1.2rem 1rem; text-align:center; cursor:pointer;
            border:2px solid transparent; transition:all .2s;
            position:relative;
        }
        .tipo-card:hover { border-color:var(--gray-200); box-shadow:var(--shadow-lg); transform:translateY(-1px); }
        .tipo-card.selected { border-color:var(--accent); background:#f0f5ff; }
        .tipo-card input[type="radio"] { position:absolute; opacity:0; pointer-events:none; }
        .tipo-icon { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; margin:0 auto .75rem; transition:all .2s; }
        .ti-admin { background:#eef0f6; color:var(--navy); }
        .ti-docente { background:#e8f0fd; color:var(--accent); }
        .ti-estudiante { background:#e8f7f0; color:var(--success); }
        .tipo-card.selected .ti-admin    { background:var(--navy); color:#fff; }
        .tipo-card.selected .ti-docente  { background:var(--accent); color:#fff; }
        .tipo-card.selected .ti-estudiante { background:var(--success); color:#fff; }
        .tipo-label { font-family:"Outfit",sans-serif; font-size:.88rem; font-weight:700; color:var(--text); }
        .tipo-desc  { font-size:.72rem; color:var(--gray-500); margin-top:.2rem; line-height:1.4; }
        .tipo-check { position:absolute; top:.65rem; right:.65rem; width:18px; height:18px; border-radius:50%; border:1.5px solid var(--gray-300); background:var(--white); display:flex; align-items:center; justify-content:center; transition:all .2s; }
        .tipo-card.selected .tipo-check { background:var(--accent); border-color:var(--accent); }
        .tipo-card.selected .tipo-check svg { display:block; }
        .tipo-check svg { display:none; }

        /* CARD */
        .card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; margin-bottom:1.5rem; animation:fadeUp .3s ease both; }
        .card:nth-child(2){animation-delay:.05s} .card:nth-child(3){animation-delay:.1s}
        .card-top { background:linear-gradient(135deg,var(--navy) 0%,var(--navy-light) 100%); padding:1.25rem 1.75rem; position:relative; overflow:hidden; }
        .card-top::after { content:""; position:absolute; right:-20px; bottom:-40px; width:140px; height:140px; border-radius:50%; background:rgba(255,255,255,.05); }
        .card-top h2 { font-family:"Outfit",sans-serif; font-size:1rem; font-weight:700; color:#fff; display:flex; align-items:center; gap:.5rem; position:relative; z-index:1; }
        .card-top p  { font-size:.78rem; color:rgba(255,255,255,.6); margin-top:.2rem; position:relative; z-index:1; }
        .card-body { padding:1.5rem 1.75rem 1.75rem; }

        /* FORM */
        .form-grid       { display:grid; grid-template-columns:1fr 1fr;     gap:1rem 1.25rem; }
        .form-grid.cols3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem 1.25rem; }
        .full { grid-column:1/-1; }

        label { display:block; font-size:.73rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:var(--gray-700); margin-bottom:.4rem; }
        label .req { color:var(--error); margin-left:2px; }

        .iw { position:relative; }
        .iw .icon { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--gray-300); pointer-events:none; transition:color .2s; }
        .iw:focus-within .icon { color:var(--accent); }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="number"],
        select {
            width:100%; height:46px; padding:0 1rem 0 2.65rem;
            border:1.5px solid var(--gray-100); border-radius:var(--radius-sm);
            font-family:"DM Sans",sans-serif; font-size:.9rem; color:var(--text);
            background:var(--gray-50);
            transition:border-color .2s, box-shadow .2s, background .2s;
            outline:none; appearance:none; -webkit-appearance:none;
        }
        input:focus, select:focus {
            border-color:var(--accent); background:var(--white);
            box-shadow:0 0 0 3px rgba(74,127,212,.12);
        }
        input.err { border-color:var(--error); background:#fff8f8; }

        /* Select arrow */
        .sw::after { content:""; position:absolute; right:.85rem; top:50%; transform:translateY(-50%); width:0; height:0; border-left:5px solid transparent; border-right:5px solid transparent; border-top:6px solid var(--gray-300); pointer-events:none; transition:border-color .2s; }
        .sw:focus-within::after { border-top-color:var(--accent); }

        .hint { font-size:.72rem; color:var(--gray-500); margin-top:.3rem; }

        /* Campo con ID grande */
        .id-input {
            font-family:"Outfit",sans-serif !important;
            font-size:1.2rem !important; font-weight:700 !important;
            letter-spacing:.08em;
        }
        .id-input::placeholder { font-size:.85rem; font-weight:400; letter-spacing:0; }

        /* Vista previa del perfil */
        .preview-card {
            background:var(--gray-50); border:1.5px solid var(--gray-100);
            border-radius:var(--radius-sm); padding:1rem 1.25rem;
            display:flex; align-items:center; gap:1rem;
            margin-bottom:1.5rem; transition:all .2s;
        }
        .preview-avatar {
            width:46px; height:46px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-family:"Outfit",sans-serif; font-weight:700; font-size:.95rem; color:#fff;
            flex-shrink:0; transition:background .3s;
        }
        .pa-admin { background:var(--navy); }
        .pa-docente { background:var(--accent); }
        .pa-estudiante { background:var(--success); }
        .preview-info { flex:1; }
        .preview-name { font-family:"Outfit",sans-serif; font-size:.95rem; font-weight:700; color:var(--text); min-height:1.2em; }
        .preview-meta { display:flex; align-items:center; gap:.6rem; margin-top:.25rem; flex-wrap:wrap; }
        .preview-chip { display:inline-flex; align-items:center; gap:.3rem; font-size:.72rem; color:var(--gray-500); }
        .preview-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .65rem; border-radius:20px; font-family:"Outfit",sans-serif; font-size:.7rem; font-weight:600; }
        .pb-admin { background:#eef0f6; color:var(--navy); }
        .pb-docente { background:#e8f0fd; color:var(--accent); }
        .pb-estudiante { background:#e8f7f0; color:var(--success); }

        /* Seccion condicional */
        .campo-condicional { display:none; }
        .campo-condicional.visible { display:block; }

        /* Info box */
        .info-box { background:#f0f6ff; border:1px solid #c8deff; border-radius:var(--radius-sm); padding:.8rem 1rem; font-size:.79rem; color:#2a4a80; display:flex; gap:.5rem; align-items:flex-start; margin-bottom:1rem; line-height:1.55; }
        .info-box svg { flex-shrink:0; margin-top:1px; }

        /* FORM FOOTER */
        .form-footer { display:flex; align-items:center; justify-content:flex-end; gap:.75rem; margin-top:1.75rem; padding-top:1.25rem; border-top:1px solid var(--gray-100); }
        .btn-cancel { height:46px; padding:0 1.4rem; background:none; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.9rem; font-weight:600; color:var(--gray-700); cursor:pointer; text-decoration:none; display:flex; align-items:center; gap:.4rem; transition:all .2s; }
        .btn-cancel:hover { background:var(--gray-50); border-color:var(--gray-300); }
        .btn-submit { height:46px; padding:0 1.8rem; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-sm); font-family:"Outfit",sans-serif; font-size:.9rem; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:.5rem; transition:all .2s; box-shadow:0 3px 12px rgba(74,127,212,.25); }
        .btn-submit:hover { background:var(--accent-h); box-shadow:0 6px 20px rgba(74,127,212,.35); transform:translateY(-1px); }
        .btn-submit:active { transform:translateY(0); }

        /* TABLA PERSONAS REGISTRADAS */
        .personas-card { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); overflow:hidden; margin-top:2rem; }
        .personas-header { padding:1rem 1.5rem; border-bottom:1px solid var(--gray-100); display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .personas-header h3 { font-family:"Outfit",sans-serif; font-size:.95rem; font-weight:700; display:flex; align-items:center; gap:.5rem; }
        .cnt-badge { background:var(--gray-100); border-radius:20px; padding:.15rem .65rem; font-size:.72rem; font-weight:600; color:var(--gray-700); }

        /* Filtros tipo */
        .tipo-filters { display:flex; gap:.4rem; flex-wrap:wrap; }
        .tf-chip { padding:.28rem .75rem; border-radius:20px; font-family:"Outfit",sans-serif; font-size:.75rem; font-weight:600; cursor:pointer; border:1.5px solid var(--gray-200); background:var(--white); color:var(--gray-700); transition:all .2s; white-space:nowrap; }
        .tf-chip:hover { border-color:var(--accent); color:var(--accent); }
        .tf-chip.active { background:var(--navy); color:#fff; border-color:var(--navy); }
        .tf-chip.f-admin.active { background:var(--navy); border-color:var(--navy); }
        .tf-chip.f-docente.active { background:var(--accent); border-color:var(--accent); }
        .tf-chip.f-est.active { background:var(--success); border-color:var(--success); }

        /* Tabla */
        .tbl-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        thead tr { background:var(--gray-50); border-bottom:1.5px solid var(--gray-200); }
        thead th { padding:.7rem 1.1rem; text-align:left; font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--gray-500); white-space:nowrap; }
        tbody tr { border-bottom:1px solid var(--gray-100); transition:background .15s; }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:#f5f7fd; }
        tbody td { padding:.85rem 1.1rem; font-size:.84rem; vertical-align:middle; }

        .td-id { font-family:"Outfit",sans-serif; font-size:.78rem; font-weight:600; color:var(--gray-500); background:var(--gray-50); border:1px solid var(--gray-200); border-radius:20px; padding:.12rem .6rem; white-space:nowrap; display:inline-block; }
        .td-nombre { font-weight:600; color:var(--text); }
        .td-correo { font-size:.76rem; color:var(--gray-500); }
        .tipo-pill { display:inline-flex; align-items:center; gap:.3rem; padding:.18rem .65rem; border-radius:20px; font-family:"Outfit",sans-serif; font-size:.7rem; font-weight:600; white-space:nowrap; }
        .tp-admin { background:#eef0f6; color:var(--navy); }
        .tp-docente { background:#e8f0fd; color:var(--accent); }
        .tp-estudiante { background:#e8f7f0; color:var(--success); }
        .dot-sm { width:5px; height:5px; border-radius:50%; }
        .ds-navy { background:var(--navy); }
        .ds-accent { background:var(--accent); }
        .ds-green { background:var(--success); }

        .btn-tbl-del { width:30px; height:30px; border-radius:var(--radius-sm); background:#fff5f5; border:1px solid #fbd5d5; color:var(--error); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .2s; }
        .btn-tbl-del:hover { background:#ffe8e8; border-color:var(--error); }

        @keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
        footer { text-align:center; padding:1.5rem; font-size:.72rem; color:var(--gray-500); }

        @media (max-width:640px) {
            .form-grid, .form-grid.cols3 { grid-template-columns:1fr; }
            .full { grid-column:1; }
            .tipo-selector { grid-template-columns:1fr; }
            header { padding:0 1rem; }
            nav a span { display:none; }
            .page { padding:1.25rem 1rem 3rem; }
        }
    </style>
</head>
<body>

<!-- HEADER -->
<header>
    <div class="hb">
        <div class="hb-logo">UdeC</div>
        <div>
            <div class="hb-name">Clubes Estudiantiles</div>
            <div class="hb-sub">Bachillerato 23</div>
        </div>
    </div>
    <nav>
        <a href="dashboard_plantel.php" class="active">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span>Inicio</span>
        </a>
        <a href="registrar_persona.php" class="active">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            <span>Registrar persona</span>
        </a>
        <a href="b23-srvc-coord.php" class="nav-out">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Salir
        </a>
    </nav>
</header>

<div class="subhdr">
    <div class="sub-av">B</div>
    <div>
        <div class="sub-name">BACHILLERATO 23</div>
        <div class="sub-det">Manzanillo, Colima</div>
    </div>
    <div class="sub-badge">
        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        Administrador del plantel
    </div>
</div>

<div class="page">

    <!-- BREADCRUMB -->
    <div class="breadcrumb">
        <a href="dashboard_plantel.php">Inicio</a>
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        <strong style="color:var(--text)">Registrar persona</strong>
    </div>

    <!-- TITLE -->
    <div class="page-title">
        <div class="page-title-icon">
            <svg width="21" height="21" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
        </div>
        <div>
            <h1>Registrar persona</h1>
            <p>Agrega docentes, administrativos o estudiantes a tu plantel</p>
        </div>
    </div>

    <!-- SELECTOR DE TIPO -->
    <div class="tipo-selector" id="tipo-selector">

        <label class="tipo-card selected" id="card-admin" onclick="selTipo(this, 'Administrativo')">
            <input type="radio" name="tipo" value="Administrativo" checked>
            <div class="tipo-check">
                <svg width="10" height="10" fill="none" stroke="white" stroke-width="3" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <div class="tipo-icon ti-admin">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="tipo-label">Administrativo</div>
            <div class="tipo-desc">Personal de administraci&oacute;n y direcci&oacute;n del plantel</div>
        </label>

        <label class="tipo-card" id="card-docente" onclick="selTipo(this, 'Docente')">
            <input type="radio" name="tipo" value="Docente">
            <div class="tipo-check">
                <svg width="10" height="10" fill="none" stroke="white" stroke-width="3" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <div class="tipo-icon ti-docente">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
            </div>
            <div class="tipo-label">Docente</div>
            <div class="tipo-desc">Profesores y maestros que pueden llevar clubes</div>
        </label>

        <label class="tipo-card" id="card-estudiante" onclick="selTipo(this, 'Estudiante')">
            <input type="radio" name="tipo" value="Estudiante">
            <div class="tipo-check">
                <svg width="10" height="10" fill="none" stroke="white" stroke-width="3" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
            </div>
            <div class="tipo-icon ti-estudiante">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            </div>
            <div class="tipo-label">Estudiante</div>
            <div class="tipo-desc">Alumnos que pueden inscribirse a los clubs</div>
        </label>

    </div>

    <!-- PREVIEW DEL PERFIL -->
    <div class="preview-card" id="preview-card">
        <div class="preview-avatar pa-admin" id="preview-av">?</div>
        <div class="preview-info">
            <div class="preview-name" id="preview-name">Escribe el nombre para previsualizar</div>
            <div class="preview-meta">
                <span class="preview-badge pb-admin" id="preview-badge">Administrativo</span>
                <span class="preview-chip" id="preview-id-chip" style="display:none">
                    <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    <span id="preview-id">---</span>
                </span>
                <span class="preview-chip" id="preview-correo-chip" style="display:none">
                    <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <span id="preview-correo">---</span>
                </span>
            </div>
        </div>
    </div>

    <form method="POST" action="#" id="form-persona" novalidate>
        <input type="hidden" name="tipo_persona" id="input-tipo" value="Administrativo">

        <!-- CARD 1: IDENTIFICACION -->
        <div class="card">
            <div class="card-top">
                <h2>
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    Identificaci&oacute;n
                </h2>
                <p>N&uacute;mero de trabajador o de cuenta del sistema SICEUC</p>
            </div>
            <div class="card-body">

                <div class="info-box" id="info-id">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Para <strong>Administrativos y Docentes</strong> usa el n&uacute;mero de trabajador. Este ser&aacute; su ID de acceso al sistema.
                </div>

                <div class="form-grid">

                    <div class="fg">
                        <label for="id_persona" id="lbl-id">N&uacute;mero de trabajador <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M7 15h2M11 15h6"/></svg>
                            <input type="text" id="id_persona" name="id_persona"
                                   class="id-input"
                                   placeholder="Ej. 12345"
                                   inputmode="numeric"
                                   maxlength="10"
                                   oninput="actualizarPreview()"
                                   required>
                        </div>
                        <p class="hint" id="hint-id">El n&uacute;mero de trabajador es &uacute;nico en todo el sistema</p>
                    </div>

                    <div class="fg">
                        <label for="correo">Correo electr&oacute;nico <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <input type="text" id="correo" name="correo"
                                   placeholder="Ej. nombre@ucol.edu.mx"
                                   oninput="actualizarPreview()"
                                   required>
                        </div>
                        <p class="hint">Correo institucional del sistema SICEUC</p>
                    </div>

                    <div class="fg">
                        <label for="telefono">Tel&eacute;fono <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.64 3.5 2 2 0 0 1 3.62 1.36h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9a16 16 0 0 0 6 6l.94-.94a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16.92z"/></svg>
                            <input type="text" id="telefono" name="telefono"
                                   placeholder="Ej. 3141234567"
                                   inputmode="numeric"
                                   maxlength="10"
                                   oninput="this.value=this.value.replace(/\D/g,'')">
                        </div>
                        <p class="hint">10 d&iacute;gitos, sin espacios ni guiones</p>
                    </div>

                </div>
            </div>
        </div>

        <!-- CARD 2: NOMBRE -->
        <div class="card">
            <div class="card-top">
                <h2>
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Nombre completo
                </h2>
                <p>Tal como aparece en los documentos oficiales</p>
            </div>
            <div class="card-body">
                <div class="form-grid cols3">

                    <div class="fg">
                        <label for="nombres">Nombre(s) <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="nombres" name="nombres"
                                   placeholder="Ej. Ximena Teresa"
                                   style="text-transform:uppercase"
                                   oninput="actualizarPreview()"
                                   required>
                        </div>
                    </div>

                    <div class="fg">
                        <label for="apellido_paterno">Apellido paterno <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="apellido_paterno" name="apellido_paterno"
                                   placeholder="Ej. Flores"
                                   style="text-transform:uppercase"
                                   oninput="actualizarPreview()"
                                   required>
                        </div>
                    </div>

                    <div class="fg">
                        <label for="apellido_materno">Apellido materno <span class="req">*</span></label>
                        <div class="iw">
                            <svg class="icon" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <input type="text" id="apellido_materno" name="apellido_materno"
                                   placeholder="Ej. Ch&aacute;vez"
                                   style="text-transform:uppercase"
                                   oninput="actualizarPreview()"
                                   required>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- FOOTER DEL FORM -->
        <div class="form-footer">
            <a href="dashboard_plantel.php" class="btn-cancel">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Cancelar
            </a>
            <button type="submit" class="btn-submit" id="btn-enviar">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                Registrar persona
            </button>
        </div>

    </form>

    <!-- ─── TABLA DE PERSONAS REGISTRADAS ────────────────────── -->
    <div class="personas-card">
        <div class="personas-header">
            <h3>
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Personas registradas
            </h3>
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                <span class="cnt-badge">6 personas</span>
                <div class="tipo-filters">
                    <button class="tf-chip active" onclick="filtrarTipo(this,'todos')">Todos</button>
                    <button class="tf-chip f-admin"      onclick="filtrarTipo(this,'Administrativo')">Administrativos</button>
                    <button class="tf-chip f-docente"    onclick="filtrarTipo(this,'Docente')">Docentes</button>
                    <button class="tf-chip f-est"        onclick="filtrarTipo(this,'Estudiante')">Estudiantes</button>
                </div>
            </div>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID / No. Cuenta</th>
                        <th>Nombre completo</th>
                        <th>Tipo</th>
                        <th>Correo</th>
                        <th>Tel&eacute;fono</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tabla-personas">

                    <tr data-tipo="Administrativo">
                        <td><span class="td-id">10001</span></td>
                        <td class="td-nombre">Mart&iacute;nez G&oacute;mez Roberto</td>
                        <td><span class="tipo-pill tp-admin"><span class="dot-sm ds-navy"></span>Administrativo</span></td>
                        <td class="td-correo">roberto.martinez@ucol.edu.mx</td>
                        <td style="font-size:.82rem;color:var(--gray-700)">314 123 4567</td>
                        <td><button class="btn-tbl-del" title="Eliminar"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button></td>
                    </tr>

                    <tr data-tipo="Docente">
                        <td><span class="td-id">10045</span></td>
                        <td class="td-nombre">Flores Ch&aacute;vez Ximena Teresa</td>
                        <td><span class="tipo-pill tp-docente"><span class="dot-sm ds-accent"></span>Docente</span></td>
                        <td class="td-correo">ximena.flores@ucol.edu.mx</td>
                        <td style="font-size:.82rem;color:var(--gray-700)">314 987 6543</td>
                        <td><button class="btn-tbl-del" title="Eliminar"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button></td>
                    </tr>

                    <tr data-tipo="Docente">
                        <td><span class="td-id">10078</span></td>
                        <td class="td-nombre">Ramos Herrera Luis Antonio</td>
                        <td><span class="tipo-pill tp-docente"><span class="dot-sm ds-accent"></span>Docente</span></td>
                        <td class="td-correo">luis.ramos@ucol.edu.mx</td>
                        <td style="font-size:.82rem;color:var(--gray-700)">314 555 0011</td>
                        <td><button class="btn-tbl-del" title="Eliminar"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button></td>
                    </tr>

                    <tr data-tipo="Estudiante">
                        <td><span class="td-id">20231113</span></td>
                        <td class="td-nombre">Flores Ch&aacute;vez Ximena Teresa</td>
                        <td><span class="tipo-pill tp-estudiante"><span class="dot-sm ds-green"></span>Estudiante</span></td>
                        <td class="td-correo">ximena.flores23@ucol.edu.mx</td>
                        <td style="font-size:.82rem;color:var(--gray-700)">314 111 2233</td>
                        <td><button class="btn-tbl-del" title="Eliminar"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button></td>
                    </tr>

                    <tr data-tipo="Estudiante">
                        <td><span class="td-id">20231089</span></td>
                        <td class="td-nombre">Mart&iacute;nez L&oacute;pez Carlos Eduardo</td>
                        <td><span class="tipo-pill tp-estudiante"><span class="dot-sm ds-green"></span>Estudiante</span></td>
                        <td class="td-correo">carlos.martinez23@ucol.edu.mx</td>
                        <td style="font-size:.82rem;color:var(--gray-700)">314 444 5566</td>
                        <td><button class="btn-tbl-del" title="Eliminar"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button></td>
                    </tr>

                    <tr data-tipo="Estudiante">
                        <td><span class="td-id">20230754</span></td>
                        <td class="td-nombre">Gonz&aacute;lez P&eacute;rez Ana Sof&iacute;a</td>
                        <td><span class="tipo-pill tp-estudiante"><span class="dot-sm ds-green"></span>Estudiante</span></td>
                        <td class="td-correo">ana.gonzalez23@ucol.edu.mx</td>
                        <td style="font-size:.82rem;color:var(--gray-700)">314 777 8899</td>
                        <td><button class="btn-tbl-del" title="Eliminar"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button></td>
                    </tr>

                </tbody>
            </table>
        </div>
    </div>

</div><!-- /page -->

<footer>&copy; 2026 Universidad de Colima &mdash; Bachillerato 23 | Sistema de Clubes Estudiantiles</footer>

<script>
// ── TIPO DE PERSONA ───────────────────────────────
var tipoActual = "Administrativo";

var config = {
    "Administrativo": {
        avClass:    "pa-admin",
        badgeClass: "pb-admin",
        badgeText:  "Administrativo",
        idLabel:    "Número de trabajador",
        idHint:     "El número de trabajador es único en todo el sistema",
        idPlaceholder: "Ej. 12345",
        infoText:   "Para <strong>Administrativos y Docentes</strong> usa el número de trabajador. Este será su ID de acceso al sistema."
    },
    "Docente": {
        avClass:    "pa-docente",
        badgeClass: "pb-docente",
        badgeText:  "Docente",
        idLabel:    "Número de trabajador",
        idHint:     "El número de trabajador es único en todo el sistema",
        idPlaceholder: "Ej. 10045",
        infoText:   "Para <strong>Administrativos y Docentes</strong> usa el número de trabajador. Este será su ID de acceso al sistema."
    },
    "Estudiante": {
        avClass:    "pa-estudiante",
        badgeClass: "pb-estudiante",
        badgeText:  "Estudiante",
        idLabel:    "Número de cuenta",
        idHint:     "El número de cuenta es el del sistema SICEUC de la Universidad de Colima",
        idPlaceholder: "Ej. 20231113",
        infoText:   "Para <strong>Estudiantes</strong> usa el número de cuenta del sistema <strong>SICEUC</strong> de la Universidad de Colima."
    }
};

function selTipo(card, tipo) {
    // Deseleccionar todas
    document.querySelectorAll(".tipo-card").forEach(c => c.classList.remove("selected"));
    card.classList.add("selected");
    card.querySelector("input[type=radio]").checked = true;

    tipoActual = tipo;
    document.getElementById("input-tipo").value = tipo;

    // Actualizar etiqueta e hint del ID
    var cfg = config[tipo];
    document.getElementById("lbl-id").innerHTML = cfg.idLabel + " <span class='req'>*</span>";
    document.getElementById("hint-id").textContent = cfg.idHint;
    document.getElementById("id_persona").placeholder = cfg.idPlaceholder;
    document.getElementById("info-id").innerHTML =
        "<svg width='14' height='14' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg>" +
        cfg.infoText;

    // Preview badge y avatar
    actualizarPreview();
}

// ── PREVIEW EN TIEMPO REAL ────────────────────────
function actualizarPreview() {
    var ap  = (document.getElementById("apellido_paterno").value || "").toUpperCase();
    var am  = (document.getElementById("apellido_materno").value || "").toUpperCase();
    var nom = (document.getElementById("nombres").value || "").toUpperCase();
    var id  = document.getElementById("id_persona").value.trim();
    var correo = document.getElementById("correo").value.trim();

    // Nombre preview
    var nombreCompleto = [ap, am, nom].filter(Boolean).join(" ");
    document.getElementById("preview-name").textContent =
        nombreCompleto || "Escribe el nombre para previsualizar";

    // Iniciales
    var iniciales = ((ap[0] || "") + (nom[0] || "")).toUpperCase() || "?";
    var av = document.getElementById("preview-av");
    av.textContent = iniciales;
    av.className   = "preview-avatar " + config[tipoActual].avClass;

    // Badge
    var badge = document.getElementById("preview-badge");
    badge.textContent = config[tipoActual].badgeText;
    badge.className   = "preview-badge " + config[tipoActual].badgeClass;

    // ID chip
    var idChip = document.getElementById("preview-id-chip");
    if (id) { document.getElementById("preview-id").textContent = id; idChip.style.display = "inline-flex"; }
    else { idChip.style.display = "none"; }

    // Correo chip
    var correoChip = document.getElementById("preview-correo-chip");
    if (correo) { document.getElementById("preview-correo").textContent = correo; correoChip.style.display = "inline-flex"; }
    else { correoChip.style.display = "none"; }
}

// Solo numeros en telefono e ID
document.getElementById("id_persona").addEventListener("input", function() {
    this.value = this.value.replace(/\D/g, "");
});

// ── FILTRO TABLA ──────────────────────────────────
function filtrarTipo(btn, tipo) {
    document.querySelectorAll(".tf-chip").forEach(c => c.classList.remove("active"));
    btn.classList.add("active");
    document.querySelectorAll("#tabla-personas tr").forEach(row => {
        row.style.display = (tipo === "todos" || row.dataset.tipo === tipo) ? "" : "none";
    });
}
</script>

</body>
</html>