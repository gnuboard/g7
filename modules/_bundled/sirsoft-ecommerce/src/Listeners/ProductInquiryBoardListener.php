<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;

/**
 * 게시판 이벤트 훅 리스너 (이커머스 문의 연동)
 *
 * 게시판 Post 삭제/복원 이벤트를 수신하여 문의 피벗 데이터를 관리합니다.
 * - sirsoft-board.post.after_delete → 질문 글 삭제 시 피벗 소프트 삭제, 답변 글 삭제 시 답변완료 재계산
 * - sirsoft-board.post.after_restore → 질문 글 복원 시 피벗 복원, 답변 글 복원 시 답변완료 재마킹
 * - sirsoft-board.board.posts.before_force_delete → 게시판 삭제 시 삭제될 글의 피벗 일괄 정리
 * - sirsoft-ecommerce.inquiry.store_validation_rules → 게시판 설정 기반 동적 검증 규칙 주입
 * - sirsoft-ecommerce.inquiry.update_validation_rules → 게시판 설정 기반 동적 검증 규칙 주입
 */
class ProductInquiryBoardListener implements HookListenerInterface
{
    /**
     * ProductInquiryBoardListener 생성자
     *
     * @param  ProductInquiryRepositoryInterface  $repository  문의 리포지토리
     * @param  ProductInquiryService  $inquiryService  문의 서비스
     */
    public function __construct(
        protected ProductInquiryRepositoryInterface $repository,
        protected ProductInquiryService $inquiryService
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 게시판 Post 삭제 후 피벗 정리 (Action 훅)
            // sync: 큐 워커 미가동 환경에서 피벗 정리가 누락되지 않도록 동기 실행 (N3)
            'sirsoft-board.post.after_delete' => [
                'method' => 'handlePostDeleted',
                'priority' => 20,
                'sync' => true,
            ],
            // 게시판 Post 복원 후 피벗 복원/재마킹 (Action 훅)
            'sirsoft-board.post.after_restore' => [
                'method' => 'handlePostRestored',
                'priority' => 20,
                'sync' => true,
            ],
            // 게시판 삭제 시 삭제될 글 ID 벌크 통지 → 피벗 정리 (Action 훅, board 1.0.4+)
            // 트랜잭션 내부에서 chunk 당 1회 다회 발화 — 멱등 처리 의무
            'sirsoft-board.board.posts.before_force_delete' => [
                'method' => 'handleBoardPostsForceDeleting',
                'priority' => 20,
                'sync' => true,
            ],
            // 문의 작성 폼 검증 규칙에 게시판 설정 기반 동적 규칙 주입 (Filter 훅)
            'sirsoft-ecommerce.inquiry.store_validation_rules' => [
                'method' => 'injectBoardValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
            // 문의 수정 폼 검증 규칙에 게시판 설정 기반 동적 규칙 주입 (Filter 훅)
            'sirsoft-ecommerce.inquiry.update_validation_rules' => [
                'method' => 'injectBoardValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 게시판 Post 삭제 후 문의 피벗 정리
     *
     * 질문 글 삭제: 연결된 문의 피벗을 소프트 삭제합니다. 이커머스 경로에서는
     * ProductInquiryService::deleteInquiry()가 피벗을 직접 삭제하므로 이 리스너가
     * 실행되더라도 이미 삭제된 피벗이면 deleteByInquirableIds가 0건 처리합니다(멱등).
     *
     * 답변 글 삭제(N4): 게시판 관리자가 답변 글을 직접 지운 경우 — 부모(질문) 피벗의
     * `is_answered` 를 잔여 답변 수 기준으로 재계산합니다. 종전에는 이 분기가 없어
     * "답변완료" 인데 답변이 없는 상태가 남았습니다.
     *
     * @param  object  $post  삭제된 Post 객체
     * @param  string  $slug  게시판 슬러그
     * @param  array  $options  삭제 옵션
     * @return void
     */
    public function handlePostDeleted(?object $post, string $slug, array $options = []): void
    {
        if ($post === null) {
            return; // 큐 워커 시점에 모델이 이미 사라진 경우 스킵
        }

        try {
            // 답변 글(자식 Post) 분기: 부모 문의의 답변완료 상태 재계산
            if (! empty($post->parent_id)) {
                $pivot = $this->repository->findByInquirable(get_class($post), (int) $post->parent_id);

                if ($pivot && $pivot->is_answered) {
                    // 기본값을 null(판정 불가)로 두고, 공급자(board 모듈 리스너)가 실제
                    // 집계값을 실어야만 해제를 진행한다. 기본값 0 이면 공급자 부재
                    // (board 비활성/구버전)가 "답변 없음" 으로 읽혀 답변완료가 오해제된다.
                    $remaining = HookManager::applyFilters(
                        'sirsoft-ecommerce.inquiry.count_replies',
                        null,
                        (int) $post->parent_id
                    );

                    if ($remaining === null || ! is_numeric($remaining)) {
                        return; // 공급자 부재 — 판정 불가 시 상태를 건드리지 않는다
                    }

                    if ((int) $remaining === 0) {
                        $this->repository->unmarkAnswered($pivot);

                        Log::debug('ProductInquiryBoardListener: 답변 글 삭제 → 답변완료 해제', [
                            'reply_post_id' => $post->id,
                            'parent_post_id' => $post->parent_id,
                        ]);
                    }
                }

                return;
            }

            // 질문 글 분기: 피벗 소프트 삭제 (SoftDeletes 도입으로 복원 대칭 확보)
            $this->repository->deleteByInquirableIds(
                get_class($post),
                [$post->id]
            );

            Log::debug('ProductInquiryBoardListener: 문의 피벗 삭제 완료', [
                'post_id' => $post->id,
            ]);
        } catch (\Exception $e) {
            Log::error('ProductInquiryBoardListener: 문의 피벗 정리 실패', [
                'post_id' => $post->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 게시판 Post 복원 후 문의 피벗 복원/재마킹
     *
     * 질문 글 복원: 소프트 삭제됐던 피벗을 복원해 문의 목록에 다시 나타나게 합니다.
     * 답변 글 복원: 부모 문의 피벗을 답변완료로 재마킹합니다 (answered_at 은
     * markAsAnswered 가 기존 값을 보존).
     *
     * @param  object  $post  복원된 Post 객체
     * @param  string  $slug  게시판 슬러그
     * @return void
     */
    public function handlePostRestored(?object $post, string $slug): void
    {
        if ($post === null) {
            return;
        }

        try {
            // 답변 글 복원: 부모 문의를 답변완료로 재마킹
            if (! empty($post->parent_id)) {
                $pivot = $this->repository->findByInquirable(get_class($post), (int) $post->parent_id);

                if ($pivot && ! $pivot->is_answered) {
                    $this->repository->markAsAnswered($pivot);

                    Log::debug('ProductInquiryBoardListener: 답변 글 복원 → 답변완료 재마킹', [
                        'reply_post_id' => $post->id,
                        'parent_post_id' => $post->parent_id,
                    ]);
                }

                return;
            }

            // 질문 글 복원: 피벗 복원
            $restored = $this->repository->restoreByInquirable(get_class($post), (int) $post->id);

            if ($restored > 0) {
                Log::debug('ProductInquiryBoardListener: 문의 피벗 복원 완료', [
                    'post_id' => $post->id,
                    'restored' => $restored,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('ProductInquiryBoardListener: 문의 피벗 복원 실패', [
                'post_id' => $post->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 게시판 삭제 시 삭제될 글 ID 벌크 수신 → 문의 피벗 소프트 삭제 (N2)
     *
     * 게시판 삭제는 글별 after_delete 훅 없이 하드 삭제되므로, 이 벌크 훅이 없으면
     * 피벗이 존재하지 않는 Post 를 가리킨 채 잔존합니다(역방향 고아). chunk 당 1회
     * 다회 발화되며 이미 삭제된 피벗은 0건 처리라 멱등합니다.
     *
     * inquirable_type 은 게시판 Post FQCN 리터럴을 사용합니다 — 이커머스는 board
     * 모듈 클래스에 컴파일 타임 의존하지 않습니다(훅 전용 연동).
     *
     * @param  object  $board  삭제 중인 게시판 모델
     * @param  array<int>  $postIds  삭제될 Post ID 배열 (withTrashed 포함)
     * @return void
     */
    public function handleBoardPostsForceDeleting(?object $board, array $postIds = []): void
    {
        if ($postIds === []) {
            return;
        }

        try {
            $deleted = $this->repository->deleteByInquirableIds(
                'Modules\\Sirsoft\\Board\\Models\\Post',
                $postIds
            );

            if ($deleted > 0) {
                Log::debug('ProductInquiryBoardListener: 게시판 삭제 → 문의 피벗 정리', [
                    'board_id' => $board->id ?? null,
                    'deleted' => $deleted,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('ProductInquiryBoardListener: 게시판 삭제 피벗 정리 실패', [
                'board_id' => $board->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 게시판 설정 기반 동적 검증 규칙 주입
     *
     * 게시판의 제목/내용 길이, 분류, 첨부파일 설정을 읽어
     * StoreInquiryRequest의 검증 규칙에 동적으로 주입합니다.
     *
     * @param  array  $rules  기존 검증 규칙
     * @param  FormRequest  $request  요청 객체
     * @return array 게시판 설정이 반영된 검증 규칙
     */
    public function injectBoardValidationRules(array $rules, $request): array
    {
        try {
            $boardSlug = $this->inquiryService->getInquiryBoardSlug();

            if (! $boardSlug) {
                return $rules;
            }

            $settings = HookManager::applyFilters(
                'sirsoft-ecommerce.inquiry.get_settings',
                [],
                $boardSlug
            );

            if (empty($settings)) {
                return $rules;
            }

            // 제목 길이 규칙 (nullable이므로 제목 입력 시만 적용)
            $minTitle = $settings['min_title_length'] ?? 2;
            $maxTitle = $settings['max_title_length'] ?? 200;
            $rules['title'] = ['nullable', 'string', "min:{$minTitle}", "max:{$maxTitle}"];

            // 내용 길이 규칙
            $minContent = $settings['min_content_length'] ?? 10;
            $maxContent = $settings['max_content_length'] ?? 10000;
            $rules['content'] = ['required', 'string', "min:{$minContent}", "max:{$maxContent}"];

            // 분류 규칙 (categories 설정 있을 때만 in 검증)
            // nullable + sometimes: null이나 빈 문자열('')이면 검증 건너뜀
            $categories = $settings['categories'] ?? [];
            if (! empty($categories)) {
                $values = implode(',', array_column($categories, 'value') ?: $categories);
                $rules['category'] = ['sometimes', 'nullable', 'string', "in:{$values}"];
            }

        } catch (\Exception $e) {
            Log::error('ProductInquiryBoardListener: 검증 규칙 주입 실패', [
                'error' => $e->getMessage(),
            ]);
        }

        return $rules;
    }
}
