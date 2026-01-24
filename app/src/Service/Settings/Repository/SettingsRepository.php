<?php

declare(strict_types=1);

namespace App\Service\Settings\Repository;

use App\Service\Database\DatabaseConnection;
use DI\Attribute\Injectable;

#[Injectable]
class SettingsRepository
{
    public function __construct(
        private DatabaseConnection $db
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $result = $this->db->queryFirst(
            "SELECT value FROM settings WHERE key = ?",
            [$key]
        );

        if (!$result) {
            return $default;
        }

        return json_decode($result['value'], true);
    }

    public function set(string $key, mixed $value): void
    {
        $this->db->insert(
            "INSERT INTO settings (key, value, updated_at)
             VALUES (?, ?, NOW())
             ON CONFLICT (key) DO UPDATE
             SET value = EXCLUDED.value, updated_at = NOW()",
            [$key, json_encode($value)]
        );
    }

    public function delete(string $key): void
    {
        $this->db->delete(
            "DELETE FROM settings WHERE key = ?",
            [$key]
        );
    }

    public function all(): array
    {
        $rows = $this->db->query("SELECT key, value FROM settings");
        $settings = [];

        foreach ($rows as $row) {
            $settings[$row['key']] = json_decode($row['value'], true);
        }

        return $settings;
    }
}
