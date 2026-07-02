# Onboarding — Editar la landing (para Claude Code)

> Pegale esto a Claude Code cuando abras la carpeta del repo. Está escrito para que Claude Code lo ejecute paso a paso.

## Contexto que Claude Code tiene que saber

- Este repo es el plugin de WordPress **StudiaHub LMS Connector**. NO es un sitio web: es un plugin que se instala en muchos WordPress de clientes.
- La **landing del curso** se renderiza desde este plugin. Los archivos que definen su estructura y estética son:
  - `plugin/studiahub-lms-connector/includes/class-shortcode-coursepage.php` — markup del shortcode `[studiahub_course_page]`
  - `plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php` — markup del shortcode `[studiahub_course_pitch]`
  - `plugin/studiahub-lms-connector/assets/css/coursepage.css` — estética del primero
  - `plugin/studiahub-lms-connector/assets/css/coursepitch.css` — estética del segundo
- La **data** (textos, precios, imágenes) NO vive acá: viene en vivo del LMS. En local se ve con un payload mockeado.
- ⚠️ **CLAVE:** los cambios NO llegan a los WordPress al pushear. Llegan **solo cuando se publica una GitHub Release** (`bin/release.sh`). Y una Release **impacta a TODOS los clientes a la vez**. Por eso el paso de release se hace con cuidado y confirmando.

---

## Paso 1 — Actualizar el repo local (NO clonar de nuevo)

La carpeta ya existe. Hay que sincronizarla con `main` antes de tocar nada, porque el clon local se desfasa.

```bash
git stash                 # guarda cambios sueltos si los hubiera
git checkout main
git fetch origin
git pull origin main
git remote -v             # verificar: git@github-studiahub:studiahub/studiahub-lms-connector.git
```

- Si `git remote -v` NO apunta a `git@github-studiahub:studiahub/studiahub-lms-connector.git`, corregilo:
  `git remote set-url origin git@github-studiahub:studiahub/studiahub-lms-connector.git`
- Si el `git pull` da conflicto o dice "diverged": **PARÁ y avisá al humano**. No hagas `git reset --hard` ni fuerces nada.

## Paso 2 — Branch nueva desde main actualizado

```bash
git checkout -b feat/landing-<descripcion-corta>
```

## Paso 3 — Ver la landing en local (Docker)

```bash
cd .docker
docker compose up -d
docker compose --profile init run --rm wpcli
```

- Abre `http://localhost:8080` — ahí se ve el WP con el plugin. El plugin es **hot-reload**: guardás un archivo y refrescás el navegador (Cmd+Shift+R), no hay que reiniciar nada.
- Si `docker compose up` falla, levantá limpio: `docker compose down -v` y repetí.
- La landing renderiza con data mockeada (no necesita el LMS corriendo).

## Paso 4 — Modificar

Editá los archivos del bloque de arriba. Estructura/funcional → los `.php`. Estética → los `.css`. Revisá el resultado en `localhost:8080` antes de commitear.

## Paso 5 — Commit y push

```bash
git add -A
git commit -m "feat(landing): <qué cambiaste>"
git push -u origin feat/landing-<descripcion-corta>
```

Abrí un Pull Request contra `main` (con `gh pr create --fill` o desde GitHub).

---

## Paso 6 — Publicar para que impacte en WordPress (RELEASE)

> Esto despliega a TODOS los clientes. Hacelo solo cuando el cambio está revisado y el PR mergeado a `main`. Confirmá con el humano antes de correr `bin/release.sh`.

1. Pasá a `main` ya con el PR mergeado:
   ```bash
   git checkout main
   git pull origin main
   ```
2. Bumpeá la versión (subí el último número, ej. `0.14.2` → `0.15.0`) en **3 lugares**:
   - `plugin/studiahub-lms-connector/studiahub-lms-connector.php`: el header `* Version:` **y** la constante `SLC_VERSION` (ambos con el mismo número).
   - `plugin/studiahub-lms-connector/readme.txt`: el campo `Stable tag:` (mismo número) **y** agregá una entrada nueva de changelog arriba de todo, con el formato `= 0.15.0 =` seguido de un renglón por cambio.
3. Commit y push del bump:
   ```bash
   git add -A
   git commit -m "chore(release): 0.15.0"
   git push origin main
   ```
4. Publicá la release:
   ```bash
   bin/release.sh
   ```
   El script valida que el working tree esté limpio, que `Stable tag` == `Version`, empaqueta el `.zip`, crea el tag `vX.Y.Z` y la GitHub Release. Requiere `gh` autenticado.

Cuando termina, cada WordPress se actualiza solo por cron (~12h) o al instante desde **Plugins → Buscar actualizaciones** en cada sitio.

### Si algo del release falla
- "hay cambios sin commitear" → commiteá o `git stash` antes.
- "Stable tag != Version" → los 3 números tienen que coincidir (paso 2).
- "el tag ya existe" → no bumpeaste la versión; subí el número.
