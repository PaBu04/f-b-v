<?php
declare(strict_types=1);

/* --------------------------------------------------------------------- */
/* Session                                                                 */
/* --------------------------------------------------------------------- */

/**
 * Verzeichnis, für das der Session-Cookie gilt – immer mit / geschrieben und
 * mit / abgeschlossen. dirname() liefert unter Windows sonst einen Backslash,
 * und ein Cookie-Pfad ohne abschließenden / gilt auch für Nachbarordner.
 */
function session_cookie_path(): string
{
    $path = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));

    if ($path === '' || $path === '.' || $path[0] !== '/') {
        $path = '/';
    }
    if (substr($path, -1) !== '/') {
        $path .= '/';
    }

    return $path;
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_name(SESSION_NAME);

    $params = [
        'lifetime' => 0,
        'path'     => session_cookie_path(),
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($params);
    } else {
        session_set_cookie_params(
            0,
            $params['path'] . '; samesite=Lax',
            '',
            $https,
            true
        );
    }

    session_start();

    // Leerlauf-Timeout
    $now = time();
    if (isset($_SESSION['last_seen']) && ($now - (int) $_SESSION['last_seen']) > SESSION_IDLE_TIMEOUT) {
        auth_logout();
        session_start();
        flash('info', 'Du wurdest wegen Inaktivität abgemeldet.');
    }
    $_SESSION['last_seen'] = $now;
}

/* --------------------------------------------------------------------- */
/* Mitglieder                                                                  */
/* --------------------------------------------------------------------- */

/**
 * @return array<int, array<string, mixed>>
 */
function users_all(): array
{
    $users = store_read('users');
    usort($users, static function (array $a, array $b) {
        return strcasecmp((string) $a['nickname'], (string) $b['nickname']);
    });

    return $users;
}

/**
 * Alle Mitglieder, indiziert nach ID – für Listen, die viele Mitglieder auflösen.
 *
 * @return array<string, array<string, mixed>>
 */
function users_by_id_map(): array
{
    $map = [];
    foreach (store_read('users') as $user) {
        $map[(string) ($user['id'] ?? '')] = $user;
    }

    return $map;
}

function user_by_id(?string $id): ?array
{
    if (!$id) {
        return null;
    }
    foreach (store_read('users') as $user) {
        if (($user['id'] ?? null) === $id) {
            return $user;
        }
    }

    return null;
}

function user_by_username(string $username): ?array
{
    $needle = mb_strtolower(trim($username));
    foreach (store_read('users') as $user) {
        if (mb_strtolower((string) $user['username']) === $needle) {
            return $user;
        }
    }

    return null;
}

function users_exist(): bool
{
    return store_read('users') !== [];
}

/**
 * Legt ein Mitglied an. Wirft bei doppeltem Benutzernamen.
 *
 * @return array<string, mixed>
 */
function user_create(string $username, string $nickname, string $birthdate, string $password, bool $isAdmin, bool $mustChangePassword = true): array
{
    $username = trim($username);
    $nickname = trim($nickname);

    return store_mutate('users', static function (array &$rows) use ($username, $nickname, $birthdate, $password, $isAdmin, $mustChangePassword) {
        foreach ($rows as $row) {
            if (mb_strtolower((string) $row['username']) === mb_strtolower($username)) {
                throw new RuntimeException('Der Benutzername ist bereits vergeben.');
            }
        }

        $user = [
            'id'                    => store_new_id(),
            'username'              => $username,
            'nickname'              => $nickname,
            'birthdate'             => $birthdate,
            'avatar'                => '',
            'notify'                => ['uploads' => true, 'likes' => true, 'birthdays' => true],
            'password_hash'         => password_hash($password, PASSWORD_DEFAULT),
            'is_admin'              => $isAdmin,
            'must_change_password'  => $mustChangePassword,
            'created_at'            => date('c'),
            'last_login_at'         => null,
        ];
        $rows[] = $user;

        return $user;
    });
}

function user_update(string $id, array $changes): void
{
    store_mutate('users', static function (array &$rows) use ($id, $changes) {
        foreach ($rows as $index => $row) {
            if (($row['id'] ?? null) === $id) {
                $rows[$index] = array_merge($row, $changes);

                return true;
            }
        }

        return false;
    });
}

function user_delete(string $id): void
{
    store_mutate('users', static function (array &$rows) use ($id) {
        foreach ($rows as $index => $row) {
            if (($row['id'] ?? null) === $id) {
                unset($rows[$index]);
                break;
            }
        }
        $rows = array_values($rows);

        return true;
    });
}

function admin_count(): int
{
    $count = 0;
    foreach (store_read('users') as $user) {
        if (!empty($user['is_admin'])) {
            $count++;
        }
    }

    return $count;
}

/* --------------------------------------------------------------------- */
/* An- und Abmelden                                                        */
/* --------------------------------------------------------------------- */

function current_user(): ?array
{
    static $cache = null;
    static $cachedId = null;

    $id = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null;
    if ($id === null) {
        return null;
    }
    if ($cache !== null && $cachedId === $id) {
        return $cache;
    }

    $user = user_by_id($id);
    if ($user === null) {
        // Mitglied wurde zwischenzeitlich gelöscht
        auth_logout();

        return null;
    }

    $cache    = $user;
    $cachedId = $id;

    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        $target = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $query  = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $_SESSION['redirect_after_login'] = $target . ($query !== '' ? '?' . $query : '');
        redirect('login.php');
    }

    // Erzwungener Passwortwechsel
    if (!empty($user['must_change_password']) && current_script() !== 'account.php' && current_script() !== 'logout.php') {
        flash('info', 'Bitte vergib zuerst ein eigenes Passwort.');
        redirect('account.php');
    }

    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (empty($user['is_admin'])) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }

    return $user;
}

function auth_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['last_seen'] = time();
    unset($_SESSION['csrf']);
    user_update((string) $user['id'], ['last_login_at' => date('c')]);
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

/* --------------------------------------------------------------------- */
/* Brute-Force-Schutz (pro IP)                                             */
/* --------------------------------------------------------------------- */

function login_locked_until(): ?int
{
    $ip  = client_ip();
    $now = time();
    foreach (store_read('throttle') as $row) {
        if (($row['ip'] ?? null) === $ip && (int) ($row['locked_until'] ?? 0) > $now) {
            return (int) $row['locked_until'];
        }
    }

    return null;
}

function login_note_failure(): void
{
    $ip  = client_ip();
    $now = time();

    store_mutate('throttle', static function (array &$rows) use ($ip, $now) {
        // Abgelaufene Einträge aufräumen
        $rows = array_values(array_filter($rows, static function (array $row) use ($now) {
            $recent = ($now - (int) ($row['first_attempt'] ?? 0)) < LOGIN_WINDOW;
            $locked = (int) ($row['locked_until'] ?? 0) > $now;

            return $recent || $locked;
        }));

        foreach ($rows as $index => $row) {
            if (($row['ip'] ?? null) === $ip) {
                $rows[$index]['attempts'] = (int) $row['attempts'] + 1;
                if ($rows[$index]['attempts'] >= LOGIN_MAX_ATTEMPTS) {
                    $rows[$index]['locked_until'] = $now + LOGIN_LOCK_SECONDS;
                    $rows[$index]['attempts']     = 0;
                    $rows[$index]['first_attempt'] = $now;
                }

                return true;
            }
        }

        $rows[] = [
            'ip'            => $ip,
            'attempts'      => 1,
            'first_attempt' => $now,
            'locked_until'  => 0,
        ];

        return true;
    });
}

function login_reset_failures(): void
{
    $ip = client_ip();
    store_mutate('throttle', static function (array &$rows) use ($ip) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($ip) {
            return ($row['ip'] ?? null) !== $ip;
        }));

        return true;
    });
}

/** Prüft Zugangsdaten. @return array{0: ?array, 1: ?string} [Mitglied, Fehlertext] */
function auth_attempt(string $username, string $password): array
{
    $lockedUntil = login_locked_until();
    if ($lockedUntil !== null) {
        $minutes = max(1, (int) ceil(($lockedUntil - time()) / 60));

        return [null, 'Zu viele Fehlversuche. Bitte in ' . $minutes . ' Minuten erneut versuchen.'];
    }

    $user = user_by_username($username);

    // Konstante Laufzeit, damit unbekannte Benutzernamen nicht erkennbar sind
    $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforsalt0000000000000000000000000000000000';

    if ($user === null || !password_verify($password, (string) $hash)) {
        login_note_failure();

        return [null, 'Benutzername oder Passwort ist falsch.'];
    }

    login_reset_failures();

    // Hash bei Bedarf modernisieren
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        user_update((string) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    }

    return [$user, null];
}

/** @return ?string Fehlermeldung oder null */
function password_problem(string $password, string $repeat): ?string
{
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Das Passwort muss mindestens ' . PASSWORD_MIN_LENGTH . ' Zeichen lang sein.';
    }
    if ($password !== $repeat) {
        return 'Die beiden Passwörter stimmen nicht überein.';
    }

    return null;
}
