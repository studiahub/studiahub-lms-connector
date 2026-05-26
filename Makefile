# StudiaHub LMS Connector — comandos atómicos del entorno de desarrollo.
#
# Pensado para que diseño / no-técnicos puedan levantar el ambiente sin
# memorizar comandos de docker compose. Workflow típico:
#
#   make setup       (una sola vez, primera instalación)
#   make up          (cada vez que arrancás a trabajar)
#   make refresh     (cuando edites el mock JSON y no veas cambios)
#   make down        (al terminar el día)
#
# Reuse el docker-compose que vive en .docker/.

DOCKER_DIR := .docker
COMPOSE := docker compose -f $(DOCKER_DIR)/docker-compose.yml

.DEFAULT_GOAL := help

## ──────────────────────────────────────────────────────────────────────────
## Setup inicial — solo se corre 1 vez
## ──────────────────────────────────────────────────────────────────────────

setup: ## Primera instalación. Levanta WP + DB + corre wp-init.sh (instala WP, crea admin, activa plugin).
	@echo "→ Levantando contenedores (puede tardar 1-2 min la primera vez)..."
	@$(COMPOSE) up -d db wp
	@echo "→ Inicializando WordPress (admin/admin)..."
	@$(COMPOSE) --profile init run --rm wpcli
	@echo ""
	@echo "✓ Listo. Probá ahora:"
	@echo "  Admin WP:     http://localhost:8080/wp-admin (user: admin / pass: admin)"
	@echo "  Landing V1:   http://localhost:8080/?slc_test_render=1&id=59&variant=page"
	@echo "  Landing V2:   http://localhost:8080/?slc_test_render=1&id=59&variant=pitch"
	@echo ""

## ──────────────────────────────────────────────────────────────────────────
## Día a día
## ──────────────────────────────────────────────────────────────────────────

up: ## Arranca el ambiente (WP + DB). No reinicializa.
	@$(COMPOSE) up -d db wp
	@echo "✓ Arriba. http://localhost:8080"

down: ## Apaga el ambiente (no borra data).
	@$(COMPOSE) stop
	@echo "✓ Apagado. Volvés a arrancar con: make up"

restart: down up ## Reinicia el ambiente.

logs: ## Muestra logs de WP en vivo. Ctrl+C para salir.
	@$(COMPOSE) logs -f wp

shell: ## Abre una shell dentro del container WP.
	@$(COMPOSE) exec wp bash

refresh: ## Bustea el cache de las landings (forzá recargar el payload).
	@$(COMPOSE) exec db mysql -uwp -pwp wordpress -e "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_slc%' OR option_name LIKE '\\_transient\\_timeout\\_slc%';" 2>/dev/null || true
	@echo "✓ Cache bustado. Recargá el browser con Cmd+Shift+R."

## ──────────────────────────────────────────────────────────────────────────
## Mock toggle (para diseño / dev sin LMS real)
## ──────────────────────────────────────────────────────────────────────────

mock-on: ## Activa el mock JSON. La landing toma data fake de .docker/dev-mock/payload.json (no toca el LMS).
	@if [ -f $(DOCKER_DIR)/dev-mock/payload.json.disabled ]; then \
		mv $(DOCKER_DIR)/dev-mock/payload.json.disabled $(DOCKER_DIR)/dev-mock/payload.json; \
	fi
	@$(MAKE) -s refresh
	@echo "✓ Mock activo. La landing lee de .docker/dev-mock/payload.json."

mock-off: ## Desactiva el mock. La landing fetcha del LMS real (necesita el LMS Next.js corriendo en localhost:3000).
	@if [ -f $(DOCKER_DIR)/dev-mock/payload.json ]; then \
		mv $(DOCKER_DIR)/dev-mock/payload.json $(DOCKER_DIR)/dev-mock/payload.json.disabled; \
	fi
	@$(MAKE) -s refresh
	@echo "✓ Mock desactivado. La landing va a fetchar del LMS real en localhost:3000."

mock-status: ## Muestra si el mock está activo o no.
	@if [ -f $(DOCKER_DIR)/dev-mock/payload.json ]; then \
		echo "🟢 Mock ACTIVO — la landing lee de .docker/dev-mock/payload.json"; \
	else \
		echo "🔴 Mock DESACTIVADO — la landing fetcha del LMS real"; \
	fi

## ──────────────────────────────────────────────────────────────────────────
## Cleanup
## ──────────────────────────────────────────────────────────────────────────

clean: ## Apaga + borra contenedores (mantiene la data en volúmenes).
	@$(COMPOSE) down
	@echo "✓ Contenedores borrados. La data está intacta. 'make up' los re-crea."

reset: ## ⚠️  DESTRUCTIVO. Borra todo (contenedores + volúmenes/DB). Próximo 'make setup' arranca desde cero.
	@echo "⚠️  Esto borra la DB de WP y toda la data local."
	@read -p "Escribí 'si' para confirmar: " confirm && [ "$$confirm" = "si" ] || (echo "Cancelado." && exit 1)
	@$(COMPOSE) down -v
	@echo "✓ Reset completo."

## ──────────────────────────────────────────────────────────────────────────
## Help
## ──────────────────────────────────────────────────────────────────────────

help: ## Muestra esta ayuda.
	@echo ""
	@echo "StudiaHub LMS Connector — comandos disponibles:"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'
	@echo ""

.PHONY: setup up down restart logs shell refresh mock-on mock-off mock-status clean reset help
