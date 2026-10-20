<?php

namespace Tests\Feature\View;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 관리자·사용자 화면 셸(admin.blade.php·app.blade.php)의 문서 언어(`<html lang>`)가 요청 언어를 쓰는지 잠급니다.
 *
 * 셸은 `<html lang>` 과 앱 초기 로케일(엔진 설정의 `locale`)을 같은 `app()->getLocale()` 로 찍는다.
 * 여기서는 템플릿을 설치하지 않아(무거운 설치를 피한다) 엔진 설정이 없는 대체 화면으로 `<html lang>` 만 본다.
 *
 * 서버 셸은 요청 언어(세션 회원 언어 > Accept-Language > 사이트 기본 언어)를 찍고, 그 뒤 앱이
 * 브라우저 저장 언어·로그인 회원 언어로 문서 언어를 다시 맞춘다(TemplateApp `syncDocumentLang`).
 * 화면 셸 요청에는 API 토큰이 실리지 않으므로 서버가 토큰 회원의 언어를 알 수 없다 — 그 맞춤은 앱 몫이다.
 */
class ShellDocumentLangTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 각 테스트 전에 실행 — 일본어를 지원 언어에 넣는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.supported_locales' => ['ko', 'en', 'ja']]);
    }

    /**
     * 요청 언어 목록.
     *
     * @return array<string, array{0: string}> 요청 언어(Accept-Language) 데이터 세트
     */
    public static function requestLocaleProvider(): array
    {
        return [
            'en' => ['en'],
            'ja' => ['ja'],
        ];
    }

    /**
     * 비로그인 관리자 셸은 Accept-Language 의 언어로 `<html lang>` 을 찍는다.
     *
     * @scenario shell=admin, requester=guest
     *
     * @effects shell_lang_follows_request_language
     *
     * @param  string  $locale  요청 언어
     * @return void 반환값 없음
     */
    #[DataProvider('requestLocaleProvider')]
    public function test_guest_admin_shell_lang_follows_request_language(string $locale): void
    {
        $content = $this->get('/admin', ['Accept-Language' => $locale])->getContent();

        $this->assertStringContainsString('<html lang="'.$locale.'">', $content);
    }

    /**
     * 비로그인 사용자 셸은 Accept-Language 의 언어로 `<html lang>` 을 찍는다.
     *
     * @scenario shell=user, requester=guest
     *
     * @effects shell_lang_follows_request_language
     *
     * @param  string  $locale  요청 언어
     * @return void 반환값 없음
     */
    #[DataProvider('requestLocaleProvider')]
    public function test_guest_user_shell_lang_follows_request_language(string $locale): void
    {
        $content = $this->get('/', ['Accept-Language' => $locale])->getContent();

        $this->assertStringContainsString('<html lang="'.$locale.'">', $content);
    }

    /**
     * 세션 회원이면 브라우저 언어보다 회원 언어를 쓴다(SetLocale 우선순위).
     *
     * @scenario shell=admin, requester=session_member
     *
     * @effects shell_lang_follows_request_language
     *
     * @return void 반환값 없음
     */
    public function test_session_member_shell_lang_follows_member_language(): void
    {
        $member = User::factory()->create(['language' => 'ja']);

        $response = $this->actingAs($member)->get('/admin', ['Accept-Language' => 'en']);

        $content = $response->getContent();
        $this->assertStringContainsString('<html lang="ja">', $content);
    }
}
