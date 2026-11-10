<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\HookManager;
use Modules\Sirsoft\Board\Listeners\ProvideNecessaryStorageKeysListener;
use Modules\Sirsoft\Board\Support\GuestUploadOwnerCookie;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 개인정보 동의 플러그인에 비밀글 열람 토큰 키를 필수 저장 키로 제공하는 리스너 검증 (#789)
 *
 * 동의 플러그인은 동의 전에는 허용목록 밖의 저장 키를 부팅마다 지운다. 이 모듈이 열람 토큰 키를
 * 그 플러그인의 필터 훅에 덧붙이지 않으면, 비밀번호를 맞힌 방문자의 토큰이 저장되지 않아 답변 링크
 * 이동·새로고침마다 다시 잠긴다 — 오류도 로그도 남지 않는다(라이브 실측). 반대로 키 이름이 실제
 * 저장 코드와 어긋나면 제공해도 아무것도 지켜지지 않으므로 원문과 대조한다.
 *
 * @scenario link=board-necessary-storage-provision
 */
class ProvideNecessaryStorageKeysListenerTest extends ModuleTestCase
{
    /** @var string 동의 플러그인이 소유한 계약 훅 */
    private const HOOK = 'sirsoft-gdpr.necessary_storage.filter_provided_keys';

    /** @var array<string, array<int, string>> 이 확장이 제공해야 하는 키 */
    private const EXPECTED = [
        'sessionStorage' => [
            'g7_board_secret_view_token',
            'g7_board_secret_view_expires_at',
        ],
    ];

    /** @var array<string, string> 키 => 그 키를 실제로 저장하는 소스 (이 확장 루트 기준) */
    private const WRITERS = [
        'g7_board_secret_view_token' => '../../../templates/_bundled/sirsoft-basic/src/handlers/storageHandlers.ts',
        'g7_board_secret_view_expires_at' => '../../../templates/_bundled/sirsoft-basic/src/handlers/storageHandlers.ts',
    ];

    /**
     * 훅을 filter 로 구독합니다.
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_subscribes_to_consent_plugin_filter_hook
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_to_the_consent_plugin_filter_hook(): void
    {
        $hooks = ProvideNecessaryStorageKeysListener::getSubscribedHooks();

        $this->assertArrayHasKey(self::HOOK, $hooks);
        $this->assertSame('filter', $hooks[self::HOOK]['type']);
        $this->assertSame('provide', $hooks[self::HOOK]['method']);
    }

    /**
     * 자기 키를 덧붙이되 앞선 리스너가 모은 키는 그대로 두고 중복은 만들지 않습니다.
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_appends_own_keys_without_dropping_others
     *
     * @return void 반환값 없음
     */
    public function test_appends_own_keys_without_dropping_others(): void
    {
        $firstKey = self::EXPECTED['sessionStorage'][0];
        $input = [
            'localStorage' => ['other_ext_key'],
            'sessionStorage' => ['g7_guest_order_token', $firstKey],
            'cookie' => ['other_cookie'],
        ];

        $result = (new ProvideNecessaryStorageKeysListener)->provide($input);

        foreach (self::EXPECTED['sessionStorage'] as $key) {
            $this->assertContains($key, $result['sessionStorage']);
        }
        $this->assertContains('g7_guest_order_token', $result['sessionStorage']);
        $this->assertSame(['other_ext_key'], $result['localStorage']);
        // 쿠키 스코프에는 앞선 값을 지키고 비회원 업로드 소유 쿠키만 더한다(#883)
        $this->assertSame(['other_cookie', 'g7_board_guest_upload'], $result['cookie']);
        $this->assertSame(1, count(array_keys($result['sessionStorage'], $firstKey, true)));
    }

    /**
     * 배열이 아닌 값을 받아도 자기 키만 담은 배열을 돌려줍니다.
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_appends_own_keys_without_dropping_others
     *
     * @return void 반환값 없음
     */
    public function test_tolerates_non_array_input(): void
    {
        $result = (new ProvideNecessaryStorageKeysListener)->provide(null);

        $this->assertSame(self::EXPECTED['sessionStorage'], $result['sessionStorage']);
    }

    /**
     * 리스너를 훅 체인에 걸면 키가 체인 결과에 실립니다.
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_appends_own_keys_without_dropping_others
     *
     * @return void 반환값 없음
     */
    public function test_keys_arrive_through_the_hook_chain(): void
    {
        $callback = [new ProvideNecessaryStorageKeysListener, 'provide'];
        HookManager::addFilter(self::HOOK, $callback);

        try {
            $result = HookManager::applyFilters(self::HOOK, ['localStorage' => [], 'sessionStorage' => [], 'cookie' => []]);
        } finally {
            HookManager::removeFilter(self::HOOK, $callback);
        }

        foreach (self::EXPECTED['sessionStorage'] as $key) {
            $this->assertContains($key, $result['sessionStorage']);
        }
    }

    /**
     * 제공 키가 그 키를 실제로 저장하는 코드의 문자열과 같습니다 (어긋나면 제공해도 지켜지지 않는다).
     *
     * @scenario scope=sessionStorage, consent_plugin=installed
     *
     * @effects provider_keys_match_the_storage_writing_code
     *
     * @return void 반환값 없음
     */
    public function test_keys_match_the_storage_writing_code(): void
    {
        $root = dirname(__DIR__, 3);
        $provided = array_values(array_unique(array_merge(...array_values(self::EXPECTED))));

        $this->assertSame(array_keys(self::WRITERS), $provided);

        foreach (self::WRITERS as $key => $relative) {
            $source = file_get_contents($root.'/'.$relative);
            $this->assertIsString($source, $relative.' 를 읽지 못했습니다.');
            $this->assertMatchesRegularExpression(
                '/[\'"]'.preg_quote($key, '/').'[\'"]/',
                $source,
                "{$key} 가 {$relative} 의 저장 키 문자열과 어긋납니다."
            );
        }
    }

    /**
     * 비회원 업로드 소유 쿠키를 필수 쿠키로 제공합니다 (#883 2단계 실측 결함).
     *
     * 동의 플러그인은 기능 쿠키에 동의하지 않은 방문자의 응답에서 허용목록 밖 쿠키를 모두 걷어낸다. 이 쿠키가
     * 빠지면 비회원은 자기가 방금 올린 임시 첨부를 지우지 못하고(403) 순서도 못 바꾼다 — 쿠키 배너를 고르지 않은
     * 비회원 전원이 겪는다(라이브 실측: 업로드 응답 Set-Cookie 0, 본인 삭제 403). 이 쿠키는 방문자가 스스로 요청한
     * 업로드를 이어 가는 데만 쓰는 소유 증명이다(서비스 제공). 이름은 모듈 설정을 따르므로 쿠키 도우미와 대조한다.
     *
     * @scenario scope=cookie, consent_plugin=installed
     *
     * @effects provider_keys_match_the_storage_writing_code
     *
     * @return void 반환값 없음
     */
    public function test_provides_guest_upload_owner_cookie(): void
    {
        $result = (new ProvideNecessaryStorageKeysListener)->provide(['cookie' => ['other_cookie']]);

        $this->assertSame('g7_board_guest_upload', app(GuestUploadOwnerCookie::class)->name());
        $this->assertContains(app(GuestUploadOwnerCookie::class)->name(), $result['cookie']);
        $this->assertContains('other_cookie', $result['cookie']);

        config(['sirsoft-board.guest_upload_cookie.name' => 'renamed_guest_upload']);
        $renamed = (new ProvideNecessaryStorageKeysListener)->provide([]);
        $this->assertSame(['renamed_guest_upload'], $renamed['cookie'], '설정으로 바꾼 이름을 따라야 한다');
    }

    /**
     * 리스너가 동의 플러그인의 클래스를 참조하지 않습니다 (미설치여도 이 확장이 선다).
     *
     * @scenario scope=sessionStorage, consent_plugin=absent
     *
     * @effects provider_does_not_reference_consent_plugin_classes
     *
     * @return void 반환값 없음
     */
    public function test_does_not_reference_consent_plugin_classes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Listeners/ProvideNecessaryStorageKeysListener.php');

        $this->assertStringNotContainsString('Plugins\\Sirsoft\\Gdpr', $source);
        $this->assertStringNotContainsString('class_exists', $source);
    }
}
