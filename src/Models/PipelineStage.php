<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

/**
 * Модель стадии воронки продаж
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $color
 * @property int $sort_order
 * @property bool $is_system
 * @property bool $is_final
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read User $user
 * @property-read Collection<Client> $clients
 */
class PipelineStage extends Model
{
    protected $table = 'pipeline_stages';

    protected $fillable = [
        'user_id',
        'name',
        'color',
        'sort_order',
        'is_system',
        'is_final',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'sort_order' => 'integer',
        'is_system' => 'boolean',
        'is_final' => 'boolean',
    ];

    /** Стадии по умолчанию для нового пользователя */
    private const DEFAULT_STAGES = [
        ['name' => 'Новый лид',        'color' => '#2196F3', 'is_system' => true,  'is_final' => false],
        ['name' => 'Первый контакт',   'color' => '#FF9800', 'is_system' => false, 'is_final' => false],
        ['name' => 'Квалификация',     'color' => '#9C27B0', 'is_system' => false, 'is_final' => false],
        ['name' => 'Подбор объектов',  'color' => '#00BCD4', 'is_system' => false, 'is_final' => false],
        ['name' => 'Показ',           'color' => '#FFC107', 'is_system' => false, 'is_final' => false],
        ['name' => 'Переговоры',      'color' => '#FF5722', 'is_system' => false, 'is_final' => false],
        ['name' => 'Задаток',         'color' => '#E91E63', 'is_system' => false, 'is_final' => false],
        ['name' => 'Сделка закрыта',  'color' => '#4CAF50', 'is_system' => true,  'is_final' => true],
        ['name' => 'Отказ',           'color' => '#F44336', 'is_system' => true,  'is_final' => true],
    ];

    /**
     * Пользователь-владелец стадии
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Клиенты на этой стадии
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'pipeline_stage_id');
    }

    /**
     * Получить все стадии пользователя, отсортированные
     *
     * @param int $userId
     * @return Collection<PipelineStage>
     */
    public static function getByUser(int $userId): Collection
    {
        return self::where('user_id', $userId)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Создать стадии по умолчанию для пользователя.
     * Вызывается при первом обращении к воронке.
     *
     * @param int $userId
     * @return Collection<PipelineStage>
     */
    public static function createDefaultStages(int $userId): Collection
    {
        foreach (self::DEFAULT_STAGES as $sortOrder => $stageData) {
            self::create([
                'user_id' => $userId,
                'name' => $stageData['name'],
                'color' => $stageData['color'],
                'sort_order' => $sortOrder + 1,
                'is_system' => $stageData['is_system'],
                'is_final' => $stageData['is_final'],
            ]);
        }

        return self::getByUser($userId);
    }

    /**
     * Получить или создать стадии пользователя (lazy initialization)
     *
     * @param int $userId
     * @return Collection<PipelineStage>
     */
    public static function getOrCreateForUser(int $userId): Collection
    {
        $stages = self::getByUser($userId);

        if ($stages->isEmpty()) {
            $stages = self::createDefaultStages($userId);
        }

        return $stages;
    }

    /**
     * Получить первую (начальную) стадию пользователя
     */
    public static function getFirstStage(int $userId): ?self
    {
        $stages = self::getOrCreateForUser($userId);
        return $stages->first();
    }

    /**
     * Проверить, принадлежит ли стадия пользователю
     */
    public static function belongsToUser(int $stageId, int $userId): bool
    {
        return self::where('id', $stageId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Получить количество клиентов на этой стадии
     */
    public function getClientsCount(): int
    {
        return $this->clients()->where('is_archived', false)->count();
    }
}
