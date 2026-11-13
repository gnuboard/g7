<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\User\DeleteMemberFieldFileRequest;
use App\Http\Requests\User\UploadMemberFieldFileRequest;
use App\Models\User;
use App\Services\MemberFieldFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * 관리자 — 회원의 파일·이미지 항목에 파일을 올리고 지우는 컨트롤러 (관리자 회원 폼).
 *
 * 사용 중인 파일 항목이면 범위(본인 수정, 관리자만 수정, 관리자만 열람)와 무관하게 다룬다. 권한은 라우트의 회원 수정 권한이다.
 *
 * @since 7.2.0
 */
class UserFieldFileController extends AdminBaseController
{
    /**
     * @param  MemberFieldFileService  $service  회원 파일 항목 Service
     */
    public function __construct(private MemberFieldFileService $service)
    {
        parent::__construct();
    }

    /**
     * 회원의 파일 항목에 파일 한 개를 올립니다.
     *
     * @param  UploadMemberFieldFileRequest  $request  검증된 요청
     * @param  User  $user  대상 회원
     * @param  string  $key  항목 키
     * @return JsonResponse 올린 파일과 그 항목의 전체 파일 목록
     */
    public function store(UploadMemberFieldFileRequest $request, User $user, string $key): JsonResponse
    {
        $definition = $request->targetField();
        if ($definition === null || $definition->key !== $key) {
            return $this->notFound('fields.file.field_not_found');
        }

        try {
            return $this->success('fields.file.uploaded', $this->service->upload($user, $definition, $request->file('file')), 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    /**
     * 회원의 파일 항목에서 파일 한 개를 지웁니다.
     *
     * @param  DeleteMemberFieldFileRequest  $request  검증된 요청
     * @param  User  $user  대상 회원
     * @param  string  $key  항목 키
     * @return JsonResponse 남은 파일 목록
     */
    public function destroy(DeleteMemberFieldFileRequest $request, User $user, string $key): JsonResponse
    {
        $definition = $request->targetField();
        if ($definition === null || $definition->key !== $key) {
            return $this->notFound('fields.file.field_not_found');
        }

        $files = $this->service->delete($user, $definition, (int) $request->validated('attachment_id'));
        if ($files === null) {
            return $this->notFound('fields.file.not_found');
        }

        return $this->success('fields.file.deleted', ['files' => $files]);
    }
}
