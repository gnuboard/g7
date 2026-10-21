<?php

namespace App\Support;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * 큐 워커 설정 다시 읽기 — 큐 작업 시작 직전에 설정 버전을 비교한다
 *
 * 워커는 부팅 때 설정을 한 번 읽고 프로세스가 끝날 때까지 들고 있다. 관리자가 메일 발신 주소나 모듈 설정을 바꿔도
 * 저장한 웹 프로세스만 다시 읽으므로 워커는 재시작 전까지 옛 설정을 쓴다. 설정 저장 때 올린 {@see SettingsVersion} 을
 * 작업마다 비교해 바뀌었으면 다시 읽는다.
 *
 * 다시 읽는 것: 코어 config(드라이버 계열 제외)·이미 만든 메일러·코어/모듈/플러그인 설정 미러.
 * 드라이버 계열(큐·캐시·세션 연결 등)은 기존대로 저장 시 `queue:restart` 가 맡는다 — 작업 도중 연결을 바꾸지 않는다.
 *
 * 프로세스 수명 동안 마지막으로 본 버전을 기억해야 하므로 의도된 싱글톤이다(SettingsServiceProvider 가 등록).
 */
class WorkerSettingsRefresher
{
    /**
     * 마지막으로 다시 읽은 시점의 설정 버전
     */
    private ?string $lastVersion = null;

    /**
     * 이 프로세스에서 한 번이라도 다시 읽었는지
     */
    private bool $refreshedOnce = false;

    /**
     * 설정 버전이 바뀌었으면 설정을 다시 읽습니다.
     *
     * 첫 호출은 버전과 무관하게 한 번 다시 읽는다 — 워커가 부팅 뒤 첫 작업까지 오래 쉬었을 수 있다. 이후에는 버전이
     * 마지막 값과 다를 때만 읽는다. 예외는 모두 잡아 경고만 남긴다 — 작업 자체를 실패시키지 않는다.
     *
     * @return void 반환값 없음
     */
    public function refreshIfChanged(): void
    {
        try {
            $current = SettingsVersion::current();
            $first = ! $this->refreshedOnce;

            if (! $first && $current === $this->lastVersion) {
                return;
            }

            $this->refresh();

            $this->refreshedOnce = true;
            $this->lastVersion = $current;

            Log::info('[Settings] 큐 워커가 설정을 다시 읽었습니다', ['version' => $current, 'first' => $first]);
        } catch (\Throwable $e) {
            Log::warning('[Settings] 큐 워커 설정 다시 읽기 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 코어 config·메일러·설정 미러를 다시 채웁니다.
     *
     * @return void 반환값 없음
     */
    private function refresh(): void
    {
        $repository = new JsonConfigRepository;

        app()->getProvider(SettingsServiceProvider::class)?->applyRuntimeSettings($repository);

        // 이미 만든 메일러는 옛 설정(발신 주소·SMTP 자격증명)을 들고 있다 — 버려서 다음 발송이 새 설정으로 만들게 한다
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->forgetMailers();
        }

        $mirror = app(ExtensionSettingsMirror::class);
        $mirror->refreshCore($repository);
        $mirror->refreshAllModules();
        $mirror->refreshAllPlugins();
    }
}
