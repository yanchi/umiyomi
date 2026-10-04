# CLAUDE.md

@AGENTS.md

## UMIYOMI での補足

AGENTS.md は Symfony 雛形の汎用ガイド。リポジトリ直下の [CLAUDE.md](../CLAUDE.md) と
[constitution](../.specify/memory/constitution.md) と食い違う場合は、そちらを優先する。特に：

- `src/Domain/` には Symfony / Doctrine の Attribute（`#[Assert\...]` など）を持ち込まない。
  入力の validation は Presentation 層の Input DTO で行い、Domain 側は Value Object で不正な状態を防ぐ
- アプリは `symfony serve` ではなく Docker（リポジトリ直下で `docker compose up -d`）で動かす。
  コマンドは `docker compose exec php bin/console ...` / `docker compose exec php composer ...`
- Doctrine は MVP では導入しない
