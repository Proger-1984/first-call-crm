<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tariff;
use App\Models\TariffPrice;
use Psr\Container\ContainerInterface;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionService
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }
    
    /**
     * Получает все активные тарифы
     * 
     * @return Collection Список тарифов
     */
    public function getActiveTariffs(): Collection
    {
        return Tariff::where('is_active', true)->get();
    }
    
    /**
     * Получает цену тарифа для указанной локации
     * 
     * @param int $tariffId ID тарифа
     * @param int $locationId ID локации
     * @return float|null Цена или null, если не найдена
     */
    public function getTariffPrice(int $tariffId, int $locationId): ?float
    {
        $priceRecord = TariffPrice::where('tariff_id', $tariffId)
            ->where('location_id', $locationId)
            ->first();
        
        if ($priceRecord) {
            return (float)$priceRecord->price;
        }
        
        // Если цена для локации не найдена, берем стандартную цену из тарифа
        $tariff = Tariff::find($tariffId);
        return $tariff ? (float)$tariff->price : null;
    }
} 