<?php

namespace Tests\Unit\Lang;

use App\Http\Requests\Menu\BulkUpdateMenuRolesRequest;
use App\Http\Requests\Role\RoleListRequest;
use App\Http\Requests\User\BulkUpdateUserRolesRequest;
use App\Http\Requests\User\BulkUpdateUserStatusRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Laravel 표준 검증 문구의 단·복수 테스트 (실제 검증기 경유).
 *
 * 「The tags field must have at least 1 items.」 처럼 규칙값(`:min`·`:max`·`:size`·`:value`·`:digits`·`:decimal`)
 * 뒤 명사를 늘 복수로 적던 영어 검증 문구를, 영어 값 `단수|복수` + 코어 검증 문구 치환기(규칙값으로 고르기)로
 * 1 이면 단수, 그 밖이면 복수로 낸다. 한국어는 그대로다.
 */
class ValidationRulePluralMessagesTest extends TestCase
{
    /**
     * 규칙별 [데이터, 규칙, 기대 영어 문구] — 규칙값 1 과 2 이상을 짝으로 둔다.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> 데이터 이름 => [데이터, 규칙, 기대 문구]
     */
    public static function englishCases(): array
    {
        return [
            'min.array 1' => [['tags' => []], 'array|min:1', 'The tags field must have at least 1 item.'],
            'min.array 2' => [['tags' => ['a']], 'array|min:2', 'The tags field must have at least 2 items.'],
            'max.array 1' => [['tags' => ['a', 'b']], 'array|max:1', 'The tags field must not have more than 1 item.'],
            'size.array 1' => [['tags' => ['a', 'b']], 'array|size:1', 'The tags field must contain 1 item.'],
            'size.array 3' => [['tags' => ['a']], 'array|size:3', 'The tags field must contain 3 items.'],
            'min.string 3' => [['code' => 'ab'], 'string|min:3', 'The code field must be at least 3 characters.'],
            'max.string 1' => [['code' => 'ab'], 'string|max:1', 'The code field must not be greater than 1 character.'],
            'max.string 2' => [['code' => 'abc'], 'string|max:2', 'The code field must not be greater than 2 characters.'],
            'size.string 1' => [['code' => 'ab'], 'string|size:1', 'The code field must be 1 character.'],
            'digits 1' => [['pin' => '12'], 'digits:1', 'The pin field must be 1 digit.'],
            'digits 3' => [['pin' => '12'], 'digits:3', 'The pin field must be 3 digits.'],
            'min_digits 2' => [['pin' => 5], 'min_digits:2', 'The pin field must have at least 2 digits.'],
            'max_digits 1' => [['pin' => 12], 'max_digits:1', 'The pin field must not have more than 1 digit.'],
            'decimal 1' => [['amount' => '1.23'], 'decimal:1', 'The amount field must have 1 decimal place.'],
            'decimal 3' => [['amount' => '1.23'], 'decimal:3', 'The amount field must have 3 decimal places.'],
            // 비교 대상 필드의 크기가 수가 된다 (:value)
            'gt.array field 1' => [['a' => ['x'], 'b' => ['x']], 'array|gt:a', 'The b field must have more than 1 item.'],
            'gt.array field 2' => [['a' => ['x', 'y'], 'b' => ['x', 'y']], 'array|gt:a', 'The b field must have more than 2 items.'],
            'gte.array field 1' => [['a' => ['x'], 'b' => []], 'array|gte:a', 'The b field must have 1 item or more.'],
            'lt.string field 1' => [['a' => 'x', 'b' => 'xy'], 'string|lt:a', 'The b field must be less than 1 character.'],
            'lte.array field 1' => [['a' => ['x'], 'b' => ['x', 'y']], 'array|lte:a', 'The b field must not have more than 1 item.'],
        ];
    }

    /**
     * 검증기를 거친 영어 검증 문구는 규칙값이 1 이면 단수, 그 밖이면 복수로 나오고 `|` 가 남지 않는다.
     *
     * @scenario surface=validator
     *
     * @effects validation_message_singular_for_rule_value_one
     *
     * @param  array<string, mixed>  $data  검증할 데이터
     * @param  string  $rule  마지막 필드(`b` 가 있으면 `b`, 없으면 첫 필드)에 걸 규칙
     * @param  string  $expected  기대 영어 문구
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('englishCases')]
    public function english_validation_message_follows_rule_value(array $data, string $rule, string $expected): void
    {
        $this->app->setLocale('en');
        $field = array_key_exists('b', $data) ? 'b' : array_key_first($data);

        $validator = Validator::make($data, [$field => $rule]);

        $this->assertTrue($validator->fails(), "{$rule}: 검증이 실패해야 문구를 볼 수 있다");
        $this->assertSame($expected, $validator->errors()->first($field));
    }

    /**
     * 파일 용량 문구도 1 KB 이면 단수다.
     *
     * @scenario surface=validator
     *
     * @effects validation_message_singular_for_rule_value_one
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function file_size_message_follows_rule_value(): void
    {
        $this->app->setLocale('en');

        $one = Validator::make(['doc' => UploadedFile::fake()->create('a.pdf', 3)], ['doc' => 'file|max:1']);
        $two = Validator::make(['doc' => UploadedFile::fake()->create('a.pdf', 3)], ['doc' => 'file|max:2']);

        $this->assertSame('The doc field must not be greater than 1 kilobyte.', $one->errors()->first('doc'));
        $this->assertSame('The doc field must not be greater than 2 kilobytes.', $two->errors()->first('doc'));
    }

    /**
     * FormRequest `messages()` 에 표준 문구 묶음(`__('validation.max')`)을 넘긴 경우에도 규칙값으로 고르고,
     * 이미 고른 한 문장의 커스텀 문구는 그대로 둔다.
     *
     * @scenario surface=validator
     *
     * @effects validation_message_singular_for_rule_value_one
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function custom_message_arrays_are_chosen_and_plain_messages_untouched(): void
    {
        $this->app->setLocale('en');

        $grouped = Validator::make(['code' => 'ab'], ['code' => 'string|max:1'], ['max' => __('validation.max')]);
        $plain = Validator::make(['code' => 'ab'], ['code' => 'string|max:1'], ['code.max' => 'Up to :max (custom).']);

        $this->assertSame('The code field must not be greater than 1 character.', $grouped->errors()->first('code'));
        $this->assertSame('Up to 1 (custom).', $plain->errors()->first('code'));
    }

    /**
     * 표준 문구를 미리 번역해 넘기던 코어 FormRequest 메시지도 규칙값(1·500·255)으로 고른 한 문장이다.
     *
     * @scenario surface=validator
     *
     * @effects validation_message_singular_for_rule_value_one
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function core_form_request_messages_are_chosen_by_rule_value(): void
    {
        $this->app->setLocale('en');

        $menu = (new BulkUpdateMenuRolesRequest)->messages();
        $roles = (new BulkUpdateUserRolesRequest)->messages();
        $status = (new BulkUpdateUserStatusRequest)->messages();
        $roleList = (new RoleListRequest)->messages();

        $this->assertSame('The menu list field must have at least 1 item.', $menu['menu_ids.min']);
        $this->assertSame('The menu list field must not have more than 500 items.', $menu['menu_ids.max']);
        $this->assertSame('The role list field must have at least 1 item.', $menu['role_ids.min']);
        $this->assertSame('The member list field must have at least 1 item.', $roles['uuids.min']);
        $this->assertSame('The member list field must not have more than 500 items.', $roles['uuids.max']);
        $this->assertSame('The role list field must have at least 1 item.', $roles['role_ids.min']);
        $this->assertSame('The user ID list field must have at least 1 item.', $status['ids.min']);
        $this->assertSame('The search field must not be greater than 255 characters.', $roleList['search.max']);
    }

    /**
     * 한국어 검증 문구는 복수 구분 기호가 없고 종전과 같은 문장이다.
     *
     * @scenario surface=validator
     *
     * @effects korean_validation_message_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function korean_validation_message_is_unchanged(): void
    {
        $this->app->setLocale('ko');

        foreach ([1, 2] as $min) {
            $validator = Validator::make(['tags' => []], ['tags' => "array|min:{$min}"]);
            $message = $validator->errors()->first('tags');

            $this->assertStringNotContainsString('|', $message);
            $this->assertStringContainsString("최소 {$min}개 이상", $message);
        }

        $menu = (new BulkUpdateMenuRolesRequest)->messages();
        $this->assertStringNotContainsString('|', $menu['menu_ids.min']);
        $this->assertStringContainsString('최소 1개 이상', $menu['menu_ids.min']);
    }
}
