<?php
// 관리자 API (cafe24 PHP 8.2 + PostgreSQL). 순위 기록(scores)과 방명록(guestbook)을 보고 지운다.
//
// 모든 요청은 POST JSON이고 관리자 비밀번호를 key에 담는다(주소창·로그에 남지 않도록 GET을 쓰지 않음).
//   {key, action:"summary"}                                     → 표별 개수
//   {key, action:"scores", board:"all"|"2d"|"3d", offset, limit} → 순위 기록, 최근에 올린 순
//   {key, action:"guestbook", offset, limit}                    → 방명록 글, 최근 글 순
//   {key, action:"delete_score", id} / {key, action:"delete_guestbook", id}
//   DB 보기(dbV1.html):
//   {key, action:"db_info"}                                     → DB 이름·버전·크기, public 스키마의 표 목록(행 수, 크기)
//   {key, action:"db_table", table, offset, limit}              → 열, 제약 조건, 인덱스, 행(최근 순)
//   {key, action:"db_query", sql}                               → 읽기 전용 SQL 한 문장 실행(결과 최대 500행)
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
        PDO::ATTR_EMULATE_PREPARES => false,  // 서버 쪽 prepare: SQL 실행 칸에서 한 번에 한 문장만 실행되게 함
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
const QUERY_ROWS_MAX = 500;
const CELL_MAX = 300;  // DB 보기에서 한 칸에 보여 줄 최대 글자 수

function quoteIdent(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

// public 스키마의 표 이름 목록. db_table은 이 목록에 있는 이름만 받는다(SQL에 이름을 넣기 전 확인)
function publicTables(PDO $pdo): array
{
    return $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
}

// 결과 한 줄을 화면용 문자열 배열로 (NULL은 null 그대로, 긴 값은 잘라서)
function cellsOut(array $row): array
{
    return array_map(static function (mixed $v): ?string {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_resource($v)) {
            return '(바이너리)';
        }
        return cut((string) $v, CELL_MAX);
    }, $row);
}

// 앞에서 $max글자까지만 (mbstring 없이 동작. 올바른 UTF-8이 아니면 바이트로 자름)
function cut(string $s, int $max): string
{
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m) !== 1) {
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }
    return strlen($m[0]) < strlen($s) ? $m[0] . '…' : $s;
}

function columnNames(PDOStatement $stmt): array
{
    $names = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
        $meta = $stmt->getColumnMeta($i);
        $names[] = $meta === false ? 'column' . ($i + 1) : (string) $meta['name'];
    }
    return $names;
}

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
        // ai_reply 열은 guestbookV1.php가 처음 요청 때 더하므로, 아직 없을 수도 있음
        $hasAi = countRows($pdo, "SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'guestbook' AND column_name = 'ai_reply'") === 1;
        $aiCols = $hasAi ? 'ai_reply, ai_model' : 'NULL AS ai_reply, NULL AS ai_model';
        $stmt = $pdo->prepare("SELECT id, name, message, {$aiCols}, " . KST . ' FROM guestbook ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'message' => $r['message'],
            'ai_reply' => $r['ai_reply'], 'ai_model' => $r['ai_model'], 'at' => $r['at'],
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

    if ($action === 'db_info') {
        $db = $pdo->query("SELECT current_database() AS name, current_setting('server_version') AS version, pg_size_pretty(pg_database_size(current_database())) AS size")->fetch();
        $tables = [];
        foreach (publicTables($pdo) as $name) {
            $size = $pdo->prepare('SELECT pg_size_pretty(pg_total_relation_size(to_regclass(:name)))');
            $size->execute([':name' => 'public.' . quoteIdent($name)]);
            $tables[] = ['name' => $name, 'rows' => countRows($pdo, 'SELECT count(*) FROM public.' . quoteIdent($name)), 'size' => $size->fetchColumn()];
        }
        reply(200, ['ok' => true, 'db' => $db, 'tables' => $tables]);
    }

    if ($action === 'db_table') {
        $table = $input['table'] ?? '';
        if (!is_string($table) || !in_array($table, publicTables($pdo), true)) {
            fail(404, '없는 표입니다.');
        }
        $qualified = 'public.' . quoteIdent($table);
        $cols = $pdo->prepare("SELECT column_name AS name, data_type AS type, is_nullable = 'YES' AS nullable, column_default AS default_value
                               FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t ORDER BY ordinal_position");
        $cols->execute([':t' => $table]);
        $columns = $cols->fetchAll();
        $cons = $pdo->prepare('SELECT conname AS name, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = to_regclass(:q) ORDER BY conname');
        $cons->execute([':q' => $qualified]);
        $idx = $pdo->prepare("SELECT indexname AS name, indexdef AS def FROM pg_indexes WHERE schemaname = 'public' AND tablename = :t ORDER BY indexname");
        $idx->execute([':t' => $table]);

        $hasId = in_array('id', array_column($columns, 'name'), true);
        $rows = $pdo->prepare("SELECT * FROM {$qualified}" . ($hasId ? ' ORDER BY id DESC' : '') . ' LIMIT :limit OFFSET :offset');
        $rows->bindValue(':limit', $limit, PDO::PARAM_INT);
        $rows->bindValue(':offset', $offset, PDO::PARAM_INT);
        $rows->execute();
        reply(200, [
            'ok' => true,
            'table' => $table,
            'columns' => array_map(static fn (array $c): array => [
                'name' => $c['name'], 'type' => $c['type'], 'nullable' => (bool) $c['nullable'], 'default' => $c['default_value'],
            ], $columns),
            'constraints' => $cons->fetchAll(),
            'indexes' => $idx->fetchAll(),
            'total' => countRows($pdo, "SELECT count(*) FROM {$qualified}"),
            'fields' => columnNames($rows),
            'rows' => array_map('cellsOut', $rows->fetchAll(PDO::FETCH_NUM)),
        ]);
    }

    // 읽기 전용 SQL. 세 겹으로 막는다:
    //   1) 첫 낱말이 SELECT/WITH/EXPLAIN/SHOW/TABLE/VALUES가 아니면 거절
    //   2) prepare(서버 쪽 준비)라서 한 번에 한 문장만 실행됨 (「SELECT 1; DELETE …」는 오류)
    //   3) BEGIN READ ONLY 안에서 실행하고 끝나면 늘 ROLLBACK (쓰기 문장은 PostgreSQL이 거절, 되더라도 되돌림)
    if ($action === 'db_query') {
        $sql = $input['sql'] ?? '';
        if (!is_string($sql)) {
            fail(400, 'SQL을 입력해 주세요.');
        }
        $sql = rtrim(trim($sql), "; \n\r\t");
        if ($sql === '' || strlen($sql) > 4000) {
            fail(400, 'SQL은 1~4000자로 입력해 주세요.');
        }
        if (preg_match('/^\s*(select|with|explain|show|table|values)\b/i', $sql) !== 1) {
            fail(400, '읽기만 하는 SQL(SELECT, WITH, EXPLAIN, SHOW, TABLE, VALUES)만 실행할 수 있습니다.');
        }
        $pdo->exec('BEGIN READ ONLY');
        $pdo->exec("SET LOCAL statement_timeout = '5s'");
        $started = microtime(true);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $fields = columnNames($stmt);
            $rows = [];
            $more = false;
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                if (count($rows) >= QUERY_ROWS_MAX) {
                    $more = true;
                    break;
                }
                $rows[] = cellsOut($row);
            }
        } catch (PDOException $e) {
            $pdo->exec('ROLLBACK');
            $message = preg_replace('/^SQLSTATE\[\w+\]:\s*/', '', $e->getMessage());
            fail(400, 'SQL 오류: ' . cut((string) $message, 400));
        }
        $pdo->exec('ROLLBACK');
        reply(200, ['ok' => true, 'fields' => $fields, 'rows' => $rows, 'more' => $more, 'ms' => (int) round((microtime(true) - $started) * 1000)]);
    }

    fail(400, '지원하지 않는 요청입니다.');
} catch (Throwable $e) {
    error_log('adminV1: ' . $e->getMessage());
    fail(500, 'DB에 연결하지 못했습니다. 잠시 뒤 다시 시도해 주세요.');
}
