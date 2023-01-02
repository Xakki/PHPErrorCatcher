SHELL = /bin/bash
### https://makefiletutorial.com/

docker := docker run -it -v $(PWD):/app phperrorcatcher
composer := $(docker) composer

docker-build:
	docker build -t phperrorcatcher .

bash:
	$(docker) bash

composer-install:
	$(composer) install

composer-up:
	$(composer) update $(name)

cs-fix:
	$(composer) cs-commit-fix

cs-check:
	$(composer) cs-commit-check

test:
	$(composer) phpunit

phpstan:
	$(docker) composer phpstan

psalm:
	$(docker) vendor/bin/psalm
