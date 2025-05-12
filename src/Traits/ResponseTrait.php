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
        // Добавляем JSON в тело ответа
        $response->getBody()->write(json_encode($responseData));

        // Устанавливаем Content-Type и статус
        $response = $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);

        // Устанавливаем куки, если переданы
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
     * @param string|null $error
     * @param int $errorCode Код ошибки (опционально)
     * @param array|null $additionalData Дополнительные данные (опционально)
     * @return ResponseInterface
     */
    protected function respondWithError(
        ResponseInterface $response, 
        string|null $errorMessage,
        string|null $error,
        int $errorCode,
        array $additionalData = null
    ): ResponseInterface
    {
        $errorData = [
            'code' => $errorCode,
            'status' => 'error',
            'message' => $errorMessage ?: $this->getDefaultErrorMessage($errorCode),
            'error' => $error ?: $this->getDefaultErrorCode($errorCode),
        ];
        
        // Добавляем дополнительные данные, если они есть
        if ($additionalData !== null) {
            $errorData = array_merge($errorData, $additionalData);
        }
        
        return $this->respondWithData($response, $errorData, $errorCode);
    }

    /**
     * Возвращает стандартное сообщение об ошибке для кода ошибки
     */
    private function getDefaultErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            400 => 'Неверный формат запроса',
            401 => 'Неверный логин или пароль',
            403 => 'Доступ запрещен',
            404 => 'Запись не найдена',
            422 => 'Ошибка валидации',
            500 => 'Внутренняя ошибка сервера',
            default => 'Произошла ошибка',
        };
    }

    /**
     * Возвращает стандартный код ошибки для кода HTTP
     */
    private function getDefaultErrorCode(int $errorCode): ?string
    {
        return match ($errorCode) {
            400, 422 => 'validation_error',
            500 => 'internal_error',
            default => null,
        };
    }

} 