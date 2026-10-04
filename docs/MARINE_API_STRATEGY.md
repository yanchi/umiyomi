# UMIYOMI 海況API・開発フェーズ方針

> 本書はモバイルアプリ化までを含めた長期方針。Web MVP の範囲（オフライン閲覧・日時指定・Flutter を MVP に含めない等）は
> [SPEC.md](SPEC.md) §0 と [constitution](../.specify/memory/constitution.md) を優先する。

## 基本方針

UMIYOMIは、指定した緯度・経度について「出航前に風・波・うねり等を確認する」モバイルアプリ。

海上では通信できない可能性があるため、陸上で予報を取得し、端末へ保存してオフラインでも閲覧できるようにする。

バックエンドにはSymfonyを使用する。

Flutterから外部の海況APIを直接呼ばず、

```text
Flutter
  ↓
Symfony API
  ↓
MarineForecastProvider
  ↓
外部海況API
```

という構成にする。

外部APIへの依存をSymfonyのInfrastructure層へ隔離し、将来的なAPI変更・複数Provider利用を容易にする。

## Phase 1：MVP開発

### 目的

まず「UMIYOMIというプロダクトが使われるか」を検証する。

この段階では予報APIに大きな費用をかけない。

### 海況API

Open-Meteo Marine / Weather APIを使用する。

主な取得項目：

- 風速
- 風向
- 波高
- 波向
- 波周期
- うねり高
- うねり方向
- うねり周期

### 実装

Symfony側に、`MarineForecastProvider` という抽象化されたPortを用意する。

Infrastructure側で、`OpenMeteoMarineForecastProvider` を実装する。

Application/DomainはOpen-Meteo固有仕様へ依存させない。

### MVP機能

- 緯度・経度指定
- 日時指定
- 風・波・うねり予報
- 時間別予報
- お気に入り地点
- 端末への予報保存
- オフライン閲覧
- 最終データ取得日時表示

### 検証

徳之島周辺など実際に海へ出るユーザーに使ってもらう。

確認すること：

- 予報が体感と大きくズレていないか
- どの情報を最も見るか
- 何時間先まで必要か
- どの機能なら課金したいか
- Open-Meteoの精度で実用になるか

## Phase 2：予報精度比較

### 目的

UMIYOMIの利用価値が確認できたら、予報精度を改善する。

Open-Meteoだけを信用するのではなく、複数Providerを比較する。

### 追加候補

Stormglass等を追加する。

```text
MarineForecastProvider
├── OpenMeteoMarineForecastProvider
└── StormglassMarineForecastProvider
```

同一の緯度・経度・日時について各Providerの予報を取得する。

### 比較

例えば、地点：徳之島沖

| | Open-Meteo | Stormglass |
|---|---|---|
| 波高 | 1.2m | 1.5m |
| 周期 | 7.1s | 7.8s |
| 風速 | 6.2m/s | 6.8m/s |
| うねり | 0.9m | 1.1m |

のように内部的に比較する。

実際の現地状況や利用者の感覚とも比較し、UMIYOMIに適したProviderを判断する。

ユーザーへ複数Providerの値をそのまま見せる必要はない。

## Phase 3：商用化

### 目的

課金ユーザーを獲得できる見込みが立った段階で、商用利用可能な海況データへ移行する。

この段階で初めてAPIコストを本格的に負担する。

### 候補

- Open-Meteo商用プラン
- Stormglass商用プラン
- 日本沿岸向け海況API
- その他の商用Marine Weather API

重要なのは、「最初に高価なAPIを契約してからユーザーを探す」のではなく、「ユーザーがいることを確認してからデータへ投資する」こと。

## Phase 4：日本沿岸特化

UMIYOMIの利用者が増え、日本沿岸での予報精度が競争力になる段階。

候補として、

- 気象庁 CWM（沿岸波浪モデル）
- MOVE-JPN
- 日本気象協会等の日本沿岸向けデータ

を検討する。

必要であればGRIB2等の数値予報データをSymfony側で処理・正規化する。

## 将来のProvider構成

最終的には以下のような構造を想定する。

```text
Flutter
   ↓
Symfony
   ↓
MarineForecastService
   ↓
MarineForecastProvider
   │
   ├── OpenMeteoProvider
   ├── StormglassProvider
   ├── JmaCwmProvider
   └── OtherProvider
```

Domain/Applicationから見れば、`forecast(Coordinate, ForecastPeriod)` で海況予報が取得できればよく、データ提供元を意識させない。

## 重要な設計原則

APIレスポンスをそのままUMIYOMIのDomain Modelにしない。

```text
External API
↓
Provider固有DTO
↓
Mapper
↓
UMIYOMI Domain Model
↓
Application
↓
API Response DTO
↓
Flutter
```

という境界を維持する。

これにより、Open-Meteo → Stormglass → JMA とProviderを変更しても、アプリ全体を書き直す必要がない。

## コスト戦略

基本思想：

```text
Phase 1
無料/低コストAPI
↓
MVP
↓
ユーザー検証

Phase 2
複数Provider比較
↓
精度検証

Phase 3
課金開始
↓
商用APIへ投資

Phase 4
日本沿岸特化
↓
高精度データ・独自処理
```

最初から最高精度を目指さない。

UMIYOMIで最初に検証すべきなのは、「最高精度の海況予報を作れるか」ではなく、「指定した海域の風・波を出航前に簡単に確認できる体験にユーザー価値があるか」である。
