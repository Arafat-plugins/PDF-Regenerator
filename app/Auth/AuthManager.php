<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;

/**
 * Login / logout / access control (Part D §1).
 *
 * Validation order: lockout → credentials + status → regenerate session → redirect.
 * Failure messages never reveal which part was wrong.
 */
final class AuthManager
{
    public const MIN_PASSWORD = 10;

    public const MSG_INVALID = 'Invalid username or password.';
    public const MSG_EMPTY = 'Please enter your username and password.';

    /** A valid bcrypt hash used to equalise timing when the username does not exist. */
    private const DUMMY_HASH = '$2y$12$XAX.YKE33AqMCBdNq.ICae8O/YetMZLf/9kEDQPJQw1GOkaR7Xcl6';

    private ?User $cachedUser = null;

    public function __construct(private CsvUserRepository $users)
    {
    }

    public function users(): CsvUserRepository
    {
        return $this->users;
    }

    public function attempt(string $username, string $password, string $ip): LoginResult
    {
        $username = trim($username);

        if ($username === '' || $password === '') {
            return LoginResult::fail(LoginResult::EMPTY, self::MSG_EMPTY);
        }

        if (strlen($username) > 64 || strlen($password) > 4096) {
            return LoginResult::fail(LoginResult::INVALID, self::MSG_INVALID);
        }

        // 1. Lockout state for this username/IP.
        $remaining = SecurityManager::lockoutRemaining($username, $ip);
        if ($remaining > 0) {
            Logger::warning('auth', 'Login blocked by lockout', ['username_hash' => substr(hash('sha256', strtolower($username)), 0, 12), 'ip' => $ip]);

            return LoginResult::fail(LoginResult::LOCKED, self::lockoutMessage($remaining), $remaining);
        }

        // 2. Username, password and account status.
        $user = $this->users->findByUsername($username);
        $valid = $user !== null
            ? $this->users->verifyPassword($user, $password)
            : password_verify($password, self::DUMMY_HASH) && false;

        if ($user === null || !$valid || !$user->isActive()) {
            $lockedNow = SecurityManager::recordFailedLogin($username, $ip);
            Logger::warning('auth', 'Login failed', [
                'username_hash' => substr(hash('sha256', strtolower($username)), 0, 12),
                'ip' => $ip,
                'reason' => $user === null ? 'unknown_user' : (!$valid ? 'bad_password' : 'inactive'),
                'lockout' => $lockedNow,
            ]);

            if ($lockedNow) {
                $seconds = (int) Config::get('login_lockout_seconds', 900);

                return LoginResult::fail(LoginResult::LOCKED, self::lockoutMessage($seconds), $seconds);
            }

            return LoginResult::fail(LoginResult::INVALID, self::MSG_INVALID);
        }

        // Opportunistic re-hash when the algorithm/cost changed (a real write event, so allowed).
        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            try {
                $this->users->updatePassword($user->id, password_hash($password, PASSWORD_DEFAULT));
            } catch (\Throwable $e) {
                Logger::warning('auth', 'Password rehash skipped', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        // 3. Regenerate the session, store only the internal user id.
        SecurityManager::clearFailedLogins($username, $ip);
        SessionManager::login($user->id);
        $fresh = $this->users->getUser($user->id) ?? $user;
        SessionManager::set('pw_sig', self::passwordSignature($fresh->passwordHash));
        $this->cachedUser = $fresh;

        Logger::info('auth', 'Login successful', ['user_id' => $user->id, 'ip' => $ip]);

        return LoginResult::success($user);
    }

    public function logout(): void
    {
        $userId = SessionManager::userId();
        SessionManager::destroy();
        $this->cachedUser = null;

        if ($userId !== null) {
            Logger::info('auth', 'Logout', ['user_id' => $userId]);
        }
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?User
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        $id = SessionManager::userId();
        if ($id === null) {
            return null;
        }

        $user = $this->users->getUser($id);
        if ($user === null || !$user->isActive()) {
            // Account removed or deactivated while logged in.
            SessionManager::destroy();

            return null;
        }

        // Password changed in another session → this session is no longer valid.
        $sig = self::passwordSignature($user->passwordHash);
        $stored = SessionManager::get('pw_sig');
        if (is_string($stored) && !hash_equals($stored, $sig)) {
            Logger::info('auth', 'Session ended after a password change', ['user_id' => $user->id]);
            SessionManager::destroy();

            return null;
        }
        if ($stored === null && session_status() === PHP_SESSION_ACTIVE) {
            SessionManager::set('pw_sig', $sig);
        }

        return $this->cachedUser = $user;
    }

    /**
     * Guard for every private page / endpoint.
     */
    public function requireAuth(): User
    {
        $user = $this->user();
        if ($user !== null) {
            return $user;
        }

        $expired = SessionManager::wasExpired();

        if (Response::wantsJson()) {
            Response::json(false, $expired ? 'Your session has expired. Please log in again.' : 'Please log in to continue.', [], ['auth'], 401);
        }

        Response::redirect(url('login.php') . ($expired ? '?expired=1' : ''));
    }

    /** Short fingerprint of the stored hash (never the hash itself) kept in the session. */
    public static function passwordSignature(string $hash): string
    {
        return substr(hash('sha256', 'pw-sig|' . $hash), 0, 24);
    }

    /**
     * Change the logged-in user's password (Part B §2).
     *
     * @return array{ok: bool, message: string, errors: array<string, string>}
     *   errors keyed by current_password | new_password | confirm_password
     */
    public function changePassword(User $user, mixed $current, mixed $new, mixed $confirm, string $ip): array
    {
        $current = is_string($current) ? $current : '';
        $new = is_string($new) ? $new : '';
        $confirm = is_string($confirm) ? $confirm : '';
        $key = 'pwchange:' . $user->username;
        $fail = static fn (array $errors, string $message = 'Please check the highlighted fields.'): array => ['ok' => false, 'message' => $message, 'errors' => $errors];

        $remaining = SecurityManager::lockoutRemaining($key, $ip);
        if ($remaining > 0) {
            return $fail(['current_password' => 'Too many wrong attempts.'], 'Too many wrong current passwords. Please try again in ' . max(1, (int) ceil($remaining / 60)) . ' minute' . ($remaining > 60 ? 's' : '') . '.');
        }

        $errors = [];
        if ($current === '') {
            $errors['current_password'] = 'Please enter your current password.';
        }
        if ($new === '') {
            $errors['new_password'] = 'Please enter a new password.';
        } elseif (mb_strlen($new) < self::MIN_PASSWORD) {
            $errors['new_password'] = 'The new password must be at least ' . self::MIN_PASSWORD . ' characters long.';
        } elseif (strlen($new) > 4096) {
            $errors['new_password'] = 'The new password is too long.';
        } elseif (strcasecmp($new, $user->username) === 0) {
            $errors['new_password'] = 'The new password must not be your username.';
        }
        if ($confirm === '') {
            $errors['confirm_password'] = 'Please repeat the new password.';
        } elseif (!isset($errors['new_password']) && !hash_equals($new, $confirm)) {
            $errors['confirm_password'] = 'The two new passwords do not match.';
        }
        if ($errors !== []) {
            Logger::info('auth', 'Password change: form not valid', ['user_id' => $user->id, 'fields' => array_keys($errors)]);

            return $fail($errors);
        }

        $fresh = $this->users->getUser($user->id);
        if ($fresh === null || strlen($current) > 4096 || !$this->users->verifyPassword($fresh, $current)) {
            $locked = SecurityManager::recordFailedLogin($key, $ip);
            Logger::warning('auth', 'Password change: wrong current password', ['user_id' => $user->id, 'ip' => $ip, 'lockout' => $locked]);

            return $fail(['current_password' => 'Your current password is not correct.'], $locked
                ? 'Too many wrong current passwords. Please try again in ' . (int) ceil((int) Config::get('login_lockout_seconds', 900) / 60) . ' minutes.'
                : 'Your current password is not correct.');
        }
        $file = $this->users->file();
        if (!is_writable($file)) {
            Logger::error('auth', 'Password change failed: users.csv is not writable', ['user_id' => $user->id]);

            return $fail([], 'Your password could not be changed because the user file is read-only. Please contact the administrator.');
        }

        try {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            if (!$this->users->updatePassword($user->id, $hash)) {
                return $fail([], 'Your account could not be found. Please log in again.');
            }
        } catch (\Throwable $e) {
            Logger::error('auth', 'Password change failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return $fail([], 'Your password could not be changed. Please try again.');
        }

        SecurityManager::clearFailedLogins($key, $ip);
        SessionManager::regenerate();
        SessionManager::set('pw_sig', self::passwordSignature($hash));
        $this->cachedUser = $this->users->getUser($user->id);
        Logger::info('auth', 'Password changed', ['user_id' => $user->id, 'ip' => $ip]);

        return ['ok' => true, 'message' => 'Your password has been changed. Other devices have been signed out.', 'errors' => []];
    }

    public static function lockoutMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return sprintf('Too many failed attempts. Please try again in %d minute%s.', $minutes, $minutes === 1 ? '' : 's');
    }
}
