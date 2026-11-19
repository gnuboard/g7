<?php

namespace App\Console\Commands\Extension;

use App\Services\Extension\ExtensionFileSyncService;

/**
 * 종전 이름 `extension:sync-files` — {@see ResetExtensionFilesFromBundledCommand} 와 같은 동작(하위 호환).
 *
 * 이름에 방향이 없어 「활성 폴더를 번들로 올린다」로도 읽혀 새 이름으로 바꿨다. 이 이름을 쓰던 배포 스크립트가 깨지지 않게 남기되
 * 명령 목록에서는 숨기고, 사람이 읽는 출력에는 새 이름을 쓰라는 안내를 먼저 낸다(`--json` 출력에는 섞지 않는다).
 *
 * @since 7.2.0
 */
class SyncExtensionFilesCommand extends ResetExtensionFilesFromBundledCommand
{
    /**
     * @var string 명령 시그니처 (새 명령과 같은 인자·옵션)
     */
    protected $signature = 'extension:sync-files
        {identifier? : 대상 확장 식별자 (생략하면 설치된 확장 전부를 판정)}
        {--type= : 종류 (module|plugin|template|language-pack)}
        {--migrate : 교체 뒤 그 확장의 미실행 마이그레이션만 실행}
        {--dry-run : 판정만 출력하고 파일을 바꾸지 않음}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * @var string 명령 설명 (LocalizesDescription 이 다국어로 바꾼다)
     */
    protected $description = 'extensions.commands.sync_files.legacy_description';

    /**
     * @var bool 명령 목록에서 숨김 (예전 이름)
     */
    protected $hidden = true;

    /**
     * 새 이름 안내를 낸 뒤 새 명령과 같은 일을 합니다.
     *
     * @param  ExtensionFileSyncService  $service  파일 동기화 서비스
     * @return int 종료 코드
     */
    public function handle(ExtensionFileSyncService $service): int
    {
        if (! $this->option('json')) {
            $this->warn(__('extensions.commands.sync_files.legacy_name'));
        }

        return parent::handle($service);
    }
}
