<?php

namespace Tests\Feature\Rules;

use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 사이트 기본 언어 필수 다국어 칸의 오류 문구가 칸 이름과 언어 이름을 알려 준다(필수 언어는 화면 언어가 아니라
 * 사이트 기본 언어 — PO 결정 2026-10-09).
 *
 * 2026-10-07 실측(O-KF3-1): 영어 화면에서 게시판을 만들며 이름을 비우면 「The en language value is required.」,
 * 한국어 화면에서는 「ko 언어의 값은 필수입니다.」처럼 로케일 코드만 나와 어느 칸의 어느 언어를 채워야 하는지
 * 알 수 없었다. 이 규칙은 코어 요청(메뉴·역할·권한·알림 템플릿 등)과 확장(게시판·이커머스)이 함께 쓴다.
 */
class LocaleRequiredTranslatableMessageTest extends TestCase
{
    /**
     * 한국어 화면의 필수 언어 오류에 칸 이름과 언어 이름(코드 병기)이 들어간다.
     *
     * @scenario locale=ko
     *
     * @effects required_locale_message_names_field_and_language
     *
     * @return void 반환값 없음
     */
    public function test_korean_message_names_field_and_language(): void
    {
        app()->setLocale('ko');

        $message = $this->messageFor(['name' => ['ko' => '', 'en' => 'Board']], ['name' => '게시판 이름']);

        $this->assertSame('게시판 이름의 한국어(ko) 값은 필수입니다.', $message);
    }

    /**
     * 영어 화면에서도 필수 언어는 사이트 기본 언어(ko)이고, 오류에 칸 이름과 언어 이름(코드 병기)이 들어간다.
     *
     * @scenario locale=en
     *
     * @effects required_locale_message_names_field_and_language
     *
     * @return void 반환값 없음
     */
    public function test_english_message_names_field_and_language(): void
    {
        app()->setLocale('en');

        $message = $this->messageFor(['name' => ['ko' => '', 'en' => 'Board']], ['name' => 'Board Name']);

        $this->assertSame('The 한국어 (ko) value of the Board Name field is required.', $message);
    }

    /**
     * 언어 이름 목록에 없는 코드는 코드 그대로 보여 준다(언어팩이 꺼진 사이트 등).
     *
     * @scenario locale=unnamed
     *
     * @effects required_locale_message_falls_back_to_code
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_code_when_language_name_unknown(): void
    {
        app()->setLocale('ko');
        config(['app.locale_names' => []]);

        $message = $this->messageFor(['name' => ['ko' => '']], ['name' => '게시판 이름']);

        $this->assertSame('게시판 이름의 ko(ko) 값은 필수입니다.', $message);
    }

    /**
     * 표시 이름을 정하지 않은 칸은 필드 키(name)를 문구에 싣지 않고 언어만 알린다.
     *
     * 2026-10-07 실측(K4): 칸 이름을 싣도록 바꾼 뒤, 표시 이름 매핑이 없는 요청(메뉴·역할·권한 이름,
     * 게시판 유형 이름, 페이지 제목 등)에서는 「name의 한국어(ko) 값은 필수입니다.」처럼 필드 키가 보였다.
     *
     * @scenario locale=unnamed_field
     *
     * @effects required_locale_message_hides_raw_field_key
     *
     * @return void 반환값 없음
     */
    public function test_unnamed_field_message_does_not_expose_field_key(): void
    {
        app()->setLocale('ko');
        $this->assertSame('한국어(ko) 값은 필수입니다.', $this->messageFor(['name' => ['ko' => '', 'en' => 'Menu']], []));

        app()->setLocale('en');
        $this->assertSame('The 한국어 (ko) value is required.', $this->messageFor(['name' => ['ko' => '', 'en' => 'Menu']], []));
    }

    /**
     * 검증기를 돌려 name 칸의 첫 오류 문구를 돌려줍니다.
     *
     * @param  array<string, mixed>  $data  검증할 값
     * @param  array<string, string>  $attributes  칸 표시 이름
     * @return string 첫 오류 문구
     */
    private function messageFor(array $data, array $attributes): string
    {
        $validator = Validator::make($data, ['name' => [new LocaleRequiredTranslatable]], [], $attributes);

        $this->assertTrue($validator->fails());

        return (string) $validator->errors()->first('name');
    }
}
