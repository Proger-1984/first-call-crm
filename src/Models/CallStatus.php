<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель статуса звонка
 * 
 * Статусы звонков операторов по объявлениям:
 * 1 - Наша квартира (успешный звонок)
 * 2 - Не дозвонился
 * 3 - Не снял
 * 4 - Агент
 * 5 - Не первые
 * 6 - Звонок (в процессе)
 * 
 * @property int $id
 * @property string $name
 * @property string|null $color
 * @property int $sort_order
 */
class CallStatus extends Model
{
    protected $table = 'call_statuses';

    protected $fillable = ['name', 'color', 'sort_order'];

    public $timestamps = false;

    /**
     * Записи агентов с этим статусом
     */
    public function agentListings(): HasMany
    {
        return $this->hasMany(AgentListing::class);
    }
}
