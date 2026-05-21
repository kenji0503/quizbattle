# バトル WebSocket daemon 起動手順

## 概要

`server/ws-battle.js` は常駐で動かす realtime 用プロセスです。

この daemon は次の役割を持ちます。

- ブラウザからの WebSocket 接続を受ける
- PHP からの publish 通知を受ける
- `q_start_at`、`reveal_at`、`switch_at` に合わせてバトル状態を配信する

現在のバトル画面は、この daemon を realtime の主経路として使う前提です。

## 使用ポートとパス

- daemon 待受ポート: 既定値 `8081`
- 公開 WebSocket パス: `/ws`
- PHP からの内部通知先: `http://127.0.0.1:8081/publish`
- ヘルスチェック: `http://127.0.0.1:8081/health`

## `.env` に入れる項目

最低限、次を設定してください。

```env
QB_WS_SECRET=十分に長いランダム文字列に変更してください
QB_WS_INTERNAL_URL=http://127.0.0.1:8081/publish
QB_WS_SYNC_BASE_URL=https://battle-test.quizbattle.jp
```

必要に応じて、次も設定できます。

```env
QB_WS_HOST=0.0.0.0
QB_WS_PORT=8081
QB_WS_PUBLIC_URL=wss://battle-test.quizbattle.jp/ws
```

補足:

- `QB_WS_SECRET` はほぼ必須です。PHP 側と daemon 側で同じ値を使います。
- `QB_WS_SYNC_BASE_URL` は `get_battle_state.php` を取得できる PHP サイトの URL を指定します。
- `QB_WS_PUBLIC_URL` は、同一ホストの `/ws` をリバースプロキシする場合は省略可能です。

## 手動起動

リポジトリのルートで次を実行します。

```powershell
node server/ws-battle.js
```

正常起動すると、次のようなログが出ます。

```text
battle ws daemon listening on 0.0.0.0:8081 base=https://battle-test.quizbattle.jp
```

## ヘルスチェック

同じサーバ上で次を実行します。

```powershell
curl http://127.0.0.1:8081/health
```

期待される応答:

```json
{"ok":true,"clients":0,"rooms":0}
```

## リバースプロキシ設定

ブラウザは PHP サイトと同じホストの `/ws` に接続する前提です。

### 既存システムに影響を出さない原則

- `battle-test.quizbattle.jp` 用の vhost / server block にだけ `/ws` を追加する
- 既存の `speed-test` や本番用ドメインの `/ws` 設定は変更しない
- 既存ドメインの共通設定にある `/ws` を書き換えない

今回の `battle-test` 環境では daemon が `127.0.0.1:8085` で待受しているので、`battle-test.quizbattle.jp` 専用設定の `/ws` だけを `127.0.0.1:8085/ws` へ転送してください。

### nginx の例

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8085/ws;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

### Apache の例

必要モジュール:

- `proxy`
- `proxy_http`
- `proxy_wstunnel`

設定例:

```apache
ProxyPass /ws ws://127.0.0.1:8085/ws
ProxyPassReverse /ws ws://127.0.0.1:8085/ws
```

サンプル全体は次を参照してください。

- `docs/webserver/nginx-battle-test.conf`
- `docs/webserver/apache-battle-test.conf`

## 初回起動手順

1. `.env` に `QB_WS_SECRET` を設定する
2. `.env` に `QB_WS_SYNC_BASE_URL` を設定する
3. `node server/ws-battle.js` で daemon を起動する
4. `http://127.0.0.1:8085/health` が `ok:true` を返すことを確認する
5. `/ws` のリバースプロキシ設定を入れる
6. nginx または Apache を reload する
7. ブラウザでロビー画面を開く
8. ブラウザが `ws://.../ws` または `wss://.../ws` に接続できることを確認する

## ブラウザでの確認項目

開発者ツールを開いて、次を確認してください。

- `/ws` が `101 Switching Protocols` で接続される
- `get_question.php` の連続ポーリングが出続けない
- `get_battle_state.php` の連続ポーリングが出続けない

初回表示時の同期リクエストは 1 回発生します。その後の更新は WebSocket 受信が主体になります。

## 運用上の注意

- ロビーでは参加維持のため `ping_battle.php` を 30 秒ごとに呼びます
- バトル状態の publish は `ready`、`next`、`reveal`、`buzz`、参加者 join / ping のタイミングで発生します
- daemon が落ちると PHP が生きていても realtime 更新は止まります

## トラブルシュート

### `/health` は通るがブラウザが接続できない

まず `/ws` のリバースプロキシ設定を確認してください。

### ブラウザは接続できるがバトルが更新されない

次を確認してください。

- `QB_WS_SECRET` が PHP 側と daemon 側で一致しているか
- daemon 側ログに publish エラーが出ていないか
- PHP 側ログに state 更新が出ているか

### daemon が battle state を取得できない

`QB_WS_SYNC_BASE_URL` を確認してください。

daemon から次にアクセスできる必要があります。

- `/get_battle_state.php`

### 既存システムとポートが衝突する

次のように変更してください。

```env
QB_WS_PORT=8085
QB_WS_INTERNAL_URL=http://127.0.0.1:8085/publish
QB_WS_PUBLIC_URL=wss://battle-test.quizbattle.jp/ws
```

ポートを変えた場合は、リバースプロキシ側の転送先も同じ番号に変更してください。

## 任意: systemd の例

Linux で自動再起動したい場合は、次のような service を作れます。

```ini
[Unit]
Description=Quiz Battle WebSocket Daemon
After=network.target

[Service]
WorkingDirectory=/path/to/battle-test
ExecStart=/usr/bin/node server/ws-battle.js
Restart=always
RestartSec=3
User=www-data
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

反映コマンド:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now quiz-battle-ws
sudo systemctl status quiz-battle-ws
```
