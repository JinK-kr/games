# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 저장소 개요

같은 게임(뱀주사위놀이, 뱀과 사다리 방식)을 두 가지로 구현한 저장소입니다. 두 버전은 코드를 공유하지 않습니다.

| 폴더 | 내용 |
|---|---|
| `snake_dice_gameV1/` | Rust 터미널 버전. 외부 크레이트 없음. `download/snake_dice_gameV1.exe`는 커밋된 Windows 빌드 결과물 |
| `snake_dice_webV1/snake_dice_webV1.html` | 웹 그래픽 버전(2D 캔버스). HTML 파일 하나에 CSS·JS가 모두 들어 있음. 빌드 과정 없음 |
| `snake_dice_3dV1/snake_dice_3dV1.html` | 3D 시제품(three.js + cannon-es 물리). 3D 게임판, 체스 폰 말, 물리 주사위, 사다리·뱀만 있음. 이솝 우화, 함정 칸, 칸 그림은 아직 2D 버전에만 있음 |

**웹 버전이 주력이고, Rust 버전은 더 이상 관리하지 않습니다 (사용자 지시).** 기능과 규칙은 웹 버전에서만 바꾸고, Rust 버전과 맞추지 않습니다. 사용자가 따로 요청할 때만 Rust 버전을 건드립니다. 두 버전은 사다리·뱀 위치표(`JUMPS`)만 같고 나머지 규칙은 이미 다릅니다.

| 규칙 | Rust 버전 | 웹 버전 |
|---|---|---|
| 100칸을 넘는 눈 | 넘친 만큼 되돌아옴 (`Board::advance`) | 100칸에 닿으면 멈추고 승리 |
| 함정 칸 (+2/−3), 연쇄 이동 | 없음 | 있음 (`TRAPS`) |
| 이야기 | 없음 | 이솝 우화 10편 (`FABLES` 등) |

뱀 데이터는 두 버전 모두 `{높은 칸: 낮은 칸}` 형식입니다. 웹 버전은 높은 칸에 꼬리, 낮은 칸에 머리를 그립니다("꼬리를 밟으면 머리로 내려감").

## 이름 규칙

- 새 파일과 폴더 이름은 `이름V숫자` 형식입니다(예: `snake_dice_webV1.html`).
- Cargo 패키지 이름은 snake_case로 씁니다(`snake_dice_game_v1`). 대문자를 넣으면 `cargo new`가 경고를 냅니다.
- Rust 모듈 파일(`board.rs` 등)과 `main.rs`, `lib.rs`, `CLAUDE.md`는 도구가 정한 이름이라 이 규칙의 예외입니다.

## Rust 버전 (`snake_dice_gameV1/`)

명령은 `snake_dice_gameV1/` 안에서 실행합니다.

```bash
cargo check --all-targets
```

```bash
cargo clippy --all-targets
```

```bash
cargo test
```

```bash
cargo test board::tests::bounce_can_land_on_snake
```

```bash
cargo run
```

### 지켜야 할 기준 (사용자 요구)

- **경고:** `cargo check`와 `cargo clippy` 모두 경고 0개.
- **unwrap 금지:** 테스트에서도 `unwrap`과 `expect`를 쓰지 않습니다. 테스트 함수는 `Result<(), Box<dyn Error>>`를 돌려주고 `?`를 씁니다. 여러 곳에 `type TestResult` 별칭이 있습니다.
- **unsafe 금지:** `#![forbid(unsafe_code)]`가 `lib.rs`와 `main.rs`에 있습니다.
- **clone 금지:** 빌림 오류를 `clone`으로 피하지 않습니다.
- **마무리:** 작업이 끝나면 `cargo test` 결과를 보고합니다.

### 구조

- **lib와 bin 분리.** `lib.rs`가 모듈을 공개하고, `main.rs`는 입출력만 맡습니다. 게임 규칙은 lib 모듈에 있고 입출력을 하지 않습니다.
- **`board`:** `Board::new`가 사다리·뱀 목록을 검사합니다(범위, 중복, 연쇄 금지). `advance`가 이동 결과(`MoveOutcome`)를 계산합니다.
- **`game`:** 차례, 승자, 플레이어 수(2~4)를 관리합니다.
- **`dice`:** 외부 크레이트 없이 만든 xorshift64 난수입니다. 시드는 splitmix64로 섞습니다.
- **`render`:** 게임판을 `String`으로 만들기만 하고 출력하지 않습니다. 그래서 테스트할 수 있습니다.
- **`main.rs`:** 끝날 때 Enter를 기다립니다(`wait_before_close`). exe를 더블클릭해서 실행하면 창이 바로 닫히는 것을 막기 위해서입니다.

### Windows exe 다시 만들기

이 Linux 환경에서 교차 빌드합니다. 처음 한 번은 `rustup target add x86_64-pc-windows-gnu`와 `gcc-mingw-w64-x86-64` 설치가 필요합니다. 빌드한 뒤 결과물을 V 이름으로 바꿔 `download/`에 복사합니다.

```bash
cargo build --release --target x86_64-pc-windows-gnu
```

```bash
cp target/x86_64-pc-windows-gnu/release/snake_dice_game_v1.exe download/snake_dice_gameV1.exe
```

## 웹 버전 (`snake_dice_webV1/snake_dice_webV1.html`)

### 파일 하나를 두 곳에 씀

- 끝에 `<!--wrap-->`가 붙은 줄(doctype, html, head, body 태그)은 파일만 따로 열 때 필요한 껍데기입니다.
- claude.ai 페이지(https://claude.ai/artifact/4MvrXo1mdkMWnTUPZ9zUqr)로 올리는 판에서는 이 줄들을 빼야 합니다. 페이지 쪽이 껍데기를 따로 씌우기 때문입니다.
- 항상 저장소 파일을 고치고, 올리는 판은 아래 명령으로 다시 만들어서 같은 경로로 다시 올립니다. 그래야 같은 주소가 유지됩니다.

```bash
grep -v '<!--wrap-->' snake_dice_webV1/snake_dice_webV1.html > <scratchpad>/snake_dice_webV1.html
```

외부에서 불러오는 것은 Google Fonts뿐입니다(Black Han Sans, IBM Plex Sans KR, Noto Color Emoji). 그 밖의 외부 스크립트나 파일은 없습니다.

### 스크립트 구조 (`<script>` 안의 즉시 실행 함수 하나)

**1. 데이터 표**
- 기본 규칙: `JUMPS`(사다리·뱀), `TRAPS`(함정)
- 이야기: `FABLES`(우화와 장면 칸 번호), `LADDER_STORY`, `SNAKE_STORY`, `DEST_NOTES`
- 이 표들로 `SCENES`(장면 칸 찾기표)와 `PICTURES`(칸 그림 스티커)를 만듭니다.
- 장면, 함정, 사다리·뱀 끝 칸이 서로 겹치면 안 됩니다. 칸을 바꿀 때는 이 표들을 함께 확인합니다.

**2. 그리기 (2단계)**
- `buildBoard()`는 바뀌지 않는 게임판을 화면 밖 캔버스 `boardCache`에 한 번만 그립니다. 게임판 데이터나 글꼴이 바뀌면 다시 불러야 합니다.
- `draw()`는 그 위에 칸 효과(`effects`), 말, 색종이를 그립니다.
- 좌표는 논리 크기 600×660을 `SCALE = 2`로 그립니다. 칸 위치는 `center(n)`으로 구하며 줄마다 방향이 바뀝니다(지그재그). 말은 바닥 위치(`groundOf`) 기준으로 그립니다.

**3. 애니메이션 루프**
- `requestAnimationFrame` 루프는 움직이는 것이 있을 때만 돕니다.
- 움직이는 것은 `tween()` 진행 중, `effects`, `confetti` 세 가지입니다. 그릴 것을 추가했으면 `kick()`을 불러야 화면이 갱신됩니다.

**4. 한 차례 진행 (`takeTurn()`)**
- 순서: `rollDiceAnim()`(입체 주사위) → `walk()`(한 칸씩 이동) → 멈춘 칸 처리 반복문
- 멈춘 칸 처리 순서: 함정 → 사다리·뱀 → 이야기. 함정으로 옮겨 간 칸이 사다리나 뱀이면 연달아 처리합니다.
- 기다리는 곳(`await`)마다 `id !== gameId`를 확인합니다. 진행 중에 새 게임을 시작하면 그 차례를 그만두기 위해서입니다. 새 기다림을 추가할 때도 이 확인을 넣습니다.

**5. 화면 위에 겹치는 DOM 요소**
- `.board-wrap` 안에서 캔버스 위에 절대 위치로 겹칩니다.
- `#dice3d`(CSS 3D 주사위), `#burst`(가운데 메시지: `is-ladder`, `is-snake`, `is-trap`, `is-story`), `#winBox`(축하 창)
- 입체 주사위의 나온 면 각도는 `CUBE_BASE`, 눈 색은 CSS 변수 `--pip-1`~`--pip-6`입니다.

**6. 효과음**
- `Sound` 모듈이 WebAudio로 소리를 직접 만듭니다. 소리 파일은 없습니다.
- 브라우저 정책상 사용자가 클릭해야 소리가 나므로, 버튼 클릭에서 `Sound.unlock()`을 부릅니다.

**7. 새로 고침 뒤 상태 이어 가기**
- `start(data)`는 `window.claude.hot`(claude.ai 미리보기가 주는 기능)이 있으면 그 상태로 이어서 시작합니다.
- 저장할 상태는 `snapshot()`에서 정합니다. 새 상태 값을 추가하면 `snapshot()`과 `start()`에 모두 넣습니다.

### 테스트

자동 테스트 묶음은 없습니다. Playwright(설치되어 있음)로 헤드리스 크롬에서 직접 실행해 확인합니다. 원하는 장면을 바로 만드는 방법은 두 가지입니다.

- **말 위치 정하기:** `page.addInitScript`로 `window.claude = { hot: { data: { setup, players: [{ slot, kind, name, pos, rolls }], current, winner, logItems, readScenes } } }`를 넣으면 그 위치에서 시작합니다.
- **주사위 고정:** 같은 초기화 스크립트에서 `crypto.getRandomValues`를 `arr[0] = 원하는눈 - 1`로 바꾸면 주사위 눈이 고정됩니다.
- **기다리는 시간:** 주사위 연출에 약 1.9초, 한 칸 이동에 0.17초가 걸립니다. 메시지 카드는 `#burst`의 class와 글자로 확인합니다.

## 3D 시제품 (`snake_dice_3dV1/snake_dice_3dV1.html`)

claude.ai 페이지 주소는 https://claude.ai/artifact/ACpCMDdEyBg4n12fTLXcx2 입니다. 2D 버전과 같은 방식으로 `<!--wrap-->` 줄을 빼서 올립니다.

### 라이브러리

- import map으로 jsdelivr에서 불러옵니다: `three@0.160.0`(본체, `OrbitControls`, `RoundedBoxGeometry`), `cannon-es@0.20.0`
- 버전을 바꾸면 import map의 주소 세 줄을 함께 바꿉니다.
- 15초 안에 라이브러리를 못 받거나 WebGL을 쓸 수 없으면, 화면에 이유를 보여 줍니다.

### 구조

- **좌표:** 칸 한 변이 1이고 판 가운데가 원점입니다. `tileXZ(n)`은 1번 줄을 +z(카메라 쪽)에 둡니다. 출발 줄은 z = 5.75입니다.
- **게임판 그림:** 판 무늬와 칸 번호는 캔버스에 그린 뒤 평면에 붙입니다(`makeBoardTexture`). 텍스처의 위쪽 줄이 먼 쪽(−z), 즉 91~100칸입니다.
- **사다리:** 아치 모양 곡선(`QuadraticBezierCurve3`)으로 만듭니다. 말은 그 곡선을 따라 오릅니다.
- **뱀:** `TubeGeometry`를 고리 단위로 줄여 굵기를 바꿉니다(꼬리 → 머리로 굵어짐). 말은 꼬리에서 머리로 같은 곡선을 따라 미끄러집니다.
- **주사위:** 결과는 물리 엔진이 정합니다.
  - 멈추면 `readTop()`이 위를 향한 면을 읽습니다.
  - 모서리로 기대 서면 살짝 건드려 다시 눕힙니다.
  - 3.5초가 지나면 감쇠를 키워 빨리 멈추게 합니다.
  - 상자 면 순서(+x, −x, +y, −y, +z, −z)와 눈의 대응은 `FACE_VALUES`입니다.
- **말과 물리:** 말은 물리 세계에서 위치만 따라가는 상자(`KINEMATIC`)로 들어가 있습니다. 그래서 주사위가 말에 부딪힙니다.
- **말 자리 맞추기:** 이동 중인 말은 `moving`이 켜져 있어 자리 맞추기를 건너뜁니다. 차례가 끝날 때 `layoutPawns`로 풀어 줍니다.

### 시험 방법

이 클라우드 환경에서는 CDN 접속이 막혀 있고(403), npm 저장소는 열려 있습니다.

1. 같은 버전을 npm에서 받아 스크래치 폴더에 풉니다(`registry.npmjs.org/three/-/three-0.160.0.tgz` 등).
2. Playwright의 `page.route('https://cdn.jsdelivr.net/npm/**')`로 요청을 가로채 그 파일로 응답합니다.
3. WebGL은 `chromium.launch({ args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'] })`로 켭니다.
4. 준비 완료는 `window.__snake3dReady === true`로 기다립니다.

참고:
- 소프트웨어로 그리기 때문에 느립니다. 화면 캡처 중에도 시간이 흐르므로, 캡처는 정확한 순간을 담지 못할 수 있습니다.
- 주사위가 공정한지는 화면 없이 Node에서 cannon-es만 불러와 같은 설정으로 수백 번 던져 확인합니다.
