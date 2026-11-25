//! 화면 그리기: 게임판과 플레이어 상태를 글자로 만듭니다.
//! 실제 출력은 main.rs에서 하므로, 여기서는 문자열만 만들어 테스트하기 쉽게 했습니다.

use crate::board::{Board, GOAL, JumpKind, ROW_LEN};
use crate::game::{Game, Player, PlayerKind};

/// 칸 하나의 안쪽 너비(글자 수)입니다.
const CELL_WIDTH: usize = 5;

/// 게임판을 그립니다. 맨 아래 줄이 1~10칸이고, 줄마다 방향이 바뀝니다(지그재그).
pub fn render_board(game: &Game) -> String {
    let board = game.board();
    let players = game.players();
    let border = border_line();
    let mut out = String::new();
    out.push_str(&border);
    for row in (0..GOAL / ROW_LEN).rev() {
        let mut numbers = String::from("|");
        let mut tokens = String::from("|");
        for square in row_squares(row) {
            numbers.push_str(&format!("{square:>3}{} |", mark(board, square)));
            tokens.push_str(&format!(" {:<4}|", players_on(players, square)));
        }
        out.push_str(&numbers);
        out.push('\n');
        out.push_str(&tokens);
        out.push('\n');
        out.push_str(&border);
    }
    out
}

/// 사다리·뱀 목록과 플레이어별 위치를 보여 줍니다.
pub fn render_status(game: &Game) -> String {
    let board = game.board();
    let mut out = String::new();
    out.push_str(&format!("사다리(^): {}\n", join_pairs(board.ladders())));
    out.push_str(&format!("뱀(~):     {}\n", join_pairs(board.snakes())));
    for player in game.players() {
        let kind = match player.kind {
            PlayerKind::Human => "사람",
            PlayerKind::Computer => "컴퓨터",
        };
        let place = if player.position == 0 {
            String::from("출발 전")
        } else {
            format!("{}칸", player.position)
        };
        out.push_str(&format!(
            "{} {}({kind}): {place}\n",
            player.symbol, player.name
        ));
    }
    out
}

fn border_line() -> String {
    let mut line = String::from("+");
    for _ in 0..ROW_LEN {
        line.push_str(&"-".repeat(CELL_WIDTH));
        line.push('+');
    }
    line.push('\n');
    line
}

/// `row`번째 줄(0이 맨 아래)의 칸 번호를 왼쪽부터 순서대로 돌려줍니다.
fn row_squares(row: u8) -> Vec<u8> {
    let first = row * ROW_LEN + 1;
    let squares = first..first + ROW_LEN;
    if row.is_multiple_of(2) {
        squares.collect()
    } else {
        squares.rev().collect()
    }
}

fn mark(board: &Board, square: u8) -> char {
    match board.kind_at(square) {
        Some(JumpKind::Ladder) => '^',
        Some(JumpKind::Snake) => '~',
        None => ' ',
    }
}

fn players_on(players: &[Player], square: u8) -> String {
    players
        .iter()
        .filter(|p| p.position == square)
        .map(|p| p.symbol)
        .collect()
}

fn join_pairs(pairs: impl Iterator<Item = (u8, u8)>) -> String {
    pairs
        .map(|(from, to)| format!("{from}→{to}"))
        .collect::<Vec<_>>()
        .join(", ")
}

#[cfg(test)]
mod tests {
    use super::*;

    type TestResult = Result<(), Box<dyn std::error::Error>>;

    /// 위에서 `k`번째 줄(0부터)의 칸 번호 줄 위치입니다.
    fn number_line(k: usize) -> usize {
        1 + 3 * k
    }

    #[test]
    fn board_has_expected_shape() -> TestResult {
        let game = Game::new(Board::standard()?, 2, 0)?;
        let text = render_board(&game);
        let lines: Vec<&str> = text.lines().collect();
        assert_eq!(lines.len(), 31);
        assert!(lines.iter().all(|line| line.chars().count() == 61));
        Ok(())
    }

    #[test]
    fn rows_zigzag_like_a_real_board() -> TestResult {
        let game = Game::new(Board::standard()?, 2, 0)?;
        let text = render_board(&game);
        let lines: Vec<&str> = text.lines().collect();
        // 맨 위 줄은 100이 왼쪽, 아래에서 두 번째 줄은 20이 왼쪽, 맨 아래 줄은 1이 왼쪽입니다.
        assert!(lines.get(number_line(0)).is_some_and(|l| l.starts_with("|100")));
        assert!(lines.get(number_line(8)).is_some_and(|l| l.starts_with("| 20")));
        assert!(lines.get(number_line(9)).is_some_and(|l| l.starts_with("|  1")));
        Ok(())
    }

    #[test]
    fn marks_ladders_and_snakes() -> TestResult {
        let game = Game::new(Board::standard()?, 2, 0)?;
        let text = render_board(&game);
        assert!(text.contains("  4^"));
        assert!(text.contains(" 99~"));
        Ok(())
    }

    #[test]
    fn shows_players_on_their_squares() -> TestResult {
        let mut game = Game::new(Board::standard()?, 2, 0)?;
        game.play_turn(3)?;
        let text = render_board(&game);
        let lines: Vec<&str> = text.lines().collect();
        let bottom_tokens = lines.get(number_line(9) + 1).ok_or("줄이 없습니다")?;
        // 맨 아래 줄 세 번째 칸(3칸)에 A가 있어야 합니다.
        assert!(bottom_tokens.starts_with("|     |     | A   |"));

        let status = render_status(&game);
        assert!(status.contains("A 플레이어1(사람): 3칸"));
        assert!(status.contains("B 플레이어2(사람): 출발 전"));
        Ok(())
    }
}
