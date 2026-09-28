<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$token = (string)($_SERVER['HTTP_X_MANAGEMENT_TOKEN'] ?? '');
$query = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json; charset=utf-8');

// ONVIF service mocks — respond to SOAP probes from Portal OnvifProbe (no management token).
if (($path === '/onvif/device_service' || $path === '/onvif/media_service') && $method === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    header('Content-Type: application/soap+xml; charset=utf-8');
    $isDeviceService = $path === '/onvif/device_service';
    $envelopeOpen = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:tds="http://www.onvif.org/ver10/device/wsdl"'
        . ' xmlns:tr="http://www.onvif.org/ver10/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">';
    $authFault = $envelopeOpen
        . '<s:Body><s:Fault><s:Code><s:Value>s:Sender</s:Value><s:Subcode><s:Value>wsse:FailedAuthentication</s:Value></s:Subcode></s:Code>'
        . '<s:Reason><s:Text xml:lang="en">Invalid username or password</s:Text></s:Reason></s:Fault></s:Body>'
        . '</s:Envelope>';
    // Validated credentials: any non-empty username/password pair is accepted,
    // except the reserved login `baduser` which always fails authentication.
    $hasCredentials = stripos($raw, 'UsernameToken') !== false
        && preg_match('/<wsse:Username>([^<]*)<\/wsse:Username>/i', $raw, $uMatch) === 1 && trim($uMatch[1] ?? '') !== ''
        && trim($uMatch[1] ?? '') !== 'baduser'
        && preg_match('/<wsse:Password[^>]*>([^<]*)<\/wsse:Password>/i', $raw, $pMatch) === 1 && trim($pMatch[1] ?? '') !== '';

    // Models a camera that enforces the WS-Security UsernameToken profile: the reserved
    // login `strictdigest` only authenticates when PasswordDigest is SHA-1 over the
    // *raw* nonce bytes (not their Base64 text) concatenated with wsu:Created + password.
    $isStrictDigest = stripos($raw, '>strictdigest<') !== false;
    if ($isStrictDigest && $hasCredentials) {
        preg_match('/<wsse:Password[^>]*>([^<]*)<\/wsse:Password>/i', $raw, $pwM);
        preg_match('/<wsse:Nonce[^>]*>([^<]*)<\/wsse:Nonce>/i', $raw, $nonceM);
        preg_match('/<wsu:Created>([^<]*)<\/wsu:Created>/i', $raw, $createdM);
        $expected = base64_encode(sha1(
            (string)base64_decode(trim($nonceM[1] ?? ''), true)
            . trim($createdM[1] ?? '')
            . 'strictpw',
            true
        ));
        if (!hash_equals($expected, trim($pwM[1] ?? ''))) {
            $hasCredentials = false;
        }
    }

    // Reserved login `nomedia` models a camera without a Media service.
    $isNomedia = stripos($raw, '>nomedia<') !== false;
    if (!$isDeviceService && $isNomedia) {
        http_response_code(404);
        echo 'not found';
        return;
    }

    // Every action except GetSystemDateAndTime requires WS-Security credentials.
    if (stripos($raw, 'GetSystemDateAndTime') === false && !$hasCredentials) {
        http_response_code(200);
        echo $authFault;
        return;
    }

    if (stripos($raw, 'GetSystemDateAndTime') !== false) {
        http_response_code(200);
        echo $envelopeOpen
            . '<s:Body><tds:GetSystemDateAndTimeResponse><tds:SystemDateAndTime><tt:UTCDateTime><tt:Date><tt:Year>2026</tt:Year><tt:Month>9</tt:Month><tt:Day>28</tt:Day></tt:Date><tt:Time><tt:Hour>12</tt:Hour><tt:Minute>0</tt:Minute><tt:Second>0</tt:Second></tt:Time></tt:UTCDateTime></tds:SystemDateAndTime></tds:GetSystemDateAndTimeResponse></s:Body>'
            . '</s:Envelope>';
        return;
    }

    if (stripos($raw, 'GetDeviceInformation') !== false) {
        http_response_code(200);
        echo $envelopeOpen
            . '<s:Body><tds:GetDeviceInformationResponse>'
            . '<tds:Manufacturer>SesameMock</tds:Manufacturer>'
            . '<tds:Model>MOCK-100</tds:Model>'
            . '<tds:FirmwareVersion>5.7.3</tds:FirmwareVersion>'
            . '<tds:SerialNumber>SN-MOCK-0001</tds:SerialNumber>'
            . '<tds:HardwareId>HW-MOCK-0001</tds:HardwareId>'
            . '</tds:GetDeviceInformationResponse></s:Body>'
            . '</s:Envelope>';
        return;
    }

    if (stripos($raw, 'GetCapabilities') !== false) {
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        http_response_code(200);
        echo $envelopeOpen
            . '<s:Body><tds:GetCapabilitiesResponse><tds:Capabilities>'
            . '<tt:Device><tt:XAddr>http://' . htmlspecialchars($host, ENT_XML1) . '/onvif/device_service</tt:XAddr></tt:Device>'
            . '<tt:Media><tt:XAddr>http://' . htmlspecialchars($host, ENT_XML1) . '/onvif/media_service</tt:XAddr></tt:Media>'
            . '<tt:PTZ><tt:XAddr>http://' . htmlspecialchars($host, ENT_XML1) . '/onvif/ptz_service</tt:XAddr></tt:PTZ>'
            . '<tt:Events><tt:XAddr>http://' . htmlspecialchars($host, ENT_XML1) . '/onvif/event_service</tt:XAddr></tt:Events>'
            . '</tds:Capabilities></tds:GetCapabilitiesResponse></s:Body>'
            . '</s:Envelope>';
        return;
    }

    if (stripos($raw, 'GetProfiles') !== false) {
        // Reserved login `notoken` models a camera dialect that exposes no profile token at
        // all (no tt:Token element and no token attribute), so the probe must fall back
        // to the profile name.
        if (stripos($raw, '>notoken<') !== false) {
            http_response_code(200);
            echo $envelopeOpen
                . '<s:Body><tr:GetProfilesResponse><tr:Profiles>'
                . '<tr:Profiles><tt:Name>proname_ch0001</tt:Name>'
                . '<tt:VideoEncoderConfiguration><tt:Encoding>H264</tt:Encoding>'
                . '<tt:Resolution><tt:Width>2560</tt:Width><tt:Height>1440</tt:Height></tt:Resolution>'
                . '<tt:RateControl><tt:FrameRateLimit>10</tt:FrameRateLimit><tt:BitrateLimit>3072</tt:BitrateLimit></tt:RateControl>'
                . '</tt:VideoEncoderConfiguration></tr:Profiles>'
                . '</tr:Profiles></tr:GetProfilesResponse></s:Body>'
                . '</s:Envelope>';
            return;
        }
        http_response_code(200);
        echo $envelopeOpen
            . '<s:Body><tr:GetProfilesResponse><tr:Profiles token="any">'
            . '<tr:Profiles token="MainProfile">'
            . '<tt:Name>MainStream</tt:Name><tt:Token>MainProfile</tt:Token>'
            // Deliberately ordered before VideoEncoderConfiguration to prove scoping.
            . '<tt:Imaging><tt:VideoStabilization><tt:Resolution><tt:Width>640</tt:Width><tt:Height>480</tt:Height></tt:Resolution></tt:VideoStabilization></tt:Imaging>'
            . '<tt:VideoEncoderConfiguration>'
            . '<tt:Encoding>H264</tt:Encoding>'
            . '<tt:Resolution><tt:Width>1920</tt:Width><tt:Height>1080</tt:Height></tt:Resolution>'
            . '<tt:RateControl><tt:FrameRateLimit>25</tt:FrameRateLimit><tt:BitrateLimit>4096</tt:BitrateLimit></tt:RateControl>'
            . '</tt:VideoEncoderConfiguration>'
            . '</tr:Profiles>'
            . '<tr:Profiles token="SubProfile">'
            . '<tt:Name>SubStream</tt:Name><tt:Token>SubProfile</tt:Token>'
            . '<tt:VideoEncoderConfiguration>'
            . '<tt:Encoding>H265</tt:Encoding>'
            . '<tt:Resolution><tt:Width>704</tt:Width><tt:Height>576</tt:Height></tt:Resolution>'
            . '<tt:RateControl><tt:FrameRateLimit>15</tt:FrameRateLimit><tt:BitrateLimit>1024</tt:BitrateLimit></tt:RateControl>'
            . '</tt:VideoEncoderConfiguration>'
            . '</tr:Profiles>'
            // Audio-only profile: must be reported without video settings.
            . '<tr:Profiles token="AudioProfile">'
            . '<tt:Name>AudioOnly</tt:Name><tt:Token>AudioProfile</tt:Token>'
            . '</tr:Profiles>'
            . '</tr:Profiles></tr:GetProfilesResponse></s:Body>'
            . '</s:Envelope>';
        return;
    }

}

if ($token !== getenv('MGMT_IMPORT')) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    return;
}

$stateFile = getenv('FAKE_DVR_STATE') ?: (sys_get_temp_dir() . '/fake_dvr_router_state.json');
$state = ['streams' => [], 'onvif' => [], 'log' => []];
if (is_file($stateFile)) {
    $loaded = json_decode((string)file_get_contents($stateFile), true);
    if (is_array($loaded)) {
        $state = array_merge($state, $loaded);
    }
}

$persist = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};
$log = static function (string $entry) use (&$state, $persist): void {
    $state['log'][] = $entry;
    if (count($state['log']) > 200) {
        $state['log'] = array_slice($state['log'], -200);
    }
    $persist();
};
$body = '';
$payload = [];
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $body = (string)$raw;
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if ($path === '/api/streams') {
    $log($method . ' ' . $path);
    if ($method === 'GET') {
        $streams = array_values($state['streams']);
        $seed = [
            ['name' => 'already-portal', 'displayName' => 'ZZ Already in Portal', 'source' => 'rtsp://example.invalid/already', 'sourceType' => 'direct', 'enabled' => true, 'archiveEnabled' => true, 'retentionDays' => '1d'],
            ['name' => 'import-cam-1', 'displayName' => 'ZZ Imported Entrance', 'source' => 'rtsp://example.invalid/import-1', 'sourceType' => 'direct', 'enabled' => true, 'archiveEnabled' => true, 'retentionDays' => '14d', 'webrtcFastStart' => true, 'audioCodec' => 'aac'],
            ['name' => 'import-cam-2', 'displayName' => 'ZZ Imported Yard', 'source' => 'push://import-cam-2', 'sourceType' => 'push', 'enabled' => false, 'archiveEnabled' => false, 'retentionDays' => '3d', 'timelapseEnabled' => true, 'timelapseFramesPerHour' => 120, 'timelapseRetentionDays' => '30d', 'timelapsePlaybackFps' => 20],
            ['name' => 'legacy stream name', 'displayName' => 'Legacy invalid name', 'source' => 'rtsp://example.invalid/legacy', 'sourceType' => 'direct', 'enabled' => true, 'archiveEnabled' => true],
        ];
        $names = array_column($streams, 'name');
        foreach ($seed as $s) {
            if (!in_array($s['name'], $names, true)) {
                $streams[] = $s;
            }
        }
        echo json_encode(['streams' => $streams], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($method === 'POST') {
        $name = (string)($payload['name'] ?? '');
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'name_required']);
            return;
        }
        $state['streams'][$name] = $payload;
        $log('POST stream ' . $name);
        $persist();
        http_response_code(201);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

if (preg_match('#^/api/streams/([^/]+)$#', $path, $m)) {
    $name = urldecode($m[1]);
    $log($method . ' ' . $path);
    if ($method === 'GET') {
        if (isset($state['streams'][$name])) {
            echo json_encode($state['streams'][$name], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    if ($method === 'PUT') {
        $payload['name'] = $name;
        $state['streams'][$name] = $payload;
        $log('PUT stream ' . $name);
        $persist();
        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($method === 'DELETE') {
        if (isset($state['streams'][$name])) {
            unset($state['streams'][$name]);
            $log('DELETE stream ' . $name);
            $persist();
            http_response_code(204);
            echo '';
            return;
        }
        $log('DELETE stream ' . $name . ' (absent)');
        $persist();
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

if ($path === '/api/onvif/devices') {
    $log($method . ' ' . $path);
    if ($method === 'GET') {
        $devices = array_values($state['onvif']);
        echo json_encode(['devices' => $devices], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($method === 'POST') {
        $id = (string)($payload['id'] ?? '');
        if ($id === '') {
            http_response_code(422);
            echo json_encode(['error' => 'id_required']);
            return;
        }
        $state['onvif'][$id] = $payload;
        $log('POST onvif ' . $id);
        $persist();
        http_response_code(201);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

if (preg_match('#^/api/onvif/devices/([^/]+)$#', $path, $m)) {
    $id = urldecode($m[1]);
    $log($method . ' ' . $path);
    if ($method === 'GET') {
        if (isset($state['onvif'][$id])) {
            echo json_encode($state['onvif'][$id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    if ($method === 'PUT') {
        $payload['id'] = $id;
        $state['onvif'][$id] = $payload;
        $log('PUT onvif ' . $id);
        $persist();
        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($method === 'DELETE') {
        if (isset($state['onvif'][$id])) {
            unset($state['onvif'][$id]);
            $log('DELETE onvif ' . $id);
            $persist();
            http_response_code(204);
            echo '';
            return;
        }
        $log('DELETE onvif ' . $id . ' (absent)');
        $persist();
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        return;
    }
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

if (str_ends_with($path, '/motion_events.json')) {
    $parts = explode('/', trim($path, '/'));
    $stream = urldecode($parts[0]);
    $now = time();
    echo json_encode([
        'bucketSeconds' => 0,
        'devices' => [[
            'deviceId' => 'mock-device-' . $stream,
            'deviceName' => $stream,
            'sourceStreams' => [$stream],
        ]],
        'from' => $now - 86400,
        'to' => $now,
        'intervals' => [
            ['deviceId' => 'mock-device-' . $stream, 'from' => $now - 3600, 'to' => $now - 3600 + 15, 'duration' => 15, 'state' => 'motion'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

if (preg_match('#^/[^/]+/([0-9]+)-preview\.jpg$#', $path)) {
    header('Content-Type: image/jpeg');
    echo "SmokeJpeg";
    return;
}

http_response_code(404);
echo json_encode(['error' => 'not_found']);
