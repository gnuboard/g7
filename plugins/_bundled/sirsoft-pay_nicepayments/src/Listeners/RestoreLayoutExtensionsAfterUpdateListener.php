<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Services\LayoutExtensionService;

class RestoreLayoutExtensionsAfterUpdateListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugins.updated' => [
                'method' => 'restoreCurrentExtensionsAfterUpdate',
                'type' => 'action',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 — 이 리스너는 구독마다 전용 메서드를 쓰므로 사용하지 않습니다.
     *
     * @param  mixed  ...$args  훅 페이로드 (미사용)
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 플러그인 업데이트 직후 이 플러그인이 소유한 overlay 확장을 다시 등록합니다.
     *
     * @param  string  $identifier  업데이트된 확장 식별자 (자기 것이 아니면 아무것도 하지 않는다)
     * @return void
     */
    public function restoreCurrentExtensionsAfterUpdate(string $identifier): void
    {
        if ($identifier !== self::PLUGIN_IDENTIFIER) {
            return;
        }

        $service = app(LayoutExtensionService::class);

        foreach ($this->currentOverlayExtensions() as $fragment) {
            $extensionData = $fragment['data'];
            $targetLayout = $extensionData['target_layout'] ?? null;
            if (! is_string($targetLayout) || $targetLayout === '') {
                continue;
            }

            foreach ($this->templateIdsForLogicalLayout($targetLayout) as $templateId) {
                $result = $service->registerExtension(
                    $extensionData,
                    LayoutSourceType::Plugin,
                    self::PLUGIN_IDENTIFIER,
                    $templateId,
                    true,
                    $fragment['key']
                );

                if ($result !== null) {
                    $service->invalidateExtensionCache($templateId, $targetLayout, LayoutExtensionType::Overlay);
                }
            }
        }
    }

    /**
     * 현재 플러그인이 소유한 overlay 확장 정의를 읽습니다.
     *
     * 수집·짝 판별·읽기는 코어가 맡는다 ({@see LayoutFragmentSourceHelper::readDirectoryKeyed()}) —
     * 여기서 `glob('*.json')` 하면 소스(`.g7.html`)이 통째로 보이지 않아, 소스만 고친 확장점은
     * 업데이트 복원에서 조용히 빠진다.
     *
     * 조각 식별자(`fragment_key`)까지 함께 받는다. 같은 대상에 조각이 둘이면 키 없는 등록은 어느
     * 행인지 가릴 수 없어 아무것도 하지 않으므로, 파일에서 바로 얻은 키를 그대로 넘긴다.
     *
     * @return array<int, array{path: string, data: array<string, mixed>, key: string}>
     */
    private function currentOverlayExtensions(): array
    {
        return array_values(array_filter(
            LayoutFragmentSourceHelper::readDirectoryKeyed(dirname(__DIR__, 2).'/resources/extensions'),
            static fn (array $fragment): bool => isset($fragment['data']['target_layout'])
        ));
    }

    /**
     * @param  string  $layoutName
     * @return array<int, int>
     */
    private function templateIdsForLogicalLayout(string $layoutName): array
    {
        $templateRepository = app(TemplateRepositoryInterface::class);
        $layoutRepository = app(LayoutRepositoryInterface::class);
        $templateIds = [];

        foreach ($templateRepository->getAll() as $template) {
            $layoutNames = $layoutRepository->getLayoutNamesByTemplateId((int) $template->id);

            foreach ($layoutNames as $storedName) {
                if (! is_string($storedName) || ! $this->matchesLogicalLayout($storedName, $layoutName)) {
                    continue;
                }

                $templateIds[(int) $template->id] = (int) $template->id;

                break;
            }
        }

        return array_values($templateIds);
    }

    /**
     * 저장된 레이아웃 이름이 이 논리 레이아웃을 가리키는지 판정합니다.
     *
     * 설치된 행의 이름은 소유 확장이 붙으면 `sirsoft-ecommerce.admin_order_detail` 처럼
     * 접두사를 갖는다. 조각은 접두사 없는 논리 이름(`admin_order_detail`)으로 대상을 적으므로
     * 정확 일치와 `.` 접미 일치를 모두 본다 — 접미만 보면 `x_admin_order_detail` 같은 다른
     * 레이아웃까지 걸린다.
     *
     * @param  string  $storedName  DB 행의 레이아웃 이름 (확장 접두사가 붙어 있을 수 있다)
     * @param  string  $layoutName  조각이 겨냥한 논리 레이아웃 이름
     * @return bool 같은 레이아웃이면 true
     */
    private function matchesLogicalLayout(string $storedName, string $layoutName): bool
    {
        return $storedName === $layoutName || str_ends_with($storedName, '.'.$layoutName);
    }
}
