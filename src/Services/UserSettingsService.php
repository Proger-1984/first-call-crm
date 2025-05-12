<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSettings;
use App\Models\Source;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Container\ContainerInterface;

class UserSettingsService
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Создает настройки по умолчанию для нового пользователя
     */
    public function createDefaultSettings(int $userId): UserSettings
    {
        // Создаем запись в таблице настроек
        $settings = new UserSettings([
            'user_id' => $userId,
            'log_events' => false,
            'auto_call' => false,
            'telegram_notifications' => false
        ]);
        $settings->save();
        
        return $settings;
    }

    /**
     * Получает настройки пользователя
     */
    #[ArrayShape(['settings' => "array", 'sources' => "mixed[]", 'active_subscriptions' => "mixed"])]
    public function getUserSettings(int $userId): array
    {
        $user = User::with(['settings', 'sources', 'activeSubscriptions.category', 'activeSubscriptions.location'])->findOrFail($userId);

        // Получаем все источники
        $allSources = Source::all();

        // Формируем массив источников с флагом enabled
        $sources = $allSources->map(function($source) use ($user) {
            return [
                'id' => $source->id,
                'name' => $source->name,
                'enabled' => $user->sources->contains(function($userSource) use ($source) {
                    return $userSource->id === $source->id && $userSource->pivot->enabled;
                })
            ];
        })->toArray();

        // Получаем активные подписки пользователя
        $activeSubscriptions = $user->activeSubscriptions->map(function($subscription) {
            return [
                'id' => $subscription->id,
                'name' => $subscription->category->name . ' | ' . $subscription->location->getFullName(),
                'enabled' => $subscription->is_enabled
            ];
        })->toArray();

        /** @var UserSettings $settings */
        $settings = $user->settings;
        if (!$settings) {
            // Создаем настройки с значениями по умолчанию
            $settings = $this->createDefaultSettings($userId);
        }
        
        return [
            'settings' => [
                'log_events' => $settings->log_events,
                'auto_call' => $settings->auto_call,
                'telegram_notifications' => $settings->telegram_notifications,
            ],
            'sources' => $sources,
            'active_subscriptions' => $activeSubscriptions
        ];
    }
    
    /**
     * Обновляет настройки пользователя
     */
    #[ArrayShape(['settings' => "array", 'sources' => "mixed[]", 'active_subscriptions' => "mixed"])]
    public function updateUserSettings(int $userId, array $data): array
    {
        $user = User::with('settings')->findOrFail($userId);
        
        // Обновляем настройки
        $settings = $user->settings;
        if (!$settings) {
            $settings = $this->createDefaultSettings($userId);
        }
        
        if (isset($data['settings']['log_events'])) {
            $settings->log_events = (bool) $data['settings']['log_events'];
        }
        
        if (isset($data['settings']['auto_call'])) {
            $settings->auto_call = (bool) $data['settings']['auto_call'];
        }
        
        if (isset($data['settings']['telegram_notifications'])) {
            $settings->telegram_notifications = (bool) $data['settings']['telegram_notifications'];
        }
        
        $settings->save();

        // Обновляем источники
        if (isset($data['sources']) && is_array($data['sources'])) {
            foreach ($data['sources'] as $source) {
                if (!isset($source['id']) || !isset($source['enabled'])) {
                    continue;
                }
                
                if ($source['enabled']) {
                    $user->enableSource((int)$source['id']);
                } else {
                    $user->disableSource((int)$source['id']);
                }
            }
        }

        // Обновляем статусы активных подписок
        if (isset($data['active_subscriptions']) && is_array($data['active_subscriptions'])) {
            foreach ($data['active_subscriptions'] as $subscription) {
                if (!isset($subscription['id']) || !isset($subscription['enabled'])) {
                    continue;
                }
                
                $user->activeSubscriptions()
                    ->where('id', $subscription['id'])
                    ->update(['is_enabled' => (bool)$subscription['enabled']]);
            }
        }

        /** Возвращаем обновленные настройки */
        return $this->getUserSettings($userId);
    }

    /**
     * Обновляет статус телефона пользователя
     */
    public function updatePhoneStatus(int $userId, bool $status): void
    {
        $user = User::findOrFail($userId);
        $user->phone_status = $status;
        $user->save();
    }
} 