<?php
// ============================================================
//  SESSIONS IN THE DATABASE  (config/session_db.php)
// ============================================================
//  PHP normally keeps "who is logged in" in small files on the server.
//  On Railway those files live inside the container, and every update
//  (each push) starts a fresh container — so everyone was logged out
//  and any page left open stopped working ("Request blocked").
//  Keeping sessions in MySQL means an update no longer signs anyone out.
// ============================================================

class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private $pdo;
    private $loaded = [];          // what read() returned, so unchanged sessions are not rewritten

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    private function ensureTable() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS php_sessions (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            data MEDIUMBLOB,
            updated_at INT UNSIGNED NOT NULL,
            INDEX idx_updated (updated_at)
        )");
    }

    // Runs a query; creates the table the first time it is missing.
    private function run($sql, $args) {
        try {
            $st = $this->pdo->prepare($sql); $st->execute($args); return $st;
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;      // anything other than "table does not exist"
            $this->ensureTable();
            $st = $this->pdo->prepare($sql); $st->execute($args); return $st;
        }
    }

    private function maxAge() { return (int)ini_get('session.gc_maxlifetime') ?: 1440; }

    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }

    public function read($id): string|false {
        $st = $this->run("SELECT data FROM php_sessions WHERE id = ? AND updated_at >= ?", [$id, time() - $this->maxAge()]);
        $data = (string)($st->fetchColumn() ?: '');
        $this->loaded[$id] = $data;
        return $data;
    }

    public function write($id, $data): bool {
        if (($this->loaded[$id] ?? null) === $data) return $this->updateTimestamp($id, $data);
        $this->run("INSERT INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)", [$id, $data, time()]);
        $this->loaded[$id] = $data;
        return true;
    }

    public function destroy($id): bool {
        $this->run("DELETE FROM php_sessions WHERE id = ?", [$id]);
        unset($this->loaded[$id]);
        return true;
    }

    public function gc($max_lifetime): int|false {
        return $this->run("DELETE FROM php_sessions WHERE updated_at < ?", [time() - (int)$max_lifetime])->rowCount();
    }

    // With session.use_strict_mode, PHP only accepts an ID that already exists here.
    public function validateId($id): bool {
        return (bool)$this->run("SELECT 1 FROM php_sessions WHERE id = ? AND updated_at >= ?", [$id, time() - $this->maxAge()])->fetchColumn();
    }

    // Unchanged session: just keep it alive (at most once a minute, to save writes).
    public function updateTimestamp($id, $data): bool {
        $this->run("UPDATE php_sessions SET updated_at = ? WHERE id = ? AND updated_at < ?", [time(), $id, time() - 60]);
        return true;
    }
}
