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

start_web7:  ## Запустить контейнер с php7
	@docker-compose up -d web7

start_web_8_1:  ## Запустить контейнер с php 8.1
	@docker-compose up -d web_8_1

start_web_8_2:  ## Запустить контейнер с php 8.2
	@docker-compose up -d web_8_2

stop:  ## Остановить контейнер
	@docker-compose down

update_kassa:  ##Обновить плагин для фискализации
	@cp -r -f komtet.kassa php/bitrix/modules/ && cp -r -f lib php/bitrix/modules/komtet.kassa

update_delivery:  ##Обновить плагин для доставки
	@cp -r -f komtet-kassa-bitrix-delivery/komtet.delivery php/bitrix/modules/

.PHONY: version  release
.DEFAULT_GOAL := version
