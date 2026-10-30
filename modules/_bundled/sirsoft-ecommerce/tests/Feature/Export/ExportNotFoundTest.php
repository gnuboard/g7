<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 없는(또는 이미 지운) 내보내기 작업 — 작업 번호를 받는 모든 API 가 다른 이커머스 API 와 같은 꼴(`success:false` + 사용자 언어 문구)로
 * 404 를 돌려주는지 잠근다. 프레임워크 기본 문구(영어, 내부 모델 경로 포함)가 화면 안내로 새지 않아야 한다.
 */
class ExportNotFoundTest extends ExportTestCase
{
    protected User $admin;

    /**
     * 내보내기 전 권한 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.exports.create',
            'sirsoft-ecommerce.exports.read',
            'sirsoft-ecommerce.exports.download',
            'sirsoft-ecommerce.exports.delete',
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.shipping-policies.read',
        ]);
    }

    /**
     * 작업 번호를 받는 라우트 — [메서드, 하위 경로]
     *
     * @return array<string, array{0: string, 1: string}> 라우트 목록
     */
    public static function routes(): array
    {
        return [
            'show' => ['GET', ''],
            'download' => ['GET', '/download'],
            'download-link' => ['POST', '/download-link'],
            'downloads' => ['GET', '/downloads'],
            'retry' => ['POST', '/retry'],
            'destroy' => ['DELETE', ''],
        ];
    }

    /**
     * 응답이 이 모듈의 「찾을 수 없음」 꼴인지 확인합니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  주소
     * @return void 반환값 없음
     */
    private function assertExportNotFound(string $method, string $uri): void
    {
        $response = $this->actingAs($this->admin)->json($method, $uri, [], ['Accept-Language' => 'ko']);

        $response->assertStatus(404)->assertJsonPath('success', false);
        $message = (string) $response->json('message');
        $this->assertSame(__('sirsoft-ecommerce::messages.export.not_found', [], 'ko'), $message);
        $this->assertStringNotContainsString('No query results', $message);
        $this->assertStringNotContainsString('Models\\Export', $message);
    }

    /**
     * 없는 작업 번호는 모든 작업 API 에서 이 모듈 문구의 404 다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $suffix  하위 경로
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects missing_export_returns_module_not_found
     */
    #[DataProvider('routes')]
    public function test_unknown_export_id_returns_module_not_found(string $method, string $suffix): void
    {
        $this->assertExportNotFound($method, $this->exportsUrl('/999999999'.$suffix));
    }

    /**
     * 다른 운영자가 방금 지운 작업(소프트 삭제)도 같은 404 다 — 화면을 새로 고치지 않은 채 그 행을 누른 경우.
     *
     * @return void 반환값 없음
     *
     * @scenario action=bulk_delete
     *
     * @effects missing_export_returns_module_not_found
     */
    public function test_deleted_export_returns_module_not_found(): void
    {
        $export = Export::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'orders',
            'format' => 'csv',
            'status' => ExportStatusEnum::FAILED->value,
            'requested_by' => $this->admin->id,
            'options' => ['fields' => ['order.order_number']],
            'requested_at' => now(),
        ]);
        $export->delete();

        foreach (self::routes() as [$method, $suffix]) {
            $this->assertExportNotFound($method, $this->exportsUrl("/{$export->id}{$suffix}"));
        }
    }

    /**
     * 일본어 응답을 받을 수 있게 지원 언어에 ja 를 더하고, 번들 일본어 언어팩의 두 「찾을 수 없음」 문구를 번역기에 싣습니다.
     *
     * 테스트 환경에는 일본어 언어팩이 설치돼 있지 않으므로 사이트가 언어팩을 켰을 때와 같은 상태를 언어팩 파일 그대로 만든다.
     *
     * @return void 반환값 없음
     */
    private function enableJapanese(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);
        $ja = include base_path('lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/backend/ja/messages.php');
        app('translator')->addLines([
            'messages.export.not_found' => $ja['export']['not_found'],
            'messages.shipping_policy.not_found' => $ja['shipping_policy']['not_found'],
        ], 'ja', 'sirsoft-ecommerce');
    }

    /**
     * 요청 언어별 경우 — [회원 언어 설정, 기대 언어]
     *
     * 요청 언어는 회원 언어 설정이 먼저다(`SetLocale`: 회원 언어 → Accept-Language → 사이트 기본). 회원 언어 칸은 비울 수 없으므로
     * 로그인한 운영자는 늘 자기 언어 설정을 따른다. 헤더는 사이트 기본(ko)과 다른 값을 실어 회원 언어가 이기는지도 본다.
     *
     * @return array<string, array{0: string, 1: string}> 경우 목록
     */
    public static function requestLocales(): array
    {
        return [
            '회원 언어 en' => ['en', 'en'],
            '회원 언어 ja' => ['ja', 'ja'],
        ];
    }

    /**
     * 다음 요청을 새 요청처럼 사이트 기본 언어에서 시작하게 합니다.
     *
     * 시험은 요청 사이에 앱을 다시 만들지 않아 앞 요청이 정한 언어가 남는다(`App::setLocale()` 은 `app.locale` 설정값까지
     * 바꾼다) — 실제 서버는 요청마다 사이트 기본 언어에서 시작한다.
     *
     * @param  string  $siteLocale  첫 요청 전에 잡아 둔 사이트 기본 언어
     * @return void 반환값 없음
     */
    private function startFromSiteLocale(string $siteLocale): void
    {
        app()->setLocale($siteLocale);
    }

    /**
     * 없는 작업의 404 문구는 다른 이커머스 API(배송정책 상세 — 컨트롤러가 찾는 쪽)와 같은 요청 언어로 나온다.
     *
     * 작업 번호 바인딩은 요청 언어를 정하는 단계보다 먼저 실패하므로, 실패 응답도 같은 언어 결정 규칙(회원 언어 → Accept-Language →
     * 사이트 기본)을 거쳐야 한다.
     *
     * @param  string  $userLanguage  회원 언어 설정
     * @param  string  $expected  기대 언어
     * @return void 반환값 없음
     *
     * @scenario action=download
     *
     * @effects missing_export_returns_module_not_found
     */
    #[DataProvider('requestLocales')]
    public function test_not_found_message_follows_request_language(string $userLanguage, string $expected): void
    {
        $siteLocale = (string) config('app.locale');
        $this->enableJapanese();
        $this->admin->forceFill(['language' => $userLanguage])->save();
        $headers = ['Accept-Language' => $siteLocale];
        $this->assertNotSame($expected, $siteLocale, '사이트 기본 언어와 같으면 이 경우는 언어를 가르지 못한다');

        // 대조군 — 같은 운영자·같은 헤더로 배송정책 상세의 404 가 그 언어로 나온다
        $this->startFromSiteLocale($siteLocale);
        $control = $this->actingAs($this->admin)->getJson('/api/modules/sirsoft-ecommerce/admin/shipping-policies/999999999', $headers);
        $control->assertStatus(404);
        $this->assertSame(__('sirsoft-ecommerce::messages.shipping_policy.not_found', [], $expected), $control->json('message'), '대조군 언어');

        $want = __('sirsoft-ecommerce::messages.export.not_found', [], $expected);
        $this->assertNotSame(__('sirsoft-ecommerce::messages.export.not_found', [], 'ko'), $want, '기대 문구가 한국어와 같으면 이 경우는 언어를 가르지 못한다');

        foreach (self::routes() as $name => [$method, $suffix]) {
            $this->startFromSiteLocale($siteLocale);
            $response = $this->actingAs($this->admin)->json($method, $this->exportsUrl('/999999999'.$suffix), [], $headers);
            $response->assertStatus(404)->assertJsonPath('success', false);
            $this->assertSame($want, $response->json('message'), "{$name}: {$expected} 문구가 아닙니다");
        }
    }
}
