<?php

return [
    'api_key' => 'sk-F9zYaJfCRya7jE1Pc3xHRL1CIG1yzZqT9HC6YtEmOhgFbnpZ',
    'api_url' => 'https://api.gapgpt.app/v1/chat/completions',
    // مدل تغییر کرد به qwen 3.6. اگر این مدل روی gapgpt فقط از endpoint
    // /v1/responses پاسخ می‌دهد (نه /v1/chat/completions)، کافی است
    // api_url بالا را به همان مقدار تغییر دهید؛ callAiApi() در lib.php از قبل
    // هم فرمت "choices[0].message.content" و هم "output_text" را پشتیبانی می‌کند.
    'model'   => 'gapgpt-qwen-3.6',
];
