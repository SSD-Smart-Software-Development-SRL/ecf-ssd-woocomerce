<?php
defined('ABSPATH') || exit;

class Ecf_Sequence_Manager {

    private const TABLE_SUFFIX = 'ecf_sequences';

    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Create the sequences table on plugin activation.
     */
    public static function create_table(): void {
        global $wpdb;
        $table = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ecf_type VARCHAR(5) NOT NULL,
            serie CHAR(1) NOT NULL DEFAULT 'E',
            prefix VARCHAR(13) NOT NULL,
            current_number BIGINT NOT NULL,
            range_start BIGINT NOT NULL,
            range_end BIGINT NOT NULL,
            expiration_date DATE NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add a new sequence range.
     */
    public static function add_sequence(
        string $ecf_type,
        string $serie,
        string $prefix,
        int $range_start,
        int $range_end,
        string $expiration_date,
    ): int|false {
        global $wpdb;

        return $wpdb->insert(self::get_table_name(), [
            'ecf_type' => $ecf_type,
            'serie' => $serie,
            'prefix' => $prefix,
            'current_number' => $range_start,
            'range_start' => $range_start,
            'range_end' => $range_end,
            'expiration_date' => $expiration_date,
            'is_active' => 1,
        ]);
    }

    /**
     * Claim the next eNCF number atomically.
     *
     * Uses UPDATE ... WHERE current_number <= range_end to prevent race conditions.
     * Returns the claimed eNCF string (e.g., "E320000000001") or null if exhausted.
     */
    public static function claim_next(string $ecf_type, string $serie = 'E'): ?array {
        global $wpdb;
        $table = self::get_table_name();
        $today = current_time('Y-m-d');

        // Find active, non-expired sequence for this type
        $sequence = $wpdb->get_row($wpdb->prepare(
            "SELECT id, prefix, current_number, range_end, expiration_date
             FROM {$table}
             WHERE ecf_type = %s
               AND serie = %s
               AND is_active = 1
               AND expiration_date >= %s
               AND current_number <= range_end
             ORDER BY id ASC
             LIMIT 1",
            $ecf_type,
            $serie,
            $today
        ));

        if (!$sequence) {
            return null;
        }

        // Atomic increment — check affected rows to confirm claim
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET current_number = current_number + 1
             WHERE id = %d AND current_number <= %d",
            $sequence->id,
            $sequence->range_end
        ));

        if ($affected === 0) {
            return self::claim_next($ecf_type, $serie);
        }

        $number = str_pad((string) $sequence->current_number, 10, '0', STR_PAD_LEFT);
        $encf = $sequence->prefix . $number;

        return [
            'encf' => $encf,
            'expiration_date' => $sequence->expiration_date,
        ];
    }

    /**
     * Deactivate a sequence.
     */
    public static function deactivate(int $id): void {
        global $wpdb;
        $wpdb->update(self::get_table_name(), ['is_active' => 0], ['id' => $id]);
    }

    /**
     * Get all sequences for admin display.
     */
    public static function get_all_sequences(): array {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_results(
            "SELECT *, (range_end - current_number + 1) as remaining
             FROM {$table}
             WHERE is_active = 1
             ORDER BY ecf_type, serie, id",
            ARRAY_A
        ) ?: [];
    }
}
