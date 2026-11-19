<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\LayoutSyncMode;
use App\Exceptions\ExtensionUpdatePreviewException;
use App\Extension\Layout\LayoutInstallSourceScope;
use App\Extension\Layout\LayoutSyncOptions;
use App\Extension\Update\ExtensionUpdatePreviewStore;
use App\Services\Extension\ExtensionUpdatePreviewService;
use App\Support\SafeExceptionMessage;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

/**
 * 모듈·플러그인·템플릿 [업데이트] 창의 미리보기와, 미리보기 결과(내려받은 새 판·화면별 결정)를 실제 업데이트에 잇는 공통 부분.
 */
trait HandlesExtensionUpdatePreview
{
    /**
     * 업데이트를 미리보기 결과와 이어 실행합니다 — `pending_token` 의 준비본을 다시 쓰고 `decisions` 를 화면별로 적용합니다.
     *
     * @param  array<string, mixed>  $validated  검증된 업데이트 요청
     * @param  Closure  $update  업데이트 실행
     * @return mixed 업데이트 결과
     */
    protected function withUpdatePreviewContext(array $validated, Closure $update): mixed
    {
        ExtensionUpdatePreviewStore::useToken($validated['pending_token'] ?? null);

        try {
            return LayoutSyncOptions::run(
                new LayoutSyncOptions(false, (array) ($validated['decisions'] ?? [])),
                $update
            );
        } finally {
            ExtensionUpdatePreviewStore::useToken(null);
        }
    }

    /**
     * 업데이트 미리보기 응답을 만듭니다.
     *
     * @param  FormRequest  $request  업데이트 요청 (layout_strategy·decisions·layout_source)
     * @param  string  $type  확장 종류 (`module`·`plugin`·`template`)
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse `{update_available, update_source, from_version, to_version, pending_token, layouts, declarative_deletions}`.
     *                      설치되지 않은 확장은 404, 알 수 없는 확장 종류는 422, 그 밖의 실패는 500
     */
    protected function respondUpdatePreview(FormRequest $request, string $type, string $identifier): JsonResponse
    {
        try {
            $validated = $request->validated();
            // 미리보기는 keep 을 기본 선택으로 보여 준다 — 덮어쓰기는 창에서 명시적으로 고를 때만이다.
            $mode = LayoutSyncMode::from($validated['layout_strategy'] ?? LayoutSyncMode::Keep->value);
            // 트레이트는 생성자를 갖지 않는다 — 생성자 주입은 이 트레이트를 쓰는 세 컨트롤러(모듈·플러그인·템플릿) 모두의
            // 생성자를 바꿔야 해서, 미리보기 한 곳에서만 쓰는 서비스는 컨테이너에서 꺼낸다.
            $preview = LayoutInstallSourceScope::run(
                $validated['layout_source'] ?? null,
                fn () => app(ExtensionUpdatePreviewService::class)->preview($type, $identifier, null, $mode, (array) ($validated['decisions'] ?? []))
            );

            return $this->success('extensions.update_preview.success', $preview);
        } catch (ExtensionUpdatePreviewException $e) {
            // 입력(식별자·종류)으로 생긴 실패 — 장애로 위장하지 않게 사유별 4xx 로 돌려준다
            return $this->error($e->getMessageKey(), $e->getStatusCode(), null, $e->getMessageParams());
        } catch (\Throwable $e) {
            return $this->error('extensions.update_preview.failed', 500, $e->getMessage(), ['error' => SafeExceptionMessage::for($e)]);
        }
    }
}
