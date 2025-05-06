<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Capsule\Manager;
use Psr\Container\ContainerInterface;

class SourceService
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Получает все источники
     */
    public function getAllSources(): array
    {
        return Manager::table('sources')
            ->get()
            ->all();
    }

    /**
     * Устанавливает источники для пользователя
     */
    public function setUserSources(User $user, array $sourceIds): void
    {
        // Проверяем, что все источники существуют
        $existingSources = Manager::table('sources')
            ->whereIn('id', $sourceIds)
            ->pluck('id')
            ->all();

        if (empty($existingSources)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $pivotData = array_map(function($sourceId) use ($user, $now) {
            return [
                'user_id' => $user->id,
                'source_id' => $sourceId,
                'created_at' => $now,
                'updated_at' => $now
            ];
        }, $existingSources);

        Manager::table('user_sources')->upsert(
            $pivotData,
            ['user_id', 'source_id'],
            ['updated_at']
        );
    }
} 