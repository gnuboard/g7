<?php

return [
    'new_comment' => [
        'subject' => '[:board_name] New comment on ":post_title"',
        'greeting' => 'Hello :name,',
        'line' => ':comment_author left a new comment on ":post_title".',
    ],
    'reply_comment' => [
        'subject' => '[:board_name] Someone replied to your comment on ":post_title"',
        'greeting' => 'Hello :name,',
        'line' => ':comment_author replied to your comment on ":post_title".',
    ],
    'post_reply' => [
        'subject' => '[:board_name] New reply to ":post_title"',
        'greeting' => 'Hello :name,',
        'line' => 'A new reply has been posted to ":post_title".',
    ],
    'post_action' => [
        'subject' => '[:board_name] ":post_title" has been :action_type',
        'greeting' => 'Hello :name,',
        'line' => 'Your post ":post_title" has been :action_type by an administrator.',
        'action_types' => [
            'blind' => 'blinded',
            'deleted' => 'deleted',
            'restored' => 'restored',
        ],
    ],
    'new_post_admin' => [
        'subject' => '[:board_name] New post ":post_title" has been created',
        'greeting' => 'Hello :name,',
        'line' => 'A new post ":post_title" has been created in ":board_name".',
    ],
    'report_received_admin' => [
        'reason_types' => [
            'abuse' => 'Abuse/Harassment',
            'hate_speech' => 'Hate Speech',
            'spam' => 'Spam/Advertising',
            'copyright' => 'Copyright Infringement',
            'privacy' => 'Privacy Violation',
            'misinformation' => 'Misinformation',
            'sexual' => 'Sexual Content',
            'violence' => 'Violent Content',
            'other' => 'Other',
        ],
    ],
    'report_action' => [
        'target_types' => [
            'post' => 'post',
            'comment' => 'comment',
        ],
        'action_types' => [
            'blind' => 'blinded',
            'deleted' => 'deleted',
            'restored' => 'rejected (restored)',
        ],
    ],
    'common' => [
        'view_button' => 'View Post',
        'regards' => 'Thank you',
    ],
];
