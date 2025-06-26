<?php
defined('MOODLE_INTERNAL') || die();
$observers = [
    // Cuando el usuario inicia sesión
    [
        'eventname' => '\core\event\user_loggedin',
        'callback'  => 'local_frappe_integration\observer::user_loggedin',
    ],
    [
        'eventname' => '\core\event\user_loggedout',
        'callback'  => 'local_frappe_integration\observer::user_loggedout',
    ],
    [
        'eventname' => '\core\event\course_viewed',
        'callback'  => 'local_frappe_integration\observer::course_viewed',
    ],
        // Se dispara cuando se califica o actualiza la nota de un usuario en un ítem.
        [
            'eventname' => '\core\event\user_graded',
            'callback'  => 'local_frappe_integration\observer::user_graded',
        ],
];
