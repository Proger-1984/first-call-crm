<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserSettings;
use App\Models\Source;
use App\Models\Category;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Container\ContainerInterface;
use Illuminate\Database\Capsule\Manager;

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
    #[ArrayShape(['settings' => "array", 'sources' => "mixed[]", 'categories' => "mixed"])]
    public function getUserSettings(int $userId): array
    {
        $user = User::with(['settings', 'sources', 'categories'])->findOrFail($userId);

        // Получаем все источники
        $allSources = Source::all();

        // Получаем ID активных источников пользователя
        $userSourceIds = $user->sources->pluck('id')->toArray();

        // Формируем массив источников с флагом enabled
        $sources = $allSources->map(function($source) use ($userSourceIds) {
            return [
                'id' => $source->id,
                'name' => $source->name,
                'enabled' => in_array($source->id, $userSourceIds)
            ];
        })->toArray();

        // Получаем категории пользователя с их статусом
        $categories = $user->categories()
            ->select('categories.*', 'user_categories.enabled')
            ->get()
            ->map(function($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'enabled' => (bool)$category->enabled
                ];
            })
            ->toArray();

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
                'phone_status' => $user->phone_status,
            ],
            'sources' => $sources,
            'categories' => $categories
        ];
    }
    
    /**
     * Обновляет настройки пользователя
     */
    #[ArrayShape(['settings' => "array", 'sources' => "mixed[]", 'categories' => "mixed"])]
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
            // Получаем ID источников, которые нужно включить
            $enabledSourceIds = collect($data['sources'])
                ->filter(function($source) {
                    return isset($source['enabled']) && $source['enabled'] === true;
                })
                ->pluck('id')
                ->toArray();

            // Проверяем существование источников
            $existingSources = Source::whereIn('id', $enabledSourceIds)->pluck('id')->toArray();

            // Сначала удаляем все связи пользователя с источниками
            $user->sources()->detach();

            // Затем добавляем только включенные источники
            if (!empty($existingSources)) {
                $user->sources()->attach($existingSources);
            }
        }

        // Обновляем категории
        if (isset($data['categories']) && is_array($data['categories'])) {
            // Получаем все существующие категории
            $allCategories = Category::all();
            
            // Получаем текущие связи пользователя с категориями
            $currentUserCategories = $user->categories()
                ->select('categories.id', 'user_categories.enabled')
                ->get()
                ->keyBy('id');

            // Формируем массив для upsert
            $now = date('Y-m-d H:i:s');
            $pivotData = [];
            
            foreach ($data['categories'] as $categoryData) {
                if (!isset($categoryData['id']) || !isset($categoryData['enabled'])) {
                    continue;
                }
                
                // Проверяем существование категории
                if (!$allCategories->contains('id', $categoryData['id'])) {
                    continue;
                }

                // Проверяем, есть ли уже связь с этой категорией
                $categoryId = $categoryData['id'];
                $isEnabled = (bool)$categoryData['enabled'];
                
                // Если связь существует и статус не изменился, пропускаем
                if ($currentUserCategories->has($categoryId) && 
                    $currentUserCategories->get($categoryId)->enabled === $isEnabled) {
                    continue;
                }
                
                $pivotData[] = [
                    'user_id' => $userId,
                    'category_id' => $categoryId,
                    'enabled' => $isEnabled,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
            
            // Обновляем только изменившиеся связи
            if (!empty($pivotData)) {
                Manager::table('user_categories')->upsert(
                    $pivotData,
                    ['user_id', 'category_id'],
                    ['enabled', 'updated_at']
                );
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