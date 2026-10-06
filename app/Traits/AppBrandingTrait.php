<?php

declare(strict_types=1);

namespace SesamePortal;

/**
 * Бренд-ассеты портала: favicon, PWA-иконки, логотип и название приложения.
 *
 * Загруженные админом файлы лежат в Config::stateDir()/branding/ — вне каталога
 * релиза, поэтому переживают обновление Portal: апдейтер каждый раз собирает
 * новую release-директорию из тарбола и переключает симлинк, всё записанное в
 * public/ пропадало бы. Отдаются публичным маршрутом /branding/<slot>.<ext> по
 * whitelisted-имени слота (имя файла клиента в путь не попадает), а пока
 * кастомного файла нет — разметка ссылается на стоковый файл из public/assets,
 * как и до появления этой функции.
 */
trait AppBrandingTrait
{
    private const BRANDING_DEFAULT_NAME = 'Портал Артел МиК';

    /** Лимиты на загрузку: PNG — любых размеров картинка, SVG — текст. */
    private const BRANDING_MAX_PNG_BYTES = 524288;  // 512 КБ
    private const BRANDING_MAX_SVG_BYTES = 262144;  // 256 КБ

    /** Расширение => Content-Type; содержимое проверяется при загрузке (finfo). */
    private const BRANDING_TYPES = [
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
    ];

    private static ?string $brandingAppName = null;

    /**
     * Слоты бренд-ассетов: ключ => стоковый файл и целевой размер для
     * автоподгонки в браузере ('' — ресайз не нужен).
     */
    private static function brandingSlots(): array
    {
        return [
            'favicon' => ['stock' => 'favicon.svg', 'target' => ''],
            'apple-touch-icon' => ['stock' => 'apple-touch-icon.png', 'target' => '180x180'],
            'icon-192' => ['stock' => 'icon-192.png', 'target' => '192x192'],
            'icon-512' => ['stock' => 'icon-512.png', 'target' => '512x512'],
            'icon-512-maskable' => ['stock' => 'icon-512-maskable.png', 'target' => '512x512'],
            'logo' => ['stock' => 'logo-sesameportal-inverse.svg', 'target' => ''],
        ];
    }

    /**
     * Название приложения: настройка админа либо значение по умолчанию.
     * Кешируется на запрос — layout() вызывает его несколько раз.
     */
    private static function appName(): string
    {
        if (self::$brandingAppName !== null) {
            return self::$brandingAppName;
        }

        $name = trim((string)DB::setting('branding_app_name', ''));
        self::$brandingAppName = $name !== '' ? $name : self::BRANDING_DEFAULT_NAME;

        return self::$brandingAppName;
    }

    private static function brandingDir(): string
    {
        return Config::stateDir() . '/branding';
    }

    /**
     * Путь к загруженному файлу слота либо null, если используется сток.
     */
    private static function brandingCustomPath(string $slot): ?string
    {
        foreach (array_keys(self::BRANDING_TYPES) as $ext) {
            $file = self::brandingDir() . '/' . $slot . '.' . $ext;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    private static function brandingStockPath(string $slot): string
    {
        $slots = self::brandingSlots();

        return dirname(__DIR__, 2) . '/public/assets/' . $slots[$slot]['stock'];
    }

    /**
     * URL слота: кастомный файл — через /branding/ с версией по mtime,
     * иначе стоковый путь с ?v= из assetUrl().
     */
    private static function brandingUrl(string $slot): string
    {
        $custom = self::brandingCustomPath($slot);
        if ($custom !== null) {
            return '/branding/' . $slot . '.' . self::brandingExt($custom)
                . '?v=' . (string)filemtime($custom);
        }

        $slots = self::brandingSlots();

        return self::assetUrl('/assets/' . $slots[$slot]['stock']);
    }

    /**
     * Фактический Content-Type слота — для type= у <link rel=icon> и манифеста.
     */
    private static function brandingType(string $slot): string
    {
        $file = self::brandingCustomPath($slot) ?? self::brandingStockPath($slot);

        return self::BRANDING_TYPES[self::brandingExt($file)] ?? 'application/octet-stream';
    }

    private static function brandingExt(string $file): string
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION));
    }

    /**
     * Ответ на запрос вида /branding/<slot>[.<ext>].
     */
    private static function brandingAsset(string $suffix): void
    {
        if (str_contains($suffix, '/') || str_contains($suffix, '..')) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $slot = preg_replace('/\.(png|svg)$/i', '', $suffix) ?? '';
        $slots = self::brandingSlots();
        if (!isset($slots[$slot])) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $custom = self::brandingCustomPath($slot);
        $file = $custom ?? self::brandingStockPath($slot);
        if (!is_file($file)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $ext = self::brandingExt($file);
        // Суффикс обязан совпадать с реальным файлом: /branding/favicon.png
        // при загруженном SVG — это не тот ресурс, что ждал клиент.
        $requestedExt = strtolower((string)(pathinfo($suffix, PATHINFO_EXTENSION) ?: ''));
        if ($requestedExt !== '' && $requestedExt !== $ext) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $type = self::BRANDING_TYPES[$ext] ?? 'application/octet-stream';
        $size = (int)filesize($file);
        $mtime = (int)filemtime($file);
        $etag = '"' . sha1($mtime . ':' . $size) . '"';

        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        // SVG отдаётся публично; если в нём уцелел скрипт, браузер не должен
        // его выполнить даже при прямом переходе по ссылке.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        // Сессия шлёт Expires/Pragma из 1981-го — они конфликтуют с кешированием ассетов.
        header_remove('Expires');
        header_remove('Pragma');
        header('Cache-Control: public, max-age=3600');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        if (self::brandingNotModified($etag, $mtime)) {
            http_response_code(304);
            return;
        }

        header('Content-Length: ' . (string)$size);
        readfile($file);
    }

    private static function brandingNotModified(string $etag, int $mtime): bool
    {
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === $etag || $candidate === '*' || rtrim($candidate, 'W/') === $etag) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($ifModifiedSince === '') {
            return false;
        }
        $since = strtotime($ifModifiedSince);

        return $since !== false && $mtime <= $since;
    }

    /**
     * manifest.json собирается на лету: название и иконки меняются из настроек,
     * а статический файл в public/ перестал бы отражать их сразу после загрузки.
     */
    private static function manifestJsonResponse(): void
    {
        $name = self::appName();
        $icons = [];
        foreach (['icon-192', 'icon-512', 'icon-512-maskable'] as $slot) {
            $slots = self::brandingSlots();
            $icons[] = [
                'src' => self::brandingUrl($slot),
                'sizes' => $slots[$slot]['target'],
                'type' => self::brandingType($slot),
                'purpose' => $slot === 'icon-512-maskable' ? 'maskable' : 'any',
            ];
        }

        $manifest = [
            'name' => $name,
            'short_name' => $name,
            'description' => 'Портал видеонаблюдения',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#121212',
            'theme_color' => '#161616',
            'icons' => $icons,
        ];

        header('Content-Type: application/json; charset=utf-8');
        header_remove('Expires');
        header_remove('Pragma');
        header('Cache-Control: public, max-age=300');
        header('Content-Length: ' . (string)strlen(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
        echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Версия манифеста для ?v= в <link rel=manifest>: меняется при смене
     * названия или любого из загруженных слотов.
     */
    private static function manifestVersion(): string
    {
        $parts = [self::appName()];
        foreach (array_keys(self::brandingSlots()) as $slot) {
            $custom = self::brandingCustomPath($slot);
            $parts[] = $custom !== null ? $slot . ':' . (string)filemtime($custom) : '';
        }

        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    /**
     * Сохранение названия приложения. Пустое значение возвращает дефолт.
     *
     * @return array{0: string, 1: string} [сообщение, класс]
     */
    private static function brandingSaveName(): array
    {
        $name = trim((string)Util::post('branding_app_name'));
        if (mb_strlen($name) > 60) {
            return [self::t('settings.brandingNameTooLong', 'Название приложения — не длиннее 60 символов'), 'danger'];
        }

        DB::setSetting('branding_app_name', $name);
        self::$brandingAppName = null;

        return $name === ''
            ? [self::t('settings.brandingNameReset', 'Название сброшено на значение по умолчанию'), 'success']
            : [self::t('settings.brandingNameSaved', 'Название приложения сохранено'), 'success'];
    }

    /**
     * @return array{0: string, 1: string} [сообщение, класс]
     */
    private static function brandingSaveFile(): array
    {
        $slot = (string)Util::post('slot');
        if (!isset(self::brandingSlots()[$slot])) {
            return [self::t('settings.brandingUnknownSlot', 'Неизвестный слот иконки'), 'danger'];
        }

        $upload = $_FILES['branding_file'] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [self::t('settings.brandingNoFile', 'Файл не выбран'), 'danger'];
        }
        if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
            return [sprintf(self::t('settings.brandingUploadFailed', 'Не удалось принять файл (код ошибки %s)'), (int)$upload['error']), 'danger'];
        }

        $tmp = (string)$upload['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return [self::t('settings.brandingUploadFailed2', 'Файл не прошёл проверку загрузки'), 'danger'];
        }

        $size = (int)$upload['size'];
        if ($size < 1) {
            return [self::t('settings.brandingEmptyFile', 'Пустой файл'), 'danger'];
        }

        $kind = self::brandingDetectKind($tmp);
        if ($kind === null) {
            return [self::t('settings.brandingBadType', 'Нужен PNG или SVG'), 'danger'];
        }

        $maxBytes = $kind === 'svg' ? self::BRANDING_MAX_SVG_BYTES : self::BRANDING_MAX_PNG_BYTES;
        if ($size > $maxBytes) {
            return [
                sprintf(self::t('settings.brandingTooBig', 'Слишком большой файл: допустимо не более %s КБ'), (string)(int)($maxBytes / 1024)),
                'danger',
            ];
        }

        $dims = null;
        $content = (string)file_get_contents($tmp);
        if ($kind === 'svg') {
            // Опасные конструкции не «чиним» молча: файл отклоняется целиком,
            // админ видит причину, а не неожиданно изменённую иконку.
            if (self::brandingSvgIsDangerous($content)) {
                return [self::t('settings.brandingSvgUnsafe', 'SVG отклонён: в нём остались скрипты или внешние вставки'), 'danger'];
            }
            $content = self::brandingSanitizeSvg($content);
            if (trim($content) === '' || self::brandingSvgIsDangerous($content)) {
                return [self::t('settings.brandingSvgUnsafe', 'SVG отклонён: в нём остались скрипты или внешние вставки'), 'danger'];
            }
        } else {
            $dims = @getimagesize($tmp);
            if (!is_array($dims) || (int)$dims[0] < 1 || (int)$dims[1] < 1) {
                return [self::t('settings.brandingNotPng', 'Файл не читается как PNG'), 'danger'];
            }
            $dims = ['w' => (int)$dims[0], 'h' => (int)$dims[1]];
        }

        $dir = self::brandingDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [self::t('settings.brandingStorageFailed', 'Не удалось создать каталог для иконок'), 'danger'];
        }

        foreach (array_keys(self::BRANDING_TYPES) as $ext) {
            $old = $dir . '/' . $slot . '.' . $ext;
            if (is_file($old)) {
                @unlink($old);
            }
        }

        $dest = $dir . '/' . $slot . '.' . $kind;
        $written = $kind === 'svg'
            ? @file_put_contents($dest, $content)
            : @move_uploaded_file($tmp, $dest);
        if ($written === false || $written === 0) {
            return [self::t('settings.brandingStorageFailed2', 'Не удалось сохранить файл'), 'danger'];
        }
        @chmod($dest, 0644);

        $label = self::brandingSlotLabel($slot);
        $message = $label . ' — ' . self::t('settings.brandingUpdated', 'обновлено');
        if ($dims !== null) {
            $message .= ' (' . $dims['w'] . '×' . $dims['h'] . ')';
            $target = self::brandingSlots()[$slot]['target'];
            $targetDims = $target !== '' ? array_map('intval', explode('x', $target)) : null;
            // Меньше целевого — допустимо (без увеличения), больше — браузер
            // уменьшит при следующей отрисовке, если JS включён.
            if ($targetDims !== null && ($dims['w'] > $targetDims[0] || $dims['h'] > $targetDims[1])) {
                $message .= '. ' . sprintf(self::t('settings.brandingSizeWarning', 'Ожидался размер %s'), str_replace('x', '×', $target))
                    . '. ' . self::t('settings.brandingSizeHint', 'Браузер уменьшит его автоматически, если включён JavaScript.');
            }
        }

        return [$message, 'success'];
    }

    /**
     * Возврат слота к стоковому файлу.
     *
     * @return array{0: string, 1: string} [сообщение, класс]
     */
    private static function brandingReset(): array
    {
        $slot = (string)Util::post('slot');
        if (!isset(self::brandingSlots()[$slot])) {
            return [self::t('settings.brandingUnknownSlot', 'Неизвестный слот иконки'), 'danger'];
        }

        $removed = false;
        foreach (array_keys(self::BRANDING_TYPES) as $ext) {
            $file = self::brandingDir() . '/' . $slot . '.' . $ext;
            if (is_file($file)) {
                $removed = @unlink($file) || $removed;
            }
        }

        return $removed
            ? [self::brandingSlotLabel($slot) . ' — ' . self::t('settings.brandingRestored', 'возвращена стандартная иконка'), 'success']
            : [self::t('settings.brandingAlreadyStock', 'Этот слот уже использует стандартную иконку'), ''];
    }

    /**
     * 'png' | 'svg' | null — по содержимому, а не по расширению: имя файла
     * клиента в путь не попадает, а подделать его легко.
     */
    private static function brandingDetectKind(string $path): ?string
    {
        $head = (string)@file_get_contents($path, false, null, 0, 4096);
        if ($head === '') {
            return null;
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $path) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if (stripos($head, '<svg') !== false || $mime === 'image/svg+xml') {
            return 'svg';
        }

        return null;
    }

    /**
     * SVG без внешних подгрузок и следов исполнения кода. DOM/SimpleXML в проекте
     * нет, поэтому чистим регулярками, а затем контрольной проверкой; заголовки
     * при отдаче (nosniff + CSP) закрывают то, что могло проскочить.
     */
    private static function brandingSanitizeSvg(string $svg): string
    {
        $patterns = [
            '/<!DOCTYPE.*?\]\s*>/is',
            '/<!DOCTYPE[^>]*>/is',
            '/<!ENTITY[^>]*>/is',
            '/<\?xml-stylesheet.*?\?>/is',
            '/<script\b.*?<\/script\s*>/is',
            '/<foreignObject\b.*?<\/foreignObject\s*>/is',
            '/<(iframe|embed|object|handler|audio|video)\b.*?<\/\1\s*>/is',
            '/<(iframe|embed|object|handler)\b[^>]*>/is',
            '/<(animate|set|animateTransform)\b[^>]*>/is',
            '/\b(?:href|xlink:href|src)\s*=\s*(?:"\s*(?:javascript|data:text\/html)[^"]*"|\'\s*(?:javascript|data:text\/html)[^\']*\'|[^\s>]*(?:javascript|data:text\/html)[^\s>]*)/i',
            '/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '/javascript\s*:/i',
        ];
        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, '', $svg);
            if (is_string($replaced)) {
                $svg = $replaced;
            }
        }

        return $svg;
    }

    /**
     * Признаки того, что SVG делать в портале небезопасно: выполнение кода,
     * внешние подгрузки, внедрение сущностей (XXE).
     */
    private static function brandingSvgIsDangerous(string $svg): bool
    {
        return (bool)preg_match(
            '/<script\b|<foreignObject\b|<iframe\b|<!ENTITY|(?:^|[\s\/])on[a-z]+\s*=|javascript\s*:/i',
            $svg
        );
    }

    /**
     * Подпись слота для панели: нейтральные технические имена не переводим.
     */
    private static function brandingSlotLabel(string $slot): string
    {
        return match ($slot) {
            'logo' => self::t('branding.slot.logo', 'Логотип'),
            'favicon' => 'Favicon',
            'apple-touch-icon' => 'Apple Touch (180×180)',
            'icon-192' => 'PWA (192×192)',
            'icon-512' => 'PWA (512×512)',
            'icon-512-maskable' => 'PWA maskable (512×512)',
            default => $slot,
        };
    }

    /**
     * Что сейчас отдаёт слот: тип, вес и (для PNG) размер в пикселях.
     */
    private static function brandingSlotMeta(string $slot): string
    {
        $file = self::brandingCustomPath($slot) ?? self::brandingStockPath($slot);
        if (!is_file($file)) {
            return '';
        }

        $kind = self::brandingExt($file) === 'svg' ? 'SVG' : 'PNG';
        $meta = $kind . ', ' . (string)round((int)filesize($file) / 1024, 1) . ' КБ';
        if ($kind === 'PNG') {
            $dims = @getimagesize($file);
            if (is_array($dims)) {
                $meta .= ', ' . (int)$dims[0] . '×' . (int)$dims[1];
            }
        }

        return $meta;
    }
}

