//! 주사위: 외부 크레이트 없이 만든 간단한 난수 생성기(xorshift64)입니다.

use std::time::{SystemTime, SystemTimeError, UNIX_EPOCH};

/// 현재 시각을 읽지 못했을 때 대신 쓰는 시드입니다.
pub const FALLBACK_SEED: u64 = 0x9E37_79B9_7F4A_7C15;

/// 1~6이 나오는 주사위입니다. 같은 시드를 주면 같은 순서로 나옵니다.
#[derive(Debug)]
pub struct Dice {
    state: u64,
}

impl Dice {
    pub fn new(seed: u64) -> Self {
        let mixed = splitmix64(seed);
        // xorshift는 상태가 0이면 계속 0만 나오므로 0을 피합니다.
        let state = if mixed == 0 { FALLBACK_SEED } else { mixed };
        Self { state }
    }

    /// 주사위를 굴려 1~6 중 하나를 돌려줍니다.
    pub fn roll(&mut self) -> u8 {
        let face = self.next_u64() % 6;
        // face는 0~5이므로 u8로 바꿔도 값이 잘리지 않습니다.
        face as u8 + 1
    }

    fn next_u64(&mut self) -> u64 {
        let mut x = self.state;
        x ^= x << 13;
        x ^= x >> 7;
        x ^= x << 17;
        self.state = x;
        x
    }
}

/// 비슷한 시드도 서로 많이 다른 값이 되도록 섞습니다.
fn splitmix64(seed: u64) -> u64 {
    let mut z = seed.wrapping_add(0x9E37_79B9_7F4A_7C15);
    z = (z ^ (z >> 30)).wrapping_mul(0xBF58_476D_1CE4_E5B9);
    z = (z ^ (z >> 27)).wrapping_mul(0x94D0_49BB_1331_11EB);
    z ^ (z >> 31)
}

/// 현재 시각(나노초)으로 시드를 만듭니다. 시계가 1970년보다 이전이면 오류를 돌려줍니다.
pub fn seed_from_time() -> Result<u64, SystemTimeError> {
    let elapsed = SystemTime::now().duration_since(UNIX_EPOCH)?;
    // 아래 64비트만 씁니다. 시드로는 이것으로 충분합니다.
    Ok(elapsed.as_nanos() as u64)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rolls_stay_between_one_and_six() {
        let mut dice = Dice::new(7);
        assert!((0..10_000).all(|_| (1..=6).contains(&dice.roll())));
    }

    #[test]
    fn same_seed_gives_same_rolls() {
        let mut first = Dice::new(1234);
        let mut second = Dice::new(1234);
        let a: Vec<u8> = (0..100).map(|_| first.roll()).collect();
        let b: Vec<u8> = (0..100).map(|_| second.roll()).collect();
        assert_eq!(a, b);
    }

    #[test]
    fn zero_seed_still_works() {
        let mut dice = Dice::new(0);
        let rolls: Vec<u8> = (0..100).map(|_| dice.roll()).collect();
        assert!(rolls.iter().any(|&r| r != rolls[0]));
    }

    #[test]
    fn every_face_appears_evenly() {
        let mut dice = Dice::new(2026);
        let mut counts = [0_u32; 6];
        for _ in 0..6_000 {
            let face = usize::from(dice.roll() - 1);
            if let Some(count) = counts.get_mut(face) {
                *count += 1;
            }
        }
        // 기댓값은 각 1000번입니다. 넉넉하게 ±10% 안에 들어오는지 확인합니다.
        assert!(
            counts.iter().all(|&c| (900..=1100).contains(&c)),
            "면별 횟수: {counts:?}"
        );
    }
}
