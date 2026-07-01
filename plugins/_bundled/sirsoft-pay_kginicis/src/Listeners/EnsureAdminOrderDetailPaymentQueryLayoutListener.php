<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class EnsureAdminOrderDetailPaymentQueryLayoutListener implements HookListenerInterface
{
    private const TARGET_LAYOUT = 'admin_ecommerce_order_detail';

    private const TARGET_IDS = [
        'tab_content_area',
        'order_detail_wrapper',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'ensurePaymentQueryLayout',
                'type' => 'filter',
                'priority' => 66,
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
    public function ensurePaymentQueryLayout(array $layout, int $templateId): array
    {
        if (($layout['layout_name'] ?? '') !== self::TARGET_LAYOUT) {
            return $layout;
        }

        $extension = $this->orderPaymentQueryExtension();

        $layout = $this->ensureDataSources($layout, $extension['data_sources'] ?? []);
        $this->ensurePaymentPanels($layout, $extension['injections'] ?? []);

        return $layout;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<int, mixed> $extensionDataSources
     * @return array<string, mixed>
     */
    private function ensureDataSources(array $layout, array $extensionDataSources): array
    {
        $dataSources = isset($layout['data_sources']) && is_array($layout['data_sources'])
            ? $layout['data_sources']
            : [];
        $existingIds = [];

        foreach ($dataSources as $dataSource) {
            if (is_array($dataSource) && is_string($dataSource['id'] ?? null)) {
                $existingIds[$dataSource['id']] = true;
            }
        }

        foreach ($extensionDataSources as $dataSource) {
            if (! is_array($dataSource) || ! is_string($dataSource['id'] ?? null)) {
                continue;
            }

            if (isset($existingIds[$dataSource['id']])) {
                continue;
            }

            $dataSources[] = $dataSource;
            $existingIds[$dataSource['id']] = true;
        }

        $layout['data_sources'] = $dataSources;

        return $layout;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<int, mixed> $injections
     */
    private function ensurePaymentPanels(array &$layout, array $injections): void
    {
        foreach ($injections as $injection) {
            if (
                ! is_array($injection)
                || ($injection['target_id'] ?? null) !== 'tab_content_area'
                || ! isset($injection['components'])
                || ! is_array($injection['components'])
            ) {
                continue;
            }

            if ($this->appendComponentsToFirstAvailableTarget($layout, self::TARGET_IDS, $injection['components'])) {
                return;
            }

            if (! isset($layout['slots']['content']) || ! is_array($layout['slots']['content'])) {
                $layout['slots']['content'] = [];
            }

            $this->appendMissingComponents($layout['slots']['content'], $injection['components']);

            return;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $targetIds
     * @param array<int, mixed> $components
     */
    private function appendComponentsToFirstAvailableTarget(array &$node, array $targetIds, array $components): bool
    {
        foreach ($targetIds as $targetId) {
            if ($this->appendComponentsToTargetId($node, $targetId, $components)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed> $components
     */
    private function appendComponentsToTargetId(array &$node, string $targetId, array $components): bool
    {
        if (($node['id'] ?? null) === $targetId) {
            if (! isset($node['children']) || ! is_array($node['children'])) {
                $node['children'] = [];
            }

            $this->appendMissingComponents($node['children'], $components);

            return true;
        }

        foreach ($node as &$value) {
            if (is_array($value) && $this->appendComponentsToTargetId($value, $targetId, $components)) {
                return true;
            }
        }
        unset($value);

        return false;
    }

    /**
     * @param array<int, mixed> $target
     * @param array<int, mixed> $components
     */
    private function appendMissingComponents(array &$target, array $components): void
    {
        foreach ($components as $component) {
            if (! is_array($component) || $this->containsComponent($target, $component)) {
                continue;
            }

            $target[] = $component;
        }
    }

    /**
     * @param array<string|int, mixed> $node
     * @param array<string, mixed> $component
     */
    private function containsComponent(array $node, array $component): bool
    {
        if (is_string($component['id'] ?? null) && $this->containsNodeId($node, $component['id'])) {
            return true;
        }

        foreach ($this->componentSignatures($component) as $signature) {
            if ($this->containsString($node, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $component
     * @return array<int, string>
     */
    private function componentSignatures(array $component): array
    {
        $json = json_encode($component, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            return [];
        }

        $signatures = [];

        foreach ([
            'sirsoft-pay_kginicis.admin.order_verify_button',
            'sirsoft-pay_kginicis.admin.escrow_delivery_title',
            'sirsoft-pay_kginicis.admin.escrow_deny_confirm_title',
            'sirsoft-pay_kginicis.admin.cash_receipt_title',
        ] as $candidate) {
            if (str_contains($json, $candidate)) {
                $signatures[] = $candidate;
            }
        }

        return $signatures;
    }

    /**
     * @param array<string|int, mixed> $node
     */
    private function containsNodeId(array $node, string $id): bool
    {
        if (($node['id'] ?? null) === $id) {
            return true;
        }

        foreach ($node as $value) {
            if (is_array($value) && $this->containsNodeId($value, $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string|int, mixed> $node
     */
    private function containsString(array $node, string $needle): bool
    {
        foreach ($node as $value) {
            if (is_array($value) && $this->containsString($value, $needle)) {
                return true;
            }

            if (is_string($value) && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPaymentQueryExtension(): array
    {
        $path = dirname(__DIR__, 2).'/resources/extensions/admin_order_payment_query.json';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
