<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class EnsureAdminOrderListTestBadgeLayoutListener implements HookListenerInterface
{
    private const TARGET_LAYOUT = 'admin_ecommerce_order_list';

    private const ORDER_GRID_ID = 'order_datagrid';

    private const DATA_SOURCE_ID = 'kginicis_test_map';

    private const BADGE_CONDITION = 'kginicis_test_map.data?.[row.order_number] === true';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'ensureTestBadgeLayout',
                'type' => 'filter',
                'priority' => 60,
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
    public function ensureTestBadgeLayout(array $layout, int $templateId): array
    {
        if (($layout['layout_name'] ?? '') !== self::TARGET_LAYOUT) {
            return $layout;
        }

        $layout = $this->ensureDataSource($layout);

        if (isset($layout['components']) && is_array($layout['components'])) {
            $this->ensureOrderGridBadge($layout['components']);
        }

        return $layout;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function ensureDataSource(array $layout): array
    {
        $dataSources = isset($layout['data_sources']) && is_array($layout['data_sources'])
            ? $layout['data_sources']
            : [];

        foreach ($dataSources as $dataSource) {
            if (is_array($dataSource) && ($dataSource['id'] ?? null) === self::DATA_SOURCE_ID) {
                $layout['data_sources'] = $dataSources;

                return $layout;
            }
        }

        $dataSources[] = [
            'id' => self::DATA_SOURCE_ID,
            'label_key' => '$t:sirsoft-pay_kginicis.editor.data_source.kginicis_test_map',
            'type' => 'api',
            'endpoint' => '/api/plugins/sirsoft-pay_kginicis/admin/orders/test-mode-map',
            'method' => 'GET',
            'auto_fetch' => true,
            'auth_required' => true,
            'loading_strategy' => 'progressive',
        ];

        $layout['data_sources'] = $dataSources;

        return $layout;
    }

    /**
     * @param array<int, mixed> $components
     */
    private function ensureOrderGridBadge(array &$components): bool
    {
        foreach ($components as &$component) {
            if (! is_array($component)) {
                continue;
            }

            if (($component['id'] ?? '') === self::ORDER_GRID_ID) {
                $this->ensurePaymentMethodColumnBadge($component);

                return true;
            }

            if (isset($component['children']) && is_array($component['children'])) {
                if ($this->ensureOrderGridBadge($component['children'])) {
                    return true;
                }
            }
        }
        unset($component);

        return false;
    }

    /**
     * @param array<string, mixed> $grid
     */
    private function ensurePaymentMethodColumnBadge(array &$grid): void
    {
        if (! isset($grid['props']['columns']) || ! is_array($grid['props']['columns'])) {
            return;
        }

        foreach ($grid['props']['columns'] as &$column) {
            if (! is_array($column) || ($column['field'] ?? null) !== 'payment_method') {
                continue;
            }

            $this->appendBadgeToColumn($column);

            return;
        }
        unset($column);
    }

    /**
     * @param array<string, mixed> $column
     */
    private function appendBadgeToColumn(array &$column): void
    {
        if ($this->containsKginicisBadgeCondition($column)) {
            return;
        }

        if (! isset($column['cellChildren']) || ! is_array($column['cellChildren'])) {
            $column['cellChildren'] = [];
        }

        foreach ($column['cellChildren'] as &$cellChild) {
            if (
                is_array($cellChild)
                && ($cellChild['name'] ?? null) === 'Div'
                && isset($cellChild['children'])
                && is_array($cellChild['children'])
            ) {
                $cellChild['children'][] = $this->badgeComponent();

                return;
            }
        }
        unset($cellChild);

        $column['cellChildren'][] = $this->badgeComponent();
    }

    /**
     * @param array<string, mixed> $node
     */
    private function containsKginicisBadgeCondition(array $node): bool
    {
        foreach ($node as $value) {
            if (is_array($value) && $this->containsKginicisBadgeCondition($value)) {
                return true;
            }

            if (is_string($value) && str_contains($value, self::BADGE_CONDITION)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function badgeComponent(): array
    {
        return [
            'type' => 'basic',
            'name' => 'Span',
            'if' => '{{'.self::BADGE_CONDITION.'}}',
            'props' => [
                'className' => 'text-xs font-medium text-orange-500 dark:text-orange-400 leading-tight',
            ],
            'text' => '($t:sirsoft-pay_kginicis.admin.test_mode_badge)',
        ];
    }
}
