<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Services\AuthService;
use App\Services\LogService;
use App\Services\JwtService;
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
    private JwtService $jwtService;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->authService = $container->get(AuthService::class);
        $this->logService = $container->get(LogService::class);
        $this->jwtService = $container->get(JwtService::class);
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

            // Проверка наличия пользователя
            $result = $this->authService->authenticateUser(
                (int)$data['login'], $data['password'], $deviceType
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

            // Получаем пользователя для проверки активных подписок
            $user = User::find($result['user']['id']);
            
            // Проверяем, что у пользователя есть хотя бы одна активная подписка или демо-версия
            if (!$user->hasAnyActiveSubscription() && !$user->hasActiveDemoSubscription()) {
                return $this->respondWithError(
                    $response,
                    'Доступ запрещен: Нет активных подписок',
                    'subscription_expired',
                    403
                );
            }

            // Возвращаем токены
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
     * Обновление токена доступа
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     * 
     * @throws Exception
     * 
     * @api {get} /api/v1/auth/refresh Обновление токена доступа
     * @apiName RefreshToken
     * @apiGroup Auth
     * 
     * @apiError token_not_found Токен не найден
     * @apiError token_expired Токен истек
     * @apiError invalid_token Недействительный токен
     * @apiError invalid_token_type Неверный тип токена
     */
    public function refresh(Request $request, Response $response): Response
    {
        try {
            // Пытаемся получить токен из заголовка
            $refreshToken = $this->getTokenFromHeader($request);
            
            // Если токен не найден в заголовке, пробуем получить из куки
            if (!$refreshToken) {
                $refreshToken = $request->getCookieParams()['refreshToken'] ?? null;
            }

            if (!$refreshToken) {
                return $this->respondWithError(
                    $response,
                    'Refresh token not found',
                    'token_not_found',
                    401
                );
            }

            // Декодируем токен для получения типа устройства
            $tokenData = $this->jwtService->decodeRefreshToken($refreshToken);
            if (!$tokenData) {
                return $this->respondWithError(
                    $response,
                    'Invalid refresh token',
                    'invalid_token',
                    401
                );
            }

            // Обновляем токены
            $result = $this->authService->refreshToken($refreshToken);
            if (!$result) {
                return $this->respondWithError(
                    $response,
                    'Refresh token expired',
                    'token_expired',
                    401
                );
            }

            // Если это веб-клиент (включая impersonate), устанавливаем куки
            if ($tokenData['device_type'] === 'web' || $tokenData['device_type'] === 'web_impersonate') {
                $sameSite = '; SameSite=None';
                $expiresGMT = gmdate('D, d M Y H:i:s T', $result['expires_in']);

                $cookie[] = sprintf(
                    'refreshToken=%s; path=/api/v1/auth; domain=.%s; max-age=%d; expires=%s; HttpOnly; Secure%s',
                    urlencode($result['refresh_token']),
                    $_ENV['HOST'],
                    (int)$result['expires_in'],
                    $expiresGMT,
                    $sameSite
                );

                return $this->respondWithData($response, [
                    'code'         => 200,
                    'status'       => 'success',
                    'access_token' => $result['access_token'],
                    'expires_in'   => $result['expires_in'],
                ], 200, $cookie);
            }

            // Для мобильного приложения возвращаем токены в теле ответа
            return $this->respondWithData($response, [
                'code'          => 200,
                'status'        => 'success',
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_in'    => $result['expires_in'],
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response, null, null, 500);
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
     * @return Response
     */
    public function logout(Request $request, Response $response): Response
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

            $this->authService->logoutByAccessToken($accessToken);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Успешный выход из системы.'
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response,null,null,500);
        }
    }

    /**
     * Выход из всех устройств
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function logoutAll(Request $request, Response $response): Response
    {
        try {
            // Получаем ID пользователя из атрибутов запроса (установлен AuthMiddleware)
            $userId = $request->getAttribute('userId');

            if (!$userId) {
                return $this->respondWithError(
                    $response,
                    'User not found',
                    'user_not_found',
                    404
                );
            }

            $this->authService->logoutFromAllDevices($userId);

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'message' => 'Успешный выход из всех устройств.'
            ], 200);

        } catch (Exception) {
            return $this->respondWithError($response,null,null,500);
        }
    }
} 