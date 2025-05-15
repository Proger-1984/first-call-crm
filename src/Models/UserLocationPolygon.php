<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Class UserLocationPolygon
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $user_id
 * @property int $subscription_id
 * @property string $name
 * @property array $polygon_coordinates
 * @property float|null $center_lat
 * @property float|null $center_lng
 * @property array|null $bounds
 * @property string|null $polygon
 * @property string|null $center_point
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read User $user
 * @property-read UserSubscription $subscription
 * @method static Builder|self where($column, $operator = null, $value = null)
 */
class UserLocationPolygon extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'name',
        'polygon_coordinates',
        'center_lat',
        'center_lng',
        'bounds'
    ];

    protected $casts = [
        'polygon_coordinates' => 'array',
        'bounds' => 'array',
        'center_lat' => 'float',
        'center_lng' => 'float'
    ];

    /**
     * Get the user that owns the location polygon
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subscription that owns the location polygon
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class);
    }
    
    /**
     * Вычисляет центр полигона и его границы
     * 
     * @return void
     */
    public function calculateGeometryData(): void
    {
        if (empty($this->polygon_coordinates) || count($this->polygon_coordinates) < 3) {
            return;
        }
        
        // Вычисляем границы полигона
        $lats = array_column($this->polygon_coordinates, 0);
        $lngs = array_column($this->polygon_coordinates, 1);
        
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);
        
        $this->bounds = [
            'north' => $maxLat,
            'south' => $minLat,
            'east' => $maxLng,
            'west' => $minLng
        ];
        
        // Вычисляем центр полигона
        $this->center_lat = ($minLat + $maxLat) / 2;
        $this->center_lng = ($minLng + $maxLng) / 2;

    }
    
    /**
     * Обновляет PostGIS геометрические поля на основе JSON координат
     */
    public function updatePostGisFields(): bool
    {
        // Эта функция вызывается после сохранения модели
        if (!$this->id || empty($this->polygon_coordinates)) {
            return true;
        }
        
        // Формируем WKT для полигона из координат
        $points = [];
        foreach ($this->polygon_coordinates as $coord) {
            $points[] = "$coord[1] $coord[0]"; // PostGIS порядок: lng lat
        }
        
        // Замыкаем полигон, добавляя первую точку в конец
        if ($points[0] !== end($points)) {
            $points[] = $points[0];
        }
        
        $wktPolygon = "POLYGON((" . implode(',', $points) . "))";
        
        try {
            // Используем PDO напрямую для обновления PostGIS полей
            $connection = DB::connection()->getPdo();
            
            $query = "UPDATE " .
                "user_location_polygons SET 
                center_point = ST_SetSRID(ST_MakePoint(?, ?), 4326),
                polygon = ST_SetSRID(ST_GeomFromText(?), 4326)
                WHERE id = ?";
                
            $statement = $connection->prepare($query);
            
            // Убедимся, что значения правильно сформированы
            $center_lng = floatval($this->center_lng); 
            $center_lat = floatval($this->center_lat);
            
            // Убедимся, что WKT строка правильно сформирована (без лишних пробелов и переводов строк)
            $wktPolygon = preg_replace('/\s+/', ' ', trim($wktPolygon));

            $result = $statement->execute([
                $center_lng, 
                $center_lat, 
                $wktPolygon, 
                $this->id
            ]);


            if (!$result) {
                return false;
            }

            return true;

        } catch (Exception) {
            return false;
        }
    }


} 