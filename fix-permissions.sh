#!/bin/bash

# Скрипт для исправления прав доступа в проекте

echo "Исправление прав доступа для файлов проекта..."

# Получаем текущего пользователя
CURRENT_USER=$(whoami)

# Исправляем права доступа для всех файлов в проекте
sudo chown -R $CURRENT_USER:$CURRENT_USER .

# Делаем скрипты исполняемыми
sudo chmod +x start-dev.sh fix-permissions.sh

# Проверяем наличие .env файла и исправляем права
if [ -f .env ]; then
    sudo chmod 644 .env
fi

# Проверяем наличие .env.dev файла и исправляем права
if [ -f .env.dev ]; then
    sudo chmod 644 .env.dev
fi

# Проверяем наличие .env.prod файла и исправляем права
if [ -f .env.prod ]; then
    sudo chmod 644 .env.prod
fi

# Создаем необходимые директории и проверяем права
mkdir -p logs
sudo chmod -R 755 logs

echo "Права доступа успешно исправлены!"
echo "Теперь вы можете запустить проект через './start-dev.sh' или 'make up'" 