<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;

/**
 * 게시판 활동 로그 description_params 해석 리스너
 *
 * 활동 로그에 저장된 엔티티 ID를 표시 시점에 사람이 읽을 수 있는 이름으로 변환합니다.
 * properties에 저장된 스냅샷 이름을 우선 사용하고, 없으면 DB를 조회합니다.
 *
 * 훅: core.activity_log.filter_description_params (Filter)
 */
class ActivityLogDescriptionResolver implements HookListenerInterface
{
    /**
     * @param  BoardRepositoryInterface  $boardRepository  게시판 Repository (ID/slug → 이름 변환)
     */
    public function __construct(
        private readonly BoardRepositoryInterface $boardRepository,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.activity_log.filter_description_params' => [
                'method' => 'resolveDescriptionParams',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return mixed
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    /**
     * description_params에 엔티티 이름을 해석하여 추가합니다.
     *
     * @param array $params 현재 description_params
     * @param string $descriptionKey 번역 키
     * @param array $properties 활동 로그 properties
     * @return array 해석된 description_params
     */
    public function resolveDescriptionParams(array $params, string $descriptionKey, array $properties): array
    {
        $prefix = 'sirsoft-board::activity_log.description.';
        if (! str_starts_with($descriptionKey, $prefix)) {
            return $params;
        }

        $keySuffix = str_replace($prefix, '', $descriptionKey);

        return match (true) {
            // 게시판 관련: board_name 해석
            str_starts_with($keySuffix, 'board_type_') => $this->resolveBoardTypeName($params, $properties),
            str_starts_with($keySuffix, 'board_') => $this->resolveBoardName($params, $properties),

            // 게시물 관련: board_name + title 해석
            str_starts_with($keySuffix, 'post_') => $this->resolvePostParams($params, $properties),

            // 댓글 관련: board_name 해석 (comment_create에서 사용)
            str_starts_with($keySuffix, 'comment_') => $this->resolveCommentParams($params, $properties),

            default => $params,
        };
    }

    /**
     * 게시판 이름을 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolveBoardName(array $params, array $properties): array
    {
        if (! empty($params['board_name'])) {
            return $params;
        }

        // 1순위: properties.name 스냅샷
        if (! empty($properties['name'])) {
            $params['board_name'] = $properties['name'];

            return $params;
        }

        // 2순위: properties.board_id로 DB 조회
        $boardId = $properties['board_id'] ?? null;
        if ($boardId) {
            $board = $this->boardRepository->find((int) $boardId);
            $params['board_name'] = $board?->name ?? "ID: {$boardId}";
        }

        return $params;
    }

    /**
     * 게시판 유형 이름을 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolveBoardTypeName(array $params, array $properties): array
    {
        if (! empty($params['type_name'])) {
            return $params;
        }

        // properties.name 스냅샷 우선
        if (! empty($properties['name'])) {
            $params['type_name'] = $properties['name'];
        }

        return $params;
    }

    /**
     * 게시물 관련 파라미터(board_name, title)를 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolvePostParams(array $params, array $properties): array
    {
        // board_name 해석
        if (empty($params['board_name']) && ! empty($properties['board_name'])) {
            $params['board_name'] = $properties['board_name'];
        }

        // title 해석 (post_create, post_update에서 사용)
        if (empty($params['title']) && ! empty($properties['title'])) {
            $params['title'] = $properties['title'];
        }

        return $params;
    }

    /**
     * 댓글 관련 파라미터(board_name)를 해석합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolveCommentParams(array $params, array $properties): array
    {
        // board_name 해석 (comment_create에서 사용)
        if (empty($params['board_name']) && ! empty($properties['board_name'])) {
            $params['board_name'] = $properties['board_name'];
        }

        // slug로 board_name 조회 (fallback)
        if (empty($params['board_name']) && ! empty($properties['slug'])) {
            $board = $this->boardRepository->findBySlug((string) $properties['slug']);
            if ($board) {
                $params['board_name'] = $board->name;
            }
        }

        return $params;
    }
}
