<?php
final class Db
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);
CREATE TABLE IF NOT EXISTS runner_cache (
    cache_key TEXT PRIMARY KEY,
    lastname TEXT,
    firstname TEXT,
    sex TEXT,
    dateofbirth TEXT,
    runner_id TEXT,
    licence TEXT,
    palmares TEXT,
    raw_payload TEXT,
    fetched_at TEXT,
    pushed_at TEXT
);
CREATE TABLE IF NOT EXISTS sync_cursor (
    event_id TEXT PRIMARY KEY,
    cursor_index INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT
);
CREATE TABLE IF NOT EXISTS sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT,
    bib TEXT,
    status TEXT,
    message TEXT,
    created_at TEXT
);
SQL);
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    public function setSetting(string $key, ?string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO settings(key,value) VALUES(:key,:value) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    public function getCursor(string $eventId): int
    {
        $stmt = $this->pdo->prepare('SELECT cursor_index FROM sync_cursor WHERE event_id = :e');
        $stmt->execute([':e' => $eventId]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0 : max(0, (int)$v);
    }

    public function setCursor(string $eventId, int $cursorIndex): void
    {
        $stmt = $this->pdo->prepare(<<<SQL
INSERT INTO sync_cursor(event_id, cursor_index, updated_at) VALUES(:e, :i, :u)
ON CONFLICT(event_id) DO UPDATE SET cursor_index=excluded.cursor_index, updated_at=excluded.updated_at
SQL);
        $stmt->execute([':e' => $eventId, ':i' => max(0, $cursorIndex), ':u' => gmdate('c')]);
    }

    public function resetCursor(?string $eventId = null): void
    {
        if ($eventId) {
            $stmt = $this->pdo->prepare('DELETE FROM sync_cursor WHERE event_id = :e');
            $stmt->execute([':e' => $eventId]);
        } else {
            $this->pdo->exec('DELETE FROM sync_cursor');
        }
    }

    public function log(string $eventId, string $bib, string $status, string $message): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO sync_log(event_id,bib,status,message,created_at) VALUES(:e,:b,:s,:m,:c)');
        $stmt->execute([
            ':e' => $eventId,
            ':b' => $bib,
            ':s' => $status,
            ':m' => mb_substr($message, 0, 2000),
            ':c' => gmdate('c'),
        ]);
    }

    public function clearCache(): void
    {
        $this->pdo->exec('DELETE FROM runner_cache');
        $this->pdo->exec('DELETE FROM sync_cursor');
    }
}
