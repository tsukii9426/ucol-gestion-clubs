# Instalación — Sistema de Gestión de Clubes

Guía para instalar el sistema **desde cero** en una computadora nueva usando Docker.

---

## 1. Instalar los programas necesarios

- **Docker Desktop** → https://www.docker.com/products/docker-desktop
  Instálalo, ábrelo y espera a que aparezca el ícono de la ballena 🐳 en la barra de tareas.
- **Git** → https://git-scm.com/download/win
  En la instalación, en la opción del PATH elige **"Git from the command line and also from 3rd-party software"**.

> Después de instalar Git, **cierra y vuelve a abrir** la terminal (CMD) para que reconozca el comando.

---

## 2. Clonar el proyecto

```cmd
git clone https://github.com/tsukii9426/ucol-gestion-clubs.git
cd ucol-gestion-clubs\docker
```

---

## 3. Crear el archivo de configuración

```cmd
copy .env.example .env
```

Abre el archivo `.env` con el Bloc de notas y cambia **solo las contraseñas**
(usa contraseñas **sin el símbolo `$`** para evitar errores):

```env
DB_PASS=MiClubSeguro2024
DB_ROOT_PASS=RootSeguro2024
```

Lo demás (`DB_NAME=bachilleratos`, etc.) déjalo como está.

---

## 4. Levantar el sistema

```cmd
docker compose up -d --build
```

La **primera vez tarda varios minutos** porque descarga las imágenes y construye todo.

> Si marca `db unhealthy` (común en computadoras lentas), espera un minuto y vuelve a ejecutar:
> ```cmd
> docker compose up -d
> ```

Verifica que los 3 contenedores estén arriba:

```cmd
docker compose ps
```

Deben aparecer `gestion_clubs_db`, `gestion_clubs_web` y `gestion_clubs_pma` en estado **Up**.

---

## 5. Averiguar la IP de esta computadora

```cmd
ipconfig
```

Busca la línea **"Dirección IPv4"** (ejemplo: `192.168.1.85`).
Esa es la dirección con la que otros dispositivos accederán. **Cada computadora tiene su propia IP.**

---

## 6. Crear el primer plantel

En el navegador de **esta misma computadora**, abre:

```
http://localhost:8080/setup_plantel.php
```

Ahí puedes:
- **Agregar un nuevo plantel** (nombre, usuario, correo y contraseña)
- Establecer contraseñas de acceso al panel
- Configurar el correo SMTP de cada plantel

Con ese usuario y contraseña, el plantel inicia sesión en `ucol-srvc-coord.php`
para gestionar las solicitudes de encargados.

> ⚠️ **Seguridad:** cuando termines de crear los planteles, elimina el archivo de setup
> para que nadie más en la red lo use:
> ```cmd
> docker compose exec web rm /var/www/html/setup_plantel.php
> ```

---

## 7. Acceder desde otros dispositivos

Cualquier celular o computadora **en la misma red WiFi/LAN** entra con la IP de esta máquina:

```
http://192.168.1.85:8080
```

(Reemplaza `192.168.1.85` por la IP real que obtuviste en el paso 5.)

> Si otro dispositivo no puede conectarse, revisa que el **Firewall de Windows**
> permita el acceso de Docker a la red (la primera vez Windows lo pregunta → dale **Permitir**).

---

## Direcciones útiles

| Servicio | Dirección |
|---|---|
| Sistema (alumnos) | `http://localhost:8080` |
| Login de plantel | `http://localhost:8080/ucol-srvc-coord.php` |
| Setup de planteles | `http://localhost:8080/setup_plantel.php` *(borrar tras usarlo)* |
| phpMyAdmin (base de datos) | `http://localhost:8081` |

---

## Comandos útiles del día a día

```cmd
docker compose up -d        # Encender el sistema
docker compose down         # Apagar (los datos se conservan)
docker compose down -v      # Apagar y BORRAR la base de datos (empezar de cero)
docker compose ps           # Ver estado de los contenedores
docker compose logs web     # Ver errores del sitio
docker compose logs db      # Ver errores de la base de datos
```

---

## Notas

- El correo (`BASE_URL`) solo se usa para los enlaces de recuperación de contraseña.
  Si los vas a usar, en `.env` pon la IP de la máquina:
  `BASE_URL=http://192.168.1.85:8080`
- Esto funciona en **red local** (misma WiFi). Para acceso por internet se necesitaría
  un servidor en la nube o configurar el router.
