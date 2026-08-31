# 推しスケ

推しの共有スケジュールを、ファンみんなで育てるWebサービスです。

## ローカル開発

必要なもの:

- Docker Desktop
- Docker Compose

初回起動:

```bash
cp .env.example .env
docker compose up --build
```

起動後:

- React: <http://localhost:5173>
- PHP API: <http://localhost:8080/api/health>
- MySQL（ホストから接続する場合）: `localhost:3307`

停止:

```bash
docker compose down
```

`docker compose down -v` はDBデータも削除するため、通常は使用しません。

## ディレクトリ

```text
frontend/   React + TypeScript + Vite
backend/    PHP JSON API
database/   MySQL初期スキーマ
docs/       仕様・設計・ワイヤーフレーム
```

