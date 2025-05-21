<?php

namespace App\Commands;

use App\Services\LogService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Capsule\Manager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Container\ContainerInterface;

class MetroStationsParserCommand extends Command
{
    private object $locations;
    private Client $client;
    private LogService $logger;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->logger = $container->get(LogService::class);

        $client = new Client();
        $this->client = $client;

        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->setName('parse-metro-stations')
            ->setDescription('Парсинг станций метро');
    }

    /**
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info("Запуск парсинга станций метро",
            ['date' => date('Y-m-d H:i:s')], 'parse-metro-stations');
        
        try {
            $this->loadLocations();

            foreach ($this->locations AS $location) {
                $id = $this->getLocationIdByCity($location->city);
                if (is_null($id)) {
                    continue;
                }

                $response = $this->client->request('GET', 'https://api.hh.ru/metro/' . $id, [
                    'timeout' => 5,
                    'connect_timeout' => 5,
                    'allow_redirects' => true,
                    'verify' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json'
                    ],
                    'http_errors' => false,
                ]);

                $data = $response->getBody();
                $data = json_decode($data->getContents(), true);

                $code = $response->getStatusCode();
                if ($code != 200) {
                    $this->logger->info("Ошибка парсинга станций метро для города: $location->city",
                        ['locationId' => $location->id, 'error' => $data], 'parse-metro-stations');
                    continue;
                }

                foreach ($data['lines'] AS $line) {
                    $hex_color = $line['hex_color'];
                    $name = $line['name'];
                    foreach ($line['stations'] AS $station) {
                        $values = [
                            'location_id' => $location->id,
                            'name' => $station['name'],
                            'line' => $name,
                            'color' => $hex_color,
                            'lat' => $station['lat'],
                            'lng' => $station['lng'],
                        ];
                        $this->saveMetroStation($values);
                    }
                }
            }

            $this->logger->info("Парсинг станций метро успешно завершен", [], 'parse-metro-stations');
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->logger->error("Ошибка при выполнении скрипта", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'parse-metro-stations');
            return Command::FAILURE;
        }
    }
    
    // Загрузка локаций из базы данных
    private function loadLocations()
    {
        $this->locations = Manager::table('locations')->get();
        $this->logger->info("Загружено локаций: " . count($this->locations), [], 'parse-metro-stations');
    }

    private function getLocationIdByCity(string $cityName): ?int
    {
        $data = [
            'Москва' => 1,
            'Санкт-Петербург' => 2,
            'Екатеринбург' => 3,
            'Новосибирск' => 4,
            'Нижний Новгород' => 66,
            'Самара' => 78,
            'Казань' => 88,
        ];

        return $data[$cityName] ?? null;
    }
    
    // Сохранение станции метро в базу данных
    private function saveMetroStation(array $values)
    {
        // Проверяем, есть ли уже такая станция
        $exists = Manager::table('metro_stations')
            ->where('location_id', $values['location_id'])
            ->where('name', $values['name'])
            ->where('line', $values['line'])
            ->exists();

        if ($exists) {
            return;
        }

        try {
            Manager::table('metro_stations')->insert($values);

        } catch (Exception $e) {
            $this->logger->error("Ошибка при сохранении станции {$values['name']}", [
                'locationId' => $values['location_id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'parse-metro-stations');
        }
    }
} 