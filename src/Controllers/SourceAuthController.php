<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserSourceCookie;
use App\Services\SourceAuthService;
use App\Traits\ResponseTrait;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Контроллер для управления авторизацией на источниках (CIAN, Avito)
 */
class SourceAuthController
{
    use ResponseTrait;

    private SourceAuthService $sourceAuthService;
    private ?LoggerInterface $logger;

    public function __construct(SourceAuthService $sourceAuthService, ?LoggerInterface $logger = null)
    {
        $this->sourceAuthService = $sourceAuthService;
        $this->logger = $logger;
    }

    /**
     * Получить статус авторизации на источниках
     * GET /api/v1/source-auth/status
     */
    public function getStatus(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $sourceType = $request->getQueryParams()['source'] ?? null;

            if ($sourceType) {
                // Статус для конкретного источника
                $status = $this->sourceAuthService->getAuthStatus($userId, $sourceType);
                return $this->respondWithData($response, [
                    'code' => 200,
                    'status' => 'success',
                    'data' => [
                        'source' => $sourceType,
                        'status' => $status,
                    ],
                ], 200);
            }

            // Статус для всех источников
            $cianStatus = $this->sourceAuthService->getAuthStatus($userId, 'cian');
            $avitoStatus = $this->sourceAuthService->getAuthStatus($userId, 'avito');

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'cian' => $cianStatus,
                    'avito' => $avitoStatus,
                ],
            ], 200);

        } catch (Exception $e) {
            $this->logger?->error('Ошибка получения статуса авторизации', [
                'error' => $e->getMessage(),
            ]);
            return $this->respondWithError($response, 'Ошибка сервера', 'server_error', 500);
        }
    }

    /**
     * Сохранить куки (ручной ввод)
     * POST /api/v1/source-auth/cookies
     */
    public function saveCookies(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();

            // Валидация
            if (empty($data['cookies'])) {
                return $this->respondWithError($response, 'Куки не указаны', 'validation_error', 400);
            }

            $sourceType = $data['source'] ?? 'cian';
            
            if (!in_array($sourceType, ['cian', 'avito'])) {
                return $this->respondWithError($response, 'Неверный источник', 'validation_error', 400);
            }

            $result = $this->sourceAuthService->saveCookies($userId, $sourceType, $data['cookies']);

            if (!$result['success']) {
                return $this->respondWithError(
                    $response,
                    $result['message'],
                    $result['error'] ?? 'save_error',
                    400
                );
            }

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (Exception $e) {
            $this->logger?->error('Ошибка сохранения кук', [
                'error' => $e->getMessage(),
            ]);
            return $this->respondWithError($response, 'Ошибка сервера', 'server_error', 500);
        }
    }

    /**
     * Удалить куки (деавторизация)
     * DELETE /api/v1/source-auth/cookies
     */
    public function deleteCookies(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $sourceType = $request->getQueryParams()['source'] ?? 'cian';

            $userCookie = UserSourceCookie::getForUser($userId, $sourceType);

            $userCookie?->delete();

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'success' => true,
                    'message' => 'Авторизация удалена',
                ],
            ], 200);

        } catch (Exception $e) {
            $this->logger?->error('Ошибка удаления кук', [
                'error' => $e->getMessage(),
            ]);
            return $this->respondWithError($response, 'Ошибка сервера', 'server_error', 500);
        }
    }

    /**
     * Перепроверить авторизацию (валидация текущих кук)
     * POST /api/v1/source-auth/revalidate
     */
    public function revalidate(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('userId');
            $data = $request->getParsedBody();

            $sourceType = $data['source'] ?? 'cian';
            
            if (!in_array($sourceType, ['cian', 'avito'])) {
                return $this->respondWithError($response, 'Неверный источник', 'validation_error', 400);
            }

            $result = $this->sourceAuthService->revalidateCookies($userId, $sourceType);

            if (!$result['success']) {
                return $this->respondWithError(
                    $response,
                    $result['message'],
                    $result['error'] ?? 'revalidate_error',
                    400
                );
            }

            return $this->respondWithData($response, [
                'code' => 200,
                'status' => 'success',
                'data' => $result,
            ], 200);

        } catch (Exception $e) {
            $this->logger?->error('Ошибка перепроверки авторизации', [
                'error' => $e->getMessage(),
            ]);
            return $this->respondWithError($response, 'Ошибка сервера', 'server_error', 500);
        }
    }
}
