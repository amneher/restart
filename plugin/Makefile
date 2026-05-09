PLUGIN_NAME := restart-registry
DIST_DIR    := $(CURDIR)/dist

.PHONY: up down logs reset test build clean

up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f

reset:
	docker compose down -v
	docker compose up -d

test:
	./vendor/bin/phpunit
	npm test

build:
	mkdir -p $(DIST_DIR)
	rm -rf /tmp/$(PLUGIN_NAME)
	rsync -a --delete --exclude-from=.distignore $(CURDIR)/ /tmp/$(PLUGIN_NAME)/
	cd /tmp && zip -r $(DIST_DIR)/$(PLUGIN_NAME).zip $(PLUGIN_NAME)
	rm -rf /tmp/$(PLUGIN_NAME)
	@echo "Packed → $(DIST_DIR)/$(PLUGIN_NAME).zip"

clean:
	rm -f $(DIST_DIR)/$(PLUGIN_NAME).zip
	@echo "Removed $(DIST_DIR)/$(PLUGIN_NAME).zip"
