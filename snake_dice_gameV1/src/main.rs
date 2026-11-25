//! 뱀주사위놀이 실행 파일: 입력을 받고 결과를 화면에 출력합니다.
#![forbid(unsafe_code)]

use std::io::{self, BufRead, Write};
use std::process::ExitCode;
use std::thread;
use std::time::Duration;

use snake_dice_game_v1::board::{Board, GOAL, JumpKind};
use snake_dice_game_v1::dice::{self, Dice};
use snake_dice_game_v1::game::{Game, MAX_PLAYERS, MIN_PLAYERS, PlayerKind, TurnReport};
use snake_dice_game_v1::render;

/// 컴퓨터가 주사위를 굴릴 때 잠깐 기다리는 시간입니다.
const COMPUTER_DELAY: Duration = Duration::from_millis(700);

fn main() -> ExitCode {
    let stdin = io::stdin();
    let mut input = stdin.lock();
    let code = run(&mut input);
    wait_before_close(&mut input);
    code
}

/// exe를 더블클릭해 실행하면 끝나자마자 창이 닫히므로, Enter를 누를 때까지 기다립니다.
fn wait_before_close(input: &mut impl BufRead) {
    println!();
    print!("Enter를 누르면 창이 닫힙니다...");
    if let Err(e) = io::stdout().flush() {
        eprintln!("화면에 안내 문구를 내보내지 못했습니다: {e}");
    }
    let mut line = String::new();
    if let Err(e) = input.read_line(&mut line) {
        eprintln!("입력을 읽지 못해 바로 닫습니다: {e}");
    }
}

/// 게임을 준비하고 끝날 때까지 진행합니다.
fn run(input: &mut impl BufRead) -> ExitCode {
    print_intro();
    let Some((humans, computers)) = ask_player_counts(input) else {
        return ExitCode::SUCCESS;
    };
    let board = match Board::standard() {
        Ok(board) => board,
        Err(e) => {
            eprintln!("게임판을 만들지 못했습니다: {e}");
            return ExitCode::FAILURE;
        }
    };
    let mut game = match Game::new(board, humans, computers) {
        Ok(game) => game,
        Err(e) => {
            eprintln!("게임을 시작하지 못했습니다: {e}");
            return ExitCode::FAILURE;
        }
    };
    let seed = match dice::seed_from_time() {
        Ok(seed) => seed,
        Err(e) => {
            eprintln!("현재 시각을 읽지 못해 정해진 값으로 주사위를 섞습니다: {e}");
            dice::FALLBACK_SEED
        }
    };
    let mut dice = Dice::new(seed);
    play(&mut game, &mut dice, input)
}

fn print_intro() {
    println!("==============================");
    println!("        뱀 주 사 위 놀 이");
    println!("==============================");
    println!("놀이법");
    println!(" - 차례대로 주사위를 굴려 나온 수만큼 앞으로 갑니다.");
    println!(" - 사다리(^) 칸에 멈추면 사다리를 타고 올라갑니다.");
    println!(" - 뱀(~) 칸에 멈추면 뱀을 타고 내려갑니다.");
    println!(" - {GOAL}칸에 정확히 도착하면 이깁니다. 넘치면 넘친 만큼 되돌아옵니다.");
    println!();
}

/// 사람과 컴퓨터 플레이어 수를 묻습니다. 입력이 끝나면 None을 돌려줍니다.
fn ask_player_counts(input: &mut impl BufRead) -> Option<(usize, usize)> {
    let humans = ask_number(
        input,
        &format!("사람 플레이어 수를 입력하세요 (1~{MAX_PLAYERS}): "),
        1,
        MAX_PLAYERS,
    )?;
    let max_computers = MAX_PLAYERS.saturating_sub(humans);
    let min_computers = MIN_PLAYERS.saturating_sub(humans);
    let computers = if max_computers == 0 {
        0
    } else {
        ask_number(
            input,
            &format!("컴퓨터 플레이어 수를 입력하세요 ({min_computers}~{max_computers}): "),
            min_computers,
            max_computers,
        )?
    };
    Some((humans, computers))
}

/// `min`~`max` 사이의 숫자를 받을 때까지 다시 묻습니다.
fn ask_number(input: &mut impl BufRead, prompt: &str, min: usize, max: usize) -> Option<usize> {
    loop {
        let line = read_line(input, prompt)?;
        let text = line.trim();
        if text.is_empty() {
            println!("아무것도 입력하지 않았습니다. {min}~{max} 사이의 숫자를 입력해 주세요.");
            continue;
        }
        match text.parse::<usize>() {
            Ok(number) if (min..=max).contains(&number) => return Some(number),
            Ok(number) => {
                println!("{number}은(는) 범위를 벗어났습니다. {min}~{max} 사이로 입력해 주세요.");
            }
            Err(_) => {
                println!("'{text}'은(는) 숫자가 아닙니다. {min}~{max} 사이의 숫자를 입력해 주세요.");
            }
        }
    }
}

/// 안내 문구를 보여 주고 한 줄을 읽습니다.
/// 입력이 끝났거나 읽지 못하면 이유를 알리고 None을 돌려줍니다.
fn read_line(input: &mut impl BufRead, prompt: &str) -> Option<String> {
    print!("{prompt}");
    if let Err(e) = io::stdout().flush() {
        eprintln!("화면에 안내 문구를 내보내지 못했습니다(계속 진행합니다): {e}");
    }
    let mut line = String::new();
    match input.read_line(&mut line) {
        Ok(0) => {
            println!();
            println!("입력이 끝나서 게임을 종료합니다.");
            None
        }
        Ok(_) => Some(line),
        Err(e) => {
            eprintln!("입력을 읽지 못해 게임을 종료합니다: {e}");
            None
        }
    }
}

/// 승자가 나오거나 사람이 그만둘 때까지 차례를 진행합니다.
fn play(game: &mut Game, dice: &mut Dice, input: &mut impl BufRead) -> ExitCode {
    loop {
        let Some(player) = game.current_player() else {
            eprintln!("지금 차례인 플레이어를 찾지 못했습니다.");
            return ExitCode::FAILURE;
        };
        let label = format!("[{} {}]", player.symbol, player.name);
        match player.kind {
            PlayerKind::Human => {
                println!();
                print!("{}", render::render_board(game));
                print!("{}", render::render_status(game));
                let prompt = format!("{label} Enter: 주사위 굴리기 / q: 그만하기 > ");
                let Some(line) = read_line(input, &prompt) else {
                    return ExitCode::SUCCESS;
                };
                if line.trim().eq_ignore_ascii_case("q") {
                    println!("게임을 그만둡니다.");
                    return ExitCode::SUCCESS;
                }
            }
            PlayerKind::Computer => {
                println!("{label} 주사위를 굴리는 중...");
                thread::sleep(COMPUTER_DELAY);
            }
        }

        let roll = dice.roll();
        let report = match game.play_turn(roll) {
            Ok(report) => report,
            Err(e) => {
                eprintln!("차례를 진행하지 못했습니다: {e}");
                return ExitCode::FAILURE;
            }
        };
        print_report(&label, &report);

        if let Some(winner) = game.winner() {
            println!();
            print!("{}", render::render_board(game));
            print!("{}", render::render_status(game));
            println!(
                "*** {} {}님이 {GOAL}칸에 도착해 이겼습니다! ***",
                winner.symbol, winner.name
            );
            return ExitCode::SUCCESS;
        }
    }
}

fn print_report(label: &str, report: &TurnReport) {
    let outcome = &report.outcome;
    let start = if outcome.from == 0 {
        String::from("출발점")
    } else {
        format!("{}칸", outcome.from)
    };
    println!(
        "{label} 주사위 {} → {start}에서 {}칸으로 갔습니다.",
        report.roll, outcome.landed
    );
    if outcome.bounced {
        println!(
            "    {GOAL}칸을 넘어서 {}칸만큼 되돌아왔습니다.",
            GOAL.saturating_sub(outcome.landed)
        );
    }
    match outcome.jump {
        Some(JumpKind::Ladder) => println!("    사다리를 타고 {}칸으로 올라갔습니다!", outcome.to),
        Some(JumpKind::Snake) => println!("    뱀을 만나 {}칸으로 미끄러졌습니다!", outcome.to),
        None => {}
    }
}
