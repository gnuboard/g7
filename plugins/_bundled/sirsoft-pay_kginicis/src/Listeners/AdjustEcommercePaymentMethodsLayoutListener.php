<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 이커머스 결제수단 설정 화면에 KG 이니시스 테스트모드 경고를 주입한다.
 *
 * 간편결제의 PG 표시(PG 고정 배지)는 코어 레이아웃이 결제수단 카탈로그의 pg_locked /
 * needs_pg 를 읽어 직접 처리하므로 이 리스너가 관여하지 않는다(#475).
 */
class AdjustEcommercePaymentMethodsLayoutListener implements HookListenerInterface
{
    private const TARGET_LAYOUT = 'admin_ecommerce_settings';

    private const TEST_MODE_DATA_SOURCE_ID = 'kginicis_test_mode_status';

    private const TEST_MODE_NOTICE_ID = 'payment_test_mode_order_settings_notice';

    private const TEST_MODE_NOTICE_ROW_ID = 'kginicis_test_mode_order_settings_notice';

    /** 주문설정 탭이 활성일 때만 fetch (탭별 지연 로딩) */
    private const ORDER_SETTINGS_TAB_CONDITION = "{{(query.tab || _global.activeEcommerceSettingsTab || 'basic_info') === 'order_settings'}}";

    private const TEST_MODE_CONDITION = 'kginicis_test_mode_status.data?.is_test_mode === true';

    /**
     * 이 리스너가 구독하는 훅 정의를 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'adjustPaymentMethodsLayout',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 이커머스 결제수단 설정 레이아웃에 테스트모드 경고를 주입합니다.
     *
     * 과거에는 코어 레이아웃의 "PG 불필요 결제수단" 하드코딩 배열
     * (`['point','deposit','free','dbank']`)을 문자열 치환해 간편결제를 그 목록에 끼워 넣어
     * PG 선택 셀렉트를 숨겼다. 그러나 간편결제는 "PG 불필요" 가 아니라 "PG 고정" 이며,
     * 이 표시 왜곡은 실제로 서버가 간편결제를 PG 없는 주문으로 오인하던 결함과 짝을 이뤘다(#475).
     *
     * 이제 결제수단 카탈로그가 `pg_locked` / `needs_pg` 를 내려주고 코어 레이아웃이 그 값으로
     * 직접 3분기(PG 고정 배지 / PG 선택 / PG 불필요)하므로 문자열 치환이 불필요하다.
     * (플러그인 간 누적 치환 충돌 위험도 함께 사라진다.)
     *
     * @param  array<string, mixed>  $layout  대상 레이아웃 정의
     * @param  int  $templateId  레이아웃이 속한 템플릿 ID
     * @return array<string, mixed> 보정된 레이아웃 정의
     */
    public function adjustPaymentMethodsLayout(array $layout, int $templateId): array
    {
        if (($layout['layout_name'] ?? '') !== self::TARGET_LAYOUT) {
            return $layout;
        }

        return $this->ensureTestModeWarning($layout);
    }

    /**
     * 테스트 모드 경고에 필요한 데이터 소스와 안내 노드를 레이아웃에 보장합니다.
     *
     * @param  array<string, mixed>  $layout  대상 레이아웃 정의
     * @return array<string, mixed> 경고가 보장된 레이아웃 정의
     */
    private function ensureTestModeWarning(array $layout): array
    {
        $layout = $this->ensureTestModeDataSource($layout);

        return $this->insertTestModeNotice($layout);
    }

    /**
     * 테스트 모드 상태 조회 데이터 소스가 없으면 레이아웃에 추가합니다.
     *
     * @param  array<string, mixed>  $layout  대상 레이아웃 정의
     * @return array<string, mixed> 데이터 소스가 보장된 레이아웃 정의
     */
    private function ensureTestModeDataSource(array $layout): array
    {
        $dataSources = is_array($layout['data_sources'] ?? null) ? $layout['data_sources'] : [];

        foreach ($dataSources as $source) {
            if (is_array($source) && ($source['id'] ?? null) === self::TEST_MODE_DATA_SOURCE_ID) {
                $layout['data_sources'] = $dataSources;

                return $layout;
            }
        }

        $dataSources[] = [
            'id' => self::TEST_MODE_DATA_SOURCE_ID,
            'label_key' => '$t:sirsoft-pay_kginicis.editor.data_source.kginicis_test_mode_status',
            'type' => 'api',
            'endpoint' => '/api/plugins/sirsoft-pay_kginicis/admin/settings/test-mode-status',
            'method' => 'GET',
            'if' => self::ORDER_SETTINGS_TAB_CONDITION,
            'auto_fetch' => true,
            'auth_required' => true,
        ];

        $layout['data_sources'] = $dataSources;

        return $layout;
    }

    /**
     * 주문설정 탭을 찾아 테스트 모드 안내 노드를 삽입합니다.
     *
     * @param  array<string, mixed>  $node  순회 대상 노드
     * @return array<string, mixed> 안내 노드가 삽입된 노드
     */
    private function insertTestModeNotice(array $node): array
    {
        if (($node['id'] ?? null) === 'tab_content_order_settings' && is_array($node['children'] ?? null)) {
            $noticeIndex = $this->findChildIndexById($node['children'], self::TEST_MODE_NOTICE_ID);

            if ($noticeIndex === null) {
                $insertAt = $this->findChildIndexById($node['children'], 'payment_methods_card') ?? count($node['children']);
                array_splice($node['children'], $insertAt, 0, [$this->testModeNoticeContainerNode()]);
                $noticeIndex = $insertAt;
            }

            if (is_array($node['children'][$noticeIndex] ?? null)) {
                $notice = $node['children'][$noticeIndex];
                $notice['if'] = $this->mergeTestModeCondition((string) ($notice['if'] ?? ''));
                $node['children'][$noticeIndex] = $this->appendTestModeNoticeRow($notice);
            }

            return $node;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->insertTestModeNotice($value);
            }
        }

        return $node;
    }

    /**
     * 자식 노드 목록에서 지정한 id 를 가진 노드의 인덱스를 찾습니다.
     *
     * @param  array<int, mixed>  $children  자식 노드 목록
     * @param  string  $id  찾을 노드 id
     * @return int|null 찾은 인덱스 (없으면 null)
     */
    private function findChildIndexById(array $children, string $id): ?int
    {
        foreach ($children as $index => $child) {
            if (is_array($child) && ($child['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * 자식 노드 목록에 지정한 id 를 가진 노드가 있는지 확인합니다.
     *
     * @param  array<int, mixed>  $children  자식 노드 목록
     * @param  string  $id  확인할 노드 id
     * @return bool 존재 여부
     */
    private function hasChildWithId(array $children, string $id): bool
    {
        return $this->findChildIndexById($children, $id) !== null;
    }

    /**
     * 기존 표시 조건에 테스트 모드 조건을 OR 로 병합합니다.
     *
     * @param  string  $condition  기존 표시 조건식
     * @return string 테스트 모드 조건이 병합된 조건식
     */
    private function mergeTestModeCondition(string $condition): string
    {
        if (str_contains($condition, self::TEST_MODE_CONDITION)) {
            return $condition !== '' ? $condition : '{{'.self::TEST_MODE_CONDITION.'}}';
        }

        $inner = trim($condition);
        if (str_starts_with($inner, '{{') && str_ends_with($inner, '}}')) {
            $inner = trim(substr($inner, 2, -2));
        }

        if ($inner === '') {
            return '{{'.self::TEST_MODE_CONDITION.'}}';
        }

        return '{{'.$inner.' || '.self::TEST_MODE_CONDITION.'}}';
    }

    /**
     * 안내 컨테이너에 이니시스 테스트 모드 안내 행을 중복 없이 추가합니다.
     *
     * @param  array<string, mixed>  $notice  안내 컨테이너 노드
     * @return array<string, mixed> 안내 행이 추가된 컨테이너 노드
     */
    private function appendTestModeNoticeRow(array $notice): array
    {
        $children = is_array($notice['children'] ?? null) ? $notice['children'] : [];

        if (! $this->hasChildWithId($children, self::TEST_MODE_NOTICE_ROW_ID)) {
            $children[] = $this->testModeNoticeRowNode();
        }

        $notice['children'] = $children;

        return $notice;
    }

    /**
     * 테스트 모드 안내를 감싸는 컨테이너 노드 정의를 반환합니다.
     *
     * @return array<string, mixed> 컨테이너 노드 정의
     */
    private function testModeNoticeContainerNode(): array
    {
        return [
            'id' => self::TEST_MODE_NOTICE_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => '{{'.self::TEST_MODE_CONDITION.'}}',
            'props' => [
                'className' => 'mb-4 space-y-3 rounded-lg border border-orange-200 bg-orange-50 p-4 dark:border-orange-700 dark:bg-orange-900/20',
            ],
            'children' => [
                $this->testModeNoticeRowNode(),
            ],
        ];
    }

    /**
     * 이니시스 테스트 모드 안내 문구와 설정 이동 버튼으로 구성된 행 노드 정의를 반환합니다.
     *
     * @return array<string, mixed> 안내 행 노드 정의
     */
    private function testModeNoticeRowNode(): array
    {
        return [
            'id' => self::TEST_MODE_NOTICE_ROW_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => '{{'.self::TEST_MODE_CONDITION.'}}',
            'props' => [
                'className' => 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between',
            ],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => [
                        'className' => 'min-w-0',
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Div',
                            'props' => [
                                'className' => 'text-sm font-medium text-orange-950 dark:text-orange-50',
                            ],
                            'text' => '$t:sirsoft-pay_kginicis.admin.test_mode_settings_warning_plugin',
                        ],
                        [
                            'type' => 'basic',
                            'name' => 'P',
                            'props' => [
                                'className' => 'mt-0.5 text-sm leading-6 text-orange-800 dark:text-orange-200',
                            ],
                            'text' => '$t:sirsoft-pay_kginicis.admin.test_mode_settings_warning_body',
                        ],
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [
                        'type' => 'button',
                        'className' => 'btn btn-outline btn-sm w-full flex-shrink-0 border-orange-300 text-orange-900 hover:bg-orange-100 sm:w-auto dark:border-orange-600 dark:text-orange-100 dark:hover:bg-orange-900/40',
                    ],
                    'actions' => [
                        [
                            'type' => 'click',
                            'handler' => 'navigate',
                            'params' => [
                                'path' => '/admin/plugins/sirsoft-pay_kginicis/settings',
                            ],
                        ],
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Span',
                            'text' => '$t:sirsoft-pay_kginicis.admin.test_mode_settings_warning_action',
                        ],
                    ],
                ],
            ],
        ];
    }
}
