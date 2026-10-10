<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Layouts;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 운영자가 읽는 안내 문구 ↔ 실제 동작·형제 어휘 대조
 *
 * 16차가 세운 규율("문구가 인용하는 것이 화면에 실재하는지 대조한다")의 다음 축이다.
 * 그 라운드는 문구가 지목한 **컨트롤**이 없는 경우를 닫았고, 여기서는 문구가 서술한
 * **동작**이 실제와 다른 경우와, 같은 개념을 형제 문구가 **다른 단어**로 부르는 경우를 닫는다.
 *
 * 두 결함 모두 예외도 로그도 남기지 않는다 — 화면은 정상으로 보이고 문구만 사실이 아니다.
 * 사전 키 파리티·3언어 대칭·토큰 집합 동일성이 모두 초록인 채로 통과한다.
 *
 * @scenario claim_axis=type_status
 *
 * @effects order_list_claim_filter_vocabulary_matches_server_gate
 */
class ClaimWordingMatchesBehaviourTest extends ModuleTestCase
{
    /**
     * 모듈 루트 경로를 반환합니다.
     *
     * @return string 모듈 루트 절대경로
     */
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 프론트 다국어 파티션을 로케일별로 읽습니다.
     *
     * ja 는 번들 언어팩이 소유하므로 경로가 다르다 — ko/en 만 검사하면 팩 쪽 문구가
     * 사실과 달라져도 드러나지 않는다.
     *
     * @param  string  $partial  파티션 상대 경로 (예: 'admin/order.json')
     * @return array<string, array<string, mixed>> 로케일 => 디코드된 사전
     */
    private function frontendLangs(string $partial): array
    {
        $root = $this->moduleRoot();
        $paths = [
            'ko' => $root.'/resources/lang/partial/ko/'.$partial,
            'en' => $root.'/resources/lang/partial/en/'.$partial,
            'ja' => dirname($root, 3).'/lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/'.$partial,
        ];

        $out = [];
        foreach ($paths as $locale => $path) {
            $this->assertFileExists($path, "{$locale} 사전을 찾지 못했다 — 모집단이 비면 이 검사가 공전한다");
            $out[$locale] = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($out[$locale], "{$locale} 사전을 파싱하지 못했다");
        }

        return $out;
    }

    /**
     * 클레임 필터 안내가 "귀책을 지정하면 취소가 빠진다" 는 사실을 말한다.
     *
     * `OrderRepository::applyClaimCriteriaFilters()` 는 귀책이 지정되면 대상 유형에서
     * `cancel` 을 뺀다 — 취소 표에 귀책 컬럼이 없기 때문이다. 그런데 안내는 "유형별 상태를
     * 지정하지 않으면 유형과 무관하게 거른다" 고만 말했다. 운영자가 귀책=고객 을 고르면
     * 취소 건이 조용히 사라지고, 귀책 + 취소유형만 고르면 결과가 영구 0건인데 안내는
     * 그 반대를 약속한다.
     */
    public function test_claim_filter_hint_states_that_selecting_a_fault_excludes_cancellations(): void
    {
        // 문구가 서술하는 동작이 지금도 그러한지 먼저 확인한다. 저장소가 제외를 그만두면
        // 이 단언이 붉어져 "문구를 되돌려라" 로 이어진다 — 한쪽만 바뀌는 것을 막는 축이다.
        $repository = (string) file_get_contents(
            $this->moduleRoot().'/src/Repositories/OrderRepository.php'
        );
        $this->assertMatchesRegularExpression(
            "/array_diff\(\\\$types,\s*\['cancel'\]\)/",
            $repository,
            '저장소가 더 이상 귀책 필터에서 취소를 제외하지 않는다 — 그렇다면 안내 문구도 함께 되돌려야 한다'
        );

        // 로케일마다 쓰는 낱말이 다르므로 문면이 아니라 **취소를 가리키는 어휘**를 본다.
        $cancelWord = [
            'ko' => '/취소/u',
            'en' => '/cancellation/iu',
            'ja' => '/キャンセル/u',
        ];

        foreach ($this->frontendLangs('admin/order.json') as $locale => $lang) {
            $hint = $lang['filter']['claim']['block_hint'] ?? null;

            $this->assertIsString($hint, "{$locale}: 클레임 필터 안내 문구가 없다");
            $this->assertMatchesRegularExpression(
                $cancelWord[$locale],
                $hint,
                "{$locale}: 안내가 취소 제외를 말하지 않는다 — 운영자는 귀책을 골라도 취소가 함께 걸린다고 읽는다"
            );
        }
    }

    /**
     * 같은 개념(귀책)을 클레임 화면의 형제 문구가 한 낱말로 부른다.
     *
     * ko/en 은 세 자리 모두 한 낱말인데 ja 만 상세 라벨이 다른 낱말이었다. 그 결과
     * 클레임 상세에서 항목 이름과 그 값이 서로 다른 말을 써, 운영자가 누가 부담하는지
     * 판단하는 바로 그 화면에서 같은 개념이 둘로 보였다.
     *
     * 기준은 ko 다 — ko 가 세 자리에 같은 낱말을 쓰는 한, 나머지 로케일도 그래야 한다.
     */
    public function test_fault_wording_is_one_word_per_locale_across_sibling_claim_labels(): void
    {
        $langs = $this->frontendLangs('admin/claim.json');

        // 형제 자리 3종 — 상세 라벨 / 목록 필터 / 목록 컬럼
        $readers = [
            'detail.reason.fault_type_label' => fn (array $l) => $l['detail']['reason']['fault_type_label'] ?? null,
            'filter.fault_type' => fn (array $l) => $l['filter']['fault_type'] ?? null,
            'column.fault_type' => fn (array $l) => $l['column']['fault_type'] ?? null,
        ];

        // ko 가 기준을 이룬다는 전제부터 고정한다. ko 가 갈라지면 이 검사는 근거를 잃는다.
        $koWords = [];
        foreach ($readers as $label => $read) {
            $value = $read($langs['ko']);
            $this->assertIsString($value, "ko: {$label} 문구가 없다");
            $koWords[$label] = $value;
        }
        $this->assertCount(
            1,
            array_unique($koWords),
            'ko 가 형제 자리에서 이미 두 낱말을 쓴다 — 기준이 무너져 다른 로케일을 판정할 수 없다'
        );

        foreach (['en', 'ja'] as $locale) {
            $words = [];
            foreach ($readers as $label => $read) {
                $value = $read($langs[$locale]);
                $this->assertIsString($value, "{$locale}: {$label} 문구가 없다");
                $words[$label] = $value;
            }

            $this->assertCount(
                1,
                array_unique($words),
                "{$locale}: 같은 개념을 형제 자리가 다른 낱말로 부른다 — 상세 화면에서 항목 이름과 값이 다른 말을 쓴다 (".
                    json_encode($words, JSON_UNESCAPED_UNICODE).')'
            );
        }
    }
}
