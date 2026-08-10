#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
# UL CMS — smoke-тесты (интеграция, HTTP).
# Разворачивает ИЗОЛИРОВАННУЮ копию во временной папке (реальные
# content/users/config/media НЕ трогаются), поднимает php -S, дёргает
# реальные URL и проверяет коды. Ловит роутинг/видимость статусов/
# режим обслуживания — то, что юнит-тесты не видят.
# Запуск: bash tests/smoke.sh   (код возврата 0 — всё ок, 1 — падения)
# ─────────────────────────────────────────────────────────────
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${SMOKE_PORT:-8123}"
BASE="http://127.0.0.1:$PORT"
TMP="$(mktemp -d)"
JAR="$TMP/cookies.txt"
SRV=""
PASS=0
FAIL=0

cleanup() {
    [ -n "$SRV" ] && kill "$SRV" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

# check "описание" ОЖИДАЕМЫЙ_КОД [доп. аргументы curl...]
check() {
    local desc="$1" exp="$2"; shift 2
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' "$@")"
    if [ "$code" = "$exp" ]; then
        PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m %s (%s)\n' "$desc" "$code"
    else
        FAIL=$((FAIL + 1)); printf '  \033[31m✗ %s — ожидал %s, получил %s\033[0m\n' "$desc" "$exp" "$code"
    fi
}

# ── 1. Изолированная копия кода ──
rsync -a \
    --exclude='.git' --exclude='node_modules' \
    --exclude='content/posts/*' --exclude='content/pages/*' \
    --exclude='media/*' --exclude='cache/*' --exclude='backups/*' \
    --exclude='users/*' --exclude='config.php' --exclude='config.json' \
    --exclude='system/secret.key' --exclude='system/security-data.php' \
    --exclude='system/logs/*' --exclude='system/categories.php' \
    --exclude='system/redirects.json' --exclude='_*' \
    "$ROOT/" "$TMP/" >/dev/null

mkdir -p "$TMP"/content/posts "$TMP"/content/pages "$TMP"/media \
         "$TMP"/cache "$TMP"/backups "$TMP"/users "$TMP"/system/logs
rm -f "$TMP/install.php"   # чтобы не редиректило в мастер установки

GUARD="<?php http_response_code(403); exit('UL CMS'); ?>"

# Версия данных: без неё Migrator посчитает конфиг «старым», выполнит миграции
# и перепишет config.php в pretty-print — sed-подстановки ниже перестанут
# совпадать. Smoke проверяет ТЕКУЩУЮ версию, сценарий обновления — в managers.php.
VER="$(php -r 'require "'"$ROOT"'/system/version.php"; echo DEENO_VERSION;')"

# ── 2. Конфиг ──
cat > "$TMP/config.php" <<EOF
$GUARD
{"site_title":"Smoke","site_url":"$BASE","theme":"default","language":"ru","timezone":"UTC","posts_per_page":10,"cache_enabled":false,"maintenance_mode":false,"sitemap_enabled":true,"current_build":"$VER","plugins":["rss","social-links"]}
EOF

# ── 3. Админ (пароль smoke12345) ──
HASH="$(php -r 'echo password_hash("smoke12345", PASSWORD_BCRYPT, ["cost" => 8]);')"
cat > "$TMP/users/admin.php" <<EOF
$GUARD
{"username":"admin","display_name":"Admin","email":"a@b.c","password":"$HASH","role":"admin","language":"ru","created":"2026-01-01T00:00:00+00:00","active":true}
EOF

# Автор (пароль тот же smoke12345) — для проверок разграничения прав
cat > "$TMP/users/petya.php" <<EOF
$GUARD
{"username":"petya","display_name":"Petya","email":"p@b.c","password":"$HASH","role":"author","language":"ru","created":"2026-01-01T00:00:00+00:00","active":true}
EOF

# ── 4. Тестовые посты ──
# Пост админа — «жертва» в проверках прав ниже. Отдельный файл, а не hello.md:
# тот участвует в тестах расстановки и меняется по ходу прогона.
cat > "$TMP/content/posts/owned-by-admin.md" <<'EOF'
---
title: Пост админа
slug: owned-by-admin
status: published
author: admin
date: 2026-01-01
---
Оригинальный текст. Автор не должен его перезаписать.
EOF
cat > "$TMP/content/posts/hello.md" <<'EOF'
---
title: Привет мир
slug: hello
status: published
category: novosti
date: 2026-01-01
---
Тело опубликованного поста.
EOF
cat > "$TMP/content/posts/archive-me.md" <<'EOF'
---
title: Кандидат в архив
slug: archive-me
status: published
category: novosti
date: 2026-01-02
---
Этот пост уедет в архив и должен исчезнуть с сайта.
EOF
cat > "$TMP/content/posts/draft.md" <<'EOF'
---
title: Черновик
slug: secret-draft
status: draft
date: 2026-01-01
---
Черновик не виден гостям.
EOF
cat > "$TMP/content/posts/future.md" <<'EOF'
---
title: Отложенный
slug: future-post
status: scheduled
scheduled_date: 2099-01-01T09:00
date: 2099-01-01
---
Ещё не время.
EOF
cat > "$TMP/content/posts/link.md" <<'EOF'
---
title: Вне списков
slug: unlisted-post
status: unlisted
date: 2026-01-01
---
Доступен по прямой ссылке.
EOF
cat > "$TMP/content/pages/about.md" <<'EOF'
---
title: О нас
slug: about
status: published
---
Про нас.
EOF
cat > "$TMP/content/pages/hidden.md" <<'EOF'
---
title: Скрытая
slug: hidden-page
status: draft
---
Черновик-страница.
EOF

# ── 5. Старт сервера ──
pushd "$TMP" >/dev/null
php -S "127.0.0.1:$PORT" index.php >"$TMP/server.log" 2>&1 &
SRV=$!
popd >/dev/null

# ждём готовности
for _ in $(seq 1 20); do
    curl -s -o /dev/null "$BASE/" && break
    sleep 0.3
done

echo "Публичные маршруты:"
check "главная → 200"            200 "$BASE/"
check "пост → 200"               200 "$BASE/novosti/hello/"
check "категория → 200"          200 "$BASE/novosti/"
check "несуществующее → 404"     404 "$BASE/net/takogo/"
check "rss.xml → 200"            200 "$BASE/rss.xml"
check "sitemap.xml → 200"        200 "$BASE/sitemap.xml"

echo "Видимость по статусу:"
check "черновик-пост скрыт → 404"    404 "$BASE/posts/secret-draft/"
check "отложенный (будущее) → 404"   404 "$BASE/posts/future-post/"
check "unlisted по ссылке → 200"     200 "$BASE/posts/unlisted-post/"
check "страница published → 200"     200 "$BASE/about/"
check "черновик-страница → 404"      404 "$BASE/hidden-page/"

echo "Авторизация и защита:"
CSRF="$(curl -s -c "$JAR" "$BASE/admin/" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$BASE/admin/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "username=admin" --data-urlencode "password=smoke12345"
check "админ вошёл: /admin/ → 200"          200 -b "$JAR" "$BASE/admin/"
check "CSRF: POST без токена → 403"          403 -b "$JAR" -X POST "$BASE/admin/settings/"

# Экран входа — на языке браузера: там ещё некому было выбрать язык, а настройка
# сайта к постороннему посетителю отношения не имеет
if curl -s -H 'Accept-Language: en-US,en;q=0.9' "$BASE/admin/" | grep -q '>Log in<'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m вход: английский браузер → английский экран\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ вход: английский браузер получил не английский экран\033[0m\n'
fi
if curl -s -H 'Accept-Language: ru-RU,ru;q=0.9' "$BASE/admin/" | grep -q '>Войти<'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m вход: русский браузер → русский экран\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ вход: русский браузер получил не русский экран\033[0m\n'
fi
if curl -s -H 'Accept-Language: de-DE,de;q=0.9' "$BASE/admin/" | grep -q '>Войти<'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m вход: незнакомый язык → язык сайта\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ вход: незнакомый язык не откатился на язык сайта\033[0m\n'
fi

# Личные настройки панели (тема и язык) переехали из сайдбара в Настройки.
# Раздел теперь открыт всем ролям, но не-админ видит только эту карточку.
SET_HTML="$(curl -s -b "$JAR" "$BASE/admin/settings/")"
if printf '%s' "$SET_HTML" | grep -q 'name="admin_theme"' && printf '%s' "$SET_HTML" | grep -q 'name="admin_lang"'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m настройки: карточка «Панель управления» на месте\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ настройки: нет полей темы/языка\033[0m\n'
fi
if printf '%s' "$(curl -s -b "$JAR" "$BASE/admin/")" | grep -q 'id="theme-toggle"'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ сайдбар: старый переключатель темы не убран\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m сайдбар: переключатели убраны\n'
fi
# Выбор темы и языка сохраняется В ПРОФИЛЬ, а не только в сессию.
# Сохранение настроек перезаписывает config целиком, поэтому бэкапим и возвращаем —
# иначе следующие проверки поедут на изменённом конфиге.
cp "$TMP/config.php" "$TMP/config.before-ui.php"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/settings/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "admin_theme=dark" --data-urlencode "admin_lang=en" \
    --data-urlencode "site_title=Smoke" --data-urlencode "posts_per_page=10"
if grep -qE '"admin_theme": *"dark"' "$TMP/users/admin.php" \
   && grep -qE '"language": *"en"' "$TMP/users/admin.php"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m тема и язык записались в профиль пользователя\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ тема/язык не сохранились в профиль\033[0m\n'
fi
mv "$TMP/config.before-ui.php" "$TMP/config.php"

echo "Расстановка (drag-and-drop):"
check "reorder без CSRF → 403"   403 -b "$JAR" -X POST "$BASE/admin/reorder/" \
    --data-urlencode 'data={"sections":[{"category":"tech","posts":["hello.md"]}]}'
check "reorder с CSRF → 200"     200 -b "$JAR" -X POST "$BASE/admin/reorder/" \
    --data-urlencode "csrf=$CSRF" \
    --data-urlencode 'data={"sections":[{"category":"tech","posts":["hello.md"]}]}'
if grep -q 'category: tech' "$TMP/content/posts/hello.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m reorder: пост перенесён в tech (файл обновлён)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ reorder: категория поста не изменилась в файле\033[0m\n'
fi
check "reorder-редирект старого URL → 301"   301 "$BASE/novosti/hello/"

# Номера пишутся только при ручном порядке: иначе дерево всё равно рендерится
# расчётом (алфавит/даты) и записанный position молча игнорировался бы.
# Перенос в другой раздел обязан работать при любом режиме.
# Заведомый номер: если правило сломается, reorder перепишет его на 0. Именно
# ЗАМЕНА существующего поля (его проставил предыдущий reorder), а не вставка
# нового — иначе в шапке оказались бы два position и парсер взял бы последний.
sed -i.bak 's/^position: .*/position: 7/' "$TMP/content/posts/hello.md"
POS_BEFORE="$(grep -E '^position:' "$TMP/content/posts/hello.md" | head -1)"
sed -i.bak 's/"posts_per_page":10/"posts_per_page":10,"article_order":"alpha"/' "$TMP/config.php"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/reorder/" \
    --data-urlencode "csrf=$CSRF" \
    --data-urlencode 'data={"sections":[{"category":"novosti","posts":["hello.md"]}]}'
POS_AFTER="$(grep -E '^position:' "$TMP/content/posts/hello.md" | head -1)"
if [ "$POS_BEFORE" = "position: 7" ] && [ "$POS_AFTER" = "position: 7" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m не ручной порядок: position в файле не переписан\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ не ручной порядок: position изменился (%s → %s)\033[0m\n' "$POS_BEFORE" "$POS_AFTER"
fi
if grep -q 'category: novosti' "$TMP/content/posts/hello.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m не ручной порядок: перенос между разделами всё равно работает\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ не ручной порядок: категория не изменилась\033[0m\n'
fi
# Тема должна получить признак режима: 0 — расставлять номера нельзя, дерево
# рендерится расчётом; 1 — ручной порядок, перетаскивание внутри раздела имеет смысл
sed -i.bak 's/"theme":"default"/"theme":"deeno-docs"/' "$TMP/config.php"
if curl -s -b "$JAR" "$BASE/" | grep -q 'data-reorder-manual="0"'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m тема deeno-docs получила data-reorder-manual="0"\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ тема не получила признак не-ручного порядка\033[0m\n'
fi
sed -i.bak 's/,"article_order":"alpha"/,"article_order":"manual"/' "$TMP/config.php"
if curl -s -b "$JAR" "$BASE/" | grep -q 'data-reorder-manual="1"'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m при ручном порядке признак меняется на "1"\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ при ручном порядке признак не стал "1"\033[0m\n'
fi
if curl -s "$BASE/" | grep -q 'data-reorder-manual'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ гостю отдаются данные для расстановки\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m гостю дерево отдаётся без данных расстановки\n'
fi
sed -i.bak 's/"theme":"deeno-docs"/"theme":"default"/; s/,"article_order":"manual"//' "$TMP/config.php"

echo "Content-Security-Policy:"
CSP_HDR="$(curl -s -D- -o /dev/null "$BASE/" | grep -i '^content-security-policy:')"
CSP_SCRIPT="$(printf '%s' "$CSP_HDR" | grep -oiE "script-src[^;]*")"
if printf '%s' "$CSP_HDR" | grep -q .; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m заголовок отдаётся публичной частью\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ CSP-заголовок не отдаётся\033[0m\n'
fi
# Главный смысл ужесточения: чужой скрипт не выполнится ни со стороннего
# домена, ни из тела страницы. Раньше политика разрешала и то, и другое.
if printf '%s' "$CSP_SCRIPT" | grep -q "unsafe-inline"; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ script-src разрешает unsafe-inline\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m script-src без unsafe-inline\n'
fi
if printf '%s' "$CSP_SCRIPT" | grep -qE "https:( |;|$)"; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ script-src разрешает любой https-домен\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m script-src не разрешает произвольные домены\n'
fi
# Отпечаток обязан быть в кавычках: без них браузер игнорирует токен и
# блокирует инлайн-скрипт темы (анти-мигание тёмной темы). Ошибки в консоли
# при этом нет — сайт просто перестаёт применять сохранённую тему.
#
# Проверяем на deeno-news: в теме default инлайн-скриптов нет, отпечатку
# взяться неоткуда и проверка была бы бессмысленно зелёной (или ложно красной).
sed -i.bak 's/"theme":"default"/"theme":"deeno-news"/' "$TMP/config.php"
CSP_INLINE="$(curl -s -D- -o /dev/null "$BASE/" | grep -i '^content-security-policy:' | grep -oiE "script-src[^;]*")"
if printf '%s' "$CSP_INLINE" | grep -qE "'sha256-[A-Za-z0-9+/]+={0,2}'"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m отпечаток инлайн-скрипта в кавычках (иначе браузер его игнорирует)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ отпечаток без кавычек или отсутствует: %s\033[0m\n' "$CSP_INLINE"
fi
sed -i.bak 's/"theme":"deeno-news"/"theme":"default"/' "$TMP/config.php"
# Видео вставляется движком как iframe на youtube-nocookie/vimeo — политика
# обязана их пропускать, иначе ролики в статьях перестанут проигрываться
if printf '%s' "$CSP_HDR" | grep -q "youtube-nocookie.com" && printf '%s' "$CSP_HDR" | grep -q "player.vimeo.com"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m frame-src пропускает видео YouTube/Vimeo\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ frame-src не пропускает видео — ролики сломаются\033[0m\n'
fi
# Домены из настройки должны попадать в политику, иначе счётчик молча не работает
sed -i.bak 's/"cache_enabled":false/"cache_enabled":false,"external_scripts":"mc.yandex.ru"/' "$TMP/config.php"
if curl -s -D- -o /dev/null "$BASE/" | grep -i '^content-security-policy:' | grep -q "https://mc.yandex.ru"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m домен счётчика из настроек попадает в политику\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ домен счётчика не попал в политику\033[0m\n'
fi
sed -i.bak 's/,"external_scripts":"mc.yandex.ru"//' "$TMP/config.php"
# У админки своя политика с nonce (её страницы не кэшируются). Фронтовый
# заголовок не должен её перекрывать — header() затёр бы админскую.
if curl -s -D- -o /dev/null -b "$JAR" "$BASE/admin/" | grep -i '^content-security-policy:' | grep -q "nonce-"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m админка сохраняет свой CSP с nonce\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ админка потеряла свой CSP с nonce\033[0m\n'
fi

echo "Архивация статей:"
# hello.md опубликован и виден на сайте — убираем его в архив кнопкой из списка
CSRF="$(curl -s -b "$JAR" "$BASE/admin/posts/" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/posts/archive/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=archive-me.md"

if grep -q 'status: archived' "$TMP/content/posts/archive-me.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m кнопка «в архив» сменила статус в файле\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ статус archived в файле не выставлен\033[0m\n'
fi
if grep -q 'должен исчезнуть с сайта' "$TMP/content/posts/archive-me.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m материал не удалён — тело на месте\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ архивация испортила тело материала\033[0m\n'
fi

check "архивный пост по прямой ссылке → 404"  404 "$BASE/novosti/archive-me/"
if curl -s "$BASE/" | grep -q 'Кандидат в архив'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ архивный пост остался в ленте на главной\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m архивного поста нет в ленте на главной\n'
fi
if curl -s "$BASE/sitemap.xml" | grep -q '/novosti/archive-me/'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ архивный пост остался в Sitemap\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m архивного поста нет в Sitemap\n'
fi
if curl -s "$BASE/rss.xml" | grep -q '/novosti/archive-me/'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ архивный пост остался в RSS\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m архивного поста нет в RSS\n'
fi

check "архивация без CSRF → 403"  403 -b "$JAR" -X POST "$BASE/admin/posts/archive/" \
    --data-urlencode "file=archive-me.md"

# Возврат из архива — всегда в черновик, а не обратно в публикацию
CSRF="$(curl -s -b "$JAR" "$BASE/admin/posts/" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/posts/archive/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=archive-me.md"
if grep -q 'status: draft' "$TMP/content/posts/archive-me.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m возврат из архива даёт черновик (не тихую публикацию)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ возврат из архива не дал черновик\033[0m\n'
fi
check "пост из архива публично недоступен (черновик) → 404"  404 "$BASE/novosti/archive-me/"

echo "История версий:"
# Первое сохранение существующего поста должно унести прежнюю версию в историю.
CSRF="$(curl -s -b "$JAR" "$BASE/admin/posts/edit/?file=hello.md" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/posts/save/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=hello.md" \
    --data-urlencode "title=Привет мир" --data-urlencode "slug=hello" \
    --data-urlencode "category=novosti" --data-urlencode "status=published" \
    --data-urlencode "date=2026-01-01" --data-urlencode "content=Текст после первой правки."

REV_ID="$(ls -1 "$TMP/content/revisions/posts/hello/" 2>/dev/null | head -1 | sed 's/\.md$//')"
if [ -n "$REV_ID" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m версия создана при сохранении (%s)\n' "$REV_ID"
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ версия при сохранении не создана\033[0m\n'
fi

if grep -q 'Тело опубликованного поста' "$TMP/content/revisions/posts/hello/$REV_ID.md" 2>/dev/null; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m в версии лежит ПРЕЖНИЙ текст\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ в версии не прежний текст\033[0m\n'
fi

check "просмотр версии → 200"             200 -b "$JAR" "$BASE/admin/posts/revision/?file=hello.md&rev=$REV_ID"
check "несуществующая версия → 404"       404 -b "$JAR" "$BASE/admin/posts/revision/?file=hello.md&rev=20990101-000000-000-x"
check "восстановление без CSRF → 403"     403 -b "$JAR" -X POST "$BASE/admin/posts/restore/" \
    --data-urlencode "file=hello.md" --data-urlencode "rev=$REV_ID"

CSRF="$(curl -s -b "$JAR" "$BASE/admin/posts/edit/?file=hello.md" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/posts/restore/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=hello.md" --data-urlencode "rev=$REV_ID"
if grep -q 'Тело опубликованного поста' "$TMP/content/posts/hello.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m восстановление вернуло прежний текст в файл\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ восстановление не изменило файл\033[0m\n'
fi
# Откат обратим: состояние до отката тоже должно уйти в историю
if [ "$(ls -1 "$TMP/content/revisions/posts/hello/" | wc -l | tr -d ' ')" = "2" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m состояние до отката сохранено (откат обратим)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ состояние до отката не сохранено\033[0m\n'
fi

# Удаление материала уносит и его историю с диска.
# Сначала правим черновик, чтобы история у него появилась.
CSRF="$(curl -s -b "$JAR" "$BASE/admin/posts/edit/?file=draft.md" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/posts/save/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=draft.md" \
    --data-urlencode "title=Черновик" --data-urlencode "slug=secret-draft" \
    --data-urlencode "status=draft" --data-urlencode "date=2026-01-01" \
    --data-urlencode "content=Черновик после правки."
if [ -d "$TMP/content/revisions/posts/draft" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m у черновика появилась история\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ история черновика не создана\033[0m\n'
fi

CSRF="$(curl -s -b "$JAR" "$BASE/admin/posts/" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR" -o /dev/null -X POST "$BASE/admin/posts/delete/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=draft.md"
if [ ! -d "$TMP/content/revisions/posts/draft" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m удаление материала унесло его историю с диска\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ история удалённого материала осталась на диске\033[0m\n'
fi
check "история удалённого материала недоступна" 403 -b "$JAR" "$BASE/admin/posts/revision/?file=draft.md&rev=$REV_ID"

echo "Разграничение прав (роль author):"
JAR2="$TMP/cookies-author.txt"
CSRF_A="$(curl -s -c "$JAR2" "$BASE/admin/" | grep -oE 'name="csrf" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')"
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$BASE/admin/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode "username=petya" --data-urlencode "password=smoke12345"
check "author вошёл: /admin/ → 200"   200 -b "$JAR2" "$BASE/admin/"

# Чужой пост нельзя перезаписать, подставив его имя файла в форму. До фикса
# маршрут сохранения проверял только CSRF: author затирал любой пост и
# становился его автором. Проверка на открытие редактора этого не ловила —
# форму можно не открывать, а отправить POST напрямую.
check "author: сохранение ЧУЖОГО поста → 403"   403 -b "$JAR2" -X POST "$BASE/admin/posts/save/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode "file=owned-by-admin.md" \
    --data-urlencode "title=ВЗЛОМАНО" --data-urlencode "content=подменено" --data-urlencode "status=published"
if grep -q 'title: Пост админа' "$TMP/content/posts/owned-by-admin.md" \
   && grep -q 'author: admin' "$TMP/content/posts/owned-by-admin.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m чужой пост не изменился (заголовок и автор на месте)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ чужой пост перезаписан автором\033[0m\n'
fi
check "author: удаление ЧУЖОГО поста → 403"     403 -b "$JAR2" -X POST "$BASE/admin/posts/delete/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode "file=owned-by-admin.md"
if [ -f "$TMP/content/posts/owned-by-admin.md" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m чужой пост не удалён\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ автор удалил чужой пост\033[0m\n'
fi

# Обратная сторона: собственная работа автора не должна пострадать от проверки
check "author: свой новый пост сохраняется → 302"  302 -b "$JAR2" -X POST "$BASE/admin/posts/save/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode "file=" \
    --data-urlencode "title=Пост автора" --data-urlencode "content=текст" --data-urlencode "status=draft"
if ls "$TMP"/content/posts/*post-avtora*.md >/dev/null 2>&1; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m свой пост создан (файл на диске)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ автор не смог создать свой пост\033[0m\n'
fi

# Разделы, закрытые для роли author по модели прав (раздел 10 ТЗ)
check "author: страницы закрыты → 403"       403 -b "$JAR2" "$BASE/admin/pages/"
check "author: категории закрыты → 403"      403 -b "$JAR2" "$BASE/admin/categories/"
check "author: пользователи закрыты → 403"   403 -b "$JAR2" "$BASE/admin/users/"
check "author: плагины закрыты → 403"        403 -b "$JAR2" "$BASE/admin/plugins/"
check "author: темы закрыты → 403"           403 -b "$JAR2" "$BASE/admin/themes/"
check "author: бэкапы закрыты → 403"         403 -b "$JAR2" "$BASE/admin/backups/"
check "author: расстановка закрыта → 403"    403 -b "$JAR2" -X POST "$BASE/admin/reorder/" \
    --data-urlencode "csrf=$CSRF_A" --data-urlencode 'data={"sections":[]}'
# Личные настройки панели открыты всем ролям — это не дыра, а осознанное решение
check "author: настройки панели доступны → 200"  200 -b "$JAR2" "$BASE/admin/settings/"

echo "Плагины v2 (настройки и маршруты):"
# Тестовый плагин: объявляет настройки в plugin.json и свой маршрут в plugin.php.
mkdir -p "$TMP/plugins/smoke-demo"
cat > "$TMP/plugins/smoke-demo/plugin.json" <<'EOF'
{
  "name": "Smoke demo",
  "version": "1.0",
  "settings": [
    { "key": "greeting", "label": "Приветствие", "type": "text", "default": "hi" },
    { "key": "loud", "label": "Громко", "type": "checkbox", "default": false }
  ]
}
EOF
cat > "$TMP/plugins/smoke-demo/plugin.php" <<'EOF'
<?php
declare(strict_types=1);
PluginManager::route('smoke-hello', function (): void {
    $g = (string)PluginManager::setting('smoke-demo', 'greeting');
    if (PluginManager::setting('smoke-demo', 'loud')) $g = strtoupper($g);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PLUGIN-ROUTE:' . $g;
});
EOF
# Включаем плагин в конфиге
sed -i.bak 's/"social-links"\]/"social-links","smoke-demo"]/' "$TMP/config.php"

# Кнопка настроек и модалка появляются только у плагина со схемой
PL_HTML="$(curl -s -b "$JAR" "$BASE/admin/plugins/")"
if printf '%s' "$PL_HTML" | grep -q 'js-plugin-settings' && printf '%s' "$PL_HTML" | grep -q 'id="plugin-settings-smoke-demo"'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m плагины: шестерёнка и модалка настроек отрисованы\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ плагины: нет кнопки настроек или модалки\033[0m\n'
fi

# Маршрут плагина работает и отдаёт значение по умолчанию
if curl -s "$BASE/smoke-hello/" | grep -q 'PLUGIN-ROUTE:hi'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m маршрут плагина отвечает (значение по умолчанию)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ маршрут плагина не отвечает\033[0m\n'
fi

# Сохранение настроек через форму админки
curl -s -o /dev/null -b "$JAR" -X POST "$BASE/admin/plugins/settings/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "name=smoke-demo" \
    --data-urlencode "greeting=privet" --data-urlencode "loud=1"
if grep -q '"greeting": *"privet"' "$TMP/system/plugin-data.php" 2>/dev/null; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m настройки плагина сохранены в system/plugin-data.php\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ настройки плагина не сохранились\033[0m\n'
fi
# Сохранённое значение реально применяется на маршруте (loud=1 → верхний регистр)
if curl -s "$BASE/smoke-hello/" | grep -q 'PLUGIN-ROUTE:PRIVET'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m плагин читает сохранённые настройки (checkbox применён)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ плагин не видит сохранённые настройки\033[0m\n'
fi
# Плагин не может перебить существующий адрес сайта: маршрут ядра сильнее.
# (Маршруты плагинов спрашиваются внутри handle404 — то есть только когда ядро
# ничего не нашло; /about/ — реальная страница из фикстур.)
if curl -s "$BASE/about/" | grep -q 'PLUGIN-ROUTE'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ плагин перебил существующую страницу\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m маршруты плагина не перебивают адреса ядра\n'
fi
sed -i.bak 's/,"smoke-demo"\]/]/' "$TMP/config.php"
rm -rf "$TMP/plugins/smoke-demo" "$TMP/system/plugin-data.php"

echo "RSS и соцсети как плагины:"
# Лента и ссылки на соцсети переехали в плагины rss и social-links.
# Ядро о них не знает: выключили плагин — функции нет вообще.
if curl -s "$BASE/rss.xml" | grep -q '<rss'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m плагин rss отдаёт валидную ленту\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ плагин rss не отдал ленту\033[0m\n'
fi
# Автодискавери-<link> добавляет плагин через site.head
if curl -s "$BASE/" | grep -q 'application/rss+xml'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m автодискавери RSS в <head> (от плагина)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ нет автодискавери RSS в <head>\033[0m\n'
fi
# Ссылки на соцсети: пока не заданы — тема ничего не рисует
if curl -s "$BASE/" | grep -q 'aria-label="telegram"'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ соцсети показаны, хотя адрес не задан\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m соцсети не показаны, пока адреса не заданы\n'
fi
# Задаём адрес через настройки плагина — ссылка появляется в подвале
curl -s -o /dev/null -b "$JAR" -X POST "$BASE/admin/plugins/settings/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "name=social-links" \
    --data-urlencode "telegram=https://t.me/deeno" --data-urlencode "vk=не-ссылка"
HOME_HTML="$(curl -s "$BASE/")"
if printf '%s' "$HOME_HTML" | grep -q 'https://t.me/deeno'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m заданная соцсеть появилась на сайте\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ заданная соцсеть не появилась\033[0m\n'
fi
# Мусор вместо URL в подвал не пускаем (в теме — только http(s))
if printf '%s' "$HOME_HTML" | grep -q 'не-ссылка'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ не-URL просочился в разметку\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m значение без http(s) отброшено\n'
fi
# Настройки RSS и соцсетей больше не живут в «Настройках сайта»
SET2="$(curl -s -b "$JAR" "$BASE/admin/settings/")"
if printf '%s' "$SET2" | grep -qE 'name="rss_enabled"|name="social\['; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ в Настройках остались поля RSS/соцсетей\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m Настройки сайта очищены от RSS и соцсетей\n'
fi
# Выключаем оба плагина — функции пропадают полностью
sed -i.bak 's/"plugins":\["rss","social-links"\]/"plugins":[]/' "$TMP/config.php"
rm -rf "$TMP/cache/pages"
check "без плагина rss.xml → 404"   404 "$BASE/rss.xml"
OFF_HTML="$(curl -s "$BASE/")"
if printf '%s' "$OFF_HTML" | grep -q 'application/rss+xml'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ автодискавери остался после выключения плагина\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m без плагина автодискавери исчез\n'
fi
if printf '%s' "$OFF_HTML" | grep -q 'https://t.me/deeno'; then
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ соцсети остались после выключения плагина\033[0m\n'
else
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m без плагина соцсети исчезли\n'
fi
sed -i.bak 's/"plugins":\[\]/"plugins":["rss","social-links"]/' "$TMP/config.php"
rm -rf "$TMP/cache/pages"

echo "Демо-режим:"
# Публичная песочница: изменяющие действия запрещены на СЕРВЕРЕ (не только в UI),
# создание/правка контента и личные настройки панели — разрешены.
sed -i.bak 's/"cache_enabled":false/"cache_enabled":false,"demo_mode":true/' "$TMP/config.php"

# Запрещённое: создание пользователя. Проверяем и редирект на ?demo=1, и что
# файл пользователя НЕ появился (доказывает серверный отказ, а не только UI).
DEMO_LOC="$(curl -s -o /dev/null -D- -b "$JAR" -X POST "$BASE/admin/users/save/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "username=hacker" \
    --data-urlencode "password=hacker12345" --data-urlencode "role=admin" | grep -i '^location:')"
if printf '%s' "$DEMO_LOC" | grep -q 'demo=1' && [ ! -f "$TMP/users/hacker.php" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m демо: создание пользователя заблокировано (редирект ?demo=1, файла нет)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ демо: пользователь создан или не было отказа\033[0m\n'
fi

# Запрещённое: удаление поста. Файл должен уцелеть.
curl -s -o /dev/null -b "$JAR" -X POST "$BASE/admin/posts/delete/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=owned-by-admin.md"
if [ -f "$TMP/content/posts/owned-by-admin.md" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m демо: удаление поста заблокировано (файл на месте)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ демо: пост удалён под демо-режимом\033[0m\n'
fi

# Запрещённое: архивация. Гость убрал бы чужой пост с публичной витрины.
curl -s -o /dev/null -b "$JAR" -X POST "$BASE/admin/posts/archive/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=owned-by-admin.md"
if grep -q 'status: published' "$TMP/content/posts/owned-by-admin.md"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m демо: архивация заблокирована (пост остался опубликованным)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ демо: пост убран в архив под демо-режимом\033[0m\n'
fi

# Разрешённое, но принудительно в draft: гость шлёт status=published, а файл
# ОБЯЗАН сохраниться черновиком — на публичный фронт контент гостя не попадает.
curl -s -o /dev/null -b "$JAR" -X POST "$BASE/admin/posts/save/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "file=" \
    --data-urlencode "title=Демо пост" --data-urlencode "content=текст" --data-urlencode "status=published"
DEMO_POST="$(ls "$TMP"/content/posts/*demo-post*.md 2>/dev/null | head -1)"
if [ -n "$DEMO_POST" ] && grep -qE '^status: draft' "$DEMO_POST"; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m демо: пост создаётся, но принудительно в draft (не публичен)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ демо: пост не создан или не ушёл в draft (status=%s)\033[0m\n' "$(grep -oE '^status: .*' "$DEMO_POST" 2>/dev/null)"
fi

# Запрещённое: загрузка медиа (AJAX-эндпоинт) — 403 JSON, не редирект.
UP_CODE="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST "$BASE/admin/media/upload/" \
    --data-urlencode "csrf=$CSRF")"
if [ "$UP_CODE" = "403" ]; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m демо: загрузка медиа заблокирована (403)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ демо: загрузка медиа не заблокирована (код %s)\033[0m\n' "$UP_CODE"
fi

# Запрещённое: правка категории (её название видно на фронте) → редирект ?demo=1.
CAT_LOC="$(curl -s -o /dev/null -D- -b "$JAR" -X POST "$BASE/admin/categories/save/" \
    --data-urlencode "csrf=$CSRF" --data-urlencode "slug=novosti" \
    --data-urlencode "title=ВЗЛОМ" | grep -i '^location:')"
if printf '%s' "$CAT_LOC" | grep -q 'demo=1'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m демо: правка категории заблокирована (редирект ?demo=1)\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ демо: правка категории не заблокирована\033[0m\n'
fi

# Настройки сайта скрыты у демо-админа (isSiteAdmin=false), личная карточка — на месте.
DEMO_SET="$(curl -s -b "$JAR" "$BASE/admin/settings/")"
if printf '%s' "$DEMO_SET" | grep -q 'name="admin_theme"' && ! printf '%s' "$DEMO_SET" | grep -q 'name="site_url"'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m демо: настройки сайта скрыты, карточка панели доступна\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ демо: настройки сайта не скрыты у демо-админа\033[0m\n'
fi

# Плашка «демо-режим» видна в шапке админки.
if printf '%s' "$(curl -s -b "$JAR" "$BASE/admin/")" | grep -q 'demo-banner'; then
    PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m демо: плашка в шапке админки\n'
else
    FAIL=$((FAIL + 1)); printf '  \033[31m✗ демо: плашки в шапке нет\033[0m\n'
fi
sed -i.bak 's/,"demo_mode":true//' "$TMP/config.php"

echo "Режим обслуживания:"
sed -i.bak 's/"maintenance_mode":false/"maintenance_mode":true/' "$TMP/config.php"
check "обслуживание: гость → 503"            503 "$BASE/"
check "обслуживание: админ проходит → 200"   200 -b "$JAR" "$BASE/"

echo ""
if [ "$FAIL" -eq 0 ]; then
    printf '\033[32mSmoke: все %d проверок прошли ✓\033[0m\n' "$PASS"
    exit 0
else
    printf '\033[31mSmoke: %d из %d упали ✗\033[0m\n' "$FAIL" "$((PASS + FAIL))"
    exit 1
fi
