<?php
declare(strict_types=1);

/**
 * Раздел «Профиль»: смена пароля и почты.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Профиль (любая роль)
// ----------------------------------------------------------------

if ($action === 'profile') {
    $me         = $users->find((string)($user['username'] ?? ''));
    $profileErr = '';

    if ($isPost && $me !== null) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }

        // Любое изменение профиля подтверждается текущим паролем
        if (!password_verify((string)($_POST['current_password'] ?? ''), (string)($me['password'] ?? ''))) {
            $profileErr = t('Текущий пароль неверен.');
        } else {
            $p1 = (string)($_POST['password'] ?? '');
            $p2 = (string)($_POST['password2'] ?? '');
            if ($p1 !== '' && ($pe = UserManager::passwordError($p1)) !== null) {
                $profileErr = t($pe);
            } elseif ($p1 !== $p2 && $p1 !== '') {
                $profileErr = t('Пароли не совпадают.');
            } else {
                $me['display_name'] = trim((string)($_POST['display_name'] ?? '')) ?: $me['username'];
                $me['email']        = trim((string)($_POST['email'] ?? ''));
                if ($p1 !== '') {
                    $me = UserManager::withNewPassword($me, $p1);
                }
                if ($users->save($me)) {
                    $_SESSION['user']['display_name'] = $me['display_name'];
                    // Смена пароля завершает сессии, открытые до неё. Свою —
                    // не завершаем: пользователь только что подтвердил текущий
                    // пароль, выкидывать его на форму входа незачем. Сдвигаем
                    // отметку входа, чтобы она была свежее отметки смены.
                    if ($p1 !== '') {
                        $_SESSION['login_time'] = time();
                    }
                    adminRedirect($adminBase . 'profile/?saved=1');
                }
                $profileErr = t('Не удалось записать файл пользователя (права на /users/?).');
            }
        }
    }

    adminRender('profile', $common + [
        'title'      => t('Профиль'),
        'me'         => $me,
        'saved'      => isset($_GET['saved']),
        'profileErr' => $profileErr,
    ]);
    exit;
}
