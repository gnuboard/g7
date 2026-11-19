<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;

/**
 * 모델 한 개의 내보내기 선언 계약 (D19 반자동 매핑)
 *
 * 모델 클래스가 내보내기를 몰라도 되도록 선언을 모델이 아니라 내보내기 쪽이 따로 갖는다. 다른 확장이 자기 모델을
 * 내보내기에 싣고 싶으면 이 계약을 구현해 `sirsoft-ecommerce.export.declarations` 필터로 더한다.
 */
interface ModelDeclaration
{
    /**
     * 선언 대상 모델 클래스를 반환합니다.
     *
     * @return class-string<Model> 모델 클래스
     */
    public static function model(): string;

    /**
     * 내보낼 컬럼을 반환합니다.
     *
     * 값은 `['type' => 값 종류, 'personal' => bool, 'label_key' => 다국어 키?, 'summable' => bool?, 'enum' => Enum 클래스?]`.
     * `type`·`personal` 은 필수다 — `personal` 을 빠뜨리면 개인정보가 일반 필드로 나갈 수 있어 기본값을 두지 않는다.
     *
     * @return array<string, array<string, mixed>> 컬럼 → 선언
     */
    public static function fields(): array;

    /**
     * 내보내지 않는 컬럼과 사유를 반환합니다.
     *
     * @return array<string, string> 컬럼 → 사유 ({@see ExportExclusion})
     */
    public static function excluded(): array;
}
