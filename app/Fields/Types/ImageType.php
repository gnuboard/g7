<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 이미지 첨부.
 *
 * 파일 첨부와 같되 이미지 확장자만 받고 업로드 때 이미지인지 확인한다.
 *
 * @since 7.2.0
 */
class ImageType extends FileType
{
    protected const DEFAULT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'image';
    }

    /**
     * {@inheritDoc}
     */
    public function uploadRules(FieldDefinition $definition): array
    {
        return [...parent::uploadRules($definition), 'image'];
    }

    /**
     * {@inheritDoc}
     *
     * 운영자가 적은 확장자 가운데 이미지 확장자만 남긴다.
     */
    public function allowedExtensions(FieldDefinition $definition): array
    {
        $allowed = array_values(array_intersect(parent::allowedExtensions($definition), self::DEFAULT_EXTENSIONS));

        return $allowed === [] ? self::DEFAULT_EXTENSIONS : $allowed;
    }
}
