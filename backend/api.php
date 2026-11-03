<?php
declare(strict_types=1);

class ApiException extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

// Send a JSON response and stop processing the current request.
function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiException('Request body must be valid JSON.', 400);
    }
    return $data;
}

function require_login(): array
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        throw new ApiException('Please sign in.', 401);
    }
    return $_SESSION['user'];
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        throw new ApiException('You do not have permission for this action.', 403);
    }
    return $user;
}

// Record user-visible actions so activity pages and reports stay consistent.
function log_action(PDO $pdo, string $userId, string $action): void
{
    $statement = $pdo->prepare('INSERT INTO ACTIVITY_LOG (user_id, action) VALUES (?, ?)');
    $statement->execute([$userId, $action]);
}

function add_display_user_ids(PDO $pdo, array $rows): array
{
    $userIds = $pdo->query(
        'SELECT user_id FROM `USER` ORDER BY created_at, user_id'
    )->fetchAll(PDO::FETCH_COLUMN);
    $displayIds = array_flip($userIds);
    foreach ($rows as &$row) {
        if (!isset($displayIds[$row['user_id']])) {
            throw new RuntimeException('Unable to assign a display ID to a user.');
        }
        $row['display_id'] = $displayIds[$row['user_id']] + 1;
    }
    unset($row);
    return $rows;
}

function get_display_user_id(PDO $pdo, string $userId): int
{
    $rows = add_display_user_ids($pdo, [['user_id' => $userId]]);
    return $rows[0]['display_id'];
}

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$isLocalDevelopmentOrigin = preg_match(
    '#^http://(?:localhost|127\.0\.0\.1)(?::[0-9]{1,5})?$#D',
    $origin
) === 1;
if ($isLocalDevelopmentOrigin) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code($isLocalDevelopmentOrigin ? 204 : 403);
    exit;
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function recalculate_folio(PDO $pdo, string $folioId): void
{
    $statement = $pdo->prepare(
        'SELECT f.discount_type, f.discount_amount, f.tax_rate, f.payment_status,
                COALESCE(SUM(i.amount), 0) AS subtotal
         FROM FOLIO f LEFT JOIN FOLIO_ITEM i ON i.folio_id=f.folio_id
         WHERE f.folio_id=? GROUP BY f.folio_id'
    );
    $statement->execute([$folioId]);
    $folio = $statement->fetch();
    if (!$folio) {
        throw new ApiException('Folio not found.', 404);
    }

    $subtotal = round((float)$folio['subtotal'], 2);
    $discountAmount = round((float)$folio['discount_amount'], 2);
    if ($folio['discount_type'] === 'Percent') {
        $discountAmount = round($subtotal * min($discountAmount, 100) / 100, 2);
    } elseif ($folio['discount_type'] !== 'Fixed') {
        $discountAmount = 0;
    }
    $discountAmount = min($discountAmount, $subtotal);
    $taxable = max(0, $subtotal - $discountAmount);
    $taxAmount = round($taxable * (float)$folio['tax_rate'] / 100, 2);
    $grandTotal = round($taxable + $taxAmount, 2);
    $update = $pdo->prepare(
        'UPDATE FOLIO SET subtotal=?, tax_amount=?, grand_total=? WHERE folio_id=?'
    );
    $update->execute([$subtotal, $taxAmount, $grandTotal, $folioId]);
}

function create_folio(PDO $pdo, string $reservationId, string $roomNumber, float $nightlyRate, int $nights): void
{
    $folioId = 'FO-'.strtoupper(bin2hex(random_bytes(6)));
    $pdo->prepare('INSERT INTO FOLIO (folio_id, reservation_id) VALUES (?, ?)')
        ->execute([$folioId, $reservationId]);
    $pdo->prepare(
        'INSERT INTO FOLIO_ITEM (folio_id, description, category, amount) VALUES (?, ?, ?, ?)'
    )->execute([
        $folioId,
        "Room {$roomNumber} ({$nights} night".($nights === 1 ? '' : 's').')',
        'Room',
        round($nightlyRate * $nights, 2)
    ]);
    recalculate_folio($pdo, $folioId);
}

try {
    $pdo = require __DIR__.'/config/database.php';
    date_default_timezone_set('Asia/Manila');
    session_set_cookie_params([
        'lifetime' => 8 * 60 * 60,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();

    $route = trim((string)($_GET['route'] ?? ''), '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $data = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? request_data() : [];

    // Authentication endpoints manage the PHP session and student registration.
    if ($route === 'auth/register' && $method === 'POST') {
        $name = trim((string)($data['full_name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        if ($name === '' || strlen($name) > 150) {
            throw new ApiException('Enter your name (up to 150 characters).', 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new ApiException('Enter a valid email address (up to 190 characters).', 400);
        }
        if (strlen($password) < 8) {
            throw new ApiException('Password must be at least 8 characters.', 400);
        }

        $userId = 'U-'.strtoupper(bin2hex(random_bytes(6)));
        $statement = $pdo->prepare(
            'INSERT INTO `USER` (user_id, full_name, email, password, role)
             VALUES (?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([
                $userId,
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                'student'
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new ApiException('An account with this email already exists. Sign in instead.', 409);
            }
            throw $error;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'user_id' => $userId,
            'full_name' => $name,
            'email' => $email,
            'role' => 'student',
            'department' => null,
            'year_level' => null
        ];
        log_action($pdo, $userId, 'Created student account');
        $user = $_SESSION['user'];
        $user['display_id'] = get_display_user_id($pdo, $userId);
        respond(['user' => $user], 201);
    }

    if ($route === 'auth/login' && $method === 'POST') {
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new ApiException('Email and password are required.', 400);
        }

        $statement = $pdo->prepare(
            'SELECT user_id, full_name, email, password, role, department, year_level, is_active
             FROM `USER` WHERE email = ?'
        );
        $statement->execute([$email]);
        $account = $statement->fetch();
        if (!$account || !(int)$account['is_active'] || !password_verify($password, $account['password'])) {
            throw new ApiException('Invalid credentials or inactive account.', 401);
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'user_id' => $account['user_id'],
            'full_name' => $account['full_name'],
            'email' => $account['email'],
            'role' => $account['role'],
            'department' => $account['department'],
            'year_level' => $account['year_level']
        ];
        log_action($pdo, $account['user_id'], 'Logged in');
        $user = $_SESSION['user'];
        $user['display_id'] = get_display_user_id($pdo, $account['user_id']);
        respond(['user' => $user]);
    }

    if ($route === 'auth/logout' && $method === 'POST') {
        $user = require_login();
        log_action($pdo, $user['user_id'], 'Logged out');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }
        session_destroy();
        respond(['message' => 'Logged out.']);
    }

    if ($route === 'auth/me' && $method === 'GET') {
        $user = require_login();
        $user['display_id'] = get_display_user_id($pdo, $user['user_id']);
        respond(['user' => $user]);
    }

    // Dashboard counts and room/guest endpoints supply front-desk inventory.
    if ($route === 'dashboard' && $method === 'GET') {
        $user = require_login();
        $roomStats = $pdo->query("
            SELECT COUNT(*) totalRooms,
            SUM(occupancy_status='Available' AND housekeeping_status='Clean') availableRooms,
            SUM(occupancy_status='Occupied') occupiedRooms FROM ROOM")->fetch();
        $reservationStats = $pdo->query("
            SELECT COUNT(*) totalReservations,
            SUM(reservation_status='Pending') pendingReservations,
            SUM(reservation_status='Checked-in') checkedIn,
            SUM(reservation_status='Checked-out') checkedOut FROM RESERVATION")->fetch();
        $userStats = $pdo->query("
            SELECT COUNT(*) totalUsers,
            SUM(role='student') students, SUM(role='instructor') instructors
            FROM `USER` WHERE is_active=1")->fetch();
        $recent = $pdo->query("
            SELECT r.reservation_id, g.full_name guest_name, rm.room_number,
            r.check_in_date, r.check_out_date, r.reservation_status
            FROM RESERVATION r JOIN GUEST g ON g.guest_id=r.guest_id
            JOIN ROOM rm ON rm.room_id=r.room_id
            ORDER BY r.created_at DESC LIMIT 8")->fetchAll();
        respond([
            'rooms' => $roomStats,
            'reservations' => $reservationStats,
            'users' => $userStats,
            'recent' => $recent,
            'role' => $user['role']
        ]);
    }

    if ($route === 'rooms' && $method === 'GET') {
        require_login();
        respond($pdo->query('SELECT * FROM ROOM ORDER BY room_number')->fetchAll());
    }

    if ($route === 'rooms' && $method === 'POST') {
        $user = require_role(['admin']);
        $roomNumber = trim((string)($data['room_number'] ?? ''));
        $roomType = trim((string)($data['room_type'] ?? ''));
        $rate = filter_var($data['rate'] ?? null, FILTER_VALIDATE_FLOAT);
        $occupancy = filter_var($data['max_occupancy'] ?? null, FILTER_VALIDATE_INT);
        if ($roomNumber === '' || $roomType === '' || $rate === false || $rate < 0 || $occupancy === false || $occupancy < 1) {
            throw new ApiException('Complete all required room fields.', 400);
        }
        $roomId = 'RM-'.strtoupper(bin2hex(random_bytes(6)));
        $statement = $pdo->prepare(
            'INSERT INTO ROOM (room_id, room_number, room_type, floor, rate, max_occupancy)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        try {
            $statement->execute([$roomId, $roomNumber, $roomType, (int)($data['floor'] ?? 1), $rate, $occupancy]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new ApiException('Room number already exists.', 400);
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Created room {$roomNumber}");
        respond(['message' => 'Room created.'], 201);
    }

    if (preg_match('#^rooms/([^/]+)$#', $route, $matches) && $method === 'PUT') {
        $user = require_role(['admin']);
        $roomId = $matches[1];
        $statement = $pdo->prepare(
            'UPDATE ROOM SET room_type=?, floor=?, rate=?, max_occupancy=?,
             housekeeping_status=?, occupancy_status=? WHERE room_id=?'
        );
        $statement->execute([
            $data['room_type'] ?? '',
            (int)($data['floor'] ?? 1),
            (float)($data['rate'] ?? 0),
            (int)($data['max_occupancy'] ?? 1),
            $data['housekeeping_status'] ?? 'Clean',
            $data['occupancy_status'] ?? 'Available',
            $roomId
        ]);
        log_action($pdo, $user['user_id'], "Updated room {$roomId}");
        respond(['message' => 'Room updated.']);
    }

    if (preg_match('#^rooms/([^/]+)$#', $route, $matches) && $method === 'DELETE') {
        $user = require_role(['admin']);
        $roomId = $matches[1];
        try {
            $statement = $pdo->prepare('DELETE FROM ROOM WHERE room_id=?');
            $statement->execute([$roomId]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new ApiException('Room cannot be deleted because it is linked to a reservation.', 409);
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Deleted room {$roomId}");
        respond(['message' => 'Room deleted.']);
    }

    if ($route === 'guests' && $method === 'GET') {
        require_login();
        respond($pdo->query('SELECT * FROM GUEST ORDER BY full_name')->fetchAll());
    }

    if ($route === 'guests' && $method === 'POST') {
        $user = require_login();
        $name = trim((string)($data['full_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            throw new ApiException('Guest name and phone are required.', 400);
        }
        $statement = $pdo->prepare(
            'INSERT INTO GUEST (guest_id, full_name, email, phone, address, id_type, preferences)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            'G-'.strtoupper(bin2hex(random_bytes(6))),
            $name,
            trim((string)($data['email'] ?? '')) ?: null,
            $phone,
            trim((string)($data['address'] ?? '')) ?: null,
            trim((string)($data['id_type'] ?? '')) ?: null,
            trim((string)($data['preferences'] ?? '')) ?: null
        ]);
        log_action($pdo, $user['user_id'], "Registered guest {$name}");
        respond(['message' => 'Guest registered.'], 201);
    }

    if (preg_match('#^guests/([^/]+)$#', $route, $matches) && $method === 'PUT') {
        $user = require_login();
        $guestId = $matches[1];
        $statement = $pdo->prepare(
            'UPDATE GUEST SET full_name=?, email=?, phone=?, address=?, id_type=?, preferences=? WHERE guest_id=?'
        );
        $statement->execute([
            $data['full_name'] ?? '',
            $data['email'] ?? null,
            $data['phone'] ?? '',
            $data['address'] ?? null,
            $data['id_type'] ?? null,
            $data['preferences'] ?? null,
            $guestId
        ]);
        log_action($pdo, $user['user_id'], "Updated guest {$guestId}");
        respond(['message' => 'Guest updated.']);
    }

    // Reservation changes are transactional so booking and room status stay aligned.
    if ($route === 'reservations' && $method === 'GET') {
        require_login();
        $rows = $pdo->query("
            SELECT r.*, g.full_name guest_name, rm.room_number, rm.room_type, rm.rate room_rate
            FROM RESERVATION r JOIN GUEST g ON g.guest_id=r.guest_id
            JOIN ROOM rm ON rm.room_id=r.room_id ORDER BY r.created_at DESC")->fetchAll();
        respond($rows);
    }

    if ($route === 'reservations' && $method === 'POST') {
        $user = require_login();
        $guestId = trim((string)($data['guest_id'] ?? ''));
        $roomId = trim((string)($data['room_id'] ?? ''));
        $checkIn = (string)($data['check_in_date'] ?? '');
        $checkOut = (string)($data['check_out_date'] ?? '');
        $pax = filter_var($data['pax'] ?? null, FILTER_VALIDATE_INT);
        if ($guestId === '' || $roomId === '' || $pax === false || $pax < 1 || !valid_date($checkIn) || !valid_date($checkOut)) {
            throw new ApiException('Complete all reservation fields with valid dates and guest count.', 400);
        }
        if ($checkOut <= $checkIn) {
            throw new ApiException('Check-out must be after check-in.', 400);
        }

        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT * FROM ROOM WHERE room_id=? FOR UPDATE');
            $statement->execute([$roomId]);
            $room = $statement->fetch();
            if (!$room) {
                throw new ApiException('Room not found.', 400);
            }
            if ($room['housekeeping_status'] !== 'Clean' || $room['occupancy_status'] !== 'Available' ||
                (int)$room['max_occupancy'] < $pax) {
                throw new ApiException('Room is not ready or guest count exceeds room capacity.', 400);
            }
            $statement = $pdo->prepare("
                SELECT reservation_id FROM RESERVATION
                WHERE room_id=? AND reservation_status IN ('Pending','Confirmed','Checked-in')
                AND check_in_date < ? AND check_out_date > ? FOR UPDATE");
            $statement->execute([$roomId, $checkOut, $checkIn]);
            if ($statement->fetch()) {
                throw new ApiException('This room is already reserved for overlapping dates.', 400);
            }
            $checkInDate = new DateTimeImmutable($checkIn);
            $checkOutDate = new DateTimeImmutable($checkOut);
            $nights = (int)$checkInDate->diff($checkOutDate)->days;
            $reservationId = 'RS-'.strtoupper(bin2hex(random_bytes(6)));
            $statement = $pdo->prepare("
                INSERT INTO RESERVATION
                (reservation_id, guest_id, room_id, user_id, booking_type, check_in_date,
                 check_out_date, number_of_nights, pax, reservation_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed')");
            $statement->execute([
                $reservationId,
                $guestId,
                $roomId,
                $user['user_id'],
                $data['booking_type'] ?? 'Advance',
                $checkIn,
                $checkOut,
                $nights,
                $pax
            ]);
            create_folio($pdo, $reservationId, $room['room_number'], (float)$room['rate'], $nights);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Created reservation {$reservationId}");
        respond(['message' => 'Reservation confirmed.', 'reservation_id' => $reservationId], 201);
    }

    if (preg_match('#^reservations/([^/]+)/check-in$#', $route, $matches) && $method === 'POST') {
        $user = require_login();
        $reservationId = $matches[1];
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT r.*, rm.occupancy_status, rm.housekeeping_status FROM RESERVATION r
                 JOIN ROOM rm ON rm.room_id=r.room_id WHERE r.reservation_id=? FOR UPDATE'
            );
            $statement->execute([$reservationId]);
            $reservation = $statement->fetch();
            if (!$reservation) {
                throw new ApiException('Reservation not found.', 404);
            }
            if (!in_array($reservation['reservation_status'], ['Pending', 'Confirmed'], true)) {
                throw new ApiException('Only pending or confirmed reservations can be checked in.', 409);
            }
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            if ($today < $reservation['check_in_date'] || $today >= $reservation['check_out_date']) {
                throw new ApiException('Check-in is only available during the reservation dates.', 409);
            }
            if ($reservation['occupancy_status'] !== 'Available') {
                throw new ApiException('The assigned room is not available for check-in.', 409);
            }
            if ($reservation['housekeeping_status'] !== 'Clean') {
                throw new ApiException('The assigned room must be clean before check-in.', 409);
            }
            $pdo->prepare("UPDATE RESERVATION SET reservation_status='Checked-in' WHERE reservation_id=?")
                ->execute([$reservationId]);
            $pdo->prepare("UPDATE ROOM SET occupancy_status='Occupied' WHERE room_id=?")
                ->execute([$reservation['room_id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Checked in reservation {$reservationId}");
        respond(['message' => 'Guest checked in.']);
    }

    if (preg_match('#^reservations/([^/]+)/check-out$#', $route, $matches) && $method === 'POST') {
        $user = require_login();
        $reservationId = $matches[1];
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT reservation_id, room_id, reservation_status FROM RESERVATION
                 WHERE reservation_id=? FOR UPDATE'
            );
            $statement->execute([$reservationId]);
            $reservation = $statement->fetch();
            if (!$reservation) {
                throw new ApiException('Reservation not found.', 404);
            }
            if ($reservation['reservation_status'] !== 'Checked-in') {
                throw new ApiException('Only checked-in guests can check out.', 409);
            }
            $pdo->prepare("UPDATE RESERVATION SET reservation_status='Checked-out' WHERE reservation_id=?")
                ->execute([$reservationId]);
            $pdo->prepare(
                "UPDATE ROOM SET occupancy_status='Available', housekeeping_status='Dirty' WHERE room_id=?"
            )->execute([$reservation['room_id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Checked out reservation {$reservationId}");
        respond(['message' => 'Guest checked out. Room marked dirty for housekeeping.']);
    }

    if (preg_match('#^reservations/([^/]+)/cancel$#', $route, $matches) && $method === 'POST') {
        $user = require_login();
        $reservationId = $matches[1];
        $pdo->beginTransaction();
        try {
            $folioStatement = $pdo->prepare(
                'SELECT f.payment_status FROM FOLIO f
                 JOIN RESERVATION r ON r.reservation_id=f.reservation_id
                 WHERE r.reservation_id=? FOR UPDATE'
            );
            $folioStatement->execute([$reservationId]);
            if ($folioStatement->fetchColumn() === 'Paid') {
                throw new ApiException('A reservation with a paid folio cannot be cancelled.', 409);
            }
            $statement = $pdo->prepare(
                "UPDATE RESERVATION SET reservation_status='Cancelled'
                 WHERE reservation_id=? AND reservation_status IN ('Pending','Confirmed')"
            );
            $statement->execute([$reservationId]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException('Only pending or confirmed reservations can be cancelled.', 409);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Cancelled reservation {$reservationId}");
        respond(['message' => 'Reservation cancelled.']);
    }

    // Housekeeping and folio routes manage room readiness and simulated charges.
    if ($route === 'housekeeping' && $method === 'GET') {
        require_login();
        $rows = $pdo->query("
            SELECT rm.*, r.reservation_id, g.full_name guest_name, r.check_out_date
            FROM ROOM rm
            LEFT JOIN RESERVATION r ON r.room_id=rm.room_id AND r.reservation_status='Checked-in'
            LEFT JOIN GUEST g ON g.guest_id=r.guest_id
            ORDER BY rm.floor, rm.room_number")->fetchAll();
        respond($rows);
    }

    if (preg_match('#^housekeeping/([^/]+)$#', $route, $matches) && $method === 'PUT') {
        $user = require_login();
        $roomId = $matches[1];
        $status = (string)($data['housekeeping_status'] ?? '');
        if (!in_array($status, ['Clean', 'Dirty', 'Cleaning', 'Maintenance'], true)) {
            throw new ApiException('Choose Clean, Dirty, Cleaning, or Maintenance.', 400);
        }
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT occupancy_status FROM ROOM WHERE room_id=? FOR UPDATE');
            $statement->execute([$roomId]);
            $room = $statement->fetch();
            if (!$room) {
                throw new ApiException('Room not found.', 404);
            }
            if ($room['occupancy_status'] === 'Occupied' && in_array($status, ['Clean', 'Maintenance'], true)) {
                throw new ApiException('An occupied room cannot be marked clean or under maintenance.', 409);
            }
            $pdo->prepare('UPDATE ROOM SET housekeeping_status=? WHERE room_id=?')->execute([$status, $roomId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Changed room {$roomId} housekeeping status to {$status}");
        respond(['message' => "Room housekeeping status changed to {$status}."]);
    }

    if ($route === 'folios' && $method === 'GET') {
        require_login();
        $rows = $pdo->query("
            SELECT f.*, r.reservation_id, r.reservation_status, r.check_in_date, r.check_out_date,
                   g.full_name guest_name, rm.room_number, rm.room_type
            FROM FOLIO f JOIN RESERVATION r ON r.reservation_id=f.reservation_id
            JOIN GUEST g ON g.guest_id=r.guest_id
            JOIN ROOM rm ON rm.room_id=r.room_id
            ORDER BY r.created_at DESC")->fetchAll();
        $items = $pdo->query('SELECT * FROM FOLIO_ITEM ORDER BY item_id')->fetchAll();
        $itemsByFolio = [];
        foreach ($items as $item) {
            $itemsByFolio[$item['folio_id']][] = $item;
        }
        foreach ($rows as &$folio) {
            $folio['items'] = $itemsByFolio[$folio['folio_id']] ?? [];
        }
        unset($folio);
        respond($rows);
    }

    if (preg_match('#^folios/([^/]+)/items$#', $route, $matches) && $method === 'POST') {
        $user = require_login();
        $folioId = $matches[1];
        $description = trim((string)($data['description'] ?? ''));
        $category = trim((string)($data['category'] ?? ''));
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($description === '' || strlen($description) > 255 || $category === '' ||
            strlen($category) > 80 || $amount === false || $amount < 0) {
            throw new ApiException('Enter a description, category, and non-negative amount.', 400);
        }
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT f.payment_status, r.reservation_status FROM FOLIO f
                 JOIN RESERVATION r ON r.reservation_id=f.reservation_id WHERE f.folio_id=? FOR UPDATE'
            );
            $statement->execute([$folioId]);
            $folio = $statement->fetch();
            if (!$folio) {
                throw new ApiException('Folio not found.', 404);
            }
            if ($folio['reservation_status'] === 'Cancelled') {
                throw new ApiException('Charges cannot be added to a cancelled reservation.', 409);
            }
            if ($folio['payment_status'] === 'Paid') {
                throw new ApiException('A paid folio cannot be changed.', 409);
            }
            $pdo->prepare(
                'INSERT INTO FOLIO_ITEM (folio_id, description, category, amount) VALUES (?, ?, ?, ?)'
            )->execute([$folioId, $description, $category, round($amount, 2)]);
            recalculate_folio($pdo, $folioId);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Added a charge to folio {$folioId}");
        respond(['message' => 'Charge added to folio.'], 201);
    }

    if (preg_match('#^folios/([^/]+)$#', $route, $matches) && $method === 'PUT') {
        $user = require_login();
        $folioId = $matches[1];
        $discountType = (string)($data['discount_type'] ?? '');
        $discountAmount = filter_var($data['discount_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $taxRate = filter_var($data['tax_rate'] ?? 0, FILTER_VALIDATE_FLOAT);
        if (!in_array($discountType, ['', 'Fixed', 'Percent'], true) ||
            $discountAmount === false || $discountAmount < 0 ||
            $taxRate === false || $taxRate < 0 || $taxRate > 100 ||
            ($discountType === 'Percent' && $discountAmount > 100)) {
            throw new ApiException('Enter a valid discount and tax rate (0–100%).', 400);
        }
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT f.payment_status, r.reservation_status FROM FOLIO f
                 JOIN RESERVATION r ON r.reservation_id=f.reservation_id WHERE f.folio_id=? FOR UPDATE'
            );
            $statement->execute([$folioId]);
            $folio = $statement->fetch();
            if (!$folio) {
                throw new ApiException('Folio not found.', 404);
            }
            if ($folio['reservation_status'] === 'Cancelled') {
                throw new ApiException('A cancelled reservation folio cannot be changed.', 409);
            }
            if ($folio['payment_status'] === 'Paid') {
                throw new ApiException('A paid folio cannot be changed.', 409);
            }
            $pdo->prepare(
                'UPDATE FOLIO SET discount_type=?, discount_amount=?, tax_rate=? WHERE folio_id=?'
            )->execute([$discountType ?: null, round($discountAmount, 2), round($taxRate, 2), $folioId]);
            recalculate_folio($pdo, $folioId);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Updated discounts or tax on folio {$folioId}");
        respond(['message' => 'Folio totals updated.']);
    }

    if (preg_match('#^folios/([^/]+)/payment$#', $route, $matches) && $method === 'POST') {
        $user = require_login();
        $folioId = $matches[1];
        $status = (string)($data['payment_status'] ?? '');
        if (!in_array($status, ['Unpaid', 'Paid'], true)) {
            throw new ApiException('Payment status must be Unpaid or Paid.', 400);
        }
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT f.grand_total, f.payment_status, r.reservation_status
                 FROM FOLIO f JOIN RESERVATION r ON r.reservation_id=f.reservation_id
                 WHERE f.folio_id=? FOR UPDATE'
            );
            $statement->execute([$folioId]);
            $folio = $statement->fetch();
            if (!$folio) {
                throw new ApiException('Folio not found.', 404);
            }
            if ($folio['reservation_status'] === 'Cancelled') {
                throw new ApiException('Cancelled reservations cannot be marked paid.', 409);
            }
            if ($folio['payment_status'] === 'Paid' && $status === 'Paid') {
                throw new ApiException('This folio is already marked paid.', 409);
            }
            $pdo->prepare('UPDATE FOLIO SET payment_status=? WHERE folio_id=?')->execute([$status, $folioId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Marked folio {$folioId} {$status} (simulated payment)");
        respond(['message' => "Folio marked {$status}. No real payment was processed."]);
    }

    // Activity and reports are read-only views of recorded actions and progress.
    if ($route === 'activity' && $method === 'GET') {
        $user = require_login();
        $sql = "
            SELECT a.*, u.full_name FROM ACTIVITY_LOG a
            JOIN `USER` u ON u.user_id=a.user_id";
        $parameters = [];
        if ($user['role'] === 'student') {
            $sql .= ' WHERE a.user_id=?';
            $parameters[] = $user['user_id'];
        }
        $sql .= ' ORDER BY a.timestamp DESC LIMIT 100';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        respond($rows);
    }

    if ($route === 'reports' && $method === 'GET') {
        require_role(['admin', 'instructor']);
        $summary = $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM `USER` WHERE role='student') students,
                (SELECT COUNT(*) FROM SCENARIO) scenarios,
                COUNT(*) submissions,
                SUM(a.submission_status='Submitted') pending_reviews,
                SUM(a.submission_status='Graded') graded,
                ROUND(AVG(CASE WHEN a.submission_status='Graded'
                    THEN a.score / NULLIF(s.target_points, 0) * 100 END), 1) average_percent
            FROM STUDENT_ASSESSMENT a
            JOIN SCENARIO s ON s.scenario_id=a.scenario_id")->fetch();
        $assessments = $pdo->query("
            SELECT a.assessment_id, u.full_name student_name, u.email,
                   s.title scenario_title, a.submission_status, a.score,
                   s.target_points, a.completed_at
            FROM STUDENT_ASSESSMENT a
            JOIN `USER` u ON u.user_id=a.user_id
            JOIN SCENARIO s ON s.scenario_id=a.scenario_id
            ORDER BY a.completed_at DESC, a.assessment_id DESC")->fetchAll();
        $activity = $pdo->query("
            SELECT a.timestamp, u.full_name, a.action
            FROM ACTIVITY_LOG a JOIN `USER` u ON u.user_id=a.user_id
            ORDER BY a.timestamp DESC LIMIT 200")->fetchAll();
        respond(['summary' => $summary, 'assessments' => $assessments, 'activity' => $activity]);
    }

    if ($route === 'instructor/members' && $method === 'GET') {
        require_role(['instructor']);
        $students = $pdo->query("
            SELECT user_id, full_name, email, department, year_level, is_active, created_at
            FROM `USER` WHERE role='student' ORDER BY full_name")->fetchAll();
        $students = add_display_user_ids($pdo, $students);
        $assessments = $pdo->query("
            SELECT a.user_id, s.title scenario_title, a.submission_status, a.submission_text,
                   a.score, s.target_points, a.feedback, a.completed_at
            FROM STUDENT_ASSESSMENT a
            JOIN SCENARIO s ON s.scenario_id=a.scenario_id
            JOIN `USER` u ON u.user_id=a.user_id
            WHERE u.role='student'
            ORDER BY a.user_id, a.completed_at DESC")->fetchAll();
        $activities = $pdo->query("
            SELECT a.user_id, a.action, a.timestamp
            FROM ACTIVITY_LOG a JOIN `USER` u ON u.user_id=a.user_id
            WHERE u.role='student' ORDER BY a.timestamp DESC")->fetchAll();
        $reservations = $pdo->query("
            SELECT r.user_id, r.reservation_id, g.full_name guest_name, rm.room_number,
                   r.check_in_date, r.check_out_date, r.reservation_status, r.created_at
            FROM RESERVATION r
            JOIN `USER` u ON u.user_id=r.user_id
            JOIN GUEST g ON g.guest_id=r.guest_id
            JOIN ROOM rm ON rm.room_id=r.room_id
            WHERE u.role='student' ORDER BY r.created_at DESC")->fetchAll();
        $dataByStudent = [];
        foreach ($students as $student) {
            $student['assessments'] = [];
            $student['activity'] = [];
            $student['reservations'] = [];
            $dataByStudent[$student['user_id']] = $student;
        }
        foreach ($assessments as $assessment) {
            $dataByStudent[$assessment['user_id']]['assessments'][] = $assessment;
        }
        foreach ($activities as $activity) {
            $dataByStudent[$activity['user_id']]['activity'][] = $activity;
        }
        foreach ($reservations as $reservation) {
            $dataByStudent[$reservation['user_id']]['reservations'][] = $reservation;
        }
        respond(array_values($dataByStudent));
    }

    // Administrative and learning endpoints enforce role permissions server-side.
    if ($route === 'admin/users' && $method === 'GET') {
        require_role(['admin']);
        $rows = $pdo->query("
            SELECT user_id,full_name,email,role,department,year_level,is_active,created_at
            FROM `USER` ORDER BY created_at DESC")->fetchAll();
        respond(add_display_user_ids($pdo, $rows));
    }

    if ($route === 'admin/users' && $method === 'POST') {
        $user = require_role(['admin']);
        $name = trim((string)($data['full_name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $role = (string)($data['role'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 ||
            !in_array($role, ['student', 'instructor', 'admin'], true)) {
            throw new ApiException('Provide name, valid email, password of at least 8 characters, and a valid role.', 400);
        }
        $statement = $pdo->prepare("
            INSERT INTO `USER` (user_id,full_name,email,password,role,department,year_level)
            VALUES (?,?,?,?,?,?,?)");
        try {
            $statement->execute([
                'U-'.strtoupper(bin2hex(random_bytes(6))),
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $data['department'] ?? null,
                $data['year_level'] ?? null
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new ApiException('Could not create account. Email may already exist.', 400);
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Created {$role} account {$email}");
        respond(['message' => 'Account created.'], 201);
    }

    if ($route === 'scenarios' && $method === 'POST') {
        $user = require_role(['admin', 'instructor']);
        $title = trim((string)($data['title'] ?? ''));
        $difficulty = (string)($data['difficulty'] ?? '');
        $objectives = trim((string)($data['learning_objectives'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $instructions = trim((string)($data['instructions'] ?? ''));
        $targetPoints = filter_var($data['target_points'] ?? null, FILTER_VALIDATE_INT);
        if ($title === '' || strlen($title) > 180 ||
            !in_array($difficulty, ['Beginner', 'Intermediate', 'Advanced'], true) ||
            $objectives === '' || strlen($objectives) > 20000 ||
            $description === '' || strlen($description) > 20000 ||
            $instructions === '' || strlen($instructions) > 20000 ||
            $targetPoints === false || $targetPoints < 1 || $targetPoints > 10000) {
            throw new ApiException('Enter a title, valid difficulty, learning objectives, description, instructions, and target points from 1 to 10,000.', 400);
        }
        $scenarioId = 'SC-'.strtoupper(bin2hex(random_bytes(6)));
        $statement = $pdo->prepare("
            INSERT INTO SCENARIO
                (scenario_id, title, difficulty, learning_objectives, target_points,
                 description, instructions, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $statement->execute([
            $scenarioId, $title, $difficulty, $objectives, $targetPoints,
            $description, $instructions, $user['user_id']
        ]);
        log_action($pdo, $user['user_id'], "Created learning scenario {$scenarioId}");
        respond(['message' => 'Learning scenario created.', 'scenario_id' => $scenarioId], 201);
    }

    if ($route === 'scenarios' && $method === 'GET') {
        $user = require_login();
        $statement = $pdo->prepare("
            SELECT s.*, a.assessment_id, a.score, a.submission_status, a.submission_text,
                   a.feedback, a.completed_at
            FROM SCENARIO s
            LEFT JOIN STUDENT_ASSESSMENT a ON a.scenario_id=s.scenario_id AND a.user_id=?
            ORDER BY s.scenario_id");
        $statement->execute([$user['user_id']]);
        respond($statement->fetchAll());
    }

    if (preg_match('#^scenarios/([^/]+)/start$#', $route, $matches) && $method === 'POST') {
        $user = require_login();
        $scenarioId = $matches[1];
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT scenario_id FROM SCENARIO WHERE scenario_id=? FOR UPDATE');
            $statement->execute([$scenarioId]);
            if (!$statement->fetch()) {
                throw new ApiException('Learning scenario not found.', 404);
            }
            $statement = $pdo->prepare(
                'SELECT assessment_id, submission_status FROM STUDENT_ASSESSMENT
                 WHERE user_id=? AND scenario_id=? ORDER BY assessment_id DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$user['user_id'], $scenarioId]);
            $assessment = $statement->fetch();
            if ($assessment && $assessment['submission_status'] === 'In Progress') {
                $pdo->commit();
                respond(['message' => 'Scenario is already in progress.', 'assessment_id' => $assessment['assessment_id']]);
            }
            if ($assessment) {
                $pdo->prepare(
                    "UPDATE STUDENT_ASSESSMENT
                     SET score=NULL, submission_status='In Progress', submission_text=NULL,
                         completed_at=NULL, feedback=NULL WHERE assessment_id=?"
                )->execute([$assessment['assessment_id']]);
                $assessmentId = (int)$assessment['assessment_id'];
            } else {
                $pdo->prepare(
                    "INSERT INTO STUDENT_ASSESSMENT (user_id, scenario_id, submission_status)
                     VALUES (?, ?, 'In Progress')"
                )->execute([$user['user_id'], $scenarioId]);
                $assessmentId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Started learning scenario {$scenarioId}");
        respond(['message' => 'Scenario started.', 'assessment_id' => $assessmentId], 201);
    }

    if (preg_match('#^scenarios/([^/]+)/submit$#', $route, $matches) && $method === 'POST') {
        $user = require_login();
        $scenarioId = $matches[1];
        $submission = trim((string)($data['submission_text'] ?? ''));
        if ($submission === '' || strlen($submission) > 20000) {
            throw new ApiException('Enter your response (up to 20,000 characters).', 400);
        }
        $statement = $pdo->prepare(
            "UPDATE STUDENT_ASSESSMENT
             SET submission_text=?, submission_status='Submitted', completed_at=CURRENT_TIMESTAMP
             WHERE user_id=? AND scenario_id=? AND submission_status='In Progress'"
        );
        $statement->execute([$submission, $user['user_id'], $scenarioId]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException('Start the scenario before submitting your response.', 409);
        }
        log_action($pdo, $user['user_id'], "Submitted learning scenario {$scenarioId}");
        respond(['message' => 'Your response was submitted for instructor review.']);
    }

    if ($route === 'assessments' && $method === 'GET') {
        $user = require_login();
        $sql = "
            SELECT a.*, u.full_name student_name, s.title scenario_title,
                   s.target_points, s.learning_objectives
            FROM STUDENT_ASSESSMENT a JOIN `USER` u ON u.user_id=a.user_id
            JOIN SCENARIO s ON s.scenario_id=a.scenario_id";
        $parameters = [];
        if ($user['role'] === 'student') {
            $sql .= ' WHERE a.user_id=?';
            $parameters[] = $user['user_id'];
        }
        $sql .= ' ORDER BY a.completed_at DESC';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        respond($statement->fetchAll());
    }

    if (preg_match('#^assessments/([0-9]+)$#', $route, $matches) && $method === 'DELETE') {
        $user = require_login();
        $assessmentId = (int)$matches[1];
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT user_id, submission_status FROM STUDENT_ASSESSMENT
                 WHERE assessment_id=? FOR UPDATE'
            );
            $statement->execute([$assessmentId]);
            $assessment = $statement->fetch();
            if (!$assessment) {
                throw new ApiException('Submission not found.', 404);
            }
            if ($user['role'] === 'student' && $assessment['user_id'] !== $user['user_id']) {
                throw new ApiException('You can delete only your own submission.', 403);
            }
            if (!in_array($user['role'], ['student', 'instructor', 'admin'], true)) {
                throw new ApiException('You do not have permission for this action.', 403);
            }
            if ($assessment['submission_status'] !== 'Submitted') {
                throw new ApiException('Only ungraded submissions can be deleted.', 409);
            }
            $pdo->prepare('DELETE FROM STUDENT_ASSESSMENT WHERE assessment_id=?')
                ->execute([$assessmentId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        log_action($pdo, $user['user_id'], "Deleted submission {$assessmentId}");
        respond(['message' => 'Ungraded submission deleted.']);
    }

    if (preg_match('#^assessments/([0-9]+)/grade$#', $route, $matches) && $method === 'POST') {
        $user = require_role(['admin', 'instructor']);
        $assessmentId = (int)$matches[1];
        $score = filter_var($data['score'] ?? null, FILTER_VALIDATE_INT);
        $feedback = trim((string)($data['feedback'] ?? ''));
        if ($score === false || $score < 0 || $feedback === '' || strlen($feedback) > 20000) {
            throw new ApiException('Enter a non-negative score and feedback (up to 20,000 characters).', 400);
        }
        $statement = $pdo->prepare(
            'SELECT a.assessment_id, a.submission_status, s.target_points
             FROM STUDENT_ASSESSMENT a JOIN SCENARIO s ON s.scenario_id=a.scenario_id
             WHERE a.assessment_id=?'
        );
        $statement->execute([$assessmentId]);
        $assessment = $statement->fetch();
        if (!$assessment) {
            throw new ApiException('Assessment not found.', 404);
        }
        if ($assessment['submission_status'] !== 'Submitted') {
            throw new ApiException('Only submitted assessments can be graded.', 409);
        }
        if ($score > (int)$assessment['target_points']) {
            throw new ApiException("Score cannot exceed {$assessment['target_points']} points.", 400);
        }
        $pdo->prepare(
            "UPDATE STUDENT_ASSESSMENT
             SET score=?, feedback=?, submission_status='Graded' WHERE assessment_id=?"
        )->execute([$score, $feedback, $assessmentId]);
        log_action($pdo, $user['user_id'], "Graded assessment {$assessmentId}");
        respond(['message' => 'Assessment graded.']);
    }

    if ($route === 'progress' && $method === 'GET') {
        $user = require_login();
        if ($user['role'] !== 'student') {
            throw new ApiException('Learning progress is available only to student accounts.', 403);
        }
        $statement = $pdo->prepare("
            SELECT s.scenario_id, s.title, s.difficulty, s.target_points,
                   a.score, COALESCE(a.submission_status, 'Not Started') submission_status,
                   a.completed_at, a.feedback
            FROM SCENARIO s LEFT JOIN STUDENT_ASSESSMENT a
              ON a.scenario_id=s.scenario_id AND a.user_id=?
            ORDER BY s.scenario_id");
        $statement->execute([$user['user_id']]);
        $rows = $statement->fetchAll();
        $graded = array_values(array_filter($rows, static fn(array $row): bool => $row['score'] !== null));
        $totalPoints = array_sum(array_map(static fn(array $row): int => (int)$row['score'], $graded));
        $totalPossible = array_sum(array_map(static fn(array $row): int => (int)$row['target_points'], $graded));
        respond([
            'scenarios' => $rows,
            'completed' => count($graded),
            'total_scenarios' => count($rows),
            'points_earned' => $totalPoints,
            'points_possible' => $totalPossible,
            'average_percent' => $totalPossible > 0 ? round($totalPoints / $totalPossible * 100, 1) : null
        ]);
    }

    throw new ApiException('API endpoint not found.', 404);
} catch (ApiException $error) {
    respond(['message' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('C.I.H.R.M. API error: '.$error->getMessage());
    respond(['message' => 'The request could not be completed. Check the PHP and MySQL server logs.'], 500);
}
