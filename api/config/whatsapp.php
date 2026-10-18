<?php
/*
 * Meta adds "sample" templates to every new WABA (hello_world, sample_shipping_confirmation, …, often in
 * en_US/es/pt_BR/id). They are not the business's own templates, so the portal never syncs or lists them.
 * This is the single place that decides what counts as a sample (see App\Models\MessageTemplate::isSample()).
 */
return [
    // Public sample files used as the media header when the Template Master "Test" button sends a template.
    'demo_media' => [
        'image' => env('WA_DEMO_IMAGE_URL', 'https://placehold.co/800x418/f97316/ffffff.png?text=Demo'),
        'video' => env('WA_DEMO_VIDEO_URL', 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4'),
        'document' => env('WA_DEMO_DOCUMENT_URL', 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf'),
    ],
    'sample_templates' => [
        'names' => array_filter(array_map('trim', explode(',', env('WA_SAMPLE_TEMPLATE_NAMES', 'hello_world')))),
        'prefixes' => array_filter(array_map('trim', explode(',', env('WA_SAMPLE_TEMPLATE_PREFIXES', 'sample_')))),
    ],
];
