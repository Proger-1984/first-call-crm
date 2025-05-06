<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Psr\Container\ContainerInterface;
use Illuminate\Database\Capsule\Manager;
use RuntimeException;
use stdClass;

class TariffService
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Назначает демо-тариф пользователю
     */
    public function assignDemoTariff(User $user): void
    {
        if ($user->is_trial_used) {
            throw new RuntimeException('Демо-период уже был использован');
        }

        /** @var stdClass $demoTariff */
        $demoTariff = Manager::table('tariffs')
            ->where('code', 'demo')
            ->first();

        if (!$demoTariff) {
            throw new RuntimeException('Демо-тариф не найден');
        }

        $user->tariff_id = (int)$demoTariff->id;
        $user->tariff_expires_at = Carbon::now()->addHours((int)$demoTariff->duration_hours);
        $user->is_trial_used = true;
        $user->save();
    }

    /**
     * Назначает премиум-тариф пользователю
     */
    public function assignPremiumTariff(User $user): void
    {
        /** @var stdClass $premiumTariff */
        $premiumTariff = Manager::table('tariffs')
            ->where('code', 'premium')
            ->first();

        if (!$premiumTariff) {
            throw new RuntimeException('Премиум-тариф не найден');
        }

        $user->tariff_id = (int)$premiumTariff->id;
        $user->tariff_expires_at = Carbon::now()->addHours((int)$premiumTariff->duration_hours);
        $user->save();
    }

    /**
     * Проверяет доступность функционала для пользователя
     */
    public function checkAccess(User $user): bool
    {
        // Если у пользователя нет тарифа или он истек
        if (!$user->tariff_id || $user->isTariffExpired()) {
            return false;
        }

        // Если тариф неактивен
        /** @var stdClass $tariff */
        $tariff = Manager::table('tariffs')
            ->where('id', $user->tariff_id)
            ->first();

        if (!$tariff || !(bool)$tariff->is_active) {
            return false;
        }

        return true;
    }

    /**
     * Получает оставшееся время действия тарифа
     */
    public function getRemainingTime(User $user): ?int
    {
        if (!$user->tariff_expires_at) {
            return null;
        }

        $now = Carbon::now();
        if ($now->isAfter($user->tariff_expires_at)) {
            return 0;
        }

        return $now->diffInSeconds($user->tariff_expires_at);
    }

    /**
     * Получает все активные тарифы
     */
    public function getActiveTariffs(): array
    {
        return Manager::table('tariffs')
            ->where('is_active', true)
            ->get()
            ->all();
    }
} 