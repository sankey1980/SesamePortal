<?php

declare(strict_types=1);

namespace SesamePortal;

final class OnvifProbe
{
    private const DEVICE_SERVICE_PATH = '/onvif/device_service';
    private const MEDIA_SERVICE_PATH = '/onvif/media_service';
    private const MAX_PROFILES = 12;
    private const REQUEST_TIMEOUT = 5;
    private const CONNECT_TIMEOUT = 4;

    private const NS_ENVELOPE = 'http://www.w3.org/2003/05/soap-envelope';
    private const NS_WSA = 'http://schemas.xmlsoap.org/ws/2004/08/addressing';
    private const NS_TDS = 'http://www.onvif.org/ver10/device/wsdl';
    private const NS_TR = 'http://www.onvif.org/ver10/media/wsdl';
    private const NS_TT = 'http://www.onvif.org/ver10/schema';
    private const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    public static function probe(string $host, int $port, string $username, string $password, int $timeout = self::REQUEST_TIMEOUT): array
    {
        $host = trim($host);
        $username = trim($username);
        $password = $password;

        if ($host === '') {
            return self::fail(I18n::t('cameras.onvifProbeNoHost', 'Не указан IP-адрес / хост'), 'validation');
        }
        if (!self::isValidHost($host)) {
            return self::fail(I18n::t('cameras.onvifProbeBadHost', 'Некорректный IP-адрес / хост'), 'validation');
        }
        if ($port < 1 || $port > 65535) {
            return self::fail(I18n::t('cameras.onvifProbeBadPort', 'Порт должен быть от 1 до 65535'), 'validation');
        }
        $timeout = max(1, min(30, $timeout));

        $url = 'http://' . $host . ':' . $port . self::DEVICE_SERVICE_PATH;
        $details = ['url' => $url];
        $warnings = [];

        // Step 1: GetSystemDateAndTime (no auth) — confirms an ONVIF device answers.
        $dateResp = self::httpPost($url, self::request('device', 'GetSystemDateAndTime', '<tds:GetSystemDateAndTime/>'), $timeout);
        if (!$dateResp['ok']) {
            return self::fail($dateResp['message'], 'date', $details + ['http' => $dateResp['http'] ?? null]);
        }
        if (!self::isSoapEnvelope($dateResp['body'])) {
            return self::fail(I18n::t('cameras.onvifProbeNotOnvif', 'По адресу отвечает не ONVIF-устройство'), 'date', $details + ['http' => $dateResp['http'] ?? null]);
        }
        if (self::faultOf($dateResp['body']) !== null) {
            return self::fail(I18n::t('cameras.onvifProbeFail', 'ONVIF: ошибка подключения') . ': ' . self::faultOf($dateResp['body']), 'date', $details);
        }
        $details['step'] = 'date';

        // Without credentials availability is all we can prove.
        if ($username === '') {
            $details['warnings'] = [I18n::t('cameras.onvifProbeNoCredentials', 'Укажите логин и пароль, чтобы получить модель, прошивку и видеопрофили камеры')];
            return self::ok($details);
        }

        $auth = self::securityHeader($username, $password);

        // Step 2: GetDeviceInformation — canonical manufacturer/model/firmware/serial.
        $infoResp = self::httpPost($url, self::request('device', 'GetDeviceInformation', '<tds:GetDeviceInformation/>', $auth), $timeout);
        if (!$infoResp['ok']) {
            return self::fail($infoResp['message'], 'info', $details + ['http' => $infoResp['http'] ?? null]);
        }
        $infoFault = self::faultOf($infoResp['body']);
        if ($infoFault !== null) {
            return self::authOrFail($infoResp['body'], 'info', $details);
        }
        $device = self::deviceInfo($infoResp['body']);
        if ($device !== []) {
            $details['device'] = $device;
        }
        $details['step'] = 'info';

        // Step 3: GetCapabilities — service list and media endpoint.
        $capsResp = self::httpPost($url, self::request('device', 'GetCapabilities', '<tds:GetCapabilities><tds:Category>All</tds:Category></tds:GetCapabilities>', $auth), $timeout);
        if (!$capsResp['ok']) {
            return self::fail($capsResp['message'], 'caps', $details + ['http' => $capsResp['http'] ?? null]);
        }
        $capsFault = self::faultOf($capsResp['body']);
        if ($capsFault !== null) {
            return self::authOrFail($capsResp['body'], 'caps', $details);
        }
        $details['services'] = self::capabilityServices($capsResp['body']);
        if ($device === []) {
            $fromCaps = self::deviceInfo($capsResp['body']);
            if ($fromCaps !== []) {
                $details['device'] = $fromCaps;
            }
        }
        $details['step'] = 'caps';

        // Step 4: GetProfiles — video encoder settings per profile.
        $mediaUrl = self::mediaUrl($host, $port);
        $details['media_url'] = $mediaUrl;
        $profilesResp = self::httpPost($mediaUrl, self::request('media', 'GetProfiles', '<tr:GetProfiles/>', $auth), $timeout);
        if (!$profilesResp['ok']) {
            $warnings[] = I18n::t('cameras.onvifProbeProfilesFail', 'Не удалось получить видеопрофили') . ': ' . $profilesResp['message'];
            return self::ok($details + ['warnings' => $warnings]);
        }
        $profilesFault = self::faultOf($profilesResp['body']);
        if ($profilesFault !== null) {
            $warnings[] = self::isAuthFaultResponse($profilesResp['body'])
                ? I18n::t('cameras.onvifProbeProfilesFail', 'Не удалось получить видеопрофили') . ': ' . I18n::t('cameras.onvifProbeAuthFail', 'ONVIF: неверный логин или пароль')
                : I18n::t('cameras.onvifProbeProfilesFail', 'Не удалось получить видеопрофили') . ': ' . $profilesFault;
            return self::ok($details + ['warnings' => $warnings]);
        }
        $profiles = self::parseProfiles($profilesResp['body']);
        $details['step'] = 'profiles';
        if ($profiles === []) {
            $warnings[] = I18n::t('cameras.onvifProbeNoProfiles', 'Камера не вернула видеопрофилей');
            return self::ok($details + ['warnings' => $warnings]);
        }
        if (count($profiles) > self::MAX_PROFILES) {
            $warnings[] = sprintf(I18n::t('cameras.onvifProbeProfilesTruncated', 'Показаны первые %d профилей из %d'), self::MAX_PROFILES, count($profiles));
            $profiles = array_slice($profiles, 0, self::MAX_PROFILES);
        }
        $details['profiles'] = $profiles;
        $details['step'] = 'profiles';
        if ($warnings !== []) {
            $details['warnings'] = $warnings;
        }

        return self::ok($details);
    }

    private static function ok(array $details): array
    {
        return ['ok' => true, 'message' => I18n::t('cameras.onvifProbeOk', 'ONVIF: подключение успешно'), 'details' => $details];
    }

    private static function fail(string $message, string $step, array $details = []): array
    {
        $details['step'] = $step;
        return ['ok' => false, 'message' => $message, 'details' => $details];
    }

    private static function authOrFail(string $body, string $step, array $details): array
    {
        $fault = self::faultOf($body) ?? 'SOAP Fault';
        if (self::isAuthFaultResponse($body)) {
            return self::fail(I18n::t('cameras.onvifProbeAuthFail', 'ONVIF: неверный логин или пароль'), 'auth-fail', $details + ['fault' => $fault]);
        }
        return self::fail(I18n::t('cameras.onvifProbeFail', 'ONVIF: ошибка подключения') . ': ' . $fault, $step, $details);
    }

    private static function isValidHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        // Hostname: letters, digits, dot, hyphen; each label <= 63 chars; total <= 253.
        if (strlen($host) > 253) {
            return false;
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (!preg_match('/^[A-Za-z0-9\-]+$/', $label)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Media service URL is always derived from the probed host:port.
     * A camera must not be able to redirect the Portal to an arbitrary address.
     */
    private static function mediaUrl(string $host, int $port): string
    {
        return 'http://' . $host . ':' . $port . self::MEDIA_SERVICE_PATH;
    }

    private static function request(string $service, string $action, string $bodyXml, string $security = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope xmlns:s="' . self::NS_ENVELOPE . '" xmlns:tds="' . self::NS_TDS . '" xmlns:tr="' . self::NS_TR . '" xmlns:tt="' . self::NS_TT . '" xmlns:wsa="' . self::NS_WSA . '">'
            . '<s:Header>'
            . '<wsa:Action>http://www.onvif.org/ver10/' . $service . '/wsdl/' . $action . '</wsa:Action>'
            . $security
            . '</s:Header>'
            . '<s:Body>' . $bodyXml . '</s:Body>'
            . '</s:Envelope>';
    }

    private static function securityHeader(string $username, string $password): string
    {
        $rawNonce = random_bytes(16);
        $nonce = base64_encode($rawNonce);
        $created = gmdate('Y-m-d\TH:i:s\Z');
        // PasswordDigest is SHA-1 over the *raw* nonce bytes, not their Base64 text.
        $digest = base64_encode(sha1($rawNonce . $created . $password, true));
        return '<wsse:Security xmlns:wsse="' . self::NS_WSSE . '" xmlns:wsu="' . self::NS_WSU . '" s:mustUnderstand="1">'
            . '<wsse:UsernameToken>'
            . '<wsse:Username>' . self::xmlEscape($username) . '</wsse:Username>'
            . '<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">' . self::xmlEscape($digest) . '</wsse:Password>'
            . '<wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">' . self::xmlEscape($nonce) . '</wsse:Nonce>'
            . '<wsu:Created>' . $created . '</wsu:Created>'
            . '</wsse:UsernameToken>'
            . '</wsse:Security>';
    }



    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function httpPost(string $url, string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'curl_init failed', 'http' => null];
        }
        self::applyCurl($ch, $timeout);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $response = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0) {
            return ['ok' => false, 'message' => self::curlErrorMessage($errno, $errmsg), 'http' => null];
        }
        if ($http < 200 || $http >= 300) {
            return ['ok' => false, 'message' => 'HTTP ' . $http, 'http' => $http];
        }
        if (!is_string($response) || $response === '') {
            return ['ok' => false, 'message' => I18n::t('cameras.onvifProbeEmpty', 'Пустой ответ от камеры'), 'http' => $http];
        }
        return ['ok' => true, 'message' => '', 'http' => $http, 'body' => $response];
    }


    private static function applyCurl(\CurlHandle $ch, int $timeout): void
    {
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/soap+xml; charset=utf-8',
                'Connection: close',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, self::CONNECT_TIMEOUT),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
    }

    private static function curlErrorMessage(int $errno, string $errmsg): string
    {
        $map = [
            CURLE_COULDNT_RESOLVE_HOST => I18n::t('cameras.onvifProbeResolve', 'Не удалось разрешить имя хоста'),
            CURLE_COULDNT_CONNECT => I18n::t('cameras.onvifProbeConnect', 'Не удалось подключиться к камере'),
            CURLE_OPERATION_TIMEDOUT => I18n::t('cameras.onvifProbeTimeout', 'Таймаут подключения к камере'),
            CURLE_RECV_ERROR => I18n::t('cameras.onvifProbeRecv', 'Ошибка получения ответа от камеры'),
            CURLE_SEND_ERROR => I18n::t('cameras.onvifProbeSend', 'Ошибка отправки запроса к камере'),
        ];
        if (isset($map[$errno])) {
            return $map[$errno];
        }
        return $errmsg !== '' ? $errmsg : 'curl error ' . $errno;
    }

    private static function isSoapEnvelope(string $body): bool
    {
        return stripos($body, ':Envelope') !== false && stripos($body, ':Body') !== false;
    }

    private static function faultOf(string $body): ?string
    {
        if (stripos($body, ':Fault') === false) {
            return null;
        }
        $text = self::firstTag($body, ['faultstring', 'Reason', 'Text', 'faultcode']);
        return $text !== '' ? $text : 'SOAP Fault';
    }

    /**
     * Cameras signal bad credentials either in the SOAP Subcode
     * (wsse:FailedAuthentication, ter:NotAuthorized) or only in the Reason text.
     */
    private static function isAuthFaultResponse(string $body): bool
    {
        $needles = ['notauthorized', 'not authorized', 'failedauthentication', 'unauthorized', 'access denied', 'ter:sender', 'wsse:failed', 'invalid username', 'invalid password', 'wrong password', 'incorrect password', 'user does not exist'];
        $haystack = mb_strtolower(self::faultSubcode($body) . ' ' . (string)self::faultOf($body));
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function faultSubcode(string $body): string
    {
        if (preg_match('/<(?:\w+:)?Subcode\b[^>]*>(.*?)<\/(?:\w+:)?Subcode>/is', $body, $m)) {
            return self::firstTag((string)$m[1], ['Value', 'faultcode']);
        }
        return '';
    }

    private static function deviceInfo(string $body): array
    {
        $info = [];
        foreach (['Manufacturer' => 'manufacturer', 'Model' => 'model', 'FirmwareVersion' => 'firmware', 'SerialNumber' => 'serial', 'HardwareId' => 'hardware'] as $tag => $key) {
            $value = self::firstTag($body, [$tag]);
            if ($value !== '') {
                $info[$key] = $value;
            }
        }
        return $info;
    }

    private static function capabilityServices(string $body): array
    {
        $services = [];
        foreach (['Device', 'Media', 'PTZ', 'Imaging', 'Events', 'Analytics', 'Extension'] as $name) {
            if (preg_match('/<(?:[a-zA-Z0-9]+:)?' . preg_quote($name, '/') . '\b[^>]*>\s*<(?:[a-zA-Z0-9]+:)?XAddr/i', $body)) {
                $services[] = $name;
            }
        }
        return $services;
    }

    /**
     * ONVIF nests <tr:Profiles>: an outer container wrapping one entry per profile.
     * Each entry is scoped to its own VideoEncoderConfiguration so that Width/Height
     * are not picked from Imaging or AudioByResolution sections.
     */
    private static function parseProfiles(string $body): array
    {
        $profiles = [];
        if (preg_match_all('/<(?:[a-zA-Z0-9]+:)?Profiles\b([^>]*)>(.*?)<\/(?:[a-zA-Z0-9]+:)?Profiles>/s', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $inner = (string)($match[2] ?? '');
                if (!preg_match('/<(?:[a-zA-Z0-9]+:)?Name\s*>/i', $inner)) {
                    // Outer container, not a profile entry.
                    continue;
                }
                $name = self::firstTag($inner, ['Name']);
                $token = self::firstTag($inner, ['Token']);
                if ($token === '' && preg_match('/<(?:[a-zA-Z0-9]+:)?Profiles\b[^>]*\btoken="([^"]*)"/i', (string)($match[1] ?? ''), $tm)) {
                    $token = self::decode(trim($tm[1]));
                }
                if ($token === '') {
                    // Some devices only carry the token inside the source configuration.
                    $token = self::firstTag(self::sectionOf($inner, 'VideoSourceConfiguration'), ['Token']);
                }
                if ($token === '') {
                    // Last resort: many devices accept the profile name as the ProfileToken.
                    $token = $name;
                }
                if ($name === '' && $token === '') {
                    continue;
                }
                $profile = ['name' => $name !== '' ? $name : $token, 'token' => $token];
                $self = self::videoEncoder($inner);
                if ($self !== []) {
                    $profile += $self;
                }
                $profiles[] = $profile;
            }
        }
        return $profiles;
    }

    private static function sectionOf(string $body, string $tag): string
    {
        $pattern = '/<(?:[a-zA-Z0-9]+:)?' . preg_quote($tag, '/') . '\b[^>]*>(.*?)<\/(?:[a-zA-Z0-9]+:)?' . preg_quote($tag, '/') . '>/s';
        if (preg_match($pattern, $body, $m)) {
            return (string)$m[1];
        }
        return '';
    }

    private static function videoEncoder(string $body): array
    {
        if (!preg_match('/<(?:[a-zA-Z0-9]+:)?VideoEncoderConfiguration\b[^>]*>(.*?)<\/(?:[a-zA-Z0-9]+:)?VideoEncoderConfiguration>/s', $body, $m)) {
            return [];
        }
        $config = (string)$m[1];
        $out = [];
        $encoding = self::firstTag($config, ['Encoding']);
        if ($encoding !== '') {
            $out['encoding'] = $encoding;
        }
        if (preg_match('/<(?:[a-zA-Z0-9]+:)?Resolution\b[^>]*>(.*?)<\/(?:[a-zA-Z0-9]+:)?Resolution>/s', $config, $rm)) {
            $width = self::firstTag((string)$rm[1], ['Width']);
            $height = self::firstTag((string)$rm[1], ['Height']);
            if ($width !== '') {
                $out['width'] = (int)$width;
            }
            if ($height !== '') {
                $out['height'] = (int)$height;
            }
        }
        $rate = self::firstTag($config, ['FrameRateLimit', 'FrameRate']);
        if ($rate !== '') {
            $out['fps'] = (int)$rate;
        }
        $bitrate = self::firstTag($config, ['BitrateLimit', 'Bitrate']);
        if ($bitrate !== '') {
            $out['bitrate'] = (int)$bitrate;
        }
        return $out;
    }

    private static function firstTag(string $body, array $tags): string
    {
        foreach ($tags as $tag) {
            $pattern = '/<(?:[a-zA-Z0-9]+:)?' . preg_quote($tag, '/') . '(?:\s[^>]*)?>([^<]*)<\/(?:[a-zA-Z0-9]+:)?' . preg_quote($tag, '/') . '>/i';
            if (preg_match($pattern, $body, $m)) {
                $value = trim(self::decode((string)$m[1]));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
