<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$username = '';

/*
|--------------------------------------------------------------------------
| Basic session-based login throttling
|--------------------------------------------------------------------------
| Five failed attempts are allowed per session within 15 minutes.
| This is a development safeguard, not production-grade rate limiting.
*/

$maxAttempts = 5;
$windowSeconds = 900;

if (!isset($_SESSION['hrms_login_attempts'])) {
    $_SESSION['hrms_login_attempts'] = [];
}

$now = time();

$_SESSION['hrms_login_attempts'] = array_values(
    array_filter(
        $_SESSION['hrms_login_attempts'],
        static fn($timestamp): bool =>
            is_int($timestamp)
            && $timestamp > ($now - $windowSeconds)
    )
);

$attempts = $_SESSION['hrms_login_attempts'];

$locked = count($attempts) >= $maxAttempts;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify the existing application's CSRF token.
    csrf_verify();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Check the current lockout state again on every POST.
    $attempts = $_SESSION['hrms_login_attempts'];
    $locked = count($attempts) >= $maxAttempts;

    if ($locked) {

        $error = 'Too many failed attempts. Please wait before trying again.';

    } elseif (
        $username === ''
        || strlen($username) > 100
        || $password === ''
        || strlen($password) > 4096
    ) {

        $error = 'Enter a valid username and password.';

    } else {

        try {

            $stmt = $conn->prepare(
                'SELECT
                    id,
                    username,
                    password_hash,
                    role,
                    employee_id,
                    is_active,
                    must_change_password
                 FROM users
                 WHERE username = ?
                 LIMIT 1'
            );

            $stmt->bind_param('s', $username);
            $stmt->execute();

            $user = $stmt->get_result()->fetch_assoc();

            $stmt->close();

        } catch (Throwable $exception) {

            error_log(
                'HRMS login database error: '
                . $exception->getMessage()
            );

            http_response_code(500);
            $error = 'Login is temporarily unavailable. Please try again later.';
            $user = null;
            $databaseError = true;
        }

        if (empty($databaseError)) {

            $authenticated = $user
                && (int) $user['is_active'] === 1
                && password_verify(
                    $password,
                    (string) $user['password_hash']
                );

            if ($authenticated) {

                try {

                    /*
                     * Regenerate the session ID after authentication.
                     */
                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException(
                            'Unable to regenerate session ID.'
                        );
                    }

                    // Rotate the CSRF token after login.
                    $_SESSION['hrms_csrf'] = bin2hex(
                        random_bytes(32)
                    );

                    $_SESSION['hrms_user'] = [
                        'id' => (int) $user['id'],
                        'username' => $user['username'],
                        'role' => $user['role'],
                        'employee_id' => $user['employee_id'] === null
                            ? null
                            : (int) $user['employee_id']
                    ];

                    $_SESSION['hrms_force_password_change'] =
                        (int) $user['must_change_password'] === 1;

                    // Clear the failed-attempt counter after login.
                    $_SESSION['hrms_login_attempts'] = [];

                    /*
                     * A last-login or audit failure must not expose
                     * database details to the browser.
                     */
                    try {

                        $update = $conn->prepare(
                            'UPDATE users
                             SET last_login = NOW()
                             WHERE id = ?'
                        );

                        $userId = (int) $user['id'];

                        $update->bind_param('i', $userId);
                        $update->execute();
                        $update->close();

                    } catch (Throwable $exception) {

                        error_log(
                            'HRMS last-login update error: '
                            . $exception->getMessage()
                        );
                    }

                    audit_event(
                        $conn,
                        'login',
                        'user',
                        (int) $user['id']
                    );

                    if ($_SESSION['hrms_force_password_change']) {

                        header('Location: change_password.php');

                    } elseif ($user['role'] === 'Employee') {

                        header('Location: employee_portal.php');

                    } else {

                        header('Location: index.php');
                    }

                    exit;

                } catch (Throwable $exception) {

                    error_log(
                        'HRMS session initialization error: '
                        . $exception->getMessage()
                    );

                    http_response_code(500);

                    $error = 'Unable to establish your session. Please try again.';
                }

            } else {

                // Do not reveal whether the username exists.
                $_SESSION['hrms_login_attempts'][] = time();

                $attemptCount = count(
                    $_SESSION['hrms_login_attempts']
                );

                if ($attemptCount >= $maxAttempts) {

                    $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';

                } else {

                    $remaining = $maxAttempts - $attemptCount;

                    $error = 'Invalid username or password, or the account is disabled.'
                        . ' Attempts remaining in this session: '
                        . $remaining . '.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Sign in | PeopleOS HRMS</title>

    <link rel="stylesheet" href="assets.css">

    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #111827, #312e81);
            padding: 22px;
        }

        .login {
            width: min(100%, 440px);
            box-sizing: border-box;
            background: #fff;
            padding: 32px;
            border-radius: 22px;
            box-shadow: 0 24px 80px #0003;
        }

        .login h1 {
            color: #172554;
            margin: 0 0 8px;
        }

        .login p {
            color: #64748b;
        }

        .login .field {
            margin: 18px 0;
        }

        .login input {
            width: 100%;
            box-sizing: border-box;
        }

        .login .btn {
            width: 100%;
        }

        .login .error-message {
            overflow-wrap: anywhere;
        }
    </style>
</head>

<body>

<section class="login">

    <h1>PeopleOS</h1>

    <p>Sign in to your HR workspace.</p>

    <?php if ($error !== ''): ?>

        <div
            class="alert error error-message"
            role="alert"
            aria-live="polite"
        >
            <?= h($error) ?>
        </div>

    <?php endif; ?>

    <form method="post" autocomplete="on">

        <?= csrf_field() ?>

        <div class="field">

            <label for="username">
                Username
            </label>

            <input
                id="username"
                name="username"
                type="text"
                maxlength="100"
                autocomplete="username"
                value="<?= h($username) ?>"
                required
                autofocus
                <?= $locked ? 'disabled' : '' ?>
            >

        </div>

        <div class="field">

            <label for="password">
                Password
            </label>

            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                maxlength="4096"
                required
                <?= $locked ? 'disabled' : '' ?>
            >

        </div>

        <button
            class="btn"
            type="submit"
            <?= $locked ? 'disabled' : '' ?>
        >
            Sign in securely
        </button>

    </form>

    <p class="muted" style="font-size:12px;margin-top:20px">
        Never reuse starter passwords in production.
    </p>

</section>

</body>
</html>