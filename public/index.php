<?php

declare(strict_types=1);

const DEFAULT_BEARER_TOKEN = 'CHANGE_ME_SUPER_SECRET_BEARER_KEY';
const DEFAULT_DATABASE_PATH = '/data/options.sqlite';

header('Content-Type: application/json; charset=utf-8');

$bearerToken = getenv('BEARER_TOKEN') ?: DEFAULT_BEARER_TOKEN;
$databasePath = getenv('DATABASE_PATH') ?: DEFAULT_DATABASE_PATH;

try {
    authenticate($bearerToken);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uuid = matchOptionsRoute($path);

    if ($uuid === null) {
        sendJson(['error' => 'Not found'], 404);
    }

    $headerUuid = getHeaderValue('X-Minecraft-UUID');
    $headerUuid = $headerUuid === null ? null : strtolower($headerUuid);
    if ($headerUuid === null || !hash_equals($uuid, $headerUuid)) {
        sendJson(['error' => 'X-Minecraft-UUID must match the URL uuid'], 400);
    }

    $pdo = connectDatabase($databasePath);

    if ($method === 'GET') {
        $options = loadOptions($pdo, $uuid);
        sendJson(['options' => $options]);
    }

    if ($method === 'PUT') {
        $payload = decodeJsonBody();
        $options = validateOptionsPayload($payload);
        saveOptions($pdo, $uuid, $options);
        sendJson(['options' => $options]);
    }

    sendJson(['error' => 'Method not allowed'], 405, ['Allow' => 'GET, PUT']);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    sendJson(['error' => 'Internal server error'], 500);
}

function authenticate(string $expectedToken): void
{
    $authorization = getHeaderValue('Authorization');
    $expectedHeader = 'Bearer ' . $expectedToken;

    if ($authorization === null || !hash_equals($expectedHeader, $authorization)) {
        sendJson(['error' => 'Unauthorized'], 401);
    }
}

function matchOptionsRoute(string $path): ?string
{
    if (!preg_match('#^/api/options/([0-9a-fA-F-]{32,36})$#', $path, $matches)) {
        return null;
    }

    return strtolower($matches[1]);
}

function connectDatabase(string $databasePath): PDO
{
    $databaseDirectory = dirname($databasePath);
    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0775, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Could not create database directory.');
    }

    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_options (
            uuid TEXT PRIMARY KEY,
            options_json TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    return $pdo;
}

/**
 * @return array<int, array{key: string, value: string}>
 */
function loadOptions(PDO $pdo, string $uuid): array
{
    $statement = $pdo->prepare('SELECT options_json FROM user_options WHERE uuid = :uuid');
    $statement->execute(['uuid' => $uuid]);

    $json = $statement->fetchColumn();
    if ($json === false) {
        return [];
    }

    $decoded = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, array{key: string, value: string}> $options
 */
function saveOptions(PDO $pdo, string $uuid, array $options): void
{
    $statement = $pdo->prepare(
        'INSERT INTO user_options (uuid, options_json, updated_at)
         VALUES (:uuid, :options_json, :updated_at)
         ON CONFLICT(uuid) DO UPDATE SET
            options_json = excluded.options_json,
            updated_at = excluded.updated_at'
    );

    $statement->execute([
        'uuid' => $uuid,
        'options_json' => json_encode($options, JSON_THROW_ON_ERROR),
        'updated_at' => gmdate('c'),
    ]);
}

/**
 * @return array<string, mixed>
 */
function decodeJsonBody(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        sendJson(['error' => 'Request body must be JSON'], 400);
    }

    try {
        $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        sendJson(['error' => 'Invalid JSON'], 400);
    }

    if (!is_array($payload)) {
        sendJson(['error' => 'JSON body must be an object'], 400);
    }

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 * @return array<int, array{key: string, value: string}>
 */
function validateOptionsPayload(array $payload): array
{
    if (!array_key_exists('options', $payload) || !is_array($payload['options'])) {
        sendJson(['error' => 'Body must contain an options array'], 400);
    }

    $options = [];
    foreach ($payload['options'] as $index => $entry) {
        if (!is_array($entry) || !array_key_exists('key', $entry) || !array_key_exists('value', $entry)) {
            sendJson(['error' => "Option at index {$index} must contain key and value"], 400);
        }

        if (!is_scalar($entry['key']) || !is_scalar($entry['value'])) {
            sendJson(['error' => "Option at index {$index} must use scalar key and value"], 400);
        }

        $key = trim((string) $entry['key']);
        if ($key === '') {
            sendJson(['error' => "Option at index {$index} has an empty key"], 400);
        }

        $options[] = [
            'key' => $key,
            'value' => (string) $entry['value'],
        ];
    }

    return $options;
}

function getHeaderValue(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if ($name === 'Authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return null;
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 */
function sendJson(array $body, int $statusCode = 200, array $headers = []): never
{
    http_response_code($statusCode);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}
