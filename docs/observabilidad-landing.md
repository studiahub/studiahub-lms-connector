# Cómo se vigila que la landing esté al día

Desde el connector **v0.18.0** (agosto 2026), si la landing deja de reflejar el LMS el sistema avisa. Antes podía estar rota durante semanas sin que nada fallara.

## Por qué no alcanzaba con reportar errores

El bug que motivó todo esto **no produjo un solo error**. El control de compra leía la caché correctamente, la landing devolvía HTML válido con 200, el LMS guardaba bien y contestaba OK. Todas las piezas hacían exactamente lo que decía su código. Sentry estaba limpio.

**No se puede alertar sobre errores que no ocurren.** Por eso lo que sigue no vigila el proceso: vigila el resultado.

---

## Las piezas

### 1. Invalidar ahora también trae el contenido

`POST /wp-json/studiahub/v1/cache-bust` antes solo borraba las copias con TTL. El contenido nuevo recién se buscaba cuando entraba un visitante, así que en un sitio con poco tráfico un cambio podía tardar horas en verse — y cualquier verificación posterior devolvía la copia vieja aunque todo funcionara bien.

Ahora, después de invalidar, llama a `get_payload()` en el momento. Quien llama es el LMS, que por definición está vivo en ese instante.

**Sin esta pieza el canario no funciona**: invalidar-y-reverificar no mediría nada.

### 2. Estado consultable: `GET /landing-status`

Bearer auth. Sin `course_id` devuelve todos los cursos publicados en un solo request.

```json
{
  "ok": true, "now": 1787172233, "ttlFresh": 900, "pluginVersion": "0.18.0",
  "courses": [{
    "courseId": "...", "served_from": "fresh", "last_success_at": 1787172074,
    "age_seconds": 159, "backoff_active": false, "has_payload": true,
    "content_version": "10cf8057181ce5f6dd102c7e549d6a3d"
  }]
}
```

**No fetchea, no escribe, no memoiza.** Se puede llamar sin alterar lo que ve el visitante — a diferencia de `get_payload()`, que muta.

### 3. Cron horario del plugin

`class-landing-health.php`. Refresca lo vencido **empezando por los más atrasados** y deja precomputado el resumen que lee el aviso del panel.

El orden no es cosmético: el cron es horario pero la copia fresca dura 15 minutos, así que en cada corrida están todos vencidos. Con orden fijo, los primeros se llevarían el presupuesto entero y del que quedara afuera en adelante no se refrescaría nunca.

Además **previene** el problema que detecta: hasta ahora el contenido se renovaba solo cuando alguien abría la página, así que en un sitio con poco tráfico el primer visitante del día se comía información vieja.

El aviso corre en `admin_notices`, o sea en cada pantalla del admin: por eso solo lee una option autoload y no hace ni una query.

### 4. El canario, del lado del LMS

`POST /api/cron/landing-health`, cada hora al minuto :53. Compara la huella que el LMS calcula ahora contra la que el WordPress está sirviendo, sin importarle por dónde se rompió la cadena.

**Primero repara, después se queja.** Una divergencia sola no es una falla: al detectarla invalida y vuelve a preguntar; solo si sigue divergiendo va a Sentry como `landing-health:stale`.

Salida sana:

```json
{"tenants":3,"courses":2,"repaired":0,"stale":0,"skipped":4,"outOfBudget":0,"errors":[]}
```

`stale > 0` es lo único que significa problema. `skipped` alto es normal mientras haya tenants sin actualizar.

### 5. `contentVersion`: la huella

Hash del payload incluido en el propio payload. Es un hash del contenido **y no un `updatedAt`** porque el payload se arma de siete fuentes y dos de ellas —`Module` y `Lesson`— no tienen columna de fecha. Un timestamp del curso sería ciego justo a lo que más se edita: reordenar lecciones, cambiar el temario, mover un encuentro en vivo.

---

## Dónde decidimos NO alertar

Esto es tan importante como lo que sí alerta. **Un sistema de alertas con falsos positivos deja de mirarse**, que es la forma más común de volver a quedarse ciego.

| Situación | Por qué se calla |
|---|---|
| El plugin del tenant no tiene el endpoint (404) | Rollout en curso |
| `has_payload: true` + `content_version: null` | Estado de **todos** los cursos hasta su primer refresco tras actualizar |
| El curso no figura en el WordPress | Su producto puede estar en borrador — decisión del cliente |
| El plugin está sirviendo `fresh` | Todo bien, aunque no haya marca de tiempo todavía |

---

## Infraestructura

El cron vive en el crontab del **VPS** (no en el contenedor, que es efímero):

```
53 * * * *  /opt/studiahub/lms-cron.sh landing-health >> /var/log/lms-cron.log 2>&1
```

Dead-man switch en Healthchecks.io, check con slug **`landing-health`**. El script pinga `https://hc-ping.com/$HC_PING_KEY/<slug>`, no la URL con UUID: **si el slug no coincide, el ping se pierde en silencio** (usa `|| true`).

Un ping legítimo se reconoce por el user agent `curl/x.y` y una IP de Hetzner. Si dice `Mozilla`, es alguien apretando el botón desde el navegador.

---

Para diagnosticar un caso puntual, ver [landing-desactualizada.md](landing-desactualizada.md).
