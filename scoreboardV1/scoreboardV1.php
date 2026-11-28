<?php
// 빠른 클리어 순위 API (cafe24 PHP 8.2 + PostgreSQL)
//
//   GET  ?board=2d|3d[&limit=10]                    → 놀이판별 빠른 순 목록
//   POST {board, name, time_ms, rolls, players}      → 기록 추가 후 그 기록의 순위
//
// 응답은 늘 JSON이고 { ok: true, ... } 또는 { ok: false, error: "사용자에게 보일 문장" } 형식이다.
// DB 접속 정보는 cafe24가 넣어 주는 환경 변수(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD)만 쓴다.
// 테이블이 없으면 처음 요청 때 만든다.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const BOARDS = ['2d', '3d'];
const NAME_MAX = 12;
const TIME_MIN = 10000;    // 10초보다 빨리 100칸에 닿는 판은 없음
const TIME_MAX = 7200000;  // 2시간
const ROLLS_MAX = 500;
const LIMIT_DEFAULT = 10;
const LIMIT_MAX = 50;

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $message): never
{
    reply($status, ['ok' => false, 'error' => $message]);
}

function env(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
    }
    return (string) $value;
}

function connect(): PDO
{
    $host = env('DB_HOST');
    $name = env('DB_NAME');
    if ($host === '' || $name === '') {
        fail(503, '순위 저장소가 아직 준비되지 않았습니다.');
    }
    $port = env('DB_PORT') !== '' ? env('DB_PORT') : '5432';
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", env('DB_USER'), env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    ensureSchema($pdo);
    return $pdo;
}

// 테이블 정의. CHECK 조건이 아래 PHP 검사와 같은 범위를 한 번 더 지킨다.
function ensureSchema(PDO $pdo): void
{
    if ($pdo->query("SELECT to_regclass('public.scores')")->fetchColumn() !== null) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scores (
          id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
          board      text        NOT NULL CHECK (board IN ('2d', '3d')),
          name       text        NOT NULL CHECK (char_length(name) BETWEEN 1 AND 12),
          time_ms    integer     NOT NULL CHECK (time_ms BETWEEN 10000 AND 7200000),
          rolls      integer     NOT NULL CHECK (rolls BETWEEN 1 AND 500),
          players    smallint    NOT NULL CHECK (players BETWEEN 2 AND 4),
          created_at timestamptz NOT NULL DEFAULT now()
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS scores_rank_idx ON scores (board, time_ms, rolls, id)');
}

// 정수만 받는다(문자열 숫자 "12"는 받고, 12.5나 "12abc"는 거절).
function intIn(mixed $value, int $min, int $max): ?int
{
    if (is_int($value)) {
        $n = $value;
    } elseif (is_string($value) && preg_match('/^-?\d{1,10}$/', $value) === 1) {
        $n = (int) $value;
    } else {
        return null;
    }
    return $n >= $min && $n <= $max ? $n : null;
}

// 이름: 제어 문자를 빼고 공백을 하나로 줄인 뒤 1~12자(코드 포인트 기준, PostgreSQL char_length와 같음)
function cleanName(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value);
    if ($name === null) {
        return null; // 올바른 UTF-8이 아님
    }
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    $length = preg_match_all('/./us', $name);
    return $length >= 1 && $length <= NAME_MAX ? $name : null;
}

function listScores(PDO $pdo, string $board, int $limit): array
{
    $stmt = $pdo->prepare("
        SELECT id, name, time_ms, rolls, players,
               to_char(created_at AT TIME ZONE 'Asia/Seoul', 'YYYY-MM-DD') AS day
        FROM scores
        WHERE board = :board
        ORDER BY time_ms, rolls, id
        LIMIT :limit
    ");
    $stmt->bindValue(':board', $board);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->fetchAll() as $i => $row) {
        $rows[] = [
            'rank' => $i + 1,
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'time_ms' => (int) $row['time_ms'],
            'rolls' => (int) $row['rolls'],
            'players' => (int) $row['players'],
            'day' => $row['day'],
        ];
    }
    return $rows;
}

function countScores(PDO $pdo, string $board): int
{
    $stmt = $pdo->prepare('SELECT count(*) FROM scores WHERE board = :board');
    $stmt->execute([':board' => $board]);
    return (int) $stmt->fetchColumn();
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $board = (string) ($_GET['board'] ?? '');
        if (!in_array($board, BOARDS, true)) {
            fail(400, '놀이판은 2d 또는 3d로 지정해 주세요.');
        }
        $limit = intIn($_GET['limit'] ?? LIMIT_DEFAULT, 1, LIMIT_MAX) ?? LIMIT_DEFAULT;
        $pdo = connect();
        reply(200, ['ok' => true, 'board' => $board, 'total' => countScores($pdo, $board), 'scores' => listScores($pdo, $board, $limit)]);
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 4096);
        $input = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($input)) {
            fail(400, '기록 형식이 올바르지 않습니다.');
        }
        $board = $input['board'] ?? null;
        if (!is_string($board) || !in_array($board, BOARDS, true)) {
            fail(400, '놀이판은 2d 또는 3d로 지정해 주세요.');
        }
        $name = cleanName($input['name'] ?? null);
        if ($name === null) {
            fail(400, '이름은 1~' . NAME_MAX . '자로 입력해 주세요.');
        }
        $time = intIn($input['time_ms'] ?? null, TIME_MIN, TIME_MAX);
        $rolls = intIn($input['rolls'] ?? null, 1, ROLLS_MAX);
        $players = intIn($input['players'] ?? null, 2, 4);
        if ($time === null || $rolls === null || $players === null) {
            fail(400, '기록 값이 올바르지 않습니다.');
        }

        $pdo = connect();
        $insert = $pdo->prepare('INSERT INTO scores (board, name, time_ms, rolls, players) VALUES (:board, :name, :time, :rolls, :players) RETURNING id');
        $insert->execute([':board' => $board, ':name' => $name, ':time' => $time, ':rolls' => $rolls, ':players' => $players]);
        $id = (int) $insert->fetchColumn();

        // 순위: 시간이 짧은 순, 같으면 굴린 횟수가 적은 순, 그래도 같으면 먼저 올린 순
        $rank = $pdo->prepare('SELECT count(*) + 1 FROM scores WHERE board = :board AND (time_ms, rolls, id) < (CAST(:time AS integer), CAST(:rolls AS integer), CAST(:id AS bigint))');
        $rank->execute([':board' => $board, ':time' => $time, ':rolls' => $rolls, ':id' => $id]);

        reply(201, ['ok' => true, 'id' => $id, 'rank' => (int) $rank->fetchColumn(), 'total' => countScores($pdo, $board)]);
    }

    header('Allow: GET, POST');
    fail(405, '지원하지 않는 요청입니다.');
} catch (Throwable $e) {
    error_log('scoreboardV1: ' . $e->getMessage());
    fail(500, '순위 저장소에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.');
}
