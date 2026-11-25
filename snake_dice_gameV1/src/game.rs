//! 게임 진행: 플레이어 차례, 말 위치, 승자를 관리합니다. 화면 입출력은 하지 않습니다.

use std::fmt;

use crate::board::{Board, GOAL, MoveOutcome};

/// 최소 플레이어 수입니다.
pub const MIN_PLAYERS: usize = 2;
/// 최대 플레이어 수입니다.
pub const MAX_PLAYERS: usize = 4;

/// 게임판에 표시할 플레이어 기호입니다.
const SYMBOLS: [char; MAX_PLAYERS] = ['A', 'B', 'C', 'D'];

/// 사람이 직접 하는지, 컴퓨터가 자동으로 하는지 나타냅니다.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PlayerKind {
    Human,
    Computer,
}

/// 플레이어 한 명입니다.
#[derive(Debug)]
pub struct Player {
    pub name: String,
    pub symbol: char,
    pub kind: PlayerKind,
    /// 현재 칸 (0은 출발 전)
    pub position: u8,
}

/// 한 차례를 진행한 결과입니다.
#[derive(Debug)]
pub struct TurnReport {
    /// 이번 차례 플레이어의 번호 (0부터)
    pub player: usize,
    pub roll: u8,
    pub outcome: MoveOutcome,
    pub won: bool,
}

/// 게임을 진행할 수 없는 이유입니다.
#[derive(Debug, PartialEq, Eq)]
pub enum GameError {
    /// 플레이어 수가 2~4명이 아닙니다.
    PlayerCount(usize),
    /// 주사위 수가 1~6이 아닙니다.
    InvalidRoll(u8),
    /// 차례인 플레이어를 찾을 수 없습니다.
    MissingPlayer(usize),
    /// 이미 승자가 나와 게임이 끝났습니다.
    AlreadyFinished,
}

impl fmt::Display for GameError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::PlayerCount(count) => write!(
                f,
                "플레이어는 {MIN_PLAYERS}~{MAX_PLAYERS}명이어야 합니다 (현재 {count}명)"
            ),
            Self::InvalidRoll(roll) => {
                write!(f, "주사위 수는 1~6이어야 합니다 (받은 값: {roll})")
            }
            Self::MissingPlayer(index) => {
                write!(f, "{index}번 플레이어를 찾을 수 없습니다")
            }
            Self::AlreadyFinished => write!(f, "이미 승자가 나와 게임이 끝났습니다"),
        }
    }
}

impl std::error::Error for GameError {}

/// 게임 한 판의 상태입니다.
#[derive(Debug)]
pub struct Game {
    board: Board,
    players: Vec<Player>,
    current: usize,
    winner: Option<usize>,
}

impl Game {
    /// 사람 `humans`명, 컴퓨터 `computers`명으로 새 게임을 만듭니다.
    pub fn new(board: Board, humans: usize, computers: usize) -> Result<Self, GameError> {
        let total = humans.saturating_add(computers);
        if !(MIN_PLAYERS..=MAX_PLAYERS).contains(&total) {
            return Err(GameError::PlayerCount(total));
        }
        let players = SYMBOLS
            .iter()
            .take(total)
            .enumerate()
            .map(|(index, &symbol)| {
                let (kind, name) = if index < humans {
                    (PlayerKind::Human, format!("플레이어{}", index + 1))
                } else {
                    (PlayerKind::Computer, format!("컴퓨터{}", index - humans + 1))
                };
                Player {
                    name,
                    symbol,
                    kind,
                    position: 0,
                }
            })
            .collect();
        Ok(Self {
            board,
            players,
            current: 0,
            winner: None,
        })
    }

    pub fn board(&self) -> &Board {
        &self.board
    }

    pub fn players(&self) -> &[Player] {
        &self.players
    }

    /// 지금 차례인 플레이어입니다.
    pub fn current_player(&self) -> Option<&Player> {
        self.players.get(self.current)
    }

    /// 승자가 나왔으면 승자를 돌려줍니다.
    pub fn winner(&self) -> Option<&Player> {
        self.winner.and_then(|index| self.players.get(index))
    }

    pub fn is_finished(&self) -> bool {
        self.winner.is_some()
    }

    /// 지금 차례인 플레이어가 주사위 `roll`만큼 움직이고, 다음 사람에게 차례를 넘깁니다.
    pub fn play_turn(&mut self, roll: u8) -> Result<TurnReport, GameError> {
        if self.is_finished() {
            return Err(GameError::AlreadyFinished);
        }
        if !(1..=6).contains(&roll) {
            return Err(GameError::InvalidRoll(roll));
        }
        let index = self.current;
        let player_count = self.players.len();
        let player = self
            .players
            .get_mut(index)
            .ok_or(GameError::MissingPlayer(index))?;
        let outcome = self.board.advance(player.position, roll);
        player.position = outcome.to;
        let won = outcome.to == GOAL;
        if won {
            self.winner = Some(index);
        } else {
            // get_mut이 성공했으므로 player_count는 1 이상입니다.
            self.current = (index + 1) % player_count;
        }
        Ok(TurnReport {
            player: index,
            roll,
            outcome,
            won,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::dice::Dice;

    type TestResult = Result<(), Box<dyn std::error::Error>>;

    #[test]
    fn rejects_wrong_player_count() -> TestResult {
        assert_eq!(
            Game::new(Board::standard()?, 1, 0).err(),
            Some(GameError::PlayerCount(1))
        );
        assert_eq!(
            Game::new(Board::standard()?, 3, 2).err(),
            Some(GameError::PlayerCount(5))
        );
        Ok(())
    }

    #[test]
    fn assigns_names_symbols_and_kinds() -> TestResult {
        let game = Game::new(Board::standard()?, 2, 1)?;
        let summary: Vec<(char, &str, PlayerKind)> = game
            .players()
            .iter()
            .map(|p| (p.symbol, p.name.as_str(), p.kind))
            .collect();
        assert_eq!(
            summary,
            vec![
                ('A', "플레이어1", PlayerKind::Human),
                ('B', "플레이어2", PlayerKind::Human),
                ('C', "컴퓨터1", PlayerKind::Computer),
            ]
        );
        assert!(game.players().iter().all(|p| p.position == 0));
        Ok(())
    }

    #[test]
    fn turns_rotate_between_players() -> TestResult {
        let mut game = Game::new(Board::standard()?, 2, 1)?;
        let report = game.play_turn(2)?;
        assert_eq!(report.player, 0);
        assert_eq!(report.outcome.to, 2);
        assert_eq!(game.current_player().map(|p| p.symbol), Some('B'));
        game.play_turn(1)?;
        game.play_turn(1)?;
        assert_eq!(game.current_player().map(|p| p.symbol), Some('A'));
        Ok(())
    }

    #[test]
    fn invalid_roll_changes_nothing() -> TestResult {
        let mut game = Game::new(Board::standard()?, 2, 0)?;
        assert_eq!(game.play_turn(0).err(), Some(GameError::InvalidRoll(0)));
        assert_eq!(game.play_turn(7).err(), Some(GameError::InvalidRoll(7)));
        assert_eq!(game.current_player().map(|p| p.position), Some(0));
        assert_eq!(game.current_player().map(|p| p.symbol), Some('A'));
        Ok(())
    }

    #[test]
    fn reaching_goal_wins_and_ends_game() -> TestResult {
        let mut game = Game::new(Board::standard()?, 2, 0)?;
        let first = game.players.get_mut(0).ok_or("플레이어가 없습니다")?;
        first.position = 97;
        let report = game.play_turn(3)?;
        assert!(report.won);
        assert_eq!(game.winner().map(|p| p.symbol), Some('A'));
        assert_eq!(game.play_turn(1).err(), Some(GameError::AlreadyFinished));
        Ok(())
    }

    #[test]
    fn computer_only_game_finishes() -> TestResult {
        let mut game = Game::new(Board::standard()?, 0, 4)?;
        let mut dice = Dice::new(42);
        for _ in 0..100_000 {
            if game.is_finished() {
                break;
            }
            game.play_turn(dice.roll())?;
        }
        let winner = game.winner().ok_or("10만 차례 안에 끝나지 않았습니다")?;
        assert_eq!(winner.position, GOAL);
        Ok(())
    }
}
