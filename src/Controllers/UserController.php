<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Traits\ResponseTrait;
use App\Services\UserSettingsService;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\User;
use Carbon\Carbon;
use App\Utils\PasswordGenerator;
use App\Services\TelegramService;
use App\Services\UserService;
use Slim\Psr7\Stream;

class UserController
{
    use ResponseTrait;

    private UserSettingsService $userSettingsService;
    private TelegramService $telegramService;
    private UserService $userService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->userSettingsService = $container->get(UserSettingsService::class);
        $this->telegramService = $container->get(TelegramService::class);
        $this->userService = $container->get(UserService::class);
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

        $allFields = ['settings', 'sources', 'active_subscriptions'];
        $missingFields = [];
        foreach ($allFields as $field) {
            if (!array_key_exists($field, $data)) {
                $missingFields[] = $field;
            }
        }
        if (!empty($missingFields)) {
            return 'Отсутствуют ключи: ' . implode(', ', $missingFields);
        }

        // Проверяем только settings и sources — active_subscriptions может быть пустым
        if (empty($data['settings'])) {
            return 'Пустые значения: settings';
        }
        
        // sources может быть пустым если источников нет в системе
        if (!is_array($data['sources'])) {
            return 'sources должен быть массивом';
        }
        
        // active_subscriptions может быть пустым если нет активных подписок
        if (!is_array($data['active_subscriptions'])) {
            return 'active_subscriptions должен быть массивом';
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

            $updatedSettings = $this->userSettingsService->updateUserSettings($userId, $data);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Настройки успешно обновлены.',
                'data' => $updatedSettings
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

    /**
     * Получение полной информации о пользователе
     */
    public function getUserInfo(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $user = User::with(['settings', 'activeSubscriptions'])->find($userId);

            if (!$user) {
                return $this->respondWithError($response, 'Пользователь не найден', 'not_found', 404);
            }

            // Получаем самую позднюю активную подписку
            $latestSubscription = $user->activeSubscriptions
                ->sortByDesc('end_date')
                ->first();

            // Формируем текст о статусе подписки
            if ($latestSubscription) {
                $endDate = $latestSubscription->end_date;
                $remainingDays = max(0, Carbon::now()->diffInDays($endDate, false));
                $subscriptionStatusText = sprintf(
                    "Доступ до %s\nОсталось %d %s",
                    $endDate->format('d.m.Y H:i'),
                    $remainingDays,
                    $this->pluralizeDays($remainingDays)
                );
            } else {
                $subscriptionStatusText = "Нет активной подписки";
            }

            // Формируем ответ
            $responseData = [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'telegram_photo_url' => $user->telegram_photo_url,
                    'role' => $user->role,
                    'is_trial_used' => $user->is_trial_used,
                    'phone_status' => $user->phone_status,
                    'app_connected' => $user->app_connected ?? false,
                    'app_last_ping_at' => $user->app_last_ping_at?->toIso8601String(),
                    'auto_call' => $user->settings ? $user->settings->auto_call : false,
                    'auto_call_raised' => $user->settings ? $user->settings->auto_call_raised : false,
                    'has_active_subscription' => $user->hasAnyActiveSubscription(),
                    'subscription_status_text' => $subscriptionStatusText
                ]
            ];

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $responseData
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }

    /**
     * Получение статуса телефона и автозвонка пользователя
     */
    public function getUserStatus(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $user = User::with(['settings'])->find($userId);

            if (!$user) {
                return $this->respondWithError($response, 'Пользователь не найден', 'not_found', 404);
            }

            // Формируем ответ
            $responseData = [
                'phone_status' => $user->phone_status,
                'auto_call' => $user->settings ? $user->settings->auto_call : false,
                'auto_call_raised' => $user->settings ? $user->settings->auto_call_raised : false
            ];

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $responseData
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }

    /**
     * Валидация данных автозвонка
     * 
     * @param array $data Данные для валидации
     * @return string|null Сообщение с ошибкой или null если валидация прошла успешно
     */
    private function validateAutoCallData(array $data): string|null
    {
        if (!isset($data['auto_call'])) {
            return 'Отсутствует обязательное поле auto_call';
        }

        if (!is_bool($data['auto_call'])) {
            return 'Поле auto_call должно быть boolean';
        }

        return null;
    }

    /**
     * Обновление статуса автозвонка
     */
    public function updateAutoCall(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();

            $errors = $this->validateAutoCallData($data);
            if ($errors !== null) {
                $message = 'Неверный формат запроса. ' . $errors;
                return $this->respondWithError($response, $message, "validation_error", 400);
            }

            $this->userSettingsService->updateAutoCall($userId, $data['auto_call']);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Статус автозвонка успешно обновлен',
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }

    /**
     * Валидация данных автозвонка на поднятые объявления
     * 
     * @param array $data Данные для валидации
     * @return string|null Сообщение с ошибкой или null если валидация прошла успешно
     */
    private function validateAutoCallRaisedData(array $data): string|null
    {
        if (!isset($data['auto_call_raised'])) {
            return 'Отсутствует обязательное поле auto_call_raised';
        }

        if (!is_bool($data['auto_call_raised'])) {
            return 'Поле auto_call_raised должно быть boolean';
        }

        return null;
    }

    /**
     * Склонение слова "день" в зависимости от числа
     */
    private function pluralizeDays(int $number): string
    {
        $lastTwo = $number % 100;
        $lastOne = $number % 10;
        
        if ($lastTwo >= 11 && $lastTwo <= 19) {
            return 'дней';
        }
        
        if ($lastOne === 1) {
            return 'день';
        }
        
        if ($lastOne >= 2 && $lastOne <= 4) {
            return 'дня';
        }
        
        return 'дней';
    }

    /**
     * Обновление статуса автозвонка на поднятые объявления
     */
    public function updateAutoCallRaised(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();

            $errors = $this->validateAutoCallRaisedData($data);
            if ($errors !== null) {
                $message = 'Неверный формат запроса. ' . $errors;
                return $this->respondWithError($response, $message, "validation_error", 400);
            }

            $this->userSettingsService->updateAutoCallRaised($userId, $data['auto_call_raised']);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Статус автозвонка на поднятые объявления успешно обновлен',
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }

    /**
     * Получение логина для приложения
     */
    public function getAppLogin(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'login' => (string)$userId
                ]
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }

    /**
     * Генерация нового пароля для приложения
     * @throws GuzzleException
     */
    public function generatePassword(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $user = $this->userService->getUserById($userId);

            if (!$user) {
                return $this->respondWithError($response, 'Пользователь не найден', 'not_found', 404);
            }

            $newPassword = PasswordGenerator::generate(6);
            $this->userService->updateUserPassword($userId, $newPassword);

            // Отправляем уведомление через Telegram
            $this->telegramService->sendPasswordNotification($user, $newPassword);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Новый пароль сгенерирован и отправлен в Telegram'
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError(
                $response,
                'Ошибка при генерации пароля: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    /**
     * Скачивание приложения для Android
     * Доступно только авторизованным пользователям
     */
    public function downloadAndroidApp(Request $request, Response $response): Response
    {
        try {
            $filePath = __DIR__ . '/../../storage/downloads/firstcall.apk';
            
            if (!file_exists($filePath)) {
                return $this->respondWithError(
                    $response, 
                    'Файл приложения не найден. Обратитесь в поддержку.', 
                    'file_not_found', 
                    404
                );
            }

            $fileSize = filesize($filePath);
            $fileName = 'FirstCall.apk';

            // Устанавливаем заголовки для скачивания
            $response = $response
                ->withHeader('Content-Type', 'application/vnd.android.package-archive')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
                ->withHeader('Content-Length', (string)$fileSize)
                ->withHeader('Cache-Control', 'no-cache, must-revalidate')
                ->withHeader('Pragma', 'no-cache');

            // Отдаём файл
            $stream = fopen($filePath, 'rb');
            return $response->withBody(new Stream($stream));

        } catch (Exception $e) {
            return $this->respondWithError(
                $response, 
                'Ошибка при скачивании: ' . $e->getMessage(), 
                null, 
                500
            );
        }
    }

    /**
     * Получение информации о доступных приложениях для скачивания
     */
    public function getDownloadInfo(Request $request, Response $response): Response
    {
        try {
            $androidPath = __DIR__ . '/../../storage/downloads/firstcall.apk';
            $androidAvailable = file_exists($androidPath);
            $androidSize = $androidAvailable ? filesize($androidPath) : null;

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'android' => [
                        'available' => $androidAvailable,
                        'size' => $androidSize,
                        'size_formatted' => $androidSize ? $this->formatFileSize($androidSize) : null,
                        'download_url' => '/api/v1/me/download/android',
                    ],
                    'ios' => [
                        'available' => false,
                        'size' => null,
                        'download_url' => null,
                    ]
                ]
            ], 200);

        } catch (Exception $e) {
            return $this->respondWithError($response, $e->getMessage(), null, 500);
        }
    }

    /**
     * Форматирование размера файла
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
} 