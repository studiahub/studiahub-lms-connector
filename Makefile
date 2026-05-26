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
	@$(COMPOSE) exec wp bash -c "wp transient delete --all --allow-root || true; wp option delete \$$(wp option list --search='_transient_slc*' --field=option_name --allow-root) --allow-root 2>/dev/null || true"
	@echo "✓ Cache bustado. Recargá el browser con Cmd+Shift+R."

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

.PHONY: setup up down restart logs shell refresh clean reset help
