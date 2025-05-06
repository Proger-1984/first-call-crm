<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TelegramAuthService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TelegramAuthController
{
    use ResponseTrait;

    private TelegramAuthService $telegramAuthService;
    private ContainerInterface $container;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->telegramAuthService = $container->get(TelegramAuthService::class);
    }

    /**
     * Авторизация через Telegram
     * @throws Exception
     */
    public function authenticate(Request $request, Response $response, $deviceType = 'web'): Response
    {
        $data = $request->getParsedBody();
        if (!$data) {
            return $this->respondWithError($response, null, 'validation_error', 422);
        }

        /** Получаем токены авторизации через Telegram */
        $authData = $this->telegramAuthService->authenticateUserByTelegram($data, $deviceType);

        if (!$authData) {
            return $this->respondWithError($response, 'Ошибка авторизации через Telegram.', 'validation_error', 422);
        }

        $sameSite = '; SameSite=None';
        $expiresGMT = gmdate('D, d M Y H:i:s T', $authData['expires_in']);

        $cookie[] = sprintf(
            'refreshToken=%s; path=/api/v1/auth; domain=.%s; max-age=%d; expires=%s; HttpOnly; Secure%s',
            urlencode($authData['refresh_token']),
            'local.firstcall.com',
            (int)$authData['expires_in'],
            $expiresGMT,
            $sameSite
        );

        return $this->respondWithData($response, [
            'code' => 200,
            'status' => 'success',
            'message' => 'Успешная авторизация через Telegram',
            'access_token' => $authData['access_token']
        ], 200, $cookie);
    }

    /**
     * Возвращает HTML-страницу тестирования Telegram авторизации
     */
    public function testPage(Request $request, Response $response): Response
    {
        $config = $this->container->get('config');
        $botUsername = $config['telegram']['bot_username'] ?? 'your_bot_username';
        
        // Создаем форму для имитации данных от Telegram
        $html = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тестирование Telegram авторизации</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .container {
            max-width: 600px;
            padding: 20px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            text-align: center;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            min-height: 100px;
            overflow: auto;
        }
        .auth-button {
            display: flex;
            justify-content: center;
            margin: 20px 0;
        }
        .success {
            color: #28a745;
            font-weight: bold;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .test-form {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .test-form label {
            display: block;
            margin-bottom: 5px;
        }
        .test-form input, .test-form select {
            width: 100%;
            padding: 8px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .test-form button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
        }
        .telegram-btn {
            background-color: #0088cc;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }
        .telegram-btn img {
            margin-right: 10px;
            width: 24px;
            height: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Тестирование Telegram авторизации</h1>
        
        <div class="auth-button">
            <!-- Официальный виджет Telegram -->
            <div id="telegram-login-{$botUsername}" data-size="large" data-userpic="false" data-auth-url="/api/v1/auth/telegram"></div>
            <script async src="https://telegram.org/js/telegram-widget.js" data-telegram-login="{$botUsername}" data-size="large" data-auth-url="/api/v1/auth/telegram" data-request-access="write"></script>
            
            <!-- Запасная кнопка для локального тестирования -->
            <a href="#" class="telegram-btn" onclick="simulateTelegramAuth()">
                <img src="https://telegram.org/img/t_logo.svg" alt="Telegram Logo">
                Войти через Telegram (тест)
            </a>
        </div>
        
        <p>В локальной среде виджет Telegram может не работать, так как требуется HTTPS. Используйте тестовую форму ниже для имитации авторизации.</p>
        
        <div class="test-form">
            <h3>Тестовая форма для имитации данных Telegram</h3>
            <form id="test-telegram-form">
                <label for="id">ID пользователя Telegram:</label>
                <input type="text" id="id" name="id" value="123456789" required>
                
                <label for="first_name">Имя:</label>
                <input type="text" id="first_name" name="first_name" value="Test User" required>
                
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" value="testuser">
                
                <label for="auth_date">Дата авторизации (Unix timestamp):</label>
                <input type="text" id="auth_date" name="auth_date" value="" required>
                
                <label for="hash">Hash (любой текст для тестирования):</label>
                <input type="text" id="hash" name="hash" value="test_hash" required>
                
                <label for="device_type">Тип устройства:</label>
                <select id="device_type" name="device_type">
                    <option value="web">Web</option>
                    <option value="mobile">Mobile</option>
                </select>
                
                <button type="button" onclick="sendTestData()">Отправить тестовые данные</button>
            </form>
        </div>
        
        <h3>Результат авторизации:</h3>
        <pre class="result" id="result">Ожидание авторизации...</pre>
    </div>
    
    <script>
        // Устанавливаем текущий timestamp для тестовых данных
        document.getElementById('auth_date').value = Math.floor(Date.now() / 1000);
        
        // Функция для получения данных из URL
        function getQueryParams() {
            const params = {};
            const queryString = window.location.search.substring(1);
            const pairs = queryString.split('&');
            
            for (const pair of pairs) {
                const [key, value] = pair.split('=');
                if (key) params[key] = decodeURIComponent(value || '');
            }
            
            return params;
        }
        
        // Функция для имитации Telegram авторизации
        function simulateTelegramAuth() {
            const form = document.getElementById('test-telegram-form');
            sendTestData();
            return false;
        }
        
        // Функция для отправки тестовых данных
        function sendTestData() {
            const formData = new FormData(document.getElementById('test-telegram-form'));
            const data = {};
            
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            const resultElement = document.getElementById('result');
            resultElement.innerHTML = 'Отправка тестовых данных на сервер: <br>' + 
                JSON.stringify(data, null, 2);
            
            // Отправляем данные на сервер для авторизации
            fetch('/api/v1/auth/telegram', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    resultElement.innerHTML += '<div class="success">Авторизация успешна!</div>' +
                        '<br>Ответ сервера: <br>' + JSON.stringify(data, null, 2);
                    // Сохраняем токены в localStorage
                    if (data.data && data.data.access_token) {
                        localStorage.setItem('access_token', data.data.access_token);
                        localStorage.setItem('refresh_token', data.data.refresh_token);
                        localStorage.setItem('expires_in', data.data.expires_in);
                    }
                } else {
                    resultElement.innerHTML += '<div class="error">Ошибка авторизации!</div>' +
                        '<br>Ответ сервера: <br>' + JSON.stringify(data, null, 2);
                }
            })
            .catch(error => {
                resultElement.innerHTML += '<div class="error">Ошибка при отправке запроса: ' + 
                    error.message + '</div>';
            });
        }
        
        // Проверяем, есть ли данные от Telegram в URL
        const params = getQueryParams();
        if (Object.keys(params).length > 0) {
            const resultElement = document.getElementById('result');
            resultElement.innerHTML = 'Получены данные из URL: <br>' + 
                JSON.stringify(params, null, 2);
            
            // Добавляем device_type по умолчанию
            params.device_type = 'web';
            
            // Отправляем данные на сервер для авторизации
            fetch('/api/v1/auth/telegram', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(params)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    resultElement.innerHTML += '<div class="success">Авторизация успешна!</div>' +
                        '<br>Ответ сервера: <br>' + JSON.stringify(data, null, 2);
                    // Сохраняем токены в localStorage
                    if (data.data && data.data.access_token) {
                        localStorage.setItem('access_token', data.data.access_token);
                        localStorage.setItem('refresh_token', data.data.refresh_token);
                        localStorage.setItem('expires_in', data.data.expires_in);
                    }
                } else {
                    resultElement.innerHTML += '<div class="error">Ошибка авторизации!</div>' +
                        '<br>Ответ сервера: <br>' + JSON.stringify(data, null, 2);
                }
            })
            .catch(error => {
                resultElement.innerHTML += '<div class="error">Ошибка при отправке запроса: ' + 
                    error.message + '</div>';
            });
        }
    </script>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html');
    }

    /**
     * Возвращает имя Telegram-бота
     */
    public function getBotUsername(Request $request, Response $response): Response
    {
        $config = $this->container->get('config');
        $botUsername = $config['telegram']['bot_username'] ?? '';
        
        $response->getBody()->write($botUsername);
        return $response->withHeader('Content-Type', 'text/plain');
    }
} 