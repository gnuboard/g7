<?php

namespace Modules\Sirsoft\Inquiry\Enums;

enum TransitionEvent: string
{
    case IssueQuote = 'issue_quote';
    case RevokeQuote = 'revoke_quote';
    case RejectQuote = 'reject_quote';
    case AcceptAndPay = 'accept_and_pay';
    case MarkPaidOffline = 'mark_paid_offline';
    case MarkCompleted = 'mark_completed';
    case Cancel = 'cancel';
}
