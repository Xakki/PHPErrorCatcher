SHELL = /bin/bash
### https://makefiletutorial.com/

# Auto: -it only when stdin is really a tty (local shell).
# In CI / when stdin is piped it is not a tty → empty flag, docker does not fail.
TTY ?= $(shell if [ -t 0 ]; then echo "-it"; fi)

# PHP version for the image (minimum supported — 8.3). Matrix run: PHP=8.4 make test-php
PHP ?= 8.3

docker := docker run --rm $(TTY) -v $(PWD):/app phperrorcatcher
composer := $(docker) composer

.PHONY: help docker-build bash composer-i composer-u cs-fix cs-check cs-file test test-php test-php-all phpstan lint check test-db test-db-down
.DEFAULT_GOAL := help

##@ Help
help:  ## Show this menu
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

##@ Docker
docker-build:  ## Build the phperrorcatcher image (PHP=8.4 — another version)
	docker build --build-arg PHP_VERSION=$(PHP) -t phperrorcatcher .

bash:  ## Shell into the container
	$(docker) bash

##@ Deps
composer-i:  ## composer install
	$(composer) install

composer-u:  ## composer update (name=package — update a single one)
	$(composer) update $(name)

##@ QA
check: cs-check phpstan test  ## Run everything: code style + phpstan + tests
cs-fix:  ## Auto-fix code style (phpcbf)
	$(composer) cs-fix

cs-check:  ## Check code style (phpcs)
	$(composer) cs-check

cs-file:  ## phpcs a single file (file=path/rel.php) — used by a hook
	$(docker) vendor/bin/phpcs $(file)

test:  ## PHPUnit
	$(composer) phpunit

# Run the tests on a specific PHP version (the CI matrix, locally):
#   PHP=8.4 make test-php   /   PHP=8.5 make test-php
# Builds a temporary phperrorcatcher:phpX image and runs vendor/bin/phpunit
# on the mounted code (vendor comes from the shared lock — same as in CI).
test-php:  ## PHPUnit on a specific PHP version (PHP=8.3|8.4|8.5; ARGS=… — extra flags)
	docker build --build-arg PHP_VERSION=$(PHP) -t phperrorcatcher:php$(PHP) .
	docker run --rm $(TTY) -v $(PWD):/app phperrorcatcher:php$(PHP) vendor/bin/phpunit --colors=always $(ARGS)

test-php-all:  ## PHPUnit on the whole 8.3/8.4/8.5 matrix
	$(MAKE) test-php PHP=8.3
	$(MAKE) test-php PHP=8.4
	$(MAKE) test-php PHP=8.5

phpstan:  ## PHPStan (level 8)
	$(composer) phpstan

# DB integration tests against mariadb:12 + postgres:17.
# Requires the image to have pdo_mysql + pdo_pgsql — rebuild with: make docker-build
COMPOSE_PROJECT ?= phperrorcatcher
MYSQL_DSN  ?= mysql:host=mariadb;port=3306;dbname=pectest
MYSQL_USER ?= pectest
MYSQL_PASS ?= pectest
PGSQL_DSN  ?= pgsql:host=postgres;port=5432;dbname=pectest
PGSQL_USER ?= pectest
PGSQL_PASS ?= pectest

test-db:  ## Run DB integration tests (spins up mariadb:12 + postgres:17 via compose.test.yml)
	docker compose -p $(COMPOSE_PROJECT) -f compose.test.yml up -d --wait
	docker run --rm $(TTY) \
		--network $(COMPOSE_PROJECT)_default \
		-v $(PWD):/app \
		-e PEC_TEST_MYSQL_DSN='$(MYSQL_DSN)' \
		-e PEC_TEST_MYSQL_USER='$(MYSQL_USER)' \
		-e PEC_TEST_MYSQL_PASS='$(MYSQL_PASS)' \
		-e PEC_TEST_PGSQL_DSN='$(PGSQL_DSN)' \
		-e PEC_TEST_PGSQL_USER='$(PGSQL_USER)' \
		-e PEC_TEST_PGSQL_PASS='$(PGSQL_PASS)' \
		phperrorcatcher vendor/bin/phpunit --colors=always --filter=PdoStorage $(ARGS)
	$(MAKE) test-db-down

test-db-down:  ## Tear down the DB services from compose.test.yml
	docker compose -p $(COMPOSE_PROJECT) -f compose.test.yml down -v

# Quick syntax lint via `php -l`.
#   make lint            — all .php in src/ and tests/
#   make lint file=path  — a specific file (path relative to the repo root)
lint:  ## php -l (file=path — a single file, otherwise all of src/ and tests/)
ifdef file
	$(docker) php -l $(file)
else
	$(docker) sh -c 'find src tests -name "*.php" -print0 | xargs -0 -n1 -P4 php -l'
endif
