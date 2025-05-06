<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\LogService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    use ResponseTrait;

    private ContainerInterface $container;
    private AuthService $authService;
    private LogService $logService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->authService = $container->get(AuthService::class);
        $this->logService = $container->get(LogService::class);
    }

    /**
     * Валидация данных авторизации
     * @param mixed $data Данные для валидации
     * @return string|null Текст с ошибкой или null если ошибок нет
     * 1. Проверяем, что данные являются массивом
     * 2. Проверяем наличие всех полей
     * 3. Проверяем заполненность обязательных полей
     */
    private function validateLoginData(mixed $data): string|null
    {
        if (!is_array($data)) {
            return 'Данные должны быть переданы в формате JSON';
        }

        $requiredFields = ['login', 'password'];
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data)) {
                $missingFields[] = $field;
            }
        }
        if (!empty($missingFields)) {
            return 'Отсутствуют обязательные поля: ' . implode(', ', $missingFields);
        }

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
     * Авторизация через логин и пароль (приложение)
     * @param Request $request
     * @param Response $response
     * @param string $deviceType
     * @return Response
     */
    public function login(Request $request, Response $response, string $deviceType = 'mobile'): Response
    {
        try {

            $data = $request->getParsedBody();
            $errors = $this->validateLoginData($data);

            if (!is_null($errors)) {
                $message = 'Неверный формат запроса. ' . $errors;
                $this->logService->warning('Неверный формат запроса', [
                    'code'   => 400,
                    'errors' => $errors,
                    'data'   => $data],
                    'mobile_auth'
                );

                return $this->respondWithError($response, $message,null,400);
            }

            // Проверка наличия пользователя */
            $result = $this->authService->authenticateUser(
                $data['login'], $data['password'], $deviceType
            );

            if (!$result) {
                $this->logService->error('Неверный логин или пароль', [
                    'code'   => 422,
                    'errors' => null,
                    'data'   => $data],
                    'mobile_auth'
                );

                return $this->respondWithError($response,null,'invalid_credentials',401);
            }

            /** Проверяем статус подписки
             * 1 - Demo
             * 2 - Premium
             * 3 - Close (Доступ закрыт)
             */
            if ($result['user']['tariff'] == 3) {
                return $this->respondWithError(
                    $response,
                    'Доступ запрещен: подписка истекла',
                    'subscription_expired',
                    403
                );
            }

            /** Возвращаем токены */
            return $this->respondWithData($response, [
                'code'          => 200,
                'status'        => 'success',
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_in'    => $result['expires_in'],
            ], 200);

        } catch (Exception $e) {
            $this->logService->warning('Внутренняя ошибка сервера', [
                'code'   => 500,
                'errors' => $e->getMessage(),
                'data'   => $data ?? null],
                'mobile_auth'
            );

            return $this->respondWithError($response,null,null,500);
        }
    }

    /**
     * Обновление токенов доступа
     *
     * @param Request $request
     * @param Response $response
     * @param string $deviceType
     * @return Response
     */
    public function refresh(Request $request, Response $response, string $deviceType = 'mobile'): Response
    {
        try {

            $refreshToken = $this->getTokenFromHeader($request);

            if (!$refreshToken) {
                return $this->respondWithError(
                    $response,
                    'Refresh token not found',
                    'token_not_found',
                    401
                );
            }

            // Обновляем токены
            $result = $this->authService->refreshToken($refreshToken, $deviceType);

            if (!$result) {
                return $this->respondWithError($response,'Refresh token expired','token_expired',401);
            }

            return $this->respondWithData($response, [
                'code'          => 200,
                'status'        => 'success',
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_in'    => $result['expires_in'],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response,null,null,500);
        }
    }

    private function getTokenFromHeader(Request $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (!$header) {
            return null;
        }

        $parts = explode(' ', $header);

        if (count($parts) !== 2 || $parts[0] !== 'Bearer') {
            return null;
        }

        return $parts[1];
    }

    /**
     * Выход из системы
     *
     * @param Request $request
     * @param Response $response
     * @param string $deviceType
     * @return Response
     */
    public function logout(Request $request, Response $response, string $deviceType = 'mobile'): Response
    {
        try {

            $accessToken = $this->getTokenFromHeader($request);

            if (!$accessToken) {
                return $this->respondWithError(
                    $response,
                    'Access token not found',
                    'token_not_found',
                    401
                );
            }

            // Получаем ID пользователя из токена без проверки валидности
            $userId = $this->authService->getUserIdFromToken($accessToken);

            if (!$userId) {
                return $this->respondWithData($response, [
                    'code' => 200,
                    'status' => 'success',
                    'message' => 'Успешный выход из системы.'
                ], 200);
            }

            $this->authService->logoutByDeviceType($userId, $deviceType);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Успешный выход из системы.'
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response,null,null,500);
        }
    }
} 