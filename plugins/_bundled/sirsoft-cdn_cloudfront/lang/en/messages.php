<?php

return [
    'disk_label' => 'CloudFront (S3 + CDN)',
    'retry' => [
        'summary' => 'CDN cache invalidation retry: :succeeded succeeded, :failed failed out of :retried',
        'abandoned' => ':count invalidation(s) stopped after reaching the retry limit. Check the last error on the plugin settings screen.',
    ],
    'test_connection' => [
        'success' => 'Connected to the CloudFront distribution.',
        'missing_credentials' => 'Save the distribution ID, invalidation access key and secret key before testing.',
        'request_failed' => 'Failed to look up the CloudFront distribution: :error',
    ],
];
