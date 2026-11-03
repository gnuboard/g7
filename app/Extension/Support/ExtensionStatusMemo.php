<?php

namespace App\Extension\Support;

use App\Support\InstallerContext;

/**
 * 활성·설치 확장 식별자 목록의 요청 메모
 *
 * 확장 로드·훅 구독·라우트 게이트 등 부팅 경로가 활성 목록을 요청마다 수십 번 읽는다(7.1.4 실측 요청당
 * 24~30회). 목록은 요청 안에서 바뀌지 않으므로 캐시 저장소는 한 번만 읽고 나머지는 이 메모로 답한다(#860).
 *
 * 컨테이너에 scoped 로 바인딩되어 웹 요청 1건·큐 잡 1건이 끝나면 버려진다 — 프로세스 수명 static 이 아니라서
 * 큐 워커가 옛 목록을 붙잡지 않고, route:cache 같은 일회용 새 앱은 새 메모를 쓴다.
 *
 * 지키는 것:
 *   - 비어 있지 않은 목록만 담는다 (빈 목록 고착은 「모든 확장 비활성」으로 번진 선례가 있다).
 *   - 비우는 지점은 기존 `invalidate{Module,Plugin,Template}StatusCache()` 하나다 — 새 무효화 지점을 만들지 않는다.
 *   - 설치 미완료·스키마 변경 명령 중에는 메모하지 않는다 (상태 테이블 자체가 바뀌는 구간).
 */
class ExtensionStatusMemo
{
    /**
     * 키별 목록
     *
     * @var array<string, array<int, string>>
     */
    private array $lists = [];

    /**
     * 메모된 목록을 반환합니다.
     *
     * @param  string  $key  상태 캐시 키 (예: `ext.modules.active_identifiers`)
     * @return array<int, string>|null 메모된 목록 (없으면 null)
     */
    public function get(string $key): ?array
    {
        return $this->lists[$key] ?? null;
    }

    /**
     * 목록을 메모합니다. 빈 목록은 담지 않습니다.
     *
     * @param  string  $key  상태 캐시 키
     * @param  array<int, string>  $list  식별자 목록
     * @return void 반환값 없음
     */
    public function put(string $key, array $list): void
    {
        if ($list === []) {
            return;
        }

        $this->lists[$key] = $list;
    }

    /**
     * 한 유형(modules·plugins·templates)의 메모를 모두 비웁니다.
     *
     * @param  string  $type  확장 유형 복수형 (상태 캐시 키의 두 번째 마디)
     * @return void 반환값 없음
     */
    public function forgetType(string $type): void
    {
        $prefix = "ext.{$type}.";

        foreach (array_keys($this->lists) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->lists[$key]);
            }
        }
    }

    /**
     * 지금 요청에서 쓸 메모를 반환합니다.
     *
     * 메모를 쓸 수 없는 구간(설치 미완료·스키마 변경 명령·컨테이너 미준비)이면 null 이다 — 호출자는
     * 메모 없이 기존 캐시·DB 경로로 동작한다.
     *
     * @return self|null 메모 (사용 불가면 null)
     */
    public static function current(): ?self
    {
        try {
            if (! function_exists('app') || ! config('app.installer_completed') || InstallerContext::isSchemaMutatingCommand()) {
                return null;
            }

            $app = app();

            return $app->bound(self::class) ? $app->make(self::class) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 이 요청에서 만든 메모가 있으면 한 유형을 비웁니다 (상태 캐시 무효화에서 부른다).
     *
     * 아직 만들지 않았으면 인스턴스를 새로 만들지 않는다 — 무효화는 부팅·설치기에서도 불린다.
     *
     * @param  string  $type  확장 유형 복수형
     * @return void 반환값 없음
     */
    public static function forgetCurrent(string $type): void
    {
        try {
            if (! function_exists('app')) {
                return;
            }

            $app = app();

            if ($app->resolved(self::class)) {
                $app->make(self::class)->forgetType($type);
            }
        } catch (\Throwable) {
            // 컨테이너를 쓸 수 없는 구간(register 초반)에는 메모도 없다
        }
    }
}
