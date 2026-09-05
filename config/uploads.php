<?php

return [
    // Maximum size, in kilobytes, of a single client-uploaded document.
    // PRODUCT.md §13 requires this to be configurable; default matches the 10 MB MVP limit.
    'max_size_kb' => (int) env('UPLOAD_MAX_SIZE_KB', 10240),
];
