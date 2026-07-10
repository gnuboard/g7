<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 이커머스 결제수단 설정 화면에서 KG 이니시스 간편결제를 PG 선택 불필요 항목으로 표시한다.
 */
class AdjustEcommercePaymentMethodsLayoutListener implements HookListenerInterface
{
    private const TARGET_LAYOUT = 'admin_ecommerce_settings';

    private const TEST_MODE_DATA_SOURCE_ID = 'kginicis_test_mode_status';

    private const TEST_MODE_NOTICE_ID = 'payment_test_mode_order_settings_notice';

    private const TEST_MODE_NOTICE_ROW_ID = 'kginicis_test_mode_order_settings_notice';

    private const TEST_MODE_CONDITION = 'kginicis_test_mode_status.data?.is_test_mode === true';

    private const CORE_NO_PG_METHODS = "['point','deposit','free','dbank']";

    private const KGINICIS_NO_PG_METHODS = "['point','deposit','free','dbank','kginicis_samsung_pay','kginicis_naverpay','kginicis_lpay','kginicis_kakaopay','kginicis_japan_paypay','kginicis_japan_cvs']";

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'markEasyPayMethodsAsPgNotRequired',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param mixed ...$args
     */
    public function handle(...$args): void {}

    /**
     * @param array<string, mixed> $layout
     * @param int $templateId
     * @return array<string, mixed>
     */
    public function markEasyPayMethodsAsPgNotRequired(array $layout, int $templateId): array
    {
        if (($layout['layout_name'] ?? '') !== self::TARGET_LAYOUT) {
            return $layout;
        }

        $layout = $this->replaceNoPgMethodExpressions($layout);

        return $this->ensureTestModeWarning($layout);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function replaceNoPgMethodExpressions(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->replaceNoPgMethodExpressions($value);
                continue;
            }

            if (is_string($value) && str_contains($value, self::CORE_NO_PG_METHODS)) {
                $node[$key] = str_replace(
                    self::CORE_NO_PG_METHODS,
                    self::KGINICIS_NO_PG_METHODS,
                    $value
                );
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function ensureTestModeWarning(array $layout): array
    {
        $layout = $this->ensureTestModeDataSource($layout);

        return $this->insertTestModeNotice($layout);
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
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
            'auto_fetch' => true,
            'auth_required' => true,
        ];

        $layout['data_sources'] = $dataSources;

        return $layout;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
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
     * @param array<int, mixed> $children
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
     * @param array<int, mixed> $children
     */
    private function hasChildWithId(array $children, string $id): bool
    {
        return $this->findChildIndexById($children, $id) !== null;
    }

    private function mergeTestModeCondition(string $condition): string
    {
        if (str_contains($condition, self::TEST_MODE_CONDITION)) {
            return $condition !== '' ? $condition : '{{' . self::TEST_MODE_CONDITION . '}}';
        }

        $inner = trim($condition);
        if (str_starts_with($inner, '{{') && str_ends_with($inner, '}}')) {
            $inner = trim(substr($inner, 2, -2));
        }

        if ($inner === '') {
            return '{{' . self::TEST_MODE_CONDITION . '}}';
        }

        return '{{' . $inner . ' || ' . self::TEST_MODE_CONDITION . '}}';
    }

    /**
     * @param array<string, mixed> $notice
     * @return array<string, mixed>
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
     * @return array<string, mixed>
     */
    private function testModeNoticeContainerNode(): array
    {
        return [
            'id' => self::TEST_MODE_NOTICE_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => '{{' . self::TEST_MODE_CONDITION . '}}',
            'props' => [
                'className' => 'mb-4 space-y-3 rounded-lg border border-orange-200 bg-orange-50 p-4 dark:border-orange-700 dark:bg-orange-900/20',
            ],
            'children' => [
                $this->testModeNoticeRowNode(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function testModeNoticeRowNode(): array
    {
        return [
            'id' => self::TEST_MODE_NOTICE_ROW_ID,
            'type' => 'basic',
            'name' => 'Div',
            'if' => '{{' . self::TEST_MODE_CONDITION . '}}',
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
