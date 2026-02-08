<?php

declare(strict_types=1);

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Сервис генерации QR-кодов для оплаты
 * 
 * ВАЖНО: QR-код по ГОСТ Р 56042-2014 временно не используется,
 * т.к. Сбербанк не поддерживает оплату по QR на счета физлиц (40817...)
 * Для работы QR нужен расчётный счёт (40802...), который самозанятым недоступен.
 * 
 * Функционал сохранён для возможного использования в будущем.
 */
class QrCodeService
{
    // Реквизиты получателя платежа (для QR по ГОСТ — пока не используется)
    private const PAYEE_NAME = 'СОКОЛОВ СЕРГЕЙ ВИКТОРОВИЧ';
    private const PAYEE_PERSONAL_ACC = '40817810263003339363';
    private const PAYEE_BANK_NAME = 'ТВЕРСКОЕ ОТДЕЛЕНИЕ N8607 ПАО СБЕРБАНК';
    private const PAYEE_BIC = '042809679';
    private const PAYEE_CORRESP_ACC = '30101810700000000679';
    // ИНН самозанятого (нужен для QR, пока не заполнен)
    private const PAYEE_INN = '';
    
    // Данные карты для перевода (используется)
    public const CARD_NUMBER = '2202 2088 2298 0794';
    public const CARD_HOLDER = 'Соколов Сергей В.';

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Генерация QR-кода для оплаты подписки по ГОСТ Р 56042-2014
     * 
     * ВНИМАНИЕ: Не работает со счетами физлиц (40817...) в Сбербанке!
     * Оставлен для будущего использования с расчётным счётом.
     *
     * @param float $sum Сумма платежа в рублях
     * @param int $subscriptionId ID заявки на подписку
     * @param int $userId ID пользователя
     * @return string|null Base64-encoded PNG изображение или null при ошибке
     */
    public function generatePaymentQrCode(float $sum, int $subscriptionId, int $userId): ?string
    {
        try {
            $purpose = $this->buildPaymentPurpose($subscriptionId, $userId);
            $qrData = $this->buildQrData($sum, $purpose);

            $this->logger->info('Генерация QR-кода для оплаты', [
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'sum' => $sum,
            ]);

            // endroid/qr-code 6.x использует конструктор с именованными аргументами
            $builder = new Builder(
                writer: new PngWriter(),
                data: $qrData,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 300,
                margin: 10
            );

            $result = $builder->build();

            // Возвращаем base64-encoded изображение
            return base64_encode($result->getString());

        } catch (Throwable $e) {
            $this->logger->error('Ошибка генерации QR-кода', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);
            return null;
        }
    }

    /**
     * Сохранение QR-кода в файл
     *
     * @param float $sum Сумма платежа в рублях
     * @param int $subscriptionId ID заявки на подписку
     * @param int $userId ID пользователя
     * @param string $outputPath Путь для сохранения файла
     * @return bool Успешность сохранения
     */
    public function savePaymentQrCode(float $sum, int $subscriptionId, int $userId, string $outputPath): bool
    {
        try {
            $purpose = $this->buildPaymentPurpose($subscriptionId, $userId);
            $qrData = $this->buildQrData($sum, $purpose);

            $builder = new Builder(
                writer: new PngWriter(),
                data: $qrData,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 300,
                margin: 10
            );

            $result = $builder->build();
            $result->saveToFile($outputPath);

            $this->logger->info('QR-код сохранён', ['path' => $outputPath]);
            return true;

        } catch (Throwable $e) {
            $this->logger->error('Ошибка сохранения QR-кода', [
                'error' => $e->getMessage(),
                'path' => $outputPath,
            ]);
            return false;
        }
    }

    /**
     * Формирование назначения платежа
     */
    public function buildPaymentPurpose(int $subscriptionId, int $userId): string
    {
        return "Оплата подписки FirstCall, заявка №{$subscriptionId}, клиент #{$userId}";
    }

    /**
     * Формирование данных для QR-кода по ГОСТ Р 56042-2014
     * ST00012 = UTF-8 кодировка
     */
    private function buildQrData(float $sum, string $purpose): string
    {
        $fields = [
            'ST00012',
            'Name=' . self::PAYEE_NAME,
            'PersonalAcc=' . self::PAYEE_PERSONAL_ACC,
            'BankName=' . self::PAYEE_BANK_NAME,
            'BIC=' . self::PAYEE_BIC,
            'CorrespAcc=' . self::PAYEE_CORRESP_ACC,
            'Sum=' . (int)($sum * 100), // Сумма в копейках
            'Purpose=' . $purpose,
        ];
        
        // ИНН добавляем только если заполнен
        if (!empty(self::PAYEE_INN)) {
            $fields[] = 'PayeeINN=' . self::PAYEE_INN;
        }
        
        return implode('|', $fields);
    }

    /**
     * Получение номера карты для отображения
     */
    public function getCardNumber(): string
    {
        return self::CARD_NUMBER;
    }

    /**
     * Получение имени держателя карты
     */
    public function getCardHolder(): string
    {
        return self::CARD_HOLDER;
    }
}
