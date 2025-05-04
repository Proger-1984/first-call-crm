<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSettings;
use App\Models\Source;
use App\Models\Category;

class UserSettingsService
{
    /**
     * Создает настройки по умолчанию для нового пользователя
     */
    public function createDefaultSettings(int $userId): UserSettings
    {
        // Создаем запись в таблице настроек
        $settings = new UserSettings([
            'user_id' => $userId,
            'log_events' => true, 
            'auto_call' => false,
            'telegram_notifications' => true
        ]);
        $settings->save();
        
        // Получаем пользователя
        $user = User::find($userId);
        
        // Прикрепляем все источники
        $sources = Source::all();
        $user->sources()->attach($sources->pluck('id')->toArray());
        
        // Прикрепляем все категории
        $categories = Category::all();
        $user->categories()->attach($categories->pluck('id')->toArray());
        
        return $settings;
    }

    /**
     * Получает настройки пользователя
     */
    public function getUserSettings(int $userId): array
    {
        $user = User::with(['settings', 'sources', 'categories'])->findOrFail($userId);
        
        $sources = $user->sources->pluck('id')->toArray();
        $categories = $user->categories->pluck('id')->toArray();
        
        $settings = $user->settings;
        if (!$settings) {
            // Создаем настройки с значениями по умолчанию
            $settings = $this->createDefaultSettings($userId);
        }
        
        return [
            'log_events' => $settings->log_events,
            'auto_call' => $settings->auto_call,
            'telegram_notifications' => $settings->telegram_notifications,
            'sources' => $sources,
            'categories' => $categories
        ];
    }
    
    /**
     * Обновляет настройки пользователя
     */
    public function updateUserSettings(int $userId, array $data): array
    {
        $user = User::with('settings')->findOrFail($userId);
        
        // Обновляем настройки
        $settings = $user->settings;
        if (!$settings) {
            $settings = $this->createDefaultSettings($userId);
        }
        
        if (isset($data['log_events'])) {
            $settings->log_events = (bool) $data['log_events'];
        }
        
        if (isset($data['auto_call'])) {
            $settings->auto_call = (bool) $data['auto_call'];
        }
        
        if (isset($data['telegram_notifications'])) {
            $settings->telegram_notifications = (bool) $data['telegram_notifications'];
        }
        
        $settings->save();
        
        // Обновляем источники
        if (isset($data['sources']) && is_array($data['sources'])) {
            // Проверяем, что все источники существуют
            $existingSources = Source::whereIn('id', $data['sources'])->pluck('id')->toArray();
            $user->sources()->sync($existingSources);
        }
        
        // Обновляем категории
        if (isset($data['categories']) && is_array($data['categories'])) {
            // Проверяем, что все категории существуют
            $existingCategories = Category::whereIn('id', $data['categories'])->pluck('id')->toArray();
            $user->categories()->sync($existingCategories);
        }
        
        // Возвращаем обновленные настройки
        return $this->getUserSettings($userId);
    }
    
    /**
     * Получает список всех доступных источников
     */
    public function getAllSources(): array
    {
        return Source::all()->toArray();
    }
    
    /**
     * Получает список всех доступных категорий
     */
    public function getAllCategories(): array
    {
        return Category::all()->toArray();
    }
} 