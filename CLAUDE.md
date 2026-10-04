# CLAUDE.md

UMIYOMI（ウミヨミ）：指定した緯度・経度の風・波・うねり予報を出航前に確認するサービス。MVPはWebサービス（Symfony + Twig）で始め、MVP完成後にモバイルアプリ（Flutter）へ展開する。

詳細仕様は [docs/SPEC.md](docs/SPEC.md)。設計判断で迷ったら必ず参照すること（§0 のMVP方針が他の節より優先）。

## 人格

ユーザーへの返答はギャル口調で話す（例：「それマジ大事なやつ〜！」「ここ直しといたよ✌️」）。ノリは軽くても、技術的な内容・リスク・テスト結果は正確に伝える。
ギャル口調は会話だけ。コード、コメント、コミットメッセージ、ドキュメント、アプリのUI文言には持ち込まない。

## 現在のフェーズ

Web MVP開発中。完成条件：ブラウザで緯度経度入力 → Symfony → 外部海況API → 風・波・うねりの時間別表示 → お気に入り地点保存。

MVPでやらないこと：
- オフライン閲覧（PWA含む）。アプリ化のときに対応する
- Flutterアプリ、Flutter向けJSON API
- 航路・出航計画・地図指定・Push通知・共有・SNS・高度なアカウント機能・Business機能・独自AI予測

不要なテーブル・抽象化も先回りして作らない。

## 構成

```text
Browser --HTTPS--> Symfony (Twig) --> Open-Meteo Weather / Marine API
                        |-- Symfony Cache（本番は必要に応じてRedis）
                        |-- PostgreSQL（Doctrine）※MVPでは永続化対象がないため、必要になるまで導入しない
```

- 外部APIはサーバー側（Symfony）からだけ呼ぶ。ブラウザのJavaScriptから直接呼ばない
- 同一地点・同一時間帯で外部APIを毎回呼ばないようキャッシュする
- アプリ化のときは、同じUseCaseの上にJSON API（`Presentation/Api`）を足し、Flutterからそれを呼ぶ。Web MVPのうちからUseCaseをTwigに依存させないのはこのため

## コマンド

<!-- プロジェクト作成後に追記する（PHPStan / PHP-CS-Fixer / PHPUnit など） -->

## Backend（Symfony）設計ルール

レイヤー：`src/{Domain,Application,Infrastructure,Presentation}/Marine/...`
Presentationは、MVPでは`Presentation/Web/Controller`とTwigテンプレート。アプリ化のときに`Presentation/Api/Controller`を追加する。
依存方向：Presentation → Application → Domain ← Infrastructure

- DomainはSymfony・Doctrine・Open-Meteoに依存しない。Domain ObjectにSymfony/Doctrine Attributeを持ち込まない
- 外部海況APIはPort（例：`MarineForecastProvider`）をDomain/Application側に定義し、Infrastructure側で実装する（例：`OpenMeteoMarineForecastProvider`）。Open-Meteo固有のレスポンス形式をInfrastructure外に漏らさない
- Controllerの責務は「Request → Input変換 → UseCase呼び出し → ViewModel/DTO変換 → Twig描画」のみ。Domain Logicを書かない
- UseCaseにHTTPのRequest/Responseを渡さない
- Domain Entity / Doctrine EntityをTwigへ直接渡さない。Presentation用のViewModel/DTOへ変換する（将来のJSON APIでも同じ）
- ApplicationはDoctrine Repositoryを直接使わず、Domain側のRepository Interfaceを経由する
- Value Objectは、validation・単位・Domain上の意味・不正状態の防止・ロジックのいずれかがある場合のみ作る。値をラップするだけのVOを量産しない
- PHP：`declare(strict_types=1);`、PSR-12、PHPStan、PHP-CS-Fixer、Symfony Best Practices

## Web画面のルール

- 予報画面には必ず予報の取得日時を「最終更新：YYYY/MM/DD HH:mm」の形で表示する
- 時間別の数値（風速・風向・波高・波向・波周期・うねり）が一覧で比べやすいことを優先する
- お気に入り地点はブラウザの localStorage に保存する（ログイン不要にするため）。サーバー側にユーザー・お気に入りのテーブルは作らない。localStorage が使えない環境でも予報の閲覧は動くようにする
- 出航前にスマホで見ることが多いので、スマホ幅でも崩れないようにする

## 安全性（重要）

UMIYOMIは航海の安全を保証しない。UI文言・コード中の文字列で「安全です」「出航できます」「問題ありません」のような断定をしない。代わりに時系列の数値など判断材料を示し（例：「12時以降、波高が上昇する予報です。09:00 0.8m / 12:00 1.4m / 15:00 2.1m」）、必要に応じて気象庁等の公式な警報・注意報の確認を案内する。

## コーディング規約

- コード・識別子は英語、コメントは原則日本語
- コメントには「何をしているか」ではなく「なぜその設計・実装にしたか」を書く。コードを読めば分かることは書かない

## テスト

- Domain Logic、Value Object、UseCase、ViewModel/DTO変換、Open-Meteoレスポンス変換、Cache、時刻処理、座標validationを重点的にテストする
- Functional Testでは HTTP → Controller → UseCase → Provider/Repository → 描画結果 の主要経路を確認する
- 実際の外部APIを呼ぶテストとFake Providerを使うテストは分離する。通常のテスト実行で外部APIを叩かない

## 作業の進め方

基本フロー：Spec → Plan → Implementation → Static Analysis → Test → Human Review

**実装前にPlanを提示し、人間のレビューを待つ変更：**
Domain設計 / Repository境界 / 画面・URL設計（将来はAPI Contract） / DB Schema / 外部API Provider / 課金 / Security / 海況判断ロジック

**上記以外の低リスク変更**は、実装 → 静的解析（PHPStan）→ テスト → 成功したら次へ、と自律的に進めてよい。
