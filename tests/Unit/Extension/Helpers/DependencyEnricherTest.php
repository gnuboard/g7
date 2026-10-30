<?php

namespace Tests\Unit\Extension\Helpers;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Helpers\DependencyEnricher;
use App\Models\Module;
use App\Support\LocalizedValue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DependencyEnricherTest extends TestCase
{
    /**
     * enrich: 빈 의존성은 빈 배열을 반환합니다.
     *
     * @scenario case=empty
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_빈_의존성은_빈_배열을_반환합니다(): void
    {
        $result = DependencyEnricher::enrich([]);

        $this->assertSame([], $result);
    }

    /**
     * enrich: modules plugins 키가 없으면 빈 배열을 반환합니다.
     *
     * @scenario case=missing_type_keys
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_modules_plugins_키가_없으면_빈_배열을_반환합니다(): void
    {
        $this->assertSame([], DependencyEnricher::enrich(['foo' => 'bar']));
    }

    /**
     * enrich: 미설치 모듈은 installed version null 과 is met false를 반환합니다.
     *
     * @scenario case=not_installed
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_미설치_모듈은_installed_version_null_과_is_met_false를_반환합니다(): void
    {
        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn(null);
        $moduleRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        // manifest 도 없는 식별자 — 이름은 식별자로 대신한다(번들 manifest 이름은 아래 bundled_manifest_name 이 잠근다)
        $result = DependencyEnricher::enrich([
            'modules' => ['acme-unbundled' => '>=1.0.0'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('acme-unbundled', $result[0]['identifier']);
        $this->assertSame('acme-unbundled', $result[0]['name']);
        $this->assertSame('module', $result[0]['type']);
        $this->assertSame('>=1.0.0', $result[0]['required_version']);
        $this->assertNull($result[0]['installed_version']);
        $this->assertFalse($result[0]['is_active']);
        $this->assertFalse($result[0]['is_met']);
    }

    /**
     * enrich: 설치 활성 모듈은 is met true를 반환합니다.
     *
     * @scenario case=installed_active
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_설치_활성_모듈은_is_met_true를_반환합니다(): void
    {
        // 활성 여부는 레코드 status 로 본다 — 판정식이 한 번 조회로 상태·판을 함께 판정한다(#152)
        $module = new Module(['name' => ['ko' => '상점', 'en' => 'Shop'], 'version' => '1.2.0', 'status' => 'active']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn($module);
        $moduleRepo->method('findActiveByIdentifier')->willReturn($module);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-ecommerce' => '>=1.0.0'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('1.2.0', $result[0]['installed_version']);
        $this->assertTrue($result[0]['is_active']);
        $this->assertTrue($result[0]['is_met']);
    }

    /**
     * enrich: 설치되었지만 비활성 모듈은 is met false를 반환합니다.
     *
     * @scenario case=installed_inactive
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_설치되었지만_비활성_모듈은_is_met_false를_반환합니다(): void
    {
        $module = new Module(['name' => ['ko' => '상점'], 'version' => '1.2.0']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn($module);
        $moduleRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-ecommerce' => '>=1.0.0'],
        ]);

        $this->assertSame('1.2.0', $result[0]['installed_version']);
        $this->assertFalse($result[0]['is_active']);
        $this->assertFalse($result[0]['is_met']);
    }

    /**
     * enrich: 버전 미충족 모듈은 is met false를 반환합니다.
     *
     * @scenario case=version_unmet
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_버전_미충족_모듈은_is_met_false를_반환합니다(): void
    {
        $module = new Module(['name' => ['ko' => '상점'], 'version' => '0.9.0', 'status' => 'active']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn($module);
        $moduleRepo->method('findActiveByIdentifier')->willReturn($module);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-ecommerce' => '>=1.0.0'],
        ]);

        $this->assertSame('0.9.0', $result[0]['installed_version']);
        $this->assertTrue($result[0]['is_active']);
        $this->assertFalse($result[0]['is_met']);
    }

    /**
     * enrich: modules 와 plugins를 순서대로 반환합니다.
     *
     * @scenario case=type_order
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_modules_와_plugins를_순서대로_반환합니다(): void
    {
        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn(null);
        $moduleRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $pluginRepo->method('findByIdentifier')->willReturn(null);
        $pluginRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-board' => '>=1.0.0'],
            'plugins' => ['sirsoft-tosspayments' => '>=0.1.0'],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('module', $result[0]['type']);
        $this->assertSame('sirsoft-board', $result[0]['identifier']);
        $this->assertSame('plugin', $result[1]['type']);
        $this->assertSame('sirsoft-tosspayments', $result[1]['identifier']);
    }

    /**
     * enrich: 다국어 이름 배열에서 현재 로케일을 선택합니다.
     *
     * @scenario case=localized_name
     *
     * @effects dependency_status_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_다국어_이름_배열에서_현재_로케일을_선택합니다(): void
    {
        app()->setLocale('ko');

        $module = new Module(['name' => ['ko' => '게시판', 'en' => 'Board'], 'version' => '1.0.0']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturn($module);
        $moduleRepo->method('findActiveByIdentifier')->willReturn($module);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);

        $pluginRepo = $this->createMock(PluginRepositoryInterface::class);
        $this->app->instance(PluginRepositoryInterface::class, $pluginRepo);

        $result = DependencyEnricher::enrich([
            'modules' => ['sirsoft-board' => '*'],
        ]);

        $this->assertSame('게시판', $result[0]['name']);
    }

    /**
     * 이름이 빈 문자열·빈 다국어 값이면 식별자를 이름으로 쓴다 (7.1.0 전수 실측).
     *
     * 화면은 이 이름을 그대로 카드·상세·확인 창에 보여 주므로, 빈 문자열이 내려가면 이름 없는 칸만 남는다.
     *
     * @scenario case=blank_name
     *
     * @effects blank_dependency_name_falls_back_to_identifier
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function enrich_이름이_비면_식별자를_이름으로_씁니다(): void
    {
        $blankString = new Module(['version' => '1.0.0']);
        // DB 에 JSON 빈 문자열("")로 저장된 이름 — 배열 캐스트를 거쳐 다국어 배열이 아닌 빈 문자열로 읽힌다
        $blankString->setRawAttributes(['name' => '""', 'version' => '1.0.0']);
        $blankLocalized = new Module(['name' => ['ko' => '', 'en' => ''], 'version' => '1.0.0']);
        // 공백만 든 문자열 이름 — 다국어 값의 공백과 같게 빈 이름으로 본다
        $whitespaceString = new Module(['version' => '1.0.0']);
        $whitespaceString->setRawAttributes(['name' => '"  "', 'version' => '1.0.0']);

        $moduleRepo = $this->createMock(ModuleRepositoryInterface::class);
        $moduleRepo->method('findByIdentifier')->willReturnMap([
            ['acme-blank-string', $blankString],
            ['acme-blank-localized', $blankLocalized],
            ['acme-blank-whitespace', $whitespaceString],
        ]);
        $moduleRepo->method('findActiveByIdentifier')->willReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $moduleRepo);
        $this->app->instance(PluginRepositoryInterface::class, $this->createMock(PluginRepositoryInterface::class));

        $result = DependencyEnricher::enrich([
            'modules' => ['acme-blank-string' => '*', 'acme-blank-localized' => '*', 'acme-blank-whitespace' => '*'],
        ]);

        $this->assertSame('acme-blank-string', $result[0]['name']);
        $this->assertSame('acme-blank-localized', $result[1]['name']);
        $this->assertSame('acme-blank-whitespace', $result[2]['name']);
    }

    /**
     * 설치 기록이 없는 의존·연동 대상은 확장 폴더(활성 → 대기 → 번들)의 manifest 에서 현재 언어 이름을 읽는다.
     *
     * 설치 창의 「함께 쓰면 연동되는 확장」·「함께 설치」 직접 의존 줄, 설치 결과 연동 안내, 사이트 503 화면의 미설치 의존 이름이
     * 모두 이 이름을 쓴다. 종전에는 기록이 없으면 식별자를 돌려줘 「gnuboard7-hello_plugin (gnuboard7-hello_plugin)」 처럼 보였다.
     *
     * @scenario case=bundled_manifest_name
     *
     * @effects uninstalled_dependency_name_from_bundled_manifest
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 미설치_의존과_연동_대상은_번들_manifest_이름을_씁니다(): void
    {
        $this->bindEmptyRepositories();

        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);

            $dependencies = DependencyEnricher::enrich([
                'modules' => ['sirsoft-page' => '*'],
                'plugins' => ['sirsoft-koreaexim' => '*'],
            ]);
            $this->assertSame($this->manifestName('modules', 'sirsoft-page', 'module.json', $locale), $dependencies[0]['name'], $locale);
            $this->assertSame($this->manifestName('plugins', 'sirsoft-koreaexim', 'plugin.json', $locale), $dependencies[1]['name'], $locale);

            $integrations = DependencyEnricher::enrichIntegrations([
                'modules' => ['sirsoft-page' => '*'],
                'plugins' => ['sirsoft-koreaexim' => '*', 'acme-unbundled' => '*'],
                'templates' => ['gnuboard7-hello_user_template' => '*'],
            ]);
            $names = array_column($integrations, 'name', 'identifier');
            $this->assertSame($this->manifestName('modules', 'sirsoft-page', 'module.json', $locale), $names['sirsoft-page'], $locale);
            $this->assertSame($this->manifestName('plugins', 'sirsoft-koreaexim', 'plugin.json', $locale), $names['sirsoft-koreaexim'], $locale);
            $this->assertSame($this->manifestName('templates', 'gnuboard7-hello_user_template', 'template.json', $locale), $names['gnuboard7-hello_user_template'], $locale);
            $this->assertSame('acme-unbundled', $names['acme-unbundled'], 'manifest 가 없으면 식별자');
        }

        $this->assertNotSame('sirsoft-koreaexim', $this->manifestName('plugins', 'sirsoft-koreaexim', 'plugin.json', 'ko'), '시험 전제 — 번들 이름이 식별자와 다르다');
    }

    /**
     * 설치 기록이 하나도 없는 저장소 대역을 묶는다.
     *
     * @return void 반환값 없음
     */
    private function bindEmptyRepositories(): void
    {
        foreach ([ModuleRepositoryInterface::class, PluginRepositoryInterface::class, TemplateRepositoryInterface::class] as $contract) {
            $repository = $this->createMock($contract);
            $repository->method('findByIdentifier')->willReturn(null);
            $this->app->instance($contract, $repository);
        }
    }

    /**
     * 확장 manifest 의 지정 언어 이름을 읽는다(기대값 원천) — 이 환경에 있는 첫 폴더(활성 → 대기 → 번들)의 manifest.
     *
     * 활성 폴더는 저장소에 들어 있지 않아 환경마다 있거나 없다. 번들 manifest 는 늘 있다.
     *
     * @param  string  $directory  확장 종류 폴더 (modules|plugins|templates)
     * @param  string  $identifier  식별자
     * @param  string  $file  manifest 파일 이름
     * @param  string  $locale  언어
     * @return string 이름
     */
    private function manifestName(string $directory, string $identifier, string $file, string $locale): string
    {
        foreach (['', '_pending/', '_bundled/'] as $sub) {
            $path = base_path("{$directory}/{$sub}{$identifier}/{$file}");
            if (is_file($path)) {
                $manifest = json_decode((string) file_get_contents($path), true);

                return (string) LocalizedValue::pick($manifest['name'], $locale);
            }
        }

        $this->fail("{$directory}/_bundled/{$identifier}/{$file} 가 없다 — 시험 전제");
    }
}
