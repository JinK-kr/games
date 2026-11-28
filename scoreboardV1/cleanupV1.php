<?php
// 1회용 정리: 배포 확인 중 실수로 저장된 시험 기록 1건(id 1)만 지운다. 실행한 뒤 이 파일은 저장소에서 지운다.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $env = static fn (string $k): string => (string) (getenv($k) ?: ($_ENV[$k] ?? $_SERVER[$k] ?? ''));
    $port = $env('DB_PORT') !== '' ? $env('DB_PORT') : '5432';
    $pdo = new PDO("pgsql:host={$env('DB_HOST')};port={$port};dbname={$env('DB_NAME')}", $env('DB_USER'), $env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $stmt = $pdo->prepare("DELETE FROM scores WHERE id = 1 AND board = '2d' AND name = :name AND time_ms = 60000");
    $stmt->execute([':name' => '열세글자이름입니다아아아']);
    echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('cleanupV1: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
