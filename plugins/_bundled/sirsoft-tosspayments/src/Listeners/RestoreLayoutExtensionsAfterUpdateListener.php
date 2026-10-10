<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Services\LayoutExtensionService;

/**
 * 플러그인 업데이트 후 레이아웃 확장(오버레이) 복원 리스너
 *
 * 업데이트 과정에서 오버레이 주입이 소실되면 주문상세의 영수증 링크·가상계좌 정보가
 * 조용히 사라진다. 업데이트 완료 훅에서 resources/extensions 의 확장점 정의를 다시 등록한다
 * (소스 `.g7.html` 우선 — 짝 판별은 코어가 한다).
 */
class RestoreLayoutExtensionsAfterUpdateListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * 구독할 훅 매핑 반환
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
                // 인프라 부수효과 — 업데이트 트랜잭션 안에서 동기 실행되어야 한다.
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 업데이트된 플러그인이 자신이면 오버레이 확장을 재등록합니다.
     *
     * @param  string  $identifier  업데이트된 플러그인 식별자
     * @param  array<string, mixed>  $result  업데이트 결과 (success=false 면 바뀐 것이 없다)
     * @return void
     */
    public function restoreCurrentExtensionsAfterUpdate(string $identifier, array $result = []): void
    {
        if ($identifier !== self::PLUGIN_IDENTIFIER || ($result['success'] ?? true) === false) {
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
     * resources/extensions 의 오버레이 확장 정의를 모두 읽습니다.
     *
     * 수집·짝 판별·읽기는 코어가 맡는다 ({@see LayoutFragmentSourceHelper::readDirectoryKeyed()}) —
     * 여기서 `glob('*.json')` 하면 소스(`.g7.html`)이 통째로 보이지 않아, 소스만 고친 확장점은
     * 업데이트 복원에서 조용히 빠진다.
     *
     * 조각 식별자(`fragment_key`)까지 함께 받는다. 같은 대상에 조각이 둘이면 키 없는 등록은 어느
     * 행인지 가릴 수 없어 아무것도 하지 않으므로, 파일에서 바로 얻은 키를 그대로 넘긴다.
     *
     * @return array<int, array{path: string, data: array<string, mixed>, key: string}> 확장 정의 목록
     */
    private function currentOverlayExtensions(): array
    {
        return array_values(array_filter(
            LayoutFragmentSourceHelper::readDirectoryKeyed(dirname(__DIR__, 2).'/resources/extensions'),
            static fn (array $fragment): bool => isset($fragment['data']['target_layout'])
        ));
    }

    /**
     * 논리 레이아웃명을 가진 템플릿 ID 목록을 반환합니다.
     *
     * @param  string  $layoutName  논리 레이아웃명
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
     * 저장된 레이아웃명이 논리 레이아웃명과 일치하는지 확인합니다.
     *
     * @param  string  $storedName  저장된 레이아웃명
     * @param  string  $layoutName  논리 레이아웃명
     * @return bool 일치 여부
     */
    private function matchesLogicalLayout(string $storedName, string $layoutName): bool
    {
        return $storedName === $layoutName || str_ends_with($storedName, '.'.$layoutName);
    }
}
