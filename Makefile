SHELL = /bin/bash
### https://makefiletutorial.com/

docker := docker run -it --rm -v $(PWD):/app phperrorcatcher56
composer := $(docker) composer --no-plugins
# psalm 1.1.9 тянет плагин muglug/package-versions-56 с composer-plugin-api ^1.0 — под Composer 2 его игнорируем
composerFlags := --ignore-platform-req=composer-plugin-api

docker-build:
	docker build -t phperrorcatcher56 .

bash:
	$(docker) sh

composer-i:
	$(composer) i $(composerFlags)

composer-u:
	$(composer) u $(name) $(composerFlags)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

# напрямую, минуя bin-proxy Composer 2 (psalm 1.x не умеет phpvfscomposer://)
psalm:
	$(docker) php vendor/vimeo/psalm/psalm

test:
	$(docker) vendor/bin/phpunit
