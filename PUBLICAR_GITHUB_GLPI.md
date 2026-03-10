# Cómo publicar CodexRoute: GitHub + GLPI Plugins

## 1. Actualizar tus URLs en GLPI Plugins

1. Entra en **plugins.glpi-project.org** e inicia sesión (usuario: **nexora-innova**).
2. Ve a **My informations** (tu perfil).
3. En el campo **Website** escribe la URL que quieras mostrar (por ejemplo tu web o el repo del plugin):
   - Ejemplo: `https://github.com/nexora-innova/codexroute`
4. Haz clic en **UPDATE** para guardar.
5. En **Actions**, haz clic en **MANAGE MY EXTERNAL SOCIAL ACCOUNTS** y vincula tu cuenta de **GitHub** si aún no lo has hecho (así GLPI puede asociar el plugin a tu perfil).

---

## 2. Subir el plugin a GitHub

### 2.1 Crear el repositorio en GitHub

1. En GitHub, haz clic en **Create repository** (o **Create project** en “Getting started”).
2. Configura:
   - **Repository name:** `codexroute`
   - **Description:** (opcional) “Plugin de seguridad y rendimiento para GLPI”
   - **Visibility:** Public
   - No marques “Add a README” (ya tienes uno en el plugin).
3. Clic en **Create repository**.

### 2.2 Subir el código desde tu PC

Abre una terminal en la **carpeta del plugin** (donde están `setup.php`, `plugin.xml`, etc.):

```bash
cd "d:\Desarrollo\backend\php\Php\2205glpi\migracionglpi\pruebas\glpi\plugins\codexroute"
```

Si es la primera vez que usas Git en este proyecto:

```bash
git init
git add .
git commit -m "Versión inicial 1.0.0 - CodexRoute"
git branch -M main
git remote add origin https://github.com/TU_USUARIO/codexroute.git
git push -u origin main
```

Sustituye **TU_USUARIO** por tu usuario de GitHub (por ejemplo `nexora-innova`).

Si ya tienes Git instalado con credenciales, el `git push` subirá todo. Si GitHub pide autenticación, usa un **Personal Access Token** (Settings → Developer settings → Personal access tokens) en lugar de la contraseña.

### 2.3 Crear la release 1.0.0

1. En el repo de GitHub, ve a **Releases** → **Create a new release**.
2. **Tag:** `1.0.0` (crear el tag desde ahí).
3. **Title:** por ejemplo `v1.0.0`.
4. En “Attach binaries” sube el archivo del plugin:
   - Comprime la carpeta `codexroute` (solo su contenido o la carpeta entera, según prefieras) en **codexroute-1.0.0.tar.gz** o **codexroute-1.0.0.zip**.
   - La URL de descarga que obtendrás será algo como:  
     `https://github.com/nexora-innova/codexroute/releases/download/1.0.0/codexroute-1.0.0.tar.gz`
5. Publica la release.

---

## 3. Poner las URLs reales en plugin.xml

En `plugin.xml` sustituye **TU_USUARIO** por tu usuario de GitHub (ej. `nexora-innova`) y la URL de descarga por la de tu release:

```xml
<homepage>https://github.com/nexora-innova/codexroute</homepage>
<download>https://github.com/nexora-innova/codexroute/releases</download>
<issues>https://github.com/nexora-innova/codexroute/issues</issues>
<readme>https://github.com/nexora-innova/codexroute/blob/main/README.md</readme>
<download_url>https://github.com/nexora-innova/codexroute/releases/download/1.0.0/codexroute-1.0.0.tar.gz</download_url>
```

Guarda, haz commit y push para que el `plugin.xml` actualizado esté en GitHub:

```bash
git add plugin.xml
git commit -m "Actualizar URLs para publicación"
git push
```

---

## 4. Solicitar la publicación en GLPI Plugins

1. Entra en **plugins.glpi-project.org**.
2. En el menú lateral derecho haz clic en **Submit a plugin**.
3. Completa el formulario con:
   - **Repository URL:** `https://github.com/nexora-innova/codexroute`
   - Nombre, descripción, compatibilidad (ya vienen en tu `plugin.xml`).
4. Envía la solicitud.

El equipo de GLPI revisará el plugin y, si cumple sus criterios, lo publicarán en el directorio. Pueden pedirte cambios; en ese caso corriges en el repo y, si hace falta, una nueva release.

---

## Resumen rápido

| Paso | Dónde | Acción |
|------|--------|--------|
| 1 | GLPI Plugins → My informations | Rellenar **Website** y **UPDATE**; vincular GitHub en **MANAGE MY EXTERNAL SOCIAL ACCOUNTS** |
| 2 | GitHub | **Create repository** → nombre `codexroute`, público |
| 3 | PC (carpeta del plugin) | `git init`, `git add .`, `git commit`, `git remote add origin`, `git push` |
| 4 | GitHub → Releases | **Create release** tag `1.0.0`, adjuntar `.tar.gz` o `.zip` del plugin |
| 5 | plugin.xml | Sustituir TU_USUARIO y `download_url` por tus URLs reales → commit y push |
| 6 | GLPI Plugins | **Submit a plugin** → URL del repo y datos del plugin |

Cuando tengas tu usuario real de GitHub (si no es nexora-innova), sustituye `nexora-innova` por ese usuario en las URLs y en los comandos.
