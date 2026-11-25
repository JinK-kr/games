//! 게임판: 1~100칸과 사다리·뱀의 위치를 관리하고, 말이 어디로 가는지 계산합니다.

use std::collections::BTreeMap;
use std::fmt;

/// 도착 칸(마지막 칸) 번호입니다.
pub const GOAL: u8 = 100;

/// 게임판 한 줄에 놓이는 칸 수입니다.
pub const ROW_LEN: u8 = 10;

/// 사다리 또는 뱀 하나입니다. `from` 칸에 멈추면 `to` 칸으로 옮겨 갑니다.
#[derive(Debug, PartialEq, Eq)]
pub struct Jump {
    pub from: u8,
    pub to: u8,
}

impl Jump {
    pub const fn new(from: u8, to: u8) -> Self {
        Self { from, to }
    }
}

/// 옮겨 가는 방향의 종류입니다.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum JumpKind {
    /// 위로 올라가는 사다리
    Ladder,
    /// 아래로 내려가는 뱀
    Snake,
}

impl JumpKind {
    fn between(from: u8, to: u8) -> Self {
        if to > from { Self::Ladder } else { Self::Snake }
    }
}

/// 기본 게임판의 사다리 7개와 뱀 8개입니다.
const STANDARD_JUMPS: [Jump; 15] = [
    // 사다리 (올라감)
    Jump::new(4, 25),
    Jump::new(13, 46),
    Jump::new(33, 49),
    Jump::new(42, 63),
    Jump::new(50, 69),
    Jump::new(62, 81),
    Jump::new(74, 92),
    // 뱀 (내려감)
    Jump::new(27, 5),
    Jump::new(40, 3),
    Jump::new(43, 18),
    Jump::new(54, 31),
    Jump::new(66, 45),
    Jump::new(76, 58),
    Jump::new(89, 53),
    Jump::new(99, 41),
];

/// 게임판을 만들 수 없는 이유입니다.
#[derive(Debug, PartialEq, Eq)]
pub enum BoardError {
    /// 출발 칸은 2~99, 도착 칸은 1~100이어야 합니다.
    OutOfRange { from: u8, to: u8 },
    /// 출발 칸과 도착 칸이 같습니다.
    SameSquare(u8),
    /// 한 칸에 사다리나 뱀이 두 개 이상 겹쳐 있습니다.
    DuplicateStart(u8),
    /// 도착 칸에 또 다른 사다리나 뱀이 있습니다.
    Chained { from: u8, to: u8 },
}

impl fmt::Display for BoardError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::OutOfRange { from, to } => write!(
                f,
                "{from}→{to}: 출발 칸은 2~99, 도착 칸은 1~{GOAL}이어야 합니다"
            ),
            Self::SameSquare(square) => {
                write!(f, "{square}칸: 출발 칸과 도착 칸이 같습니다")
            }
            Self::DuplicateStart(square) => {
                write!(f, "{square}칸: 사다리나 뱀이 두 개 이상 겹쳐 있습니다")
            }
            Self::Chained { from, to } => write!(
                f,
                "{from}→{to}: 도착 칸에 또 다른 사다리나 뱀이 있습니다"
            ),
        }
    }
}

impl std::error::Error for BoardError {}

/// 주사위를 한 번 굴려 움직인 결과입니다.
#[derive(Debug, PartialEq, Eq)]
pub struct MoveOutcome {
    /// 움직이기 전 칸 (0은 출발 전)
    pub from: u8,
    /// 주사위 수만큼 움직여 처음 멈춘 칸
    pub landed: u8,
    /// 100칸을 넘어서 되돌아왔는지 여부
    pub bounced: bool,
    /// 멈춘 칸에서 사다리나 뱀을 만났는지 여부
    pub jump: Option<JumpKind>,
    /// 최종 칸
    pub to: u8,
}

/// 사다리와 뱀이 놓인 게임판입니다.
#[derive(Debug)]
pub struct Board {
    jumps: BTreeMap<u8, u8>,
}

impl Board {
    /// 사다리·뱀 목록으로 게임판을 만듭니다. 규칙에 맞지 않으면 이유를 돌려줍니다.
    pub fn new(jumps: &[Jump]) -> Result<Self, BoardError> {
        let mut map = BTreeMap::new();
        for jump in jumps {
            let (from, to) = (jump.from, jump.to);
            if !(2..GOAL).contains(&from) || !(1..=GOAL).contains(&to) {
                return Err(BoardError::OutOfRange { from, to });
            }
            if from == to {
                return Err(BoardError::SameSquare(from));
            }
            if map.insert(from, to).is_some() {
                return Err(BoardError::DuplicateStart(from));
            }
        }
        if let Some((&from, &to)) = map.iter().find(|&(_, to)| map.contains_key(to)) {
            return Err(BoardError::Chained { from, to });
        }
        Ok(Self { jumps: map })
    }

    /// 기본 배치의 게임판을 만듭니다.
    pub fn standard() -> Result<Self, BoardError> {
        Self::new(&STANDARD_JUMPS)
    }

    /// 이 칸에 사다리나 뱀이 있으면 옮겨 갈 칸을 돌려줍니다.
    pub fn destination(&self, square: u8) -> Option<u8> {
        self.jumps.get(&square).copied()
    }

    /// 이 칸에 있는 것이 사다리인지 뱀인지 알려 줍니다.
    pub fn kind_at(&self, square: u8) -> Option<JumpKind> {
        self.destination(square)
            .map(|to| JumpKind::between(square, to))
    }

    /// 사다리 목록 (출발 칸, 도착 칸)입니다.
    pub fn ladders(&self) -> impl Iterator<Item = (u8, u8)> {
        self.pairs().filter(|&(from, to)| to > from)
    }

    /// 뱀 목록 (머리 칸, 꼬리 칸)입니다.
    pub fn snakes(&self) -> impl Iterator<Item = (u8, u8)> {
        self.pairs().filter(|&(from, to)| to < from)
    }

    fn pairs(&self) -> impl Iterator<Item = (u8, u8)> {
        self.jumps.iter().map(|(&from, &to)| (from, to))
    }

    /// `from` 칸에서 주사위 `roll`만큼 움직인 결과를 계산합니다.
    /// 100칸을 넘으면 넘친 만큼 되돌아옵니다.
    pub fn advance(&self, from: u8, roll: u8) -> MoveOutcome {
        let from = from.min(GOAL);
        let target = from.saturating_add(roll);
        let (landed, bounced) = if target > GOAL {
            (GOAL.saturating_sub(target - GOAL), true)
        } else {
            (target, false)
        };
        let (jump, to) = match self.destination(landed) {
            Some(to) => (Some(JumpKind::between(landed, to)), to),
            None => (None, landed),
        };
        MoveOutcome {
            from,
            landed,
            bounced,
            jump,
            to,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn standard_board_is_valid() -> Result<(), BoardError> {
        let board = Board::standard()?;
        assert_eq!(board.ladders().count(), 7);
        assert_eq!(board.snakes().count(), 8);
        assert!(board.ladders().all(|(from, to)| to > from));
        assert!(board.snakes().all(|(from, to)| to < from));
        Ok(())
    }

    #[test]
    fn rejects_out_of_range_squares() {
        assert_eq!(
            Board::new(&[Jump::new(1, 10)]).err(),
            Some(BoardError::OutOfRange { from: 1, to: 10 })
        );
        assert_eq!(
            Board::new(&[Jump::new(100, 5)]).err(),
            Some(BoardError::OutOfRange { from: 100, to: 5 })
        );
        assert_eq!(
            Board::new(&[Jump::new(10, 101)]).err(),
            Some(BoardError::OutOfRange { from: 10, to: 101 })
        );
    }

    #[test]
    fn rejects_same_square() {
        assert_eq!(
            Board::new(&[Jump::new(10, 10)]).err(),
            Some(BoardError::SameSquare(10))
        );
    }

    #[test]
    fn rejects_duplicate_start() {
        assert_eq!(
            Board::new(&[Jump::new(10, 20), Jump::new(10, 30)]).err(),
            Some(BoardError::DuplicateStart(10))
        );
    }

    #[test]
    fn rejects_chained_jumps() {
        assert_eq!(
            Board::new(&[Jump::new(10, 20), Jump::new(20, 5)]).err(),
            Some(BoardError::Chained { from: 10, to: 20 })
        );
    }

    #[test]
    fn plain_move() -> Result<(), BoardError> {
        let board = Board::standard()?;
        let outcome = board.advance(0, 2);
        assert_eq!(
            outcome,
            MoveOutcome {
                from: 0,
                landed: 2,
                bounced: false,
                jump: None,
                to: 2
            }
        );
        Ok(())
    }

    #[test]
    fn ladder_moves_up() -> Result<(), BoardError> {
        let board = Board::standard()?;
        let outcome = board.advance(0, 4);
        assert_eq!(outcome.landed, 4);
        assert_eq!(outcome.jump, Some(JumpKind::Ladder));
        assert_eq!(outcome.to, 25);
        Ok(())
    }

    #[test]
    fn snake_moves_down() -> Result<(), BoardError> {
        let board = Board::standard()?;
        let outcome = board.advance(22, 5);
        assert_eq!(outcome.landed, 27);
        assert_eq!(outcome.jump, Some(JumpKind::Snake));
        assert_eq!(outcome.to, 5);
        Ok(())
    }

    #[test]
    fn exact_roll_reaches_goal() -> Result<(), BoardError> {
        let board = Board::standard()?;
        let outcome = board.advance(94, 6);
        assert!(!outcome.bounced);
        assert_eq!(outcome.to, GOAL);
        Ok(())
    }

    #[test]
    fn overshoot_bounces_back() -> Result<(), BoardError> {
        let board = Board::standard()?;
        let outcome = board.advance(98, 5);
        assert!(outcome.bounced);
        assert_eq!(outcome.landed, 97);
        assert_eq!(outcome.to, 97);
        Ok(())
    }

    #[test]
    fn bounce_can_land_on_snake() -> Result<(), BoardError> {
        let board = Board::standard()?;
        let outcome = board.advance(97, 4);
        assert!(outcome.bounced);
        assert_eq!(outcome.landed, 99);
        assert_eq!(outcome.jump, Some(JumpKind::Snake));
        assert_eq!(outcome.to, 41);
        Ok(())
    }

    #[test]
    fn huge_roll_does_not_panic() -> Result<(), BoardError> {
        let board = Board::standard()?;
        let outcome = board.advance(100, u8::MAX);
        assert!(outcome.bounced);
        assert!(outcome.to <= GOAL);
        Ok(())
    }
}
