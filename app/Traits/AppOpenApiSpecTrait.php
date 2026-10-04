<?php

declare(strict_types=1);

namespace SesamePortal;

/**
 * Assembles the OpenAPI document for the SesamePortal HTTP API.
 *
 * The document is built from plain PHP arrays on purpose: the project ships no
 * composer dependencies, no build step and no generated artifacts, so a YAML
 * spec would require a parser nobody depends on. Assembling in PHP also lets
 * tests/openapi_spec_test.php validate the document in-process and lets the
 * live endpoint serve it without touching the filesystem.
 *
 * Paths live in AppOpenApiPathsTrait, components/schemas in AppOpenApiSchemasTrait.
 */
trait AppOpenApiSpecTrait
{
    /** Semantic version of the described API. The URL prefix stays /v1. */
    private const OPENAPI_VERSION = '1.0.0';

    private const OPENAPI_SPEC_VERSION = '3.0.3';

    /**
     * Full OpenAPI document.
     *
     * @return array<string, mixed>
     */
    public static function openApiSpec(): array
    {
        return [
            'openapi' => self::OPENAPI_SPEC_VERSION,
            'info' => self::openApiInfo(),
            'servers' => self::openApiServers(),
            'tags' => self::openApiTags(),
            'paths' => self::openApiPaths(),
            'components' => self::openApiComponents(),
        ];
    }

    /**
     * `GET /openapi.json`: the document itself. Public, so that the documentation can be
     * read before signing in and so that external tooling can fetch it anonymously.
     */
    public static function openApiJsonResponse(): void
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(self::openApiSpec(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit;
    }

    /**
     * `GET /docs.html` and `GET /api/docs`: the Swagger UI shell from
     * public/assets/swagger-ui. Rendered standalone on purpose, so that it works without
     * a session and without the navigation chrome of the web UI.
     */
    public static function openApiDocsPage(): void
    {
        $file = dirname(__DIR__, 2) . '/public/docs.html';
        if (!is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "docs.html is missing\n";
            exit;
        }
        $html = (string)file_get_contents($file);
        // Point the UI at the spec and at the asset directory actually in use, so the page
        // keeps working behind a sub-path installation or a test router.
        $html = strtr($html, [
            '"/openapi.json"' => json_encode(self::openApiBaseUrl() . 'openapi.json', JSON_UNESCAPED_SLASHES),
            '"/assets/swagger-ui/' => json_encode(self::openApiBaseUrl() . 'assets/swagger-ui/', JSON_UNESCAPED_SLASHES),
        ]);
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        echo $html;
        exit;
    }

    /**
     * Absolute base URL of the installation, always ending in a slash. Derived from
     * SCRIPT_NAME so that a sub-path installation and the test router both work.
     */
    private static function openApiBaseUrl(): string
    {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (str_ends_with($script, '/index.php')) {
            $base = substr($script, 0, -strlen('index.php'));
            return $base === '' ? '/' : $base;
        }
        return '/';
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiInfo(): array
    {
        return [
            'title' => 'SesamePortal API',
            'version' => self::OPENAPI_VERSION,
            'description' => self::openApiDescription(),
            'license' => [
                'name' => 'Proprietary',
            ],
        ];
    }

    private static function openApiDescription(): string
    {
        return <<<'TEXT'
        Local surveillance portal API for SesameDVR servers. The path prefix is
        `/api/portal/v1`; the version segment is independent from the document
        version below. Verbs that are not listed for a path answer `404 not_found`
        or `405 method_not_allowed`.

        ### Authentication

        Three ways to authenticate, in the order the server checks them:

        1. `Authorization: Bearer <static-token>` — the recommended way for scripts and
           integrations. Tokens are issued per user (`sp_...`) and are listed in the admin
           UI. The same token is accepted in the `X-Portal-Token` or `X-Api-Token` header.
        2. The `sesame_portal` session cookie — used by the web UI. Works from the same
           origin; needs `X-CSRF-Token` on the video wall write endpoints.
        3. `X-App-Key` — only for the external integration endpoints
           (`/auth/token-by-phone`, `/billing/groups/block`), authenticated against the
           `external_app_key` setting instead of a user token.

        ### Authorisation

        Most resources require the `admin` role and answer `403 forbidden` otherwise.
        `me`, `cameras`, `favorites` and `video-walls` are available to any authenticated
        user, with rows filtered down to what the user may see: a non-admin gets `404
        not_found` for a camera that is not linked to any of their folders, which
        intentionally does not reveal that the camera exists.

        ### Errors

        Every error is a JSON envelope: `{"error": {"code": "...", "message": "..."}}`.
        Some codes add sibling keys inside `error`, for example `existingId` on a login
        conflict or `retry_after_seconds` on a rate limit. See the `Error` schema for
        the full list of codes.

        ### Pagination

        List endpoints accept `page` (1-based) and `pageSize` (`page_size` also works)
        and answer with a `pagination` object. The maximum page size is 200 for users,
        groups, folders and servers, and 500 for cameras, favorites and audit events.

        ### Field naming

        Requests accept both `camelCase` and `snake_case` spellings for every field.
        Responses always use `camelCase`.
        TEXT;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function openApiServers(): array
    {
        return [
            [
                'url' => '/',
                'description' => 'This SesamePortal instance',
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function openApiTags(): array
    {
        return [
            ['name' => 'Self', 'description' => 'API index and information about the authenticated caller.'],
            ['name' => 'Dashboard', 'description' => 'Object counts and DVR metrics. Admin only.'],
            ['name' => 'Cameras', 'description' => 'Cameras, their DVR stream settings and per-user favorites.'],
            ['name' => 'Servers', 'description' => 'DVR servers, reachability checks and metrics. Admin only.'],
            ['name' => 'Video walls', 'description' => 'Per-user video wall layouts.'],
            ['name' => 'Access control', 'description' => 'Users, groups and folders that define what a user can see. Admin only.'],
            ['name' => 'Agents', 'description' => 'Edge agents registered on a DVR server. Admin only.'],
            ['name' => 'Audit', 'description' => 'Administrative audit trail. Admin only.'],
            ['name' => 'Integrations', 'description' => 'Endpoints for external applications: phone login tokens, callback authorization and billing.'],
            ['name' => 'DVR', 'description' => 'Endpoints the SesameDVR server itself calls.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiComponents(): array
    {
        return [
            'securitySchemes' => self::openApiSecuritySchemes(),
            'responses' => self::openApiResponses(),
            'schemas' => self::openApiSchemas(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiSecuritySchemes(): array
    {
        return [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'Static user token (`sp_...`) issued by an administrator. Send it as `Authorization: Bearer <token>`.',
            ],
            'portalToken' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'X-Portal-Token',
                'description' => 'Alternative to the `Authorization` header: same static user token. `X-Api-Token` is accepted too.',
            ],
            'sessionCookie' => [
                'type' => 'apiKey',
                'in' => 'cookie',
                'name' => 'sesame_portal',
                'description' => 'Browser session of the web UI. Session-authenticated writes to `/video-walls` additionally require `X-CSRF-Token`.',
            ],
            'appKey' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'X-App-Key',
                'description' => 'Shared secret from the `external_app_key` setting, checked with `hash_equals`. Only used by the integration endpoints.',
            ],
            'callbackToken' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'X-Callback-Token',
                'description' => 'Webhook token from the `callback_webhook_token` setting.',
            ],
            'callbackBearer' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'The same `callback_webhook_token` value, sent as `Authorization: Bearer <token>`. Used when the header form is unavailable.',
            ],
        ];
    }

/**
     * Reusable responses. Every API error is the same `Error` envelope, only the
     * status code and the wording differ.
     *
     * @return array<string, mixed>
     */
    private static function openApiResponses(): array
    {
        return [
            'Unauthorized' => self::oaErr('401 `unauthorized`. Missing or invalid credentials: no session cookie, no static token, or an invalid external app key.'),
            'Forbidden' => self::oaErr('403 `forbidden`. Authenticated, but the `admin` role is required for this resource.'),
            'NotFound' => self::oaErr('404 `not_found`. No such object, or the caller may not see it.'),
            'Conflict' => self::oaErr('409 `conflict`. The object conflicts with an existing one. Codes: `login_exists`, `phone_exists`, `group_id_exists`, `billing_id_exists`.'),
            'CsrfMismatch' => self::oaErr('419 `csrf_mismatch`. Session-authenticated write to `/video-walls` without a valid `X-CSRF-Token`.'),
            'ValidationFailed' => self::oaErr('422 `validation_failed`. Body or query failed validation. Codes: `validation_failed`, `invalid_phone`, `invalid_stream_name`, `invalid_video_wall`.'),
            'RateLimited' => self::oaErr('429 `rate_limited`. Too many attempts. Codes: `rate_limited`, `pending_exists`. Carries `retry_after_seconds` inside `error`.'),
            'InternalError' => self::oaErr('500 `internal_error`. Unhandled server-side failure; details are written to the PHP error log.'),
            'DvrUnavailable' => self::oaErr('502 `dvr_delete_failed`. The DVR rejected the operation.'),
            'IntegrationDisabled' => self::oaErr('503 `integration_disabled`. The integration is switched off, for example because `external_app_key` is empty.'),
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, string>
     */
    private static function oaRef(string $component): array
    {
        return ['$ref' => '#/components/schemas/' . $component];
    }

    /**
     * @return array<string, string>
     */
    private static function oaResponseRef(string $component): array
    {
        return ['$ref' => '#/components/responses/' . $component];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaListOf(string $component): array
    {
        return ['type' => 'array', 'items' => self::oaRef($component)];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function oaJson(string $description, array $schema): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaJsonRef(string $description, string $component): array
    {
        return self::oaJson($description, self::oaRef($component));
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaErr(string $description): array
    {
        return self::oaJson($description, self::oaRef('Error'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaErrRef(string $component): array
    {
        return self::oaResponseRef($component);
    }

    /**
     * 401, plus 403 when the endpoint is admin-only.
     *
     * @return array<string, mixed>
     */
    private static function oaAuthErrors(bool $admin): array
    {
        $responses = ['401' => self::oaErrRef('Unauthorized')];
        if ($admin) {
            $responses['403'] = self::oaErrRef('Forbidden');
        }
        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaPathParam(string $name, string $description, string $type = 'integer'): array
    {
        $schema = ['type' => $type];
        if ($type === 'integer') {
            $schema['minimum'] = 1;
        }
        return [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'description' => $description,
            'schema' => $schema,
        ];
    }

    /**
     * @param array<string, mixed> $extra extra keys merged into the parameter (for example `example`)
     * @return array<string, mixed>
     */
    private static function oaQuery(string $name, string $description, string $type = 'string', array $extra = []): array
    {
        $parameter = [
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'schema' => array_merge(['type' => $type], $extra),
        ];
        return $parameter;
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaBodyRef(string $schema, bool $required = true): array
    {
        $content = ['content' => ['application/json' => ['schema' => self::oaRef($schema)]]];
        if ($required) {
            $content['required'] = true;
        }
        return $content;
    }

    /**
     * Authentication for a regular, session-or-token authenticated endpoint.
     *
     * @return array<int, array<string, array<int, string>>>
     */
    private static function oaUserSecurity(): array
    {
        return [
            ['bearerAuth' => []],
            ['portalToken' => []],
            ['sessionCookie' => []],
        ];
    }

    /**
     * @return array<int, array<string, array<int, string>>>
     */
    private static function oaPublicSecurity(): array
    {
        return [];
    }

    /**
     * @return array<int, array<string, array<int, string>>>
     */
    private static function oaAppKeySecurity(): array
    {
        return [['appKey' => []]];
    }

    /**
     * Standard list parameters. The `pageSize` bound differs per resource, so it is
     * not taken from components/parameters when the cap is not the default.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function oaListParameters(bool $withPageSize = true): array
    {
        $parameters = [['name' => 'page', 'in' => 'query', 'required' => false, 'description' => '1-based page number.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]]];
        if ($withPageSize) {
            $parameters[] = self::oaQuery('pageSize', 'Rows per page (`page_size` also works).', 'integer', ['minimum' => 1, 'maximum' => 200, 'default' => 25]);
        }
        return $parameters;
    }
}