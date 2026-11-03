<?php
declare(strict_types=1);

$pdo = require __DIR__.'/config/database.php';

// These accounts are for local demonstrations only; running this script resets their passwords.
$accounts = [
    ['U-ADMIN-001', 'System Administrator', 'admin@cihrm.local', 'admin'],
    ['U-INST-001', 'Demo Instructor', 'instructor@cihrm.local', 'instructor'],
    ['U-STUD-001', 'Demo Student', 'student@cihrm.local', 'student']
];
$hash = password_hash('Password123!', PASSWORD_DEFAULT);
$statement = $pdo->prepare("
    INSERT INTO `USER` (user_id,full_name,email,password,role,department,year_level)
    VALUES (?,?,?,?,?,'Hospitality Management','3rd Year')
    ON DUPLICATE KEY UPDATE
      full_name=VALUES(full_name),
      email=VALUES(email),
      password=VALUES(password),
      role=VALUES(role),
      department=VALUES(department),
      year_level=VALUES(year_level),
      is_active=TRUE");

foreach ($accounts as [$id, $name, $email, $role]) {
    $statement->execute([$id, $name, $email, $hash, $role]);
}

echo "Demo accounts are ready. Password for all: Password123!\n";
