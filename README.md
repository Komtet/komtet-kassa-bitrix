# Модуль КОМТЕТ Кассы для CMS Bitrix
komtet-kassa-bitrix

## Запуск проекта
* Скачать проект
```sh
git clone --recursive git@github.com:Komtet/komtet-kassa-bitrix.git
```

* Скачать установщик Bitrix CMS (Бизнес) - http://www.1c-bitrix.ru/download/cms.php

* Добавить в /etc/hosts  127.0.0.1 bitrix.localhost.ru
```sh
127.0.0.1 bitrix.localhost.ru
```
* Добавить bitrix_nginx.cfg в sites-enabled nginx
```sh
sudo cp [путь_до_проекта]/komtet-kassa-bitrix/bitrix_nginx.cfg /etc/nginx/sites-enabled
```
* Перезапустить nginx
```sh
sudo nginx -t
```
```sh
sudo nginx -s reload
```
* Создать папку php в папке komtet-kassa-bitrix
```sh
mkdir php
```
* Распаковать архив Bitrix CMS в папку php

* Установить права на папку php
```sh
sudo chmod -R 777 php
```
* Перейти в директорию проекта и запустить сборку контейнера
```sh
make build
```
* Запустить проект с php7
```sh
make start_web7
```

* Запустить проект с php8
```sh
make start_web8
```
* Проект будет доступен по адресу: http://bitrix.localhost.ru

## Установка Bitrix
* Создание базы данных
```sh
    сервер : mysql
    имя пользователя : devuser
    пароль : devpass
    имя БД : test_db
    Порт : 9906
```

* Выполнить установку CMS Bitrix

## Установка / обновление плагина Комтет Кассы для фискализации чеков
* Изменить кодировки всех файлов в папке плагина lang на UTF-8, сохранить.

* Установить / Обновить плагин Комтет Кассы для обычной фискализации (будет создан модуль в папке modules)
```sh
make update_kassa
```

## Установка / обновление плагина Комтет Кассы для доставки
* Если так же необходимо установить плагин Комтет Кассы для курьерской доставки, то клонируем репозиторий рядом с папкой плагина для фискализации чеков:
```sh
git clone --recursive git@github.com:Komtet/komtet-kassa-bitrix-delivery.git
```
* Изменить кодировки всех файлов в папке плагина lang на UTF-8, сохранить.

* Установить / Обновить плагин Комтет Кассы для доставки (будет создан модуль в папке modules)
```sh
make update_delivery
```

* По ссылке https://www.1c-bitrix.ru/bsm_register.php получить тестовый лицензионный ключ
* Добавить тестовый лицензионный ключ на странице "Обновление платформы" http://bitrix.localhost.ru/bitrix/admin/update_system.php?lang=ru

## Доступные комманды из Makefile

* Собрать проект
```sh
make build
```
* Запустить проект
```sh
make start
```
* Остановить проект
```sh
make stop
```
* Версия проекта
```sh
make version
```
* Сборка релиза
```sh
make release
```
