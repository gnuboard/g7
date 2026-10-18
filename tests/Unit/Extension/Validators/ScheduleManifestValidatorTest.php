<?php

namespace Tests\Unit\Extension\Validators;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionScheduleSyncHelper;
use App\Extension\Validators\ScheduleManifestValidator;
use App\Jobs\GenerateSitemapJob;
use Tests\TestCase;

/**
 * 스케줄 선언 검증기 테스트.
 *
 * 검증 입력은 동기화 헬퍼가 정규화한 DB 속성 모양이다. 명령 보안 규칙(거부 목록·코어 허용목록)과
 * 선언으로 받을 수 있는 유형(artisan·job)·잠금 분 범위를 고정한다.
 *
 * 조합은 메서드마다 @scenario 로 단다(매니페스트 schedule-declaration-sync.yaml).
 */
class ScheduleManifestValidatorTest extends TestCase
{
    private ScheduleManifestValidator $validator;

    private ExtensionScheduleSyncHelper $helper;

    /**
     * 검증기와 정규화 헬퍼를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = $this->app->make(ScheduleManifestValidator::class);
        $this->helper = $this->app->make(ExtensionScheduleSyncHelper::class);
    }

    /**
     * 선언을 정규화해 검증하고 위반 사유를 돌려줍니다.
     *
     * @param  array<string, mixed>  $definition  선언
     * @param  ExtensionOwnerType  $owner  소유 타입
     * @return array<int, string> 위반 사유
     */
    private function errorsFor(array $definition, ExtensionOwnerType $owner = ExtensionOwnerType::Module): array
    {
        return $this->validator->errors($this->helper->normalizeDefinition($definition), $owner);
    }

    /**
     * 코어 카탈로그 32건은 모두 통과한다.
     *
     * @scenario declaration_shape=new_schema, owner=core, sync_run=first
     *
     * @effects catalog_gc_commands_are_allowlisted
     *
     * @return void 반환값 없음
     */
    public function test_core_catalog_passes(): void
    {
        foreach (config('core.schedules') as $name => $definition) {
            $this->assertSame([], $this->errorsFor($definition + ['name' => $name], ExtensionOwnerType::Core), $name);
        }
    }

    /**
     * 거부 목록·거부 접두사 명령은 누구든 거부, 코어는 허용목록 밖도 거부, 확장은 허용목록 밖을 받는다.
     *
     * @scenario declaration_shape=invalid, owner=module, sync_run=first
     *
     * @effects validator_rejects_denylisted_and_unlisted_core_commands
     *
     * @return void 반환값 없음
     */
    public function test_command_security_rules(): void
    {
        $base = ['name' => 'probe', 'expression' => '0 4 * * *'];

        $this->assertNotSame([], $this->errorsFor($base + ['command' => 'migrate:fresh']));
        $this->assertNotSame([], $this->errorsFor($base + ['command' => 'make:model Foo']));
        $this->assertNotSame([], $this->errorsFor($base + ['command' => 'tinker'], ExtensionOwnerType::Core));
        $this->assertNotSame([], $this->errorsFor($base + ['command' => 'vendor-probe:run'], ExtensionOwnerType::Core));
        $this->assertSame([], $this->errorsFor($base + ['command' => 'vendor-probe:run --scheduled']));
        $this->assertSame([], $this->errorsFor($base + ['command' => 'cache:clear'], ExtensionOwnerType::Core));
    }

    /**
     * shell·url 은 선언으로 받지 않고, job 은 존재하는 클래스만 받는다.
     *
     * @scenario declaration_shape=invalid, owner=module, sync_run=first
     *
     * @effects validator_rejects_shell_and_url_declarations
     *
     * @return void 반환값 없음
     */
    public function test_declaration_types(): void
    {
        $base = ['name' => 'probe', 'expression' => '0 4 * * *'];

        $this->assertNotSame([], $this->errorsFor($base + ['type' => 'shell', 'command' => 'php /x.php']));
        $this->assertNotSame([], $this->errorsFor($base + ['type' => 'url', 'command' => 'https://example.com']));
        $this->assertNotSame([], $this->errorsFor($base + ['type' => 'job', 'command' => 'App\\Jobs\\NoSuchJob']));
        $this->assertSame([], $this->errorsFor($base + ['type' => 'job', 'command' => GenerateSitemapJob::class]));
    }

    /**
     * 잠금 분은 0~10080, cron·이름·시간대·조건 형식을 검사한다.
     *
     * @scenario declaration_shape=invalid, owner=module, sync_run=first
     *
     * @effects validator_bounds_overlap_minutes
     *
     * @return void 반환값 없음
     */
    public function test_field_bounds(): void
    {
        $base = ['name' => 'probe', 'command' => 'cache:clear', 'expression' => '0 4 * * *'];

        $this->assertSame([], $this->errorsFor($base + ['without_overlapping' => 10080]));
        $this->assertNotSame([], $this->errorsFor($base + ['without_overlapping' => 10081]));
        $this->assertNotSame([], $this->errorsFor(['expression' => 'every day'] + $base));
        $this->assertNotSame([], $this->errorsFor(['name' => 'Bad Name'] + $base));
        $this->assertNotSame([], $this->errorsFor($base + ['timezone' => 'Mars/Base']));
        $this->assertNotSame([], $this->errorsFor($base + ['enabled_condition' => [['source' => 'env', 'key' => 'X', 'operator' => 'truthy']]]));
        $this->assertNotSame([], $this->errorsFor($base + ['dispatch_mode' => 'later']));
        $this->assertSame([], $this->errorsFor($base + ['without_overlapping' => true]), 'boolean true 는 1440분으로 정규화');
    }
}
