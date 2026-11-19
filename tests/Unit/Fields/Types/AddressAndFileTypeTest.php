<?php

namespace Tests\Unit\Fields\Types;

use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\FieldTypeRegistry;
use App\Fields\Types\FileType;
use App\Fields\Types\ImageType;

/**
 * 주소 유형과 첨부 유형(파일, 이미지)의 규칙, 그리고 유형 등록소의 구성.
 */
class AddressAndFileTypeTest extends FieldTypeTestCase
{
    /**
     * 운영자 추가 주소 항목은 한 묶음 아래 하위 값 세 개를 받고, 필수 판정은 우편번호와 기본 주소로 한다.
     *
     * @scenario field_type=address
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_address_nested_parts(): void
    {
        $definition = $this->definition('address');
        $type = $this->type('address');
        $value = ['zipcode' => '04524', 'address' => '서울 중구 세종대로 110', 'address_detail' => '3층'];

        $this->assertTrue($this->passes($definition, $value));
        $this->assertFalse($this->passes($definition, [...$value, 'city' => '서울']), '모르는 하위 키가 통과했다');
        $this->assertFalse($this->passes($definition, [...$value, 'zipcode' => str_repeat('1', 11)]), '우편번호 10자 상한이 적용되지 않았다');
        $this->assertSame($value, $this->roundTrip($definition, $value));
        $this->assertSame(['field.zipcode', 'field.address'], $type->requiredAttributes($definition, 'field'));
        $this->assertTrue($type->isEmpty(['zipcode' => '04524', 'address' => null, 'address_detail' => '3층']), '기본 주소가 없는데 채워진 값으로 판정했다');
        $this->assertFalse($type->isEmpty(['zipcode' => '04524', 'address' => '서울 중구', 'address_detail' => null]), '상세 주소가 없다고 빈 값으로 판정했다');
        $this->assertSame('(04524) 서울 중구 세종대로 110 3층', $type->display($definition, $value));
    }

    /**
     * 코어 회원 주소처럼 하위 값이 최상위 요청 키로 흩어진 항목은 그 키들로 규칙을 만들고 값을 꺼낸다.
     *
     * @scenario field_type=address
     *
     * @effects field_type_address_flat_parts_keep_legacy_request_keys
     *
     * @return void 반환값 없음
     */
    public function test_address_flat_parts_use_top_level_keys(): void
    {
        $definition = $this->definition('address', ['parts' => ['zipcode' => 'zipcode', 'address' => 'address', 'address_detail' => 'address_detail']]);
        $type = $this->type('address');

        $this->assertSame(['zipcode', 'address', 'address_detail'], array_keys($type->rules($definition, 'address')), '기존 요청 키와 다른 규칙 경로가 나왔다');
        $this->assertSame(['zipcode', 'address', 'address_detail'], $type->inputPaths($definition, 'address'));
        $this->assertSame(
            ['zipcode' => '04524', 'address' => '서울', 'address_detail' => null],
            $type->extract($definition, 'address', ['zipcode' => '04524', 'address' => '서울']),
        );
        $this->assertNull($type->extract($definition, 'address', ['name' => '홍길동']), '주소 키가 하나도 없는 요청에서 값이 만들어졌다');
    }

    /**
     * 파일 항목은 폼 본문 규칙이 없고, 업로드 규칙이 확장자·크기·개수 제한을 건다.
     *
     * @scenario field_type=file
     *
     * @effects field_type_file_upload_limits
     *
     * @return void 반환값 없음
     */
    public function test_file_upload_limits_and_no_form_rules(): void
    {
        /** @var FileType $type */
        $type = $this->type('file');
        $definition = $this->definition('file', ['extensions' => ['.PDF', 'php', 'svg', 'docx'], 'max_count' => 99, 'max_size_mb' => 999]);

        $this->assertSame([], $type->rules($definition, 'field'), '파일 항목이 폼 본문 규칙을 가졌다');
        $this->assertFalse($type->supports(FieldTypeInterface::FEATURE_SIGNUP), '파일 항목이 가입 화면에서 받을 수 있다고 답했다');
        $this->assertSame(['pdf', 'docx'], $type->allowedExtensions($definition), '실행될 수 있는 확장자가 걸러지지 않았다');
        $this->assertSame(FileType::MAX_COUNT_LIMIT, $type->maxCount($definition));
        $this->assertSame(FileType::MAX_SIZE_LIMIT_MB, $type->maxSizeMb($definition));
        $this->assertContains('max:'.(FileType::MAX_SIZE_LIMIT_MB * 1024), $type->uploadRules($definition));
        $this->assertTrue($type->isEmpty([]));
        $this->assertFalse($type->isEmpty([['id' => 1, 'name' => 'a.pdf']]));
        $this->assertSame('a.pdf, b.pdf', $type->display($definition, [['name' => 'a.pdf'], ['name' => 'b.pdf']]));
    }

    /**
     * 이미지 항목은 이미지 확장자만 남기고 업로드 때 이미지인지 확인한다.
     *
     * @scenario field_type=image
     *
     * @effects field_type_file_upload_limits
     *
     * @return void 반환값 없음
     */
    public function test_image_keeps_only_image_extensions(): void
    {
        /** @var ImageType $type */
        $type = $this->type('image');

        $this->assertSame(['png'], $type->allowedExtensions($this->definition('image', ['extensions' => ['pdf', 'png']])));
        $this->assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], $type->allowedExtensions($this->definition('image', ['extensions' => ['pdf']])), '이미지가 아닌 확장자만 적었을 때 기본 목록으로 돌아가지 않았다');
        $this->assertContains('image', $type->uploadRules($this->definition('image')));
        $this->assertSame('file', $type->renderer());
    }

    /**
     * 등록소는 코어 유형 15종을 타일 순서대로 갖고, 필터가 더한 유형도 받는다.
     *
     * @scenario field_type=text
     *
     * @effects field_type_registry_lists_core_types
     *
     * @return void 반환값 없음
     */
    public function test_registry_lists_fifteen_core_types_in_order(): void
    {
        $registry = app(FieldTypeRegistry::class);

        $this->assertSame(
            ['text', 'textarea', 'number', 'boolean', 'date', 'datetime', 'date_range', 'choice', 'multi_choice', 'phone', 'email', 'url', 'address', 'file', 'image'],
            array_keys($registry->all()),
        );
        $this->assertSame(['core', 'core'], $registry->ownerOf('address'));
        $this->assertNull($registry->get('color'), '등록되지 않은 유형이 조회됐다');
    }
}
