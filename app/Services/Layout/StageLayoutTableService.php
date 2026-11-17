<?php

namespace App\Services\Layout;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\StageLayoutTableRepositoryInterface;
use App\Services\LayoutFileService;
use App\Services\LayoutResolverService;
use App\Services\LayoutService;
use App\Support\RuntimeRole;

/**
 * 검수 서버(stage)용 레이아웃 테이블 관리 — 운영 행 복사(resetFromLive)와 템플릿 제거 시 다른 역할 행 정리.
 *
 * 레이아웃 모델은 역할별 테이블을 자동으로 쓰지만, 한 역할의 코드가 다른 역할의 테이블을 다뤄야 하는 두 곳이 있다. 이 서비스가
 * 그 두 곳만 맡는다(그 밖의 코드는 `LayoutTable::name()`·모델을 쓴다). 질의는 저장소가 한다.
 *
 * @since 7.2.0
 */
class StageLayoutTableService
{
    /**
     * @param  StageLayoutTableRepositoryInterface  $tables  스테이지 테이블 저장소
     * @param  LayoutService  $layouts  레이아웃 서비스(병합본 캐시 무효화)
     * @param  LayoutResolverService  $resolver  레이아웃 해석기(해석 캐시 무효화)
     * @param  CacheInterface  $cache  코어 캐시(역변환 마크업 캐시 태그 비우기)
     */
    public function __construct(
        private readonly StageLayoutTableRepositoryInterface $tables,
        private readonly LayoutService $layouts,
        private readonly LayoutResolverService $resolver,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 스테이지 테이블이 모두 있는지.
     *
     * @return bool 있으면 true
     */
    public function available(): bool
    {
        return $this->tables->available();
    }

    /**
     * 운영 레이아웃·조각을 스테이지 테이블에 ID 그대로 복사합니다 — 운영 테이블에는 쓰지 않는다.
     *
     * 복사는 행을 한꺼번에 넣어 모델 저장 이벤트(레이아웃 캐시 무효화)를 거치지 않는다. 그래서 복사 전·후 스테이지 레이아웃
     * 전부의 병합본 캐시를 직접 버린다 — 버전 없는 병합본 캐시는 캐시 버전을 올려도 남아, 검수 서버가 캐시 수명 동안 옛 화면을 보였다.
     * 이 서비스는 검수 서버에서만 불리므로 버리는 캐시도 검수 서버 범위(코드 범위 `g7:stage:`)다.
     *
     * 함께 버리는 검수 서버 범위 캐시:
     * - 레이아웃 해석 캐시(`layout_resolver.{템플릿}.{이름}`) — 값이 행 ID 라 지운 검수 서버 전용 행(ID 10억대)을 가리키면 캐시 수명
     *   동안 그 화면 레이아웃이 사라진다. 병합본 무효화는 모듈 형식 이름만 해석 캐시를 지우므로 이름 형식과 무관하게 직접 지운다.
     * - 역변환 마크업 캐시(`layout:markup:{ID}:{lock_version}`, 조각은 `layout:markup:ext:…`) — 복사가 ID·잠금 판을 그대로 옮겨
     *   검수 서버 편집본과 같은 키가 될 수 있다. 검수 서버 역할 태그({@see LayoutFileService::markupCacheTag()})로 한꺼번에 지운다.
     *
     * @return array{layouts: int, extensions: int, checksum_match: bool} 복사 결과
     */
    public function resetFromLive(): array
    {
        $before = $this->tables->stageLayoutKeys();
        $result = $this->tables->resetFromLive();

        $seen = [];
        foreach (array_merge($before, $this->tables->stageLayoutKeys()) as $key) {
            $id = $key['template_id'].'|'.$key['name'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $this->layouts->clearLayoutCache($key['template_id'], $key['name']);
            $this->resolver->clearResolutionCache($key['name'], $key['template_id']);
        }

        $this->cache->flushTags([LayoutFileService::markupCacheTag(RuntimeRole::STAGE)]);

        return $result;
    }

    /**
     * 운영 레이아웃으로 덮어쓰면 사라질 검수 서버 편집본 수 — 명령이 복사 전에 알린다.
     *
     * @return array{layouts: int, extensions: int, versions: int} 지워질 건수
     */
    public function stageDivergence(): array
    {
        return $this->tables->stageDivergence();
    }

    /**
     * 운영·스테이지 테이블의 본문 체크섬.
     *
     * @param  string  $base  원 테이블
     * @param  bool  $stage  스테이지 테이블이면 true
     * @return string 체크섬
     */
    public function checksum(string $base, bool $stage): string
    {
        return $this->tables->checksum($base, $stage);
    }

    /**
     * 템플릿을 제거할 때 다른 역할 테이블의 그 템플릿 행을 명시적으로 지웁니다(DB CASCADE 비의존).
     *
     * @param  int  $templateId  템플릿 ID
     * @return int 지운 레이아웃·조각 수
     */
    public function deleteOtherRoleRowsForTemplate(int $templateId): int
    {
        return $this->tables->deleteOtherRoleRowsForTemplate($templateId);
    }
}
