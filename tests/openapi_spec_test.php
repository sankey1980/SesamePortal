<?php

declare(strict_types=1);

/**
 * Structural contract test for the OpenAPI document served at /openapi.json.
 *
 * Runs without a database and without a web server: the document is assembled in
 * process by App::openApiSpec(). Checks the things a real client depends on —
 * resolvable $refs, path parameters matching their template, unique operationIds,
 * declared tags, existing security schemes, a success response per operation — and
 * that the documented surface stays inside the two prefixes the router owns.
 *
 * Usage: php tests/openapi_spec_test.php
 */

require_once __DIR__ . '/../app/Portal.php';

use SesamePortal\App;

const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

$spec = App::openApiSpec();
$errors = [];

$fail = static function (string $message) use (&$errors): void {
    $errors[] = $message;
};

// ---------------------------------------------------------------- document head

if (($spec['openapi'] ?? '') !== '3.0.3') {
    $fail('openapi must be 3.0.3, got ' . var_export($spec['openapi'] ?? null, true));
}
foreach (['title', 'version'] as $key) {
    if (empty($spec['info'][$key])) {
        $fail("info.$key is empty");
    }
}
if (empty($spec['servers'][0]['url'])) {
    $fail('servers[0].url is empty');
}
// `summary` on Info only exists in OpenAPI 3.1 and breaks strict 3.0 validators.
if (isset($spec['info']['summary'])) {
    $fail('info.summary is not part of the OpenAPI 3.0 Info object');
}

$schemas = $spec['components']['schemas'] ?? [];
$responses = $spec['components']['responses'] ?? [];
$securitySchemes = $spec['components']['securitySchemes'] ?? [];
$declaredTags = [];
foreach ($spec['tags'] ?? [] as $tag) {
    $declaredTags[$tag['name']] = true;
}

foreach (['Error', 'Pagination', 'ApiIndex'] as $required) {
    if (!isset($schemas[$required])) {
        $fail("components.schemas.$required is missing");
    }
}
foreach (['Unauthorized', 'Forbidden', 'NotFound', 'Conflict', 'ValidationFailed'] as $required) {
    if (!isset($responses[$required])) {
        $fail("components.responses.$required is missing");
    }
}
foreach (['bearerAuth', 'portalToken', 'sessionCookie', 'appKey', 'callbackToken'] as $required) {
    if (!isset($securitySchemes[$required])) {
        $fail("components.securitySchemes.$required is missing");
    }
}

// ---------------------------------------------------------------- $ref integrity

$walk = static function (array $node, callable $visit) use (&$walk): void {
    foreach ($node as $key => $value) {
        $visit((string) $key, $value);
        if (is_array($value)) {
            $walk($value, $visit);
        }
    }
};

$walk($spec, static function (string $key, $value) use ($fail): void {
    if ($key !== '$ref' || !is_string($value)) {
        return;
    }
    if (!str_starts_with($value, '#/')) {
        $fail("external \$ref is not allowed: $value");
        return;
    }
    $node = $GLOBALS['spec'];
    foreach (explode('/', substr($value, 2)) as $segment) {
        if (!is_array($node) || !array_key_exists($segment, $node)) {
            $fail("unresolvable \$ref: $value");
            return;
        }
        $node = $node[$segment];
    }
});

// ---------------------------------------------------------------- paths

$operationIds = [];
$operationCount = 0;
$documentedVerbs = [];

foreach ($spec['paths'] ?? [] as $path => $item) {
    if (!str_starts_with($path, '/api/portal/v1') && $path !== '/api/sesamedvr/auth') {
        $fail("path outside the documented prefixes: $path");
    }
    if (!is_array($item)) {
        $fail("path item $path is not an object");
        continue;
    }

    $templateParams = [];
    preg_match_all('/\{([^}]+)\}/', $path, $matches);
    $templateParams = $matches[1];

    // Parameters declared on the path item apply to every operation below it.
    $shared = [];
    foreach ($item['parameters'] ?? [] as $parameter) {
        if (($parameter['in'] ?? '') === 'path') {
            $shared[] = (string) ($parameter['name'] ?? '');
        }
    }
    foreach ($templateParams as $needed) {
        if (!in_array($needed, $shared, true)) {
            $fail("$path: template parameter {{$needed}} is not declared on the path item");
        }
    }
    foreach ($shared as $declared) {
        if (!in_array($declared, $templateParams, true)) {
            $fail("$path: declared path parameter $declared is not part of the template");
        }
    }

    foreach ($item as $method => $operation) {
        if (!in_array($method, HTTP_METHODS, true)) {
            continue;
        }
        $operationCount++;
        $where = strtoupper($method) . ' ' . $path;
        $documentedVerbs[$path][] = strtoupper($method);

        $id = $operation['operationId'] ?? '';
        if ($id === '') {
            $fail("$where: operationId is empty");
        } elseif (isset($operationIds[$id])) {
            $fail("$where: duplicate operationId $id, already used by " . $operationIds[$id]);
        } else {
            $operationIds[$id] = $where;
        }
        if (($operation['summary'] ?? '') === '') {
            $fail("$where: summary is empty");
        }

        foreach ($operation['tags'] ?? [] as $tag) {
            if (!isset($declaredTags[$tag])) {
                $fail("$where: tag $tag is not declared in the top level tags list");
            }
        }
        foreach ($operation['security'] ?? [] as $requirement) {
            foreach (array_keys($requirement) as $scheme) {
                if (!isset($securitySchemes[$scheme])) {
                    $fail("$where: security requirement $scheme is not a declared security scheme");
                }
            }
        }

        $declaredHere = [];
        foreach (array_merge($item['parameters'] ?? [], $operation['parameters'] ?? []) as $parameter) {
            foreach (['name', 'in', 'schema'] as $field) {
                if (!isset($parameter[$field])) {
                    $fail("$where: parameter is missing $field");
                }
            }
            if (($parameter['in'] ?? '') === 'path') {
                $declaredHere[] = (string) $parameter['name'];
                if (($parameter['required'] ?? false) !== true) {
                    $fail("$where: path parameter {$parameter['name']} must be required");
                }
                if (!in_array((string) $parameter['name'], $templateParams, true)) {
                    $fail("$where: path parameter {$parameter['name']} is not in the template");
                }
            }
        }
        foreach ($templateParams as $needed) {
            if (!in_array($needed, $shared, true) && !in_array($needed, $declaredHere, true)) {
                $fail("$where: template parameter {{$needed}} is not declared");
            }
        }

        $responsesOfOperation = $operation['responses'] ?? [];
        if ($responsesOfOperation === []) {
            $fail("$where: no responses");
        }
        $success = false;
        foreach ($responsesOfOperation as $status => $response) {
            if (!preg_match('/^[1-5][0-9X]{2}$/', (string) $status)) {
                $fail("$where: invalid response key $status");
            }
            if (!is_array($response)) {
                $fail("$where: response $status is not an object");
                continue;
            }
            if ($status === 'default' || (int) $status < 400) {
                $success = $success || $status !== 'default' && (int) $status < 400;
            }
            if (isset($response['content']) && str_starts_with($path, '/api/portal/v1')) {
                foreach ($response['content'] as $mediaType => $media) {
                    if (!str_contains((string) $mediaType, 'json')) {
                        $fail("$where: response $status uses non JSON content type $mediaType");
                    }
                    if (!isset($media['schema'])) {
                        $fail("$where: response $status has content without a schema");
                    }
                }
            }
        }
        if (!$success) {
            $fail("$where: no success response");
        }
    }
}

if ($operationCount < 50) {
    $fail("only $operationCount operations documented, the API surface shrank unexpectedly");
}

// ---------------------------------------------------------------- dispatch contract

// The two prefixes the router claims to own, and the verbs the switch in AppApiTrait
// and AppAuthBackendTrait actually implement. A resource that shows up in the spec but
// not here means the document drifted away from the code.
$expected = [
    '/api/portal/v1' => ['GET'],
    '/api/portal/v1/me' => ['GET'],
    '/api/portal/v1/dashboard' => ['GET', 'POST'],
    '/api/portal/v1/users' => ['GET', 'POST'],
    '/api/portal/v1/users/{userId}' => ['GET', 'PUT', 'PATCH', 'DELETE'],
    '/api/portal/v1/users/{userId}/static-token' => ['GET', 'POST', 'DELETE'],
    '/api/portal/v1/groups' => ['GET', 'POST'],
    '/api/portal/v1/groups/{groupId}' => ['GET', 'PUT', 'PATCH', 'DELETE'],
    '/api/portal/v1/groups/{groupId}/children' => ['GET', 'POST'],
    // Group membership is read only; linking is managed through the folder endpoints.
    '/api/portal/v1/groups/{groupId}/users' => ['GET'],
    '/api/portal/v1/groups/{groupId}/cameras' => ['GET'],
    '/api/portal/v1/folders' => ['GET', 'POST'],
    '/api/portal/v1/folders/{folderId}' => ['GET', 'PUT', 'PATCH', 'DELETE'],
    // POST adds, PUT and PATCH replace the whole set, DELETE removes the listed ids.
    '/api/portal/v1/folders/{folderId}/users' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '/api/portal/v1/folders/{folderId}/cameras' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    '/api/portal/v1/servers' => ['GET', 'POST'],
    '/api/portal/v1/servers/{serverId}' => ['GET', 'PUT', 'PATCH', 'DELETE'],
    '/api/portal/v1/servers/{serverId}/check' => ['POST'],
    '/api/portal/v1/servers/{serverId}/refresh' => ['POST'],
    '/api/portal/v1/cameras' => ['GET', 'POST'],
    '/api/portal/v1/cameras/{cameraId}' => ['GET', 'PUT', 'PATCH', 'DELETE'],
    '/api/portal/v1/cameras/{cameraId}/sync' => ['POST'],
    '/api/portal/v1/cameras/{cameraId}/permanent-token' => ['GET', 'POST', 'DELETE'],
    '/api/portal/v1/favorites' => ['GET'],
    '/api/portal/v1/favorites/{cameraId}' => ['PUT', 'POST', 'DELETE'],
    '/api/portal/v1/video-walls' => ['GET', 'POST'],
    '/api/portal/v1/video-walls/{videoWallId}' => ['GET', 'PUT', 'PATCH', 'DELETE'],
    '/api/portal/v1/agents' => ['GET', 'POST'],
    '/api/portal/v1/agents/{agentId}' => ['GET', 'PUT', 'PATCH', 'DELETE'],
    '/api/portal/v1/agents/{agentId}/cameras' => ['GET'],
    '/api/portal/v1/agents/{agentId}/cameras/scan' => ['POST'],
    '/api/portal/v1/agents/{agentId}/commands' => ['GET', 'POST'],
    '/api/portal/v1/agents/{agentId}/logs' => ['GET'],
    '/api/portal/v1/agents/{agentId}/enrollment-password' => ['POST'],
    '/api/portal/v1/agents/{agentId}/revoke' => ['POST'],
    '/api/portal/v1/agents/{agentId}/rotate-secret' => ['POST'],
    '/api/portal/v1/agents/{agentId}/diagnostics' => ['POST'],
    '/api/portal/v1/audit' => ['GET'],
    '/api/portal/v1/auth/token-by-phone' => ['POST'],
    '/api/portal/v1/auth/callback/start' => ['POST'],
    '/api/portal/v1/auth/callback/poll' => ['GET'],
    '/api/portal/v1/auth/callback/complete' => ['POST'],
    '/api/portal/v1/auth/callback/webhook' => ['POST'],
    '/api/portal/v1/billing/groups/block' => ['POST'],
    '/api/sesamedvr/auth' => ['GET'],
];

$documented = $documentedVerbs;
ksort($documented);
$documentedKeys = [];
foreach ($documented as $path => $verbs) {
    sort($verbs);
    $documentedKeys[] = $path . ' ' . implode(',', $verbs);
}
sort($documentedKeys);
$expectedKeys = [];
foreach ($expected as $path => $verbs) {
    sort($verbs);
    $expectedKeys[] = $path . ' ' . implode(',', $verbs);
}
sort($expectedKeys);

foreach (array_diff($expectedKeys, $documentedKeys) as $missing) {
    $fail("undocumented route: $missing");
}
foreach (array_diff($documentedKeys, $expectedKeys) as $extra) {
    $fail("documented route that the router does not implement: $extra");
}

// The DVR auth backend is served from its own prefix and is not part of apiPortalV1.
$backendPath = '/api/sesamedvr/auth';
if (!isset($spec['paths'][$backendPath])) {
    $fail("$backendPath is not documented");
} elseif (!isset($spec['paths'][$backendPath]['get'])) {
    $fail("$backendPath has no GET operation");
}

// ---------------------------------------------------------------- assets and page

$publicDir = dirname(__DIR__) . '/public';
foreach (['docs.html', 'assets/swagger-ui/swagger-ui.css', 'assets/swagger-ui/swagger-ui-bundle.js', 'assets/swagger-ui/swagger-ui-standalone-preset.js', 'assets/swagger-ui/LICENSE'] as $asset) {
    if (!is_file($publicDir . '/' . $asset)) {
        $fail("missing UI asset: public/$asset");
    }
}
$docs = (string) @file_get_contents($publicDir . '/docs.html');
if (!str_contains($docs, 'swagger-ui.css') || !str_contains($docs, 'swagger-ui-bundle.js')) {
    $fail('public/docs.html does not reference the vendored Swagger UI files');
}
if (preg_match('#https?://(cdn|unpkg|cdnjs)#i', $docs)) {
    $fail('public/docs.html must not load Swagger UI from a CDN');
}

if ($errors !== []) {
    fwrite(STDERR, "openapi_spec_test: " . count($errors) . " problem(s)\n");
    foreach ($errors as $message) {
        fwrite(STDERR, "  - $message\n");
    }
    exit(1);
}

printf(
    "openapi_spec_test: ok, %d paths, %d operations, %d schemas\n",
    count($spec['paths']),
    $operationCount,
    count($schemas)
);