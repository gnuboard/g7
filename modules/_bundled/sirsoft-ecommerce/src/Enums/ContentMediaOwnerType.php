<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;

/**
 * 이커머스 본문 미디어 소유 종류 (#883 4단계)
 *
 * 본문 미디어는 이 모듈의 범용 첨부 표(`ecommerce_attachments`, collection = content_media)에 둔다(#887). 소유 종류는 그 표의
 * `attachmentable_type`(소유 모델의 morph 값)으로 저장되고, 저장 전 임시 행도 소유 종류를 갖는다(`attachmentable_id` 만 null).
 */
enum ContentMediaOwnerType: string
{
    /** 상품 설명 */
    case Product = 'product';

    /** 상품 공통 정보 */
    case CommonInfo = 'common_info';

    /**
     * 값 목록.
     *
     * @return array<int, string> 값
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 소유 모델 클래스.
     *
     * @return class-string 모델 클래스
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Product => Product::class,
            self::CommonInfo => ProductCommonInfo::class,
        };
    }

    /**
     * `ecommerce_attachments.attachmentable_type` 에 저장하는 값 — 소유 모델의 morph 값(별칭이 없으면 클래스 이름).
     *
     * @return string morph 값
     */
    public function morphType(): string
    {
        $class = $this->modelClass();

        return (new $class)->getMorphClass();
    }

    /**
     * `attachmentable_type` 값으로 소유 종류를 찾습니다.
     *
     * @param  string|null  $type  morph 값 또는 클래스 이름
     * @return self|null 소유 종류 (이 모듈의 본문 미디어 소유 모델이 아니면 null)
     */
    public static function fromMorphType(?string $type): ?self
    {
        if ($type === null || $type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;
        foreach (self::cases() as $case) {
            if ($case->modelClass() === $class || $case->morphType() === $type) {
                return $case;
            }
        }

        return null;
    }
}
