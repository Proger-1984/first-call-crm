<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class LocationProxy
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $proxy
 * @property int $source_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read Source $source
 * 
 * @method static LocationProxy|null find(int $id)
 * @method static LocationProxy findOrFail(int $id)
 * @method static Builder|LocationProxy where(string $column, mixed $operator = null, mixed $value = null)
 * @method static Builder|LocationProxy whereIn(string $column, array $values)
 * @method static Collection|LocationProxy[] all()
 */
class LocationProxy extends Model
{
    use HasFactory;

    protected $table = 'location_proxies';

    protected $fillable = [
        'proxy',
        'source_id'
    ];

    /**
     * Получить источник для данного прокси
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Получить все прокси для конкретного источника/локации/категории
     */
    public static function getProxiesForSource(int $sourceId, int $locationId, int $categoryId): Collection
    {
        return static::where('source_id', $sourceId)
            ->where('location_id', $locationId)
            ->where('category_id', $categoryId)
            ->get();
    }

    /**
     * Получить прокси в простом формате ip:port
     */
    public function getSimpleFormat(): string
    {
        return $this->proxy;
    }
} 