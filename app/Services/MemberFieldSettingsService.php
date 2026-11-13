<?php

namespace App\Services;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Jobs\RecomputeNicknameUniqueKeysJob;

/**
 * 환경설정 > 회원 탭이 쓰는 안내 값(닉네임 겹침, 중복 검사 준비 진행, 가입 화면 수정본 경고) 유스케이스.
 *
 * @since 7.2.0
 */
class MemberFieldSettingsService
{
    /** 가입 화면 레이아웃 이름 */
    public const SIGNUP_LAYOUT = 'auth/register';

    /** 가입 화면의 항목 반복 구획 노드 id — 이 노드가 없으면 운영자 수정본이 새 항목 조각을 받지 못한 것이다 */
    public const SIGNUP_FIELDS_NODE_ID = 'member_fields_root';

    /**
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  TemplateRepositoryInterface  $templates  템플릿 저장소
     * @param  LayoutRepositoryInterface  $layouts  레이아웃 저장소
     * @param  LayoutRowSynchronizer  $layoutRows  레이아웃 행 동기화기 (운영자 수정본 판정)
     */
    public function __construct(
        protected UserRepositoryInterface $users,
        protected TemplateRepositoryInterface $templates,
        protected LayoutRepositoryInterface $layouts,
        protected LayoutRowSynchronizer $layoutRows,
    ) {}

    /**
     * 지금 겹치는 닉네임의 종 수와 회원 수를 반환합니다 (중복 금지를 켜기 전 안내).
     *
     * @return array{duplicate_nicknames: int, duplicate_members: int} 겹침 요약
     */
    public function nicknameDuplicates(): array
    {
        $summary = $this->users->nicknameDuplicateSummary();

        return [
            'duplicate_nicknames' => $summary['nicknames'],
            'duplicate_members' => $summary['members'],
        ];
    }

    /**
     * 중복 검사 준비(중복 키 재계산) 작업의 진행 상태를 반환합니다.
     *
     * @return array{status: string, processed: int, total: int, unique: bool|null, started_at: string|null, finished_at: string|null} 진행 상태
     */
    public function nicknameRecomputeStatus(): array
    {
        return RecomputeNicknameUniqueKeysJob::progress();
    }

    /**
     * 가입 화면이 운영자 수정본으로 유지돼 새 항목 조각이 빠져 있는지 반환합니다.
     *
     * 수정본이라도 병합으로 항목 반복 구획이 들어갔으면 경고하지 않는다. 사용자 템플릿이 없거나 가입 레이아웃이 없으면 false.
     *
     * @return bool 항목 설정이 가입 화면에 보이지 않을 수 있으면 true
     */
    public function signupLayoutModified(): bool
    {
        $template = $this->templates->findActiveByType('user');
        if ($template === null) {
            return false;
        }

        $layout = $this->layouts->findByName($template->id, self::SIGNUP_LAYOUT);
        if ($layout === null || ! $this->layoutRows->isOperatorModified($layout)) {
            return false;
        }

        return ! $this->layouts->layoutContainsNodeId($template->id, self::SIGNUP_LAYOUT, self::SIGNUP_FIELDS_NODE_ID);
    }
}
