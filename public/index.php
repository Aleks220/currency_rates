<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Predis\Client as RedisClient;
use Dotenv\Dotenv;

// Загружаем .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Получаем дату из запроса
$date = $_GET['date'] ?? date('d.m.Y');
$dateFormatted = DateTime::createFromFormat('d.m.Y', $date)?->format('Y-m-d') ?? date('Y-m-d');

header('Content-Type: application/json');

// Инициализация Redis
$redis = new RedisClient([
    'scheme' => 'tcp',
    'host'   => $_ENV['REDIS_HOST'] ?? 'redis_currencies',
    'port'   => (int) ($_ENV['REDIS_PORT'] ?? 6379),
]);

$cacheKey = "nbu:currencies:$dateFormatted";

// 1. Redis
if ($redis->exists($cacheKey)) {
    echo $redis->get($cacheKey);
    exit;
}

// 2. Подключение к БД
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'],
            $_ENV['DB_DATABASE']
        ),
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'message' => $e->getMessage()]);
    exit;
}

// 3. Поиск в БД
$stmt = $pdo->prepare("SELECT * FROM currencies WHERE exchangedate = ?");
$stmt->execute([$dateFormatted]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($data) {
    $json = json_encode($data);
    $redis->setex($cacheKey, 3600, $json);
    echo $json;
    exit;
}

// 4. Запрос к NBU
try {
    $client = new Client(['base_uri' => 'https://bank.gov.ua']);
    $response = $client->get('/NBUStatService/v1/statdirectory/exchange', [
        'query' => [
            'json' => '',
            'date' => str_replace('-', '', $dateFormatted) // формат: 20171219
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => 'NBU API unavailable', 'message' => $e->getMessage()]);
    exit;
}

if ($response->getStatusCode() !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'NBU API responded with error']);
    exit;
}

$body = $response->getBody()->getContents();
$currencies = json_decode($body, true);

// 5. Сохраняем в БД
$insert = $pdo->prepare("
    INSERT IGNORE INTO currencies (r030, txt, rate, cc, exchangedate)
    VALUES (?, ?, ?, ?, ?)
");

foreach ($currencies as $c) {
    $insert->execute([
        $c['r030'],
        $c['txt'],
        $c['rate'],
        $c['cc'],
        date('Y-m-d', strtotime($c['exchangedate']))
    ]);
}

// 6. Кешируем и возвращаем
$redis->setex($cacheKey, 3600, $body);
echo $body;
