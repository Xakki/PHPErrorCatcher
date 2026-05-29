SHELL = /bin/bash
### https://makefiletutorial.com/

# Авто: -it только если stdin реально tty (локальный шелл).
# В CI / при пайпе stdin не tty → флаг пустой, docker не падает.
TTY ?= $(shell if [ -t 0 ]; then echo "-it"; fi)

docker := docker run --rm $(TTY) -v $(PWD):/app phperrorcatcher
composer := $(docker) composer

.PHONY: help docker-build bash composer-i composer-u cs-fix cs-check cs-file test phpstan lint check
.DEFAULT_GOAL := help

##@ Help
help:  ## Показать это меню
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

##@ Docker
docker-build:  ## Собрать образ phperrorcatcher
	docker build -t phperrorcatcher .

bash:  ## Шелл в контейнере
	$(docker) bash

##@ Deps
composer-i:  ## composer install
	$(composer) install

composer-u:  ## composer update (name=пакет — обновить один)
	$(composer) update $(name)

##@ QA
check: cs-check phpstan test  ## Прогнать всё: code style + phpstan + тесты
cs-fix:  ## Автофикс code style (phpcbf)
	$(composer) cs-fix

cs-check:  ## Проверка code style (phpcs)
	$(composer) cs-check

cs-file:  ## phpcs одного файла (file=path/rel.php) — используется хуком
	$(docker) vendor/bin/phpcs $(file)

test:  ## PHPUnit
	$(composer) phpunit

phpstan:  ## PHPStan (level 8)
	$(composer) phpstan

# Быстрый синтаксический линт через `php -l`.
#   make lint            — все .php в src/ и tests/
#   make lint file=path  — конкретный файл (путь относительно корня репо)
lint:  ## php -l (file=path — один файл, иначе весь src/ и tests/)
ifdef file
	$(docker) php -l $(file)
else
	$(docker) sh -c 'find src tests -name "*.php" -print0 | xargs -0 -n1 -P4 php -l'
endif
