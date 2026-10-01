THEME_NAME := theRestart
DIST_DIR   := $(CURDIR)/dist

.PHONY: install test test-php test-js test-assets-min pack build-assets clean

install:
	composer install --no-interaction
	npm ci

test: test-php test-js

test-php:
	./vendor/bin/phpunit

test-js:
	npm test

# Rebuilds .min files, then runs the full JS suite against them (same tests
# as `npm test`, loading the .min build instead of source) to confirm
# minification didn't change behavior.
test-assets-min:
	npm run test:min

build-assets:
	npm run build

# test-assets-min rebuilds assets (via its pretest:min hook) before testing
# them, so the theme package always ships freshly-minified, verified assets.
pack: test-assets-min
	mkdir -p $(DIST_DIR)
	@TMPDIR=$$(mktemp -d); \
	cp -r $(CURDIR) $$TMPDIR/$(THEME_NAME); \
	cd $$TMPDIR && zip -r $(DIST_DIR)/$(THEME_NAME).zip $(THEME_NAME) \
		--exclude "$(THEME_NAME)/.git/*" \
		--exclude "$(THEME_NAME)/dist/*" \
		--exclude "$(THEME_NAME)/node_modules/*" \
		--exclude "$(THEME_NAME)/vendor/*" \
		--exclude "$(THEME_NAME)/coverage/*" \
		--exclude "$(THEME_NAME)/Makefile" \
		--exclude "$(THEME_NAME)/.gitignore" \
		--exclude "$(THEME_NAME)/.claude/*" \
		--exclude "$(THEME_NAME)/TODO.md"; \
	rm -rf $$TMPDIR
	@echo "Packed → $(DIST_DIR)/$(THEME_NAME).zip"

clean:
	rm -f $(DIST_DIR)/$(THEME_NAME).zip
	@echo "Removed $(DIST_DIR)/$(THEME_NAME).zip"
