<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문상태 라벨 일관성 테스트 (S3-D1 회귀 · #599 통합실측 세션3)
 *
 * 같은 주문상태가 화면마다 다른 이름으로 불리면 운영자가 서로 다른 상태로 읽는다.
 * 관리자 주문상세에서 실제로 그 일이 났다 — 일괄변경 Select 와 진행 단계 스텝퍼는
 * `배송준비완료`(filter.order_status_options.shipping_ready)를 쓰는데, 같은 값을 고르고
 * 연 확인 모달의 「변경 상태」와 주문상품 표 주문상태 열은
 * `배송준비중`(status.shipping_ready)을 보여줬다. 운영자는 한 값을 골랐는데
 * 확인창이 다른 이름으로 바꾸겠다고 묻는다.
 *
 * en 은 두 키가 모두 "Ready to Ship" 로 같아 이 어긋남은 ko 에만 있었다.
 * **정본은 `배송준비중`**(운영 결정 2026-09-15) — 어긋나 있던 쪽이 아니라
 * 더 널리 쓰이던 `배송준비완료` 를 전면 교체했다.
 *
 * 이 라벨들은 레이아웃 JSON 이 `$t:` 로 참조해 **프론트엔드가** 해석한다
 * (`lang/{locale}.json` 은 `$partial` 참조만 담아 PHP `__()` 로는 보이지 않는다).
 * 그래서 파셜 언어 파일을 직접 읽어 정적으로 잠근다.
 */
class OrderStatusLabelConsistencyTest extends ModuleTestCase
{
    /**
     * 라벨을 쓰는 세 경로 — [파셜 파일, 파일 안 키 경로].
     *
     * - enums.json order_status.*                       : 정본(공용 enum 라벨)
     * - admin/order.json status.*                       : 확인 모달 · 주문상품 표 주문상태 열
     * - admin/order.json filter.order_status_options.*  : 일괄변경 Select · 진행 단계 · 목록 필터
     */
    private const PATHS = [
        ['enums.json', 'order_status'],
        ['admin/order.json', 'status'],
        ['admin/order.json', 'filter.order_status_options'],
    ];

    /**
     * 세 경로에 공통으로 존재하는 주문상태만 대조한다.
     */
    private const STATUSES = [
        'payment_complete',
        'preparing',
        'shipping_ready',
        'shipping',
        'delivered',
        'cancelled',
        'confirmed',
    ];

    /**
     * 모듈 언어 파셜 파일의 절대 경로를 만듭니다.
     *
     * @param  string  $locale  로케일 (ko/en)
     * @param  string  $file  파셜 파일명
     * @return string 파셜 파일 경로
     */
    private function langPath(string $locale, string $file): string
    {
        return __DIR__."/../../../resources/lang/partial/{$locale}/{$file}";
    }

    /**
     * 언어 파셜 파일에서 점 경로의 라벨 문자열을 읽습니다 — 없거나 문자열이 아니면 null 입니다.
     *
     * @param  string  $locale  로케일 (ko/en)
     * @param  string  $file  파셜 파일명
     * @param  string  $path  점으로 구분한 키 경로
     * @return string|null 라벨 문자열 (없으면 null)
     */
    private function readKey(string $locale, string $file, string $path): ?string
    {
        $full = $this->langPath($locale, $file);
        $this->assertFileExists($full, "언어 파셜 파일이 없습니다: {$locale}/{$file}");

        $data = json_decode((string) file_get_contents($full), true);
        $this->assertIsArray($data, "언어 파셜 파일이 JSON 이 아닙니다: {$locale}/{$file}");

        $node = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }

    // 이 테스트는 세 경로를 한 루프로 함께 대조한다 (docblock 은 조합을 1건만 인식하므로 나머지 둘은 라인 주석으로 병기).
    // @scenario label_path=admin_order_status
    // @scenario label_path=admin_order_filter_options

    /**
     * 한 주문상태는 어느 화면에서도 같은 이름으로 불려야 한다.
     *
     * @scenario label_path=enums_order_status
     *
     * @effects order_status_label_identical_across_paths
     *
     * @return void 반환값 없음
     */
    public function test_order_status_labels_are_identical_across_surfaces(): void
    {
        foreach (['ko', 'en'] as $locale) {
            foreach (self::STATUSES as $status) {
                $resolved = [];

                foreach (self::PATHS as [$file, $prefix]) {
                    $label = $this->readKey($locale, $file, "{$prefix}.{$status}");

                    $this->assertNotNull(
                        $label,
                        "[{$locale}] {$file} 의 {$prefix}.{$status} 라벨이 없습니다."
                    );

                    $resolved["{$file}:{$prefix}"] = $label;
                }

                $this->assertCount(
                    1,
                    array_unique(array_values($resolved)),
                    "[{$locale}] 주문상태 '{$status}' 의 라벨이 화면마다 다릅니다: "
                        .json_encode($resolved, JSON_UNESCAPED_UNICODE)
                );
            }
        }
    }

    /**
     * `shipping_ready` 는 정본대로 `배송준비중` 이어야 한다 (S3-D1 본체 · 운영 결정).
     *
     * 세 경로를 한 값으로 맞출 때 아무 쪽으로나 맞춰도 위 일관성 테스트는 통과하므로,
     * 정본 문자열 자체를 따로 잠근다.
     *
     * @scenario label_path=admin_order_status
     *
     * @effects shipping_ready_uses_canonical_korean_label
     *
     * @return void 반환값 없음
     */
    public function test_shipping_ready_uses_canonical_korean_label(): void
    {
        foreach (self::PATHS as [$file, $prefix]) {
            $this->assertSame(
                '배송준비중',
                $this->readKey('ko', $file, "{$prefix}.shipping_ready"),
                "[ko] {$file} 의 {$prefix}.shipping_ready 가 정본 라벨과 다릅니다."
            );
        }
    }

    /**
     * 백엔드 enum 라벨(서버가 만드는 진행단계 스텝퍼·통계가 쓴다)도 같은 정본이어야 한다.
     *
     * 프론트 파셜만 고치면 관리자·회원·비회원 세 상세의 스텝퍼가 서버 라벨(`배송준비완료`)로
     * 남아 화면 안에서 다시 갈린다.
     *
     * @scenario label_path=enums_order_status
     *
     * @effects backend_enum_label_matches_canonical
     *
     * @return void 반환값 없음
     */
    public function test_backend_enum_label_matches_canonical(): void
    {
        $this->app->setLocale('ko');

        $this->assertSame(
            '배송준비중',
            __('sirsoft-ecommerce::enums.order_status.shipping_ready'),
            '[ko] 백엔드 enums.order_status.shipping_ready 가 정본 라벨과 다릅니다.'
        );
    }

    /**
     * 일괄변경 Select·목록 상태 필터가 쓰는 경로도 정본이어야 한다.
     *
     * 운영자가 **상태를 고르는** 자리라, 여기만 옛 라벨로 남으면 고른 이름과 적용 결과가
     * 다시 어긋난다(S3-D1 이 처음 드러난 지점이 이 Select 와 확인 모달의 불일치였다).
     *
     * @scenario label_path=admin_order_filter_options
     *
     * @effects shipping_ready_uses_canonical_korean_label
     *
     * @return void 반환값 없음
     */
    public function test_admin_filter_options_use_canonical_label(): void
    {
        $this->assertSame(
            '배송준비중',
            $this->readKey('ko', 'admin/order.json', 'filter.order_status_options.shipping_ready'),
            '[ko] 일괄변경 Select·목록 필터의 shipping_ready 가 정본 라벨과 다릅니다.'
        );
    }
}
