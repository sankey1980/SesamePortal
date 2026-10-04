<?php

declare(strict_types=1);

namespace SesamePortal;

/**
 * OpenAPI paths for the SesamePortal API.
 *
 * Every operation here mirrors a real branch of AppApiTrait::apiPortalV1():
 * a path that the dispatcher cannot reach is deliberately absent, and an alias
 * that only returns 404 or 405 is described as such rather than invented as a
 * supported verb. tests/openapi_spec_test.php and the contract loop in
 * tests/http_smoke.sh keep the two sides honest.
 */
trait AppOpenApiPathsTrait
{
    /**
     * @return array<string, mixed>
     */
    private static function openApiPaths(): array
    {
        return array_merge(
            self::openApiSelfPaths(),
            self::openApiDashboardPaths(),
            self::openApiUserPaths(),
            self::openApiGroupPaths(),
            self::openApiFolderPaths(),
            self::openApiServerPaths(),
            self::openApiCameraPaths(),
            self::openApiFavoritePaths(),
            self::openApiVideoWallPaths(),
            self::openApiAgentPaths(),
            self::openApiAuditPaths(),
            self::openApiAuthPaths(),
            self::openApiBillingPaths(),
            self::openApiDvrPaths(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiSelfPaths(): array
    {
        return [
            '/api/portal/v1' => [
                'get' => [
                    'tags' => ['Self'],
                    'summary' => 'List the resources of this API version.',
                    'operationId' => 'getApiIndex',
                    'security' => self::oaPublicSecurity(),
                    'responses' => [
                        '200' => self::oaJsonRef('Service index.', 'ApiIndex'),
                        '500' => self::oaErrRef('InternalError'),
                    ],
                ],
            ],
            '/api/portal/v1/me' => [
                'get' => [
                    'tags' => ['Self'],
                    'summary' => 'Return the profile of the authenticated caller.',
                    'description' => 'Answers the session cookie when the browser is signed in, otherwise the static token. Returns the folders that define what the caller can see.',
                    'operationId' => 'getMe',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('Profile of the caller.', 'MeResponse'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiDashboardPaths(): array
    {
        return [
            '/api/portal/v1/dashboard' => [
                'get' => [
                    'tags' => ['Dashboard'],
                    'summary' => 'Object counts and the servers with their last metrics.',
                    'operationId' => 'getDashboard',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Counts and server rows.', 'DashboardResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'post' => [
                    'tags' => ['Dashboard'],
                    'summary' => 'Fetch fresh DVR metrics.',
                    'description' => 'With `serverId` the metrics of that one server are returned as the DVR sent them. Without it, every unblocked server is queried and the answers are collected into `results`.',
                    'operationId' => 'fetchDashboardMetrics',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => [
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'serverId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Limit the query to one server.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJson(
                            'Live DVR metrics. With `serverId` the metrics object of that one server, without it a `results` array holding one entry per unblocked server.',
                            [
                                'oneOf' => [
                                    self::oaDvrPassThrough('Metrics of a single server, when `serverId` was sent.'),
                                    self::oaRef('MetricsResultsResponse'),
                                ],
                            ]
                        ),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiUserPaths(): array
    {
        return [
            '/api/portal/v1/users' => [
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'List users.',
                    'operationId' => 'listUsers',
                    'security' => self::oaUserSecurity(),
                    'parameters' => self::oaListParameters(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Users of this portal.', 'UsersResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Access control'],
                    'summary' => 'Create a user.',
                    'operationId' => 'createUser',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('UserWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '201' => self::oaJsonRef('The created user.', 'UserResponse'),
                        '400' => self::oaBadJson(),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/users/{userId}' => [
                'parameters' => [self::oaPathParam('userId', 'Numeric user id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'Return one user.',
                    'operationId' => 'getUser',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The user with its folder links.', 'UserResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'put' => [
                    'tags' => ['Access control'],
                    'summary' => 'Replace a user.',
                    'description' => 'Alias of PATCH: fields that are omitted keep their current value.',
                    'operationId' => 'replaceUser',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('UserWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated user.', 'UserResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Access control'],
                    'summary' => 'Update a user.',
                    'operationId' => 'updateUser',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('UserWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated user.', 'UserResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Access control'],
                    'summary' => 'Delete a user.',
                    'operationId' => 'deleteUser',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Deleted.', 'Ok'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
            '/api/portal/v1/users/{userId}/static-token' => [
                'parameters' => [self::oaPathParam('userId', 'Numeric user id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'Read the static token of a user.',
                    'description' => 'Answers the token in clear text, or `null` when none is issued. Prefer `hasStaticToken` from the user row when you only need to know whether one exists.',
                    'operationId' => 'getUserStaticToken',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The token or null.', 'TokenResult'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'post' => [
                    'tags' => ['Access control'],
                    'summary' => 'Issue or rotate the static token of a user.',
                    'description' => 'Rotating revokes the previous token immediately, so any script still holding it stops working.',
                    'operationId' => 'issueUserStaticToken',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The new token.', 'TokenResult'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Access control'],
                    'summary' => 'Revoke the static token of a user.',
                    'operationId' => 'revokeUserStaticToken',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Revoked.', 'Ok'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiGroupPaths(): array
    {
        return [
            '/api/portal/v1/groups' => [
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'List groups.',
                    'operationId' => 'listGroups',
                    'security' => self::oaUserSecurity(),
                    'parameters' => self::oaListParameters(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Groups of this portal.', 'GroupsResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Access control'],
                    'summary' => 'Create a group.',
                    'operationId' => 'createGroup',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('GroupWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '201' => self::oaJsonRef('The created group.', 'GroupResponse'),
                        '400' => self::oaBadJson(),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/groups/{groupId}' => [
                'parameters' => [self::oaPathParam('groupId', 'Numeric group id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'Return one group with its children and folders.',
                    'operationId' => 'getGroup',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The group.', 'GroupResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'put' => [
                    'tags' => ['Access control'],
                    'summary' => 'Replace a group.',
                    'operationId' => 'replaceGroup',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('GroupWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated group.', 'GroupResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Access control'],
                    'summary' => 'Update a group.',
                    'operationId' => 'updateGroup',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('GroupWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated group.', 'GroupResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Access control'],
                    'summary' => 'Delete a group.',
                    'description' => 'Children are detached first, so they survive as root groups. Folder links to the group are removed with it.',
                    'operationId' => 'deleteGroup',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Deleted.', 'Ok'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
            '/api/portal/v1/groups/{groupId}/children' => [
                'parameters' => [self::oaPathParam('groupId', 'Numeric group id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'List the direct children of a group.',
                    'operationId' => 'listGroupChildren',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The group with its direct children.', 'GroupChildrenResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'post' => [
                    'tags' => ['Access control'],
                    'summary' => 'Create a child group.',
                    'description' => 'Same as creating a group with `parentGroupId` set to this group; the path wins over the body.',
                    'operationId' => 'createGroupChild',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('GroupWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '201' => self::oaJsonRef('The created child group.', 'GroupResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/groups/{groupId}/users' => [
                'parameters' => [self::oaPathParam('groupId', 'Numeric group id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'List the users that have a camera in this group.',
                    'description' => 'Read-only. Membership itself is managed through the folder endpoints, since a user sees exactly the cameras linked to their folders.',
                    'operationId' => 'listGroupUsers',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Users reachable through the folders of this group.', 'GroupUsersResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
            '/api/portal/v1/groups/{groupId}/cameras' => [
                'parameters' => [self::oaPathParam('groupId', 'Numeric group id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'List the cameras in the folders of this group.',
                    'description' => 'Read-only, see the note on the sibling users endpoint.',
                    'operationId' => 'listGroupCameras',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Cameras linked to the folders of this group.', 'GroupCamerasResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiFolderPaths(): array
    {
        $membersOk = [
            '200' => self::oaJsonRef('The folder with its members after the change.', 'FolderDetail'),
        ];

        return [
            '/api/portal/v1/folders' => [
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'List folders.',
                    'operationId' => 'listFolders',
                    'security' => self::oaUserSecurity(),
                    'parameters' => self::oaListParameters(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Folders of this portal.', 'FoldersResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Access control'],
                    'summary' => 'Create a folder.',
                    'operationId' => 'createFolder',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '201' => self::oaJsonRef('The created folder.', 'FolderResponse'),
                        '400' => self::oaBadJson(),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/folders/{folderId}' => [
                'parameters' => [self::oaPathParam('folderId', 'Numeric folder id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'Return one folder with its members.',
                    'operationId' => 'getFolder',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The folder.', 'FolderResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'put' => [
                    'tags' => ['Access control'],
                    'summary' => 'Replace a folder.',
                    'operationId' => 'replaceFolder',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated folder.', 'FolderResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Access control'],
                    'summary' => 'Update a folder.',
                    'operationId' => 'updateFolder',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated folder.', 'FolderResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Access control'],
                    'summary' => 'Delete a folder.',
                    'description' => 'Links to users and cameras are removed with it; the users and cameras themselves survive.',
                    'operationId' => 'deleteFolder',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Deleted.', 'Ok'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
            '/api/portal/v1/folders/{folderId}/users' => [
                'parameters' => [self::oaPathParam('folderId', 'Numeric folder id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'List the users linked to a folder.',
                    'operationId' => 'listFolderUsers',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The folder with its users.', 'FolderUsersResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'post' => [
                    'tags' => ['Access control'],
                    'summary' => 'Add users to a folder.',
                    'description' => 'Ids that are already linked are ignored, so the call is safe to repeat.',
                    'operationId' => 'addFolderUsers',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderUserIdsWrite'),
                    'responses' => self::oaAuthErrors(true) + $membersOk + [
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'put' => [
                    'tags' => ['Access control'],
                    'summary' => 'Replace the users of a folder.',
                    'description' => 'The resulting set is exactly `userIds`, so an empty array clears the folder.',
                    'operationId' => 'replaceFolderUsers',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderUserIdsWrite'),
                    'responses' => self::oaAuthErrors(true) + $membersOk + [
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Access control'],
                    'summary' => 'Replace the users of a folder.',
                    'description' => 'Alias of PUT.',
                    'operationId' => 'updateFolderUsers',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderUserIdsWrite'),
                    'responses' => self::oaAuthErrors(true) + $membersOk + [
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Access control'],
                    'summary' => 'Remove users from a folder.',
                    'operationId' => 'removeFolderUsers',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderUserIdsWrite'),
                    'responses' => self::oaAuthErrors(true) + $membersOk + [
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/folders/{folderId}/cameras' => [
                'parameters' => [self::oaPathParam('folderId', 'Numeric folder id.', 'integer')],
                'get' => [
                    'tags' => ['Access control'],
                    'summary' => 'List the cameras linked to a folder.',
                    'description' => 'These are exactly the cameras a user linked to this folder can see.',
                    'operationId' => 'listFolderCameras',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The folder with its cameras.', 'FolderCamerasResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'post' => [
                    'tags' => ['Access control'],
                    'summary' => 'Add cameras to a folder.',
                    'operationId' => 'addFolderCameras',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderCameraIdsWrite'),
                    'responses' => self::oaAuthErrors(true) + $membersOk + [
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'put' => [
                    'tags' => ['Access control'],
                    'summary' => 'Replace the cameras of a folder.',
                    'operationId' => 'replaceFolderCameras',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderCameraIdsWrite'),
                    'responses' => self::oaAuthErrors(true) + $membersOk + [
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Access control'],
                    'summary' => 'Replace the cameras of a folder.',
                    'description' => 'Alias of PUT.',
                    'operationId' => 'updateFolderCameras',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderCameraIdsWrite'),
                    'responses' => self::oaAuthErrors(true) + $membersOk + [
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Access control'],
                    'summary' => 'Remove cameras from a folder.',
                    'operationId' => 'removeFolderCameras',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('FolderCameraIdsWrite'),
                    'responses' => self::oaAuthErrors(true) + $membersOk + [
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiServerPaths(): array
    {
        return [
            '/api/portal/v1/servers' => [
                'get' => [
                    'tags' => ['Servers'],
                    'summary' => 'List DVR servers.',
                    'operationId' => 'listServers',
                    'security' => self::oaUserSecurity(),
                    'parameters' => self::oaListParameters(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Configured DVR servers.', 'ServersResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Servers'],
                    'summary' => 'Register a DVR server.',
                    'operationId' => 'createServer',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('ServerWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '201' => self::oaJsonRef('The registered server.', 'ServerResponse'),
                        '400' => self::oaBadJson(),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/servers/{serverId}' => [
                'parameters' => [self::oaPathParam('serverId', 'Numeric DVR server id.', 'integer')],
                'get' => [
                    'tags' => ['Servers'],
                    'summary' => 'Return one DVR server with its last metrics.',
                    'operationId' => 'getServer',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The server.', 'ServerResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'put' => [
                    'tags' => ['Servers'],
                    'summary' => 'Replace a DVR server.',
                    'operationId' => 'replaceServer',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('ServerWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated server.', 'ServerResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Servers'],
                    'summary' => 'Update a DVR server.',
                    'operationId' => 'updateServer',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('ServerWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated server.', 'ServerResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Servers'],
                    'summary' => 'Remove a DVR server from the portal.',
                    'description' => 'Streams on that server are left untouched; only the portal side registration is removed.',
                    'operationId' => 'deleteServer',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Removed.', 'Ok'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
            '/api/portal/v1/servers/{serverId}/check' => [
                'parameters' => [self::oaPathParam('serverId', 'Numeric DVR server id.', 'integer')],
                'post' => [
                    'tags' => ['Servers'],
                    'summary' => 'Run a reachability check against a DVR server.',
                    'operationId' => 'checkServer',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJson('Check result as returned by the DVR client.', self::oaDvrPassThrough('Reachability check payload.')),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
            '/api/portal/v1/servers/{serverId}/refresh' => [
                'parameters' => [self::oaPathParam('serverId', 'Numeric DVR server id.', 'integer')],
                'post' => [
                    'tags' => ['Servers'],
                    'summary' => 'Fetch fresh metrics from a DVR server.',
                    'operationId' => 'refreshServerMetrics',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJson('Metrics as returned by the DVR client.', self::oaDvrPassThrough('Live DVR metrics payload.')),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiCameraPaths(): array
    {
        $identifierParam = self::oaPathParam(
            'cameraId',
            'Camera id as a number, or the DVR stream name, or the camera name. A camera that the caller may not see answers 404.',
            'string'
        );

        return [
            '/api/portal/v1/cameras' => [
                'get' => [
                    'tags' => ['Cameras'],
                    'summary' => 'List cameras visible to the caller.',
                    'description' => 'Non-admins only ever see cameras linked to one of their folders. Admins additionally get the full list unless `scope=accessible`.',
                    'operationId' => 'listCameras',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'required' => false, 'description' => '1-based page number.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                        self::oaQuery('pageSize', 'Rows per page (`page_size` also works), up to 500.', 'integer', ['minimum' => 1, 'maximum' => 500, 'default' => 25]),
                        self::oaQuery('scope', 'For admins: `accessible` restricts the list to what the caller can see themselves.', 'string', ['enum' => ['all', 'accessible']]),
                        self::oaQuery('filter', 'List selector. Accepts `all`, `folder:<id>`, `group:<id>`, or a bare group id.', 'string'),
                        self::oaQuery('folderId', 'Shortcut for `filter=folder:<id>`.', 'integer', ['minimum' => 1]),
                        self::oaQuery('folderIds', 'Several folder ids, comma or space separated. Takes precedence over `folderId`.', 'string'),
                        self::oaQuery('groupId', 'Shortcut for `filter=group:<id>`.', 'integer', ['minimum' => 1]),
                        self::oaQuery('groupIds', 'Several group ids, comma or space separated. Takes precedence over `groupId`.', 'string'),
                        self::oaQuery('sort', 'Sort column for admins.', 'string', ['enum' => ['name', 'stream', 'server', 'mode', 'archive', 'retention', 'sync', 'updated', 'created']]),
                        self::oaQuery('dir', 'Sort direction for admins.', 'string', ['enum' => ['asc', 'desc']]),
                        self::oaQuery('q', 'Search query applied to the accessible camera list.', 'string'),
                    ],
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('Cameras visible to the caller.', 'CamerasResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Create a camera.',
                    'description' => 'Admin only. Unless `sync: false` is passed the stream is synchronised on the DVR right after saving.',
                    'operationId' => 'createCamera',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('CameraWrite', false),
                    'responses' => self::oaAuthErrors(true) + [
                        '201' => self::oaJsonRef('The created camera and the sync outcome.', 'CameraSaveResponse'),
                        '400' => self::oaBadJson(),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/cameras/{cameraId}' => [
                'parameters' => [$identifierParam],
                'get' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Return one camera with its folder links.',
                    'operationId' => 'getCamera',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('The camera.', 'CameraResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'put' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Replace a camera.',
                    'description' => 'Admin only. Alias of PATCH: omitted fields keep their current value.',
                    'operationId' => 'replaceCamera',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('CameraWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated camera and the sync outcome.', 'CameraSaveResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Update a camera.',
                    'description' => 'Admin only.',
                    'operationId' => 'updateCamera',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('CameraWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The updated camera and the sync outcome.', 'CameraSaveResponse'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '409' => self::oaErrRef('Conflict'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Delete a camera.',
                    'description' => 'Admin only. With `purge: true` the stream is also deleted on the DVR, and a DVR refusal answers 502 without deleting the portal row.',
                    'operationId' => 'deleteCamera',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => [
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'purge' => ['type' => 'boolean', 'default' => false, 'description' => 'Also delete the stream on the DVR. `deleteDvrStream` is accepted as an alias.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Deleted, with the DVR result when `purge` was requested.', 'CameraDeleteResult'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '502' => self::oaErrRef('DvrUnavailable'),
                    ],
                ],
            ],
            '/api/portal/v1/cameras/{cameraId}/sync' => [
                'parameters' => [$identifierParam],
                'post' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Synchronise the stream of a camera on the DVR.',
                    'description' => 'Admin only. Answers the DVR client payload verbatim.',
                    'operationId' => 'syncCamera',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJson('Sync result as returned by the DVR client.', self::oaDvrPassThrough('Camera synchronisation payload.')),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
            '/api/portal/v1/cameras/{cameraId}/permanent-token' => [
                'parameters' => [$identifierParam],
                'get' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Read the permanent token of a camera.',
                    'description' => 'Admin only. Answers `null` when no token is issued.',
                    'operationId' => 'getCameraPermanentToken',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The token or null.', 'TokenResult'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'post' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Issue or rotate the permanent token of a camera.',
                    'description' => 'Admin only. A camera permanent token is what the DVR sends to `/api/sesamedvr/auth` when it checks stream access, and it is bound to one camera.',
                    'operationId' => 'issueCameraPermanentToken',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('The new token.', 'TokenResult'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Revoke the permanent token of a camera.',
                    'operationId' => 'revokeCameraPermanentToken',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Revoked.', 'Ok'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiFavoritePaths(): array
    {
        return [
            '/api/portal/v1/favorites' => [
                'get' => [
                    'tags' => ['Cameras'],
                    'summary' => 'List the cameras the caller marked as favorites.',
                    'operationId' => 'listFavorites',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'required' => false, 'description' => '1-based page number.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                        self::oaQuery('pageSize', 'Rows per page (`page_size` also works), up to 500.', 'integer', ['minimum' => 1, 'maximum' => 500, 'default' => 25]),
                        self::oaQuery('q', 'Search query applied to the favorite list.', 'string'),
                    ],
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('Favorites of the caller.', 'FavoritesResponse'),
                    ],
                ],
            ],
            '/api/portal/v1/favorites/{cameraId}' => [
                'parameters' => [self::oaPathParam('cameraId', 'Numeric camera id.', 'integer')],
                'put' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Add a camera to the favorites of the caller.',
                    'operationId' => 'addFavorite',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('The camera is a favorite now.', 'FavoriteResult'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'post' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Add a camera to the favorites of the caller.',
                    'description' => 'Alias of PUT.',
                    'operationId' => 'addFavoriteAlias',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('The camera is a favorite now.', 'FavoriteResult'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Cameras'],
                    'summary' => 'Remove a camera from the favorites of the caller.',
                    'operationId' => 'removeFavorite',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('The camera is no longer a favorite.', 'FavoriteResult'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiVideoWallPaths(): array
    {
        $csrfNote = 'Session-authenticated writes additionally require the `X-CSRF-Token` header; requests authenticated with a static token do not.';

        return [
            '/api/portal/v1/video-walls' => [
                'get' => [
                    'tags' => ['Video walls'],
                    'summary' => 'List the video walls of the caller.',
                    'operationId' => 'listVideoWalls',
                    'security' => self::oaUserSecurity(),
                    'parameters' => self::oaListParameters(),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('Video walls of the caller.', 'VideoWallListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Video walls'],
                    'summary' => 'Create a video wall.',
                    'description' => $csrfNote,
                    'operationId' => 'createVideoWall',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('VideoWallWrite'),
                    'responses' => self::oaAuthErrors(false) + [
                        '201' => self::oaJsonRef('The created video wall.', 'VideoWallResponse'),
                        '400' => self::oaBadJson(),
                        '419' => self::oaErrRef('CsrfMismatch'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/video-walls/{videoWallId}' => [
                'parameters' => [self::oaPathParam('videoWallId', 'Numeric video wall id.', 'integer')],
                'get' => [
                    'tags' => ['Video walls'],
                    'summary' => 'Return one video wall.',
                    'description' => 'A video wall of another user answers 404, not 403.',
                    'operationId' => 'getVideoWall',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('The video wall.', 'VideoWallResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
                'put' => [
                    'tags' => ['Video walls'],
                    'summary' => 'Replace a video wall.',
                    'description' => $csrfNote,
                    'operationId' => 'replaceVideoWall',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('VideoWallWrite'),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('The updated video wall.', 'VideoWallResponse'),
                        '400' => self::oaBadJson(),
                        '419' => self::oaErrRef('CsrfMismatch'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Video walls'],
                    'summary' => 'Update a video wall.',
                    'description' => $csrfNote,
                    'operationId' => 'updateVideoWall',
                    'security' => self::oaUserSecurity(),
                    'requestBody' => self::oaBodyRef('VideoWallWrite'),
                    'responses' => self::oaAuthErrors(false) + [
                        '200' => self::oaJsonRef('The updated video wall.', 'VideoWallResponse'),
                        '400' => self::oaBadJson(),
                        '419' => self::oaErrRef('CsrfMismatch'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Video walls'],
                    'summary' => 'Delete a video wall.',
                    'description' => $csrfNote . ' Answers 204 with an empty body.',
                    'operationId' => 'deleteVideoWall',
                    'security' => self::oaUserSecurity(),
                    'responses' => self::oaAuthErrors(false) + [
                        '204' => ['description' => 'Deleted. The body is empty.'],
                        '419' => self::oaErrRef('CsrfMismatch'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiAgentPaths(): array
    {
        $serverIdParam = self::oaQuery('serverId', 'DVR server the agents belong to. Required on every agent endpoint; `server_id` is accepted too.', 'integer', ['minimum' => 1]);
        $agentParam = self::oaPathParam('agentId', 'Agent identifier as registered on the DVR.', 'string');
        $passThrough = static fn(string $what): array => self::oaJson('As returned by the DVR: ' . $what, self::oaDvrPassThrough($what));

        return [
            '/api/portal/v1/agents' => [
                'get' => [
                    'tags' => ['Agents'],
                    'summary' => 'List the edge agents of a DVR server.',
                    'operationId' => 'listAgents',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Agent list.'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'post' => [
                    'tags' => ['Agents'],
                    'summary' => 'Register an edge agent on a DVR server.',
                    'operationId' => 'createAgent',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'requestBody' => self::oaBodyRef('AgentCreateWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '201' => $passThrough('Created agent.'),
                        '400' => self::oaBadJson(),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}' => [
                'parameters' => [$agentParam],
                'get' => [
                    'tags' => ['Agents'],
                    'summary' => 'Return one agent with its cameras, commands and logs.',
                    'operationId' => 'getAgent',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Agent detail collected from the DVR.', 'AgentDetailResponse'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'put' => [
                    'tags' => ['Agents'],
                    'summary' => 'Update an agent.',
                    'operationId' => 'replaceAgent',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'requestBody' => self::oaBodyRef('AgentUpdateWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Updated agent.'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'patch' => [
                    'tags' => ['Agents'],
                    'summary' => 'Update an agent.',
                    'description' => 'Only the fields present in the body are sent to the DVR.',
                    'operationId' => 'updateAgent',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'requestBody' => self::oaBodyRef('AgentUpdateWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Updated agent.'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'delete' => [
                    'tags' => ['Agents'],
                    'summary' => 'Delete an agent.',
                    'operationId' => 'deleteAgent',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Deletion result.'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}/cameras' => [
                'parameters' => [$agentParam],
                'get' => [
                    'tags' => ['Agents'],
                    'summary' => 'List the cameras reported by an agent.',
                    'operationId' => 'listAgentCameras',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Agent cameras.'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}/cameras/scan' => [
                'parameters' => [$agentParam],
                'post' => [
                    'tags' => ['Agents'],
                    'summary' => 'Ask an agent to rescan for cameras.',
                    'operationId' => 'scanAgentCameras',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Scan result.'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}/commands' => [
                'parameters' => [$agentParam],
                'get' => [
                    'tags' => ['Agents'],
                    'summary' => 'List the commands an agent accepts.',
                    'operationId' => 'listAgentCommands',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Command catalogue.'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
                'post' => [
                    'tags' => ['Agents'],
                    'summary' => 'Run a command on an agent.',
                    'operationId' => 'runAgentCommand',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'requestBody' => self::oaBodyRef('AgentCommandWrite', false),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Command result.'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}/logs' => [
                'parameters' => [$agentParam],
                'get' => [
                    'tags' => ['Agents'],
                    'summary' => 'Read the logs of an agent.',
                    'operationId' => 'getAgentLogs',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Agent logs.'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}/enrollment-password' => [
                'parameters' => [$agentParam],
                'post' => [
                    'tags' => ['Agents'],
                    'summary' => 'Set the enrollment password of an agent.',
                    'operationId' => 'setAgentEnrollmentPassword',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'requestBody' => self::oaBodyRef('AgentPasswordWrite'),
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Updated agent.'),
                        '400' => self::oaBadJson(),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}/revoke' => [
                'parameters' => [$agentParam],
                'post' => [
                    'tags' => ['Agents'],
                    'summary' => 'Revoke an agent.',
                    'operationId' => 'revokeAgent',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Revocation result.'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}/rotate-secret' => [
                'parameters' => [$agentParam],
                'post' => [
                    'tags' => ['Agents'],
                    'summary' => 'Rotate the shared secret of an agent.',
                    'description' => 'The agent must be reconfigured with the new secret afterwards.',
                    'operationId' => 'rotateAgentSecret',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Rotation result.'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
            '/api/portal/v1/agents/{agentId}/diagnostics' => [
                'parameters' => [$agentParam],
                'post' => [
                    'tags' => ['Agents'],
                    'summary' => 'Collect diagnostics from an agent.',
                    'operationId' => 'getAgentDiagnostics',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [$serverIdParam],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => $passThrough('Diagnostics payload.'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiAuditPaths(): array
    {
        return [
            '/api/portal/v1/audit' => [
                'get' => [
                    'tags' => ['Audit'],
                    'summary' => 'Read the audit trail.',
                    'operationId' => 'listAuditEvents',
                    'security' => self::oaUserSecurity(),
                    'parameters' => [
                        self::oaQuery('q', 'Free-text filter applied to action and details.', 'string'),
                        self::oaQuery('action', 'Filter by exact action name, for example `camera.save`.', 'string'),
                        self::oaQuery('actor', 'Filter by actor user id.', 'integer', ['minimum' => 1]),
                        ['name' => 'page', 'in' => 'query', 'required' => false, 'description' => '1-based page number.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                        self::oaQuery('pageSize', 'Rows per page (`page_size` also works), up to 500. Defaults to 50 here.', 'integer', ['minimum' => 1, 'maximum' => 500, 'default' => 50]),
                    ],
                    'responses' => self::oaAuthErrors(true) + [
                        '200' => self::oaJsonRef('Audit events, newest first.', 'AuditResponse'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiAuthPaths(): array
    {
        return [
            '/api/portal/v1/auth/token-by-phone' => [
                'post' => [
                    'tags' => ['Integrations'],
                    'summary' => 'Exchange a phone number for the static token of that account.',
                    'description' => 'Machine-to-machine login for external apps. Requires the `external_app_key` setting; answers 503 when it is empty. The token is created on first use and returned again afterwards.',
                    'operationId' => 'issueTokenByPhone',
                    'security' => self::oaAppKeySecurity(),
                    'requestBody' => self::oaBodyRef('TokenByPhoneWrite'),
                    'responses' => [
                        '200' => self::oaJsonRef('The token of the account behind the phone number.', 'PhoneTokenResult'),
                        '400' => self::oaBadJson(),
                        '401' => self::oaErrRef('Unauthorized'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                        '503' => self::oaErrRef('IntegrationDisabled'),
                    ],
                ],
            ],
            '/api/portal/v1/auth/callback/start' => [
                'post' => [
                    'tags' => ['Integrations'],
                    'summary' => 'Start a callback authorization for a phone number.',
                    'description' => 'Creates a pending request that lives for 120 seconds. The user is expected to call the number returned in `callback_phone`, which confirms the request through the webhook. At most 5 attempts per minute per IP address.',
                    'operationId' => 'startCallbackAuth',
                    'security' => self::oaPublicSecurity(),
                    'requestBody' => self::oaBodyRef('CallbackStartWrite'),
                    'responses' => [
                        '200' => self::oaJsonRef('The pending callback request.', 'CallbackStartResult'),
                        '400' => self::oaBadJson(),
                        '401' => self::oaErr('401 `callback_disabled` or `user_not_found`. Callback authorization is switched off, or no active account uses this phone number.'),
                        '409' => self::oaErr('409 `callback_not_configured`. No callback phone number is set on this instance.'),
                        '422' => self::oaErrRef('ValidationFailed'),
                        '429' => self::oaErrRef('RateLimited'),
                    ],
                ],
            ],
            '/api/portal/v1/auth/callback/poll' => [
                'get' => [
                    'tags' => ['Integrations'],
                    'summary' => 'Poll the status of a callback request.',
                    'operationId' => 'pollCallbackAuth',
                    'security' => self::oaPublicSecurity(),
                    'parameters' => [
                        self::oaQuery('pending_id', 'Identifier returned by the start call.', 'string'),
                    ],
                    'responses' => [
                        '200' => self::oaJsonRef('Current status of the request.', 'CallbackStatusResult'),
                        '400' => self::oaErr('400 `missing_pending_id`. The `pending_id` parameter is missing.'),
                        '404' => self::oaErrRef('NotFound'),
                    ],
                ],
            ],
            '/api/portal/v1/auth/callback/complete' => [
                'post' => [
                    'tags' => ['Integrations'],
                    'summary' => 'Finish a callback authorization and start a browser session.',
                    'description' => 'Answers the redirect the caller should send the user to. A confirmed but not yet completed request logs the user in and sets the remember-me cookie.',
                    'operationId' => 'completeCallbackAuth',
                    'security' => self::oaPublicSecurity(),
                    'requestBody' => self::oaBodyRef('CallbackCompleteWrite'),
                    'responses' => [
                        '200' => self::oaJson('The request is complete. `redirect` tells the client where to send the browser.', ['type' => 'object', 'required' => ['ok', 'redirect'], 'properties' => ['ok' => ['type' => 'boolean'], 'redirect' => ['type' => 'string', 'example' => '/']]]),
                        '400' => self::oaErr('400 `missing_pending_id`. The `pending_id` field is missing.'),
                        '401' => self::oaErr('401 `user_not_found`. The phone number behind the request has no active account.'),
                        '404' => self::oaErrRef('NotFound'),
                        '409' => self::oaErr('409 `not_confirmed` or `request_expired`. The webhook has not confirmed the request yet, or it expired.'),
                    ],
                ],
            ],
            '/api/portal/v1/auth/callback/webhook' => [
                'post' => [
                    'tags' => ['Integrations'],
                    'summary' => 'Confirm a callback request from the telephony side.',
                    'description' => 'Called by the call-handling system, not by the portal UI. Authenticates with the `callback_webhook_token` setting and scans the whole request body for the first phone-like number of 8 to 15 digits.',
                    'operationId' => 'callbackAuthWebhook',
                    'security' => [
                        ['callbackToken' => []],
                        ['callbackBearer' => []],
                    ],
                    'requestBody' => self::oaBodyRef('CallbackWebhookWrite', false),
                    'responses' => [
                        '200' => self::oaJsonRef('The pending request is confirmed.', 'Ok'),
                        '400' => self::oaBadJson(),
                        '401' => self::oaErr('401 `callback_disabled` or `webhook_not_configured`. Callback authorization is off, or no webhook token is configured.'),
                        '403' => self::oaErr('403 `invalid_token`. The webhook token does not match.'),
                        '404' => self::oaErr('404 `no_active_request`. No pending request for that phone number.'),
                        '422' => self::oaErrRef('ValidationFailed'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiBillingPaths(): array
    {
        return [
            '/api/portal/v1/billing/groups/block' => [
                'post' => [
                    'tags' => ['Integrations'],
                    'summary' => 'Block or unblock a group by its billing id.',
                    'description' => 'Called by a billing system through the `external_app_key`; answers 503 when the integration is not configured.',
                    'operationId' => 'blockGroupByBillingId',
                    'security' => self::oaAppKeySecurity(),
                    'requestBody' => self::oaBodyRef('BillingBlockWrite'),
                    'responses' => [
                        '200' => self::oaJsonRef('The group after the change.', 'GroupResponse'),
                        '400' => self::oaBadJson(),
                        '401' => self::oaErrRef('Unauthorized'),
                        '404' => self::oaErrRef('NotFound'),
                        '422' => self::oaErrRef('ValidationFailed'),
                        '503' => self::oaErrRef('IntegrationDisabled'),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApiDvrPaths(): array
    {
        return [
            '/api/sesamedvr/auth' => [
                'get' => [
                    'tags' => ['DVR'],
                    'summary' => 'Authorisation callback used by SesameDVR when it opens a stream.',
                    'description' => 'Answers plain text, not JSON: `ok` with 200, `denied` with 403, and `archive_denied` when the user is not allowed to use the archive.\n\nThe token is read from `token`, `auth_token` or `playback_token`; it is also picked up from the `qs` parameter or from the `u` target URL, because the DVR sends a full playback URL. The camera name comes from the request parameters or the path of `u`.\n\nTwo tokens are accepted: a camera permanent token, which is bound to one camera, and a user token (static or daily).',
                    'operationId' => 'dvrAuthBackend',
                    'security' => self::oaPublicSecurity(),
                    'parameters' => [
                        self::oaQuery('token', 'Static, daily or camera permanent token. `auth_token` and `playback_token` are accepted as aliases.', 'string'),
                        self::oaQuery('qs', 'Query string that itself carries the token, as sent by the DVR.', 'string'),
                        self::oaQuery('u', 'Target URL; the token and camera name are taken from it when present.', 'string'),
                        self::oaQuery('camera', 'Camera name to authorise. Also read from `stream` or `name`.', 'string'),
                    ],
                    'responses' => [
                        '200' => ['description' => '`ok` — the DVR may open the stream.', 'content' => ['text/plain' => ['schema' => ['type' => 'string', 'example' => 'ok']]]],
                        '403' => ['description' => '`denied` for a missing or invalid token, a camera that does not exist, or a user without access to it. `archive_denied` when the archive is hidden from this user.', 'content' => ['text/plain' => ['schema' => ['type' => 'string', 'example' => 'denied']]]],
                    ],
                ],
            ],
        ];
    }

    /**
     * 400 for a body that `apiInput()` refuses to parse. Returned as a single
     * Response Object so that call sites can write `'400' => self::oaBadJson()`.
     *
     * @return array<string, mixed>
     */
    private static function oaBadJson(): array
    {
        return self::oaErr('400 `invalid_json`. The request body could not be parsed as JSON.');
    }
}