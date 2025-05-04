<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UserSettingsService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController
{
    private UserSettingsService $userSettingsService;

    public function __construct(ContainerInterface $container)
    {
        $this->userSettingsService = $container->get(UserSettingsService::class);
    }

    /**
     * Получение настроек пользователя
     */
    public function getSettings(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $settings = $this->userSettingsService->getUserSettings($userId);

        return $this->respondWithJson($response, [
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    /**
     * Обновление настроек пользователя
     */
    public function updateSettings(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();

        if (!is_array($data)) {
            return $this->respondWithError($response, 'Неверные данные', 400);
        }

        try {
            $updatedSettings = $this->userSettingsService->updateUserSettings($userId, $data);

            return $this->respondWithJson($response, [
                'status' => 'success',
                'message' => 'Настройки успешно обновлены',
                'data' => $updatedSettings,
            ]);
        } catch (\Exception $e) {
            return $this->respondWithError($response, 'Ошибка при обновлении настроек: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Получение всех доступных источников
     */
    public function getSources(Request $request, Response $response): Response
    {
        $sources = $this->userSettingsService->getAllSources();

        return $this->respondWithJson($response, [
            'status' => 'success',
            'data' => $sources,
        ]);
    }

    /**
     * Получение всех доступных категорий
     */
    public function getCategories(Request $request, Response $response): Response
    {
        $categories = $this->userSettingsService->getAllCategories();

        return $this->respondWithJson($response, [
            'status' => 'success',
            'data' => $categories,
        ]);
    }

    /**
     * Возвращает JSON ответ
     */
    private function respondWithJson(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Возвращает ответ с ошибкой
     */
    private function respondWithError(Response $response, string $message, int $status = 400): Response
    {
        return $this->respondWithJson($response, [
            'status' => 'error',
            'message' => $message,
        ], $status);
    }
} 