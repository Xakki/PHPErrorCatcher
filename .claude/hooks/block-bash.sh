#!/usr/bin/env bash
# PreToolUse+Bash guard. Exit 2 → блок (stderr уходит в Claude), exit 0 → пропустить.
set -euo pipefail

CMD=$(jq -r '.tool_input.command // empty')
[ -z "$CMD" ] && exit 0

# --- Деструктивное: жёсткий блок ---
if printf '%s' "$CMD" | grep -Eq 'rm[[:space:]]+-[A-Za-z]*[rR][A-Za-z]*[fF]|rm[[:space:]]+-[A-Za-z]*[fF][A-Za-z]*[rR]'; then
  echo "Заблокировано: 'rm -rf' разрушительно. Удаляй конкретные пути явно или сделай это сам." >&2
  exit 2
fi
if printf '%s' "$CMD" | grep -Eq 'git[[:space:]]+push\b' \
   && printf '%s' "$CMD" | grep -Eq -- '(^|[[:space:]])(--force|-f)([[:space:]]|$)'; then
  echo "Заблокировано: force-push. Пушь без --force (--force-with-lease разрешён) или через PR/rebase." >&2
  exit 2
fi

# --- Обход make: всё QA/деплой гонится через docker внутри make ---
SEP='(^|[;&|][[:space:]]*)'
if printf '%s' "$CMD" | grep -Eq "${SEP}(phpunit|phpstan|phpcs|phpcbf)\b" \
   || printf '%s' "$CMD" | grep -Eq "${SEP}(\./)?vendor/bin/(phpunit|phpstan|phpcs|phpcbf)\b" \
   || printf '%s' "$CMD" | grep -Eq "${SEP}docker[[:space:]]+run\b" \
   || printf '%s' "$CMD" | grep -Eq "${SEP}composer[[:space:]]+(install|update|i|u)\b"; then
  echo "Заблокировано: гоняй через make (make test | phpstan | cs-check | cs-fix | composer-i). Нет таргета — добавь его." >&2
  exit 2
fi

exit 0
