SHELL = /bin/bash
### https://makefiletutorial.com/

docker := docker run -it -v $(PWD):/app phperrorcatcher56
composer := $(docker) composer

docker-build:
	docker build -t phperrorcatcher56 .

bash:
	$(docker) sh

composer-install:
	$(composer) install

composer-up:
	$(composer) update $(name) --no-plugins

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

test:
	$(composer) phpunit

psalm:
	$(docker) vendor/bin/psalm
