#!/usr/bin/env bash
# PostToolUse+Edit|Write: phpcs изменённого .php-файла, только предупреждение.
# Exit 2 → отчёт phpcs уходит в Claude (PostToolUse не блокирует правку). Exit 0 → тихо.
# Тулинг недоступен (нет docker/образа) → молча exit 0, чтобы не шуметь.
set -euo pipefail

FILE=$(jq -r '.tool_input.file_path // empty')
[ -z "$FILE" ] && exit 0
case "$FILE" in *.php) ;; *) exit 0 ;; esac

PROJ="${CLAUDE_PROJECT_DIR:-$PWD}"
REL="${FILE#"$PROJ"/}"
# phpcs.xml покрывает только src/ и tests/ — остальное не проверяем.
case "$REL" in src/*|tests/*) ;; *) exit 0 ;; esac

command -v docker >/dev/null 2>&1 || exit 0
docker image inspect phperrorcatcher >/dev/null 2>&1 || exit 0
command -v make >/dev/null 2>&1 || exit 0

if OUT=$(cd "$PROJ" && make cs-file file="$REL" 2>&1); then
  exit 0
fi
echo "phpcs нашёл нарушения в $REL — поправь (make cs-fix) или объясни:" >&2
printf '%s\n' "$OUT" >&2
exit 2
