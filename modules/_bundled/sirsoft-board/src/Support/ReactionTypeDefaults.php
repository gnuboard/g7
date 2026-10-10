<?php

namespace Modules\Sirsoft\Board\Support;

/**
 * 반응 유형 기본값 단일 원천(SSoT).
 *
 * 같은 기본값이 시더(BoardReactionTypeSeeder)와 업그레이드 백필
 * (upgrades/data/1.0.3/migrations/07_SeedReactionTypes.php,
 * upgrades/data/1.2.0/migrations/06_SeedReactionTypes.php)에 따로 정의돼 있어
 * 한쪽만 고치면 신규 설치와 업그레이드 설치의 유형이 갈라졌다.
 *
 * 현행 SSoT 는 이 클래스이며 시더가 여기서 읽는다. 백필 스텝은 이미 실행된 설치본의
 * 재현성을 위해 값이 동결돼 있고(그 시점 값이 그대로 남아야 한다), 두 값의 드리프트는
 * tests/Unit/Support/ReactionTypeDefaultsTest 가 감시한다.
 *
 * 프레임워크에 의존하지 않는 순수 정적 배열이다 — 마이그레이션·시더·테스트 어디서나 부를 수 있다.
 */
final class ReactionTypeDefaults
{
    /**
     * 기본 반응 유형 전량.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'like',
                'name' => ['ko' => '추천', 'en' => 'Recommend', 'ja' => 'おすすめ'],
                'icon' => 'fas fa-thumbs-up',
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'dislike',
                'name' => ['ko' => '비추천', 'en' => 'Not Recommend', 'ja' => 'よくない'],
                'icon' => 'fas fa-thumbs-down',
                'display_order' => 2,
                'is_active' => true,
            ],
        ];
    }

    /**
     * 기본 반응 유형 code 목록.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }
}
