<?php
declare(strict_types=1);

$pdo = require __DIR__.'/../backend/config/database.php';
// Keep this migration safe to rerun by adding the submission field only when missing.
$columns = $pdo->query('SHOW COLUMNS FROM STUDENT_ASSESSMENT')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('submission_text', $columns, true)) {
    $pdo->exec('ALTER TABLE STUDENT_ASSESSMENT ADD COLUMN submission_text TEXT NULL AFTER submission_status');
    echo "Added STUDENT_ASSESSMENT.submission_text.\n";
} else {
    echo "STUDENT_ASSESSMENT.submission_text already exists.\n";
}
