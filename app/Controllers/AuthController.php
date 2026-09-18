<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Services\LoginThrottleService;

final class AuthController extends BaseController
{
    public function home(): void
    {
        view('auth/home', [
            'title' => 'Лемма',
        ], 'layouts/guest');
    }

    /**
     * Demo personas offered behind the «Демо доступ» button. key => label/email/hint.
     * Each email must be a seeded @example.local account.
     */
    public static function demoPersonas(): array
    {
        return [
            'engineer' => ['label' => 'Инженер', 'email' => 'ov.engineer1@example.local', 'hint' => 'Мои задачи, моё время'],
            'gip'      => ['label' => 'ГИП', 'email' => 'head.gip@example.local', 'hint' => 'Штурман, контроль проектов'],
            'head'     => ['label' => 'Начальник отдела', 'email' => 'head.ov@example.local', 'hint' => 'Команда отдела ОВ'],
            'director' => ['label' => 'Директор', 'email' => 'director@example.local', 'hint' => 'Сводная картина и отчёты'],
        ];
    }

    public function loginForm(): void
    {
        $next = $this->safeNext($_GET['next'] ?? '');
        $user = current_user();
        if ($user && !config('app.demo_mode')) {
            redirect($next !== '' ? $next : role_home_path($user));
        }

        view('auth/login', [
            'title' => 'Вход',
            'demoMode' => (bool) config('app.demo_mode'),
            'demoPersonas' => self::demoPersonas(),
            'next' => $next,
        ], 'layouts/guest');
    }

    public function demoLogin(): void
    {
        if (!config('app.demo_mode')) {
            redirect('/login');
        }

        $key = (string) ($_POST['persona'] ?? '');
        $personas = self::demoPersonas();
        if (!isset($personas[$key])) {
            flash('error', 'Неизвестная демо-роль.');
            redirect('/login');
        }

        $stmt = $this->db()->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$personas[$key]['email']]);
        $id = (int) $stmt->fetchColumn();

        if ($id <= 0 || !Auth::loginAs($id)) {
            flash('error', 'Демо-учётка недоступна.');
            redirect('/login');
        }
        $this->db()->prepare('UPDATE users SET must_change_password = 0 WHERE id = ?')->execute([$id]);

        redirect(role_home_path(require_auth()));
    }

    public function login(): void
    {
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $next = $this->safeNext($_POST['next'] ?? '');
        $retryPath = '/login' . ($next !== '' ? '?next=' . rawurlencode($next) : '');

        if ($login !== '' && LoginThrottleService::tooManyAttempts($login)) {
            flash('error', 'Слишком много неудачных попыток входа. Подождите 15 минут и попробуйте снова.');
            redirect($retryPath);
        }

        if ($login === '' || $password === '' || !Auth::attempt($login, $password)) {
            if ($login !== '') {
                LoginThrottleService::registerFailure($login);
            }
            flash('error', 'Неверный табельный номер, email или пароль.');
            redirect($retryPath);
        }

        LoginThrottleService::clear($login);
        $user = current_user();
        if (!$user) {
            redirect($retryPath);
        }
        if ((int) ($user['must_change_password'] ?? 0) === 1) {
            if ($next !== '') {
                $_SESSION['_after_password_path'] = $next;
            }
            redirect('/password/change');
        }
        redirect($next !== '' ? $next : role_home_path($user));
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/');
    }

    public function passwordForm(): void
    {
        require_auth();
        view('auth/password', ['title' => 'Смена пароля'], 'layouts/guest');
    }

    public function passwordChange(): void
    {
        $user = require_auth();
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');

        if (mb_strlen($password) < 8 || $password !== $confirm) {
            flash('error', 'Пароль должен быть не короче 8 символов и совпадать с подтверждением.');
            redirect('/password/change');
        }

        $stmt = $this->db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        flash('success', 'Пароль изменён.');
        $next = $this->safeNext($_SESSION['_after_password_path'] ?? '');
        unset($_SESSION['_after_password_path']);
        redirect($next !== '' ? $next : role_home_path($user));
    }

    private function safeNext(mixed $value): string
    {
        $next = trim((string) $value);
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '';
        }
        if (preg_match('/[\r\n]/', $next)) {
            return '';
        }
        $parts = parse_url($next);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return '';
        }
        return mb_substr($next, 0, 2000);
    }
}
