<?php
declare(strict_types=1);

/**
 * Раздел «Обзор»: сводка, график посещений, джамп-бар.
 *
 * Подключается из admin/index.php и работает в его области видимости:
 * $config, $security, $cms, $users, $user, $action, $sub, $isPost, $common
 * и остальное объявлены там.
 */

defined('FFC_ADMIN') or exit;

// ----------------------------------------------------------------
// Dashboard
// ----------------------------------------------------------------

if ($action === 'dashboard') {
    $allPosts = $cms->posts(0, ['status' => 'all']);

    // Author видит на дашборде только своё
    if ($role === 'author') {
        $me = (string)($user['username'] ?? '');
        $allPosts = array_values(array_filter($allPosts, fn($p) => $p->author === $me));
    }

    $counters = [
        'published' => count(array_filter($allPosts, fn($p) => in_array($p->status, ['published', 'sticky'], true))),
        'drafts'    => count(array_filter($allPosts, fn($p) => $p->status === 'draft')),
        'pages'     => count($cms->pages()),
        'users'     => $users->count(),
    ];
    // «В работе» — черновики + отложенные (требуют внимания)
    $inWork = $counters['drafts']
        + count(array_filter($allPosts, fn($p) => $p->status === 'scheduled'));

    // Последние 5 изменённых материалов
    $recent = $allPosts;
    usort($recent, fn($a, $b) => strtotime($b->dateModifiedRaw ?: '0') <=> strtotime($a->dateModifiedRaw ?: '0'));
    $recent = array_slice($recent, 0, 5);

    // Чек-лист безопасности
    $checklist = [
        'HTTPS включён'            => $security->isHttps(),
        'Режим debug выключен'      => empty($config['debug']),
        'install.php удалён'        => !file_exists(ROOT_DIR . '/install.php'),
        'Кэш включён'               => !empty($config['cache_enabled']),
        // Без сохранённого ключа ссылки восстановления пароля и предпросмотра
        // перестают работать — молча, поэтому вынесено на видное место
        'Секретный ключ сохранён'   => Security::secretKeyOk(),
    ];

    // Статистика просмотров (раздел 10.1 ТЗ): текущий календарный месяц
    // (с 1-го числа по сегодня — не скользящее окно), топ-5, график по дням
    $statsDays  = (int)date('j'); // день месяца = сколько дней прошло с 1-го числа
    $monthNames = [
        1 => 'январь', 2 => 'февраль', 3 => 'март', 4 => 'апрель',
        5 => 'май', 6 => 'июнь', 7 => 'июль', 8 => 'август',
        9 => 'сентябрь', 10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь',
    ];
    $statsMonthLabel = t($monthNames[(int)date('n')]);

    $stats      = new StatsManager();
    $viewsTotal = $stats->totalViews($statsDays);
    $viewsDaily = $stats->dailyTotals($statsDays); // ['Y-m-d' => n] — даты нужны графику
    $topPages   = $stats->topPages($statsDays);
    // Уникальные считаются в пределах суток (суточная соль хэша), поэтому за
    // месяц корректно показывать их по дням, а не одним числом — см. StatsManager
    $uniqDaily  = $stats->dailyUniques($statsDays);

    // Просмотры за 7 дней + тренд к предыдущей неделе (для верхней статкарты)
    $views7     = $stats->totalViews(7);
    $viewsPrev7 = $stats->totalViews(14) - $views7;
    $viewsTrend = $viewsPrev7 > 0 ? (int)round(($views7 - $viewsPrev7) / $viewsPrev7 * 100) : null;

    // Системная сводка.
    //
    // Размер каталога считается рекурсивным обходом — на медиатеке в тысячи
    // файлов это секунды, а показывается оно на каждом открытии «Обзора».
    // Точность здесь никому не нужна, поэтому результат кэшируется на 15 минут:
    // цифра «занимает сайт» может отставать, зато дашборд открывается сразу.
    $dirSize = function (string $dir) use ($cache): int {
        if (!is_dir($dir)) return 0;

        $key    = 'dirsize-' . md5($dir);
        $bucket = (int)floor(time() / 900);          // подпись меняется раз в 15 минут
        $hit    = $cache->get($key, (string)$bucket);
        if (is_int($hit)) {
            return $hit;
        }

        $sum = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) $sum += $f->getSize();
        }
        $cache->set($key, (string)$bucket, $sum);
        return $sum;
    };
    $fmtSize = function (int $b): string {
        if ($b >= 1073741824) return number_format($b / 1073741824, 1, '.', ' ') . ' ' . t('ГБ');
        if ($b >= 1048576)    return number_format($b / 1048576, 1, '.', ' ') . ' ' . t('МБ');
        return number_format(max(0, $b) / 1024, 0, '.', ' ') . ' ' . t('КБ');
    };
    $lastBackup = (new BackupManager($config))->all()[0]['mtime'] ?? null;
    $backupDays = $lastBackup !== null ? (int)floor((time() - (int)$lastBackup) / 86400) : null;

    // «Свободно на диске» убрано (2026-07-20): disk_free_space() показывает
    // свободное место на РАЗДЕЛЕ сервера, а не квоту аккаунта. На shared-хостинге
    // это вводило в заблуждение — при лимите в 1 ГБ на дашборде значилось
    // 300+ ГБ. Квота живёт уровнем выше (cPanel) и из PHP не читается.
    // Полезнее показать, сколько занимает сама установка.
    // Дата последнего бэкапа здесь не нужна — она есть в статкарте наверху
    $sysinfo = [
        'deeno'                  => defined('DEENO_VERSION') ? DEENO_VERSION : '—',
        'PHP'                    => PHP_VERSION,
        t('Занимает сайт')       => $fmtSize($dirSize(ROOT_DIR)),
        t('Медиатека')           => $fmtSize($dirSize(ROOT_DIR . '/media')),
        t('Кэш')                 => $fmtSize($dirSize(ROOT_DIR . '/cache')),
        t('Бэкапы')              => $fmtSize($dirSize(ROOT_DIR . '/backups')),
    ];

    adminRender('dashboard', $common + [
        'title'      => t('Обзор'),
        'counters'   => $counters,
        'inWork'     => $inWork,
        'views7'     => $views7,
        'viewsTrend' => $viewsTrend,
        'backupDays' => $backupDays,
        'recent'     => $recent,
        'checklist'  => $checklist,
        'viewsTotal'      => $viewsTotal,
        'viewsDaily'      => $viewsDaily,
        'topPages'        => $topPages,
        'uniqDaily'       => $uniqDaily,
        'statsMonthLabel' => $statsMonthLabel,
        'sysinfo'    => $sysinfo,
    ]);
    exit;
}
