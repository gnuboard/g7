<?php

namespace Modules\Sirsoft\Ecommerce\Tests;

use App\Extension\HookListenerRegistrar;
use App\Helpers\PermissionHelper;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Exports\Declarations\ModelDeclarationRegistry;
use Modules\Sirsoft\Ecommerce\Listeners\ExportActivityLogDescriptionResolver;
use Modules\Sirsoft\Ecommerce\Listeners\ExportActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\ExportNotificationDataListener;
use ReflectionClass;

/**
 * 내보내기(#787) 테스트 기반 — 모듈 테스트 기반의 격리를 재사용하고 DB 는 트랜잭션으로 보호한다.
 *
 * 모듈 테스트 기반이 서비스 제공자·라우트·모듈 인스턴스를 준비한다. 여기서는 내보내기 리스너(활동 로그·설명 해석·
 * 알림 데이터)를 등록하고, 모듈 디스크를 가짜로 바꾸고, 선언 레지스트리와 권한 캐시를 비운다.
 */
abstract class ExportTestCase extends ModuleTestCase
{
    /**
     * 모듈 식별자
     */
    protected const IDENTIFIER = 'sirsoft-ecommerce';

    /**
     * 테스트 DB 재생성 없이 트랜잭션으로 테스트를 격리합니다.
     *
     * 내보내기 테이블이 아직 없는 새 테스트 DB 에서는 모듈 테스트 기반의 재생성을 한 번 거친다 — 테이블이 있다는
     * 가정만으로는 빈 DB(CI 등)에서 첫 테스트부터 실패한다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        if (! Schema::hasTable('ecommerce_exports') || ! Schema::hasTable('ecommerce_export_downloads')) {
            parent::refreshDatabase();

            return;
        }

        $this->beginDatabaseTransaction();
    }

    /**
     * 내보내기 리스너·가짜 저장소·빈 캐시를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');

        // 출처는 부팅 때 모듈 관리자가 쓰는 식별자와 같아야 한다 — 등록기가 「출처 + 클래스」로 중복을 거르므로,
        // 설치본이 이미 리스너를 등록한 환경에서 다른 출처로 또 등록하면 같은 훅이 두 번 돈다(활동 로그 2건)
        foreach ([ExportActivityLogListener::class, ExportActivityLogDescriptionResolver::class, ExportNotificationDataListener::class] as $listener) {
            HookListenerRegistrar::register($listener, self::IDENTIFIER);
        }

        ModelDeclarationRegistry::flush();
        $this->clearPermissionCache();
    }

    /**
     * 권한 캐시를 비웁니다(테스트마다 역할·범위를 새로 만든다).
     *
     * @return void 반환값 없음
     */
    protected function clearPermissionCache(): void
    {
        $reflection = new ReflectionClass(PermissionHelper::class);
        if ($reflection->hasProperty('permissionCache')) {
            $prop = $reflection->getProperty('permissionCache');
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }
    }

    /**
     * 내보내기 API 주소를 반환합니다.
     *
     * @param  string  $path  하위 경로
     * @return string 주소
     */
    protected function exportsUrl(string $path = ''): string
    {
        return '/api/modules/'.self::IDENTIFIER.'/admin/exports'.$path;
    }

    /**
     * 저장소에서 내보내기 파일의 전체 경로를 반환합니다(모듈 디스크 기준).
     *
     * @param  string  $filePath  작업의 file_path
     * @return string 디스크 상대 경로
     */
    protected function storedPath(string $filePath): string
    {
        return self::IDENTIFIER.'/exports/'.$filePath;
    }
}
