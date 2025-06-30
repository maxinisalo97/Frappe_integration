<?php
// local/frappe_integration/locallib.php

defined('MOODLE_INTERNAL') || die();

if (!function_exists('local_frappe_log')) {
    /**
     * Escribe un mensaje en un log propio de este plugin
     *
     * @param string $message
     * @return void
     */
    function local_frappe_log(string $message): void {
        global $CFG;
        // usamos dataroot para no ensuciar /var/log de sistema
        $logfile = $CFG->dataroot . '/local_frappe_integration.log';
        $date    = date('Y-m-d H:i:s');
        // agregamos línea y bloqueamos el fichero
        file_put_contents($logfile, "[$date] $message\n", FILE_APPEND | LOCK_EX);
    }
}
