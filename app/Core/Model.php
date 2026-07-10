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

    protected function findBy(string $table, string $column, mixed $value): ?array
    {
        return $this->one("SELECT * FROM $table WHERE $column = ? LIMIT 1", [$value]);
    }
}
