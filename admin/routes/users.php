<?php
declare(strict_types=1);

/**
 * Раздел «Пользователи»: список, создание, правка, удаление.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Пользователи (только Admin)
// ----------------------------------------------------------------

if ($action === 'users') {
    requireRole(['admin'], $user);

    // Создание / редактирование (POST + CSRF)
    if ($sub === 'save' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }

        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $existing = $users->find($username);
        $isNewUser = $existing === null;
        $password  = (string)($_POST['password'] ?? '');
        $role      = (string)($_POST['role'] ?? 'author');
        $active    = !empty($_POST['active']);
        $err       = '';

        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $username)) {
            $err = t('Логин: только латиница, цифры, дефис и подчёркивание.');
        } elseif (!in_array($role, UserManager::ROLES, true)) {
            $err = t('Неизвестная роль.');
        } elseif ($isNewUser && $password === '') {
            $err = t('Для нового пользователя нужен пароль.');
        } elseif ($password !== '' && ($pe = UserManager::passwordError($password)) !== null) {
            $err = t($pe);
        } elseif (!$isNewUser
            && ($existing['role'] ?? '') === 'admin' && !empty($existing['active'])
            && ($role !== 'admin' || !$active)
            && $users->activeAdmins() <= 1) {
            $err = t('Нельзя понизить или отключить последнего администратора.');
        } else {
            $record = $existing ?? ['username' => $username, 'created' => date('c')];
            $record['display_name'] = trim((string)($_POST['display_name'] ?? '')) ?: $username;
            $record['email']        = trim((string)($_POST['email'] ?? ''));
            $record['role']         = $role;
            $record['active']       = $active;
            if ($password !== '') {
                // Смена пароля админом завершает открытые сессии этого
                // пользователя — это и есть способ отобрать доступ немедленно
                $record = UserManager::withNewPassword($record, $password);
                if (strtolower($username) === strtolower((string)($user['username'] ?? ''))) {
                    $_SESSION['login_time'] = time();   // свою сессию не рвём
                }
            }
            if (!$users->save($record)) {
                $err = t('Не удалось записать файл пользователя (права на /users/?).');
            }
        }

        adminRedirect($adminBase . 'users/?' . ($err !== '' ? 'error=' . urlencode($err) : 'saved=1'));
    }

    // Удаление (POST + CSRF)
    if ($sub === 'delete' && $isPost) {
        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            csrfFail();
        }
        $name   = strtolower((string)($_POST['name'] ?? ''));
        $target = $users->find($name);
        $err    = '';
        if ($name === strtolower((string)($user['username'] ?? ''))) {
            $err = t('Нельзя удалить собственную учётную запись.');
        } elseif ($target !== null && ($target['role'] ?? '') === 'admin'
            && !empty($target['active']) && $users->activeAdmins() <= 1) {
            $err = t('Нельзя удалить последнего администратора.');
        } elseif ($target !== null) {
            $users->delete($name);
        }
        adminRedirect($adminBase . 'users/?' . ($err !== '' ? 'error=' . urlencode($err) : 'deleted=1'));
    }

    adminRender('users', $common + [
        'title'    => t('Пользователи'),
        'list'     => $users->all(),
        'saved'    => isset($_GET['saved']),
        'deleted'  => isset($_GET['deleted']),
        'usersErr' => (string)($_GET['error'] ?? ''),
        'selfName' => strtolower((string)($user['username'] ?? '')),
    ]);
    exit;
}
