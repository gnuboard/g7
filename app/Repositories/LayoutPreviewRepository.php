<?php

namespace App\Repositories;

use App\Contracts\Repositories\LayoutPreviewRepositoryInterface;
use App\Models\TemplateLayoutPreview;

/**
 * 레이아웃 미리보기 리포지토리 구현체
 */
class LayoutPreviewRepository implements LayoutPreviewRepositoryInterface
{
    /**
     * 미리보기를 생성합니다.
     *
     * @param  array  $data  생성 데이터
     * @return TemplateLayoutPreview 생성된 미리보기 모델
     */
    public function create(array $data): TemplateLayoutPreview
    {
        return TemplateLayoutPreview::create($data);
    }

    /**
     * 토큰으로 미리보기를 조회합니다.
     *
     * @param  string  $token  미리보기 토큰
     * @return TemplateLayoutPreview|null 찾은 미리보기 모델 또는 null
     */
    public function findByToken(string $token): ?TemplateLayoutPreview
    {
        return TemplateLayoutPreview::where('token', $token)
            ->notExpired()
            ->first();
    }

    /**
     * 토큰으로 미리보기를 삭제합니다.
     *
     * @param  string  $token  미리보기 토큰
     * @return bool 삭제 성공 여부
     */
    public function deleteByToken(string $token): bool
    {
        return TemplateLayoutPreview::where('token', $token)->delete() > 0;
    }

    /**
     * 만료된 미리보기를 일괄 삭제합니다.
     *
     * @return int 삭제된 행 수
     */
    public function deleteExpired(): int
    {
        // 만료 정리는 역할과 무관하다 — 검수 서버는 스케줄을 돌리지 않으므로 운영 서버가 모든 역할의 만료 행을 지운다
        return TemplateLayoutPreview::withoutGlobalScope('runtime_role')->where('expires_at', '<=', now())->delete();
    }

    /**
     * 특정 관리자의 특정 레이아웃 미리보기를 삭제합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @param  int  $adminId  관리자 ID
     * @return int 삭제된 행 수
     */
    public function deleteByLayoutAndAdmin(int $templateId, string $layoutName, int $adminId): int
    {
        return TemplateLayoutPreview::where('template_id', $templateId)
            ->where('layout_name', $layoutName)
            ->where('admin_id', $adminId)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByTemplateId(int $templateId): int
    {
        // 템플릿 제거는 모든 역할의 미리보기를 지운다
        return TemplateLayoutPreview::withoutGlobalScope('runtime_role')->where('template_id', $templateId)->delete();
    }
}
