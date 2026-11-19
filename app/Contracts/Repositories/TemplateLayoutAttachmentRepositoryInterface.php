<?php

namespace App\Contracts\Repositories;

use App\Models\TemplateLayoutAttachment;
use Illuminate\Database\Eloquent\Collection;

/**
 * 템플릿 레이아웃 첨부 파일 Repository 인터페이스
 */
interface TemplateLayoutAttachmentRepositoryInterface
{
    /**
     * ID로 첨부 파일 조회
     *
     * @param  int  $id  첨부 파일 ID
     * @return TemplateLayoutAttachment|null 첨부 파일 또는 null
     */
    public function findById(int $id): ?TemplateLayoutAttachment;

    /**
     * 템플릿(+선택적 레이아웃)별 첨부 파일 목록 조회 (최신순)
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $layoutName  레이아웃 이름 (null 이면 템플릿 전체)
     * @return Collection<int, TemplateLayoutAttachment>
     */
    public function listForTemplate(int $templateId, ?string $layoutName = null): Collection;

    /**
     * 첨부 파일 생성
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return TemplateLayoutAttachment 생성된 첨부 파일
     */
    public function create(array $data): TemplateLayoutAttachment;

    /**
     * 첨부 파일 삭제 (DB 행만 — 스토리지 파일 삭제는 Service 책임)
     *
     * @param  TemplateLayoutAttachment  $attachment  삭제할 첨부 파일
     * @return bool 삭제 성공 여부
     */
    public function delete(TemplateLayoutAttachment $attachment): bool;

    /**
     * 래스터 이미지 MIME 행 수 (썸네일 미리 만들기 진행률)
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @return int 행 수
     */
    public function countByMimeTypes(array $mimeTypes): int;

    /**
     * 래스터 이미지 MIME 행을 id 순으로 나눠 조회합니다 (썸네일 미리 만들기 — 커서는 마지막 id).
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @param  int  $afterId  이 id 보다 큰 행부터
     * @param  int  $limit  최대 행 수
     * @return Collection<int, TemplateLayoutAttachment> 행 (id·disk·path·mime_type 등)
     */
    public function chunkByMimeTypes(array $mimeTypes, int $afterId, int $limit): Collection;
}
