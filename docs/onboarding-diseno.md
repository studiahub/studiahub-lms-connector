# 🎨 Onboarding diseño — Landing de cursos StudiaHub

> Bienvenida 👋. Este documento es para que puedas levantar el ambiente en tu computadora, ver la landing del curso en vivo, y empezar a iterar el diseño usando Claude Code. Está pensado para que **no necesites saber programación** — vamos a usar Claude Code como puente entre vos y el código.

**Objetivo concreto:** que iteres el diseño del shortcode `[studiahub_course_pitch]` (la landing "estilo DTC") hasta dejarla como una plantilla definitiva para usarla en todos los clientes.

---

## 📋 Lo que vas a tener al final

- Un WordPress local corriendo en `http://localhost:8080` con el plugin StudiaHub instalado.
- La landing del curso de prueba renderizada con data realista (instructores, bonos, FAQ, reseñas).
- Claude Code abierto en tu carpeta del plugin para pedirle cambios visuales en lenguaje natural.
- Todo desconectado del LMS real — trabajás contra un mock JSON local. Cero riesgo de afectar clientes reales.

---

## 🛠 Parte 1 — Instalación inicial (una sola vez, ~30 min)

### Paso 1.1 — Instalar las apps base

Si no las tenés, instalá en este orden:

1. **Docker Desktop** (corre WordPress sin instalar nada manual)
   - Mac: https://www.docker.com/products/docker-desktop/
   - Después de instalarlo, ábralo y dejalo corriendo en background (vas a ver una ballenita 🐳 arriba a la derecha).

2. **Git** (descarga repos desde GitHub)
   - Mac: viene preinstalado. Para chequear, abrí Terminal y escribí `git --version`. Si responde con un número, está OK.
   - Si no: https://git-scm.com/download/mac

3. **GitHub Desktop** (para no pelearte con la línea de comandos de git)
   - https://desktop.github.com/

4. **Visual Studio Code** (para ver/editar archivos cuando haga falta)
   - https://code.visualstudio.com/

5. **Claude Code** (el asistente que va a hacer los cambios por vos)
   - https://docs.claude.com/claude-code/quickstart
   - Vas a usarlo con tu cuenta de Anthropic. Si no tenés, hablalo con Gon.

### Paso 1.2 — Hacer fork del repo en GitHub

Un "fork" es **tu propia copia** del repo. Vos podés cambiar lo que quieras en tu fork sin afectar el original. Cuando estés conforme, le mandás un "Pull Request" a Gon y él lo integra al repo principal.

1. Andá a: https://github.com/studiahub/studiahub-lms-connector
2. Arriba a la derecha, click en el botón **"Fork"**.
3. En la página que abre, dejá todo por default y dale **"Create fork"**.
4. Vas a quedar en tu propia copia (la URL va a decir `github.com/TU-USUARIO/studiahub-lms-connector`).

### Paso 1.3 — Clonar tu fork a tu computadora

Con GitHub Desktop:

1. Abrí GitHub Desktop. Si te pide loguearte, hacelo con tu cuenta.
2. Menú **File → Clone repository**.
3. Elegí tu fork de la lista (`TU-USUARIO/studiahub-lms-connector`).
4. **Local path:** algo como `~/Dev/studiahub-lms-connector` (es donde se va a descargar en tu Mac).
5. Click **Clone**.

Esperá que termine (1-2 min). Cuando termine vas a tener la carpeta en tu Mac con todos los archivos.

### Paso 1.4 — Levantar el ambiente

1. Abrí **Terminal** (Cmd+Espacio, escribís "terminal", enter).
2. Andá a la carpeta del repo:
   ```bash
   cd ~/Dev/studiahub-lms-connector
   ```
   (Cambia esa ruta por donde lo hayas clonado).

3. Levantá todo con un comando:
   ```bash
   make setup
   ```
   Esto:
   - Descarga las imágenes de WordPress + MySQL (la primera vez tarda 5-10 min).
   - Las arranca.
   - Instala WordPress automáticamente con usuario `admin` / pass `admin`.
   - Activa el plugin StudiaHub LMS Connector.
   - Crea el producto de prueba (id=59).

4. Cuando termine, vas a ver algo así:
   ```
   ✓ Listo. Probá ahora:
     Admin WP:     http://localhost:8080/wp-admin (user: admin / pass: admin)
     Landing V1:   http://localhost:8080/?slc_test_render=1&id=59&variant=page
     Landing V2:   http://localhost:8080/?slc_test_render=1&id=59&variant=pitch
   ```

5. Abrí el link de **Landing V2** en el browser. Tenés que ver la landing renderizada.

> **⚠️ Si algo falla:** Sacale captura al Terminal y mandasela a Gon. Casi todos los errores son problemas de Docker arrancando lento o de puertos ocupados.

---

## 🎯 Parte 2 — Empezar a diseñar

### Paso 2.1 — Abrir Claude Code en la carpeta del plugin

1. Abrí **Terminal** otra vez (o usá el mismo).
2. Andá a la carpeta:
   ```bash
   cd ~/Dev/studiahub-lms-connector
   ```
3. Arrancá Claude Code:
   ```bash
   claude
   ```
4. Lo primero que te conviene preguntarle:
   ```
   ¿Podés leer docs/guia-secciones-pitch.md y darme un resumen
   de qué sección está donde?
   ```
   Eso le da contexto del proyecto.

### Paso 2.2 — Workflow de cambios

El flow es así:

1. **Tenés una idea visual** ("quiero que el título del hero sea más grande y morado").
2. **Le pedís a Claude Code** en lenguaje natural:
   ```
   Hacé el título principal del hero gigante (la sección
   "hero--big" de la landing pitch) un 20% más grande y
   cambialo a un morado más vibrante.
   ```
3. Claude Code edita los archivos. Te muestra qué cambió.
4. **Recargás el browser** con `Cmd+Shift+R` (recarga sin cache) en la URL de la landing V2.
5. Ves el resultado. Si te gusta, seguís. Si no, le pedís ajustes:
   ```
   El morado quedó muy fuerte, hacelo más pastel.
   ```

### Paso 2.3 — Cuando cambies el contenido de prueba (opcional)

El contenido del curso (textos, instructores, bonos, etc.) lo lee desde el archivo `.docker/dev-mock/payload.json`. Si querés probar cómo se ve con otro texto:

1. Abrí el archivo en VS Code: `Open → ~/Dev/studiahub-lms-connector/.docker/dev-mock/payload.json`
2. Cambiá lo que quieras (ej. el `title`, agregar más bonos, sacar reseñas).
3. Recargá el browser con `Cmd+Shift+R`. Aparece al toque.

Lo importante: **los textos de ese JSON son data fake**. En clientes reales, todo eso viene del LMS y lo carga el dueño de la academia. Vos NO diseñás los textos, vos diseñás **cómo se ven**.

---

## 🧭 Parte 3 — Entender qué es contenido y qué es diseño

Esto es **lo más importante** para no romper nada y para que el diseño funcione con cualquier curso de cualquier cliente.

### 📥 CONTENIDO (NO TOCAR — viene del LMS)

Todo lo que ves como **texto, imágenes, números, listas** en la landing **viene del LMS** y lo carga cada dueño de academia desde su panel admin. Vos no lo modificás. Ejemplos:

- Título del curso ("Marketing Digital Avanzado")
- Subtítulo, descripción larga, descripción corta
- Precio, precio tachado, label de cuotas, deadline
- Lista de "Lo que vas a aprender"
- Lista de "A quién está dirigido"
- Módulos y lecciones del temario
- Instructores (nombre, foto, cargo, bio)
- Bonos incluidos
- Preguntas frecuentes (FAQ)
- Reseñas reales de alumnos
- Garantía
- Logo del cliente, colores primarios, tipografía (esto viene de la "Configuración" del cliente)

**Si querés probar variantes** (ej. ver cómo queda con 1 solo bono, o sin instructor, o sin garantía), editá el `.docker/dev-mock/payload.json` y recargá.

### 🎨 DISEÑO (SÍ se puede tocar)

Lo que vos vas a modificar son **dos archivos principales**:

1. **CSS del shortcode V2** → controla todo lo visual.
   - Path: `plugin/studiahub-lms-connector/assets/css/coursepitch.css`
   - Ejemplos: colores, tamaños de texto, espaciados, sombras, bordes, transiciones, layouts.

2. **HTML estructural del shortcode V2** (con cuidado) → controla qué bloques aparecen y en qué orden.
   - Path: `plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php`
   - Ejemplos: cambiar el orden de las secciones, mover una columna de lugar, agregar/quitar wrappers HTML.
   - **Cuidado:** acá hay código PHP mezclado con HTML. Pedile a Claude Code que haga estos cambios — no edites a mano si no sabés.

### 🚫 Cosas que NO toques

- **V1** (`coursepage.css` y `class-shortcode-coursepage.php`): es la otra variante de la landing, está congelada para esta iteración. Si la tocás se rompe la otra cosa.
- **`includes/class-landing-fetch.php`** y otros archivos del backend del plugin: lógica de cómo trae los datos.
- **`.docker/`** (excepto `dev-mock/payload.json`): configuración del ambiente.

---

## 🔄 Parte 4 — Subir tu trabajo a GitHub

Cuando termines una iteración de cambios y querés que Gon los integre:

1. Abrí **GitHub Desktop**.
2. Vas a ver todos los archivos que cambiaste en la columna izquierda.
3. Abajo escribís un **summary** corto del cambio (ej. "Hero más grande + colores morados pastel").
4. Click **"Commit to main"**.
5. Arriba a la derecha, click **"Push origin"** (sube tus cambios a tu fork).
6. En el browser, andá a tu fork en GitHub. Vas a ver un botón **"Contribute → Open pull request"**. Click ahí.
7. Llená título + descripción y dale **"Create pull request"**.
8. Avisale a Gon que hiciste el PR.

---

## 🆘 Comandos útiles del día a día

Todos se corren en la Terminal, dentro de la carpeta del repo (`cd ~/Dev/studiahub-lms-connector`):

| Comando | Qué hace |
|---|---|
| `make up` | Arranca WP (cuando volvés a trabajar después de apagar la compu) |
| `make down` | Apaga WP (al terminar el día — libera memoria) |
| `make refresh` | Bustea el cache cuando recargás la landing y no ves los cambios |
| `make logs` | Muestra logs en vivo si algo se rompe (Ctrl+C para salir) |
| `make help` | Lista todos los comandos disponibles |

---

## 🤝 Cómo pedirle cosas a Claude Code

Algunos ejemplos de prompts buenos:

✅ **Específicos y visuales:**
- "El padding de las cards de bonos es muy chico, ponelo en 32px y agregale un borde gris claro de 1px"
- "Hacé que el botón principal (CTA grande) tenga una sombra más marcada cuando le pasás el mouse por encima"
- "Las imágenes de instructores se ven aplastadas en mobile, ajustalas"

✅ **Pedidos de exploración:**
- "Mostrame todas las clases CSS que usa la sección de FAQ"
- "¿Dónde está definida la tipografía principal de la landing pitch?"

❌ **Vagos (vas a tener que iterar mucho):**
- "Hacelo más lindo"
- "Cambiá el diseño"

❌ **De contenido (no es tu rol):**
- "Cambiá el título del curso a 'Curso XYZ'" → eso lo carga el cliente desde su admin del LMS
- "Agregá un instructor más" → idem

Cuando dudes, pregúntale a Claude Code:
```
¿Esto que quiero modificar es algo de diseño (CSS/HTML del plugin)
o es contenido que viene del LMS?
```

Te va a guiar.

---

## 📚 Más documentos

- **`docs/guia-secciones-pitch.md`** — Mapa visual de cada sección de la landing V2 con su clase CSS y qué datos del LMS muestra.

---

## ❓ FAQ

**¿Qué pasa si Docker se cuelga?**
Cerrá Docker Desktop completamente (menú de la ballenita → Quit) y abrilo de nuevo. Después corré `make up` otra vez.

**¿Por qué los cambios no aparecen al recargar?**
1. Verificá que estás editando los archivos correctos (`coursepitch.css`, no `coursepage.css`).
2. Hace recarga con cache busted: `Cmd+Shift+R` (no solo `Cmd+R`).
3. Si seguís sin verlos: `make refresh` y recargá de nuevo.

**¿Puedo trabajar offline?**
Sí, una vez que `make setup` corrió, no necesitás internet. Lo único online es Claude Code (necesita conexión).

**¿Cómo veo el HTML real que se renderiza?**
En el browser: click derecho → "Inspeccionar" (o `Cmd+Option+I`). Te abre las dev tools. Ahí ves el HTML, los estilos aplicados y podés probar cambios temporales antes de pedírselos a Claude Code.

**¿Y si rompo algo y no se recupera?**
Tranqui. Abrí GitHub Desktop. Click derecho en cada archivo modificado de la lista → "Discard Changes". Vuelve al estado anterior. Si rompiste muchas cosas: en Terminal, `git reset --hard origin/main` reinicia todo al último commit del repo.

**¿Cómo le pido ayuda a Gon?**
Cualquier duda visual o "esto no sé si tocarlo" → mandale captura por WhatsApp/Slack. Cualquier error técnico → captura del Terminal + descripción de qué estabas haciendo.

---

Cuando vayas avanzando, este documento se va a ir actualizando con lo que aprendamos en el camino. Buen viaje 🚀
