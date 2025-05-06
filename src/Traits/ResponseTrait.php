<?php

declare(strict_types=1);

namespace App\Traits;

use Psr\Http\Message\ResponseInterface;

/**
 * Трейт для унифицированной обработки ответов в контроллерах
 */
trait ResponseTrait
{
    /**
     * Возвращает ответ с данными в формате JSON
     *
     * @param ResponseInterface $response Объект ответа
     * @param mixed|null $responseData
     * @param int $statusCode HTTP-код статуса
     * @param array|string|bool $cookies Куки для установки (опционально)
     * @return ResponseInterface
     */
    protected function respondWithData(
        ResponseInterface $response,
        array $responseData,
        int $statusCode,
        mixed $cookies = false): ResponseInterface
    {
        /** Добавляем JSON в тело ответа */
        $response->getBody()->write(json_encode($responseData));

        /** Устанавливаем Content-Type и статус */
        $response = $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);

        /** Устанавливаем куки, если переданы */
        if ($cookies) {
            if (is_array($cookies)) {
                foreach ($cookies as $cookie) {
                    $response = $response->withAddedHeader('Set-Cookie', $cookie);
                }
            } else {
                $response = $response->withHeader('Set-Cookie', $cookies);
            }
        }
        
        return $response;
    }

    /**
     * Возвращает ответ с ошибкой
     *
     * @param ResponseInterface $response Объект ответа
     * @param string|null $errorMessage Текст ошибки
     * @param int $errorCode Код ошибки (опционально)
     * @param string $error
     * @return ResponseInterface
     */
    protected function respondWithError(
        ResponseInterface $response, 
        string|null $errorMessage,
        string $error,
        int $errorCode
    ): ResponseInterface
    {
        return match ($errorCode) {

            /** Ошибки аутентификации */
            401 => $this->respondWithData($response, [
                'code' => $errorCode,
                'status' => 'error',
                'message' => $errorMessage ?: 'Неверный логин или пароль.',
                'error' => $error
            ], 401),
            422 => $this->respondWithData($response, [
                'code' => $errorCode,
                'status' => 'error',
                'message' => $errorMessage ?: 'Ошибка валидации.',
                'error' => $error
            ], 422),
            403 => $this->respondWithData($response, [
                'code' => $errorCode,
                'status' => 'error',
                'message' => $errorMessage ?: 'Доступ запрещен.',
                'error' => $error
            ], 403),
            500 => $this->respondWithData($response, [
                'code' => $errorCode,
                'status' => 'error',
                'message' => $errorMessage ?: 'Внутренняя ошибка сервера.',
                'error' => $error
            ], 500),








            // Ошибки аутентификации
//            401 => $this->respondWithError($response, $errorMessage ?: 'Unauthorized', 401, $errorCode),
//            403 => $this->respondWithError($response, $errorMessage ?: 'Forbidden', 403, $errorCode),
//
//            // Ошибки связанные с JWT-токенами
//            215 => $this->respondWithError($response, 'Token expired', 401, $errorCode),
//            217 => $this->respondWithError($response, 'Refresh token expired', 401, $errorCode),
//            219 => $this->respondWithError($response, 'Refresh token not found', 401, $errorCode),
//
//            // Ошибки валидации и данных
//            422 => $this->respondWithError($response, $errorMessage ?: 'Validation error', 422, $errorCode),
//            23505 => $this->respondWithError($response, 'Resource already exists', 400, $errorCode),
//
//            // Ошибки доступа
//            221 => $this->respondWithError($response, 'Permission denied', 403, $errorCode),
//            223 => $this->respondWithError($response, $errorMessage ?: 'Access forbidden', 403, $errorCode),
//
//            // Пользовательские ошибки с сообщениями
//            253, 222 => $this->respondWithError($response, $errorMessage ?: 'Bad request', 400, $errorCode),
//
//            // Прочие ошибки
//            404, 0 => $this->respondWithError($response, $errorMessage ?: 'Not found', 404, $errorCode ?: 404),
//
//            // По умолчанию
//            default => $this->respondWithError($response, $errorMessage ?: 'An error occurred', 400, $errorCode),
        };

    }

} 