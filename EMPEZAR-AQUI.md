# 🚀 Empezar acá — Setup completo para diseño

> **Si sos Nadi (diseño):** este documento te explica cómo arrancar. Vas a hacer **3 cosas** y de ahí en adelante un asistente de IA (Claude Code) hace todo lo demás con vos. **No vas a tener que instalar nada a mano más allá de Claude Code** — el asistente instala todo el resto.
>
> **Si sos Claude Code:** saltá a la **Parte B**. Ese es tu playbook completo.

---

# Parte A — Lo que hace Nadi (5 min)

## 1. Instalá Claude Code

Es lo único que necesitás instalar a mano. Es el asistente de IA que va a hacer todo lo demás.

👉 https://docs.claude.com/claude-code/quickstart

Seguí las instrucciones de esa página para Mac. Necesitás una cuenta de Anthropic. Si no tenés, hablalo con Gon antes de seguir.

## 2. Abrí Terminal

- Apretá `Cmd+Espacio` (lupa de búsqueda de Mac).
- Escribí `terminal`.
- Apretá `Enter`.

Se va a abrir una ventana negra (o blanca según tu config). Eso es la Terminal.

## 3. Pegá este comando, dale Enter, y después pegá el prompt

Primero arrancá Claude Code:

```
claude
```

Cuando aparezca el chat de Claude Code, **copiá y pegá exactamente este texto**:

> Hola Claude. Soy Nadi, diseñadora, no soy técnica. Vengo a trabajar en una landing de WordPress para StudiaHub. Necesito que hagas el setup completo en mi Mac de cero a punto.
>
> Por favor leé este documento y ejecutá toda la **Parte B** al pie de la letra. Ejecutá vos todo lo que puedas. Cuando necesites algo de mí (escribir mi contraseña, abrir una app a mano, autorizar algo en el browser), explicame claro y simple qué tengo que hacer.
>
> Doc: https://raw.githubusercontent.com/studiahub/studiahub-lms-connector/main/EMPEZAR-AQUI.md
>
> Empezá ahora. Yo te voy guiando con vos en pantalla.

**Dale Enter.** A partir de ahí, Claude Code se hace cargo. Vos solo respondés lo que te pregunta y aceptás las acciones que te propone (te va a pedir aprobación antes de cada comando importante).

> ⏱️ **Tiempo estimado total del setup**: 20-30 minutos (la mayoría es esperar descargas).

---

# Parte B — Playbook para Claude Code

> Hola agente. Sos asistente de **Nadi** (diseñadora, **no-técnica**, Mac, español rioplatense). Acaba de pegarte un prompt pidiéndote que hagas el setup completo del proyecto `studiahub-lms-connector` en su Mac, de cero a landing renderizando.
>
> Este es tu playbook completo. Seguilo en orden estricto, ejecutando vos todo lo que puedas.

## Reglas absolutas

1. **Español rioplatense, casual, paciente.** Nada de jerga sin explicar.
2. **Antes de cada paso decile qué vas a hacer y por qué** (1 oración corta). Después del paso, decile si salió bien.
3. **Ejecutá vos todos los comandos que puedas** con la herramienta Bash. No le pidas a Nadi que escriba comandos a mano salvo que sea estrictamente necesario (login a GitHub, password de sudo, abrir Docker Desktop).
4. **Cuando un comando requiere input de ella** (password, OAuth, abrir GUI), explicale exactamente qué va a pasar antes y qué tiene que hacer.
5. **Si algo falla 2 veces**, parate. Explicale el error, pedile captura, decile que avise a Gon. Mejor parar que destruir cosas.
6. **Nunca corras comandos destructivos** (rm -rf, git reset --hard, docker volume rm, etc.) sin advertirle explícitamente y esperar su "sí".
7. **No asumas que tiene cosas instaladas.** Verificá todo, no des nada por sentado.

## Variables del entorno objetivo

Al final del playbook, Nadi debe tener:
- Homebrew instalado
- Docker Desktop instalado y CORRIENDO
- git instalado
- gh CLI instalado y autenticado contra GitHub
- Repo `TU-USUARIO/studiahub-lms-connector` forkeado y clonado en `~/Documents/studiahub-lms-connector/` (o donde ella prefiera)
- WordPress local corriendo en http://localhost:8080
- La landing V2 renderizada en `http://localhost:8080/?slc_test_render=1&id=59&variant=pitch`

## Fases del setup

Avanzá por fases. Cada fase tiene un check de "listo para la siguiente". Si una falla, no avanzes.

---

### FASE 0 — Saludá y explicá el plan

Decile algo como:

> "Hola Nadi! Soy Claude. Te voy a ayudar a dejar todo listo para que puedas diseñar la landing. El proceso completo toma 20-30 min, pero la mayoría es esperar descargas. Yo voy a ejecutar lo que pueda; vos solo aprobás lo que te pregunto y a veces te voy a pedir tu contraseña o que abras una app. ¿Arrancamos?"

Esperá su "dale" o equivalente.

---

### FASE 1 — Detectá qué ya tiene instalado

Hacé un scan rápido y silencioso:

```bash
which brew
which git
which gh
which docker
docker info 2>&1 | head -3
```

Reportale en una frase: "Tenés X, Y y Z. Falta instalar A y B. Voy a arrancar por A."

Si TODO está instalado y Docker está corriendo, saltá directo a FASE 5.

---

### FASE 2 — Homebrew

Si `brew` no está:

Decile:

> "Voy a instalar Homebrew. Es un instalador de programas para Mac, súper estándar. Te va a pedir tu contraseña de Mac (la que usás para desbloquear la compu) — tipeala cuando aparezca el cursor pidiéndola. Mientras escribís no vas a ver caracteres, es normal."

Ejecutá:
```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

Cuando termine, asegurate de que `brew` esté en el PATH. En Apple Silicon es:
```bash
echo 'eval "$(/opt/homebrew/bin/brew shellenv)"' >> ~/.zprofile
eval "$(/opt/homebrew/bin/brew shellenv)"
```

En Intel:
```bash
echo 'eval "$(/usr/local/bin/brew shellenv)"' >> ~/.zprofile
eval "$(/usr/local/bin/brew shellenv)"
```

Verificá:
```bash
brew --version
```

Si responde, ✓. Si no, parate y pedile captura.

---

### FASE 3 — Git + GitHub CLI

Decile: "Ahora voy a instalar Git (descarga código) y GitHub CLI (te permite manejar GitHub desde acá). Esto es rápido, no te va a pedir nada."

```bash
brew install git gh
```

Verificá:
```bash
git --version
gh --version
```

---

### FASE 4 — Docker Desktop

Si `docker` no está instalado:

Decile:

> "Voy a instalar Docker Desktop. Es la app que corre el servidor de WordPress en tu Mac. La descarga puede tardar 5-10 minutos según tu internet."

```bash
brew install --cask docker
```

**Cuando termine la instalación**, Docker Desktop NO arranca solo. Tenés que pedirle a Nadi que lo abra a mano:

> "Listo, Docker Desktop quedó instalado. Ahora necesito que vos hagas lo siguiente:
>
> 1. Apretá `Cmd+Espacio`, escribí `docker` y dale Enter. Se va a abrir Docker Desktop.
> 2. Va a aparecer una pantalla pidiéndote aceptar los términos. Aceptalos.
> 3. Va a pedirte tu contraseña de Mac al final del proceso (es para configurar permisos). Ingrésala.
> 4. Esperá hasta ver una **ballenita 🐳 arriba en la barra superior** (al lado del reloj).
>
> Avisame cuando veas la ballenita."

Esperá su confirmación. Cuando confirme, verificá:

```bash
docker info 2>&1 | head -3
```

Tiene que responder sin error. Si dice "Cannot connect to the Docker daemon", Docker todavía está arrancando — esperá 30 segundos y reintentá. Si después de 3 intentos no anda, escala a Gon.

---

### FASE 5 — Login a GitHub con gh

Verificá si ya está logueada:
```bash
gh auth status 2>&1
```

Si NO está logueada, decile:

> "Necesito conectarte a GitHub. Voy a abrir un proceso que te pide ir a una URL en el browser, copiar un código corto y autorizar la conexión. No es complicado, te guío.
>
> Si todavía no tenés cuenta de GitHub, creala primero en https://github.com/signup (gratis), y después seguimos."

Ejecutá:
```bash
gh auth login --git-protocol https --web
```

Esto va a imprimir un código de 8 caracteres y una URL (https://github.com/login/device). Decile a Nadi:

> "Copiá ESTE código: `XXXX-XXXX`. Abrí ESTA URL en el browser: https://github.com/login/device. Pegá el código ahí. Autorizá la conexión. Volvé acá cuando termines."

Esperá a que el comando finalice. Verificá:
```bash
gh auth status
```

---

### FASE 6 — Fork del repo

Decile: "Voy a hacer una copia tuya del repositorio del plugin. Esto se llama 'fork' — es tu copia personal donde podés cambiar lo que quieras sin afectar el original."

```bash
gh repo fork studiahub/studiahub-lms-connector --clone=false
```

Esto crea el fork en su cuenta. Verificá que existe:
```bash
gh repo view --json url
```

---

### FASE 7 — Clone del fork

Preguntale dónde quiere que viva la carpeta del proyecto en su Mac:

> "¿Dónde querés que ponga la carpeta del proyecto en tu Mac? Sugiero `~/Documents/studiahub-lms-connector` (queda en Documentos). Si preferís otro lugar, decímelo. Sino dale Enter para usar el sugerido."

Si elige el default o no contesta claramente, usá `~/Documents/studiahub-lms-connector`.

Primero asegurate de tener su usuario de GitHub:
```bash
GH_USER=$(gh api user --jq .login)
echo "Usuario GitHub: $GH_USER"
```

Cloná el fork:
```bash
mkdir -p ~/Documents
cd ~/Documents
gh repo clone "$GH_USER/studiahub-lms-connector"
```

Verificá:
```bash
ls -la ~/Documents/studiahub-lms-connector/EMPEZAR-AQUI.md
```

Si existe ✓.

---

### FASE 8 — Levantá el ambiente

Decile:

> "Ahora viene el paso más largo. Voy a descargar las imágenes de WordPress y la base de datos, las arranco, e instalo WordPress automáticamente. Puede tardar 5-10 min la primera vez. Vas a ver mucho texto pasar, es normal — esperá a que termine."

```bash
cd ~/Documents/studiahub-lms-connector
make setup
```

Mirá el output. Debe terminar con:

```
✓ Listo. Probá ahora:
  Admin WP:     http://localhost:8080/wp-admin (user: admin / pass: admin)
  Landing V1:   http://localhost:8080/?slc_test_render=1&id=59&variant=page
  Landing V2:   http://localhost:8080/?slc_test_render=1&id=59&variant=pitch
```

Si terminó OK, validá con un curl:
```bash
sleep 5 && curl -s "http://localhost:8080/?slc_test_render=1&id=59&variant=pitch" | grep -oE "Marketing Digital Avanzado" | head -1
```

Tiene que responder `Marketing Digital Avanzado`. Si responde vacío, esperá 15 seg más y reintentá.

---

### FASE 9 — Mostrale la landing y orientala

Decile:

> "¡Listo! Tu WordPress local está corriendo. Abrí este link en tu browser para ver la landing del curso de prueba:
>
> http://localhost:8080/?slc_test_render=1&id=59&variant=pitch
>
> Esto es lo que vas a iterar visualmente. La data que ves (instructores, bonos, precios, etc.) es de un curso de prueba — vos diseñás cómo se ve, no qué dice."

Después, **leé los dos docs principales** (con la herramienta Read) y resumiselos:

- `docs/onboarding-diseno.md` — workflow del día a día.
- `docs/guia-secciones-pitch.md` — mapa de cada sección con su clase CSS.

Decile algo como:

> "Hay dos documentos importantes que ya leí por vos:
>
> 1. **Workflow** — cómo pedirme cambios y subir tu trabajo a GitHub.
> 2. **Mapa de secciones** — qué clase CSS controla cada parte de la landing.
>
> ¿Querés que te los resuma ahora o preferís pasar directo a probar un primer cambio chiquito?"

---

### FASE 10 — Primer cambio de warm-up

Para cerrar el setup con una victoria, proponé hacer un cambio chiquito:

> "Para que pruebes cómo trabajamos juntas, decime un cambio mínimo que quieras hacer. Por ejemplo:
> - 'agrandá un poquito el título principal'
> - 'cambiá el color de los botones a azul'
> - 'ponele más espacio entre las cards de bonos'
>
> Yo te muestro qué archivo voy a tocar antes de hacerlo, lo cambio, y vos recargás el browser para ver el resultado. **Tip importante**: para recargar sin cache, usá Cmd+Shift+R (no solo Cmd+R)."

Cuando Nadi proponga un cambio:
1. Identificá la(s) clase(s) CSS afectada(s) — usá `docs/guia-secciones-pitch.md`.
2. Decile: "Voy a tocar `plugin/studiahub-lms-connector/assets/css/coursepitch.css`. ¿Voy?"
3. Hacé el cambio (Edit tool en el CSS).
4. Decile: "Listo. Recargá el browser con Cmd+Shift+R y contame qué te parece."

---

## Reglas de qué tocar y qué NO tocar

### 🎨 Se puede tocar (diseño):
- `plugin/studiahub-lms-connector/assets/css/coursepitch.css` (el CSS del V2)
- `plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php` (HTML estructural del V2, con cuidado)
- `.docker/dev-mock/payload.json` (data fake si quiere probar variantes)

### 🚫 NO tocar (riesgo de romper cosas):
- `plugin/studiahub-lms-connector/assets/css/coursepage.css` (V1, congelado)
- `plugin/studiahub-lms-connector/includes/class-shortcode-coursepage.php` (V1, congelado)
- Cualquier otro archivo `.php` del plugin (backend)
- Archivos del directorio `.docker/` (salvo `dev-mock/payload.json`)

### 📥 Si Nadi pide cambiar CONTENIDO (no diseño):
Si pide cambiar **textos del curso** (título, descripción, instructores, bonos, etc.), explicale:

> "Eso es contenido del curso. Lo edita cada cliente desde el admin de su LMS. Vos diseñás cómo se ve, no qué dice.
>
> Si querés probar tu diseño con otros textos, puedo editar `.docker/dev-mock/payload.json` que es la data fake que usamos para diseñar. Pero ese cambio queda solo en tu Mac, no afecta clientes reales."

## Cómo Nadi sube su trabajo a GitHub (PR flow detallado)

> **IMPORTANTE**: Nadi trabaja en un FORK del repo de Gon. Sus cambios van:
> 1. Primero a su fork en GitHub (`nadi-usuario/studiahub-lms-connector`)
> 2. Después a un Pull Request al repo original (`studiahub/studiahub-lms-connector`)
> 3. Gon revisa, comenta, mergea o pide cambios.

### Cuándo es un buen momento para mandar PR

- Cuando terminó una iteración completa de un cambio (no a cada tweak chico).
- Cuando quiere que Gon vea el resultado y opine.
- Cuando lleva varias horas sin guardar cambios → hacer un commit aunque sea WIP.

**Idealmente** hacé varios commits chicos coherentes en lugar de uno grande con todo mezclado. Ejemplo:
- ✅ Commit 1: "Hero más grande + colores morados pastel"
- ✅ Commit 2: "Cards de bonos con más padding y borde sutil"
- ❌ Commit gigante: "Varios cambios visuales"

### Flow paso a paso (vos ejecutás, Nadi confirma)

Cuando Nadi diga "quiero mandar mis cambios a Gon" o equivalente:

#### Paso 1 — Mostrale qué cambió

```bash
cd ~/Documents/studiahub-lms-connector
git status
git diff --stat
```

Resumile en lenguaje natural qué archivos tocó (ej. "Cambiaste 2 archivos: el CSS del V2 y el HTML del shortcode V2").

#### Paso 2 — Pedile un título corto del cambio

Decile:

> "Para subirlo a GitHub necesito un título corto que describa qué hiciste. Pensalo como un 'qué cambió' en 5-10 palabras. Por ejemplo: 'Hero más grande + colores morados pastel'. ¿Cómo lo querés titular?"

#### Paso 3 — Commit + push a su fork

Una vez que tiene el título, ejecutá (donde `<título>` es lo que ella dio):

```bash
cd ~/Documents/studiahub-lms-connector
git add -A
git commit -m "<título>"
git push origin main
```

#### Paso 4 — Crear el Pull Request

Pedile una descripción más larga (opcional pero útil):

> "Antes de crear el Pull Request: ¿querés agregar alguna descripción más larga? Por ejemplo qué buscabas, qué probaste, alguna captura de antes/después, o alguna duda que tengas para Gon. Si no, dejo solo el título."

Después ejecutá:

```bash
cd ~/Documents/studiahub-lms-connector
gh pr create \
  --title "<título>" \
  --body "<descripción larga o '_Sin descripción extra._'>" \
  --repo studiahub/studiahub-lms-connector \
  --base main \
  --head "$(gh api user --jq .login):main"
```

Esto crea el PR en el repo original de Gon. El comando devuelve un link tipo:
```
https://github.com/studiahub/studiahub-lms-connector/pull/42
```

#### Paso 5 — Avisarle a Nadi

Pasale el link del PR y decile:

> "¡Listo! Acá está tu Pull Request: <LINK>
>
> Avisale a Gon (WhatsApp / Slack / email) que mande review. Mientras tanto vos podés seguir trabajando — si Gon te pide cambios, los hacés en los mismos archivos, hacés otro `commit` + `push`, y el PR se actualiza solo automáticamente."

### Si Gon pide cambios al PR

Cuando Nadi diga "Gon me pidió cambios al PR" o equivalente:

1. Hacé los cambios visuales que ella pida (mismo flow normal de Claude Code).
2. Cuando confirme que está OK, ejecutá:
   ```bash
   cd ~/Documents/studiahub-lms-connector
   git add -A
   git commit -m "<ajustes pedidos por Gon: descripción corta>"
   git push origin main
   ```
3. **No hace falta crear PR nuevo** — el push actualiza el PR existente automáticamente.

### Si Gon mergeó el PR

Cuando Gon mergea, el código de Nadi pasa al `main` del repo original (`studiahub/...`). Para que Nadi tenga ese código actualizado en su Mac (importante si va a seguir trabajando), tiene que **sincronizar el fork**:

```bash
cd ~/Documents/studiahub-lms-connector
git fetch upstream main 2>/dev/null || (git remote add upstream https://github.com/studiahub/studiahub-lms-connector.git && git fetch upstream main)
git checkout main
git merge upstream/main
git push origin main
```

Decile a Nadi:

> "Sincronicé tu copia local con los últimos cambios del repo original. Ya estás al día. Podés seguir iterando desde acá."

### Resumen visual del flow

```
┌──────────────┐  commit+push   ┌────────────────┐
│  Mac de Nadi │ ─────────────→ │  Fork de Nadi  │
│  (clonó acá) │                │  en GitHub     │
└──────────────┘                └────────┬───────┘
                                         │ Pull Request
                                         ▼
                                ┌────────────────┐
                                │  Repo original │
                                │  studiahub/... │
                                └────────┬───────┘
                                         │ Gon mergea
                                         ▼
                                ┌────────────────┐
                                │  main del repo │
                                │  original      │
                                └────────┬───────┘
                                         │ sync fork
                                         ▼
                                ┌────────────────┐
                                │  Fork de Nadi  │
                                │  actualizado   │
                                └────────────────┘
```

### Cosas que NUNCA hacés con git

- ❌ `git push --force` o `git push -f` — puede destruir trabajo de Gon.
- ❌ `git reset --hard origin/main` sin avisar — borra todos los cambios locales sin recuperación.
- ❌ Mergear el PR vos. Solo Gon mergea.
- ❌ Tocar branches que no sean `main`. Trabajamos siempre en `main` del fork.

Si por accidente alguno de estos pasa, parate y pedile a Nadi que avise a Gon **inmediatamente** con captura.

## Comandos útiles del día a día

Cuando le venga bien:
- `make refresh` — bustea el cache (cuando edita el JSON y no ve cambios al recargar).
- `make logs` — logs en vivo si algo se rompe (Ctrl+C para salir).
- `make down` — apaga el ambiente al terminar el día.
- `make up` — vuelve a arrancarlo al día siguiente.

## Cuándo escalar a Gon

Decile que mande captura + descripción a Gon en estos casos:
- Cualquier error de Docker que no se resuelva en 2 intentos.
- Puerto 8080 ocupado y no podés liberarlo.
- `make setup` falla con error técnico inentendible.
- Pide cambios que rompen el backend del plugin.
- Quiere mergear su PR (eso lo hace Gon).

---

# 📋 Apéndice — Contexto del proyecto

- **Repo plugin (en el que está trabajando Nadi)**: `studiahub/studiahub-lms-connector`
- **Repo LMS** (no usar — solo saber que existe): `studiahub/studiahub-lms`. Nadi NO necesita correrlo: el mock JSON cubre toda la data.
- **WordPress local**: http://localhost:8080
- **DB local** (TablePlus opcional): host `127.0.0.1`, port `3307`, user `wp`, pass `wp`, db `wordpress`.
- **Variantes de landing**:
  - V1 (congelada): `[studiahub_course_page]` → `?slc_test_render=1&id=59&variant=page`
  - V2 (en iteración): `[studiahub_course_pitch]` → `?slc_test_render=1&id=59&variant=pitch`
- **Mock activo**: el mu-plugin `.docker/mu-plugins/zz-dev-mock-payload.php` intercepta el fetch al LMS y devuelve `.docker/dev-mock/payload.json`. Por eso Nadi puede trabajar sin tener el LMS Next.js corriendo.
- **Branding**: el primaryColor (#7950F2 morado), secondaryColor (#FA5252), font Inter vienen del mock. En producción los configura cada cliente desde su admin del LMS.

---

# 📌 Apartado para Gon (no para Nadi ni para el agente de ella)

> Notas para tu yo del futuro cuando retomes este repo y no te acuerdes del setup.

## Toggle del mock JSON

Si abrís el WP local de este repo (`make up`), por defecto está activo el mu-plugin de dev mock — la landing **NO** está fetchando del LMS real. Es por diseño (para que Nadi pueda trabajar sin LMS).

### Si querés testear contra TU LMS real

```bash
make mock-off    # desactiva el mock + bustea cache
```

A partir de ahí la landing fetcha de tu LMS Next.js en `localhost:3000`. Tenés que tener `npm run dev` corriendo en el repo del LMS para que funcione.

### Volver al mock (para diseño)

```bash
make mock-on
```

### Ver estado actual

```bash
make mock-status
```

## Commits a coordinar al pushear

Cuando subas cambios al plugin que rompen el contrato del payload, **acordate** que tenés que pushear también el LMS y el cliente WP (si está en producción) tiene que actualizar el plugin. El payload es contrato entre dos repos:

- `studiahub/studiahub-lms` (Next.js, expone `/api/wc/courses/:id/landing-payload`)
- `studiahub/studiahub-lms-connector` (WP plugin, consume el payload)

Si cambiás un campo del payload en el LMS sin actualizar el plugin → la landing se rompe en producción. Y al revés.

## Para retomar el laburo de diseño con Nadi

1. Revisar PRs abiertos en https://github.com/studiahub/studiahub-lms-connector/pulls
2. Si hay uno de Nadi:
   - Hacer checkout local: `gh pr checkout <numero>`
   - Probar visualmente con `make up` + el link de la landing
   - Si OK → mergear desde la web de GitHub
   - Si pide cambios → comentar en el PR vía web (Nadi recibe notificación)
3. Después de mergear → avisarle a Nadi que sincronice su fork (`git fetch upstream && git merge upstream/main && git push`)

## Commands cheatsheet

```bash
make help          # lista todos los comandos
make up            # arranca WP local
make down          # apaga
make refresh       # bustea cache
make mock-on/off   # toggle del mock
make logs          # logs en vivo
make shell         # bash dentro del container WP
make clean         # borra containers (data intacta)
make reset         # ⚠️ DESTRUCTIVO: borra DB + containers
```
