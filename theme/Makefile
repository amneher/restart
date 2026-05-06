THEME_NAME := theRestart
DIST_DIR   := $(CURDIR)/dist

.PHONY: install test test-php test-js pack clean

install:
	composer install --no-interaction
	npm ci

test: test-php test-js

test-php:
	./vendor/bin/phpunit

test-js:
	npm test

pack:
	mkdir -p $(DIST_DIR)
	@TMPDIR=$$(mktemp -d); \
	cp -r $(CURDIR) $$TMPDIR/$(THEME_NAME); \
	cd $$TMPDIR && zip -r $(DIST_DIR)/$(THEME_NAME).zip $(THEME_NAME) \
		--exclude "$(THEME_NAME)/.git/*" \
		--exclude "$(THEME_NAME)/dist/*" \
		--exclude "$(THEME_NAME)/Makefile" \
		--exclude "$(THEME_NAME)/.gitignore" \
		--exclude "$(THEME_NAME)/.claude/*" \
		--exclude "$(THEME_NAME)/TODO.md"; \
	rm -rf $$TMPDIR
	@echo "Packed → $(DIST_DIR)/$(THEME_NAME).zip"

clean:
	rm -f $(DIST_DIR)/$(THEME_NAME).zip
	@echo "Removed $(DIST_DIR)/$(THEME_NAME).zip"
