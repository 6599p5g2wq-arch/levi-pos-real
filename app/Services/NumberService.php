<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Generates human-readable, collision-free document numbers.
 */
class NumberService
{
    public static function generate(PDO $pdo, string $table, string $column, string $prefix): string
    {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $column = preg_replace('/[^A-Za-z0-9_]/', '', $column);
        for ($i = 0; $i < 25; $i++) {
            $micro = explode(' ', microtime())[0];
            $fraction = substr($micro, 2, 6);
            $candidate = $prefix . '-' . date('Ymd-His') . '-' . $fraction . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $pdo->prepare('SELECT 1 FROM ' . Database::quoteIdentifier($table) . ' WHERE ' . Database::quoteIdentifier($column) . ' = :n LIMIT 1');
            $stmt->execute([':n' => $candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
        }
        throw new RuntimeException('تعذر توليد رقم مرجعي فريد. حاول مرة أخرى.');
    }
}
