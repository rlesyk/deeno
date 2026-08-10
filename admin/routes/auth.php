<?php
declare(strict_types=1);

/**
 * Экраны до входа: вход, выход, восстановление пароля.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Вход / выход
// ----------------------------------------------------------------

if ($action === 'login' && $user !== null) {
    adminRedirect($adminBase);
}

// ----------------------------------------------------------------
// Восстановление пароля (доступно до входа)
// ----------------------------------------------------------------

if ($action === 'reset' && $user === null) {
    $resetMsg  = '';
    $resetErr  = '';
    $tokenOk   = false;

    $rUser = strtolower(trim((string)($_REQUEST['u'] ?? '')));
    $rExp  = (int)($_REQUEST['exp'] ?? 0);
    $rTok  = (string)($_REQUEST['t'] ?? '');

    // Ссылка из письма: проверяем токен, показываем форму нового пароля
    if ($rUser !== '' && $rTok !== '') {
        $target  = $users->find($rUser);
        $tokenOk = $target !== null
            && Security::verifyResetToken($rUser, $rExp, (string)($target['password'] ?? ''), $rTok);
        if (!$tokenOk) {
            $resetErr = t('Ссылка недействительна или устарела. Запросите новую.');
        }
    }

    if ($isPost && $security->verifyCsrf($_POST['csrf'] ?? null)) {
        // Запрос письма
        if (($_POST['do'] ?? '') === 'request') {
            $ip = $security->clientIp();
            if (!$security->allowPasswordReset($ip)) {
                $resetErr = t('Слишком много запросов. Подождите 15 минут.');
            } else {
                $security->registerPasswordReset($ip);
                $login  = strtolower(trim((string)($_POST['username'] ?? '')));
                $target = $users->find($login);
                if ($target !== null && !empty($target['email']) && !empty($target['active'])) {
                    $exp   = time() + Security::RESET_TTL;
                    $token = Security::resetToken($login, $exp, (string)($target['password'] ?? ''));
                    $link  = rtrim((string)($config['site_url'] ?? ''), '/') . $adminBase
                        . 'reset/?u=' . urlencode($login) . '&exp=' . $exp . '&t=' . $token;
                    Mailer::send($config, (string)$target['email'],
                        t('Восстановление пароля') . ' — ' . (string)($config['site_title'] ?? 'deeno'),
                        t('Чтобы задать новый пароль, откройте ссылку (действует 30 минут):') . "\n\n" . $link . "\n\n"
                        . t('Если вы не запрашивали смену пароля — просто игнорируйте это письмо.'));
                }
                // Нейтральный ответ: не раскрываем, существует ли аккаунт
                $resetMsg = t('Если такой пользователь существует и у него указан email — письмо отправлено. Не пришло? Проверьте спам или обратитесь к администратору.');
            }
        }

        // Установка нового пароля по токену
        if (($_POST['do'] ?? '') === 'set' && $tokenOk) {
            $p1 = (string)($_POST['password'] ?? '');
            $p2 = (string)($_POST['password2'] ?? '');
            if (($pe = UserManager::passwordError($p1)) !== null) {
                $resetErr = t($pe);
            } elseif ($p1 !== $p2) {
                $resetErr = t('Пароли не совпадают.');
            } else {
                $target = UserManager::withNewPassword((array)$users->find($rUser), $p1);
                $users->save($target);
                adminRedirect($adminBase . '?reset_done=1');
            }
        }
    }

    $csrfField = $security->csrfField();
    $siteTitle = (string)($config['site_title'] ?? 'deeno');
    $siteLogo  = (string)($config['logo'] ?? '');
    require dirname(__DIR__) . '/views/reset.php';
    exit;
}

// Форма входа рендерится на любом URL админки без редиректа:
// /admin/ — физическая папка, поэтому вход работает даже на хостинге,
// где не настроен fallback ЧПУ на index.php (nginx без try_files)
if ($user === null && $action !== 'logout') {
    $error   = '';
    $blocked = false;
    $ip      = $security->clientIp();

    if ($security->isBlocked($ip)) {
        $blocked = true;
    } elseif ($isPost && isset($_POST['username'], $_POST['password'])) {
        $username = (string)$_POST['username'];
        $password = (string)$_POST['password'];

        if (!$security->verifyCsrf($_POST['csrf'] ?? null)) {
            $error = t('Сессия устарела, попробуйте ещё раз.');
        } else {
            // Задержка при частых попытках по ЭТОМУ логину — против перебора
            // с меняющихся адресов, который лимит по IP не видит. Ждём до
            // проверки пароля и одинаково для существующих и несуществующих
            // логинов, иначе время ответа выдаёт, какие имена заведены.
            if (($delay = $security->loginDelay($username)) > 0) {
                sleep($delay);
            }
            $found = $users->verify($username, $password);
            if ($found !== null) {
                $security->loginUser($found);
                $security->clearFailures($ip, (string)$found['username']);
                $users->touchLastLogin((string)$found['username']);
                adminRedirect($adminBase);
            }
            $blocked = $security->registerFailure($ip, $username);
            $error   = t('Неверное имя пользователя или пароль.');
        }
    }

    if ($blocked) {
        http_response_code(429);
        header('Retry-After: ' . max(1, $security->blockRemaining($ip)));
        $error = sprintf(
            t('Слишком много попыток входа. Подождите %d мин.'),
            (int)ceil(max(1, $security->blockRemaining($ip)) / 60)
        );
    }

    $csrfField = $security->csrfField();
    $siteTitle = (string)($config['site_title'] ?? 'deeno');
    // Логотип сайта — тот же, что в шапке сайдбара (Настройки → «Логотип»)
    $siteLogo  = (string)($config['logo'] ?? '');
    $resetDone = isset($_GET['reset_done']);
    // Демо-режим: показываем на форме известные логин/пароль песочницы
    $demoMode  = isDemoMode();
    $demoLogin = (string)($config['demo_login'] ?? '');
    $demoPass  = (string)($config['demo_pass'] ?? '');
    require dirname(__DIR__) . '/views/login.php';
    exit;
}

if ($action === 'logout') {
    if ($isPost && $security->verifyCsrf($_POST['csrf'] ?? null)) {
        $security->logout();
    }
    // На /admin/ гостя встретит форма входа (без зависимости от ЧПУ)
    adminRedirect($adminBase);
}
