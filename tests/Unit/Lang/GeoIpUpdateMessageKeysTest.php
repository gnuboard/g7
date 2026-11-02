<?php

namespace Tests\Unit\Lang;

use App\Http\Controllers\Api\Admin\GeoIpController;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 환경설정 > 고급 > GeoIP [갱신] 응답 message 키의 번역 존재 회귀 시험.
 *
 * 컨트롤러가 돌려주는 `settings.geoip.*` 키에 번역이 없으면 관리자 토스트에
 * 키 문자열이 그대로 보인다.
 */
class GeoIpUpdateMessageKeysTest extends TestCase
{
    /**
     * 컨트롤러가 쓰는 메시지 키 목록(성공 키 + 서비스 상태별 실패 키).
     *
     * @return array<int, string> 메시지 키
     */
    private function messageKeys(): array
    {
        $controller = (new \ReflectionClass(GeoIpController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(GeoIpController::class, 'mapStatusToMessageKey');

        $keys = ['settings.geoip.update_success'];
        foreach (['missing_license_key', 'unauthorized', 'connection_failed', 'download_failed', 'unknown'] as $status) {
            $keys[] = $method->invoke($controller, $status);
        }

        return array_values(array_unique($keys));
    }

    /**
     * 코어 lang 디렉토리에 있는 로케일 목록을 제공합니다.
     *
     * @return array<string, array{0: string}> 로케일 데이터셋
     */
    public static function coreLocales(): array
    {
        return ['ko' => ['ko'], 'en' => ['en']];
    }

    /**
     * 코어 로케일(ko·en)에 GeoIP 갱신 메시지 키가 모두 있는지 확인합니다.
     *
     * @scenario locale_source=core_lang
     *
     * @effects geoip_update_messages_translated
     *
     * @param  string  $locale  검사할 로케일
     * @return void 반환값 없음
     */
    #[DataProvider('coreLocales')]
    public function test_core_locale_has_every_geoip_message_key(string $locale): void
    {
        foreach ($this->messageKeys() as $key) {
            $this->assertTrue(Lang::hasForLocale($key, $locale), "{$locale} 번역 없음: {$key}");
        }
    }

    /**
     * 동봉 일본어 코어 언어팩에 GeoIP 갱신 메시지 키가 모두 있는지 확인합니다.
     *
     * @scenario locale_source=bundled_language_pack
     *
     * @effects geoip_update_messages_translated
     *
     * @return void 반환값 없음
     */
    public function test_bundled_japanese_language_pack_has_every_geoip_message_key(): void
    {
        $messages = require base_path('lang-packs/_bundled/g7-core-ja/backend/ja/settings.php');

        foreach ($this->messageKeys() as $key) {
            $this->assertTrue(Arr::has($messages, substr($key, strlen('settings.'))), "ja 번역 없음: {$key}");
        }
    }
}
