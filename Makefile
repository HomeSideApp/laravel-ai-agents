# QA commands for the laravel-ai-agents package, run inside Docker.
#
#   make build     Build the development image.
#   make install   composer install (uses composer.lock).
#   make qa        Full pipeline: pint --test, phpstan, phpunit.
#   make shell     Open a shell inside the container.

COMPOSE := docker compose

.PHONY: build install update test lint lint-test analyse qa shell clean

build:
	$(COMPOSE) build

install:
	$(COMPOSE) run --rm qa composer install

update:
	$(COMPOSE) run --rm qa composer update

test:
	$(COMPOSE) run --rm qa vendor/bin/phpunit

lint:
	$(COMPOSE) run --rm qa vendor/bin/pint --config pint.json src config database tests

lint-test:
	$(COMPOSE) run --rm qa vendor/bin/pint --config pint.json --test src config database tests

analyse:
	$(COMPOSE) run --rm qa vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=512M

qa: lint-test analyse test

# Firewall classifier: regenerate the embedded seed model (see
# resources/firewall/model/DATASETS.md for real-dataset training).
train:
	$(COMPOSE) run --rm qa php resources/firewall/model/generate-seed.php

shell:
	$(COMPOSE) run --rm qa sh

clean:
	rm -rf vendor .phpunit.result.cache
