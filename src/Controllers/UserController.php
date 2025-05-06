<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Traits\ResponseTrait;
use App\Services\UserSettingsService;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController
{
    use ResponseTrait;

    private UserSettingsService $userSettingsService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->userSettingsService = $container->get(UserSettingsService::class);
    }

    /**
     * Получение настроек пользователя
     */
    public function getSettings(Request $request, Response $response): Response
    {
        try {

            $userId = $request->getAttribute('userId');
            $settings = $this->userSettingsService->getUserSettings($userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $settings
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response,null,null,500);
        }
    }

    /**
     * Валидация данных настроек
     * 
     * @param array $data Данные для валидации
     * @return string|null Сообщение с ошибкой или null если валидация прошла успешно
     */
    private function validateSettingsData(array $data): string|null
    {
        if (!is_array($data)) {
            return 'Данные должны быть переданы в формате JSON';
        }

        $allFields = ['settings', 'sources', 'categories'];
        $missingFields = [];
        foreach ($allFields as $field) {
            if (!array_key_exists($field, $data)) {
                $missingFields[] = $field;
            }
        }
        if (!empty($missingFields)) {
            return 'Отсутствуют ключи: ' . implode(', ', $missingFields);
        }

        $requiredFields = ['settings', 'sources', 'categories'];
        $emptyFields = [];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $emptyFields[] = $field;
            }
        }
        if (!empty($emptyFields)) {
            return 'Пустые значения: ' . implode(', ', $emptyFields);
        }

        return null;
    }

    /**
     * Обновление настроек пользователя
     */
    public function updateSettings(Request $request, Response $response): Response
    {
        try {

            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();

            $errors = $this->validateSettingsData($data);
            if (!is_null($errors)) {
                $message = 'Неверный формат запроса. ' . $errors;
                return $this->respondWithError($response, $message,null,400);
            }

            $this->userSettingsService->updateUserSettings($userId, $data);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Настройки успешно обновлены.',
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response,null,null,500);
        }

    }

    /**
     * Валидация данных статуса телефона
     * 
     * @param array $data Данные для валидации
     * @return string|null Сообщение с ошибкой или null если валидация прошла успешно
     */
    private function validatePhoneStatusData(array $data): string|null
    {
        if (!isset($data['status'])) {
            return 'Отсутствует обязательное поле status';
        }

        if (!is_bool($data['status'])) {
            return 'Поле status должно быть boolean';
        }

        return null;
    }

    /**
     * Обновление статуса телефона пользователя
     */
    public function updatePhoneStatus(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();

            $errors = $this->validatePhoneStatusData($data);
            if ($errors !== null) {
                $message = 'Неверный формат запроса. ' . $errors;
                return $this->respondWithError($response, $message, "validation_error", 400);
            }

            $this->userSettingsService->updatePhoneStatus($userId, $data['status']);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Статус телефона успешно обновлен',
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, null, null, 500);
        }
    }
} 