SHELL:=/bin/bash
VERSION=$(shell grep -o '^[0-9]\+\.[0-9]\+\.[0-9]\+' CHANGELOG.rst | head -n1)

# Colors
COLOR_OFF=\033[0m
RED=\033[1;31m
YELLOW_BRIGHT := \033[0;93m

help:
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST) | sort

version:  ## Версия проекта
	@echo -e "${RED}Version:${COLOR_OFF} $(VERSION)";

release:  ## Создание релиза маркет/github
	@echo -e "\n${YELLOW_BRIGHT}Перед релизом необходимо провести аудит безопасности при помощи скилла, размещенного по ссылке в файле skills/marketplace-security-skills.md\n" &&\
	 ./create_release.bash $(VERSION)

build:  ## Собрать контейнер
	@sudo chmod -R 777 php/ &&\
	 docker-compose build

start_web_8_2:  ## Запустить контейнер с php 8.2
	@docker-compose up web_8_2 phpmyadmin

stop:  ## Остановить контейнер
	@docker-compose down

update_kassa:  ##Обновить плагин для фискализации
	@cp -r -f komtet.kassa php/bitrix/modules/ &&\
	 echo "Плагин Komtet.kassa обновлен"

update_delivery:  ##Обновить плагин для доставки
	@cp -r -f komtet-kassa-bitrix-delivery/komtet.delivery php/bitrix/modules/ &&\
	 echo "Плагин Komtet.delivery обновлен"

.PHONY: version  release
.DEFAULT_GOAL := version
