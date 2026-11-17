<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

function travel_minutes(PDO $pdo, int $from, int $to): int
{
    if ($from === $to) {
        return 0;
    }

    $query = $pdo->prepare(
        'SELECT travel_minutes FROM branch_travel_times
         WHERE from_branch_id = :from_id AND to_branch_id = :to_id'
    );
    $query->execute(['from_id' => $from, 'to_id' => $to]);

    return (int) ($query->fetchColumn() ?: 0);
}

function schedule_conflict(
    PDO $pdo,
    int $teacherId,
    int $branchId,
    string $room,
    int $day,
    int $start,
    int $end,
    ?int $excludeId = null
): ?string {
    $sql = 'SELECT s.id, s.teacher_id, s.branch_id, s.room, s.subject_name,
                   s.start_period, s.end_period, b.branch_name
            FROM schedules s
            JOIN branches b ON b.id = s.branch_id
            WHERE s.day_of_week = :day';
    $params = ['day' => $day];
    if ($excludeId !== null) {
        $sql .= ' AND s.id <> :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $sql .= ' AND s.start_period <= :end_period
              AND s.end_period >= :start_period
              AND (s.teacher_id = :teacher_id OR (s.branch_id = :branch_id AND s.room = :room))';
    $params += [
        'end_period' => $end,
        'start_period' => $start,
        'teacher_id' => $teacherId,
        'branch_id' => $branchId,
        'room' => $room,
    ];
    $query = $pdo->prepare($sql);
    $query->execute($params);

    foreach ($query->fetchAll() as $existing) {
        if ((int) $existing['teacher_id'] === $teacherId) {
            return sprintf(
                'Giảng viên đã có lịch "%s" tại %s ở tiết %s-%s trong khung giờ này.',
                $existing['subject_name'],
                $existing['branch_name'],
                $existing['start_period'],
                $existing['end_period']
            );
        }

        return sprintf(
            'Phòng %s đã được sử dụng tại %s trong khung giờ này.',
            $room,
            $existing['branch_name']
        );
    }

    $sql = 'SELECT branch_id, start_period, end_period
            FROM schedules
            WHERE teacher_id = :teacher_id AND day_of_week = :day';
    $params = ['teacher_id' => $teacherId, 'day' => $day];
    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $sql .= ' AND end_period < :start_period ORDER BY end_period DESC LIMIT 1';
    $params['start_period'] = $start;
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $previous = $query->fetch();

    if ($previous && (int) $previous['branch_id'] !== $branchId) {
        $minutes = travel_minutes($pdo, (int) $previous['branch_id'], $branchId);
        $freePeriods = $start - (int) $previous['end_period'] - 1;
        $requiredPeriods = (int) ceil($minutes / 50);
        if ($freePeriods < $requiredPeriods) {
            return sprintf(
                'Chưa đủ thời gian di chuyển giữa hai cơ sở: cần %d tiết trống (%d phút), hiện có %d tiết.',
                $requiredPeriods,
                $minutes,
                $freePeriods
            );
        }
    }

    $sql = 'SELECT branch_id, start_period, end_period
            FROM schedules
            WHERE teacher_id = :teacher_id AND day_of_week = :day';
    $params = ['teacher_id' => $teacherId, 'day' => $day];
    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $sql .= ' AND start_period > :end_period ORDER BY start_period ASC LIMIT 1';
    $params['end_period'] = $end;
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $next = $query->fetch();

    if ($next && (int) $next['branch_id'] !== $branchId) {
        $minutes = travel_minutes($pdo, $branchId, (int) $next['branch_id']);
        $freePeriods = (int) $next['start_period'] - $end - 1;
        $requiredPeriods = (int) ceil($minutes / 50);
        if ($freePeriods < $requiredPeriods) {
            return sprintf(
                'Chưa đủ thời gian di chuyển đến cơ sở kế tiếp: cần %d tiết trống (%d phút), hiện có %d tiết.',
                $requiredPeriods,
                $minutes,
                $freePeriods
            );
        }
    }

    return null;
}

function teacher_suggestions(
    PDO $pdo,
    string $subject,
    int $branch,
    int $day,
    int $start,
    int $end,
    ?int $excludeId = null
): array
{
    $query = $pdo->prepare(
        'SELECT t.id, t.full_name, t.expertise,
                (SELECT COUNT(*) FROM schedules s WHERE s.teacher_id = t.id AND s.day_of_week = :day) AS day_load
         FROM teachers t'
    );
    $query->execute(['day' => $day]);
    $teachers = $query->fetchAll();

    $keywords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($subject), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $keywords = array_values(array_filter($keywords, static fn (string $word): bool => mb_strlen($word) > 2));
    $suggestions = [];
    foreach ($teachers as $teacher) {
        if (schedule_conflict($pdo, (int) $teacher['id'], $branch, '', $day, $start, $end, $excludeId) !== null) {
            continue;
        }
        $expertise = mb_strtolower($teacher['expertise']);
        $score = 0;
        foreach ($keywords as $keyword) {
            if (mb_stripos($expertise, $keyword) !== false) {
                $score++;
            }
        }
        if ($score === 0 && $keywords !== []) {
            continue;
        }
        $teacher['match_score'] = $score;
        $teacher += schedule_efficiency($pdo, (int) $teacher['id'], $branch, $day, $start, $end, $excludeId);
        $suggestions[] = $teacher;
    }

    usort($suggestions, static function (array $a, array $b): int {
        $expertiseOrder = $b['match_score'] <=> $a['match_score'];
        if ($expertiseOrder !== 0) {
            return $expertiseOrder;
        }

        $travelOrder = $a['campus_changes'] <=> $b['campus_changes'];
        if ($travelOrder !== 0) {
            return $travelOrder;
        }

        $gapOrder = $a['dead_gaps'] <=> $b['dead_gaps'];
        if ($gapOrder !== 0) {
            return $gapOrder;
        }

        $loadOrder = $a['day_load'] <=> $b['day_load'];

        return $loadOrder !== 0 ? $loadOrder : strcmp($a['full_name'], $b['full_name']);
    });

    return $suggestions;
}

function schedule_rows(PDO $pdo, array $filters = []): array
{
    $sql = 'SELECT s.*, t.full_name, b.branch_name, b.address
            FROM schedules s
            JOIN teachers t ON t.id = s.teacher_id
            JOIN branches b ON b.id = s.branch_id
            WHERE 1 = 1';
    $params = [];
    foreach (['branch_id' => 's.branch_id', 'teacher_id' => 's.teacher_id'] as $key => $column) {
        if (isset($filters[$key]) && (int) $filters[$key] > 0) {
            $sql .= " AND {$column} = :{$key}";
            $params[$key] = (int) $filters[$key];
        }
    }
    if (isset($filters['from_date'], $filters['to_date'])) {
        $sql .= ' AND s.day_of_week BETWEEN :from_date AND :to_date';
        $params['from_date'] = $filters['from_date'];
        $params['to_date'] = $filters['to_date'];
    }
    $sql .= ' ORDER BY s.day_of_week, s.start_period, b.branch_name';

    $query = $pdo->prepare($sql);
    $query->execute($params);

    return $query->fetchAll();
}

function schedule_efficiency(
    PDO $pdo,
    int $teacherId,
    int $branch,
    int $day,
    int $start,
    int $end,
    ?int $excludeId = null
): array
{
    $sql = 'SELECT branch_id, start_period, end_period FROM schedules
            WHERE teacher_id = :teacher_id AND day_of_week = :day';
    $params = ['teacher_id' => $teacherId, 'day' => $day];
    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $sql .= ' ORDER BY start_period';
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $daySchedules = $query->fetchAll();
    $daySchedules[] = [
        'branch_id' => $branch,
        'start_period' => $start,
        'end_period' => $end,
    ];
    usort($daySchedules, static fn (array $a, array $b): int => $a['start_period'] <=> $b['start_period']);

    $campusChanges = 0;
    $deadGaps = 0;
    $previous = null;
    foreach ($daySchedules as $schedule) {
        if ($previous !== null) {
            $deadGaps += max(0, (int) $schedule['start_period'] - (int) $previous['end_period'] - 1);
            if ((int) $schedule['branch_id'] !== (int) $previous['branch_id']) {
                $campusChanges++;
            }
        }
        $previous = $schedule;
    }

    return ['campus_changes' => $campusChanges, 'dead_gaps' => $deadGaps];
}

$pdo = db();
$page = (string) ($_GET['page'] ?? 'dashboard');

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string) ($_POST['password'] ?? '');
    $query = $pdo->prepare(
        'SELECT u.id, u.email, u.password_hash, u.role, u.teacher_id, t.full_name
         FROM users u LEFT JOIN teachers t ON t.id = u.teacher_id
         WHERE u.email = :email'
    );
    $query->execute(['email' => $email ?: '']);
    $user = $query->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        set_flash('Email hoặc mật khẩu không chính xác.', 'error');
        redirect('?page=login');
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'teacher_id' => $user['teacher_id'] === null ? null : (int) $user['teacher_id'],
        'name' => $user['full_name'] ?: 'Quản trị viên',
    ];
    redirect($user['role'] === 'admin' ? '?page=dashboard' : '?page=portal');
}

if ($page === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $_SESSION = [];
    session_destroy();
    redirect('?page=login');
}

if ($page === 'schedule-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf_token();

    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $teacherId = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
    $branchId = filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT);
    $day = filter_var($_POST['day_of_week'] ?? null, FILTER_VALIDATE_INT);
    $start = filter_var($_POST['start_period'] ?? null, FILTER_VALIDATE_INT);
    $end = filter_var($_POST['end_period'] ?? null, FILTER_VALIDATE_INT);
    $subject = trim((string) ($_POST['subject_name'] ?? ''));
    $room = trim((string) ($_POST['room'] ?? ''));

    if (!$teacherId || !$branchId || !$day || !$start || !$end
        || $day < 1 || $day > 7 || $start < 1 || $end > 12 || $start > $end
        || $subject === '' || $room === ''
    ) {
        set_flash('Vui lòng nhập đầy đủ thông tin. Thứ phải từ 2 đến Chủ nhật, tiết từ 1 đến 12.', 'error');
        redirect('?page=schedules' . ($id ? '&edit=' . $id : ''));
    }

    $conflict = schedule_conflict($pdo, $teacherId, $branchId, $room, $day, $start, $end, $id ?: null);
    if ($conflict !== null) {
        set_flash($conflict, 'error');
        redirect('?page=schedules' . ($id ? '&edit=' . $id : ''));
    }

    $values = [
        'teacher_id' => $teacherId,
        'branch_id' => $branchId,
        'subject_name' => $subject,
        'room' => $room,
        'day_of_week' => $day,
        'start_period' => $start,
        'end_period' => $end,
        'shift_or_periods' => "Tiết {$start}-{$end}",
    ];
    if ($id) {
        $values['id'] = $id;
        $query = $pdo->prepare(
            'UPDATE schedules SET teacher_id = :teacher_id, branch_id = :branch_id,
             subject_name = :subject_name, room = :room, day_of_week = :day_of_week,
             start_period = :start_period, end_period = :end_period,
             shift_or_periods = :shift_or_periods WHERE id = :id'
        );
        $query->execute($values);
        set_flash('Đã cập nhật lịch dạy.');
    } else {
        $query = $pdo->prepare(
            'INSERT INTO schedules
             (teacher_id, branch_id, subject_name, room, day_of_week, start_period, end_period, shift_or_periods)
             VALUES (:teacher_id, :branch_id, :subject_name, :room, :day_of_week, :start_period, :end_period, :shift_or_periods)'
        );
        $query->execute($values);
        set_flash('Đã tạo lịch dạy.');
    }
    redirect('?page=schedules');
}

if ($page === 'schedule-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    verify_csrf_token();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        set_flash('Mã lịch dạy không hợp lệ.', 'error');
    } else {
        $query = $pdo->prepare('DELETE FROM schedules WHERE id = :id');
        $query->execute(['id' => $id]);
        set_flash('Đã xóa lịch dạy.');
    }
    redirect('?page=schedules');
}

$user = current_user();
if ($page === 'suggestions') {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    $branch = filter_var($_GET['branch_id'] ?? null, FILTER_VALIDATE_INT);
    $day = filter_var($_GET['day_of_week'] ?? null, FILTER_VALIDATE_INT);
    $start = filter_var($_GET['start_period'] ?? null, FILTER_VALIDATE_INT);
    $end = filter_var($_GET['end_period'] ?? null, FILTER_VALIDATE_INT);
    $subject = trim((string) ($_GET['subject_name'] ?? ''));
    if (!$branch || !$day || !$start || !$end || $day < 1 || $day > 7
        || $start < 1 || $end > 12 || $start > $end
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Thông tin môn học, cơ sở, thứ và tiết chưa hợp lệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $excludeId = filter_var($_GET['exclude_id'] ?? null, FILTER_VALIDATE_INT);
    echo json_encode(
        ['teachers' => teacher_suggestions($pdo, $subject, $branch, $day, $start, $end, $excludeId ?: null)],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($page === 'portal' && (!$user || $user['role'] !== 'teacher')) {
    redirect($user ? '?page=dashboard' : '?page=login');
}
if ($page !== 'login' && !$user) {
    redirect('?page=login');
}
if ($user && $user['role'] === 'teacher' && !in_array($page, ['portal', 'logout'], true)) {
    redirect('?page=portal');
}
if ($user && $user['role'] === 'admin' && $page === 'portal') {
    redirect('?page=dashboard');
}
if (!in_array($page, ['login', 'dashboard', 'schedules', 'portal'], true)) {
    $page = $user['role'] === 'teacher' ? 'portal' : 'dashboard';
}

$flash = take_flash();
$branches = $pdo->query('SELECT id, branch_name, address FROM branches ORDER BY branch_name')->fetchAll();
$teachers = $pdo->query('SELECT id, full_name, expertise FROM teachers ORDER BY full_name')->fetchAll();
$editSchedule = null;
if ($page === 'schedules' && isset($_GET['edit'])) {
    require_admin();
    $query = $pdo->prepare('SELECT * FROM schedules WHERE id = :id');
    $query->execute(['id' => (int) $_GET['edit']]);
    $editSchedule = $query->fetch() ?: null;
}

$scheduleFilters = [];
if ($page === 'dashboard') {
    $scheduleFilters = [
        'branch_id' => $_GET['branch_id'] ?? null,
        'teacher_id' => $_GET['teacher_id'] ?? null,
    ];
}
$portalPeriod = (string) ($_GET['period'] ?? 'week');
$portalDate = (string) ($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $portalDate) || strtotime($portalDate) === false) {
    $portalDate = date('Y-m-d');
}
$portalSchedules = [];
$weekStart = '';
$weekEnd = '';
if ($page === 'portal') {
    $selectedDate = new DateTimeImmutable($portalDate);
    if ($portalPeriod === 'month') {
        $rangeStart = $selectedDate->modify('first day of this month')->format('Y-m-d');
        $rangeEnd = $selectedDate->modify('last day of this month')->format('Y-m-d');
    } else {
        $rangeStart = $selectedDate->modify('monday this week')->format('Y-m-d');
        $rangeEnd = $selectedDate->modify('sunday this week')->format('Y-m-d');
        $weekStart = $rangeStart;
        $weekEnd = $rangeEnd;
    }
    $fromDay = $portalPeriod === 'month' ? 1 : (int) (new DateTimeImmutable($rangeStart))->format('N');
    $toDay = $portalPeriod === 'month' ? 7 : (int) (new DateTimeImmutable($rangeEnd))->format('N');
    $portalSchedules = schedule_rows($pdo, [
        'teacher_id' => $user['teacher_id'],
        'from_date' => $fromDay,
        'to_date' => $toDay,
    ]);
}

$allSchedules = $page === 'dashboard' ? schedule_rows($pdo, $scheduleFilters) : [];
$editing = $editSchedule;
$selectedDay = (int) ($editing['day_of_week'] ?? 1);
$selectedStart = (int) ($editing['start_period'] ?? 1);
$selectedEnd = (int) ($editing['end_period'] ?? 2);
$selectedBranch = (int) ($editing['branch_id'] ?? ($branches[0]['id'] ?? 0));
$selectedTeacher = (int) ($editing['teacher_id'] ?? ($teachers[0]['id'] ?? 0));
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $page === 'login' ? 'Đăng nhập' : 'HUIT Schedule Hub' ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($page === 'login'): ?>
    <main class="login-layout">
        <section class="login-card">
            <div class="brand-mark">H</div>
            <p class="eyebrow">HUIT SCHEDULE HUB</p>
            <h1>Đăng nhập hệ thống</h1>
            <p class="muted">Tra cứu và quản lý lịch giảng dạy đa cơ sở.</p>
            <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
            <form method="post" action="?page=login" class="stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Email trường<input type="email" name="email" autocomplete="username" required autofocus></label>
                <label>Mật khẩu<input type="password" name="password" autocomplete="current-password" required></label>
                <button class="button primary wide" type="submit">Đăng nhập</button>
            </form>
            <p class="login-help">Tài khoản quản trị và giảng viên được tạo bởi lệnh <code>php database/seed.php</code>.</p>
        </section>
    </main>
<?php else: ?>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="<?= $user['role'] === 'teacher' ? '?page=portal' : '?page=dashboard' ?>">
                <span class="brand-mark small">H</span><span>Schedule<span class="brand-light">Hub</span></span>
            </a>
            <div class="nav-caption">KHÔNG GIAN LÀM VIỆC</div>
            <?php if ($user['role'] === 'admin'): ?>
                <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span>▦</span>Tổng quan</a>
                <a class="nav-link <?= $page === 'schedules' ? 'active' : '' ?>" href="?page=schedules"><span>▤</span>Quản lý lịch dạy</a>
            <?php else: ?>
                <a class="nav-link active" href="?page=portal"><span>▦</span>Lịch của tôi</a>
            <?php endif; ?>
            <div class="sidebar-bottom">
                <div class="user-chip"><span class="avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span><span><strong><?= e($user['name']) ?></strong><small><?= $user['role'] === 'admin' ? 'Quản trị viên' : 'Giảng viên' ?></small></span></div>
                <form method="post" action="?page=logout">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <button class="logout-button" type="submit">Đăng xuất</button>
                </form>
            </div>
        </aside>
        <main class="main-content">
            <header class="topbar"><span>HUIT <span class="muted">/</span> <?= $page === 'dashboard' ? 'Tổng quan' : ($page === 'schedules' ? 'Lịch giảng dạy' : 'Cổng giảng viên') ?></span><span class="top-date"><?= e(date('d/m/Y')) ?></span></header>
            <div class="page-content">
                <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
                <?php if ($page === 'dashboard'): ?>
                    <?php
                    $scheduleCount = count($allSchedules);
                    $teacherCount = count(array_unique(array_column($allSchedules, 'teacher_id')));
                    $branchCount = count(array_unique(array_column($allSchedules, 'branch_id')));
                    $countsByDay = array_fill(1, 7, 0);
                    foreach ($allSchedules as $schedule) {
                        $countsByDay[(int) $schedule['day_of_week']]++;
                    }
                    $maxDayCount = max(1, max($countsByDay));
                    ?>
                    <div class="page-heading"><div><p class="eyebrow">QUẢN LÝ ĐÀO TẠO</p><h1>Tổng quan lịch dạy</h1><p class="muted">Theo dõi phân bổ giảng dạy và hoạt động tại các cơ sở.</p></div><a class="button primary" href="?page=schedules">＋ Tạo lịch dạy</a></div>
                    <section class="stat-grid">
                        <article class="stat-card"><span class="stat-icon blue">▤</span><div><span class="stat-label">Lịch đang hiển thị</span><strong><?= $scheduleCount ?></strong><small>Lịch giảng dạy</small></div></article>
                        <article class="stat-card"><span class="stat-icon purple">♙</span><div><span class="stat-label">Giảng viên</span><strong><?= $teacherCount ?></strong><small>Có lịch trong bộ lọc</small></div></article>
                        <article class="stat-card"><span class="stat-icon green">⌖</span><div><span class="stat-label">Cơ sở</span><strong><?= $branchCount ?></strong><small>Đang được sử dụng</small></div></article>
                    </section>
                    <section class="panel">
                        <div class="panel-heading"><div><h2>Phân bổ theo ngày</h2><p class="muted">Số lớp theo từng ngày trong tuần</p></div></div>
                        <div class="chart">
                            <?php foreach ($countsByDay as $day => $count): ?>
                                <div class="chart-row"><span><?= e(day_name($day)) ?></span><div class="bar-track"><div class="bar" style="width: <?= (int) round($count / $maxDayCount * 100) ?>%"></div></div><strong><?= $count ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-heading"><div><h2>Lịch giảng dạy</h2><p class="muted">Lọc nhanh theo cơ sở hoặc giảng viên</p></div></div>
                        <form class="filter-form" method="get">
                            <input type="hidden" name="page" value="dashboard">
                            <label>Cơ sở<select name="branch_id"><option value="">Tất cả cơ sở</option><?php foreach ($branches as $branch): ?><option value="<?= (int) $branch['id'] ?>" <?= (string) ($scheduleFilters['branch_id'] ?? '') === (string) $branch['id'] ? 'selected' : '' ?>><?= e($branch['branch_name']) ?></option><?php endforeach; ?></select></label>
                            <label>Giảng viên<select name="teacher_id"><option value="">Tất cả giảng viên</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= (string) ($scheduleFilters['teacher_id'] ?? '') === (string) $teacher['id'] ? 'selected' : '' ?>><?= e($teacher['full_name']) ?></option><?php endforeach; ?></select></label>
                            <button class="button secondary" type="submit">Lọc lịch</button>
                        </form>
                        <?= render_schedule_table($allSchedules, false) ?>
                    </section>
                <?php elseif ($page === 'schedules'): ?>
                    <div class="page-heading"><div><p class="eyebrow">PHÂN CÔNG GIẢNG DẠY</p><h1><?= $editing ? 'Cập nhật lịch dạy' : 'Quản lý lịch dạy' ?></h1><p class="muted">Phân công theo chuyên môn, khung tiết và cơ sở.</p></div></div>
                    <section class="panel">
                        <div class="panel-heading"><div><h2><?= $editing ? 'Chỉnh sửa lịch' : 'Tạo lịch mới' ?></h2><p class="muted">Hệ thống tự kiểm tra trùng lịch và thời gian di chuyển.</p></div></div>
                        <?php if (!$branches || !$teachers): ?>
                            <div class="notice error">Chưa có cơ sở hoặc giảng viên. Hãy chạy <code>php database/seed.php</code> để tạo dữ liệu mẫu.</div>
                        <?php else: ?>
                            <form method="post" action="?page=schedule-save" class="schedule-form" id="schedule-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                                <label>Tên học phần<input type="text" name="subject_name" id="subject_name" value="<?= e($editing['subject_name'] ?? '') ?>" placeholder="Ví dụ: Lập trình web" required></label>
                                <label>Cơ sở<select name="branch_id" id="branch_id" required><?php foreach ($branches as $branch): ?><option value="<?= (int) $branch['id'] ?>" <?= $selectedBranch === (int) $branch['id'] ? 'selected' : '' ?>><?= e($branch['branch_name']) ?></option><?php endforeach; ?></select></label>
                                <label>Thứ<select name="day_of_week" id="day_of_week"><?php for ($day = 1; $day <= 7; $day++): ?><option value="<?= $day ?>" <?= $selectedDay === $day ? 'selected' : '' ?>><?= e(day_name($day)) ?></option><?php endfor; ?></select></label>
                                <label>Tiết bắt đầu<select name="start_period" id="start_period"><?php for ($period = 1; $period <= 12; $period++): ?><option value="<?= $period ?>" <?= $selectedStart === $period ? 'selected' : '' ?>>Tiết <?= $period ?></option><?php endfor; ?></select></label>
                                <label>Tiết kết thúc<select name="end_period" id="end_period"><?php for ($period = 1; $period <= 12; $period++): ?><option value="<?= $period ?>" <?= $selectedEnd === $period ? 'selected' : '' ?>>Tiết <?= $period ?></option><?php endfor; ?></select></label>
                                <label>Phòng<input type="text" name="room" value="<?= e($editing['room'] ?? '') ?>" placeholder="A.101" required></label>
                                <label class="teacher-field">Giảng viên<select name="teacher_id" id="teacher_id" required><?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>" <?= $selectedTeacher === (int) $teacher['id'] ? 'selected' : '' ?>><?= e($teacher['full_name']) ?> — <?= e($teacher['expertise']) ?></option><?php endforeach; ?></select></label>
                                <div class="suggestion-box teacher-field" id="suggestion-box">Nhập tên học phần và chọn khung giờ để xem gợi ý giảng viên phù hợp.</div>
                                <div class="form-actions"><button class="button primary" type="submit"><?= $editing ? 'Lưu thay đổi' : '＋ Thêm lịch dạy' ?></button><?php if ($editing): ?><a class="button secondary" href="?page=schedules">Hủy chỉnh sửa</a><?php endif; ?></div>
                            </form>
                        <?php endif; ?>
                    </section>
                    <section class="panel">
                        <div class="panel-heading"><div><h2>Danh sách lịch dạy</h2><p class="muted">Sắp xếp theo thứ và tiết bắt đầu.</p></div></div>
                        <?= render_schedule_table(schedule_rows($pdo), true) ?>
                    </section>
                <?php else: ?>
                    <?php
                    $days = [];
                    foreach ($portalSchedules as $schedule) {
                        $days[(int) $schedule['day_of_week']][] = $schedule;
                    }
                    $monthStart = (new DateTimeImmutable($portalDate))->modify('first day of this month');
                    $monthLength = (int) $monthStart->format('t');
                    $monthOffset = (int) $monthStart->format('N') - 1;
                    ?>
                    <div class="page-heading"><div><p class="eyebrow">CỔNG THÔNG TIN GIẢNG VIÊN</p><h1>Lịch dạy của tôi</h1><p class="muted">Xin chào <?= e($user['name']) ?>, đây là lịch giảng dạy của bạn.</p></div></div>
                    <section class="panel">
                        <div class="panel-heading"><div><h2><?= $portalPeriod === 'month' ? 'Lịch tháng' : 'Lịch tuần' ?></h2><p class="muted"><?= $portalPeriod === 'month' ? e((new DateTimeImmutable($portalDate))->format('m/Y')) : e(date('d/m', strtotime($weekStart)) . ' – ' . date('d/m/Y', strtotime($weekEnd))) ?></p></div></div>
                        <form class="filter-form" method="get">
                            <input type="hidden" name="page" value="portal">
                            <label>Ngày tham chiếu<input type="date" name="date" value="<?= e($portalDate) ?>"></label>
                            <label>Hiển thị<select name="period"><option value="week" <?= $portalPeriod !== 'month' ? 'selected' : '' ?>>Tuần</option><option value="month" <?= $portalPeriod === 'month' ? 'selected' : '' ?>>Tháng</option></select></label>
                            <button class="button secondary" type="submit">Xem lịch</button>
                        </form>
                        <?php if (!$portalSchedules): ?><div class="empty-state">Không có lịch dạy trong khoảng thời gian này.</div><?php endif; ?>
                        <?php if ($portalPeriod === 'month'): ?>
                            <div class="portal-days month-grid">
                                <?php foreach (range(1, 7) as $day): ?><div class="month-weekday"><?= e(day_name($day)) ?></div><?php endforeach; ?>
                                <?php for ($blank = 0; $blank < $monthOffset; $blank++): ?><div class="month-blank"></div><?php endfor; ?>
                                <?php for ($number = 1; $number <= $monthLength; $number++):
                                    $date = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('m'), $number);
                                    $dateSchedules = $days[(int) $date->format('N')] ?? [];
                                ?>
                                    <article class="portal-day month-day"><h3><?= $number ?></h3>
                                        <?php foreach ($dateSchedules as $schedule): ?>
                                            <div class="class-card"><div class="class-time">Tiết <?= (int) $schedule['start_period'] ?>–<?= (int) $schedule['end_period'] ?></div><strong><?= e($schedule['subject_name']) ?></strong><span><?= e($schedule['branch_name']) ?> · <?= e($schedule['room']) ?></span></div>
                                        <?php endforeach; ?>
                                    </article>
                                <?php endfor; ?>
                            </div>
                        <?php else: ?>
                            <div class="portal-days">
                                <?php $weekDate = new DateTimeImmutable($weekStart); ?>
                                <?php for ($day = 1; $day <= 7; $day++):
                                    $date = $weekDate->modify('+' . ($day - 1) . ' days');
                                    $daySchedules = $days[$day] ?? [];
                                ?>
                                    <article class="portal-day"><h3><?= e(day_name($day)) ?> · <?= e($date->format('d/m')) ?></h3>
                                        <?php if (!$daySchedules): ?><small class="no-classes">Không có lịch dạy</small><?php endif; ?>
                                        <?php foreach ($daySchedules as $schedule): ?>
                                            <div class="class-card"><div class="class-time">Tiết <?= (int) $schedule['start_period'] ?>–<?= (int) $schedule['end_period'] ?></div><strong><?= e($schedule['subject_name']) ?></strong><span><?= e($schedule['branch_name']) ?> · <?= e($schedule['room']) ?></span><small><?= e($schedule['address']) ?></small></div>
                                        <?php endforeach; ?>
                                    </article>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
<?php endif; ?>
<script src="assets/app.js" defer></script>
</body>
</html>
<?php

function render_schedule_table(array $schedules, bool $withActions): void
{
    if (!$schedules) {
        echo '<div class="empty-state">Chưa có lịch dạy phù hợp. Hãy tạo lịch mới hoặc thay đổi bộ lọc.</div>';
        return;
    }
    ?>
    <div class="table-wrap"><table><thead><tr><th>Ngày / tiết</th><th>Học phần</th><th>Giảng viên</th><th>Cơ sở / phòng</th><?php if ($withActions): ?><th>Thao tác</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($schedules as $schedule): ?>
            <tr>
                <td><strong><?= e(day_name((int) $schedule['day_of_week'])) ?></strong><small>Tiết <?= (int) $schedule['start_period'] ?>–<?= (int) $schedule['end_period'] ?></small></td>
                <td><?= e($schedule['subject_name']) ?></td>
                <td><?= e($schedule['full_name']) ?></td>
                <td><?= e($schedule['branch_name']) ?><small><?= e($schedule['room']) ?></small></td>
                <?php if ($withActions): ?><td><div class="row-actions"><a class="text-action" href="?page=schedules&edit=<?= (int) $schedule['id'] ?>">Sửa</a><form method="post" action="?page=schedule-delete" onsubmit="return confirm('Bạn có chắc muốn xóa lịch dạy này?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>"><button class="text-action danger-text" type="submit">Xóa</button></form></div></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php
}
