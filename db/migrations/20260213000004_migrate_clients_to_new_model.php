<?php

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Миграция данных: перенос из старой модели (clients) в новую (properties + contacts + object_clients)
 *
 * Данных мало (~2 пользователя, ~3 клиента). Старые таблицы НЕ удаляются.
 */
class MigrateClientsToNewModel
{
    public function getTableName(): string
    {
        return 'object_clients';
    }

    public function modifiesExistingTable(): bool
    {
        return true;
    }

    public function up()
    {
        // Проверяем, что старая таблица существует и новые таблицы пусты
        if (!Manager::schema()->hasTable('clients')) {
            return;
        }

        $existingContacts = DB::table('contacts')->count();
        if ($existingContacts > 0) {
            // Уже мигрировано, пропускаем
            return;
        }

        $clients = DB::table('clients')->get();
        if ($clients->isEmpty()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($clients as $client) {
            // 1. Создаём контакт
            $contactId = DB::table('contacts')->insertGetId([
                'user_id' => $client->user_id,
                'name' => $client->name,
                'phone' => $client->phone,
                'phone_secondary' => $client->phone_secondary,
                'email' => $client->email,
                'telegram_username' => $client->telegram_username,
                'comment' => $client->comment,
                'is_archived' => $client->is_archived,
                'created_at' => $client->created_at ?? $now,
                'updated_at' => $now,
            ]);

            // 2. Если есть привязанные объявления — создаём объекты из них
            if (Manager::schema()->hasTable('client_listings')) {
                $clientListings = DB::table('client_listings')
                    ->where('client_id', $client->id)
                    ->get();

                foreach ($clientListings as $clientListing) {
                    $listing = DB::table('listings')
                        ->where('id', $clientListing->listing_id)
                        ->first();

                    if (!$listing) {
                        continue;
                    }

                    // Создаём объект из объявления
                    $propertyId = DB::table('properties')->insertGetId([
                        'user_id' => $client->user_id,
                        'listing_id' => $listing->id,
                        'title' => $listing->title,
                        'address' => $listing->address ?? null,
                        'price' => $listing->price ?? null,
                        'rooms' => $listing->rooms ?? null,
                        'area' => $listing->area ?? null,
                        'floor' => $listing->floor ?? null,
                        'floors_total' => $listing->floors_total ?? null,
                        'url' => $listing->url ?? null,
                        'deal_type' => in_array($client->client_type ?? '', ['buyer', 'renter'])
                            ? ($client->client_type === 'renter' ? 'rent' : 'sale')
                            : 'sale',
                        'source_type' => $client->source_type,
                        'source_details' => $client->source_details,
                        'is_archived' => $client->is_archived,
                        'created_at' => $client->created_at ?? $now,
                        'updated_at' => $now,
                    ]);

                    // 3. Создаём связку объект+контакт
                    DB::table('object_clients')->insert([
                        'property_id' => $propertyId,
                        'contact_id' => $contactId,
                        'pipeline_stage_id' => $client->pipeline_stage_id,
                        'comment' => $clientListing->comment,
                        'next_contact_at' => $client->next_contact_at,
                        'last_contact_at' => $client->last_contact_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down()
    {
        // Откат: очищаем новые таблицы (данные восстановятся из старых)
        DB::table('object_clients')->truncate();
        DB::table('properties')->truncate();
        DB::table('contacts')->truncate();
    }
}
