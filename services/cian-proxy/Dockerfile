# Build stage
FROM golang:1.24-alpine AS builder

WORKDIR /app

# Устанавливаем зависимости для сборки
RUN apk add --no-cache gcc musl-dev

# Копируем go.mod и go.sum
COPY go.mod go.sum ./

# Загружаем зависимости
RUN go mod download

# Копируем исходный код
COPY . .

# Собираем бинарник
RUN CGO_ENABLED=0 GOOS=linux go build -a -installsuffix cgo -o cian-proxy .

# Runtime stage
FROM alpine:3.19

WORKDIR /app

# Устанавливаем ca-certificates для HTTPS запросов
RUN apk --no-cache add ca-certificates tzdata

# Копируем бинарник из builder stage
COPY --from=builder /app/cian-proxy .

# Устанавливаем timezone
ENV TZ=Europe/Moscow

# Порт по умолчанию
ENV PORT=4829

EXPOSE 4829

# Запускаем сервис
CMD ["./cian-proxy"]
