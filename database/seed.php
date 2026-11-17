<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Faker\Factory;

$pdo = db();
$faker = Factory::create('vi_VN');
$pdo->beginTransaction();

$branchData = [
    ['Cơ sở Tân Phú', 'Số 140 Lê Trọng Tấn, phường Tây Thạnh, TP. Hồ Chí Minh'],
    ['Cơ sở Quang Trung', 'Công viên Phần mềm Quang Trung, Quận 12, TP. Hồ Chí Minh'],
    ['Trung tâm liên kết Thủ Đức', 'TP. Thủ Đức, TP. Hồ Chí Minh'],
];
$branchIds = [];
foreach ($branchData as [$name, $address]) {
    $find = $pdo->prepare('SELECT id FROM branches WHERE branch_name = :name');
    $find->execute(['name' => $name]);
    $branchId = $find->fetchColumn();
    if (!$branchId) {
        $insert = $pdo->prepare('INSERT INTO branches (branch_name, address) VALUES (:name, :address)');
        $insert->execute(['name' => $name, 'address' => $address]);
        $branchId = $pdo->lastInsertId();
    }
    $branchIds[] = (int) $branchId;
}

$routes = [
    [$branchIds[0], $branchIds[1], 45],
    [$branchIds[1], $branchIds[0], 45],
    [$branchIds[0], $branchIds[2], 75],
    [$branchIds[2], $branchIds[0], 75],
    [$branchIds[1], $branchIds[2], 90],
    [$branchIds[2], $branchIds[1], 90],
];
$routeInsert = $pdo->prepare(
    'INSERT INTO branch_travel_times (from_branch_id, to_branch_id, travel_minutes)
     VALUES (:from_id, :to_id, :minutes)
     ON DUPLICATE KEY UPDATE travel_minutes = VALUES(travel_minutes)'
);
foreach ($routes as [$from, $to, $minutes]) {
    $routeInsert->execute(['from_id' => $from, 'to_id' => $to, 'minutes' => $minutes]);
}

$teacherData = [
    ['Nguyễn Minh An', 'an.nguyen@huit.local', 'Lập trình web, PHP, JavaScript'],
    ['Trần Thu Hà', 'ha.tran@huit.local', 'Cơ sở dữ liệu, SQL, Phân tích dữ liệu'],
    ['Lê Quốc Bảo', 'bao.le@huit.local', 'Mạng máy tính, An toàn thông tin'],
];
$teacherIds = [];
foreach ($teacherData as $index => [$name, $email, $expertise]) {
    $find = $pdo->prepare('SELECT id FROM teachers WHERE email = :email');
    $find->execute(['email' => $email]);
    $teacherId = $find->fetchColumn();
    if (!$teacherId) {
        $insert = $pdo->prepare(
            'INSERT INTO teachers (full_name, email, phone, expertise)
             VALUES (:name, :email, :phone, :expertise)'
        );
        $insert->execute([
            'name' => $name,
            'email' => $email,
            'phone' => $faker->phoneNumber(),
            'expertise' => $expertise,
        ]);
        $teacherId = $pdo->lastInsertId();
    }
    $teacherIds[] = (int) $teacherId;
    $account = $pdo->prepare(
        'INSERT INTO users (email, password_hash, role, teacher_id)
         VALUES (:email, :password_hash, :role, :teacher_id)
         ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id)'
    );
    $account->execute([
        'email' => $email,
        'password_hash' => password_hash('Teacher123!', PASSWORD_DEFAULT),
        'role' => 'teacher',
        'teacher_id' => $teacherId,
    ]);
}

for ($index = 4; $index <= 10; $index++) {
    $email = sprintf('gv%02d@huit.local', $index);
    $find = $pdo->prepare('SELECT id FROM teachers WHERE email = :email');
    $find->execute(['email' => $email]);
    $teacherId = $find->fetchColumn();
    if (!$teacherId) {
        $expertise = ['Lập trình web, PHP', 'Cơ sở dữ liệu, SQL', 'Mạng máy tính', 'Phân tích dữ liệu'];
        $insert = $pdo->prepare(
            'INSERT INTO teachers (full_name, email, phone, expertise)
             VALUES (:name, :email, :phone, :expertise)'
        );
        $insert->execute([
            'name' => $faker->name(),
            'email' => $email,
            'phone' => $faker->phoneNumber(),
            'expertise' => $expertise[$index % count($expertise)],
        ]);
        $teacherId = $pdo->lastInsertId();
    }
    $account = $pdo->prepare(
        'INSERT INTO users (email, password_hash, role, teacher_id)
         VALUES (:email, :password_hash, :role, :teacher_id)
         ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id)'
    );
    $account->execute([
        'email' => $email,
        'password_hash' => password_hash('Teacher123!', PASSWORD_DEFAULT),
        'role' => 'teacher',
        'teacher_id' => (int) $teacherId,
    ]);
}

$admin = $pdo->prepare(
    'INSERT INTO users (email, password_hash, role, teacher_id)
     VALUES (:email, :password_hash, :role, NULL)
     ON DUPLICATE KEY UPDATE role = VALUES(role), teacher_id = NULL'
);
$admin->execute([
    'email' => 'admin@huit.local',
    'password_hash' => password_hash('Admin123!', PASSWORD_DEFAULT),
    'role' => 'admin',
]);

$sampleSchedules = [
    [$teacherIds[0], $branchIds[0], 'Lập trình web', 'A.101', 1, 1, 3],
    [$teacherIds[1], $branchIds[0], 'Cơ sở dữ liệu', 'B.203', 2, 4, 6],
    [$teacherIds[2], $branchIds[1], 'Mạng máy tính', 'P.302', 3, 1, 3],
];
$scheduleInsert = $pdo->prepare(
    'INSERT INTO schedules
     (teacher_id, branch_id, subject_name, room, day_of_week, start_period, end_period, shift_or_periods)
     SELECT :teacher_id, :branch_id, :subject, :room, :day, :start_period, :end_period, :label
     WHERE NOT EXISTS (
       SELECT 1 FROM schedules WHERE teacher_id = :existing_teacher
         AND day_of_week = :existing_day AND subject_name = :existing_subject
     )'
);
foreach ($sampleSchedules as [$teacherId, $branchId, $subject, $room, $day, $start, $end]) {
    $scheduleInsert->execute([
        'teacher_id' => $teacherId,
        'branch_id' => $branchId,
        'subject' => $subject,
        'room' => $room,
        'day' => $day,
        'start_period' => $start,
        'end_period' => $end,
        'label' => "Tiết {$start}-{$end}",
        'existing_teacher' => $teacherId,
        'existing_day' => $day,
        'existing_subject' => $subject,
    ]);
}

$pdo->commit();

echo "Đã tạo dữ liệu mẫu.\n";
echo "Quản trị: admin@huit.local / Admin123!\n";
echo "Giảng viên: an.nguyen@huit.local / Teacher123!\n";
