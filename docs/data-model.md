# 推しスケ MVP データモデル設計

- バージョン: 0.1
- 作成日: 2026-08-29
- 状態: 中核モデルの初版
- 参照: `docs/oshisuke-mvp-spec.md`

## 1. この設計の範囲

最初の実装に必要な、次のデータ境界を定める。

- ユーザー
- 推しプロフィールと推し登録
- 共有カードと開催枠
- カードに関連する推し
- マイスケジュール
- 情報元URL

共同編集、投票、通知、通報、セットリスト、管理操作履歴は重要だが、最初の縦切り実装には含めない。中核テーブルのIDを参照する別テーブルとして後から追加する。

## 2. 最も重要な分離

```text
共有データ                              個人データ

oshi ──< card_oshi >── card             user
                         │                │
                         ├──< occurrence  ├──< user_oshi >── oshi
                         │                │
                         └──< card_source └──< user_schedule >── occurrence
```

- `card` と `occurrence` は、ファン全体で共有する情報である。
- `user_schedule` は、共有情報をユーザーがチェックまたはコミットした記録である。
- マイスケジュールへ入れてもカードを複製しない。
- 共有カードが更新された場合、参照しているユーザーにも最新情報が見える。

## 3. 中核テーブル

### 3.1 users

ログインと内部権限を管理する。

| カラム | 型の候補 | 内容 |
|---|---|---|
| id | BIGINT UNSIGNED | 主キー |
| handle_name | VARCHAR(50) | 非公開のHN |
| email | VARCHAR(255) | ログイン用。重複不可 |
| password_hash | VARCHAR(255) | PHPのパスワードハッシュ |
| email_verified_at | DATETIME NULL | メール確認日時 |
| role | VARCHAR(20) | user / moderator / admin |
| status | VARCHAR(20) | active / suspended / deleted |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |

メールアドレスとパスワードハッシュはAPIレスポンスへ含めない。

### 3.2 oshis

人物、グループ、作品、キャラクター等の共有プロフィール。

| カラム | 型の候補 | 内容 |
|---|---|---|
| id | BIGINT UNSIGNED | 主キー |
| name | VARCHAR(150) | 正式名称 |
| short_name | VARCHAR(40) NULL | ホーム等の狭い場所で使う略称 |
| name_kana | VARCHAR(150) NULL | 読み方 |
| alternate_names | JSON NULL | 別名・英語表記 |
| oshi_type | VARCHAR(30) | person / group / work / character / other |
| description | TEXT NULL | 短い紹介 |
| default_icon_key | VARCHAR(50) | 初期SVGアイコンのキー |
| default_color | CHAR(7) | 初期色。例 `#C74D67` |
| status | VARCHAR(20) | active / hidden |
| created_by | BIGINT UNSIGNED | 作成ユーザー |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |

カテゴリは複数選択なので、後続の `categories` と `oshi_categories` で管理する。別名は初期段階ではJSONとし、検索要件が増えた場合は専用テーブルへ移行できる。

### 3.3 user_oshis

ユーザーが推し登録した対象と、個人用の見た目を管理する。

| カラム | 型の候補 | 内容 |
|---|---|---|
| id | BIGINT UNSIGNED | 主キー |
| user_id | BIGINT UNSIGNED | ユーザー |
| oshi_id | BIGINT UNSIGNED | 推し |
| custom_icon_key | VARCHAR(50) NULL | 個人用アイコン。NULLなら共有設定 |
| custom_color | CHAR(7) NULL | 個人用カラー。NULLなら共有設定 |
| is_active | BOOLEAN | 現在登録中か |
| created_at | DATETIME | 初回登録日時 |
| updated_at | DATETIME | 更新日時 |

`user_id + oshi_id` は重複不可とする。登録解除時は削除せず `is_active = false` にし、再登録時に個人設定を復元する。

### 3.4 cards

ライブ、発売、配信などの共有情報のまとまり。

| カラム | 型の候補 | 内容 |
|---|---|---|
| id | BIGINT UNSIGNED | 主キー |
| card_type | VARCHAR(30) | concert / tour / event / ticket / goods / release / streaming / media / movie / other |
| title | VARCHAR(200) | タイトル |
| summary | TEXT NULL | 内容・概要 |
| benefit_status | VARCHAR(20) | unknown / none / available |
| schedule_status | VARCHAR(20) | undated / scheduled / postponed / cancelled / completed |
| announced_period | VARCHAR(100) NULL | 「2026年冬」等の日程未定情報 |
| placeholder_key | VARCHAR(50) | 仮サムネイルの用途キー |
| created_by | BIGINT UNSIGNED | 最初に作成したユーザー |
| status | VARCHAR(20) | active / hidden / merged |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 重要更新を含む更新日時 |

種類ごとに異なる細かな情報は、初期段階では共通項目と `summary` を使う。検索・並び替えに必要だと確認できた項目から専用カラムまたは詳細テーブルへ分ける。

### 3.5 card_oshis

カードと推しの多対多関係。グループ所属だけでカードを自動表示せず、実際に関連する推しを明示する。

| カラム | 型の候補 | 内容 |
|---|---|---|
| card_id | BIGINT UNSIGNED | カード |
| oshi_id | BIGINT UNSIGNED | 関連する推し |
| relation_type | VARCHAR(30) | primary / performer / related |
| created_at | DATETIME | 関連付け日時 |

`card_id + oshi_id` は重複不可とする。

### 3.6 occurrences

カード内の個別の日程。ツアー、公演、土日連続イベント、複数部を同じ仕組みで扱う。

| カラム | 型の候補 | 内容 |
|---|---|---|
| id | BIGINT UNSIGNED | 主キー |
| card_id | BIGINT UNSIGNED | 親カード |
| label | VARCHAR(150) NULL | 東京公演、第1部等 |
| starts_at | DATETIME NULL | 開始日時 |
| ends_at | DATETIME NULL | 終了日時 |
| date_precision | VARCHAR(20) | datetime / date / month / season / unknown |
| venue_name | VARCHAR(200) NULL | 会場名 |
| venue_address | VARCHAR(255) NULL | 会場住所 |
| status | VARCHAR(20) | scheduled / postponed / cancelled / completed |
| sort_order | INT | カード内の表示順 |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |

単日カードにも原則1件の `occurrence` を作る。完全に日程未定の場合も `starts_at = NULL` の開催枠を作り、後から日付を追加できるようにする。

### 3.7 user_schedules

マイスケジュールの個人状態。

| カラム | 型の候補 | 内容 |
|---|---|---|
| id | BIGINT UNSIGNED | 主キー |
| user_id | BIGINT UNSIGNED | ユーザー |
| occurrence_id | BIGINT UNSIGNED | 対象の開催枠 |
| schedule_state | VARCHAR(20) | checked / committed |
| created_at | DATETIME | 初回登録日時 |
| updated_at | DATETIME | 状態変更日時 |

`user_id + occurrence_id` は重複不可とする。一覧から外す操作はこの行を削除する。将来、操作履歴が必要になった場合は別の履歴テーブルへ記録する。

ツアー全体を一括チェックする操作は、対象となる各 `occurrence` の `user_schedules` を一括作成する。個別公演の状態は後から変更できる。

### 3.8 card_sources

カードに紐づく複数の情報元URL。

| カラム | 型の候補 | 内容 |
|---|---|---|
| id | BIGINT UNSIGNED | 主キー |
| card_id | BIGINT UNSIGNED | 対象カード |
| source_role | VARCHAR(20) | primary / related / archived |
| submitted_url | TEXT | ユーザーが入力したURL |
| resolved_url | TEXT NULL | リダイレクト後のURL |
| canonical_url | TEXT NULL | ページが示した正規URL |
| outbound_url | TEXT | ユーザーが実際に開くURL |
| page_title | VARCHAR(300) NULL | OGPまたはHTMLのページ名称 |
| thumbnail_url | TEXT NULL | 取得したサムネイル候補 |
| fetch_status | VARCHAR(20) | pending / success / failed / blocked |
| created_by | BIGINT UNSIGNED | 追加ユーザー |
| created_at | DATETIME | 追加日時 |
| updated_at | DATETIME | 更新日時 |

URLは単純に上書きしない。ティザーから正式LPへ移行した場合は、新しい行を主情報元にし、以前の行を関連情報または履歴として残す。OGP取得失敗はカード保存を妨げない。

## 4. 次に追加するテーブル群

中核モデルの確認後、次の単位で設計する。

1. `categories`, `oshi_categories`, `hashtags`, `card_hashtags`
2. `oshi_links`（`0011_oshi_profiles.sql` で追加済み）, `oshi_relations`
3. `edit_proposals`, `edit_proposal_votes`, `change_history`
4. `notifications`, `notification_preferences`
5. `reports`, `admin_action_logs`
6. `setlists`, `setlist_items`（`0010_setlists.sql` で追加済み）

### 4.1 setlists / setlist_items

`setlists` は1つの開催枠（公演）につき1件とする。`setlist_items` は配列順を `sort_order` で保持し、種類を `song`, `mc`, `op`, `se`, `ed`, `other` から選ぶ。曲番号は保存せず、表示時に `song` だけを数えて自動採番する。これにより、途中へMCやSEを挿入しても曲番号がずれない。

## 5. DB実装前に決める事項

- 日時をDBではUTCで保存し、画面では日本時間へ変換するか
- `alternate_names` をJSONで開始するか、検索用テーブルへ最初から分けるか
- カード種類固有項目のうち、MVP初期から専用カラムが必要なもの
- 論理削除の共通ルールと保持期間
- 外部キー削除時の動作

これらを主要API設計と一緒に確認してから、MySQLのDDLを作成する。
