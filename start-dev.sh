#!/bin/bash

# Проверяем наличие .env файла
if [ ! -f .env ]; then
    echo "Файл .env не найден. Копируем из .env.example"
    cp .env.example .env
fi

# Проверка прав на запись
if [ ! -w "$(pwd)" ]; then
    echo "ВНИМАНИЕ: У вас нет прав на запись в текущую директорию."
    echo "Выполните: sudo chown -R $(whoami):$(whoami) ."
    exit 1
fi

# Запускаем docker-compose в режиме разработки
docker-compose up -d

echo "Приложение запущено в режиме разработки." 