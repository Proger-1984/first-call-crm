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
        $userId = $request->getAttribute('userId');
        $settings = $this->userSettingsService->getUserSettings($userId);

        return $this->respondWithData($response, [
            'code' => 200,
            'status' => 'success',
            'data' => $settings
        ], 200);
    }

    /**
     * Обновление настроек пользователя
     */
    public function updateSettings(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('userId');
        $data = $request->getParsedBody();

        if (!is_array($data)) {
            return $this->respondWithError($response, null, 'validation_error', 422);
        }

        try {
            $this->userSettingsService->updateUserSettings($userId, $data);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Настройки успешно обновлены.',
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка при обновлении настроек.', '', 500);
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
     * Обновление статуса телефона пользователя
     */
    public function updatePhoneStatus(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('userId');
        $data = $request->getParsedBody();

        if (!is_array($data) || !isset($data['status'])) {
            return $this->respondWithError($response, null, 'validation_error', 422);
        }

        try {
            $this->userSettingsService->updatePhoneStatus($userId, $data['status']);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Статус телефона успешно обновлен.',
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, 'Ошибка при обновлении статуса телефона.', '', 500);
        }
    }
} 