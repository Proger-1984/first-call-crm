<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TariffService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CheckExpiredTariffs extends Command
{
    protected $signature = 'tariffs:check-expired';
    protected $description = 'Check and handle expired tariffs';

    private TariffService $tariffService;

    public function __construct(TariffService $tariffService)
    {
        parent::__construct();
        $this->tariffService = $tariffService;
    }

    public function handle()
    {
        $expiredUsers = User::where('tariff_expires_at', '<', Carbon::now())
            ->whereNotNull('tariff_expires_at')
            ->get();

        foreach ($expiredUsers as $user) {
            // Здесь можно добавить логику обработки истекшего тарифа
            // Например, отправить уведомление пользователю
            $this->info("User {$user->id} tariff has expired");
            
            // Можно также сбросить тариф
            $user->update([
                'tariff_id' => null,
                'tariff_expires_at' => null
            ]);
        }

        $this->info("Checked {$expiredUsers->count()} expired tariffs");
    }
} 