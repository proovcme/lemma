<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;

    public static function attempt(string $login, string $password): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('
            SELECT *
            FROM users
            WHERE (email = :login_email OR tab_number = :login_tab)
              AND is_active = 1
            LIMIT 1
        ');
        $stmt->execute([
            'login_email' => $login,
            'login_tab' => $login,
        ]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        $pdo->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$user['id']]);
        self::$user = null;

        return true;
    }

    /**
     * Establish a session for a user by id, without a password. Callers are
     * responsible for authorising this (e.g. demo mode + an allow-listed user).
     */
    public static function loginAs(int $userId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $pdo->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId]);
        self::$user = null;

        return true;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int) $id]);
        $user = $stmt->fetch() ?: null;
        if ($user === null) {
            self::logout();
        }
        self::$user = $user;

        return $user;
    }

    public static function require(): array
    {
        $user = self::user();
        if (!$user) {
            redirect('/login');
        }

        if ((int) $user['must_change_password'] === 1 && request_path() !== '/password/change' && request_path() !== '/logout') {
            redirect('/password/change');
        }

        return $user;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => (string) ($params['path'] ?? '/'),
                'domain' => (string) ($params['domain'] ?? ''),
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => (string) ($params['samesite'] ?? 'Lax'),
            ]);
        }
        session_destroy();
        self::$user = null;
    }
}
