<!--
Sync Impact Report
- Version change: (template) → 1.0.0
- Modified principles: なし（初版）
- Added principles:
  I. MVPスコープの厳守 / II. レイヤー境界と依存方向 / III. 外部海況APIの隔離とキャッシュ /
  IV. 判断材料の提示と断定の禁止 / V. 重点領域のテスト
- Added sections: 技術的制約 / 開発ワークフローと品質ゲート / Governance
- Removed sections: なし
- Templates:
  ✅ .specify/templates/plan-template.md（Constitution Check はこのファイルから動的に導出するため変更不要）
  ✅ .specify/templates/spec-template.md（必須セクションの追加なし、変更不要）
  ✅ .specify/templates/tasks-template.md（テストを任意とする注記を原則 V に合わせて修正）
  ✅ CLAUDE.md（本 constitution を参照済み）
- Follow-up TODOs: なし
-->

# UMIYOMI Constitution

UMIYOMI は、指定した緯度・経度の風・波・うねり予報を出航前に確認するサービスである。
本 constitution は [docs/SPEC.md](../../docs/SPEC.md) と [CLAUDE.md](../../CLAUDE.md) から
変更不可の原則を抜き出したもの。詳細な設計例は SPEC.md を参照する。

## Core Principles

### I. MVPスコープの厳守

- MVP は Symfony + Twig による Web サービスとして作る。完成条件は「ブラウザで緯度・経度を入力 →
  外部海況APIから取得 → 風・波・うねりの時間別表示 → お気に入り地点の保存」の 4 点のみ。
- MVP 完成まで、以下を spec / plan / tasks に含めてはならない（MUST NOT）：
  Flutter アプリ、Flutter 向け JSON API、オフライン閲覧（PWA 含む）、航路、出航計画、地図指定、
  Push 通知、共有・SNS、ユーザー登録などのアカウント機能、Business 機能、課金、独自 AI 予測。
- お気に入り地点はブラウザの localStorage に保存する。サーバー側にユーザー・お気に入りの
  テーブルを作ってはならない（MUST NOT）。
- 今必要でないテーブル、抽象化、設定項目を先回りして作らない（YAGNI）。

**理由**: 「明日この海域へ行くとき、風と波がどう変化するかを出航前に確認できる」体験を
最短で完成させるため。

### II. レイヤー境界と依存方向

- Backend は `Domain` / `Application` / `Infrastructure` / `Presentation` の 4 層で構成し、
  依存方向は Presentation → Application → Domain ← Infrastructure とする（MUST）。
- Domain は Symfony・Doctrine・Open-Meteo などの技術詳細に依存してはならない。Domain Object に
  Symfony / Doctrine の Attribute を持ち込まない（MUST NOT）。
- Controller の責務は「Request → Input 変換 → UseCase 呼び出し → ViewModel/DTO 変換 → 描画」に
  限る。Controller に Domain Logic を書かない（MUST NOT）。
- UseCase に HTTP の Request / Response や Twig を渡さない（MUST NOT）。
- Domain Entity / Doctrine Entity を Twig や JSON へ直接渡さず、Presentation 用の ViewModel/DTO に
  変換する（MUST）。
- Application は Doctrine Repository を直接使わず、Domain 側の Repository Interface を経由する（MUST）。
- Value Object は、validation・単位・Domain 上の意味・不正状態の防止・ロジックのいずれかを
  持つ場合に限って作る。値をラップするだけの Value Object を量産しない（SHOULD NOT）。

**理由**: MVP 後のアプリ化で、同じ UseCase の上に JSON API を足すだけで済むようにするため。

### III. 外部海況APIの隔離とキャッシュ

- 外部海況 API（初期は Open-Meteo Weather / Marine）はサーバー側からのみ呼び出す。ブラウザの
  JavaScript や将来のアプリから直接呼んではならない（MUST NOT）。
- 外部 API へのアクセスは Domain/Application 側に定義した Port（例：`MarineForecastProvider`）を
  通し、実装は Infrastructure 層に置く（MUST）。
- Open-Meteo 固有のレスポンス形式・パラメータ名を Infrastructure 層の外へ漏らしてはならない（MUST NOT）。
- 同一地点・同一時間帯の予報は Symfony Cache でキャッシュし、外部 API を毎回呼ばない（MUST）。

**理由**: 提供元の変更や障害、利用制限の影響を Infrastructure 層だけに閉じ込めるため。

### IV. 判断材料の提示と断定の禁止（NON-NEGOTIABLE）

- UMIYOMI は航海・出航の安全を保証しない。UI 文言、エラーメッセージ、コード中の文字列で
  「安全です」「出航できます」「問題ありません」のような断定をしてはならない（MUST NOT）。
- 代わりに、時系列の数値と変化の傾向を判断材料として示す
  （例：「12時以降、波高が上昇する予報です。09:00 0.8m / 12:00 1.4m / 15:00 2.1m」）。
- 予報を表示する画面には、予報の取得日時を「最終更新：YYYY/MM/DD HH:mm」の形で必ず表示する（MUST）。
- 必要に応じて、気象庁などの公式な警報・注意報も確認するよう案内する（SHOULD）。
- 海況の評価・判定・警告に関わるロジックの追加や変更は、人間のレビューを必須とする（MUST）。

**理由**: 利用者の命に関わる判断を、予報データ以上の確かさで後押ししないため。

### V. 重点領域のテスト

- 以下の領域には自動テストを必須とする（MUST）：Domain Logic、Value Object、UseCase、
  ViewModel/DTO 変換、Open-Meteo レスポンス変換、Cache、時刻処理（タイムゾーン含む）、座標 validation。
- Functional Test で HTTP → Controller → UseCase → Provider/Repository → 描画結果の主要経路を確認する（MUST）。
- 通常のテスト実行では実際の外部 API を呼ばず、Fake Provider を使う。実 API を呼ぶテストは
  分離し、明示的に実行したときだけ動くようにする（MUST）。
- 上記以外を、網羅のためだけに機械的にテストする必要はない。

**理由**: 予報値の変換ミスや時刻ずれは画面上で気づきにくく、そのまま利用者の判断を誤らせるため。

## 技術的制約

- Backend：PHP、Symfony（開発開始時点の安定版）、Twig、Symfony Cache（本番は必要に応じて Redis）。
- Database：PostgreSQL + Doctrine を第一候補とするが、MVP ではサーバー側に永続化対象がないため、
  必要になるまで導入しない。
- PHP は `declare(strict_types=1);`、PSR-12、PHPStan、PHP-CS-Fixer、Symfony Best Practices に従う。
- コード・識別子は英語、コメントは原則日本語とする。コメントには「なぜその設計・実装にしたか」を書き、
  コードを読めば分かることは書かない。
- Web 画面は、出航前にスマホで見ることを前提に、スマホ幅でも崩れないようにする。

## 開発ワークフローと品質ゲート

- 基本フロー：Spec → Plan → Implementation → Static Analysis → Test → Human Review。
  機能単位では Spec Kit（`/speckit.specify` → `/speckit.plan` → `/speckit.tasks` → `/speckit.implement`）で進める。
- 以下の変更は、実装前に plan を提示し、人間の承認を得てから着手する（MUST）：
  Domain 設計、Repository 境界、画面・URL 設計（将来は API Contract）、DB Schema、外部 API Provider、
  課金、Security、海況判断ロジック。
- 上記以外の低リスク変更は、実装 → 静的解析（PHPStan）→ テストが通れば、自律的に次へ進めてよい。
- 静的解析とテストが失敗している状態で、完了扱いにしてはならない（MUST NOT）。

## Governance

- 本 constitution は、他の開発慣行や plan / tasks の記述より優先する。plan の Constitution Check で
  違反がある場合は、Complexity Tracking に理由と、より単純な代替案を採らなかった理由を書く。
- 改定は、変更内容と理由を示したうえで人間の承認を得て行う。改定時は SPEC.md・CLAUDE.md・
  `.specify/templates/` との整合を確認する。
- バージョンはセマンティックバージョニングに従う。原則の削除・再定義は MAJOR、原則・節の追加や
  大幅な拡張は MINOR、文言の明確化は PATCH とする。
- MVP 完成時に原則 I を見直し、アプリ化（Flutter、JSON API、オフライン閲覧）に向けて改定する。
- 日々の開発の具体的な指示は [CLAUDE.md](../../CLAUDE.md) を参照する。

**Version**: 1.0.0 | **Ratified**: 2026-10-05 | **Last Amended**: 2026-10-05
