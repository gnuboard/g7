<?php

namespace App\Contracts\Repositories;

use App\Models\TemplateLayoutPreview;

interface LayoutPreviewRepositoryInterface
{
    /**
     * 미리보기를 생성합니다.
     *
     * @param array $data 생성 데이터
     * @return TemplateLayoutPreview 생성된 미리보기 모델
     */
    public function create(array $data): TemplateLayoutPreview;

    /**
     * 토큰으로 미리보기를 조회합니다.
     *
     * @param string $token 미리보기 토큰
     * @return TemplateLayoutPreview|null 찾은 미리보기 모델 또는 null
     */
    public function findByToken(string $token): ?TemplateLayoutPreview;

    /**
     * 토큰으로 미리보기를 삭제합니다.
     *
     * @param string $token 미리보기 토큰
     * @return bool 삭제 성공 여부
     */
    public function deleteByToken(string $token): bool;

    /**
     * 만료된 미리보기를 일괄 삭제합니다.
     *
     * @return int 삭제된 행 수
     */
    public function deleteExpired(): int;

    /**
     * 특정 관리자의 특정 레이아웃 미리보기를 삭제합니다.
     *
     * @param int $templateId 템플릿 ID
     * @param string $layoutName 레이아웃 이름
     * @param int $adminId 관리자 ID
     * @return int 삭제된 행 수
     */
    public function deleteByLayoutAndAdmin(int $templateId, string $layoutName, int $adminId): int;
}
