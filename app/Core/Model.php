<?php
namespace App\Core;

class Model
{
    protected \PDO $db;
    protected string $table = '';

    public function __construct() { $this->db = Database::connection(); }

    protected function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    protected function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    protected function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    protected function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->db->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return (int) $this->db->lastInsertId();
    }

    protected function update(string $table, array $data, string $whereColumn, mixed $whereValue): bool
    {
        $columns = array_keys($data);
        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $sql = "UPDATE $table SET $set WHERE $whereColumn = :__where";
        $stmt = $this->db->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':__where', $whereValue);
        return $stmt->execute();
    }

    /**
     * Optimistic-concurrency update: only writes if the row's updated_at
     * still matches what the editor last saw (snapshotted into a hidden
     * field when the edit form was loaded). Returns false when the row was
     * already changed by someone else since then -- 0 rows affected, not an
     * error -- so the caller can tell the user to refresh instead of
     * silently overwriting a newer version.
     */
    protected function updateOptimistic(string $table, array $data, int $id, string $expectedUpdatedAt): bool
    {
        $columns = array_keys($data);
        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $sql = "UPDATE $table SET $set WHERE id = :__id AND updated_at = :__expected";
        $stmt = $this->db->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':__id', $id);
        $stmt->bindValue(':__expected', $expectedUpdatedAt);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Runs $fn inside a real DB transaction on the shared connection --
     * commits on success, rolls back (including anything $fn already wrote,
     * such as an Idempotency::begin() PENDING row) on any exception, then
     * rethrows. Safe to call from any Model subclass: Database::connection()
     * is one PDO singleton per request, so a transaction opened via one
     * model instance transparently covers writes made through any other
     * model instantiated afterward in the same request.
     */
    public function transaction(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $fn($this->db);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    protected function findBy(string $table, string $column, mixed $value): ?array
    {
        return $this->one("SELECT * FROM $table WHERE $column = ? LIMIT 1", [$value]);
    }
}
