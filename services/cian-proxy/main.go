package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/Danny-Dasilva/CycleTLS/cycletls"
	"golang.org/x/sync/semaphore"
)

var (
	// Семафор для ограничения одновременных запросов
	maxConcurrent = int64(50)
	sem           = semaphore.NewWeighted(maxConcurrent)
	// Глобальный клиент CycleTLS для переиспользования соединений
	globalClient cycletls.CycleTLS

	// Дефолтные заголовки для Cian
	defaultHeaders = map[string]string{
		"accept":             "*/*",
		"accept-encoding":    "gzip, deflate, br, zstd",
		"accept-language":    "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
		"cache-control":      "no-cache",
		"pragma":             "no-cache",
		"priority":           "u=1, i",
		"sec-ch-ua":          `"Google Chrome";v="143", "Chromium";v="143", "Not A(Brand";v="24"`,
		"sec-ch-ua-mobile":   "?0",
		"sec-ch-ua-platform": `"Windows"`,
		"sec-fetch-dest":     "empty",
		"sec-fetch-mode":     "cors",
		"sec-fetch-site":     "same-site",
	}

	// Дефолтный User-Agent
	defaultUserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36"
)

func main() {
	port := os.Getenv("PORT")
	if port == "" {
		port = "4829"
	}

	// Инициализируем глобальный CycleTLS клиент
	globalClient = cycletls.Init()
	log.Println("CycleTLS клиент инициализирован")

	// Устанавливаем TLS 1.3
	if err := os.Setenv("tls13", "1"); err != nil {
		log.Printf("Ошибка установки tls13: %v", err)
	}

	router := http.NewServeMux()
	router.HandleFunc("/handle", Handle)
	router.HandleFunc("/health", HealthCheck)

	server := &http.Server{
		Addr:           ":" + port,
		Handler:        router,
		ReadTimeout:    60 * time.Second,
		WriteTimeout:   120 * time.Second,
		IdleTimeout:    120 * time.Second,
		MaxHeaderBytes: 1 << 20,
	}

	// Graceful shutdown
	go func() {
		sigChan := make(chan os.Signal, 1)
		signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
		<-sigChan

		log.Println("Получен сигнал завершения, останавливаем сервер...")

		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()

		if err := server.Shutdown(ctx); err != nil {
			log.Printf("Ошибка при остановке сервера: %v", err)
		}

		globalClient.Close()
		log.Println("Сервер остановлен")
	}()

	fmt.Printf("Cian proxy запущен на порту %s\n", port)
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("Ошибка запуска сервера: %v", err)
	}
}

// HealthCheck — эндпоинт для проверки работоспособности
func HealthCheck(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	json.NewEncoder(w).Encode(map[string]string{"status": "ok"})
}

// Handle — основной обработчик запросов
func Handle(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")

	defer func() {
		if r.Body != nil {
			r.Body.Close()
		}
	}()

	// Ограничиваем количество одновременных запросов
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	if err := sem.Acquire(ctx, 1); err != nil {
		log.Printf("Не удалось получить семафор: %v", err)
		sendError(w, "server overloaded", http.StatusTooManyRequests)
		return
	}
	defer sem.Release(1)

	var req HandleRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		log.Printf("Невалидный запрос: %v", err)
		sendError(w, fmt.Sprintf("invalid request: %v", err), http.StatusBadRequest)
		return
	}

	// Валидация URL
	if req.Url == "" {
		sendError(w, "url is required", http.StatusBadRequest)
		return
	}

	// Метод по умолчанию
	if req.Method == "" {
		req.Method = "GET"
	}

	// Логируем входящий запрос
	log.Printf("➡️  %s %s", req.Method, req.Url)

	// Конвертируем куки из запроса в формат CycleTLS
	var cookies []cycletls.Cookie
	for _, cookie := range req.Cookies {
		cookies = append(cookies, cycletls.Cookie{
			Name:    cookie.Name,
			Value:   cookie.Value,
			Path:    cookie.Path,
			Domain:  cookie.Domain,
			Expires: cookie.Expires,
			MaxAge:  cookie.MaxAge,
			Secure:  cookie.Secure,
		})
	}

	// Собираем заголовки: сначала дефолтные, потом переданные (перезаписывают)
	headers := make(map[string]string)
	for k, v := range defaultHeaders {
		headers[k] = v
	}
	if req.Headers != nil {
		for k, v := range req.Headers {
			headers[k] = v
		}
	}

	// User-Agent: переданный или дефолтный
	userAgent := req.UserAgent
	if userAgent == "" {
		userAgent = defaultUserAgent
	}

	// Устанавливаем таймаут по умолчанию
	if req.Timeout == 0 {
		req.Timeout = 30000
	}

	// Выполняем запрос через CycleTLS
	resp, err := globalClient.Do(req.Url, cycletls.Options{
		Cookies:         cookies,
		Body:            req.Body,
		Proxy:           req.Proxy,
		Timeout:         req.Timeout,
		Headers:         headers,
		Ja3:             req.Ja3,
		UserAgent:       userAgent,
		DisableRedirect: req.DisableRedirect,
	}, req.Method)

	if err != nil {
		log.Printf("❌ Ошибка: %s → %v", req.Url, err)
		sendErrorResponse(w, err.Error())
		return
	}

	// Логируем ответ
	log.Printf("⬅️  %s → %d", req.Url, resp.Status)

	// Формируем ответ
	response := HandleResponse{
		Body:   resp.Body,
		Status: resp.Status,
	}

	json.NewEncoder(w).Encode(response)
}

// sendError отправляет HTTP ошибку
func sendError(w http.ResponseWriter, message string, statusCode int) {
	w.WriteHeader(statusCode)
	json.NewEncoder(w).Encode(HandleResponse{
		Status: statusCode,
		Error:  message,
	})
}

// sendErrorResponse отправляет ошибку с 200 статусом (для совместимости)
func sendErrorResponse(w http.ResponseWriter, message string) {
	json.NewEncoder(w).Encode(HandleResponse{
		Status: 0,
		Error:  message,
	})
}

// RequestCookie — формат куки во входящем запросе
type RequestCookie struct {
	Name    string    `json:"name"`
	Value   string    `json:"value"`
	Path    string    `json:"path"`
	Domain  string    `json:"domain"`
	Expires time.Time `json:"expires"`
	MaxAge  int       `json:"maxAge"`
	Secure  bool      `json:"secure"`
}

// HandleRequest — структура входящего запроса
type HandleRequest struct {
	Url             string            `json:"url"`
	Method          string            `json:"method"`
	Headers         map[string]string `json:"headers"`
	Cookies         []RequestCookie   `json:"cookies"`
	Body            string            `json:"body"`
	Proxy           string            `json:"proxy"`
	Timeout         int               `json:"timeout"`
	UserAgent       string            `json:"userAgent"`
	Ja3             string            `json:"ja3"`
	DisableRedirect bool              `json:"disableRedirect"`
}

// HandleResponse — структура ответа
type HandleResponse struct {
	Body   string `json:"body"`
	Status int    `json:"status"`
	Error  string `json:"error,omitempty"`
}
