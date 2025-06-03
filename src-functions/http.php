<?php
namespace Polidog\Shield\Http;
use JsonException;

function getHttpRequest(): array
{
    // すべてのHTTPヘッダーを取得（重複・不整合解消）
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $header = str_replace(['HTTP_', '_'], ['', '-'], $key);
            $header = ucwords(strtolower($header), '-');
            $headers[$header] = $value;
        }
    }
    // HTTP_で始まらない主要ヘッダーも追加
    foreach ([
        'CONTENT_TYPE' => 'Content-Type',
        'AUTHORIZATION' => 'Authorization',
    ] as $serverKey => $headerName) {
        if (isset($_SERVER[$serverKey])) {
            $headers[$headerName] = $_SERVER[$serverKey];
        }
    }

    // Query parameters
    $queryParams = [];
    if (isset($_SERVER['QUERY_STRING'])) {
        parse_str($_SERVER['QUERY_STRING'], $queryParams);
    }

    return [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'path' =>  $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
        'query' => $queryParams,
        'headers' => $headers,
        'body' => null,
    ];
}

function writeHttpResponse(array $response, int $statusCode = 200): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    try {
        echo json_encode($response, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
}

function matchDefinition(string $methodAndRoutePath, array $definitions): array|false
{
    if (isset($definitions[$methodAndRoutePath]) && is_array($definitions[$methodAndRoutePath])) {
        return $definitions[$methodAndRoutePath];
    }

    return false;
}

/**
 * @internal
 *
 * @param array $definition
 * @param array $request
 * @return array
 */
function bindQueryParams(array $definition, array $request): array
{
    if (isset($definition['query'])) {
        foreach ($definition['query'] as $paramName => $paramDefinition) {
            if (!isset($request['query'][$paramName])) {
                if (!empty($paramDefinition['required'])) {
                    return [true, [
                        'message' => "Missing required query parameter: $paramName",
                        'parameter' => $paramName,
                    ], $request];
                }
                $request['query'][$paramName] = $paramDefinition['default'] ?? null;
            }

            $value = $request['query'][$paramName];
            $type = $paramDefinition['type'] ?? 'string';
            $converted = match($type) {
                'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
                'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                'array' => is_array($value) ? $value : explode(',', (string)$value),
                default => (string)$value,
            };
            if ($converted === null && $type !== 'string' && $value !== null) {
                return [true, [
                    'message' => "Invalid type for query parameter: $paramName",
                    'parameter' => $paramName,
                ], $request];
            }
            $request['query'][$paramName] = $converted;
        }
    }
    return [false, [], $request];
}

function bindBody(array $definition, array $request)
{
    if ($request['method'] === 'POST' || $request['method'] === 'PUT') {
        $body = file_get_contents('php://input');
        if ($body !== false) {
            try {
                $jsonBody = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                return [false, [], $jsonBody];
            } catch (JsonException $e) {
                return [true, ['message' => 'Invalid JSON body'], $body];
            }
        } else {
            return [true, ['message' => 'Failed to read request body'], null];
        }
    }
    // POST/PUT以外でも必ず3要素返す
    return [false, [], null];
}


/**
 * @param array<callable> $definitions
 * @return void
 * @throws \JsonException
 */
function handleHttpRequest(array $definitions): void
{
    $request = getHttpRequest();
    $definition = matchDefinition($request['method'] . ' ' . $request['path'], $definitions);
    if ($definition === false) {
        writeHttpResponse(['message' => 'No matching route found'], 404);
        return;
    }

    // バリデーションの共通処理
    $validators = [
        function($def, $req) {
            return bindQueryParams($def, $req);
        },
        function($def, $req) {
            [$err, $msg, $body] = bindBody($def, $req);
            $req['body'] = $body;
            return [$err, $msg, $req];
        },
    ];

    $currentRequest = $request;
    foreach ($validators as $validator) {
        [$error, $errorMessage, $currentRequest] = $validator($definition, $currentRequest);
        if ($error) {
            writeHttpResponse($errorMessage, 400);
            return;
        }
    }
    $requestWithQuery = $currentRequest;

    if (isset($definition['callback']) && is_callable($definition['callback'])) {
        [$statusCode, $response] = $definition['callback']($requestWithQuery);
        if (is_array($response)) {
            // If the middleware returns a response, write it and stop further processing
            writeHttpResponse($response, $statusCode);
            return;
        }
    }

    writeHttpResponse(['message' => 'No middleware handled the request'], 404);
}