<?php

namespace Plugins\Sirsoft\Ckeditor5\Repositories;

use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;

/**
 * CKEditor5 이미지 업로드 Repository 구현체
 */
class ImageUploadRepository implements ImageUploadRepositoryInterface
{
    public function __construct(
        protected Ckeditor5ImageUpload $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function findByHash(string $hash): ?Ckeditor5ImageUpload
    {
        return $this->model->where('hash', $hash)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): Ckeditor5ImageUpload
    {
        return $this->model->create($data);
    }
}
