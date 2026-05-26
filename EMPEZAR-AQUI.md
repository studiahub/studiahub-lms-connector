# 🚀 Empezar acá — Setup completo para diseño

> **Si sos Nadi (diseño):** este documento te guía paso a paso. Vas a leer la **Parte A** y después le pegás a Claude Code el prompt que dice ahí. El asistente sigue solo a partir de ese momento.
>
> **Si sos Claude Code:** saltá directo a la **Parte B**. Tu trabajo arranca cuando Nadi te pega el prompt de la Parte A.

---

# Parte A — Lo que hace Nadi (15-20 min)

## 0. Antes que nada

Estás por levantar un WordPress local en tu Mac para iterar visualmente el diseño de una landing page. Todo corre en tu computadora — no toca clientes reales. Cuando termines un cambio, lo subís a GitHub y Gon lo revisa antes de mergearlo.

**No necesitás saber programar.** Vas a usar Claude Code como puente: le pedís cosas en español, él hace los cambios.

## 1. Instalá estas apps (10 min)

En este orden:

### a) Docker Desktop
👉 https://www.docker.com/products/docker-desktop/

- Descargá la versión para Mac (elegí Apple Silicon si tu Mac es M1/M2/M3, Intel si es más vieja).
- Instalá normal (arrastrá a Applications).
- Abrí la app. Aceptá los términos. Va a aparecer una **ballenita 🐳 en la barra superior** — eso significa que Docker está corriendo.
- **Dejala corriendo en background.** Cada vez que prendas la Mac y quieras trabajar, abrí Docker Desktop primero.

### b) Git
👉 Para chequear si ya lo tenés, abrí **Terminal** (Cmd+Espacio, escribís "terminal", Enter) y tipeá:
```
git --version
```
Si responde con un número de versión (ej. `git version 2.39.0`), ya está.
Si no, te va a abrir un instalador automático — aceptá.

### c) GitHub Desktop
👉 https://desktop.github.com/

- Instalá normal.
- Cuando abra, **logueate con tu cuenta de GitHub**. (Si no tenés cuenta de GitHub, creala primero en https://github.com/signup — gratis).

### d) Visual Studio Code
👉 https://code.visualstudio.com/

- Instalá normal. Es donde vas a poder mirar/editar archivos cuando haga falta.

### e) Claude Code
👉 https://docs.claude.com/claude-code/quickstart

- Seguí las instrucciones de la página para Mac.
- Vas a necesitar una cuenta de Anthropic con créditos. Si no tenés, hablalo con Gon **antes** de avanzar.

## 2. Hacé fork del repo del plugin (2 min)

Un **"fork"** es tu copia personal del proyecto. Vos modificás tu copia sin afectar la original.

1. Abrí en el browser: 👉 https://github.com/studiahub/studiahub-lms-connector
2. Arriba a la derecha, click en el botón **"Fork"**.
3. Dejá todo por default y click **"Create fork"**.
4. Te lleva a tu propia copia. La URL ahora dice `github.com/TU-USUARIO/studiahub-lms-connector` — fijate y copiala mentalmente.

## 3. Cloná tu fork a tu Mac (2 min)

Con GitHub Desktop:

1. Abrí **GitHub Desktop**.
2. Menú **File → Clone repository**.
3. En la pestaña "**Your repositories**" elegí `TU-USUARIO/studiahub-lms-connector` (tu fork).
4. **Local Path:** dejá lo que sugiere o cambialo a `Documents/studiahub-lms-connector` (o donde prefieras).
5. Click **Clone**. Esperá 30 segundos.

## 4. Abrí Claude Code en la carpeta del proyecto

1. Abrí **Terminal**.
2. Andá a la carpeta. Si la clonaste en `Documents/`, escribí:
   ```
   cd ~/Documents/studiahub-lms-connector
   ```
   Si fue en otro lado, ajustá la ruta.
3. Arrancá Claude Code:
   ```
   claude
   ```
4. Pegá este prompt y dale Enter:

> Hola! Soy Nadi, diseñadora. Vengo a trabajar en la landing del plugin StudiaHub LMS Connector. Por favor leé el archivo `EMPEZAR-AQUI.md` en el root del repo y seguí la **Parte B** para guiarme con el setup completo paso a paso. No soy técnica, explicame todo en lenguaje simple y verificá cada paso antes de avanzar al siguiente.

5. **Esperá.** El agente va a leer el doc, verificar tu sistema, y guiarte paso a paso. A partir de acá vos solo respondés lo que él te pregunta y aceptás los comandos que te propone.

---

# Parte B — Instrucciones para Claude Code (asistente de Nadi)

> Hola agente. Sos un asistente que ayuda a **Nadi** (diseñadora, **no-técnica**) a hacer el setup completo del entorno de desarrollo local del plugin **studiahub-lms-connector**. Este documento es tu playbook. Seguilo en orden estricto.

## Reglas de comunicación con Nadi

1. **Español rioplatense, casual, paciente.** Cero jerga técnica sin explicar. Si tenés que usar una palabra técnica (ej. "container", "puerto"), explicala en una línea.
2. **Antes de cada paso**, decile qué vas a hacer y por qué (en una oración corta).
3. **Después de cada comando**, verificá que funcionó antes de seguir.
4. **Si algo falla**, no intentes 3 fixes — explicale a Nadi qué pasó y proponele que le saque captura al Terminal y se la mande a Gon. Mejor parar que cagarla.
5. **Nunca corras comandos destructivos** sin avisarle (ej. `rm`, `git reset --hard`, `docker compose down -v`).
6. **No le pidas que ejecute comandos a mano** — vos los ejecutás con la herramienta Bash. Ella solo aprueba.

## Playbook del setup (seguir en orden)

### Paso 1 — Verificá las instalaciones base

Decí a Nadi: "Voy a chequear que tengas todo lo necesario instalado."

Corré estos checks en paralelo (usá Bash con múltiples llamadas):

```bash
docker --version
docker compose version
git --version
which code
```

Y verificá que Docker Desktop esté **corriendo** (no solo instalado):
```bash
docker info 2>&1 | head -3
```

**Si todo OK** → "Perfecto, tenés todo. Voy al siguiente paso."

**Si falta algo**:
- **Docker no instalado**: pedile que descargue desde https://www.docker.com/products/docker-desktop/ y avise cuando esté listo.
- **Docker no corriendo**: pedile que abra Docker Desktop (la app) y espere a ver la ballenita arriba.
- **Git no instalado**: explicale que escriba `git --version` en Terminal y le va a abrir el instalador.
- **VS Code no instalado**: link https://code.visualstudio.com/ (no urgente, puede seguir sin esto).

NO avances al paso 2 hasta que Docker esté corriendo.

### Paso 2 — Verificá ubicación y estructura del repo

Asegurate de estar dentro de la carpeta correcta del repo clonado:

```bash
pwd
ls -la
```

Tiene que haber un `Makefile`, una carpeta `.docker/`, una carpeta `plugin/`, una carpeta `docs/`, y este mismo archivo `EMPEZAR-AQUI.md`.

**Si NO está en la carpeta correcta**: pedile a Nadi que confirme dónde clonó el repo y guiarla a hacer `cd` ahí.

### Paso 3 — Levantá el ambiente con `make setup`

Decí: "Ahora voy a levantar WordPress + base de datos en tu Mac. La primera vez tarda 5-10 minutos porque tiene que descargar las imágenes de Docker. Vas a ver mucho texto pasar, es normal."

Ejecutá:
```bash
make setup
```

**Mirá el output**. Tiene que terminar con un mensaje que muestra:
```
✓ Listo. Probá ahora:
  Admin WP:     http://localhost:8080/wp-admin (user: admin / pass: admin)
  Landing V1:   http://localhost:8080/?slc_test_render=1&id=59&variant=page
  Landing V2:   http://localhost:8080/?slc_test_render=1&id=59&variant=pitch
```

**Si terminó OK** → seguí al paso 4.

**Si falló**:
- **Puerto 8080 ocupado**: explicale que probablemente tiene otro WP / Apache corriendo. Pedile captura del error y avisar a Gon.
- **Docker dio error de red**: pedile que verifique que Docker Desktop está corriendo.
- **Cualquier otro error**: captura al Terminal completo + avisar a Gon.

### Paso 4 — Verificá que la landing funcione

Ejecutá un check rápido al endpoint:
```bash
curl -s "http://localhost:8080/?slc_test_render=1&id=59&variant=pitch" | grep -oE "Marketing Digital Avanzado|Estrategias modernas" | head -3
```

**Debe responder**: `Marketing Digital Avanzado` y/o `Estrategias modernas`. Si responde vacío, esperá 10 segundos y volvé a probar — WP puede estar terminando de arrancar.

**Si todo OK** → decí a Nadi:

> "¡Funciona! Abrí este link en tu browser:
>
> http://localhost:8080/?slc_test_render=1&id=59&variant=pitch
>
> Vas a ver la landing del curso de prueba. Esta es la **V2** que vas a iterar."

### Paso 5 — Onboarding al workflow

Decí: "Hay dos documentos importantes que tenés que leer (o pedirme que te los resuma):

1. **`docs/onboarding-diseno.md`** — el workflow del día a día (cómo pedirme cambios, cómo subir tu trabajo).
2. **`docs/guia-secciones-pitch.md`** — el mapa de la landing: qué clase CSS controla qué sección.

¿Querés que te los resuma ahora o preferís leerlos vos primero?"

Si Nadi pide resumen, leé los dos archivos y resumí los puntos clave (anchos, qué se puede tocar vs qué no, mecánica de Claude Code).

### Paso 6 — Primer cambio sugerido (warm-up)

Para que Nadi pruebe el flow completo, proponele un cambio chiquito de prueba:

> "Para que pruebes cómo es trabajar conmigo, decime un cambio chico que quieras hacer en la landing. Por ejemplo:
> - 'hacé el título del hero un poco más grande'
> - 'cambiá el color de los botones'
> - 'ponele más espacio entre las cards de bonos'
>
> Yo te muestro qué archivo voy a tocar antes de hacerlo, lo cambio, y recargás el browser para ver el resultado."

Cuando Nadi proponga un cambio:
1. Identificá la(s) clase(s) CSS afectada(s) (usá `docs/guia-secciones-pitch.md` como referencia).
2. Antes de editar, decile: "Voy a tocar `plugin/studiahub-lms-connector/assets/css/coursepitch.css` y cambiar [X]. ¿Voy?"
3. Hacé el cambio (Edit tool en el CSS).
4. Decile: "Listo. Recargá el browser con **Cmd+Shift+R** (recarga sin cache) y contame qué te parece."

## Reglas de qué tocar y qué NO tocar

Aplicar siempre al editar:

### 🎨 Se puede tocar:
- `plugin/studiahub-lms-connector/assets/css/coursepitch.css` (el CSS del V2)
- `plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php` (HTML estructural del V2, con cuidado — pedile a Nadi confirmación si cambia markup significativo)
- `.docker/dev-mock/payload.json` si Nadi quiere probar variantes de data (ej. ver cómo queda sin reseñas)

### 🚫 NO tocar:
- `plugin/studiahub-lms-connector/assets/css/coursepage.css` (el CSS del V1 — está congelado)
- `plugin/studiahub-lms-connector/includes/class-shortcode-coursepage.php` (V1 congelado)
- `plugin/studiahub-lms-connector/includes/class-landing-fetch.php` (backend del fetch)
- `plugin/studiahub-lms-connector/includes/class-plugin.php` (loader)
- Cualquier archivo del LMS Next.js (no estamos en ese repo de todos modos)

### 📥 Si Nadi pide algo que NO es diseño:
Si pide cambiar **textos del curso** (título, descripción, instructores, bonos, etc.), explicale:

> "Eso no es diseño, es contenido del curso. Lo edita el dueño de la academia desde el admin del LMS. Vos diseñás **cómo se ve**, no **qué dice**.
>
> Si querés probar cómo queda con otro texto para ver tu diseño, puedo editar el archivo `.docker/dev-mock/payload.json` (es el contenido fake que usamos solo para diseñar). Pero ese cambio queda solo en tu Mac, no afecta clientes reales."

## Comandos útiles que podés ofrecer a Nadi

Cuando le venga bien:
- `make refresh` — bustea el cache (si edita el JSON y no ve cambios al recargar).
- `make logs` — muestra los logs del servidor en vivo si algo se rompe (Ctrl+C para salir).
- `make down` — apaga el ambiente cuando termina el día (libera memoria).
- `make up` — vuelve a arrancarlo al día siguiente.

## Cuándo escalar a Gon

Decile a Nadi que mande captura + descripción a Gon en estos casos:
- Cualquier error de Docker que no se resuelva en 2 intentos.
- Puerto 8080 ocupado y no podés liberarlo.
- `make setup` falla con error técnico inentendible.
- Nadi pide hacer un cambio que **rompe algo del backend** (no de diseño).
- Nadi quiere mergear su PR (eso lo hace Gon).

## Cómo Nadi sube su trabajo a GitHub

Cuando termine una iteración y quiera mandar PR:

1. Abrir **GitHub Desktop** (no Terminal — para no asustarla).
2. En la lista izquierda ve los archivos modificados.
3. Abajo escribe un summary corto (ej. "Hero más grande + colores pastel").
4. Click **"Commit to main"**.
5. Click **"Push origin"** arriba a la derecha.
6. Click **"Create Pull Request"** (te lleva al browser).
7. Llenar título + descripción, click **"Create pull request"**.
8. Avisar a Gon.

Si Nadi pide help con esto, guiala paso a paso con captures sugeridas.

---

# 📋 Apéndice — Información de contexto del proyecto

- **Repo plugin (en el que está trabajando Nadi)**: `studiahub/studiahub-lms-connector`
- **Repo LMS** (no usar — pero conviene saber que existe): `studiahub/studiahub-lms`. Nadi NO necesita correrlo localmente: el mock JSON cubre toda la data.
- **Puerto WordPress local**: `http://localhost:8080`
- **DB local (acceso TablePlus opcional)**: host `127.0.0.1`, port `3307`, user `wp`, pass `wp`, database `wordpress`.
- **Variantes de landing actuales**:
  - V1 (congelada): `[studiahub_course_page]` → http://localhost:8080/?slc_test_render=1&id=59&variant=page
  - V2 (en iteración): `[studiahub_course_pitch]` → http://localhost:8080/?slc_test_render=1&id=59&variant=pitch
- **Cliente del repo**: StudiaHub, agencia digital en Buenos Aires. El plugin se instala en WordPress de clientes (academias online).
- **Branding dinámico**: los colores y la tipografía de cada landing los configura cada cliente en su panel admin del LMS (no son hardcoded). Para diseño usamos el branding del cliente demo: primary `#7950F2`, secondary `#FA5252`, font Inter.
