<?php

namespace App\Extension\Helpers;

use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Support\HookCacheFreshProcess;
use Illuminate\Support\Facades\Log;

/**
 * 확장 업데이트 실패 처리 뒤 훅 캐시·확장 autoload 를 지금 디스크(되돌린 판) 기준으로 다시 맞춥니다.
 *
 * 업데이트는 도중에(업데이트 중 상태 창의 autoload 갱신) 새 판 기준으로 `bootstrap/cache/hooks.php`·`autoload-extensions.php` 를
 * 다시 만든다. 실패해 파일을 되돌려도 두 캐시가 새 판 그대로면, 되돌린 판에 없는 리스너 클래스를 부팅 때 불러 사이트 전체가 500 이
 * 된다(W3 리허설 — 결제 플러그인 업그레이드 가드 실패 뒤). 이 프로세스에는 새 판 클래스가 이미 올라가 있어 여기서 다시 구우면 새 판
 * 리스너가 다시 들어갈 수 있으므로, 새 PHP 프로세스(`extension:update-autoload`)로 굽는다. 띄울 수 없으면 autoload 만 여기서 다시
 * 만들고(디스크 기준) 훅 캐시는 지워, 다음 요청이 디스크의 확장 정의로 리스너를 찾게 한다(스캔 폴백).
 */
final class ExtensionRollbackCacheRepair
{
    /**
     * 캐시를 다시 맞춥니다. 실패해도 예외를 올리지 않는다 — 원래 업데이트 실패가 진짜 원인이다.
     *
     * @param  ExtensionManager  $extensionManager  확장 매니저 (폴백의 autoload 재생성)
     * @param  callable(array<int, string>): array{ok: bool, exit_code: int|null, output: string}|null  $freshProcessRunner  새 프로세스 실행기 (기본 HookCacheFreshProcess::run)
     * @param  HookCacheManager|null  $hookCache  훅 캐시 관리자 (기본 컨테이너)
     * @return string 처리 방식 (`fresh_process`·`in_process_fallback`)
     */
    public static function rebuild(ExtensionManager $extensionManager, ?callable $freshProcessRunner = null, ?HookCacheManager $hookCache = null): string
    {
        $runner = $freshProcessRunner ?? fn (array $arguments) => HookCacheFreshProcess::run($arguments);

        try {
            $result = $runner(['extension:update-autoload']);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'exit_code' => null, 'output' => $e->getMessage()];
        }

        if (($result['ok'] ?? false) === true) {
            Log::info('확장 업데이트 실패 뒤 훅 캐시·autoload 를 새 프로세스로 다시 만들었습니다');

            return 'fresh_process';
        }

        try {
            $extensionManager->updateComposerAutoload();
        } catch (\Throwable $e) {
            Log::warning('확장 업데이트 실패 뒤 autoload 재생성 실패', ['error' => $e->getMessage()]);
        }

        // 위 재생성이 이 프로세스의 (새 판) 클래스로 훅 캐시를 다시 구웠을 수 있다 — 지워서 다음 요청이 디스크 기준으로 찾게 한다.
        ($hookCache ?? app(HookCacheManager::class))->clear();

        Log::warning('확장 업데이트 실패 뒤 새 프로세스를 띄우지 못해 훅 캐시를 지웠습니다 — 나중에 php artisan extension:update-autoload 를 실행하세요', [
            'reason' => $result['output'] ?? null,
        ]);

        return 'in_process_fallback';
    }
}
