.PHONY: up down logs reset test

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
