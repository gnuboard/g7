<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;

/**
 * 게시판 이벤트 훅 리스너 (이커머스 문의 연동)
 *
 * 게시판 Post 생성/삭제 이벤트를 수신하여 문의 피벗 데이터를 관리합니다.
 * - sirsoft-board.post.after_delete → 게시판 Post 삭제 시 피벗 삭제
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
            // 게시판 Post 삭제 후 피벗 삭제 (Action 훅)
            'sirsoft-board.post.after_delete' => [
                'method' => 'handlePostDeleted',
                'priority' => 20,
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
     * @param mixed ...$args 훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 게시판 Post 삭제 후 문의 피벗 삭제
     *
     * 게시판에서 Post가 삭제될 때 연결된 문의 피벗 데이터를 정리합니다.
     * 이커머스 경로에서는 ProductInquiryService::deleteInquiry()가 피벗을 직접 삭제하므로
     * 이 리스너가 실행되더라도 이미 삭제된 피벗이면 deleteByInquirableIds가 0건 처리합니다.
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
            $this->repository->deleteByInquirableIds(
                get_class($post),
                [$post->id]
            );

            Log::debug('ProductInquiryBoardListener: 문의 피벗 삭제 완료', [
                'post_id' => $post->id,
            ]);
        } catch (\Exception $e) {
            Log::error('ProductInquiryBoardListener: 문의 피벗 삭제 실패', [
                'post_id' => $post->id ?? null,
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
     * @param  \Illuminate\Foundation\Http\FormRequest  $request  요청 객체
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
