<?php
// 관리자 API (cafe24 PHP 8.2 + PostgreSQL). 순위 기록(scores)과 방명록(guestbook)을 보고 지운다.
//
// 모든 요청은 POST JSON이고 관리자 비밀번호를 key에 담는다(주소창·로그에 남지 않도록 GET을 쓰지 않음).
//   {key, action:"summary"}                                     → 표별 개수
//   {key, action:"scores", board:"all"|"2d"|"3d", offset, limit} → 순위 기록, 최근에 올린 순
//   {key, action:"guestbook", offset, limit}                    → 방명록 글, 최근 글 순
//   {key, action:"delete_score", id} / {key, action:"delete_guestbook", id}
//
// 비밀번호 자체는 어디에도 저장하지 않는다. cafe24 환경 변수 ADMIN_KEY_HASH에
// "pbkdf2_sha256:<반복 횟수>:<salt base64>:<hash base64>" 형식의 값만 넣어 두고, 받은 key로 같은 값을 계산해 비교한다.
// (저장소가 공개라서 비밀번호나 해시를 코드에 넣지 않음. '$'는 환경 변수에서 바뀔 수 있어 ':'로 구분)

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

const LIMIT_DEFAULT = 50;
const LIMIT_MAX = 200;
const KEY_MAX = 200;

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

// 받은 key가 ADMIN_KEY_HASH와 맞는지 확인. 설정이 없으면 null(관리자 기능 꺼짐)
function keyMatches(string $key): ?bool
{
    $parts = explode(':', trim(env('ADMIN_KEY_HASH')));
    if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') {
        return null;
    }
    $iterations = (int) $parts[1];
    $salt = base64_decode($parts[2], true);
    $expected = base64_decode($parts[3], true);
    if ($iterations < 10000 || $iterations > 2000000 || $salt === false || $expected === false || $expected === '') {
        return null;
    }
    $actual = hash_pbkdf2('sha256', $key, $salt, $iterations, strlen($expected), true);
    return hash_equals($expected, $actual);
}

function connect(): PDO
{
    $host = env('DB_HOST');
    $name = env('DB_NAME');
    if ($host === '' || $name === '') {
        fail(503, 'DB가 아직 준비되지 않았습니다.');
    }
    $port = env('DB_PORT') !== '' ? env('DB_PORT') : '5432';
    return new PDO("pgsql:host={$host};port={$port};dbname={$name}", env('DB_USER'), env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

// 아직 아무도 쓰지 않아 표가 없을 수 있음 (표는 scoreboardV1.php, guestbookV1.php가 처음 요청 때 만듦)
function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT to_regclass(:name)');
    $stmt->execute([':name' => 'public.' . $table]);
    return $stmt->fetchColumn() !== null;
}

function intIn(mixed $value, int $min, int $max, int $default): int
{
    if (is_int($value) || (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1)) {
        $n = (int) $value;
        if ($n >= $min && $n <= $max) {
            return $n;
        }
    }
    return $default;
}

function countRows(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

const KST = "to_char(created_at AT TIME ZONE 'Asia/Seoul', 'YYYY-MM-DD HH24:MI:SS') AS at";

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        fail(405, '지원하지 않는 요청입니다.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 4096);
    $input = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($input)) {
        fail(400, '요청 형식이 올바르지 않습니다.');
    }

    $key = $input['key'] ?? null;
    if (!is_string($key) || $key === '' || strlen($key) > KEY_MAX) {
        fail(401, '관리자 비밀번호를 입력해 주세요.');
    }
    $matches = keyMatches($key);
    if ($matches === null) {
        fail(503, '관리자 비밀번호가 서버에 설정되지 않았습니다.');
    }
    if ($matches === false) {
        sleep(1); // 여러 번 맞혀 보는 것을 늦춤
        fail(401, '관리자 비밀번호가 맞지 않습니다.');
    }

    $pdo = connect();
    $hasScores = tableExists($pdo, 'scores');
    $hasGuestbook = tableExists($pdo, 'guestbook');
    $action = $input['action'] ?? '';
    $limit = intIn($input['limit'] ?? null, 1, LIMIT_MAX, LIMIT_DEFAULT);
    $offset = intIn($input['offset'] ?? null, 0, 1000000, 0);

    if ($action === 'summary') {
        reply(200, [
            'ok' => true,
            'scores2d' => $hasScores ? countRows($pdo, "SELECT count(*) FROM scores WHERE board = '2d'") : 0,
            'scores3d' => $hasScores ? countRows($pdo, "SELECT count(*) FROM scores WHERE board = '3d'") : 0,
            'guestbook' => $hasGuestbook ? countRows($pdo, 'SELECT count(*) FROM guestbook') : 0,
        ]);
    }

    if ($action === 'scores') {
        $board = $input['board'] ?? 'all';
        if (!in_array($board, ['all', '2d', '3d'], true)) {
            fail(400, '놀이판은 all, 2d, 3d 중 하나로 지정해 주세요.');
        }
        if (!$hasScores) {
            reply(200, ['ok' => true, 'total' => 0, 'rows' => []]);
        }
        $where = $board === 'all' ? '' : 'WHERE board = :board';
        $params = $board === 'all' ? [] : [':board' => $board];
        $stmt = $pdo->prepare("SELECT id, board, name, time_ms, rolls, players, " . KST . " FROM scores {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'board' => $r['board'], 'name' => $r['name'], 'time_ms' => (int) $r['time_ms'],
            'rolls' => (int) $r['rolls'], 'players' => (int) $r['players'], 'at' => $r['at'],
        ], $stmt->fetchAll());
        reply(200, ['ok' => true, 'total' => countRows($pdo, "SELECT count(*) FROM scores {$where}", $params), 'rows' => $rows]);
    }

    if ($action === 'guestbook') {
        if (!$hasGuestbook) {
            reply(200, ['ok' => true, 'total' => 0, 'rows' => []]);
        }
        $stmt = $pdo->prepare('SELECT id, name, message, ' . KST . ' FROM guestbook ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'message' => $r['message'], 'at' => $r['at'],
        ], $stmt->fetchAll());
        reply(200, ['ok' => true, 'total' => countRows($pdo, 'SELECT count(*) FROM guestbook'), 'rows' => $rows]);
    }

    if ($action === 'delete_score' || $action === 'delete_guestbook') {
        $table = $action === 'delete_score' ? 'scores' : 'guestbook';
        $exists = $table === 'scores' ? $hasScores : $hasGuestbook;
        $id = intIn($input['id'] ?? null, 1, PHP_INT_MAX, 0);
        if ($id === 0) {
            fail(400, '지울 항목을 지정해 주세요.');
        }
        if (!$exists) {
            fail(404, '이미 지워졌거나 없는 항목입니다.');
        }
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            fail(404, '이미 지워졌거나 없는 항목입니다.');
        }
        reply(200, ['ok' => true, 'id' => $id]);
    }

    fail(400, '지원하지 않는 요청입니다.');
} catch (Throwable $e) {
    error_log('adminV1: ' . $e->getMessage());
    fail(500, 'DB에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.');
}
