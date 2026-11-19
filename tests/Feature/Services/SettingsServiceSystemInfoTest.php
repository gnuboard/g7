<?php

namespace Tests\Feature\Services;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\SystemInfoSectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 시스템 정보 응답의 직렬화 계약 테스트.
 *
 * 배경 (gnuboard/g7#62 와 동형):
 *   `getSystemInfo()` 는 외부 프로세스 출력(powershell/wmic CPU 조회)과 환경값을
 *   한 배열에 모아 관리자 환경설정 화면으로 내보낸다. 그 안에 invalid UTF-8 이
 *   섞이면 Laravel JsonResponse 직렬화가 Malformed UTF-8 로 실패해 화면이 500 이 된다.
 *   `safeSystemProbe` 는 probe 예외만 잡고 직렬화 예외는 잡지 못한다.
 *
 * 단위 테스트(SettingsServiceCpuInfoTest)는 CPU 필드 하나의 정규화 계약만 본다.
 * 이 테스트는 **페이로드 전체**가 직렬화 가능한지를 잠가, 나중에 다른 probe 가
 * 외부 출력을 그대로 싣더라도 같은 결함이 재발하지 않게 한다.
 *
 * DB 스키마를 건드리지 않으므로 RefreshDatabase 를 쓰지 않는다(API 응답 시험이 만드는 관리자 계정은 트랜잭션으로 되돌린다).
 *
 * 확장 카드(#888): 확장은 필터 훅 `core.settings.filter_system_info_sections` 로 응답의 `extra_sections` 에 카드를 더한다.
 * 형식이 틀린 섹션은 버리고 경고를 남기며, 리스너가 던지면 그 리스너 섹션만 빠지고 응답은 200 이다. 코어 정보는 1시간
 * 캐시지만 확장 섹션은 캐시 밖에서 매 요청 새로 계산한다.
 */
class SettingsServiceSystemInfoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 시험마다 확장 카드 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::clearFilter(SystemInfoSectionService::HOOK);
    }

    /**
     * 시험이 등록한 확장 카드 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(SystemInfoSectionService::HOOK);

        parent::tearDown();
    }

    /**
     * 리스너가 없으면 확장 섹션은 빈 배열이다.
     *
     * @scenario case=no_listeners
     *
     * @effects extra_sections_empty_without_listeners
     *
     * @return void 반환값 없음
     */
    public function test_extra_sections_empty_without_listeners(): void
    {
        $info = app(SettingsService::class)->getSystemInfo();

        $this->assertArrayHasKey('extra_sections', $info);
        $this->assertSame([], $info['extra_sections']);
    }

    /**
     * 리스너 둘이 더한 정상 섹션이 등록 순서대로, 정규화된 모양으로 실린다.
     *
     * @scenario case=valid_sections
     *
     * @effects extra_sections_listed_in_order_normalized
     *
     * @return void 반환값 없음
     */
    public function test_valid_sections_are_listed_in_order_and_normalized(): void
    {
        HookManager::addFilter(SystemInfoSectionService::HOOK, function (array $sections): array {
            $sections[] = [
                'id' => 'acme-server_status.overview',
                'title' => '서버 상태',
                'badge' => ['text' => '정상', 'tone' => 'success'],
                'rows' => [['label' => '큐 대기', 'value' => 3], ['label' => '호스트', 'value' => 'web-1', 'mono' => true]],
                'unknown_key' => 'dropped',
            ];

            return $sections;
        });

        $first = app(SettingsService::class)->getSystemInfo()['extra_sections'];
        $this->assertCount(1, $first);
        $this->assertSame([
            'id' => 'acme-server_status.overview',
            'title' => '서버 상태',
            'badge' => ['text' => '정상', 'tone' => 'success'],
            'rows' => [
                ['label' => '큐 대기', 'value' => '3', 'mono' => false],
                ['label' => '호스트', 'value' => 'web-1', 'mono' => true],
            ],
            'list' => null,
            'notice' => null,
        ], $first[0]);

        HookManager::addFilter(SystemInfoSectionService::HOOK, function (array $sections): array {
            $sections[] = [
                'id' => 'acme-backup_monitor:last_run',
                'title' => '백업',
                'rows' => [],
                'list' => ['title' => '최근 실패', 'items' => ['a', 'b'], 'collapsible' => true],
                'notice' => ['tone' => 'warning', 'text' => '어제 백업이 실패했습니다'],
            ];

            return $sections;
        }, 20);

        $second = app(SettingsService::class)->getSystemInfo()['extra_sections'];
        $this->assertSame(['acme-server_status.overview', 'acme-backup_monitor:last_run'], array_column($second, 'id'));
        $this->assertSame(['title' => '최근 실패', 'items' => ['a', 'b'], 'collapsible' => true], $second[1]['list']);
        $this->assertEquals(['tone' => 'warning', 'text' => '어제 백업이 실패했습니다'], $second[1]['notice']);
        $this->assertNull($second[1]['badge']);
    }

    /**
     * 형식이 틀린 섹션(그리고 id 가 겹치는 뒤 섹션)은 버리고 경고를 남기며, 맞는 섹션은 남긴다.
     *
     * @scenario case=malformed_sections
     *
     * @effects malformed_sections_dropped_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_malformed_sections_are_dropped_with_warning(): void
    {
        Log::spy();

        HookManager::addFilter(SystemInfoSectionService::HOOK, function (array $sections): array {
            return array_merge($sections, [
                ['id' => 'acme-server_status.ok', 'title' => '정상', 'rows' => []],
                ['id' => 'no prefix', 'title' => '식별자 형식 틀림', 'rows' => []],
                ['id' => 'acme-server_status.no_title', 'rows' => []],
                ['id' => 'acme-server_status.bad_rows', 'title' => '행 틀림', 'rows' => [['label' => '값 없음']]],
                ['id' => 'acme-server_status.bad_tone', 'title' => '색조 틀림', 'rows' => [], 'badge' => ['text' => 'x', 'tone' => 'purple']],
                ['id' => 'acme-server_status.bad_list', 'title' => '목록 틀림', 'rows' => [], 'list' => ['title' => 't', 'items' => [['nested']], 'collapsible' => false]],
                ['id' => 'acme-server_status.html_mono', 'title' => '고정폭 값 형식 틀림', 'rows' => [['label' => 'a', 'value' => 'b', 'mono' => 'yes']]],
                ['id' => 'acme-server_status.ok', 'title' => '겹친 id', 'rows' => []],
                'not an array',
            ]);
        });

        $sections = app(SettingsService::class)->getSystemInfo()['extra_sections'];

        $this->assertSame(['acme-server_status.ok'], array_column($sections, 'id'));
        $this->assertSame('정상', $sections[0]['title']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => ($context['hook'] ?? null) === SystemInfoSectionService::HOOK)
            ->times(8);
    }

    /**
     * 리스너 하나가 던지면 그 리스너 섹션만 빠지고, 시스템 정보 API 는 200 으로 다른 섹션을 싣는다.
     *
     * @scenario case=throwing_listener
     *
     * @effects throwing_listener_isolated_response_ok
     *
     * @return void 반환값 없음
     */
    public function test_throwing_listener_only_drops_its_section_and_api_stays_ok(): void
    {
        Exceptions::fake();

        HookManager::addFilter(SystemInfoSectionService::HOOK, function (array $sections): array {
            $sections[] = ['id' => 'acme-server_status.before', 'title' => '앞', 'rows' => []];

            return $sections;
        }, 5);
        HookManager::addFilter(SystemInfoSectionService::HOOK, function (): array {
            throw new \RuntimeException('서버 상태 플러그인 오류');
        }, 10);
        HookManager::addFilter(SystemInfoSectionService::HOOK, function (array $sections): array {
            $sections[] = ['id' => 'acme-server_status.after', 'title' => '뒤', 'rows' => []];

            return $sections;
        }, 15);

        $response = $this->actingAsSettingsReader()->getJson('/api/admin/settings/system-info');

        $response->assertOk();
        $this->assertSame(
            ['acme-server_status.before', 'acme-server_status.after'],
            array_column($response->json('data.extra_sections'), 'id'),
        );
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === '서버 상태 플러그인 오류');
    }

    /**
     * 코어 정보가 캐시에서 나와도 확장 섹션은 매 요청 새로 계산된다.
     *
     * @scenario case=cached_core_info
     *
     * @effects extra_sections_recomputed_outside_cache
     *
     * @return void 반환값 없음
     */
    public function test_sections_are_recomputed_even_when_core_info_is_cached(): void
    {
        $counter = 0;
        HookManager::addFilter(SystemInfoSectionService::HOOK, function (array $sections) use (&$counter): array {
            $counter++;
            $sections[] = ['id' => 'acme-server_status.counter', 'title' => '호출', 'rows' => [['label' => '횟수', 'value' => $counter]]];

            return $sections;
        });

        $service = app(SettingsService::class);
        $first = $service->getSystemInfo();
        $second = $service->getSystemInfo();

        // 코어 정보는 같은 캐시 값이다 — 같은 서버 시각 밖의 진단값이 같아야 한다.
        $this->assertSame($first['php_version'], $second['php_version']);
        $this->assertSame('1', $first['extra_sections'][0]['rows'][0]['value']);
        $this->assertSame('2', $second['extra_sections'][0]['rows'][0]['value']);
        $this->assertSame(2, $counter);
    }

    /**
     * 시스템 정보 읽기 권한(`core.settings.read`)을 가진 관리자로 인증합니다.
     *
     * @return static 인증 헤더가 붙은 테스트 케이스
     */
    private function actingAsSettingsReader(): static
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
        $permission = Permission::firstOrCreate(['identifier' => 'core.settings.read'], [
            'name' => json_encode(['ko' => 'core.settings.read', 'en' => 'core.settings.read']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => 'admin',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 시스템 정보 페이로드 전체가 JSON 으로 직렬화된다 (외부 프로세스 출력의 깨진 UTF-8 로 화면이 500 이 되지 않는다).
     *
     * @scenario case=payload_json_serializable
     *
     * @effects system_info_payload_json_serializable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function system_info_payload_is_always_json_serializable(): void
    {
        $info = app(SettingsService::class)->getSystemInfo();

        $this->assertNotSame([], $info, '시스템 정보 페이로드가 비어 있으면 안 된다.');

        $encoded = json_encode($info, JSON_UNESCAPED_UNICODE);

        $this->assertNotFalse(
            $encoded,
            'getSystemInfo() 페이로드 직렬화 실패: '.json_last_error_msg()
                .' — invalid UTF-8 필드: '.implode(', ', $this->invalidUtf8Paths($info))
        );
    }

    /**
     * 시스템 정보의 모든 문자열이 올바른 UTF-8 이다.
     *
     * @scenario case=payload_valid_utf8
     *
     * @effects system_info_strings_valid_utf8
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_string_in_system_info_is_valid_utf8(): void
    {
        $info = app(SettingsService::class)->getSystemInfo();

        $this->assertSame(
            [],
            $this->invalidUtf8Paths($info),
            '시스템 정보에 유효하지 않은 UTF-8 문자열이 있으면 관리자 화면이 500 이 된다.'
        );
    }

    /**
     * invalid UTF-8 문자열이 있는 키 경로를 점 표기로 수집합니다.
     *
     * @param  mixed  $value  검사할 값
     * @param  string  $prefix  상위 경로
     * @return array<int, string> 점 구분 경로 목록
     */
    private function invalidUtf8Paths(mixed $value, string $prefix = ''): array
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? [] : [$prefix === '' ? '(root)' : $prefix];
        }

        if (! is_array($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $key => $item) {
            $label = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $paths = array_merge($paths, $this->invalidUtf8Paths($item, $label));
        }

        return $paths;
    }
}
