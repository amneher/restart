PLUGIN_NAME := restart-registry
DIST_DIR    := $(CURDIR)/dist

.PHONY: up down logs reset test test-assets-min build build-assets clean

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

# Rebuilds .min files, then runs the full JS suite against them (same tests
# as `npm test`, loading the .min build instead of source) to confirm
# minification didn't change behavior.
test-assets-min:
	npm run test:min

build-assets:
	npm run build

# test-assets-min rebuilds assets (via its pretest:min hook) before testing
# them, so the zip always ships freshly-minified, verified JS/CSS.
build: test-assets-min
	mkdir -p $(DIST_DIR)
	rm -f $(DIST_DIR)/$(PLUGIN_NAME).zip
	rm -rf /tmp/$(PLUGIN_NAME)
	rsync -a --delete --exclude-from=.distignore $(CURDIR)/ /tmp/$(PLUGIN_NAME)/
	cd /tmp && zip -r $(DIST_DIR)/$(PLUGIN_NAME).zip $(PLUGIN_NAME)
	rm -rf /tmp/$(PLUGIN_NAME)
	@echo "Packed → $(DIST_DIR)/$(PLUGIN_NAME).zip"

clean:
	rm -f $(DIST_DIR)/$(PLUGIN_NAME).zip
	@echo "Removed $(DIST_DIR)/$(PLUGIN_NAME).zip"
