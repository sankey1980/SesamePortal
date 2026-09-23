<?php

declare(strict_types=1);

namespace SesamePortal;

final class Auth
{
    private const REMEMBER_COOKIE = 'sesame_remember';
    private const REMEMBER_LIFETIME = 30 * 24 * 3600; // 30 days

    public static function start(): void
    {
        self::configureSessionCookie();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('sesame_portal');
            session_start();
        }

        if (empty($_SESSION['user_id']) && isset($_COOKIE[self::REMEMBER_COOKIE])) {
            $token = (string)$_COOKIE[self::REMEMBER_COOKIE];
            if ($token === '') {
                self::expireRememberCookie();
                return;
            }
            $match = self::findRememberToken($token);
            if ($match !== null) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$match['user_id'];
                $_SESSION['remember_token_id'] = (int)$match['id'];
                self::touchRememberToken((int)$match['id']);
                self::refreshRememberCookie($token);
                Audit::logForUser((int)$match['user_id'], 'auth.remember_restore', 'ip=' . Audit::clientIp() . ' device=' . Audit::cleanValue((string)$match['label']));
            } else {
                self::expireRememberCookie();
                Audit::logForUser(null, 'auth.remember_failed', 'ip=' . Audit::clientIp());
            }
        }
    }

    public static function user(): ?array
    {
        self::start();
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }

        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE id = ? AND blocked = 0');
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: null;
        if ($user) {
            TokenService::ensureUserTokens((int)$user['id']);
            $stmt->execute([$id]);
            $user = $stmt->fetch() ?: null;
        }
        return $user;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            Util::redirect('/login');
        }
        if (($user['role'] ?? '') !== 'admin' && (int)($user['must_change_password'] ?? 0) === 1) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if ($path !== '/onboarding' && $path !== '/logout') {
                Util::redirect('/onboarding');
            }
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        return $user;
    }

    public static function login(string $login, string $password, bool $rememberMe = false): bool
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM users WHERE login = ? AND blocked = 0');
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Audit::logForUser($user['id'] ?? null, 'auth.login_failed', 'login=' . Audit::cleanValue($login) . ' ip=' . Audit::clientIp());
            return false;
        }

        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        TokenService::ensureUserTokens((int)$user['id']);
        DB::pdo()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Util::now(), $user['id']]);
        Audit::logForUser((int)$user['id'], 'auth.login', 'login=' . Audit::cleanValue((string)$user['login']) . ' ip=' . Audit::clientIp());

        if ($rememberMe) {
            self::setRememberMeCookie((int)$user['id']);
        }

        return true;
    }

    public static function logout(): void
    {
        self::start();
        if (!empty($_SESSION['user_id'])) {
            Audit::logForUser((int)$_SESSION['user_id'], 'auth.logout', 'ip=' . Audit::clientIp());
            self::clearCurrentRememberToken();
        }
        $params = session_get_cookie_params();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (headers_sent() === false && isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', [
                'expires' => 1,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => self::isHttps(),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function setRememberMeCookie(int $userId): void
    {
        $token = Util::randomToken(32);
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $label = self::rememberDeviceLabel();
        $expires = gmdate('c', time() + self::REMEMBER_LIFETIME);

        DB::pdo()->prepare('INSERT INTO remember_me_tokens(user_id, token_hash, label, expires, last_seen_at, created_at) VALUES(?, ?, ?, ?, NULL, ?)')
            ->execute([$userId, $hash, $label, $expires, Util::now()]);
        $tokenId = (int)DB::lastInsertId('remember_me_tokens');

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['remember_token_id'] = $tokenId;
        }

        self::sendRememberCookie($token);
    }

    public static function clearAllRememberMeTokens(int $userId): void
    {
        DB::pdo()->prepare('DELETE FROM remember_me_tokens WHERE user_id = ?')->execute([$userId]);
        self::expireRememberCookie();
    }

    private static function configureSessionCookie(): void
    {
        session_set_cookie_params([
            'lifetime' => self::REMEMBER_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string)self::REMEMBER_LIFETIME);
    }

    private static function isHttps(): bool
    {
        $https = (string)($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
        return false;
    }

    private static function rememberDeviceLabel(): string
    {
        $ua = preg_replace('/\s+/', ' ', trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) ?: '';
        return substr($ua, 0, 160);
    }

    private static function sendRememberCookie(string $token): void
    {
        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + self::REMEMBER_LIFETIME,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function refreshRememberCookie(string $token): void
    {
        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + self::REMEMBER_LIFETIME,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function expireRememberCookie(): void
    {
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', [
                'expires' => 1,
                'path' => '/',
                'secure' => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    private static function findRememberToken(string $token): ?array
    {
        $nowTs = time();
        $rows = DB::pdo()->query('SELECT id, user_id, token_hash, label, expires FROM remember_me_tokens')->fetchAll();

        $match = null;
        foreach ($rows as $row) {
            if ((int)strtotime((string)$row['expires']) < $nowTs) {
                continue;
            }
            if (password_verify($token, (string)$row['token_hash'])) {
                $match = $row;
                break;
            }
        }

        $cutoff = gmdate('c', $nowTs);
        DB::pdo()->prepare('DELETE FROM remember_me_tokens WHERE expires < ?')->execute([$cutoff]);

        return $match;
    }

    private static function touchRememberToken(int $tokenId): void
    {
        DB::pdo()->prepare('UPDATE remember_me_tokens SET last_seen_at = ?, expires = ? WHERE id = ?')
            ->execute([Util::now(), gmdate('c', time() + self::REMEMBER_LIFETIME), $tokenId]);
    }

    private static function clearCurrentRememberToken(): void
    {
        $tokenId = (int)($_SESSION['remember_token_id'] ?? 0);
        if ($tokenId > 0) {
            DB::pdo()->prepare('DELETE FROM remember_me_tokens WHERE id = ?')->execute([$tokenId]);
        } else {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $token = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
            if ($userId > 0 && $token !== '') {
                $stmt = DB::pdo()->prepare('SELECT id, token_hash FROM remember_me_tokens WHERE user_id = ?');
                $stmt->execute([$userId]);
                foreach ($stmt->fetchAll() as $row) {
                    if (password_verify($token, (string)$row['token_hash'])) {
                        DB::pdo()->prepare('DELETE FROM remember_me_tokens WHERE id = ?')->execute([(int)$row['id']]);
                    }
                }
            }
        }
        self::expireRememberCookie();
    }
}
