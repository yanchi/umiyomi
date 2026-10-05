# UMIYOMI

## 0. MVP方針（2026-10-05 変更）

この節は以降の節より優先する。

- MVPは**Webサービス**として開始する。フロントは**Symfony + Twig**で、Symfony単体で完結させる
- Flutterによるモバイルアプリ（iOS/Android）は**MVP完成後**に展開する。§3・§4のMobile・§9は、アプリ化のときの設計とする
- **オフライン閲覧はMVPの範囲外**とし、アプリ化のときに対応する（§10はアプリ化のときの設計とする）
- MVPではFlutter向けJSON API（§8）は必須にしない。ただしアプリ化のときに同じUseCaseの上へJSON APIを追加できるよう、UseCaseはTwig/HTTPに依存させない
- Presentation層は、MVPでは`Presentation/Web/Controller`とTwigテンプレート。アプリ化のときに`Presentation/Api/Controller`を追加する
- Domain Entity / Doctrine EntityはTwigへ直接渡さず、Presentation用のViewModel/DTOへ変換する
- 予報の取得日時（「最終更新：YYYY/MM/DD HH:mm」）はWeb版でも表示する
- お気に入り地点は**ブラウザの localStorage**に保存する。ログイン不要とし、MVPではサーバー側にユーザー・お気に入りのテーブルを作らない（PostgreSQLは永続化対象が出てくるまで導入しない）

Web MVPの完成条件（§16を置き換える）：

1. ブラウザで緯度・経度を入力
2. Symfonyから外部海況APIを取得
3. 風・波・うねりを時間別表示
4. 地点をお気に入り保存

## 1. プロダクト概要

**UMIYOMI（ウミヨミ）**は、これから向かう海域の緯度・経度を指定し、その地点の**風・波・うねりなどの海況予報を出航前に確認するモバイルアプリ**。

コンセプト：

> **海を読む。出る前に。**

海上では通信できない可能性があるため、陸上で事前に海況を取得し、必要な情報をスマートフォンへ保存する。

海上では最後に取得した予報をオフラインで確認できるようにする。

### 想定ユーザー

- 小型船・プレジャーボート利用者
- 釣り人
- 遊漁船
- 漁業従事者
- ダイビング・マリンレジャー利用者

初期ターゲットは、

**「明日、このポイントへ船で行く場合の海況を事前に確認したい人」**

とする。


# 2. MVP

> Web MVPの範囲は§0を優先する。5（オフライン閲覧）はWeb MVPの範囲外で、アプリ化のときに対応する。

MVPでは以下を実装する。

1. 緯度・経度による海域指定
2. 風・波・うねりの予報取得
3. 時間別予報
4. お気に入り地点（Web MVPではブラウザの localStorage に保存）
5. オフライン閲覧（アプリ化のとき）

将来的な、

- 航路予報
- 出航計画
- 地図指定
- Push通知
- ユーザー間共有
- Businessプラン

などはMVP完成後に検討する。


# 3. システム構成

```text
Flutter App
     |
     | HTTPS / JSON
     v
Symfony API
     |
     +---- Weather Provider
     |       |
     |       +---- Open-Meteo Weather API
     |       +---- Open-Meteo Marine API
     |
     +---- Cache
     |
     +---- Database
```

FlutterからOpen-Meteo等の外部サービスを直接呼び出さない。

必ずSymfony APIを経由する。


# 4. 技術スタック

## Mobile

- Flutter
- Dart
- iOS
- Android

1コードベースでiOS/Androidに対応する。


## Backend

- PHP
- Symfony
- REST API
- Doctrine ORM / DBAL

Symfonyは開発開始時点の安定版を採用する。

バックエンドはWebページを提供することを主目的とせず、Flutter向けAPIとして設計する。


## Database

PostgreSQLを第一候補とする。

用途：

- ユーザー
- お気に入り地点
- 将来的な航路
- ユーザー設定
- 課金情報
- その他サーバー側で永続化すべき情報

MVP初期では不要なテーブルを先回りして作らない。


## Cache

Symfony Cacheを利用する。

本番環境では必要に応じてRedisを使用する。

同一地点・同一時間帯について外部APIを毎回呼ばないようにする。


## External API

初期候補：

### Open-Meteo Weather API

- 風速
- 風向
- 気象情報

### Open-Meteo Marine Weather API

- 波高
- 波向
- 波周期
- うねり
- 海面水温
- 海流

外部APIはInfrastructure層に隔離する。

Domain/Application層からOpen-Meteo固有のレスポンス形式を参照してはいけない。


# 5. Backend Architecture

Symfony側はClean Architecture / Layered Architectureを基本とする。

```text
src/

  Domain/
    Marine/
      Entity/
      ValueObject/
      Repository/
      Service/

  Application/
    Marine/
      UseCase/
      DTO/

  Infrastructure/
    Marine/
      Weather/
        OpenMeteo/
      Persistence/
        Doctrine/

  Presentation/
    Api/
      Controller/
```

依存方向：

```text
Presentation
     |
     v
Application
     |
     v
Domain
     ^
     |
Infrastructure
```

DomainはSymfony、Doctrine、Open-Meteoなどの技術詳細へ依存しない。


# 6. 外部海況APIの抽象化

Providerの選定・切り替えのフェーズ方針は [MARINE_API_STRATEGY.md](MARINE_API_STRATEGY.md) を参照する。

Open-Meteoを直接Application層から利用しない。

例えばDomain/Application側には以下のようなPortを定義する。

```text
MarineForecastProvider
```

概念：

```php
interface MarineForecastProvider
{
    public function forecast(
        Coordinate $coordinate,
        ForecastPeriod $period,
    ): MarineForecast;
}
```

Infrastructure側で、

```text
OpenMeteoMarineForecastProvider
```

を実装する。

将来的に、

```text
OpenMeteo
    ↓
別のMarine Weather API
```

へ変更してもApplication/Domainへ影響を与えないようにする。


# 7. Domain Model

候補となるValue Object：

```text
Coordinate
Latitude
Longitude

WindSpeed
WindDirection

WaveHeight
WaveDirection
WavePeriod

SwellHeight
SwellDirection
SwellPeriod
```

ただし、値をラップするだけのValue Objectを大量生産しない。

以下のいずれかを満たす場合にValue Object化を検討する。

- validationが存在する
- 単位を持つ
- Domain上の意味を持つ
- 不正な状態を防止できる
- Domainロジックを持たせる意味がある


# 8. API

MVPでは例えば、

```http
GET /api/v1/marine-forecasts
```

Query：

```text
latitude=27.75
longitude=129.05
date=2026-10-06
```

Response例：

```json
{
  "location": {
    "latitude": 27.75,
    "longitude": 129.05
  },
  "generatedAt": "2026-10-05T12:00:00+09:00",
  "forecasts": [
    {
      "time": "2026-10-06T06:00:00+09:00",
      "wind": {
        "speed": 4.2,
        "direction": 45
      },
      "wave": {
        "height": 0.8,
        "direction": 80,
        "period": 7.1
      },
      "swell": {
        "height": 0.6,
        "direction": 75,
        "period": 8.2
      }
    }
  ]
}
```

API ResponseはPresentation用DTOへ変換する。

Domain EntityをそのままJSON serializeしない。


# 9. Flutter Architecture

Flutter側もPresentationとデータ取得処理を分離する。

```text
lib/

  presentation/
    screens/
    widgets/

  application/
    usecases/

  domain/
    models/
    repositories/

  infrastructure/
    api/
    storage/

  core/
```

通信：

```text
Flutter
   ↓
UmiyomiApiClient
   ↓
Symfony API
```

Symfony APIのレスポンスDTOを直接Widgetで使用しない。


# 10. オフライン設計

海上では通信できないことを前提とする。

出航前：

```text
Flutter
  ↓
Symfony
  ↓
Weather Provider
  ↓
Marine Forecast
  ↓
Flutter Local Storage
```

海上：

```text
Flutter
  ↓
Local Storage
```

最後に取得した予報を表示する。

画面には必ず、

```text
最終更新：2026/10/05 20:15
```

を表示する。

通信できない場合：

> 現在オフラインです。表示されている情報は最後に取得した予報です。

と明示する。


# 11. 開発ルール

## Language

コード：

英語

Class / Method / Variable：

英語

コメント：

原則日本語。

コードを読めば分かる内容をコメントしない。

「何をしているか」ではなく、**なぜその実装・設計を選択したのか**を書く。


## PHP

- PSR-12準拠
- strict_typesを使用
- PHPStanを使用
- PHP-CS-Fixer等による自動整形
- Symfony Best Practicesを尊重する


## Dart

- Effective Dart準拠
- dart format
- flutter analyze


# 12. Symfonyでの設計ルール

ControllerへDomain Logicを書かない。

Controllerの責務：

```text
HTTP Request
↓
Input変換
↓
UseCase呼び出し
↓
Response変換
```

UseCaseへHTTP固有のRequest/Responseオブジェクトを渡さない。

Doctrine EntityをAPI Responseとして直接返さない。

Doctrine RepositoryをApplicationから直接利用せず、Domain側のRepository Interfaceを経由する。

Symfony Service ContainerはInfrastructure/Presentationの関心事として扱う。

可能な限りDomain ObjectへSymfony Attribute等を持ち込まない。


# 13. テスト

## Backend

重点的にテストする：

- Domain Logic
- Value Object
- UseCase
- API Response変換
- Open-Meteoレスポンス変換
- Cache
- 時刻処理
- 座標validation

Feature/Integration Testでは、

```text
HTTP
→ Controller
→ UseCase
→ Repository/Provider
→ Response
```

の主要経路を確認する。

外部APIを利用するテストとFake Providerを利用するテストを分離する。


## Flutter

重点：

- API Response変換
- ローカルキャッシュ
- オフライン処理
- 重要画面
- 時間別予報表示

すべてのWidgetへ機械的にテストを書く必要はない。


# 14. AI駆動開発

Claude Codeを積極的に利用する。

基本：

```text
Spec
↓
Plan
↓
Implementation
↓
Static Analysis
↓
Test
↓
Human Review
```

大きな変更では実装前にPlanを作成する。

特にHuman Reviewを必要とする：

- Domain設計
- Repository境界
- API Contract変更
- DB Schema変更
- 外部API Provider変更
- 課金
- Security
- 海況判断ロジック

低リスク変更については、

```text
Implementation
↓
PHPStan / Analyze
↓
Test
↓
成功
↓
次へ
```

と自律的に進めてよい。


# 15. 安全性

UMIYOMIは航海・出航の安全を保証するシステムではない。

以下のような断定を原則として行わない。

```text
安全です
出航できます
問題ありません
```

代わりに、

```text
12時以降、波高が上昇する予報です。

09:00  0.8m
12:00  1.4m
15:00  2.1m
```

のように判断材料を提示する。

必要に応じて気象庁等の公式な気象・海上警報も確認するよう案内する。


# 16. MVP完成条件

> Web MVPの完成条件は§0で置き換えている。以下はアプリ化（Flutter）のときの完成条件とする。

以下が動作すればMVP完成とする。

1. Flutterで緯度・経度を入力
2. Symfony APIへ問い合わせ
3. Symfonyから外部海況APIを取得
4. 風・波・うねりを時間別表示
5. 地点をお気に入り保存
6. 取得した予報を端末保存
7. オフライン状態でも最後の予報を閲覧

これが完成するまでは、

- 航路
- SNS
- 高度なアカウント機能
- Business機能
- 独自AI予測

などへスコープを広げない。

**まず「明日この海域へ行くとき、風と波がどう変化するかを出航前に簡単に確認できる」という体験を完成させる。**
