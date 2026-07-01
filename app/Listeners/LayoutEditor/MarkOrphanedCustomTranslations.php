<?php

namespace App\Listeners\LayoutEditor;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\TemplateCustomTranslationRepositoryInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Services\LanguagePack\CustomTranslationUsageScanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `core.layout.after_update` 리스너 — 좀비(고아) 커스텀 다국어 키 자동 표시.
 *
 * 레이아웃 저장 후, 저장된 content 를 스캔해 실제로 참조되는 `$t:custom.*` 키
 * 집합을 얻고, 같은 (template_id, layout_name) 의 커스텀 키 중 더 이상 참조되지
 * 않는 것을 `status='orphaned'` 로 전이합니다. 반대로 참조가 되살아난(텍스트를
 * 되돌린) orphaned 키는 `active` 로 복원합니다.
 *
 * 코어 본문(LayoutService::updateLayout)은 손대지 않고 이미 존재하는 after_update
 * 액션 훅에 결선합니다. 데이터 접근은 Repository 위임이며(리스너 직접 DB 금지 —
 * listener-direct-db-facade), 상태 전이 시 다국어 캐시 버전을 무효화해 다음
 * serveLanguage 가 orphaned 제외 병합(MergeCustomTranslations)을 재계산하게 합니다.
 *
 * 동기 실행(`sync => true`) — 저장 직후 같은 요청에서 좀비 상태가 확정되어야
 * 후속 조회/관리 모달이 일관된 상태를 보게 합니다.
 *
 * @since engine-v1.54.0
 */
class MarkOrphanedCustomTranslations implements HookListenerInterface
{
    /**
     * @param  TemplateCustomTranslationRepositoryInterface  $repository  커스텀 키 리포지토리
     * @param  CustomTranslationUsageScanner  $scanner  키 사용 스캐너 (순수)
     */
    public function __construct(
        private readonly TemplateCustomTranslationRepositoryInterface $repository,
        private readonly CustomTranslationUsageScanner $scanner,
    ) {}

    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout.after_update' => [
                'method' => 'handleLayoutAfterUpdate',
                'priority' => 30,
                // 저장 직후 같은 요청에서 좀비 상태 확정 — 큐 비동기 금지.
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (HookListenerInterface 필수 — 개별 메서드 사용).
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        // 개별 메서드(handleLayoutAfterUpdate)에서 처리.
    }

    /**
     * 레이아웃 저장 후 좀비 키를 표시/복원합니다.
     *
     * @param  Model  $layout  업데이트된 레이아웃 모델 (content 보유)
     * @param  int  $templateId  템플릿 ID
     * @param  string  $name  레이아웃 이름 (원본, 예: board/list)
     * @param  array<string, mixed>  $data  업데이트 데이터
     */
    public function handleLayoutAfterUpdate(Model $layout, int $templateId, string $name, array $data): void
    {
        // 무손실 디그레이드: 좀비 표시는 부가 정리 단계다. 실패가 레이아웃 저장
        // 트랜잭션 흐름을 깨면 안 되므로 예외를 흡수한다(after_update 는 저장 완료 후).
        try {
            $rows = $this->repository->getByTemplateIdAndLayout($templateId, $name);

            if ($rows->isEmpty()) {
                return;
            }

            $referenced = array_flip($this->scanner->collectReferencedKeys($layout->content ?? []));

            $toOrphan = [];
            $toActivate = [];

            foreach ($rows as $row) {
                $isReferenced = isset($referenced[$row->translation_key]);
                $status = (string) $row->status;

                if (! $isReferenced && $status === 'active') {
                    $toOrphan[] = (int) $row->id;
                } elseif ($isReferenced && $status === 'orphaned') {
                    $toActivate[] = (int) $row->id;
                }
            }

            $changed = 0;
            if ($toOrphan !== []) {
                $changed += $this->repository->updateStatus($toOrphan, 'orphaned');
            }
            if ($toActivate !== []) {
                $changed += $this->repository->updateStatus($toActivate, 'active');
            }

            if ($changed > 0) {
                $this->invalidateLanguageCache();
            }
        } catch (Throwable $e) {
            Log::warning('MarkOrphanedCustomTranslations: 좀비 키 표시 실패 — 디그레이드', [
                'template_id' => $templateId,
                'layout' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 다국어 캐시 버전을 무효화합니다.
     *
     * `ext.cache_version` 은 코어 소유 키이므로 컨테이너 바인딩에 의존하지 않고
     * 항상 CoreCacheDriver(`g7:core:` 네임스페이스)를 직접 생성합니다
     * (ClearsTemplateCaches 트레이트와 동일 SSoT).
     */
    private function invalidateLanguageCache(): void
    {
        (new CoreCacheDriver(config('cache.default', 'array')))->put('ext.cache_version', time());
    }
}
