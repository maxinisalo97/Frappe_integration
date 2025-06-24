<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    // Endpoint de consulta puntual:
    'local_frappeintegration_course_user_info' => [
        'classname'   => 'local_frappe_integration\external',
        'methodname'  => 'course_user_info',
        'classpath'   => 'local/frappe_integration/classes/external.php',
        'description' => 'Devuelve firstaccess, lastaccess, lastlogin y online status de un usuario en un curso.',
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
