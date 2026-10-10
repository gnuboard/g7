<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 관리자 반응 목록 컬렉션
 *
 * 스크랩 관리 컬렉션과 같은 모양이되, 유형 필터 셀렉트가 쓸 유형 메타를 함께 싣는다 —
 * 반응 조회 권한만 가진 매니저는 환경설정(settings.read)을 볼 수 없어 유형 API 를
 * 부를 수 없으므로, 이 목록 응답이 유형 옵션의 유일한 출처다.
 *
 * 페이지네이션 메타는 BaseApiCollection::paginationMeta() 를 그대로 쓴다. 관리자 목록은
 * 지연 조인 + resultCap 경로라 결과가 BoundedPage 일 수 있고, 그 형태의 total_relation·
 * result_cap 까지 실어내는 규칙이 부모에 한 벌만 있어야 한다.
 */
class AdminReactionCollection extends BaseApiCollection
{
    use ChecksBoardPermission;

    /**
     * 리소스 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return array_merge([
            'data' => $this->collection->map(fn ($reaction) => new AdminReactionResource($reaction)),
        ], $this->paginationMeta(), [
            'reaction_types' => $this->reactionTypeOptions(),
            'boards' => $this->boardOptions(),
        ]);
    }

    /**
     * 권한 정보가 포함된 형태의 배열을 반환합니다.
     *
     * 화면이 일괄 삭제 버튼·행 액션·체크박스 노출을 이 값으로 판정한다 — 행마다의
     * abilities.can_delete 와 같은 근거이되, 선택이 0건이거나 목록이 비었을 때도
     * 버튼 상태를 정할 수 있다.
     *
     * @return array<string, mixed> 권한 정보가 포함된 반응 컬렉션
     */
    public function withPermissions(): array
    {
        return array_merge([
            'data' => $this->collection->map(fn ($reaction) => new AdminReactionResource($reaction)),
        ], $this->paginationMeta(), [
            'reaction_types' => $this->reactionTypeOptions(),
            'boards' => $this->boardOptions(),
            'abilities' => [
                'can_view' => $this->checkModulePermission('reactions', 'view'),
                'can_manage' => $this->checkModulePermission('reactions', 'manage'),
            ],
        ]);
    }

    /**
     * 게시판 필터 셀렉트용 옵션을 만듭니다 (비활성 게시판 포함, 최근 생성 순).
     *
     * 게시판 목록 API 는 게시판 관리 권한이 있어야 열리므로, 반응 조회 권한만 가진 매니저도
     * 게시판으로 좁힐 수 있게 유형 옵션과 같은 자리(목록 응답 메타)로 싣는다.
     *
     * @return array<int, array<string, mixed>> 게시판 옵션
     */
    private function boardOptions(): array
    {
        return app(BoardRepositoryInterface::class)->all()
            ->sortByDesc('id')
            ->map(fn ($board) => [
                'id' => (int) $board->id,
                'slug' => $board->slug,
                'name' => $board->getLocalizedName(),
            ])
            ->values()
            ->all();
    }

    /**
     * 유형 필터 셀렉트용 옵션을 만듭니다 (비활성 포함).
     *
     * 이미 비활성화된 유형으로 남은 과거 반응도 골라낼 수 있어야 하므로 전 유형을 낸다.
     *
     * @return array<int, array<string, mixed>> 유형 옵션
     */
    private function reactionTypeOptions(): array
    {
        return app(ReactionTypeRepositoryInterface::class)->all()
            ->map(fn ($type) => [
                'id' => (int) $type->id,
                'code' => $type->code,
                'name' => $type->getLocalizedName(),
                'icon_name' => $type->getIconName(),
                'icon_url' => $type->getIconUrl(),
                'is_active' => (bool) $type->is_active,
            ])
            ->values()
            ->all();
    }
}
