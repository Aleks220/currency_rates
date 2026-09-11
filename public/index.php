<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Predis\Client as RedisClient;

final class NbuApiException extends RuntimeException
{
}

function respond(array $payload, int $statusCode = 200, ?string $source = null): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    if ($source !== null) {
        header("X-Data-Source: $source");
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parseExchangeDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!d.m.Y', $date);
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsed === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $parsed->format('d.m.Y') !== $date
    ) {
        respond([
            'status' => 'error',
            'message' => 'Некоректна дата. Очікуваний формат: ДД.ММ.ГГГГ',
        ], 400);
    }
    return $parsed->format('Y-m-d');
}

function createSyncTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS currency_syncs (
            exchange_date DATE PRIMARY KEY,
            status ENUM('loading', 'completed', 'failed') NOT NULL,
            currencies_count INT NOT NULL DEFAULT 0,
            completed_at TIMESTAMP NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}

function getSyncState(PDO $pdo, string $date): ?array
{
    $statement = $pdo->prepare(
        'SELECT status, currencies_count FROM currency_syncs WHERE exchange_date = ?'
    );
    $statement->execute([$date]);
    $state = $statement->fetch(PDO::FETCH_ASSOC);
    return $state === false ? null : $state;
}

function getCompletedCurrencies(
    PDO $pdo,
    string $date,
    ?array $state = null,
    ?string $valcode = null
): ?array
{
    $state ??= getSyncState($pdo, $date);
    if ($state === null || $state['status'] !== 'completed' || (int) $state['currencies_count'] < 1) {
        return null;
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM currencies WHERE exchangedate = ?');
    $count->execute([$date]);
    if ((int) $count->fetchColumn() !== (int) $state['currencies_count']) {
        return null;
    }

    $sql = <<<'SQL'
        SELECT r030, txt, rate, cc, DATE_FORMAT(exchangedate, '%d.%m.%Y') AS exchangedate
        FROM currencies
        WHERE exchangedate = ?
        SQL;
    $parameters = [$date];

    if ($valcode !== null) {
        $sql .= ' AND cc = ?';
        $parameters[] = $valcode;
    }

    $sql .= ' ORDER BY r030';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $currencies = $statement->fetchAll(PDO::FETCH_ASSOC);

    return $currencies;
}

function getCachedCurrencies(RedisClient $redis, string $cacheKey, int $expectedCount): ?array
{
    $cached = $redis->get($cacheKey);
    if ($cached === null) {
        return null;
    }

    $currencies = json_decode($cached, true);
    if (!is_array($currencies) || !array_is_list($currencies) || count($currencies) !== $expectedCount) {
        $redis->del([$cacheKey]);
        return null;
    }
    return $currencies;
}

function respondWithCurrencies(array $currencies, string $date, ?string $valcode, string $source): never
{
    if ($valcode === null) {
        respond($currencies, 200, $source);
    }

    foreach ($currencies as $currency) {
        if (isset($currency['cc']) && strtoupper((string) $currency['cc']) === $valcode) {
            respond([$currency], 200, $source);
        }
    }

    respond([
        'status' => 'error',
        'message' => sprintf(
            'Не вдалось отримати курс валюти %s за %s',
            $valcode,
            DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('d.m.Y')
        ),
    ], 404, $source);
}

function fetchNbuCurrencies(string $date): array
{
    $configuredBaseUri = getenv('NBU_BASE_URI');
    $client = new Client([
        'base_uri' => $configuredBaseUri !== false
            ? $configuredBaseUri
            : ($_ENV['NBU_BASE_URI'] ?? 'https://bank.gov.ua'),
        'connect_timeout' => 5,
        'timeout' => 20,
        'http_errors' => false,
    ]);

    try {
        $response = $client->get('/NBUStatService/v1/statdirectory/exchange', [
            'query' => [
                'json' => '',
                'date' => str_replace('-', '', $date),
            ],
        ]);
    } catch (Throwable $exception) {
        throw new NbuApiException('NBU API unavailable', 0, $exception);
    }

    if ($response->getStatusCode() !== 200) {
        throw new NbuApiException(
            sprintf('NBU API responded with HTTP %d', $response->getStatusCode())
        );
    }

    $currencies = json_decode($response->getBody()->getContents(), true);
    if (!is_array($currencies) || !array_is_list($currencies) || $currencies === []) {
        throw new NbuApiException('NBU API returned an invalid or empty JSON array');
    }

    $requiredCurrencies = ['EUR' => false, 'USD' => false];
    foreach ($currencies as $index => $currency) {
        if (!is_array($currency)) {
            throw new NbuApiException("NBU API item $index is not an object");
        }

        foreach (['r030', 'cc', 'rate', 'exchangedate'] as $field) {
            if (!array_key_exists($field, $currency)) {
                throw new NbuApiException("NBU API item $index does not contain $field");
            }
        }

        $itemDate = DateTimeImmutable::createFromFormat('!d.m.Y', (string) $currency['exchangedate']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($itemDate === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $itemDate->format('Y-m-d') !== $date
        ) {
            throw new NbuApiException("NBU API item $index has an unexpected exchange date");
        }

        $currencyCode = strtoupper(trim((string) $currency['cc']));
        if ($currencyCode === '' || !is_numeric($currency['r030']) || !is_numeric($currency['rate'])) {
            throw new NbuApiException("NBU API item $index contains invalid currency data");
        }

        if (array_key_exists($currencyCode, $requiredCurrencies)) {
            $requiredCurrencies[$currencyCode] = true;
        }
    }

    foreach ($requiredCurrencies as $currencyCode => $present) {
        if (!$present) {
            throw new NbuApiException("NBU API response does not contain $currencyCode");
        }
    }
    return $currencies;
}

function synchronizeDate(PDO $pdo, RedisClient $redis, string $date, string $cacheKey): array
{
    $redis->del([$cacheKey]);
    $pdo->beginTransaction();

    try {
        $sync = $pdo->prepare(<<<'SQL'
            INSERT INTO currency_syncs (exchange_date, status, currencies_count, completed_at)
            VALUES (?, 'loading', 0, NULL)
            ON DUPLICATE KEY UPDATE
                status = 'loading', currencies_count = 0, completed_at = NULL
            SQL);
        $sync->execute([$date]);

        $currencies = fetchNbuCurrencies($date);

        $delete = $pdo->prepare('DELETE FROM currencies WHERE exchangedate = ?');
        $delete->execute([$date]);

        $insert = $pdo->prepare(<<<'SQL'
            INSERT INTO currencies (r030, txt, rate, cc, exchangedate)
            VALUES (?, ?, ?, ?, ?)
            SQL);
        foreach ($currencies as $currency) {
            $insert->execute([
                (int) $currency['r030'],
                $currency['txt'] ?? null,
                (float) $currency['rate'],
                strtoupper(trim((string) $currency['cc'])),
                $date,
            ]);
        }

        $complete = $pdo->prepare(<<<'SQL'
            UPDATE currency_syncs
            SET status = 'completed', currencies_count = ?, completed_at = CURRENT_TIMESTAMP
            WHERE exchange_date = ?
            SQL);
        $complete->execute([count($currencies), $date]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $redis->del([$cacheKey]);

        try {
            $failed = $pdo->prepare(<<<'SQL'
                INSERT INTO currency_syncs (exchange_date, status, currencies_count, completed_at)
                VALUES (?, 'failed', 0, NULL)
                ON DUPLICATE KEY UPDATE
                    status = 'failed', currencies_count = 0, completed_at = NULL
                SQL);
            $failed->execute([$date]);
        } catch (Throwable $statusException) {
            error_log('Unable to mark currency synchronization as failed: ' . $statusException->getMessage());
        }
        throw $exception;
    }

    $json = json_encode($currencies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode synchronized currencies');
    }

    // Кэш создаётся только после успешного COMMIT.
    $redis->setex($cacheKey, 3600, $json);
    return $currencies;
}

function releaseLock(RedisClient $redis, string $lockKey, string $token): void
{
    $redis->eval(
        "if redis.call('get', KEYS[1]) == ARGV[1] then "
        . "return redis.call('del', KEYS[1]) else return 0 end",
        1,
        $lockKey,
        $token
    );
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$requestedDate = (string) ($_GET['date'] ?? date('d.m.Y'));
$date = parseExchangeDate($requestedDate);
$valcode = isset($_GET['valcode']) ? strtoupper(trim((string) $_GET['valcode'])) : null;
if ($valcode !== null && !preg_match('/^[A-Z]{3}$/', $valcode)) {
    respond([
        'status' => 'error',
        'message' => 'Некорректний код валюти. Очікується три латинські літери',
    ], 400);
}

try {
    $redis = new RedisClient([
        'scheme' => 'tcp',
        'host' => $_ENV['REDIS_HOST'] ?? 'redis_currencies',
        'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
    ]);
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'],
            $_ENV['DB_DATABASE']
        ),
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    createSyncTable($pdo);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond([
        'status' => 'error',
        'message' => 'Внутрішня помилка сховища сервису',
    ], 500);
}

$cacheKey = "nbu:currencies:$date";
$lockKey = "nbu:currencies:lock:$date";
$state = getSyncState($pdo, $date);
if ($state !== null && $state['status'] === 'completed') {
    $currencies = getCachedCurrencies($redis, $cacheKey, (int) $state['currencies_count']);
    if ($currencies !== null) {
        respondWithCurrencies($currencies, $date, $valcode, 'redis');
    }

    $currencies = getCompletedCurrencies($pdo, $date, $state, $valcode);
    if ($currencies !== null) {
        if ($valcode === null) {
            $redis->setex(
                $cacheKey,
                3600,
                json_encode($currencies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
        respondWithCurrencies($currencies, $date, $valcode, 'database');
    }
}

$lockToken = bin2hex(random_bytes(16));
$lockAcquired = (string) $redis->set($lockKey, $lockToken, 'EX', 60, 'NX') === 'OK';
if (!$lockAcquired) {
    $deadline = microtime(true) + 30;
    do {
        usleep(200_000);
        $state = getSyncState($pdo, $date);
        if ($state !== null && $state['status'] === 'completed') {
            $currencies = getCachedCurrencies($redis, $cacheKey, (int) $state['currencies_count'])
                ?? getCompletedCurrencies($pdo, $date, $state, $valcode);
            if ($currencies !== null) {
                respondWithCurrencies($currencies, $date, $valcode, 'synchronized');
            }
        }
    } while (microtime(true) < $deadline);

    respond([
        'status' => 'error',
        'message' => "Синхронізація курсів за $requestedDate ще виконується",
    ], 503);
}

try {
    // Інший запит міг завершити синхронізацію до отримання lock.
    $currencies = getCompletedCurrencies($pdo, $date, null, $valcode);
    if ($currencies !== null) {
        if ($valcode === null) {
            $redis->setex(
                $cacheKey,
                3600,
                json_encode($currencies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
        releaseLock($redis, $lockKey, $lockToken);
        respondWithCurrencies($currencies, $date, $valcode, 'database');
    }

    $currencies = synchronizeDate($pdo, $redis, $date, $cacheKey);
} catch (NbuApiException $exception) {
    error_log($exception->getMessage());
    releaseLock($redis, $lockKey, $lockToken);
    respond([
        'status' => 'error',
        'message' => "Не вдалось завантажити курси НБУ за $requestedDate",
    ], 502);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    releaseLock($redis, $lockKey, $lockToken);
    respond([
        'status' => 'error',
        'message' => 'Не вдалось зберігти повний набір курсів',
    ], 500);
}

releaseLock($redis, $lockKey, $lockToken);
respondWithCurrencies($currencies, $date, $valcode, 'nbu');
