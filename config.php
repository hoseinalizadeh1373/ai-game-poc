<?php

return [
    'api_key' => 'sk-F9zYaJfCRya7jE1Pc3xHRL1CIG1yzZqT9HC6YtEmOhgFbnpZ',
    'api_url' => 'https://api.gapgpt.app/v1/chat/completions',
    'model'   => 'deepseek-v4-flash',

    // در تست شما فقط همین فیلد thinking را روی deepseek-v4-flash خاموش کرد (reasoning=0).
    // فقط برای مدل‌های deepseek به درخواست اضافه می‌شود.
    'extra_body' => [
        'thinking' => ['type' => 'disabled'],
    ],

    // دمای پایین‌تر = کد پایدارتر. سقف خروجی عمداً تعیین نشده است.
    'temperature' => 0.3,
];