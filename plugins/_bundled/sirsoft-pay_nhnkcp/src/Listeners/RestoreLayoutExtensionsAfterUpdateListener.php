<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Services\LayoutExtensionService;

class RestoreLayoutExtensionsAfterUpdateListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    /**
     * 구독할 훅 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 정규 완료 훅 — 옛 core.plugins.updated 는 deprecated(#138 후속). 업데이트할 것이 없을 때도
            // 발화하므로(success=false) 그때는 건너뛴다.
            'core.plugins.after_update' => [
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
     * 플러그인 업데이트 직후 이 플러그인이 소유한 레이아웃 확장 조각(overlay·확장점)을 다시 등록합니다.
     *
     * 확장점 조각은 그 확장점을 가진 레이아웃이 있는 템플릿에만 등록한다(관리자 주문 목록의 결제수단 표시
     * 자리처럼 호스트 레이아웃이 확장점을 열어 둔 경우). overlay 는 대상 레이아웃이 있는 템플릿에 등록한다.
     *
     * @param  string  $identifier  업데이트된 확장 식별자 (자기 것이 아니면 아무것도 하지 않는다)
     * @param  array<string, mixed>  $result  업데이트 결과 (success=false 면 바뀐 것이 없다)
     * @return void
     */
    public function restoreCurrentExtensionsAfterUpdate(string $identifier, array $result = []): void
    {
        if ($identifier !== self::PLUGIN_IDENTIFIER || ($result['success'] ?? true) === false) {
            return;
        }

        $service = app(LayoutExtensionService::class);

        foreach ($this->currentExtensionFragments() as $fragment) {
            $extensionData = $fragment['data'];
            $targetLayout = $extensionData['target_layout'] ?? null;
            $extensionPoint = $extensionData['extension_point'] ?? null;

            if (is_string($targetLayout) && $targetLayout !== '') {
                $targetName = $targetLayout;
                $extensionType = LayoutExtensionType::Overlay;
                $templateIds = $this->templateIdsForLogicalLayout($targetLayout);
            } elseif (is_string($extensionPoint) && $extensionPoint !== '') {
                $targetName = $extensionPoint;
                $extensionType = LayoutExtensionType::ExtensionPoint;
                $templateIds = $this->templateIdsWithExtensionPoint($extensionPoint);
            } else {
                continue;
            }

            foreach ($templateIds as $templateId) {
                $result = $service->registerExtension(
                    $extensionData,
                    LayoutSourceType::Plugin,
                    self::PLUGIN_IDENTIFIER,
                    $templateId,
                    true,
                    $fragment['key']
                );

                if ($result !== null) {
                    $service->invalidateExtensionCache($templateId, $targetName, $extensionType);
                }
            }
        }
    }

    /**
     * 현재 플러그인이 소유한 레이아웃 확장 조각(overlay·확장점) 정의를 읽습니다.
     *
     * 수집·짝 판별·읽기는 코어가 맡는다 ({@see LayoutFragmentSourceHelper::readDirectoryKeyed()}) —
     * 여기서 `glob('*.json')` 하면 소스(`.g7.html`)이 통째로 보이지 않아, 소스만 고친 확장점은
     * 업데이트 복원에서 조용히 빠진다.
     *
     * 조각 식별자(`fragment_key`)까지 함께 받는다. 같은 대상에 조각이 둘이면 키 없는 등록은 어느
     * 행인지 가릴 수 없어 아무것도 하지 않으므로, 파일에서 바로 얻은 키를 그대로 넘긴다.
     *
     * @return array<int, array{path: string, data: array<string, mixed>, key: string}> 조각 정의 목록
     */
    private function currentExtensionFragments(): array
    {
        return array_values(array_filter(
            LayoutFragmentSourceHelper::readDirectoryKeyed(dirname(__DIR__, 2).'/resources/extensions'),
            static fn (array $fragment): bool => isset($fragment['data']['target_layout'])
                || isset($fragment['data']['extension_point'])
        ));
    }

    /**
     * 지정 확장점을 가진 레이아웃이 있는 템플릿 ID 목록을 반환합니다.
     *
     * @param  string  $extensionPoint  확장점 이름
     * @return array<int, int> 템플릿 ID 목록
     */
    private function templateIdsWithExtensionPoint(string $extensionPoint): array
    {
        $templateRepository = app(TemplateRepositoryInterface::class);
        $layoutRepository = app(LayoutRepositoryInterface::class);
        $templateIds = [];

        foreach ($templateRepository->getAll() as $template) {
            if ($layoutRepository->hasExtensionPoint((int) $template->id, $extensionPoint)) {
                $templateIds[] = (int) $template->id;
            }
        }

        return $templateIds;
    }

    /**
     * 논리 레이아웃 이름을 가진 레이아웃이 있는 템플릿 ID 목록을 반환합니다.
     *
     * @param  string  $layoutName  조각이 겨냥한 논리 레이아웃 이름 (확장 접두사 없음)
     * @return array<int, int> 템플릿 ID 목록
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
