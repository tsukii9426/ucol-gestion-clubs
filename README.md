# Sistema de Gestión de Clubes Estudiantiles

Plataforma web para administrar los clubes estudiantiles de los planteles del
Bachillerato. Permite a los alumnos inscribirse a clubes, a los encargados
gestionar sus clubes y tomar asistencia por código QR, y a cada plantel
administrar las solicitudes y estadísticas.

---

## Características

- **Inscripción a clubes** — los alumnos se registran con su número de cuenta y
  pueden inscribirse a **más de un club**.
- **Asistencia por QR** — los encargados registran entrada y salida escaneando el
  código del alumno (cámara o lector USB), con validación de día y horario.
- **Gestión por roles** — alumnos, encargados y planteles, cada uno con su panel.
- **Solicitudes de encargado** — alta de encargados con aprobación por el plantel.
- **Estadísticas e historial** — por club y por plantel.
- **Correos automáticos** — confirmaciones y recuperación de contraseña vía SMTP.
- **Despliegue con Docker** — instalación reproducible en cualquier computadora.

---

## Roles

| Rol | Acceso | Funciones |
|---|---|---|
| **Alumno** | `login.php` | Inscribirse a clubes, ver sus clubes, horarios y asistencias |
| **Encargado** | `solicitud_encargado.php` (alta) | Crear/editar clubes, tomar asistencia QR, ver estadísticas |
| **Plantel** | `ucol-srvc-coord.php` | Aprobar encargados, ver estadísticas e historial del plantel |

> El login del plantel usa un nombre de URL no obvio (`ucol-srvc-coord.php`) como
> medida de seguridad básica.

---

## Tecnologías

- **PHP 8.2** (sin framework) sobre **Apache**
- **MySQL 8.0**
- **PDO** para acceso a datos
- **html5-qrcode** para el escaneo de QR en el navegador
- **Docker + Docker Compose** para el despliegue

---

## Instalación

La guía completa de instalación desde cero está en **[INSTALACION.md](INSTALACION.md)**.

Resumen rápido (con Docker):

```bash
git clone https://github.com/tsukii9426/ucol-gestion-clubs.git
cd ucol-gestion-clubs/docker
cp .env.example .env      # editar contraseñas
docker compose up -d --build
```

Luego abre `http://localhost:8082` y crea el primer plantel en
`http://localhost:8082/setup_plantel.php`.

### Puertos

| Servicio | URL |
|---|---|
| Sistema (alumnos) | `http://localhost:8082` |
| phpMyAdmin | `http://localhost:8083` |
| MySQL (host) | `localhost:3308` |

---

## Estructura del proyecto

```
gestion_clubs/
├── login.php                 # Login / registro de alumnos
├── dashboard_alumno.php      # Panel del alumno
├── mis_clubes.php            # Clubes del encargado + asistencia QR
├── asistencias.php           # Toma de asistencia por QR
├── procesar_asistencia.php   # Endpoint JSON de asistencia
├── confirmar_registro.php    # Confirmación de inscripción a club
├── ucol-srvc-coord.php       # Login del plantel (URL de seguridad)
├── dashboard_plantel.php     # Panel del plantel
├── estadisticas_plantel.php  # Estadísticas del plantel
├── estadisticas_historial.php
├── historial_clubs.php
├── solicitud_encargado.php   # Alta de encargados
├── dashboard_encargado.php   # Panel del encargado
├── registrar_club.php        # Crear club
├── editar_club.php           # Editar club
├── setup_plantel.php         # Configuración inicial de planteles (solo local)
├── db.php                    # Conexión PDO (getDB)
├── config.php                # Credenciales (NO se versiona)
├── base_de_datos.sql         # Esquema completo de la base de datos
├── INSTALACION.md            # Guía de instalación
└── docker/                   # Configuración de Docker
    ├── docker-compose.yml
    ├── Dockerfile
    ├── config.php            # config.php para Docker (lee variables de entorno)
    ├── .env.example
    └── apache/000-default.conf
```

---

## Base de datos

El esquema completo está en [base_de_datos.sql](base_de_datos.sql). Tablas principales:

- `planteles`, `personas`, `encargados`
- `clubes`, `horarios`
- `estudiantes`, `inscripciones_club` *(soporte multi-club)*
- `asistencias_club`, `auxiliares_club`
- `solicitudes_encargado`, `tokens_pendientes`

En Docker, este esquema se carga automáticamente la primera vez que arranca MySQL.

---

## Notas de seguridad

- `config.php` (con las credenciales reales) **no se versiona** — está en `.gitignore`.
- `setup_plantel.php` solo debe usarse durante la configuración inicial; **elimínalo
  después** de crear los planteles.
- Las contraseñas se guardan con `password_hash` (bcrypt).
