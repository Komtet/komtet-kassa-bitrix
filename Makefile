SHELL:=/bin/bash
VERSION=$(shell grep -o '^[0-9]\+\.[0-9]\+\.[0-9]\+' CHANGELOG.rst | head -n1)

# Colors
Color_Off=\033[0m
Red=\033[1;31m


version:  ## Версия проекта
	@echo -e "${Red}Version:${Color_Off} $(VERSION)";

release:  ## Создание релиза маркет/github
	@./create_release.bash $(VERSION)

.PHONY: version  release
.DEFAULT_GOAL := version
