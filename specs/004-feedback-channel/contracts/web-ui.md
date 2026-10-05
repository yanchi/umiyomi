# Contract: フッターのリンク・案内画面・外部フォーム

**Feature**: 004-feedback-channel | **Date**: 2026-10-05

ルートを 1 つ追加する（`GET /feedback`）。既存の `GET /`・`GET /forecast` の Query・ステータスは変えない。画面・URL 設計は人間のレビュー対象。

## フッター（全画面共通、`base.html.twig`）

```html
<footer class="site-footer">
    <a class="site-footer__feedback" href="/feedback?lat=27.75&amp;lon=129.05&amp;updated=2026%2F10%2F05%2009%3A00">フィードバック</a>
    <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a>
</footer>
```

- `FEEDBACK_FORM_URL` / `FEEDBACK_FORM_PREFILL_FIELD` が未設定（[research R2](../research.md#r2-外部フォームの設定と事前入力の欄の数)）なら、フィードバックのリンクを出さない（FR-012）
- 同じタブで開く（`target` なし）（FR-014）
- Query は画面の状態で変わる（[data-model.md](../data-model.md) の `feedbackQuery`）

| 画面 | リンク |
|---|---|
| トップ画面 | `/feedback` |
| 予報画面（予報あり・代替表示） | `/feedback?lat=27.75&lon=129.05&updated=2026/10/05 09:00`（URL エンコード済み） |
| 予報画面（取得失敗 503・回数制限 429） | `/feedback?lat=27.75&lon=129.05` |
| 予報画面（入力エラー 422） | `/feedback?input_lat=北緯二十七度&input_lon=129.05`（各 100 文字まで） |
| 案内画面 | 出さない（自分自身へのリンクになるため） |

- スマホ幅で指で押せるよう、リンクは `display: inline-block` と上下の余白で高さ 44px 以上を確保し、Open-Meteo の表記とは行を分ける（FR-002、SC-004）

## `GET /feedback`

### Query

| パラメータ | 形式（FR-016） | 例 |
|---|---|---|
| `lat` / `lon` | `^-?\d{1,3}\.\d{2}$`、範囲内。両方そろったときだけ使う | `27.75` / `129.05` |
| `updated` | `YYYY/MM/DD HH:mm`（実在する日時）。`lat`・`lon` を使うときだけ使う | `2026/10/05 09:00` |
| `input_lat` / `input_lon` | 任意の文字列。先頭 100 文字まで。`lat`・`lon` を使うときは使わない | `北緯二十七度` |

形に合わない値は無視する（エラーを出さない）。

### Response

| 状況 | Status | 内容 |
|---|---|---|
| フォームが設定されている | 200 | 案内画面 |
| フォームが未設定 | 404 | Symfony の既定の 404 |

- 予報の取得（UseCase・キャッシュ・回数制限）は行わない（FR-013）
- `<head>` に `<meta name="referrer" content="no-referrer">` と `<meta name="robots" content="noindex">` を出す（[research R7](../research.md#r7-外部フォームを開くリンクfr-014fr-018)）

### 画面の構成（上から順に）

```text
UMIYOMI（トップ画面へのリンク）
フィードバック                                          ← h1
不具合・要望・予報の値についての気づきなどを、外部のフォームで受け付けています。

┌ 送る前にご確認ください ─────────────────────────────┐  ← FR-004。フォームを開く操作より上
│ ・返信や対応をお約束するものではありません。             │
│ ・海上での事件・事故の緊急通報は、海上保安庁（118 番）へ   │
│   連絡してください。このフォームでは受け付けていません。   │
│ ・出航の判断には、気象庁などが発表する警報・注意報も       │
│   あわせて確認してください。                             │
└───────────────────────────────────────────────┘

（Forecast のとき）
緯度 27.75・経度 129.05、最終更新 2026/10/05 09:00 がフォームに入ります。
（RejectedInput のとき）
受け付けられなかった次の入力がフォームに入ります。
  緯度欄に入力した文字列
  ┃ 北緯二十七度                                       ← <blockquote>（FR-017）
  経度欄に入力した文字列
  ┃ 129.05
（Forecast・RejectedInput のとき）
送る前にフォームで確認でき、送りたくない場合は消せます。

[ フィードバックのフォームを開く（新しいタブ） ]         ← FR-013。操作はこれ 1 つ
返信用の連絡先の記入は任意です。

← 予報画面に戻る / 入力画面に戻る / トップ画面に戻る   ← FR-014
```

- フォームを開くリンク：`<a class="feedback-open" href="{formUrl}" target="_blank" rel="noopener noreferrer">`。ボタンの見た目で、高さ 44px 以上（FR-002 と同じ基準）
- 入力の文字列・地点は Twig の自動エスケープで出す。`|raw` を使わない
- 文言に「安全です」「出航できます」「問題ありません」などの断定を含めない（FR-005）。Functional Test で確かめる

## 外部フォームの URL

`FeedbackFormLink::urlFor()`（[research R3](../research.md#r3-事前入力の-url-の組み立て)）：

| 設定された URL | 文脈 | 開く URL |
|---|---|---|
| `https://docs.google.com/forms/d/e/XXX/viewform?usp=pp_url` | Forecast | `…/viewform?usp=pp_url&entry.1000=%E7%B7%AF%E5%BA%A6%2027.75%E3%83%BB…` |
| 同上 | None | `…/viewform?usp=pp_url`（そのまま） |
| `https://tally.so/r/XXX` | Forecast | `https://tally.so/r/XXX?context=%E7%B7%AF…` |

## 外部フォーム側の設定（運営者が用意する。UMIYOMI の実装外）

- 欄：種類（不具合・要望・予報の値についての気づき・その他、必須）（FR-006）、本文（必須）、返信用の連絡先（任意）（FR-007）、表示中の情報（任意。事前入力の欄）
- ログイン・アカウントなしで送れる設定にする。回答者のメールアドレスを収集しない
- 送信後の表示（送れたことが分かる）、迷惑な送信への対策、届いた内容の扱いの説明はフォーム側で行う
- 確認の手順は [quickstart.md](../quickstart.md)
