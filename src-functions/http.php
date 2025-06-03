<?php
namespace Polidog\Shield\Http;
use JsonException;

function getHttpRequest(): array
{
    // 特定のヘッダーを取得
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // すべてのHTTPヘッダーを取得
    $headers = [
        'User-Agent' => $userAgent,
        'Content-Type' => $contentType,
        'Authorization' => $authorization,
    ];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $header = str_replace(array('HTTP_', '_'), array('', '-'), $key);
            $header = ucwords(strtolower($header), '-');
            $headers[$header] = $value;
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
    // This function is a placeholder for the actual HTTP response logic.
    // It should handle the response, such as sending it back to the client.
    header('Content-Type: application/json');
    http_response_code($statusCode);
    try {
        echo json_encode($response, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        http_response_code(500);
        echo "{\"error\": \"Internal Server Error\"}";
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
                if (isset($paramDefinition['required']) && $paramDefinition['required']) {
                    return [true, [
                        'message' => "Missing required query parameter: $paramName",
                        'parameter' => $paramName,
                    ], $request]; // Required parameter is missing
                }
                // Set default value if available
                $request['query'][$paramName] = $paramDefinition['default'] ?? null;
            }

            $query = match($definition['query'][$paramName]['type'] ?? 'string') {
                'int' => (int)$request['query'][$paramName],
                'float' => (float)$request['query'][$paramName],
                'bool' => filter_var($request['query'][$paramName], FILTER_VALIDATE_BOOLEAN),
                'array' => explode(',', $request['query'][$paramName]),
                default => (string)$request['query'][$paramName],
            };
            $request['query'][$paramName] = $query;
        }
        $request['query'] = $request['query'] ?? [];
    }
    return [false, [], $request]; // Placeholder implementation
}

function bindBody(array $definition, array $request)
{
    if ($request['method'] === 'POST' || $request['method'] === 'PUT') {
        // Handle request body for POST/PUT methods
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

    // クエリの検証
    [$error, $message, $requestWithQuery] = bindQueryParams($definition, $request);
    if ($error) {
        writeHttpResponse($message, 400);
        return;
    }
    // リクエストBodyの検証
    [$bodyError, $message, $body] = bindBody($definition, $requestWithQuery);
    if ($bodyError) {
        writeHttpResponse($message, 400);
        return;
    }
    $requestWithQuery['body'] = $body;

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