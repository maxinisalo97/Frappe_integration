<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    // Único endpoint genérico
    'local_frappeintegration_api' => [
        'classname'   => 'local_frappe_integration\external',
        'methodname'  => 'api',
        'classpath'   => 'local/frappe_integration/classes/external.php',
        'description' => 'Dispatch a multiple internal methods based on "method" param',
        'type'        => 'read',
    ],
];

$services = [
    'Frappe Integration Service' => [
        'functions'       => array_keys($functions),
        'requiredcapability' => '',
        'enabled'         => 1,
        'restrictedusers' => 0,
    ],
];
