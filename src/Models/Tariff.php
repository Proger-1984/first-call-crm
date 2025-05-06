<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class Tariff
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $duration_hours
 * @property float|null $price
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @method static Tariff|null find(int $id)
 * @method static Tariff findOrFail(int $id)
 * @method static Tariff where(string $column, mixed $operator = null, mixed $value = null)
 */
class Tariff extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'duration_hours',
        'price',
        'description',
        'is_active'
    ];

    protected $casts = [
        'duration_hours' => 'integer',
        'price' => 'float',
        'is_active' => 'boolean'
    ];

    /**
     * Пользователи с этим тарифом
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Проверяет, является ли тариф демо
     */
    public function isDemo(): bool
    {
        return $this->code === 'demo';
    }

    /**
     * Проверяет, является ли тариф премиум
     */
    public function isPremium(): bool
    {
        return $this->code === 'premium';
    }

    /**
     * Получает дату окончания тарифа
     */
    public function getExpirationDate(): Carbon
    {
        return Carbon::now()->addHours($this->duration_hours);
    }
} 