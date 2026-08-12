<?php
declare(strict_types=1);

namespace Health;

/**
 * 비밀번호 하나짜리 단일 사용자 로그인.
 */
final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = App::storage('sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir)) {
            session_save_path($dir);
        }
        session_name((string) App::conf('session_name', 'healthsid'));
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path'     => App::url('/') ?: '/',
            'httponly' => true,
            'secure'   => (bool) App::conf('secure_cookie', true),
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['authed']);
    }

    /** 아이디 · 비밀번호가 맞으면 로그인시키고 true. */
    public static function attempt(string $username, string $password): bool
    {
        $hash = (string) App::conf('password_hash', '');
        if ($hash === '') {
            return false;
        }
        // 아이디가 틀려도 verify 를 한 번 돌려 응답 시간 차이를 줄인다.
        $okUser = hash_equals((string) App::conf('username', 'bs2'), $username);
        $okPass = password_verify($password, $hash);
        if (!$okUser || !$okPass) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['authed']   = true;
        $_SESSION['login_at'] = date('c');
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'httponly' => true,
                'secure'   => $p['secure'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    /** 로그인 안 됐으면 로그인 화면으로 보낸다. */
    public static function require(): void
    {
        if (self::check()) {
            return;
        }
        $want = $_SERVER['REQUEST_URI'] ?? '/';
        $_SESSION['after_login'] = Router::pathFromRequest(
            (string) $want,
            (string) App::conf('base_path', '')
        );
        Http::redirect('/login');
    }

    /** 비밀번호가 설정돼 있는가. 없으면 아무도 못 들어온다. */
    public static function configured(): bool
    {
        return (string) App::conf('password_hash', '') !== '';
    }
}
