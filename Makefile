SHELL:=/bin/bash
VERSION=$(shell grep -o '^[0-9]\+\.[0-9]\+\.[0-9]\+' CHANGELOG.rst | head -n1)

# Colors
Color_Off=\033[0m
Red=\033[1;31m

help:
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST) | sort

version:  ## Версия проекта
	@echo -e "${Red}Version:${Color_Off} $(VERSION)";

release:  ## Создание релиза маркет/github
	@./create_release.bash $(VERSION)

build:  ## Собрать контейнер
	@sudo chmod -R 777 php/ &&\
	 docker-compose build

start:  ## Запустить контейнер
	@docker-compose up -d web

stop:  ## Остановить контейнер
	@docker-compose down

update_kassa:  ##Обновить плагин для фискализации
	@cp -r -f komtet-kassa-bitrix/komtet.kassa php/bitrix/modules/ && cp -r -f komtet-kassa-bitrix/lib php/bitrix/modules/komtet.kassa

update_delivery:  ##Обновить плагин для доставки
	@cp -r -f komtet.delivery php/bitrix/modules/

.PHONY: version  release
.DEFAULT_GOAL := version
