# 推しスケ MVP API設計

- バージョン: 0.1
- 作成日: 2026-08-29
- 状態: 最小縦切り用の初版
- 参照: `docs/oshisuke-mvp-spec.md`, `docs/data-model.md`

## 1. 最初に動かす利用経路

```text
推し検索
  ↓
推しを登録
  ↓
ホームで登録した推しのカードを見る
  ↓
開催枠をチェックまたはコミット
  ↓
マイスケジュールで確認する
```

この経路に必要なAPIから実装する。共同編集、投票、通知、通報、OGP取得、管理画面のAPIは後続とする。

## 2. 共通ルール

### 2.1 URL

- APIのベースパスは `/api` とする。
- JSONを返すPHP APIとして実装する。
- URL中のリソース名は複数形、英小文字、ハイフン区切りとする。

例:

```text
GET /api/oshis
GET /api/cards/123
PUT /api/me/schedules/456
```

### 2.2 JSONレスポンス

成功時:

```json
{
  "data": {}
}
```

一覧成功時:

```json
{
  "data": [],
  "meta": {
    "next_cursor": null
  }
}
```

失敗時:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "入力内容を確認してください。",
    "fields": {
      "title": "タイトルを入力してください。"
    }
  }
}
```

画面に表示する日本語は `message` または `fields` を使う。内部の例外内容、SQL、ファイルパスは返さない。

### 2.3 主なHTTPステータス

| 状態 | HTTP |
|---|---:|
| 正常取得・更新 | 200 |
| 正常作成 | 201 |
| 正常削除・レスポンス本文なし | 204 |
| 入力不正 | 422 |
| 未ログイン | 401 |
| 権限不足 | 403 |
| 対象なし | 404 |
| 重複・状態競合 | 409 |
| 回数制限 | 429 |
| 予期しない内部エラー | 500 |

### 2.4 認証とCSRF

- 認証はPHPセッションCookieを使用する。
- ログイン不要のGET APIと、ログイン必須APIを明確に分ける。
- 状態を変更するPOST、PUT、PATCH、DELETEではCSRFトークンを確認する。
- Reactはログイン確認APIからCSRFトークンを受け取り、変更系リクエストの `X-CSRF-Token` ヘッダーへ設定する。
- Cookieは本番で `HttpOnly`, `Secure`, `SameSite=Lax` を基本とする。

## 3. 共通の表示データ

### 3.1 OshiSummary

```json
{
  "id": 10,
  "name": "アイドルA",
  "name_kana": "あいどるえー",
  "type": "group",
  "categories": ["アイドル"],
  "icon_key": "microphone",
  "color": "#C74D67",
  "is_following": true
}
```

ログインユーザーに個人用アイコン・カラーがあれば `icon_key` と `color` へ反映済みの値を返す。未ログインまたは個人設定がなければ共有の初期値を返す。

### 3.2 CardSummary

```json
{
  "id": 120,
  "type": "concert",
  "title": "WINTER TOUR 2026",
  "summary": "全国4都市を巡る冬のツアー。",
  "schedule_status": "scheduled",
  "thumbnail": {
    "url": null,
    "placeholder_key": "concert"
  },
  "oshis": [
    {
      "id": 10,
      "name": "アイドルA",
      "icon_key": "microphone",
      "color": "#C74D67"
    }
  ],
  "occurrences": [
    {
      "id": 501,
      "label": "東京公演",
      "starts_at": "2026-12-24T09:00:00Z",
      "display_timezone": "Asia/Tokyo",
      "venue_name": "東京ドーム",
      "status": "scheduled",
      "my_schedule_state": "checked"
    }
  ],
  "primary_source": {
    "page_title": "WINTER TOUR 2026 公式ページ",
    "domain": "example.jp",
    "outbound_url": "https://example.jp/winter-tour"
  },
  "my_schedule_count": 1250,
  "updated_at": "2026-08-29T03:00:00Z"
}
```

`my_schedule_state` は未ログインまたは未追加なら `null` とする。パスワード、メールアドレス、作成者のHNは含めない。

## 4. 最初に実装するAPI

### 4.1 動作確認

#### `GET /api/health`

ログイン不要。PHP APIが応答できることを確認する。

```json
{
  "data": {
    "status": "ok"
  }
}
```

通常はDB接続確認を含めない。DB接続確認が必要な開発用診断は、外部公開しない別手段にする。

### 4.2 ログイン状態

#### `GET /api/session`

ログイン状態とCSRFトークンを返す。未ログインでも200とする。

```json
{
  "data": {
    "authenticated": true,
    "user": {
      "id": 1,
      "handle_name": "ユーザーA"
    },
    "csrf_token": "response-only-example"
  }
}
```

未ログイン時は `user` を `null` とする。`csrf_token` はログインフォーム等の変更系リクエストにも必要なため、未ログインでも匿名セッション用の値を返す。

### 4.3 推し検索

#### `GET /api/oshis?q={keyword}&category={category}&cursor={cursor}`

ログイン不要。正式名称、読み方、別名、英語表記を検索する。`category` は任意。

- `q` が空の場合は人気候補等を返せるが、初期実装では登録件数順とする。
- 初期取得件数は20件とする。
- `is_following` は未ログインなら `false` とする。

レスポンスの各要素は `OshiSummary`。

#### `POST /api/oshis`

ログイン必須。正式名称、略称、読み方、種類、紹介、初期アイコン、初期カラーから共有の推しプロフィールを作成し、作成者の推しへ自動登録する。同じ正式名称または読み方がある場合は作成しない。公開環境ではメール認証を必須とし、登録7日未満は1日3件までとする。

#### `GET /api/oshis/{oshiId}`

ログイン不要。推しプロフィール、略称、別名、紹介、公式リンク、推し登録人数、および閲覧者の推し登録状態を返す。

### 4.4 推し登録

#### `PUT /api/me/oshis/{oshiId}`

ログイン必須。推し登録または再登録を行う。同じ操作を繰り返しても結果が変わらない。

リクエスト:

```json
{
  "custom_icon_key": null,
  "custom_color": null
}
```

レスポンスは反映後の `OshiSummary`。

#### `DELETE /api/me/oshis/{oshiId}`

ログイン必須。`user_oshis.is_active` を無効にする。個人用アイコン・カラーとマイスケジュールは保持する。

#### `PATCH /api/me/oshis/{oshiId}`

ログイン必須。推し登録している本人の画面だけで使うアイコンとカラーを変更する。共有プロフィールは変更せず、投票も行わない。

```json
{
  "icon_key": "music",
  "color": "#477BC4"
}
```

共有プロフィールの初期アイコン・カラーへ戻す場合は `{ "reset": true }` を送る。推し登録解除後も個人設定は保持し、再登録時に復元する。

### 4.5 ホームのカード一覧

#### `GET /api/cards?scope=following&sort={sort}&oshi_id={oshiId}&cursor={cursor}`

閲覧自体はログイン不要。ただし `scope=following` はログイン必須。

| パラメータ | 値 |
|---|---|
| scope | `following` または `oshi` |
| sort | `new`, `date`, `popular` |
| oshi_id | 特定の推しに絞る場合。`scope=oshi` では必須 |
| cursor | 続きを取得するカーソル |

- ホームは `scope=following` を使用する。
- 推しページは `scope=oshi&oshi_id=...` を使用する。
- 初期取得件数は20件とする。
- レスポンスの各要素は `CardSummary`。

#### `GET /api/cards/{cardId}`

ログイン不要。カード詳細、すべての開催枠、情報元、ハッシュタグを返す。

### 4.6 URLのページ情報確認

#### `POST /api/url-previews`

ログイン必須。カード保存前に情報元URLを安全に確認し、ページ名称とサムネイル候補を返す。

```json
{
  "url": "https://example.com/event"
}
```

- トークンの有効期限は30分、1ユーザーあたり1時間20回まで。
- HTTP/HTTPSの標準ポートのみ許可する。
- DNS解決後のすべてのIPと各リダイレクト先を検査し、ローカル・プライベート・予約済みIPへの通信を拒否する。
- 通信時間、本文容量、画像容量、リダイレクト回数を制限する。
- 取得失敗はカード作成を妨げない。ただし、未確認URLは閲覧者向けリンクとして表示しない。

#### `GET /api/url-previews/{token}/image`

ログイン必須。確認を実行した本人だけが、一時保存されたサムネイル候補を表示できる。

#### `GET /api/source-images/{sourceId}`

カード保存時に採用してサーバーへ保存したサムネイルを返す。外部画像URLを画面から直接読み込まない。

`POST /api/cards` では、元の `source_url` とともに `preview_token`、`use_thumbnail` を送信できる。トークンと元URLが一致する場合だけ、リダイレクト後URL、ページ名称、画像を採用する。

### 4.7 マイスケジュール操作

#### `PUT /api/me/schedules/{occurrenceId}`

ログイン必須。開催枠をマイスケジュールへ追加するか、既存状態を変更する。

リクエスト:

```json
{
  "state": "checked"
}
```

`state` は `checked` または `committed` のみ。

レスポンス:

```json
{
  "data": {
    "occurrence_id": 501,
    "state": "checked",
    "updated_at": "2026-08-29T03:00:00Z"
  }
}
```

#### `DELETE /api/me/schedules/{occurrenceId}`

ログイン必須。一覧から外す。共有カードや開催枠は削除しない。

#### `GET /api/me/schedules?state={state}&period={period}&cursor={cursor}`

ログイン必須。

| パラメータ | 値 |
|---|---|
| state | `checked` または `committed` |
| period | `future`, `past`, `all` |
| cursor | 続きを取得するカーソル |

初期表示は `period=future`。各要素は開催枠を1件含む `CardSummary` とする。

### 4.8 セットリスト

#### `GET /api/occurrences/{occurrenceId}/setlist`

ログイン不要。公演単位のセットリストを表示する。`song_number` は曲だけに付き、MC・OP・SE・ED・その他では `null` となる。

#### `PUT /api/occurrences/{occurrenceId}/setlist`

ログイン必須。最初の登録、または登録者本人による更新を行う。

```json
{
  "items": [
    { "type": "se", "title": "開演SE" },
    { "type": "song", "title": "曲名" },
    { "type": "mc", "title": "MC" }
  ]
}
```

`type` は `song`, `mc`, `op`, `se`, `ed`, `other`。表示順は配列順とし、途中へ行を追加しても曲番号は表示時に自動採番する。現在、他人が登録したセットリストの変更は不可とし、共同編集対応時に編集案方式を追加する。

## 5. 今後追加・拡張するAPI

### 5.1 認証の拡張

- メール確認とパスワード再設定

最初の画面接続では開発用ユーザーを利用できるが、一般公開前に本来の認証へ置き換える。開発用ログインは本番環境で有効にしない。

### 5.2 カード追加の拡張

- `POST /api/cards/{cardId}/sources`
- `POST /api/cards/{cardId}/occurrences`

### 5.3 共同編集以降

- 他人が登録したセットリストの編集案と確認

## 6. 実装前に確認すること

- PHPのルーティング方式
- ローカル開発時のReactとPHPの接続先
- カーソルページングの具体的なキー
- 日時をUTCで保存し、日本時間へ表示する方針
- 開発用ユーザーをどの方法で選択するか

次はこのAPI境界を使い、Docker ComposeでReact、PHP、MySQLが起動し、`GET /api/health` が返る最小環境を作る。
