<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts;

use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 배지 색 어휘 ↔ 두 소비 화면의 색 사전 파리티
 *
 * `SummarizesClaims` 가 관리자 주문목록과 구매자 마이페이지에 **같은** `status_variant` 를
 * 내려보낸다(트레이트 자신이 "한쪽만 고치면 같은 주문이 화면마다 다른 상태로 보인다" 고
 * 선언한다). 그런데 색을 입히는 사전은 두 화면이 각자 갖는다 — 관리자는 `StatusBadge` 의
 * `statusStyles`, 구매자는 레이아웃 `classMap.variants` 다.
 *
 * 어긋나면 둘 다 조용하다:
 *  - 관리자: 사전에 없으면 `StatusBadge` 의 `default` 폴백으로 떨어진다. 그 폴백이 생기기
 *    전에는 조회 실패가 곧 className 의 리터럴 `"undefined"` 였고 배경·글자색·테두리가
 *    통째로 사라졌다 — 지금은 그 모드가 막혔지만, 레이아웃 JSON 이 TS 타입 검사 밖이라는
 *    사실은 그대로여서 어긋남 자체는 여전히 컴파일에 걸리지 않는다.
 *  - 구매자: 사전에 없으면 `classMap.default` 로 떨어진다.
 *
 * 즉 지금의 실패 모드는 양쪽 모두 "조용한 기본색" 하나다 — 서로 다른 상태가 같은 색이 되고
 * 같은 주문이 화면마다 다른 색으로 보인다. 그래서 폴백이 있어도 이 대조는 계속 필요하다.
 *
 * 그래서 Enum 이 실제로 내보내는 어휘를 이 테스트가 단일 출처로 고정하고, 두 사전이 그것을
 * 전부 덮는지 본다. Enum 에 새 variant 가 생기면 여기서 red 가 나 소비처를 함께 보게 된다.
 *
 * @scenario reason_type=exchange
 *
 * @effects claim_status_variant_vocabulary_covered_by_both_screens
 */
class ClaimStatusVariantVocabularyTest extends ModuleTestCase
{
    /** 관리자 배지 컴포넌트 (색 사전 보유) */
    private const STATUS_BADGE = __DIR__.'/../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/composite/StatusBadge.tsx';

    /** 구매자 마이페이지 주문 카드 (classMap 색 사전 보유) */
    private const MYPAGE_LIST = __DIR__.'/../../../../../../templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_list.json';

    /**
     * 세 Enum 이 실제로 내보내는 variant 어휘를 모읍니다.
     *
     * @return array<int, string> 정렬된 variant 값 목록
     */
    private function enumVariants(): array
    {
        $variants = [];

        // 취소 유형도 `SummarizesClaims` 가 배지로 내려보낸다 — 세 유형만 보면 취소 상태가 늘어도 게이트가 초록을 유지한다.
        foreach ([ExchangeStatusEnum::cases(), ReturnStatusEnum::cases(), RefundStatusEnum::cases(), CancelStatusEnum::cases()] as $cases) {
            foreach ($cases as $case) {
                $variants[] = $case->variant();
            }
        }

        $variants = array_values(array_unique($variants));
        sort($variants);

        return $variants;
    }

    /**
     * 관리자 배지 컴포넌트의 색 사전이 Enum 어휘를 전부 덮습니다.
     *
     * @return void
     */
    public function test_admin_status_badge_covers_every_enum_variant(): void
    {
        $source = file_get_contents(self::STATUS_BADGE);
        $this->assertNotFalse($source, 'StatusBadge.tsx 를 읽지 못했다 — 경로가 바뀌었는지 확인할 것');

        // `statusStyles` 블록만 본다. 유니언 타입에만 넣고 사전에 안 넣으면 여전히 무색이다.
        $this->assertSame(
            1,
            preg_match('/const statusStyles: Record<StatusType, string> = \{(.*?)\n  \};/s', $source, $m),
            'statusStyles 블록을 찾지 못했다 — 컴포넌트 구조가 바뀌었으면 이 테스트도 함께 갱신할 것'
        );

        preg_match_all('/^\s*([a-zA-Z]+):/m', $m[1], $keyMatches);
        $known = $keyMatches[1];

        foreach ($this->enumVariants() as $variant) {
            $this->assertContains(
                $variant,
                $known,
                "관리자 배지 사전에 `{$variant}` 가 없다 — 그 상태의 뱃지가 무색으로 렌더된다(오류·경고 없음)"
            );
        }
    }

    /**
     * 구매자 마이페이지의 classMap 사전이 Enum 어휘를 전부 덮습니다.
     *
     * @return void
     */
    public function test_mypage_class_map_covers_every_enum_variant(): void
    {
        $raw = file_get_contents(self::MYPAGE_LIST);
        $this->assertNotFalse($raw, '마이페이지 주문 카드 파셜을 읽지 못했다');

        $layout = json_decode($raw, true);
        $this->assertIsArray($layout, '마이페이지 주문 카드 파셜이 유효한 JSON 이 아니다');

        $variants = $this->findClaimVariantMap($layout);
        $this->assertNotNull($variants, '클레임 배지의 classMap.variants 를 찾지 못했다 — 배지가 사라졌는지 확인할 것');

        foreach ($this->enumVariants() as $variant) {
            $this->assertArrayHasKey(
                $variant,
                $variants,
                "구매자 배지 사전에 `{$variant}` 가 없다 — 그 상태가 default 색으로 떨어져 다른 상태와 구분되지 않는다"
            );
        }
    }

    /**
     * S10 이 신설한 Enum 라벨은 프론트 사본과 백엔드 정본이 같은 말을 해야 한다.
     *
     * 이 케이스는 클래스 docblock 의 배지 색 어휘 effect 를 물려받지 않는다 — variant 를
     * 한 번도 보지 않는 다른 축이다.
     *
     * @scenario reason_type=exchange
     *
     * @effects claim_enum_labels_agree_between_screen_and_api
     *
     * 같은 Enum 값의 라벨이 두 곳에 있다 — 화면(프론트 partial `enums.json`)과 API 응답
     * (백엔드 `enums.php` 를 `label()` 이 읽는다). 두 사전은 서로 다른 네임스페이스 파일이라
     * 키 파리티 검사가 **값을 대조하지 않는다**. 갈리면 운영자는 주문목록 필터 칩에서 한 말을,
     * 같은 값의 클레임 목록·상세 라벨에서 다른 말을 본다 — 오류도 로그도 없다.
     *
     * 대상은 S10 이 새로 더한 두 그룹으로 한정한다. HEAD 전반의 선재 불일치까지 넓히면
     * 이번 변경과 무관한 모집단이 들어와 최소 범위를 넘는다(그 축은 S16 소유).
     */
    public function test_s10_enum_labels_agree_between_frontend_and_backend(): void
    {
        $groups = [
            'cancel_type' => ['full', 'partial'],
            'claim_shipping_fee_mode' => ['original_policy', 'fixed'],
        ];

        $sources = [
            'ko' => [
                'frontend' => json_decode((string) file_get_contents(__DIR__.'/../../../resources/lang/partial/ko/enums.json'), true),
                'backend' => require __DIR__.'/../../../src/lang/ko/enums.php',
            ],
            'en' => [
                'frontend' => json_decode((string) file_get_contents(__DIR__.'/../../../resources/lang/partial/en/enums.json'), true),
                'backend' => require __DIR__.'/../../../src/lang/en/enums.php',
            ],
        ];

        foreach ($sources as $locale => $pair) {
            foreach ($groups as $group => $keys) {
                foreach ($keys as $key) {
                    $frontend = $pair['frontend'][$group][$key] ?? null;
                    $backend = $pair['backend'][$group][$key] ?? null;

                    $this->assertNotNull($frontend, "{$locale} 프론트 사전에 {$group}.{$key} 가 없다");
                    $this->assertNotNull($backend, "{$locale} 백엔드 사전에 {$group}.{$key} 가 없다");
                    $this->assertSame(
                        $frontend,
                        $backend,
                        "{$locale} 의 {$group}.{$key} 가 화면과 API 응답에서 다른 말을 한다"
                    );
                }
            }
        }
    }

    /**
     * `claim.status_variant` 를 key 로 쓰는 classMap 의 variants 를 찾습니다.
     *
     * @param  mixed  $node  레이아웃 노드
     * @return array<string, string>|null 찾은 variants 맵 (없으면 null)
     */
    private function findClaimVariantMap(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        $classMap = $node['classMap'] ?? null;

        if (is_array($classMap)
            && is_string($classMap['key'] ?? null)
            && str_contains($classMap['key'], 'status_variant')
            && is_array($classMap['variants'] ?? null)) {
            return $classMap['variants'];
        }

        foreach ($node as $child) {
            $found = $this->findClaimVariantMap($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
