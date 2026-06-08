/**
 * Combo picker — hidrata el multiselect de cursos del LMS en el metabox
 * "StudiaHub LMS" del editor de producto WC.
 *
 * Vanilla JS (sin jQuery). Flujo:
 *   1. Lee la selección inicial del data-attr `data-selected` (JSON array de
 *      course ids) que renderizó el PHP.
 *   2. Fetch a /wp-json/studiahub/v1/lms-courses (server-side proxy al LMS, con
 *      fallback a productos sincronizados).
 *   3. Renderiza checkboxes + buscador. Cada checkbox tickeado emite un hidden
 *      input name="_lms_course_ids[]" que el save() del metabox persiste.
 *   4. El toggle "es combo" muestra/oculta el picker.
 *
 * Robusto: si el fetch falla, deja los hidden seed inputs (la selección previa)
 * intactos para no perder data al guardar, y muestra un botón de reintento.
 */
(function () {
  'use strict';

  if (typeof window.slcCombo === 'undefined') {
    return;
  }

  var cfg = window.slcCombo;
  var i18n = cfg.i18n || {};

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  ready(function () {
    var root = document.querySelector('.slc-combo');
    var toggle = document.getElementById('slc-combo-enabled');
    var picker = root ? root.querySelector('.slc-combo__picker') : null;
    var mount = document.getElementById('slc-combo-mount');

    if (!root || !toggle || !picker || !mount) {
      return;
    }

    root.classList.add('is-hydrated');

    // Selección inicial desde el data-attr.
    var selected = [];
    try {
      var raw = picker.getAttribute('data-selected');
      var parsed = raw ? JSON.parse(raw) : [];
      if (Array.isArray(parsed)) {
        selected = parsed.map(String);
      }
    } catch (e) {
      selected = [];
    }

    // Estado en memoria de los ids elegidos (Set para dedup rápido).
    var chosen = {};
    selected.forEach(function (id) {
      chosen[id] = true;
    });

    // Toggle del combo: mostrar/ocultar picker.
    function syncPickerVisibility() {
      if (toggle.checked) {
        picker.removeAttribute('hidden');
        if (!picker.dataset.loaded) {
          loadCourses();
        }
      } else {
        picker.setAttribute('hidden', '');
      }
    }
    toggle.addEventListener('change', syncPickerVisibility);

    // --- Carga de cursos ---
    function loadCourses() {
      picker.dataset.loaded = '1';
      renderStatus(i18n.loading || 'Cargando…');

      var url = cfg.coursesEndpoint;

      window
        .fetch(url, {
          method: 'GET',
          credentials: 'same-origin',
          headers: {
            'X-WP-Nonce': cfg.restNonce,
            Accept: 'application/json',
          },
        })
        .then(function (res) {
          if (!res.ok) {
            throw new Error('HTTP ' + res.status);
          }
          return res.json();
        })
        .then(function (data) {
          var courses = data && Array.isArray(data.courses) ? data.courses : [];
          renderList(courses, data && data.source);
        })
        .catch(function () {
          // Fetch falló: marcamos para poder reintentar y NO tocamos los seed
          // inputs (la selección previa sigue viajando al guardar).
          delete picker.dataset.loaded;
          renderError();
        });
    }

    // --- Render helpers ---
    function clearMount() {
      mount.innerHTML = '';
    }

    // Reemite los hidden inputs name="_lms_course_ids[]" desde el estado `chosen`.
    // Vive en un contenedor propio dentro del picker (fuera del mount) para que
    // sobreviva a los clearMount() de los re-render. Garantiza que la selección
    // se persista al guardar incluso si el fetch de cursos falla.
    function emitHiddenFromChosen() {
      var holder = picker.querySelector('.slc-combo__hidden');
      if (!holder) {
        holder = document.createElement('div');
        holder.className = 'slc-combo__hidden';
        picker.appendChild(holder);
      }
      holder.innerHTML = '';
      Object.keys(chosen).forEach(function (id) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_lms_course_ids[]';
        input.value = id;
        holder.appendChild(input);
      });
    }

    function renderStatus(text, isError) {
      clearMount();
      var p = document.createElement('p');
      p.className = 'slc-combo__status' + (isError ? ' slc-combo__status--error' : '');
      p.textContent = text;
      mount.appendChild(p);
    }

    function renderError() {
      renderStatus(i18n.loadError || 'Error al cargar.', true);
      // No perdemos la selección previa aunque no hayamos podido listar.
      emitHiddenFromChosen();
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'button button-secondary slc-combo__retry';
      btn.textContent = i18n.retry || 'Reintentar';
      btn.addEventListener('click', function () {
        loadCourses();
      });
      mount.appendChild(btn);
    }

    function renderList(courses, source) {
      clearMount();

      if (!courses.length) {
        renderStatus(i18n.empty || 'No hay cursos disponibles.');
        return;
      }

      // Aviso si la lista vino del fallback (productos sincronizados).
      if (source === 'synced_products' && i18n.fallbackNotice) {
        var notice = document.createElement('p');
        notice.className = 'slc-combo__notice';
        notice.textContent = i18n.fallbackNotice;
        mount.appendChild(notice);
      }

      // Buscador.
      var search = document.createElement('input');
      search.type = 'search';
      search.className = 'slc-combo__search';
      search.placeholder = i18n.searchPlaceholder || 'Buscar…';
      mount.appendChild(search);

      // Lista.
      var list = document.createElement('div');
      list.className = 'slc-combo__list';
      mount.appendChild(list);

      var emptySearch = document.createElement('p');
      emptySearch.className = 'slc-combo__empty-search slc-combo__item--hidden';
      emptySearch.textContent = i18n.empty || 'Sin resultados.';
      list.appendChild(emptySearch);

      var count = document.createElement('p');
      count.className = 'slc-combo__count';
      mount.appendChild(count);

      var itemEls = [];

      courses.forEach(function (course) {
        var id = String(course.id);
        var title = String(course.title || id);

        var label = document.createElement('label');
        label.className = 'slc-combo__item';
        label.dataset.title = title.toLowerCase();

        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = id;
        cb.checked = !!chosen[id];

        var span = document.createElement('span');
        span.className = 'slc-combo__item-title';
        span.textContent = title;

        label.appendChild(cb);
        label.appendChild(span);
        list.appendChild(label);
        itemEls.push(label);

        function reflect() {
          if (cb.checked) {
            label.classList.add('slc-combo__item--checked');
          } else {
            label.classList.remove('slc-combo__item--checked');
          }
        }
        reflect();

        cb.addEventListener('change', function () {
          if (cb.checked) {
            chosen[id] = true;
          } else {
            delete chosen[id];
          }
          reflect();
          updateCount();
        });
      });

      function updateCount() {
        var n = Object.keys(chosen).length;
        var tpl = i18n.selectedCount || '%d seleccionados';
        count.textContent = tpl.replace('%d', String(n));
        count.classList.toggle('slc-combo__count--zero', n === 0);
        emitHiddenFromChosen();
      }

      // Buscador: filtra por título.
      search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        var visible = 0;
        itemEls.forEach(function (el) {
          var match = !q || el.dataset.title.indexOf(q) !== -1;
          el.classList.toggle('slc-combo__item--hidden', !match);
          if (match) {
            visible++;
          }
        });
        emptySearch.classList.toggle('slc-combo__item--hidden', visible > 0);
      });

      updateCount();
    }

    // Init.
    syncPickerVisibility();
  });
})();
