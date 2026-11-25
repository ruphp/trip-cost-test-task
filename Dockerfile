FROM php:8.4-cli

# Локальный образ проекта. Тут нет зависимостей кроме PHP + SQLite (pdo_sqlite есть в официальном образе).
# Префикс имени образа задаём в docker-compose.yml: test1_php:8.4-cli

WORKDIR /app