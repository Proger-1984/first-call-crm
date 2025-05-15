#!/bin/bash

# Скрипт для анализа проекта и определения оптимальной версии Claude
# Автор: Claude 3.7 Sonnet

# Цветовая кодировка для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Директория для анализа (по умолчанию - текущая)
PROJECT_DIR="."
if [ -n "$1" ]; then
    PROJECT_DIR="$1"
fi

echo -e "${BLUE}======================================================${NC}"
echo -e "${BLUE}        АНАЛИЗАТОР ПРОЕКТА ДЛЯ CLAUDE AI${NC}"
echo -e "${BLUE}======================================================${NC}"
echo -e "Анализируем проект в директории: ${GREEN}$PROJECT_DIR${NC}\n"

# Исключаемые директории
EXCLUDE_DIRS=(
    "node_modules"
    "vendor"
    "dist"
    "build"
    ".git"
    ".idea"
    ".vscode"
)

# Формирование строки исключений для команд find
EXCLUDE_STRING=""
for dir in "${EXCLUDE_DIRS[@]}"; do
    EXCLUDE_STRING="$EXCLUDE_STRING -not -path \"*/$dir/*\""
done

# Определение языков программирования в проекте
echo -e "${YELLOW}Определение основных языков программирования...${NC}"
eval "find $PROJECT_DIR -type f $EXCLUDE_STRING | grep -v 'package-lock.json' | grep -v 'yarn.lock' | sed 's/.*\.//' | sort | uniq -c | sort -nr | head -10"
echo ""

# Подсчет общего количества файлов
echo -e "${YELLOW}Подсчет файлов в проекте (исключая зависимости)...${NC}"
FILE_COUNT=$(eval "find $PROJECT_DIR -type f $EXCLUDE_STRING | wc -l")
echo -e "Всего файлов: ${GREEN}$FILE_COUNT${NC}"

# Подсчет количества строк кода
echo -e "\n${YELLOW}Анализ строк кода...${NC}"
LINE_COUNT=$(eval "find $PROJECT_DIR -type f $EXCLUDE_STRING | xargs cat 2>/dev/null | wc -l")
echo -e "Всего строк кода: ${GREEN}$LINE_COUNT${NC}"

# Определение размера проекта
echo -e "\n${YELLOW}Анализ размера проекта...${NC}"
PROJECT_SIZE=$(du -sh "$PROJECT_DIR" --exclude={$(IFS=,; echo "${EXCLUDE_DIRS[*]}")} | awk '{print $1}')
echo -e "Размер проекта (без зависимостей): ${GREEN}$PROJECT_SIZE${NC}"

# Нахождение наиболее крупных файлов
echo -e "\n${YELLOW}Наиболее крупные файлы:${NC}"
eval "find $PROJECT_DIR -type f $EXCLUDE_STRING | xargs wc -l 2>/dev/null | sort -nr | head -6 | tail -5" | 
    awk '{printf "%-8s строк: %s\n", $1, $2}'

# Определение максимального размера файла
MAX_FILE_SIZE=$(eval "find $PROJECT_DIR -type f $EXCLUDE_STRING | xargs wc -l 2>/dev/null | sort -nr | head -1" | awk '{print $1}')
if [[ ! "$MAX_FILE_SIZE" =~ ^[0-9]+$ ]]; then
    MAX_FILE_SIZE=0
fi

# Преобразование размера проекта в MB для сравнения
PROJECT_SIZE_NUM=$(echo "$PROJECT_SIZE" | sed 's/[^0-9.]//g')
PROJECT_SIZE_UNIT=$(echo "$PROJECT_SIZE" | sed 's/[0-9.]//g')
PROJECT_SIZE_MB=0

case "$PROJECT_SIZE_UNIT" in
    "K")
        PROJECT_SIZE_MB=$(echo "$PROJECT_SIZE_NUM / 1024" | bc -l)
        ;;
    "M")
        PROJECT_SIZE_MB="$PROJECT_SIZE_NUM"
        ;;
    "G")
        PROJECT_SIZE_MB=$(echo "$PROJECT_SIZE_NUM * 1024" | bc -l)
        ;;
    *)
        PROJECT_SIZE_MB=$(echo "$PROJECT_SIZE_NUM / 1024 / 1024" | bc -l)
        ;;
esac

# Определение баллов сложности проекта
COMPLEXITY_SCORE=0

# Оценка по количеству файлов
if [ "$FILE_COUNT" -gt 500 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 3))
elif [ "$FILE_COUNT" -gt 200 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 2))
elif [ "$FILE_COUNT" -gt 100 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 1))
fi

# Оценка по количеству строк кода
if [ "$LINE_COUNT" -gt 200000 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 3))
elif [ "$LINE_COUNT" -gt 100000 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 2))
elif [ "$LINE_COUNT" -gt 50000 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 1))
fi

# Оценка по размеру проекта
if (( $(echo "$PROJECT_SIZE_MB > 100" | bc -l) )); then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 3))
elif (( $(echo "$PROJECT_SIZE_MB > 50" | bc -l) )); then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 2))
elif (( $(echo "$PROJECT_SIZE_MB > 25" | bc -l) )); then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 1))
fi

# Оценка по максимальному размеру файла
if [ "$MAX_FILE_SIZE" -gt 5000 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 3))
elif [ "$MAX_FILE_SIZE" -gt 2000 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 2))
elif [ "$MAX_FILE_SIZE" -gt 1000 ]; then
    COMPLEXITY_SCORE=$((COMPLEXITY_SCORE + 1))
fi

echo -e "\n${YELLOW}Результаты анализа:${NC}"
echo -e "Общее количество файлов: ${GREEN}$FILE_COUNT${NC}"
echo -e "Общее количество строк кода: ${GREEN}$LINE_COUNT${NC}"
echo -e "Размер проекта: ${GREEN}$PROJECT_SIZE${NC}"
echo -e "Размер наибольшего файла: ${GREEN}$MAX_FILE_SIZE строк${NC}"
echo -e "Оценка сложности проекта: ${GREEN}$COMPLEXITY_SCORE / 12${NC}"

echo -e "\n${BLUE}======================================================${NC}"
echo -e "${YELLOW}РЕКОМЕНДАЦИЯ:${NC}"

if [ "$COMPLEXITY_SCORE" -ge 6 ]; then
    echo -e "${RED}Для вашего проекта рекомендуется использовать Claude 3.7 Sonnet-max${NC}"
    echo "Причины:"
    [ "$FILE_COUNT" -gt 200 ] && echo " - Большое количество файлов"
    [ "$LINE_COUNT" -gt 100000 ] && echo " - Значительный объем кода"
    (( $(echo "$PROJECT_SIZE_MB > 50" | bc -l) )) && echo " - Большой размер проекта"
    [ "$MAX_FILE_SIZE" -gt 2000 ] && echo " - Наличие очень крупных файлов"
elif [ "$COMPLEXITY_SCORE" -ge 3 ]; then
    echo -e "${YELLOW}Для вашего проекта может быть полезен Claude 3.7 Sonnet-max${NC}"
    echo "Однако стандартная версия Claude 3.7 Sonnet также подойдет для большинства задач."
else
    echo -e "${GREEN}Для вашего проекта достаточно стандартной версии Claude 3.7 Sonnet${NC}"
    echo "Размер и сложность проекта не требуют расширенного контекстного окна."
fi
echo -e "${BLUE}======================================================${NC}" 