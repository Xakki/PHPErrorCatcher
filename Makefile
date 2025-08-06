SHELL = /bin/bash
### https://makefiletutorial.com/

docker := docker run -it -v $(PWD):/app phperrorcatcher56
composer := $(docker) composer

docker-build:
	docker build -t phperrorcatcher56 .

bash:
	$(docker) sh

composer-i:
	$(composer) i

composer-u:
	$(composer) u $(name) --no-plugins

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

psalm:
	$(docker) vendor/bin/psalm
