<?php

namespace Tests\Feature\Requests;

use App\Http\Requests\Menu\CreateMenuRequest;
use App\Http\Requests\Menu\UpdateMenuRequest;
use App\Http\Requests\NotificationTemplate\UpdateNotificationTemplateRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 코어 요청의 다국어 필수 칸 오류 문구가 칸 이름을 싣는다.
 *
 * 2026-10-07 실측(K4): 메뉴·역할·권한·알림 템플릿 저장에서 다국어 칸을 비우면 「한국어(ko) 값은 필수입니다.」처럼
 * 칸 이름 없이 나왔다. 코어 규칙(LocaleRequiredTranslatable)은 표시 이름이 정해진 칸에만 칸 이름을 싣는데,
 * 이 요청들에 다국어 칸의 표시 이름 매핑(attributes)이 없었다(결정 2026-10-08 「6종 모두 칸 이름 넣기」).
 *
 * @scenario-manifest tests/scenarios/core-request-multilingual-display-name.yaml
 */
class MultilingualFieldDisplayNameTest extends TestCase
{
    /**
     * 요청 · 칸 · 기대 표시 이름(ko, en) 목록.
     *
     * @return array<string, array{0: class-string<FormRequest>, 1: string, 2: string, 3: string}> 케이스 목록
     */
    public static function requestFieldProvider(): array
    {
        return [
            'menu create name' => [CreateMenuRequest::class, 'name', '메뉴 이름', 'menu name'],
            'menu update name' => [UpdateMenuRequest::class, 'name', '메뉴 이름', 'menu name'],
            'role store name' => [StoreRoleRequest::class, 'name', '역할 이름', 'role name'],
            'role update name' => [UpdateRoleRequest::class, 'name', '역할 이름', 'role name'],
            'permission update name' => [UpdatePermissionRequest::class, 'name', '권한 이름', 'permission name'],
            'notification template subject' => [UpdateNotificationTemplateRequest::class, 'subject', '알림 제목', 'notification subject'],
            'notification template body' => [UpdateNotificationTemplateRequest::class, 'body', '알림 본문', 'notification body'],
        ];
    }

    /**
     * 한국어 화면에서 다국어 칸을 비우면 칸 이름과 언어 이름이 함께 나온다.
     *
     * @scenario locale=ko
     *
     * @effects core_request_locale_required_message_names_field
     *
     * @param  class-string<FormRequest>  $requestClass  요청 클래스
     * @param  string  $field  다국어 칸
     * @param  string  $ko  한국어 표시 이름
     * @param  string  $en  영어 표시 이름
     * @return void 반환값 없음
     */
    #[DataProvider('requestFieldProvider')]
    public function test_korean_message_names_field(string $requestClass, string $field, string $ko, string $en): void
    {
        app()->setLocale('ko');

        $this->assertSame("{$ko}의 한국어(ko) 값은 필수입니다.", $this->messageFor($requestClass, $field));
    }

    /**
     * 영어 화면에서 다국어 칸을 비우면 칸 이름과 언어 이름이 함께 나온다.
     *
     * @scenario locale=en
     *
     * @effects core_request_locale_required_message_names_field
     *
     * @param  class-string<FormRequest>  $requestClass  요청 클래스
     * @param  string  $field  다국어 칸
     * @param  string  $ko  한국어 표시 이름
     * @param  string  $en  영어 표시 이름
     * @return void 반환값 없음
     */
    #[DataProvider('requestFieldProvider')]
    public function test_english_message_names_field(string $requestClass, string $field, string $ko, string $en): void
    {
        app()->setLocale('en');

        $this->assertSame("The 한국어 (ko) value of the {$en} field is required.", $this->messageFor($requestClass, $field));
    }

    /**
     * 역할·권한의 다국어 설명 칸도 표시 이름을 갖는다(설명 칸 규칙 문구가 칸 이름을 쓸 때 필드 키가 보이지 않게).
     *
     * @scenario locale=ko
     *
     * @effects core_request_translatable_description_has_display_name
     *
     * @return void 반환값 없음
     */
    public function test_description_fields_have_display_names(): void
    {
        foreach (['ko' => ['역할 설명', '권한 설명'], 'en' => ['role description', 'permission description']] as $locale => [$role, $permission]) {
            app()->setLocale($locale);

            $this->assertSame($role, (new StoreRoleRequest)->attributes()['description'] ?? null, "[{$locale}] 역할 생성");
            $this->assertSame($role, (new UpdateRoleRequest)->attributes()['description'] ?? null, "[{$locale}] 역할 수정");
            $this->assertSame($permission, (new UpdatePermissionRequest)->attributes()['description'] ?? null, "[{$locale}] 권한 수정");
        }
    }

    /**
     * 요청의 규칙·문구·표시 이름으로 다국어 칸만 검증해 첫 오류 문구를 돌려줍니다.
     *
     * 다른 칸 규칙(유일성 검사 등)은 DB 를 읽으므로 대상 칸 규칙만 떼어 돌린다.
     *
     * @param  class-string<FormRequest>  $requestClass  요청 클래스
     * @param  string  $field  다국어 칸
     * @return string 첫 오류 문구
     */
    private function messageFor(string $requestClass, string $field): string
    {
        /** @var FormRequest $request */
        $request = new $requestClass;
        $rules = array_intersect_key($request->rules(), [$field => true]);

        $validator = Validator::make(
            [$field => ['ko' => '', 'en' => '']],
            $rules,
            $request->messages(),
            $request->attributes(),
        );

        $this->assertTrue($validator->fails(), "{$requestClass} {$field} 가 비었는데 통과했다");

        return (string) $validator->errors()->first($field);
    }
}
