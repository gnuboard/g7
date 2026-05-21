<?php

namespace Modules\Sirsoft\Inquiry\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Events\InquiryMessagePosted;
use Modules\Sirsoft\Inquiry\Http\Requests\StoreInquiryMessageRequest;
use Modules\Sirsoft\Inquiry\Http\Resources\InquiryMessageResource;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface;

class InquiryMessageController extends Controller
{
    public function __construct(
        private readonly InquiryMessageRepositoryInterface $messages,
        private readonly InquiryAttachmentRepositoryInterface $attachments,
    ) {}

    public function index(Request $request, Inquiry $inquiry)
    {
        $this->authorize('view', $inquiry);

        // 상대 역할 메시지 읽음 처리
        $myRole = $request->user()->id === $inquiry->user_id
            ? SenderRole::Client
            : SenderRole::Operator;
        $opposite = $myRole === SenderRole::Client ? SenderRole::Operator : SenderRole::Client;
        $this->messages->markReadFor($inquiry, $opposite);

        $perPage = (int) $request->query('per_page', 50);
        $paginator = $this->messages->listForInquiry($inquiry, $perPage);

        return InquiryMessageResource::collection($paginator);
    }

    public function store(StoreInquiryMessageRequest $request, Inquiry $inquiry)
    {
        $role = $request->user()->id === $inquiry->user_id
            ? SenderRole::Client
            : SenderRole::Operator;

        $msg = $this->messages->append(
            $inquiry,
            $request->user()->id,
            $role,
            $request->string('body', '')
        );

        $attachmentIds = $request->input('attachment_ids', []);
        foreach ($attachmentIds as $attId) {
            $att = $this->attachments->findOrFail((int) $attId);
            if ($att->inquiry_id !== $inquiry->id) {
                abort(422, 'Attachment does not belong to this inquiry');
            }
            $this->attachments->attachToMessage($att, $msg);
        }

        // 상대 역할 이전 메시지 읽음 처리
        $opposite = $role === SenderRole::Client ? SenderRole::Operator : SenderRole::Client;
        $this->messages->markReadFor($inquiry, $opposite);

        InquiryMessagePosted::dispatch($msg);

        return (new InquiryMessageResource($msg->load('attachments')))
            ->response()
            ->setStatusCode(201);
    }
}
