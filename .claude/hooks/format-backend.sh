#!/usr/bin/env bash
# Claude Code の PostToolUse フック。backend/ の PHP / Twig を編集したら、Docker コンテナ内のフォーマッターで整形する。
# コンテナが起動していないときは何もしない（編集自体を失敗させないため）。
set -euo pipefail

project_dir="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
file="$(jq -r '.tool_response.filePath // .tool_input.file_path // empty')"

case "$file" in
  "$project_dir"/backend/vendor/* | "$project_dir"/backend/var/*) exit 0 ;;
  "$project_dir"/backend/*.php | "$project_dir"/backend/*.twig) ;;
  *) exit 0 ;;
esac

compose=(docker compose -f "$project_dir/compose.yaml")
"${compose[@]}" ps --status running --services 2>/dev/null | grep -qx php || exit 0

rel="${file#"$project_dir"/backend/}"
case "$rel" in
  *.php) "${compose[@]}" exec -T php vendor/bin/php-cs-fixer fix --quiet --path-mode=intersection -- "$rel" ;;
  *.twig) "${compose[@]}" exec -T php vendor/bin/twig-cs-fixer fix --no-cache -- "$rel" >/dev/null ;;
esac
