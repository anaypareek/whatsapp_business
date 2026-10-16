<?php
/*
 * Meta adds "sample" templates to every new WABA (hello_world, sample_shipping_confirmation, …, often in
 * en_US/es/pt_BR/id). They are not the business's own templates, so the portal never syncs or lists them.
 * This is the single place that decides what counts as a sample (see App\Models\MessageTemplate::isSample()).
 */
return [
    'sample_templates' => [
        'names' => array_filter(array_map('trim', explode(',', env('WA_SAMPLE_TEMPLATE_NAMES', 'hello_world')))),
        'prefixes' => array_filter(array_map('trim', explode(',', env('WA_SAMPLE_TEMPLATE_PREFIXES', 'sample_')))),
    ],
];
