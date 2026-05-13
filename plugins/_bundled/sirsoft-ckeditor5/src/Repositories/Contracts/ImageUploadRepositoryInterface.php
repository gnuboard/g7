<?php

namespace Plugins\Sirsoft\Ckeditor5\Repositories\Contracts;

use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;

/**
 * CKEditor5 이미지 업로드 Repository 인터페이스
 */
interface ImageUploadRepositoryInterface
{
    /**
     * 해시로 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return Ckeditor5ImageUpload|null
     */
    public function findByHash(string $hash): ?Ckeditor5ImageUpload;

    /**
     * 이미지 기록 생성
     *
     * @param  array  $data  이미지 데이터
     * @return Ckeditor5ImageUpload
     */
    public function create(array $data): Ckeditor5ImageUpload;
}
