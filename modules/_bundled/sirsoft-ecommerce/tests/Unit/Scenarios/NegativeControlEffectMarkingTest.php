<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Scenarios;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 거절을 이름에 담은 effect 는 거절을 단언하는 케이스만 마킹한다
 *
 * 시나리오 커버리지 룰(`test-scenario-coverage`)은 **마킹의 존재**만 세고, 그 마킹을 단
 * 테스트가 실제로 무엇을 단언하는지는 읽지 않는다(매니페스트 자신이 그렇게 기록해 두었다).
 * 그래서 음성 대조군(미사용 개명이 200 으로 통과하는 것 등)이 거절 effect 를 함께 마킹하면
 * **그 셀이 200 단언만으로 채워진다** — 400/422 가드가 통째로 회귀해도 커버리지는 초록이다.
 *
 * 이 형태가 이 모듈에서 6건 나왔다(클레임 사유 2 · 배송유형 2 · 배송사 1 · 쿠폰 2 중
 * 중복 제외). 전부 "거절 effect 를 마킹한 200 케이스" 였고, 매니페스트가 앞서 스스로
 * 세운 원칙("축값 하나가 두 계약을 뭉개면 안 된다")과 상충했다.
 *
 * **이 검사의 모집단은 저장소에서 파생한다** — 테스트 디렉토리 전체를 훑어 `@effects` 를 단
 * 메서드를 모으므로, 새 테스트가 같은 형태를 복제하면 손을 대지 않아도 걸린다.
 */
class NegativeControlEffectMarkingTest extends ModuleTestCase
{
    /** 거절을 이름에 담은 effect 로 볼 어휘 */
    private const REJECT_EFFECT = '/(returns_4\d\d|returns_5\d\d|_reject|_blocked|_locked|_denied|_forbidden|_unauthorized)/';

    /** 거절을 단언하는 형태 */
    private const REJECT_ASSERTION = '/assertStatus\(\s*[45]\d\d\s*\)'
        .'|assertJsonValidationErrors'
        .'|assertForbidden|assertUnauthorized|assertNotFound|assertServerError'
        .'|expectException/';

    /** 성공을 단언하는 형태 */
    private const OK_ASSERTION = '/assertOk\(\)|assertStatus\(\s*20[01]\s*\)|assertCreated\(\)/';

    /**
     * 거절 어휘가 **도메인 낱말**로 들어간 effect — 요청 거절이 아니라 성공이 계약이다.
     *
     * 어휘 판정(`REJECT_EFFECT`)은 이름만 본다. 클레임의 「반려」(관리자가 신청을 반려하는 처리 자체),
     * 주문 권한이 없는 회원 상태, 「막히지 않는다」는 부정형, 잠긴 필드 종류처럼 거절 낱말이 도메인
     * 뜻으로 쓰이면 성공(2xx) 단언이 맞는 계약인데도 음성 대조군으로 오인한다. 어휘를 좁히면
     * `core_fields_untouched_on_rejection` 같은 진짜 거절 effect 까지 빠지므로, 이름을 하나씩 적고
     * 사유를 남긴다. 목록이 매니페스트에서 사라진 effect 를 들고 있지 않은지는
     * `every_domain_word_exception_is_still_declared` 가 지킨다.
     *
     * @var array<string, string> effect => 성공이 계약인 이유
     */
    private const DOMAIN_WORD_EFFECTS = [
        'claim_header_hides_rejection_when_items_are_ahead' => '상품 칸 반려 처리가 성공한 뒤 신청서 칸 선택지에서 「반려」 가 빠지는지 본다',
        'claim_item_rejection_reason_reaches_the_claim' => '상품 칸 반려 처리가 성공하고 그 사유가 신청서·상품 행에 남는지 본다',
        'claim_bulk_rejection_stores_reason_on_every_claim' => '목록 일괄 반려 처리가 성공하고 사유가 고른 신청서 전부에 들어가는지 본다',
        'order_collection_returns_can_create_false_for_blocked_user' => '주문 권한이 없는 회원도 목록 조회는 성공하고 can_create 만 false 다',
        'mileage_preview_not_blocked_by_limit' => '부정형 — 한도가 바뀌어도 체크아웃 조회가 막히지 않고 200 이어야 한다',
        'builtin_and_locked_fields_not_listed' => '잠긴(고정) 필드 종류가 목록에서 빠지는지 — 조회 성공 응답의 내용 계약이다',
    ];

    /**
     * 이 모듈 테스트 디렉토리의 PHP 파일 경로를 모두 모읍니다.
     *
     * @return array<int, string> 파일 절대경로 배열
     */
    private function phpFilesUnderTests(): array
    {
        $root = dirname(__DIR__, 2);

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * `@effects` 를 단 테스트 메서드를 (파일, 메서드명, effects, 본문) 으로 모읍니다.
     *
     * @return array<int, array{file: string, method: string, effects: array<int, string>, body: string}>
     */
    private function markedMethods(): array
    {
        $found = [];

        foreach ($this->phpFilesUnderTests() as $path) {
            $source = (string) file_get_contents($path);

            // docblock 본문은 `*/` 를 포함할 수 없다. `(.*?)` 로 두면 lazy 라도 `s` 플래그 아래에서
            // 첫 `*/` 를 넘어 다음 `public function` 까지 확장되므로, **클래스 docblock 이 그 파일
            // 첫 메서드에 귀속**된다(그렇게 16건이 오귀속돼 있었다).
            preg_match_all(
                '#/\*\*((?:(?!\*/).)*)\*/\s*(?:\#\[[^\]]+\]\s*)*public function (\w+)\(#s',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                // `@effects` 는 여러 줄에 걸칠 수 있다(`*   다음_effect,` 로 이어진다). 첫 줄만 읽으면
                // **두 번째 줄에 적힌 거절 effect 가 이 검사를 통째로 빠져나간다** — 음성 대조군을
                // 잡으려고 만든 검사에 정확히 그 형태의 탈출구가 남는다.
                if (! preg_match('/@effects\s+(.+?)(?=\n\s*\*\s*@|$)/s', $match[1][0], $effectMatch)) {
                    continue;
                }

                // 이어진 줄의 `*` 와 들여쓰기를 걷어내 한 줄로 만든 뒤 쉼표로 나눈다.
                $effectList = (string) preg_replace('/\s*\n\s*\*\s*/', ' ', $effectMatch[1]);

                $start = $match[0][1] + strlen($match[0][0]);
                $next = strpos($source, "\n    /**", $start);
                $body = substr($source, $start, $next === false ? null : $next - $start);

                $found[] = [
                    'file' => basename($path),
                    'method' => $match[2][0],
                    'effects' => array_values(array_filter(array_map('trim', explode(',', $effectList)))),
                    'body' => $body,
                ];
            }
        }

        return $found;
    }

    #[Test]
    public function the_population_is_not_empty(): void
    {
        // 파싱 규약(docblock 바로 뒤 public function)이 바뀌면 아래 검사가 통째로 사문이 된다
        $this->assertGreaterThan(
            100,
            count($this->markedMethods()),
            '@effects 를 단 테스트를 하나도 못 찾았다 — 이 검사가 아무것도 재지 않고 있다'
        );
    }

    #[Test]
    public function a_rejection_effect_is_never_marked_by_a_success_only_case(): void
    {
        $offenders = [];

        foreach ($this->markedMethods() as $method) {
            $rejectEffects = array_values(array_filter(
                $method['effects'],
                fn (string $effect) => preg_match(self::REJECT_EFFECT, $effect) === 1
                    && ! array_key_exists($effect, self::DOMAIN_WORD_EFFECTS)
            ));

            if ($rejectEffects === []) {
                continue;
            }

            if (preg_match(self::REJECT_ASSERTION, $method['body']) === 1) {
                continue;
            }

            if (preg_match(self::OK_ASSERTION, $method['body']) !== 1) {
                continue;
            }

            $offenders[] = sprintf(
                '%s::%s -> %s',
                $method['file'],
                $method['method'],
                implode(', ', $rejectEffects)
            );
        }

        $this->assertSame(
            [],
            $offenders,
            "거절을 이름에 담은 effect 를 성공(2xx)만 단언하는 케이스가 마킹하고 있다.\n"
            ."그 셀은 200 단언만으로 채워지므로 거절 가드가 회귀해도 커버리지가 초록으로 남는다.\n"
            ."음성 대조군은 자기 계약을 말하는 effect 를 따로 선언해 마킹할 것:\n  "
            .implode("\n  ", $offenders)
        );
    }

    /**
     * 도메인 낱말 예외 목록이 매니페스트에서 사라진 effect 를 들고 있지 않다.
     *
     * effect 가 개명·삭제된 뒤에도 예외가 남으면, 같은 이름으로 새로 생긴 진짜 거절 effect 가
     * 검사 없이 빠져나간다. 예외는 지금 선언돼 있고 지금 어휘에 걸리는 이름만 둔다.
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_domain_word_exception_is_still_declared(): void
    {
        $manifests = '';
        foreach (glob(dirname(__DIR__, 2).'/scenarios/*.yaml') ?: [] as $path) {
            $manifests .= (string) file_get_contents($path);
        }
        $this->assertNotSame('', $manifests, '매니페스트를 하나도 읽지 못했다 — 이 검사가 아무것도 재지 않는다');

        $stale = [];
        foreach (array_keys(self::DOMAIN_WORD_EFFECTS) as $effect) {
            if (preg_match(self::REJECT_EFFECT, $effect) !== 1) {
                $stale[] = $effect.' (거절 어휘에 걸리지 않으므로 예외가 필요 없다)';
            } elseif (preg_match('/\b'.preg_quote($effect, '/').'\b/', $manifests) !== 1) {
                $stale[] = $effect.' (매니페스트에 선언돼 있지 않다)';
            }
        }

        $this->assertSame([], $stale, "도메인 낱말 예외 목록을 정리할 것:\n  ".implode("\n  ", $stale));
    }
}
