<?php
declare(strict_types=1);

try {
    $pdo = require __DIR__.'/config/database.php';
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!$database) {
        throw new RuntimeException('No database selected. Check backend/.env.');
    }

    // Check that every ERD table exists before validating its relationships.
    $requiredTables = [
        'USER',
        'GUEST',
        'ROOM',
        'RESERVATION',
        'FOLIO',
        'FOLIO_ITEM',
        'ACTIVITY_LOG',
        'SCENARIO',
        'STUDENT_ASSESSMENT'
    ];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tableLookup = array_fill_keys(array_map('strtolower', $tables), true);
    $missing = array_values(array_filter(
        $requiredTables,
        static fn(string $table): bool => !isset($tableLookup[strtolower($table)])
    ));
    if ($missing) {
        throw new RuntimeException('Missing tables: '.implode(', ', $missing).'. Import database/cihrm_database.sql.');
    }

    // Match each required foreign key to the ERD and check for orphaned rows below.
    $requiredRelationships = [
        ['RESERVATION', 'guest_id', 'GUEST', 'guest_id'],
        ['RESERVATION', 'room_id', 'ROOM', 'room_id'],
        ['RESERVATION', 'user_id', 'USER', 'user_id'],
        ['FOLIO', 'reservation_id', 'RESERVATION', 'reservation_id'],
        ['FOLIO_ITEM', 'folio_id', 'FOLIO', 'folio_id'],
        ['ACTIVITY_LOG', 'user_id', 'USER', 'user_id'],
        ['STUDENT_ASSESSMENT', 'user_id', 'USER', 'user_id'],
        ['STUDENT_ASSESSMENT', 'scenario_id', 'SCENARIO', 'scenario_id']
    ];
    $foreignKeys = $pdo->query("
        SELECT table_name, column_name, referenced_table_name, referenced_column_name
        FROM information_schema.key_column_usage
        WHERE constraint_schema = DATABASE() AND referenced_table_name IS NOT NULL
    ")->fetchAll();
    $foreignKeyLookup = [];
    foreach ($foreignKeys as $foreignKey) {
        $key = strtolower(
            $foreignKey['table_name'].'.'.$foreignKey['column_name'].'>'.
            $foreignKey['referenced_table_name'].'.'.$foreignKey['referenced_column_name']
        );
        $foreignKeyLookup[$key] = true;
    }

    $missingRelationships = [];
    foreach ($requiredRelationships as [$table, $column, $parentTable, $parentColumn]) {
        $key = strtolower("{$table}.{$column}>{$parentTable}.{$parentColumn}");
        if (!isset($foreignKeyLookup[$key])) {
            $missingRelationships[] = $key;
        }
    }
    if ($missingRelationships) {
        throw new RuntimeException(
            'Missing ERD foreign keys: '.implode(', ', $missingRelationships).
            '. Import database/cihrm_database.sql or repair the schema.'
        );
    }

    foreach ($requiredRelationships as [$table, $column, $parentTable, $parentColumn]) {
        $statement = $pdo->query(
            "SELECT COUNT(*) FROM `{$table}` child
             LEFT JOIN `{$parentTable}` parent
               ON child.`{$column}` = parent.`{$parentColumn}`
             WHERE child.`{$column}` IS NOT NULL
               AND parent.`{$parentColumn}` IS NULL"
        );
        if ((int)$statement->fetchColumn() !== 0) {
            throw new RuntimeException(
                "Orphaned records found for {$table}.{$column} -> {$parentTable}.{$parentColumn}."
            );
        }
    }

    $assessmentColumns = $pdo->query('SHOW COLUMNS FROM STUDENT_ASSESSMENT')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('submission_text', $assessmentColumns, true)) {
        throw new RuntimeException(
            'Workflow schema update is missing. Run php database/workflow_migration.php from the project root.'
        );
    }

    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM `USER`')->fetchColumn();
    echo "Connected to MySQL database: {$database}\n";
    echo "Schema and ERD relationship checks passed (".count($requiredRelationships)." foreign keys, no orphaned records).\n";
    if ($userCount === 0) {
        echo "No login accounts found. Run php backend/seed.php.\n";
    } else {
        echo "User accounts: {$userCount}\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "DATABASE CHECK FAILED: {$error->getMessage()}\n");
    exit(1);
}
