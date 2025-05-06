<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TelegramAuthService;
use App\Traits\ResponseTrait;
use Exception;
use App\Services\LogService;
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
    private LogService $logService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->telegramAuthService = $container->get(TelegramAuthService::class);
        $this->logService = $container->get(LogService::class);
    }

    /**
     * Валидация данных авторизации Telegram
     * @param mixed $data Данные для валидации
     * @return string|null Текст ошибки или null если ошибок нет
     * 1. Проверяем, что данные являются массивом
     * 2. Проверяем наличие всех полей
     * 3. Проверяем заполненность обязательных полей
     */
    private function validateTelegramAuthData(mixed $data):string|null
    {
        if (!is_array($data)) {
            return 'Данные должны быть переданы в формате JSON';
        }

        $allFields = ['id', 'first_name', 'last_name', 'auth_date', 'hash'];
        $missingFields = [];
        foreach ($allFields as $field) {
            if (!array_key_exists($field, $data)) {
                $missingFields[] = $field;
            }
        }
        if (!empty($missingFields)) {
            return 'Отсутствуют поля: ' . implode(', ', $missingFields);
        }

        $requiredFields = ['id', 'first_name', 'auth_date', 'hash'];
        $emptyFields = [];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $emptyFields[] = $field;
            }
        }
        if (!empty($emptyFields)) {
            return 'Обязательные поля не заполнены: ' . implode(', ', $emptyFields);
        }

        return null;
    }

    /**
     * Авторизация через Telegram
     * @throws Exception
     */
    public function authenticate(Request $request, Response $response, $deviceType = 'web'): Response
    {
        try {

            $data = $request->getParsedBody();
            $errors = $this->validateTelegramAuthData($data);

            if (!is_null($errors)) {
                $message = 'Неверный формат запроса. ' . $errors;
                $this->logService->warning('Неверный формат запроса', [
                    'code'   => 400,
                    'errors' => $errors,
                    'data'   => $data],
                    'telegram_auth'
                );

                return $this->respondWithError($response, $message,null,400);
            }

            // Получаем токены авторизации через Telegram
            $authData = $this->telegramAuthService->authenticateUserByTelegram($data, $deviceType);

            if (!$authData) {
                $message = 'Ошибка авторизации через Telegram';
                $this->logService->error($message, [
                    'code'   => 422,
                    'errors' => null,
                    'data'   => $authData],
                    'telegram_auth'
                );

                return $this->respondWithError($response, $message,null,422);
            }

            $sameSite = '; SameSite=None';
            $expiresGMT = gmdate('D, d M Y H:i:s T', $authData['expires_in']);

            $cookie[] = sprintf(
                'refreshToken=%s; path=/api/v1/auth; domain=.%s; max-age=%d; expires=%s; HttpOnly; Secure%s',
                urlencode($authData['refresh_token']),
                $_ENV['HOST'],
                (int)$authData['expires_in'],
                $expiresGMT,
                $sameSite
            );

            $message = 'Успешная авторизация через Telegram';
            $this->logService->info($message, [
                'code' => 200,
                'data' => $authData]
                ,'telegram_auth'
            );

            return $this->respondWithData($response, [
                'code'         => 200,
                'status'       => 'success',
                'message'      => $message,
                'access_token' => $authData['access_token']
            ], 200, $cookie);

        } catch (Exception $e) {
            $this->logService->warning('Внутренняя ошибка сервера', [
                'code'   => 500,
                'errors' => $e->getMessage(),
                'data'   => $data ?? null],
                'telegram_auth'
            );

            return $this->respondWithError($response,null,null,500);
        }
    }

    /**
     * Возвращает имя Telegram-бота
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getBotUsername(Response $response): Response
    {
        try {

            $config = $this->container->get('config');
            $botUsername = $config['telegram']['bot_username'] ?? '';

            $response->getBody()->write($botUsername);
            return $response->withHeader('Content-Type', 'text/plain');

        } catch (Exception) {

            return $this->respondWithError($response,null,null,500);
        }
    }
} 