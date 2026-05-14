SHELL = /bin/bash
### https://makefiletutorial.com/

# Авто: -it только если stdin реально tty (локальный шелл).
# В CI / при пайпе stdin не tty → флаг пустой, docker не падает.
TTY ?= $(shell if [ -t 0 ]; then echo "-it"; fi)

docker := docker run --rm $(TTY) -v $(PWD):/app phperrorcatcher
composer := $(docker) composer

docker-build:
	docker build -t phperrorcatcher .

bash:
	$(docker) bash

composer-i:
	$(composer) install

composer-u:
	$(composer) update $(name)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

test:
	$(composer) phpunit

phpstan:
	$(composer) phpstan

# Быстрый синтаксический линт через `php -l`.
# Использование:
#   make lint            — проверить все .php в src/ и tests/
#   make lint file=path  — проверить конкретный файл (путь относительно корня репо)
lint:
ifdef file
	$(docker) php -l $(file)
else
	$(docker) sh -c 'find src tests -name "*.php" -print0 | xargs -0 -n1 -P4 php -l'
endif
