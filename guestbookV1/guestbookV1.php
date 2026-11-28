<?php
// 방명록 API (cafe24 PHP 8.2 + PostgreSQL). scoreboardV1.php와 같은 DB를 쓰고 응답 형식도 같다.
//
//   GET  [?before=<id>][&limit=20]                          → 최신 글부터. before가 있으면 그 글보다 오래된 글
//   POST {action:"add", name, message, password, website}  → 글 추가. website는 봇을 거르는 숨은 칸(사람은 비워 둠)
//   POST {action:"delete", id, password}                    → 글쓴이가 정한 비밀번호로 그 글 삭제
//
// 응답은 늘 JSON이고 { ok: true, ... } 또는 { ok: false, error: "사용자에게 보일 문장" } 형식이다.
// DB 접속 정보는 cafe24가 넣어 주는 환경 변수(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD)만 쓴다.
// 테이블이 없으면 처음 요청 때 만든다.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const NAME_MAX = 12;
const MESSAGE_MAX = 200;
const PASSWORD_MIN = 4;
const PASSWORD_MAX = 20;
const POST_GAP_SECONDS = 30;  // 같은 사용자(IP)는 30초에 한 번만 쓸 수 있음
const LIMIT_DEFAULT = 20;
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
        fail(503, '방명록 저장소가 아직 준비되지 않았습니다.');
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

// 테이블 정의. 비밀번호는 password_hash 결과만, IP는 HMAC 값만 저장한다(원래 값은 저장하지 않음).
function ensureSchema(PDO $pdo): void
{
    if ($pdo->query("SELECT to_regclass('public.guestbook')")->fetchColumn() !== null) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS guestbook (
          id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
          name          text        NOT NULL CHECK (char_length(name) BETWEEN 1 AND 12),
          message       text        NOT NULL CHECK (char_length(message) BETWEEN 1 AND 200),
          password_hash text        NOT NULL,
          ip_hash       text        NOT NULL,
          created_at    timestamptz NOT NULL DEFAULT now()
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS guestbook_ip_idx ON guestbook (ip_hash, created_at)');
}

function intIn(mixed $value, int $min, int $max): ?int
{
    if (is_int($value)) {
        $n = $value;
    } elseif (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
        $n = (int) $value;
    } else {
        return null;
    }
    return $n >= $min && $n <= $max ? $n : null;
}

// 글자 수 (코드 포인트 기준, PostgreSQL char_length와 같음). 올바른 UTF-8이 아니면 -1
function charCount(string $text): int
{
    $n = preg_match_all('/./us', $text);
    return $n === false ? -1 : $n;
}

// 이름: 제어 문자를 빼고 공백을 하나로 줄인 뒤 1~12자
function cleanName(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value);
    if ($name === null) {
        return null;
    }
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    $length = charCount($name);
    return $length >= 1 && $length <= NAME_MAX ? $name : null;
}

// 내용: 줄바꿈은 남기고 나머지 제어 문자는 뺌. 빈 줄은 한 줄까지만, 1~200자
function cleanMessage(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $text = str_replace(["\r\n", "\r"], "\n", $value);
    $text = preg_replace('/(?!\n)[\p{Cc}\p{Cf}]/u', '', $text);
    if ($text === null) {
        return null;
    }
    $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);
    $text = (string) preg_replace('/ *\n */u', "\n", $text);
    $text = trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    $length = charCount($text);
    return $length >= 1 && $length <= MESSAGE_MAX ? $text : null;
}

// 같은 사람인지 가리기 위한 값. IP를 그대로 저장하지 않고 서버 비밀값으로 HMAC한 값만 저장
function clientKey(): string
{
    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $ip = $forwarded !== '' ? trim(explode(',', $forwarded)[0]) : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $secret = env('DB_PASSWORD') !== '' ? env('DB_PASSWORD') : 'guestbookV1';
    return hash_hmac('sha256', $ip, $secret);
}

function entryOut(array $row): array
{
    return ['id' => (int) $row['id'], 'name' => $row['name'], 'message' => $row['message'], 'at' => $row['at']];
}

const SELECT_COLUMNS = "id, name, message, to_char(created_at AT TIME ZONE 'Asia/Seoul', 'YYYY-MM-DD HH24:MI') AS at";

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $limit = intIn($_GET['limit'] ?? LIMIT_DEFAULT, 1, LIMIT_MAX) ?? LIMIT_DEFAULT;
        $before = isset($_GET['before']) ? intIn($_GET['before'], 1, PHP_INT_MAX) : null;
        $pdo = connect();
        // 한 개 더 읽어서 더 오래된 글이 남았는지(more) 알아냄
        if ($before === null) {
            $stmt = $pdo->prepare('SELECT ' . SELECT_COLUMNS . ' FROM guestbook ORDER BY id DESC LIMIT :limit');
        } else {
            $stmt = $pdo->prepare('SELECT ' . SELECT_COLUMNS . ' FROM guestbook WHERE id < :before ORDER BY id DESC LIMIT :limit');
            $stmt->bindValue(':before', $before, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $more = count($rows) > $limit;
        reply(200, ['ok' => true, 'more' => $more, 'entries' => array_map('entryOut', array_slice($rows, 0, $limit))]);
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 8192);
        $input = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($input)) {
            fail(400, '요청 형식이 올바르지 않습니다.');
        }
        $action = $input['action'] ?? '';

        if ($action === 'add') {
            if (($input['website'] ?? '') !== '') {
                fail(400, '등록하지 못했습니다.');
            }
            $name = cleanName($input['name'] ?? null);
            if ($name === null) {
                fail(400, '이름은 1~' . NAME_MAX . '자로 입력해 주세요.');
            }
            $message = cleanMessage($input['message'] ?? null);
            if ($message === null) {
                fail(400, '내용은 1~' . MESSAGE_MAX . '자로 입력해 주세요.');
            }
            $password = $input['password'] ?? null;
            $passwordLength = is_string($password) ? charCount($password) : -1;
            if ($passwordLength < PASSWORD_MIN || $passwordLength > PASSWORD_MAX) {
                fail(400, '비밀번호는 ' . PASSWORD_MIN . '~' . PASSWORD_MAX . '자로 입력해 주세요.');
            }

            $pdo = connect();
            $key = clientKey();
            $recent = $pdo->prepare("SELECT 1 FROM guestbook WHERE ip_hash = :key AND created_at > now() - interval '" . POST_GAP_SECONDS . " seconds' LIMIT 1");
            $recent->execute([':key' => $key]);
            if ($recent->fetchColumn() !== false) {
                fail(429, '방명록은 ' . POST_GAP_SECONDS . '초에 한 번씩 쓸 수 있습니다. 잠시 뒤 다시 시도해 주세요.');
            }

            $insert = $pdo->prepare('INSERT INTO guestbook (name, message, password_hash, ip_hash) VALUES (:name, :message, :hash, :key) RETURNING ' . SELECT_COLUMNS);
            $insert->execute([':name' => $name, ':message' => $message, ':hash' => password_hash($password, PASSWORD_DEFAULT), ':key' => $key]);
            reply(201, ['ok' => true, 'entry' => entryOut($insert->fetch())]);
        }

        if ($action === 'delete') {
            $id = intIn($input['id'] ?? null, 1, PHP_INT_MAX);
            $password = $input['password'] ?? null;
            if ($id === null || !is_string($password) || $password === '' || charCount($password) > PASSWORD_MAX) {
                fail(400, '비밀번호를 입력해 주세요.');
            }
            $pdo = connect();
            $find = $pdo->prepare('SELECT password_hash FROM guestbook WHERE id = :id');
            $find->bindValue(':id', $id, PDO::PARAM_INT);
            $find->execute();
            $hash = $find->fetchColumn();
            if ($hash === false) {
                fail(404, '이미 지워졌거나 없는 글입니다.');
            }
            if (!password_verify($password, (string) $hash)) {
                usleep(400000); // 비밀번호를 빠르게 여러 번 맞혀 보는 것을 늦춤
                fail(403, '비밀번호가 맞지 않습니다.');
            }
            $delete = $pdo->prepare('DELETE FROM guestbook WHERE id = :id');
            $delete->bindValue(':id', $id, PDO::PARAM_INT);
            $delete->execute();
            reply(200, ['ok' => true, 'id' => $id]);
        }

        fail(400, '지원하지 않는 요청입니다.');
    }

    header('Allow: GET, POST');
    fail(405, '지원하지 않는 요청입니다.');
} catch (Throwable $e) {
    error_log('guestbookV1: ' . $e->getMessage());
    fail(500, '방명록 저장소에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.');
}
