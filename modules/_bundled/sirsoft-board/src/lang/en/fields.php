<?php

/**
 * Names and help text of the member fields this module declares in the core.user field set.
 *
 * The declaration (`getFields()` in `module.php`) reads this file directly to build the multilingual arrays.
 */
return [
    'user' => [
        'notify_post_complete' => [
            'name' => 'Post Complete Notification',
            'description' => 'Get notified when a post you wrote has been processed.',
        ],
        'notify_post_reply' => [
            'name' => 'Post Reply Notification',
            'description' => 'Get notified when someone posts a reply to your post.',
        ],
        'notify_comment' => [
            'name' => 'Comment Notification',
            'description' => 'Get notified when someone comments on your post.',
        ],
        'notify_reply_comment' => [
            'name' => 'Reply Comment Notification',
            'description' => 'Get notified when someone replies to your comment.',
        ],
    ],
    // Fields declared in the guest author field set (sirsoft-board.guest_author)
    'guest_author' => [
        'email' => [
            'name' => 'Email',
            'description' => 'An email address for replies. It is not shown to other people.',
        ],
    ],
];
