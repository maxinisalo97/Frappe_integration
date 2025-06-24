<?php
namespace local_frappe_integration;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
global $CFG;
use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class external extends external_api {
    // Función helper para obtener el host sin protocolo
    protected static function get_moodle_domain(): string {
        global $CFG;
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
        return $host ?: '';
    }
    // 1) Define parámetros de entrada
    public static function course_user_info_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Nombre de usuario en Moodle'),
            'courseid' => new external_value(PARAM_INT, 'ID de curso'),
        ]);
    }

    // 2) Lógica del endpoint
    // 2) Lógica del endpoint
    public static function course_user_info($username, $courseid) {
        // die("DEBUG: ¡SE ESTÁ EJECUTANDO EL ARCHIVO MODIFICADO EL " . date("Y-m-d H:i:s") . "!");
        global $DB;
        $params = self::validate_parameters(
            self::course_user_info_parameters(),
            compact('username','courseid')
        );

        // 2.1 Buscamos usuario y extraemos $userid
        $user = $DB->get_record('user',
            ['username'=>$params['username']],
            'id, lastlogin',
            IGNORE_MISSING
        );
        if (!$user) {
            throw new \invalid_parameter_exception('Usuario no existe');
        }
        $userid = (int)$user->id;

        // 2.2 Verificamos curso
        if (!$DB->record_exists('course',['id'=>$params['courseid']])) {
            throw new \invalid_parameter_exception('Curso no existe');
        }

        // 2.3 Primer acceso
        $firstaccess = $DB->get_field_sql("
            SELECT MIN(timecreated)
              FROM {logstore_standard_log}
             WHERE userid = :uid
               AND courseid = :cid
        ", ['uid'=>$userid,'cid'=>$params['courseid']]);

        // 2.4 Último acceso al curso
        $lastaccess = $DB->get_field(
            'user_lastaccess',
            'timeaccess',
            ['userid'=>$userid,'courseid'=>$params['courseid']]
        );

        // 2.5 Último login global
        $lastlogin = (int)$user->lastlogin;

        // 2.6 Estado online
        $threshold = time() - 300; // 5 minutos de umbral
        $online = (bool)$DB->record_exists_select(
            'sessions',
            'userid = :uid AND timemodified > :thr', // <--- CORREGIDO
            ['uid'=>$userid,'thr'=>$threshold]
        );

        return [
            'moodle_domain' => self::get_moodle_domain(), // Dominio de Moodle sin protocolo
            'firstaccess' => $firstaccess ? (int)$firstaccess : null,
            'lastaccess'  => $lastaccess  ? (int)$lastaccess  : null,
            'lastlogin'   => $lastlogin   ? (int)$lastlogin   : null,
            'online'      => $online,
        ];
    }


    // 3) Estructura de retorno
    public static function course_user_info_returns(): external_single_structure {
        return new external_single_structure([
            'moodle_domain' => new external_value(PARAM_URL, 'Dominio de Moodle sin protocolo'),
            'firstaccess' => new external_value(PARAM_INT,  'Timestamp UNIX primer acceso o null'),
            'lastaccess'  => new external_value(PARAM_INT,  'Timestamp UNIX último acceso o null'),
            'lastlogin'   => new external_value(PARAM_INT,  'Timestamp UNIX último login o null'),
            'online'      => new external_value(PARAM_BOOL, 'Si el usuario está “online”'),
        ]);
    }
}
