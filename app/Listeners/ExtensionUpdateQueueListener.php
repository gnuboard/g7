<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * 확장 코드 유입·제거 후 큐 워커 재시작 리스너
 *
 * 세 확장 종류(모듈·플러그인·템플릿)의 설치·활성화·업데이트·제거가 성공하면 큐 워커를 재시작하여
 * 상주 워커가 새 코드(클래스·바인딩·리스너)나 지운 확장을 즉시 반영하도록 합니다.
 *
 * 제거와 템플릿은 설계 확정(2026-11-08)으로 들어왔다 — 상주 워커는 재시작 전까지 지운 확장의 클래스·리스너·설정을 메모리에
 * 든 채 작업을 돌고, 템플릿도 같은 사건에 같게 보낸다. 켜진 확장의 제거는 비활성화를 먼저 거치지만, 비활성화 자체는 대상이 아니다.
 */
class ExtensionUpdateQueueListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        // 반드시 동기 실행한다. Action 훅의 기본 등록은 큐 디스패치라, sync 가 없으면
        // "큐 워커를 재시작하는 작업을 그 큐 워커가 실행" 하게 된다. 또한 확장 작업은 CLI
        // 커맨드(module:update 등)로도 수행되며, 커맨드 프로세스는 실행 후 즉시 종료되어
        // 큐에 쌓인 작업을 처리할 주체가 없다(ExtensionConfigCacheListener 와 같은 논거).
        // queue:restart 는 캐시 타임스탬프 1건 쓰기라 동기 비용이 없다.
        $update = ['method' => 'handleAfterUpdate', 'priority' => 100, 'sync' => true];
        $setup = ['method' => 'handleAfterSetup', 'priority' => 100, 'sync' => true];
        $uninstall = ['method' => 'handleAfterUninstall', 'priority' => 100, 'sync' => true];

        return [
            'core.modules.after_install' => $setup,
            'core.modules.after_activate' => $setup,
            'core.modules.after_update' => $update,
            'core.plugins.after_install' => $setup,
            'core.plugins.after_activate' => $setup,
            'core.plugins.after_update' => $update,
            'core.templates.after_install' => $setup,
            // 템플릿 활성화 완료 훅만 식별자 없이 정보 배열 하나를 넘긴다
            'core.templates.after_activate' => ['method' => 'handleAfterTemplateActivate', 'priority' => 100, 'sync' => true],
            'core.templates.after_version_update' => $update,
            'core.modules.after_uninstall' => $uninstall,
            'core.plugins.after_uninstall' => ['method' => 'handleAfterPluginUninstall', 'priority' => 100, 'sync' => true],
            'core.templates.after_uninstall' => $uninstall,
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음 (개별 메서드로 라우팅됨)
    }

    /**
     * 확장 업데이트 후 큐 워커를 재시작합니다.
     *
     * "업데이트할 것 없음" 조기 리턴에서도 훅이 발화하므로 결과의 success 를 확인한다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array  $result  업데이트 결과 배열 (['success' => bool, ...])
     * @param  array|null  $info  확장 정보
     * @return void 반환값 없음
     */
    public function handleAfterUpdate(string $identifier, array $result, ?array $info = null): void
    {
        // 업데이트 실패 시 재시작 불필요
        if (! ($result['success'] ?? false)) {
            return;
        }

        $this->restartQueue($identifier, 'update');
    }

    /**
     * 확장 설치·활성화 후 큐 워커를 재시작합니다.
     *
     * 두 훅은 성공 분기에서만 발화하므로 결과 검사가 필요 없다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array|null  $info  확장 정보
     * @return void 반환값 없음
     */
    public function handleAfterSetup(string $identifier, ?array $info = null): void
    {
        $this->restartQueue($identifier, 'setup');
    }

    /**
     * 템플릿 활성화 후 큐 워커를 재시작합니다 (훅 인자는 정보 배열 하나, 성공 분기에서만 발화).
     *
     * @param  array  $info  템플릿 정보 (`identifier` 포함)
     * @return void 반환값 없음
     */
    public function handleAfterTemplateActivate(array $info = []): void
    {
        $this->restartQueue((string) ($info['identifier'] ?? ''), 'setup');
    }

    /**
     * 모듈·템플릿 제거 후 큐 워커를 재시작합니다.
     *
     * 두 훅은 성공 분기에서만 발화하므로 결과 검사가 필요 없다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array|null  $info  제거 전 확장 정보
     * @param  bool  $deleteData  데이터 삭제 여부 (쓰지 않는다)
     * @return void 반환값 없음
     */
    public function handleAfterUninstall(string $identifier, ?array $info = null, bool $deleteData = false): void
    {
        $this->restartQueue($identifier, 'uninstall');
    }

    /**
     * 플러그인 제거 후 큐 워커를 재시작합니다.
     *
     * 플러그인 제거 완료 훅은 실패에도 발화한다(인자 순서: 식별자, 데이터 삭제 여부, 결과) — 성공일 때만 보낸다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  mixed  $deleteData  데이터 삭제 여부 (쓰지 않는다)
     * @param  mixed  $result  제거 성공 여부
     * @return void 반환값 없음
     */
    public function handleAfterPluginUninstall(string $identifier, mixed $deleteData = false, mixed $result = false): void
    {
        if ($result !== true) {
            return;
        }

        $this->restartQueue($identifier, 'uninstall');
    }

    /**
     * queue:restart 를 실행합니다. 실패해도 확장 작업 자체에는 영향을 주지 않습니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  string  $event  사건 구분 (update|setup|uninstall) — 로그용
     * @return void 반환값 없음
     */
    private function restartQueue(string $identifier, string $event): void
    {
        try {
            Artisan::call('queue:restart');

            Log::info('확장 코드 변경(유입·제거) 후 큐 워커 재시작 완료', [
                'identifier' => $identifier,
                'event' => $event,
            ]);
        } catch (\Throwable $e) {
            // 큐 재시작 실패는 확장 작업 자체에 영향을 주지 않음
            Log::warning('확장 코드 변경(유입·제거) 후 큐 워커 재시작 실패', [
                'identifier' => $identifier,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
