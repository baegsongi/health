<?php
declare(strict_types=1);

namespace Health;

use PDO;

/**
 * SQLite 한 파일. PDO 얇은 래퍼 + 마이그레이션.
 * 모든 질의는 prepared statement 로만 나간다.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static bool $readOnly = false;

    public static function path(): string
    {
        return App::storage('health.sqlite');
    }

    /**
     * DB 파일에 쓸 수 없는가.
     * 웹서버 사용자에게 storage/ 쓰기 권한이 없으면 여기가 true 가 된다 —
     * 그래도 열람 화면은 열리도록 읽기 전용으로 연다.
     */
    public static function isReadOnly(): bool
    {
        self::pdo();
        return self::$readOnly;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dir  = App::storage();
        $file = $dir . '/health.sqlite';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // is_writable() 은 NAS 의 ACL 을 제대로 못 본다. 실제로 써보고 판단한다.
        // journal_mode 는 읽기 전용에서도 조용히 통과하므로 판별에 쓸 수 없다 —
        // 쓰기 잠금을 실제로 잡는 BEGIN IMMEDIATE 로 확인한다.
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            // 잠금만으로는 부족하다(저널을 아직 안 만든다). 실제로 한 페이지를 쓰고 되돌린다.
            $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('PRAGMA user_version = ' . $version);
            $pdo->exec('ROLLBACK');
            return self::$pdo = $pdo;
        } catch (\PDOException $e) {
            if (!is_file($file)) {
                throw new \RuntimeException(
                    "storage 에 쓸 수 없습니다: $dir — 웹서버 사용자에게 읽기·쓰기 권한을 주세요.",
                    0,
                    $e
                );
            }
        }

        // 쓸 수 없다. 그래도 열람 화면은 열리도록 읽기 전용으로 연다.
        self::$readOnly = true;
        $pdo = new PDO('sqlite:file:' . $file . '?mode=ro', null, null, $opts);
        $pdo->exec('PRAGMA query_only = 1');

        return self::$pdo = $pdo;
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $out = $fn();
            $pdo->commit();
            return $out;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 마이그레이션. 파일명 순서대로 돌리고 이미 돌린 것은 건너뛴다.
     * @return array<int,string> 이번에 적용한 이름
     */
    public static function migrate(): array
    {
        $pdo = self::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
            name TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL
        )');

        $applied = [];
        foreach (self::all('SELECT name FROM migrations') as $r) {
            $applied[(string) $r['name']] = true;
        }

        $files = glob(App::root() . '/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);

        $done = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                self::run(
                    'INSERT INTO migrations (name, applied_at) VALUES (?, ?)',
                    [$name, date('c')]
                );
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new \RuntimeException("마이그레이션 실패: $name — " . $e->getMessage(), 0, $e);
            }
            $done[] = $name;
        }
        return $done;
    }
}
