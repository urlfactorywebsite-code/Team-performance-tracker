<?php
session_start();
header('Content-Type: application/json');
require_once 'db_config.php';

$ROLES = ['owner', 'manager', 'employee'];
$CURRENCIES = ['USD', 'INR'];
$SALES_TYPES = ['leads', 'revenue'];
$PROGRESS_MODES = ['daily', 'monthly'];
$DEFAULT_OWNER_USERNAME = 'owner';
$DEFAULT_OWNER_PASSWORD = 'Owner@123';
$DEFAULT_TEAMS = [
    ['id' => 'data_sales', 'name' => 'Data Sales', 'target' => 25000, 'position' => 1],
    ['id' => 'zea_india', 'name' => 'ZEA CEM India', 'target' => 2500, 'position' => 2],
    ['id' => 'zea_usa', 'name' => 'ZEA CRM USA', 'target' => 1000, 'position' => 3]
];

function json_response($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function connect_db($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS) {
    return new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function slugify_team_id($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug ?: 'team';
}

function ensure_schema($pdo, $defaultTeams, $defaultUsername, $defaultPassword) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS teams (
          id VARCHAR(50) PRIMARY KEY,
          name VARCHAR(100) NOT NULL,
          unit VARCHAR(20) NOT NULL DEFAULT 'USD',
          target DECIMAL(12,2) NOT NULL DEFAULT 0,
          metric_type VARCHAR(20) NOT NULL DEFAULT 'revenue',
          currency VARCHAR(3) NULL DEFAULT 'USD',
          progress_mode VARCHAR(20) NOT NULL DEFAULT 'monthly',
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          position INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    try {
        $pdo->exec("ALTER TABLE teams ADD COLUMN metric_type VARCHAR(20) NOT NULL DEFAULT 'revenue'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE teams ADD COLUMN currency VARCHAR(3) NULL DEFAULT 'USD'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE teams ADD COLUMN progress_mode VARCHAR(20) NOT NULL DEFAULT 'monthly'");
    } catch (Exception $e) {}

    $pdo->exec("
        UPDATE teams
        SET metric_type = CASE WHEN LOWER(unit) = 'leads' THEN 'leads' ELSE 'revenue' END,
            currency = CASE WHEN LOWER(unit) = 'leads' THEN NULL WHEN UPPER(unit) IN ('USD', 'INR') THEN UPPER(unit) ELSE 'USD' END,
            progress_mode = CASE WHEN progress_mode IN ('daily', 'monthly') THEN progress_mode ELSE 'monthly' END
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
          id INT AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(60) NOT NULL,
          display_name VARCHAR(100) NOT NULL,
          role ENUM('owner','manager','employee') NOT NULL DEFAULT 'employee',
          team_id VARCHAR(50) NULL,
          entry_unit VARCHAR(20) NOT NULL DEFAULT 'leads',
          entry_target DECIMAL(12,2) NOT NULL DEFAULT 0,
          currency VARCHAR(3) NOT NULL DEFAULT 'USD',
          sales_type VARCHAR(20) NOT NULL DEFAULT 'leads',
          show_dashboard_card TINYINT(1) NOT NULL DEFAULT 0,
          show_entry_list TINYINT(1) NOT NULL DEFAULT 1,
          password_hash VARCHAR(255) NOT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          deleted_at DATETIME NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY username_unique (username)
        )
    ");

    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN entry_unit VARCHAR(20) NOT NULL DEFAULT 'leads'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN entry_target DECIMAL(12,2) NOT NULL DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'USD'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN sales_type VARCHAR(20) NOT NULL DEFAULT 'leads'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN show_dashboard_card TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN show_entry_list TINYINT(1) NOT NULL DEFAULT 1");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL");
    } catch (Exception $e) {}

    $pdo->exec("
        UPDATE users
        SET currency = CASE
              WHEN UPPER(entry_unit) IN ('USD', 'INR') THEN UPPER(entry_unit)
              ELSE currency
            END,
            sales_type = CASE
              WHEN UPPER(entry_unit) IN ('USD', 'INR') THEN 'revenue'
              ELSE 'leads'
            END
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS team_user_assignments (
          user_id INT NOT NULL,
          team_id VARCHAR(50) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (user_id, team_id),
          INDEX team_lookup (team_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_entries (
          id INT AUTO_INCREMENT PRIMARY KEY,
          team_id VARCHAR(50) NOT NULL,
          entry_date DATE NOT NULL,
          amount DECIMAL(12,2) NOT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY team_date (team_id, entry_date)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS entry_adjustments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          team_id VARCHAR(50) NOT NULL,
          entry_date DATE NOT NULL,
          amount DECIMAL(12,2) NOT NULL,
          action ENUM('add','revoke') NOT NULL,
          metric_type VARCHAR(20) NOT NULL DEFAULT 'revenue',
          currency VARCHAR(3) NULL DEFAULT 'USD',
          note VARCHAR(255) NULL,
          user_id INT NULL,
          created_by INT NULL,
          reverses_adjustment_id INT NULL UNIQUE,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX entry_lookup (entry_date, team_id),
          INDEX user_lookup (user_id)
        )
    ");
    try {
        $pdo->exec("ALTER TABLE entry_adjustments ADD COLUMN metric_type VARCHAR(20) NOT NULL DEFAULT 'revenue'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE entry_adjustments ADD COLUMN currency VARCHAR(3) NULL DEFAULT 'USD'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE entry_adjustments ADD COLUMN created_by INT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE entry_adjustments ADD COLUMN reverses_adjustment_id INT NULL UNIQUE");
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) !== 1060) throw $e;
    }
    $pdo->exec("
        UPDATE entry_adjustments ea
        LEFT JOIN teams t ON t.id = ea.team_id
        SET ea.metric_type = COALESCE(t.metric_type, CASE WHEN LOWER(t.unit) = 'leads' THEN 'leads' ELSE 'revenue' END, ea.metric_type),
            ea.currency = CASE
              WHEN COALESCE(t.metric_type, CASE WHEN LOWER(t.unit) = 'leads' THEN 'leads' ELSE 'revenue' END) = 'leads' THEN NULL
              ELSE COALESCE(t.currency, IF(UPPER(t.unit) IN ('USD','INR'), UPPER(t.unit), 'USD'))
            END,
            ea.created_by = COALESCE(ea.created_by, ea.user_id)
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_daily_entries (
          id INT AUTO_INCREMENT PRIMARY KEY,
          entry_user_id INT NOT NULL,
          entry_date DATE NOT NULL,
          amount DECIMAL(12,2) NOT NULL,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY user_date (entry_user_id, entry_date)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_entry_adjustments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          entry_user_id INT NOT NULL,
          entry_date DATE NOT NULL,
          amount DECIMAL(12,2) NOT NULL,
          action ENUM('add','revoke') NOT NULL,
          metric_type VARCHAR(20) NOT NULL DEFAULT 'leads',
          currency VARCHAR(3) NULL,
          note VARCHAR(255) NULL,
          actor_user_id INT NULL,
          created_by INT NULL,
          reverses_adjustment_id INT NULL UNIQUE,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX entry_lookup (entry_date, entry_user_id),
          INDEX actor_lookup (actor_user_id)
        )
    ");
    try {
        $pdo->exec("ALTER TABLE user_entry_adjustments ADD COLUMN metric_type VARCHAR(20) NOT NULL DEFAULT 'leads'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE user_entry_adjustments ADD COLUMN currency VARCHAR(3) NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE user_entry_adjustments ADD COLUMN created_by INT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE user_entry_adjustments ADD COLUMN reverses_adjustment_id INT NULL UNIQUE");
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) !== 1060) throw $e;
    }
    $pdo->exec("
        UPDATE user_entry_adjustments uea
        LEFT JOIN users u ON u.id = uea.entry_user_id
        SET uea.metric_type = COALESCE(u.sales_type, uea.metric_type),
            uea.currency = CASE WHEN COALESCE(u.sales_type, 'leads') = 'revenue' THEN COALESCE(u.currency, 'USD') ELSE NULL END,
            uea.created_by = COALESCE(uea.created_by, uea.actor_user_id)
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS leave_days (
          entry_date DATE PRIMARY KEY,
          note VARCHAR(255) NULL,
          created_by INT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exchange_rates (
          id INT AUTO_INCREMENT PRIMARY KEY,
          base_currency VARCHAR(3) NOT NULL,
          target_currency VARCHAR(3) NOT NULL,
          rate DECIMAL(18,8) NOT NULL,
          source VARCHAR(80) NULL,
          rate_date DATE NOT NULL,
          fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          INDEX rate_lookup (base_currency, target_currency, rate_date)
        )
    ");

    foreach ($defaultTeams as $team) {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO teams (id, name, unit, target, metric_type, currency, progress_mode, is_active, position)
            VALUES (:id, :name, 'USD', :target, 'revenue', 'USD', 'monthly', 1, :position)
        ");
        $stmt->execute([
            ':id' => $team['id'],
            ':name' => $team['name'],
            ':target' => $team['target'],
            ':position' => $team['position']
        ]);
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, display_name, role, team_id, password_hash, is_active)
            VALUES (:username, 'Owner', 'owner', NULL, :password_hash, 1)
        ");
        $stmt->execute([
            ':username' => $defaultUsername,
            ':password_hash' => password_hash($defaultPassword, PASSWORD_DEFAULT)
        ]);
    }

    $pdo->exec("
        INSERT IGNORE INTO team_user_assignments (user_id, team_id)
        SELECT id, team_id
        FROM users
        WHERE team_id IS NOT NULL AND team_id <> ''
    ");
}

function get_user_team_ids($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT team_id FROM team_user_assignments WHERE user_id = :user_id ORDER BY team_id");
    $stmt->execute([':user_id' => $userId]);
    return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function public_user($pdo, $user) {
    if (!$user) return null;
    return [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'role' => $user['role'],
        'team_id' => $user['team_id'],
        'team_ids' => get_user_team_ids($pdo, (int)$user['id']),
        'entry_unit' => $user['entry_unit'] ?? 'leads',
        'entry_target' => (float)($user['entry_target'] ?? 0),
        'currency' => $user['currency'] ?? 'USD',
        'sales_type' => $user['sales_type'] ?? 'leads',
        'show_dashboard_card' => (int)($user['show_dashboard_card'] ?? 0),
        'show_entry_list' => (int)($user['show_entry_list'] ?? 1),
        'is_active' => (int)$user['is_active']
    ];
}

function public_team($team) {
    $metricType = $team['metric_type'] ?? (strtolower((string)($team['unit'] ?? 'USD')) === 'leads' ? 'leads' : 'revenue');
    $currency = $metricType === 'revenue'
        ? ($team['currency'] ?? ((in_array(strtoupper((string)($team['unit'] ?? 'USD')), $GLOBALS['CURRENCIES'], true)) ? strtoupper((string)$team['unit']) : 'USD'))
        : null;
    return [
        'id' => $team['id'],
        'name' => $team['name'],
        'unit' => $metricType === 'revenue' ? $currency : 'leads',
        'target' => (float)$team['target'],
        'metric_type' => $metricType,
        'currency' => $currency,
        'progress_mode' => in_array(($team['progress_mode'] ?? 'monthly'), $GLOBALS['PROGRESS_MODES'], true) ? $team['progress_mode'] : 'monthly',
        'is_active' => (int)$team['is_active'],
        'position' => (int)$team['position']
    ];
}

function normalize_team_metric_settings($data) {
    $metricType = strtolower(trim((string)($data['metric_type'] ?? 'revenue')));
    $currency = strtoupper(trim((string)($data['currency'] ?? 'USD')));
    $progressMode = strtolower(trim((string)($data['progress_mode'] ?? 'monthly')));

    if (!in_array($metricType, $GLOBALS['SALES_TYPES'], true)) return [null, null, null, null];
    if (!in_array($progressMode, $GLOBALS['PROGRESS_MODES'], true)) $progressMode = 'monthly';
    if ($metricType === 'revenue') {
        if (!in_array($currency, $GLOBALS['CURRENCIES'], true)) return [null, null, null, null];
        return [$metricType, $currency, $currency, $progressMode];
    }
    return ['leads', null, 'leads', $progressMode];
}

function normalize_user_sales_settings($data) {
    $legacyUnit = trim((string)($data['entry_unit'] ?? ''));
    $legacyUnitUpper = strtoupper($legacyUnit);
    $currency = strtoupper(trim((string)($data['currency'] ?? '')));
    $salesType = strtolower(trim((string)($data['sales_type'] ?? '')));

    if ($currency === '' && in_array($legacyUnitUpper, $GLOBALS['CURRENCIES'], true)) {
        $currency = $legacyUnitUpper;
    }
    if ($salesType === '') {
        $salesType = in_array($legacyUnitUpper, $GLOBALS['CURRENCIES'], true) ? 'revenue' : 'leads';
    }
    if ($currency === '') {
        $currency = 'USD';
    }

    if (!in_array($currency, $GLOBALS['CURRENCIES'], true) || !in_array($salesType, $GLOBALS['SALES_TYPES'], true)) {
        return [null, null, null];
    }

    $entryUnit = $salesType === 'revenue' ? $currency : 'leads';
    return [$currency, $salesType, $entryUnit];
}

function current_user($pdo) {
    if (empty($_SESSION['user_id'])) return null;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user['team_ids'] = get_user_team_ids($pdo, (int)$user['id']);
    return $user;
}

function require_login($pdo) {
    $user = current_user($pdo);
    if (!$user) json_response(['error' => 'Login required'], 401);
    return $user;
}

function require_owner($pdo) {
    $user = require_login($pdo);
    if ($user['role'] !== 'owner') json_response(['error' => 'Owner permission required'], 403);
    return $user;
}

// Serialize owner changes so two owners cannot remove each other's access concurrently.
// The caller owns the transaction and handles rollback on validation failures.
function lock_managed_user($pdo, $actorId, $targetId = null) {
    $pdo->query("SELECT id FROM users WHERE role = 'owner' AND is_active = 1 AND deleted_at IS NULL ORDER BY id FOR UPDATE")->fetchAll();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id IN (:actor_id, :target_id) ORDER BY id FOR UPDATE");
    $stmt->execute([':actor_id' => $actorId, ':target_id' => $targetId ?? $actorId]);
    $actor = null;
    $target = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int)$row['id'] === (int)$actorId) $actor = $row;
        if ($targetId !== null && (int)$row['id'] === (int)$targetId) $target = $row;
    }
    if (!$actor || $actor['role'] !== 'owner' || !(int)$actor['is_active'] || $actor['deleted_at'] !== null) {
        throw new RuntimeException('Owner permission required', 403);
    }
    if ($targetId !== null && (!$target || $target['deleted_at'] !== null)) {
        throw new RuntimeException('User not found', 404);
    }
    return $target;
}

function team_exists($pdo, $teamId, $activeOnly = true) {
    $sql = "SELECT COUNT(*) FROM teams WHERE id = :id";
    if ($activeOnly) $sql .= " AND is_active = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $teamId]);
    return (int)$stmt->fetchColumn() > 0;
}

function validate_team_id($pdo, $teamId, $allowNull = false, $activeOnly = true) {
    if ($allowNull && ($teamId === '' || $teamId === null)) return null;
    return is_string($teamId) && team_exists($pdo, $teamId, $activeOnly) ? $teamId : false;
}

function unique_team_id($pdo, $name) {
    $base = substr(slugify_team_id($name), 0, 44);
    $id = $base;
    $i = 2;
    while (team_exists($pdo, $id, false)) {
        $suffix = '_' . $i;
        $id = substr($base, 0, 50 - strlen($suffix)) . $suffix;
        $i++;
    }
    return $id;
}

function can_update_team($user, $teamId) {
    if ($user['role'] === 'owner') return true;
    return in_array($teamId, $user['team_ids'] ?? [], true);
}

function can_update_entry_user($user, $entryUserId) {
    if ($user['role'] === 'owner') return true;
    return (int)$user['id'] === (int)$entryUserId;
}

function entry_user_exists($pdo, $entryUserId, $activeOnly = true) {
    $sql = "SELECT COUNT(*) FROM users WHERE id = :id";
    if ($activeOnly) $sql .= " AND is_active = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => (int)$entryUserId]);
    return (int)$stmt->fetchColumn() > 0;
}

function own_net_amount($pdo, $userId, $teamId, $date) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN action = 'add' THEN amount ELSE -amount END), 0)
        FROM entry_adjustments
        WHERE user_id = :user_id AND team_id = :team_id AND entry_date = :date
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':team_id' => $teamId,
        ':date' => $date
    ]);
    return (float)$stmt->fetchColumn();
}

function own_user_net_amount($pdo, $actorUserId, $entryUserId, $date) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN action = 'add' THEN amount ELSE -amount END), 0)
        FROM user_entry_adjustments
        WHERE actor_user_id = :actor_user_id AND entry_user_id = :entry_user_id AND entry_date = :date
    ");
    $stmt->execute([
        ':actor_user_id' => $actorUserId,
        ':entry_user_id' => $entryUserId,
        ':date' => $date
    ]);
    return (float)$stmt->fetchColumn();
}

function apply_adjustment($pdo, $user, $teamId, $date, $amount, $action, $note = null, $reversesAdjustmentId = null) {
    if ($reversesAdjustmentId !== null && ($user['role'] !== 'owner' || $action !== 'revoke')) {
        json_response(['error' => 'Owner permission required'], 403);
    }
    if ($amount <= 0) {
        json_response(['error' => 'Amount must be greater than 0'], 400);
    }
    if (!can_update_team($user, $teamId)) {
        json_response(['error' => 'You are not assigned to this team'], 403);
    }
    $stmt = $pdo->prepare("SELECT unit, metric_type, currency FROM teams WHERE id = :id");
    $stmt->execute([':id' => $teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $metricType = $team['metric_type'] ?? (strtolower((string)($team['unit'] ?? 'USD')) === 'leads' ? 'leads' : 'revenue');
    $currency = $metricType === 'revenue'
        ? ($team['currency'] ?? (in_array(strtoupper((string)($team['unit'] ?? 'USD')), $GLOBALS['CURRENCIES'], true) ? strtoupper((string)$team['unit']) : 'USD'))
        : null;

    $signedAmount = $action === 'revoke' ? -$amount : $amount;

    $pdo->beginTransaction();
    try {
        if ($action === 'revoke') {
            $stmt = $pdo->prepare("SELECT amount FROM daily_entries WHERE team_id = :team_id AND entry_date = :date FOR UPDATE");
            $stmt->execute([':team_id' => $teamId, ':date' => $date]);
            $current = $stmt->fetchColumn();
            if ($reversesAdjustmentId !== null) {
                $stmt = $pdo->prepare("SELECT id FROM entry_adjustments WHERE reverses_adjustment_id = :id");
                $stmt->execute([':id' => $reversesAdjustmentId]);
                if ($stmt->fetchColumn() !== false) {
                    $pdo->commit();
                    return;
                }
            }
            if ($current === false || (float)$current < $amount) {
                $pdo->rollBack();
                json_response(['error' => 'Revoke amount is greater than the saved total'], 400);
            }

            if ($user['role'] !== 'owner') {
                $ownNet = own_net_amount($pdo, (int)$user['id'], $teamId, $date);
                if ($ownNet < $amount) {
                    $pdo->rollBack();
                    json_response(['error' => 'You can only revoke your own daily entry amount'], 403);
                }
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO daily_entries (team_id, entry_date, amount) VALUES (:team_id, :date, :amount)
             ON DUPLICATE KEY UPDATE amount = GREATEST(0, amount + VALUES(amount))"
        );
        $stmt->execute([
            ':team_id' => $teamId,
            ':date' => $date,
            ':amount' => $signedAmount
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO entry_adjustments (team_id, entry_date, amount, action, metric_type, currency, note, user_id, created_by, reverses_adjustment_id)
            VALUES (:team_id, :date, :amount, :action, :metric_type, :currency, :note, :user_id, :created_by, :reverses_adjustment_id)
        ");
        $stmt->execute([
            ':team_id' => $teamId,
            ':date' => $date,
            ':amount' => $amount,
            ':action' => $action,
            ':metric_type' => $metricType,
            ':currency' => $currency,
            ':note' => $note,
            ':user_id' => (int)$user['id'],
            ':created_by' => (int)$user['id'],
            ':reverses_adjustment_id' => $reversesAdjustmentId
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function apply_user_adjustment($pdo, $user, $entryUserId, $date, $amount, $action, $note = null, $reversesAdjustmentId = null) {
    if ($reversesAdjustmentId !== null && ($user['role'] !== 'owner' || $action !== 'revoke')) {
        json_response(['error' => 'Owner permission required'], 403);
    }
    if ($amount <= 0) {
        json_response(['error' => 'Amount must be greater than 0'], 400);
    }
    if (!can_update_entry_user($user, $entryUserId)) {
        json_response(['error' => 'You can only update your own daily entry'], 403);
    }
    $stmt = $pdo->prepare("SELECT sales_type, currency FROM users WHERE id = :id");
    $stmt->execute([':id' => $entryUserId]);
    $entryUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $metricType = $entryUser['sales_type'] ?? 'leads';
    $currency = $metricType === 'revenue' ? ($entryUser['currency'] ?? 'USD') : null;

    $signedAmount = $action === 'revoke' ? -$amount : $amount;

    $pdo->beginTransaction();
    try {
        if ($action === 'revoke') {
            $stmt = $pdo->prepare("SELECT amount FROM user_daily_entries WHERE entry_user_id = :entry_user_id AND entry_date = :date FOR UPDATE");
            $stmt->execute([':entry_user_id' => $entryUserId, ':date' => $date]);
            $current = $stmt->fetchColumn();
            if ($reversesAdjustmentId !== null) {
                $stmt = $pdo->prepare("SELECT id FROM user_entry_adjustments WHERE reverses_adjustment_id = :id");
                $stmt->execute([':id' => $reversesAdjustmentId]);
                if ($stmt->fetchColumn() !== false) {
                    $pdo->commit();
                    return;
                }
            }
            if ($current === false || (float)$current < $amount) {
                $pdo->rollBack();
                json_response(['error' => 'Revoke amount is greater than the saved total'], 400);
            }

            if ($user['role'] !== 'owner') {
                $ownNet = own_user_net_amount($pdo, (int)$user['id'], $entryUserId, $date);
                if ($ownNet < $amount) {
                    $pdo->rollBack();
                    json_response(['error' => 'You can only revoke your own daily entry amount'], 403);
                }
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO user_daily_entries (entry_user_id, entry_date, amount) VALUES (:entry_user_id, :date, :amount)
             ON DUPLICATE KEY UPDATE amount = GREATEST(0, amount + VALUES(amount))"
        );
        $stmt->execute([
            ':entry_user_id' => $entryUserId,
            ':date' => $date,
            ':amount' => $signedAmount
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO user_entry_adjustments (entry_user_id, entry_date, amount, action, metric_type, currency, note, actor_user_id, created_by, reverses_adjustment_id)
            VALUES (:entry_user_id, :date, :amount, :action, :metric_type, :currency, :note, :actor_user_id, :created_by, :reverses_adjustment_id)
        ");
        $stmt->execute([
            ':entry_user_id' => $entryUserId,
            ':date' => $date,
            ':amount' => $amount,
            ':action' => $action,
            ':metric_type' => $metricType,
            ':currency' => $currency,
            ':note' => $note,
            ':actor_user_id' => (int)$user['id'],
            ':created_by' => (int)$user['id'],
            ':reverses_adjustment_id' => $reversesAdjustmentId
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function normalize_team_ids($pdo, $teamIds) {
    if (!is_array($teamIds)) $teamIds = [];
    $clean = [];
    foreach ($teamIds as $teamId) {
        $teamId = trim((string)$teamId);
        if ($teamId !== '' && validate_team_id($pdo, $teamId, false, false) !== false) {
            $clean[] = $teamId;
        }
    }
    return array_values(array_unique($clean));
}

function save_user_assignments($pdo, $userId, $teamIds) {
    $stmt = $pdo->prepare("DELETE FROM team_user_assignments WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $userId]);

    $stmt = $pdo->prepare("INSERT INTO team_user_assignments (user_id, team_id) VALUES (:user_id, :team_id)");
    foreach ($teamIds as $teamId) {
        $stmt->execute([':user_id' => $userId, ':team_id' => $teamId]);
    }
}

try {
    $pdo = connect_db($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS);
    ensure_schema($pdo, $DEFAULT_TEAMS, $DEFAULT_OWNER_USERNAME, $DEFAULT_OWNER_PASSWORD);
} catch (Exception $e) {
    json_response(['error' => 'Database connection failed'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action === 'me' && $method === 'GET') {
    $user = current_user($pdo);
    json_response([
        'authenticated' => (bool)$user,
        'user' => public_user($pdo, $user)
    ]);
}

if ($action === 'login' && $method === 'POST') {
    $data = read_json_body();
    $username = trim($data['username'] ?? '');
    $password = (string)($data['password'] ?? '');

    if ($username === '' || $password === '') {
        json_response(['error' => 'Username and password are required'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_response(['error' => 'Invalid username or password'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    json_response(['success' => true, 'user' => public_user($pdo, $user)]);
}

if ($action === 'logout' && $method === 'POST') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    json_response(['success' => true]);
}

if ($action === 'teams' && $method === 'GET') {
    require_login($pdo);
    $includeInactive = ($_GET['include_inactive'] ?? '') === '1';
    if ($includeInactive) require_owner($pdo);
    $activeFilter = $includeInactive ? '' : 'WHERE is_active = 1';
    $rows = $pdo->query("
        SELECT id, name, unit, target, metric_type, currency, progress_mode, is_active, position
        FROM teams
        $activeFilter
        ORDER BY position, name
    ")->fetchAll(PDO::FETCH_ASSOC);
    json_response(['rows' => array_map('public_team', $rows)]);
}

if ($action === 'teams_manage' && $method === 'GET') {
    require_owner($pdo);
    $rows = $pdo->query("
        SELECT id, name, unit, target, metric_type, currency, progress_mode, is_active, position
        FROM teams
        ORDER BY is_active DESC, position, name
    ")->fetchAll(PDO::FETCH_ASSOC);
    json_response(['rows' => array_map('public_team', $rows)]);
}

if ($action === 'team_save' && $method === 'POST') {
    require_owner($pdo);
    $data = read_json_body();
    $id = trim($data['id'] ?? '');
    $name = trim($data['name'] ?? '');
    $target = $data['target'] ?? 0;
    list($metricType, $currency, $unit, $progressMode) = normalize_team_metric_settings($data);
    $position = isset($data['position']) ? (int)$data['position'] : 0;
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($name === '' || strlen($name) > 100) {
        json_response(['error' => 'Team name is required'], 400);
    }
    if (!is_numeric($target) || (float)$target < 0) {
        json_response(['error' => 'Target must be 0 or greater'], 400);
    }
    if ($metricType === null) {
        json_response(['error' => 'Invalid team metric type or currency'], 400);
    }

    if ($id === '') {
        $id = unique_team_id($pdo, $name);
        $stmt = $pdo->prepare("
            INSERT INTO teams (id, name, unit, target, metric_type, currency, progress_mode, is_active, position)
            VALUES (:id, :name, :unit, :target, :metric_type, :currency, :progress_mode, :is_active, :position)
        ");
    } else {
        if (!team_exists($pdo, $id, false)) json_response(['error' => 'Team not found'], 404);
        $stmt = $pdo->prepare("
            UPDATE teams
            SET name = :name, unit = :unit, target = :target, metric_type = :metric_type,
                currency = :currency, progress_mode = :progress_mode, is_active = :is_active, position = :position
            WHERE id = :id
        ");
    }

    $stmt->execute([
        ':id' => $id,
        ':name' => $name,
        ':unit' => $unit,
        ':target' => (float)$target,
        ':metric_type' => $metricType,
        ':currency' => $currency,
        ':progress_mode' => $progressMode,
        ':is_active' => $isActive,
        ':position' => $position
    ]);

    json_response(['success' => true, 'id' => $id]);
}

if ($action === 'users' && $method === 'GET') {
    require_owner($pdo);
    $rows = $pdo->query("
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.entry_unit, u.entry_target,
               u.currency, u.sales_type, u.show_dashboard_card, u.show_entry_list,
               u.is_active, u.created_at, u.updated_at,
               GROUP_CONCAT(tua.team_id ORDER BY tua.team_id SEPARATOR ',') AS team_ids
        FROM users u
        LEFT JOIN team_user_assignments tua ON tua.user_id = u.id
        WHERE u.deleted_at IS NULL
        GROUP BY u.id, u.username, u.display_name, u.role, u.team_id, u.entry_unit, u.entry_target,
                 u.currency, u.sales_type, u.show_dashboard_card, u.show_entry_list,
                 u.is_active, u.created_at, u.updated_at
        ORDER BY u.role = 'owner' DESC, u.username
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['team_ids'] = $row['team_ids'] ? explode(',', $row['team_ids']) : [];
    }

    json_response(['rows' => $rows]);
}

if (in_array($action, ['user_reset_password', 'user_set_active', 'user_update_settings', 'user_delete'], true) && $method === 'POST') {
    $currentUser = require_owner($pdo);
    $data = read_json_body();
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) json_response(['error' => 'Valid user ID is required'], 400);
    if ($action === 'user_reset_password' && (!is_string($data['password'] ?? null) || strlen($data['password']) < 6)) {
        json_response(['error' => 'Password must be at least 6 characters'], 400);
    }
    $settings = [];
    if ($action === 'user_set_active' || $action === 'user_update_settings') {
        $fields = $action === 'user_set_active' ? ['is_active'] : ['show_dashboard_card', 'show_entry_list'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) continue;
            if (!in_array($data[$field], [true, false, 0, 1, '0', '1'], true)) {
                json_response(['error' => 'Invalid toggle value'], 400);
            }
            $settings[$field] = (int)(bool)$data[$field];
        }
        if (!$settings) json_response(['error' => 'Choose a setting to update'], 400);
    }
    if ($id === (int)$currentUser['id'] && ($action === 'user_delete' || ($action === 'user_set_active' && !$settings['is_active']))) {
        json_response(['error' => 'You cannot deactivate or delete your own owner account'], 400);
    }
    try {
        $pdo->beginTransaction();
        $target = lock_managed_user($pdo, (int)$currentUser['id'], $id);
        if ($action === 'user_reset_password') {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :id");
            $stmt->execute([':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT), ':id' => $id]);
        } elseif ($action === 'user_delete') {
            if (($data['confirm_username'] ?? '') !== $target['username']) {
                throw new RuntimeException('Type the username to confirm deletion', 400);
            }
            // Keep the identity and assignments for historical logs; never delete financial/lead records.
            $stmt = $pdo->prepare("UPDATE users SET is_active = 0, deleted_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':id' => $id]);
        } else {
            $columns = array_map(function($field) { return "$field = :$field"; }, array_keys($settings));
            $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $columns) . " WHERE id = :id");
            $params = [':id' => $id];
            foreach ($settings as $field => $value) $params[":$field"] = $value;
            $stmt->execute($params);
        }
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status = in_array($e->getCode(), [400, 403, 404], true) ? $e->getCode() : 500;
        json_response(['error' => $status === 500 ? 'User action could not be completed' : $e->getMessage()], $status);
    }
    json_response(['success' => true]);
}

if ($action === 'entry_users' && $method === 'GET') {
    $user = require_login($pdo);
    $includeInactive = ($_GET['include_inactive'] ?? '') === '1';
    if ($includeInactive) require_owner($pdo);
    $activeFilter = $includeInactive ? '' : 'WHERE u.is_active = 1';
    if ($user['role'] === 'owner') {
        $stmt = $pdo->query("
            SELECT u.id, u.username, u.display_name, u.role, u.entry_unit, u.entry_target,
                   u.currency, u.sales_type, u.show_dashboard_card, u.show_entry_list, u.is_active, u.deleted_at,
                   GROUP_CONCAT(tua.team_id ORDER BY tua.team_id SEPARATOR ',') AS team_ids
            FROM users u
            LEFT JOIN team_user_assignments tua ON tua.user_id = u.id
            $activeFilter
            GROUP BY u.id, u.username, u.display_name, u.role, u.entry_unit, u.entry_target,
                     u.currency, u.sales_type, u.show_dashboard_card, u.show_entry_list, u.is_active, u.deleted_at
            ORDER BY u.display_name, u.username
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.display_name, u.role, u.entry_unit, u.entry_target,
                   u.currency, u.sales_type, u.show_dashboard_card, u.show_entry_list, u.is_active,
                   GROUP_CONCAT(tua.team_id ORDER BY tua.team_id SEPARATOR ',') AS team_ids
            FROM users u
            LEFT JOIN team_user_assignments tua ON tua.user_id = u.id
            WHERE u.id = :id AND u.is_active = 1
            GROUP BY u.id, u.username, u.display_name, u.role, u.entry_unit, u.entry_target,
                     u.currency, u.sales_type, u.show_dashboard_card, u.show_entry_list, u.is_active
        ");
        $stmt->execute([':id' => (int)$user['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    json_response(['rows' => array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'team_ids' => $row['team_ids'] ? explode(',', $row['team_ids']) : [],
            'unit' => $row['entry_unit'] ?: 'leads',
            'target' => (float)($row['entry_target'] ?? 0),
            'currency' => $row['currency'] ?: 'USD',
            'sales_type' => $row['sales_type'] ?: 'leads',
            'show_dashboard_card' => (int)($row['show_dashboard_card'] ?? 0),
            'show_entry_list' => (int)($row['show_entry_list'] ?? 1),
            'is_active' => (int)$row['is_active'],
            'deleted_at' => $row['deleted_at'] ?? null
        ];
    }, $rows)]);
}

if ($action === 'user_save' && $method === 'POST') {
    $currentUser = require_owner($pdo);
    $data = read_json_body();
    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $username = strtolower(trim($data['username'] ?? ''));
    $displayName = trim($data['display_name'] ?? '');
    $role = strtolower(trim($data['role'] ?? 'employee'));
    $teamIds = normalize_team_ids($pdo, $data['team_ids'] ?? []);
    list($currency, $salesType, $entryUnit) = normalize_user_sales_settings($data);
    $entryTarget = $data['entry_target'] ?? 0;
    $password = (string)($data['password'] ?? '');
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $showDashboardCard = !empty($data['show_dashboard_card']) ? 1 : 0;
    $showEntryList = !array_key_exists('show_entry_list', $data) || !empty($data['show_entry_list']) ? 1 : 0;

    if (!preg_match('/^[a-z0-9_.-]{3,60}$/', $username)) {
        json_response(['error' => 'Username must be 3-60 characters and use letters, numbers, dots, dashes, or underscores'], 400);
    }
    if ($displayName === '' || strlen($displayName) > 100) {
        json_response(['error' => 'Display name is required'], 400);
    }
    if (!in_array($role, $GLOBALS['ROLES'], true)) {
        json_response(['error' => 'Invalid role'], 400);
    }
    if ($currency === null || $salesType === null) {
        json_response(['error' => 'Invalid currency or sales type'], 400);
    }
    if (!is_numeric($entryTarget) || (float)$entryTarget < 0) {
        json_response(['error' => 'Entry target must be 0 or greater'], 400);
    }
    if (!$id && strlen($password) < 6) {
        json_response(['error' => 'Password must be at least 6 characters'], 400);
    }
    if ($id && $password !== '' && strlen($password) < 6) {
        json_response(['error' => 'Password must be at least 6 characters'], 400);
    }
    if ($id && $id === (int)$currentUser['id'] && ($role !== 'owner' || !$isActive)) {
        json_response(['error' => 'You cannot remove your own owner access'], 400);
    }

    try {
        $pdo->beginTransaction();
        lock_managed_user($pdo, (int)$currentUser['id'], $id);
        if ($id) {
            if ($password !== '') {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET username = :username, display_name = :display_name, role = :role,
                        team_id = NULL, entry_unit = :entry_unit, entry_target = :entry_target,
                        currency = :currency, sales_type = :sales_type,
                        show_dashboard_card = :show_dashboard_card, show_entry_list = :show_entry_list,
                        password_hash = :password_hash, is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':username' => $username,
                    ':display_name' => $displayName,
                    ':role' => $role,
                    ':entry_unit' => $entryUnit,
                    ':entry_target' => (float)$entryTarget,
                    ':currency' => $currency,
                    ':sales_type' => $salesType,
                    ':show_dashboard_card' => $showDashboardCard,
                    ':show_entry_list' => $showEntryList,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':is_active' => $isActive,
                    ':id' => $id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET username = :username, display_name = :display_name, role = :role,
                        team_id = NULL, entry_unit = :entry_unit, entry_target = :entry_target,
                        currency = :currency, sales_type = :sales_type,
                        show_dashboard_card = :show_dashboard_card, show_entry_list = :show_entry_list,
                        is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':username' => $username,
                    ':display_name' => $displayName,
                    ':role' => $role,
                    ':entry_unit' => $entryUnit,
                    ':entry_target' => (float)$entryTarget,
                    ':currency' => $currency,
                    ':sales_type' => $salesType,
                    ':show_dashboard_card' => $showDashboardCard,
                    ':show_entry_list' => $showEntryList,
                    ':is_active' => $isActive,
                    ':id' => $id
                ]);
            }
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    username, display_name, role, team_id, entry_unit, entry_target,
                    currency, sales_type, show_dashboard_card, show_entry_list,
                    password_hash, is_active
                )
                VALUES (
                    :username, :display_name, :role, NULL, :entry_unit, :entry_target,
                    :currency, :sales_type, :show_dashboard_card, :show_entry_list,
                    :password_hash, :is_active
                )
            ");
            $stmt->execute([
                ':username' => $username,
                ':display_name' => $displayName,
                ':role' => $role,
                ':entry_unit' => $entryUnit,
                ':entry_target' => (float)$entryTarget,
                ':currency' => $currency,
                ':sales_type' => $salesType,
                ':show_dashboard_card' => $showDashboardCard,
                ':show_entry_list' => $showEntryList,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':is_active' => $isActive
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        save_user_assignments($pdo, $id, $teamIds);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['error' => 'Username already exists or user could not be saved'], 400);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['error' => $e->getMessage()], in_array($e->getCode(), [403, 404], true) ? $e->getCode() : 400);
    }

    json_response(['success' => true]);
}

if ($action === 'month' && $method === 'GET') {
    require_login($pdo);
    $month = $_GET['month'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        json_response(['error' => 'Invalid month format, expected YYYY-MM'], 400);
    }

    $stmt = $pdo->prepare(
        "SELECT team_id, entry_date, amount
         FROM daily_entries
         WHERE entry_date LIKE :prefix
         ORDER BY entry_date"
    );
    $stmt->execute([':prefix' => "$month-%"]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT entry_user_id, entry_date, amount
         FROM user_daily_entries
         WHERE entry_date LIKE :prefix
         ORDER BY entry_date"
    );
    $stmt->execute([':prefix' => "$month-%"]);
    $userRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT ea.id, ea.team_id, ea.entry_date, ea.amount, ea.action, ea.metric_type, ea.currency, ea.note, ea.user_id, ea.created_by, ea.reverses_adjustment_id,
               ea.created_at, COALESCE(u.display_name, u.username, 'Unknown') AS user_name
        FROM entry_adjustments ea
        LEFT JOIN users u ON u.id = ea.user_id
        WHERE ea.entry_date LIKE :prefix
        ORDER BY ea.created_at DESC, ea.id DESC
    ");
    $stmt->execute([':prefix' => "$month-%"]);
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT uea.id, uea.entry_user_id, uea.entry_date, uea.amount, uea.action, uea.metric_type, uea.currency, uea.note, uea.actor_user_id, uea.created_by, uea.reverses_adjustment_id,
               uea.created_at,
               COALESCE(actor.display_name, actor.username, 'Unknown') AS actor_name,
               COALESCE(target.display_name, target.username, 'Unknown') AS entry_user_name
        FROM user_entry_adjustments uea
        LEFT JOIN users actor ON actor.id = uea.actor_user_id
        LEFT JOIN users target ON target.id = uea.entry_user_id
        WHERE uea.entry_date LIKE :prefix
        ORDER BY uea.created_at DESC, uea.id DESC
    ");
    $stmt->execute([':prefix' => "$month-%"]);
    $userAdjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT ld.entry_date, ld.note, ld.created_by, ld.created_at, ld.updated_at,
               COALESCE(u.display_name, u.username, 'Owner') AS created_by_name
        FROM leave_days ld
        LEFT JOIN users u ON u.id = ld.created_by
        WHERE ld.entry_date LIKE :prefix
        ORDER BY ld.entry_date
    ");
    $stmt->execute([':prefix' => "$month-%"]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_response([
        'rows' => $rows,
        'user_rows' => $userRows,
        'adjustments' => $adjustments,
        'user_adjustments' => $userAdjustments,
        'leaves' => $leaves
    ]);
}

if ($action === 'leave_save' && $method === 'POST') {
    $user = require_owner($pdo);
    $data = read_json_body();
    $date = $data['date'] ?? '';
    $isLeave = !empty($data['is_leave']);
    $note = trim((string)($data['note'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['error' => 'Invalid date'], 400);
    }

    if ($isLeave) {
        $stmt = $pdo->prepare("
            INSERT INTO leave_days (entry_date, note, created_by)
            VALUES (:date, :note, :created_by)
            ON DUPLICATE KEY UPDATE note = VALUES(note), created_by = VALUES(created_by)
        ");
        $stmt->execute([
            ':date' => $date,
            ':note' => $note !== '' ? $note : null,
            ':created_by' => (int)$user['id']
        ]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM leave_days WHERE entry_date = :date");
        $stmt->execute([':date' => $date]);
    }

    json_response(['success' => true]);
}

if ($action === 'exchange_rates_current' && $method === 'GET') {
    require_owner($pdo);
    $rows = $pdo->query("
        SELECT base_currency, target_currency, rate, source, rate_date, fetched_at
        FROM exchange_rates
        WHERE is_active = 1
        ORDER BY fetched_at DESC, id DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    $current = [];
    foreach ($rows as $row) {
        $key = $row['base_currency'] . '_' . $row['target_currency'];
        if (!isset($current[$key])) $current[$key] = $row;
    }
    json_response(['rates' => $current]);
}

if ($action === 'exchange_rates_history' && $method === 'GET') {
    require_owner($pdo);
    $rows = $pdo->query("
        SELECT base_currency, target_currency, rate, source, rate_date, fetched_at, is_active
        FROM exchange_rates
        ORDER BY fetched_at DESC, id DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
    json_response(['rows' => $rows]);
}

if (($action === 'exchange_rates_manual' || $action === 'exchange_rates_refresh') && $method === 'POST') {
    require_owner($pdo);
    $data = read_json_body();
    $source = $action === 'exchange_rates_refresh' ? 'live' : 'manual';
    $rate = isset($data['rate']) ? (float)$data['rate'] : 0;

    if ($action === 'exchange_rates_refresh') {
        $url = getenv('EXCHANGE_RATE_API_URL') ?: 'https://open.er-api.com/v6/latest/USD';
        $json = @file_get_contents($url);
        $payload = $json ? json_decode($json, true) : null;
        if (is_array($payload)) {
            if (isset($payload['rates']['INR'])) {
                $rate = (float)$payload['rates']['INR'];
            } elseif (isset($payload['conversion_rates']['INR'])) {
                $rate = (float)$payload['conversion_rates']['INR'];
            }
        }
        if ($rate <= 0) {
            json_response(['error' => 'Live rate unavailable. Enter a manual rate.'], 503);
        }
    }

    if ($rate <= 0) {
        json_response(['error' => 'USD to INR rate must be greater than 0'], 400);
    }

    $today = date('Y-m-d');
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE exchange_rates SET is_active = 0 WHERE base_currency IN ('USD','INR') AND target_currency IN ('USD','INR')");
        $stmt = $pdo->prepare("
            INSERT INTO exchange_rates (base_currency, target_currency, rate, source, rate_date, is_active)
            VALUES (:base, :target, :rate, :source, :rate_date, 1)
        ");
        $stmt->execute([
            ':base' => 'USD',
            ':target' => 'INR',
            ':rate' => $rate,
            ':source' => $source,
            ':rate_date' => $today
        ]);
        $stmt->execute([
            ':base' => 'INR',
            ':target' => 'USD',
            ':rate' => 1 / $rate,
            ':source' => $source,
            ':rate_date' => $today
        ]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['error' => 'Exchange rate could not be saved'], 500);
    }

    json_response(['success' => true]);
}

if ($action === 'save' && $method === 'POST') {
    $user = require_login($pdo);
    $data = read_json_body();
    $teamId = $data['team_id'] ?? '';
    $date = $data['date'] ?? '';
    $amount = $data['amount'] ?? null;

    if (validate_team_id($pdo, $teamId, false, true) === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($amount)) {
        json_response(['error' => 'Invalid input'], 400);
    }

    try {
        apply_adjustment($pdo, $user, $teamId, $date, (float)$amount, 'add', $data['note'] ?? 'Daily addition');
    } catch (Exception $e) {
        json_response(['error' => 'Save failed'], 500);
    }

    json_response(['success' => true]);
}

if ($action === 'history_revoke' && $method === 'POST') {
    $user = require_owner($pdo);
    $data = read_json_body();
    $kind = $data['kind'] ?? '';
    $id = filter_var($data['adjustment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!in_array($kind, ['team', 'user'], true) || $id === false) {
        json_response(['error' => 'Invalid history entry'], 400);
    }
    $table = $kind === 'team' ? 'entry_adjustments' : 'user_entry_adjustments';
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = :id AND action = 'add'");
    $stmt->execute([':id' => $id]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry) json_response(['error' => 'Addition not found'], 404);
    try {
        $note = "History correction of $kind addition #$id";
        if ($kind === 'team') {
            apply_adjustment($pdo, $user, $entry['team_id'], $entry['entry_date'], (float)$entry['amount'], 'revoke', $note, $id);
        } else {
            apply_user_adjustment($pdo, $user, (int)$entry['entry_user_id'], $entry['entry_date'], (float)$entry['amount'], 'revoke', $note, $id);
        }
    } catch (Exception $e) {
        json_response(['error' => 'History revoke failed'], 500);
    }
    json_response(['success' => true]);
}

if ($action === 'revoke' && $method === 'POST') {
    $user = require_login($pdo);
    $data = read_json_body();
    $teamId = $data['team_id'] ?? '';
    $date = $data['date'] ?? '';
    $amount = $data['amount'] ?? null;

    if (validate_team_id($pdo, $teamId, false, false) === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($amount)) {
        json_response(['error' => 'Invalid input'], 400);
    }

    try {
        apply_adjustment($pdo, $user, $teamId, $date, (float)$amount, 'revoke', $data['note'] ?? 'Manual correction');
    } catch (Exception $e) {
        json_response(['error' => 'Revoke failed'], 500);
    }

    json_response(['success' => true]);
}

if ($action === 'user_entry_save' && $method === 'POST') {
    $user = require_login($pdo);
    $data = read_json_body();
    $entryUserId = isset($data['entry_user_id']) ? (int)$data['entry_user_id'] : 0;
    $date = $data['date'] ?? '';
    $amount = $data['amount'] ?? null;

    if (!entry_user_exists($pdo, $entryUserId, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($amount)) {
        json_response(['error' => 'Invalid input'], 400);
    }

    try {
        apply_user_adjustment($pdo, $user, $entryUserId, $date, (float)$amount, 'add', $data['note'] ?? 'Daily addition');
    } catch (Exception $e) {
        json_response(['error' => 'Save failed'], 500);
    }

    json_response(['success' => true]);
}

if ($action === 'user_entry_revoke' && $method === 'POST') {
    $user = require_login($pdo);
    $data = read_json_body();
    $entryUserId = isset($data['entry_user_id']) ? (int)$data['entry_user_id'] : 0;
    $date = $data['date'] ?? '';
    $amount = $data['amount'] ?? null;

    if (!entry_user_exists($pdo, $entryUserId, false) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($amount)) {
        json_response(['error' => 'Invalid input'], 400);
    }

    try {
        apply_user_adjustment($pdo, $user, $entryUserId, $date, (float)$amount, 'revoke', $data['note'] ?? 'Manual correction');
    } catch (Exception $e) {
        json_response(['error' => 'Revoke failed'], 500);
    }

    json_response(['success' => true]);
}

json_response(['error' => 'Unknown action'], 404);
