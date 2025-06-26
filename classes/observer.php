<?php
namespace local_frappe_integration;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php');

// Al principio del archivo observer.php, añade:
global $CFG;
use DateTime;
use DateTimeZone;

class observer {
    // Función helper para obtener el host sin protocolo
    protected static function get_moodle_domain(): string {
        global $CFG;
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
        return $host ?: '';
    }
    protected static function to_local_timestamp($ts, DateTimeZone $tz) {
        if (empty($ts)) {
            return null;
        }
        // Creamos un DateTime en UTC y pedimos el offset de Madrid
        $dt_utc   = new DateTime("@{$ts}");
        $offset   = $tz->getOffset($dt_utc);
        // Devolvemos el epoch ajustado
        return $ts + $offset;
    }
/**
 * Envía un POST con JSON a Frappe con el payload y token.
 *
 * @param array $payload_data  Array asociativo con los datos de la notificación.
 * @return mixed               Cadena de respuesta en caso de éxito, false en caso de error.
 */
protected static function notify_frappe(array $payload_data) {
    // 1. Cargamos URL base y token desde configuración
    $baseurl = get_config('local_frappe_integration', 'frappe_api_url');
    $token   = get_config('local_frappe_integration', 'frappe_api_token');

    if (empty($baseurl)) {
        error_log('Frappe Integration: Frappe API URL is not configured.');
        return false;
    }
    if (empty($token)) {
        error_log('Frappe Integration: Frappe API Token is not configured.');
        return false;
    }

    // 2. Preparamos URL y payload completo
    $url = rtrim($baseurl, '/') . '/api/method/moodle_integration.notify_frappe';
    $payload = $payload_data;
    $payload['token'] = $token;

    // 3. Codificamos a JSON
    $jsonpayload = json_encode($payload);
    if ($jsonpayload === false) {
        error_log('Frappe Integration: json_encode error: ' . json_last_error_msg());
        return false;
    }

    // 4. Enviamos con Moodle cURL
    $curl = new \curl();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    try {
        $response_body = $curl->post(
            $url,
            $jsonpayload,
            ['CURLOPT_HTTPHEADER' => $headers]
        );
        $http_status = $curl->get_info(CURLINFO_HTTP_CODE);

        // 5. Logs para depurar
        error_log(sprintf(
            'Frappe Integration: Sent JSON to %s → HTTP %d',
            $url,
            $http_status
        ));
        error_log('Frappe Integration: JSON payload: ' . $jsonpayload);
        error_log('Frappe Integration: Response body: ' . $response_body);

        if ($http_status >= 200 && $http_status < 300) {
            return $response_body;
        } else {
            error_log(sprintf(
                'Frappe Integration: Error from Frappe API. Status: %d. URL: %s. Response: %s',
                $http_status,
                $url,
                $response_body
            ));
            return false;
        }

    } catch (\curl_exception $e) {
        error_log('Frappe Integration: cURL exception: ' . $e->getMessage());
        return false;
    } catch (\Exception $e) {
        error_log('Frappe Integration: General exception during notify_frappe: ' . $e->getMessage());
        return false;
    }
}

    public static function user_loggedout(\core\event\user_loggedout $event) {
        $tz = new DateTimeZone('Europe/Madrid');
        global $DB;
        $d = $event->get_data();
        $userid = $d['objectid']; // ID de usuario
    
        // Sólo notificamos si existe el usuario
        $user_record = $DB->get_record('user',
            ['id' => $userid],
            'id, username',
            IGNORE_MISSING
        );
        if (!$user_record || empty($user_record->username)) {
            error_log("Frappe Integration (user_loggedout): User with ID {$userid} not found. Skipping.");
            return;
        }
        $timecreated = self::to_local_timestamp($d['timecreated'], $tz);
    
        $payload = [
            'action'        => 'user_loggedout',
            'moodle_domain' => self::get_moodle_domain(),
            'userid'        => $userid,
            'username'      => $user_record->username,
            'timestamp'     => $timecreated, // cuándo ocurrió el logout
        ];
    
        self::notify_frappe($payload);
    }

    public static function user_loggedin(\core\event\user_loggedin $event) {
        $tz = new DateTimeZone('Europe/Madrid');
        global $DB;
        $d = $event->get_data();
        $userid = $d['objectid'];

        $user_record = $DB->get_record('user', ['id' => $userid], 'id, username, lastlogin', IGNORE_MISSING);

        if (!$user_record || empty($user_record->username)) {
            error_log("Frappe Integration (user_loggedin): User with ID {$userid} not found or has no username. Skipping.");
            return;
        }
        
        $username = $user_record->username;
        $db_lastlogin = (int)$user_record->lastlogin; // Lastlogin de la tabla 'user'
        $lastlog_format = self::to_local_timestamp($db_lastlogin, $tz);
        $event_timecreated = self::to_local_timestamp($d['timecreated'], $tz);      // Timestamp del evento

        $payload = [
            'action'            => 'user_loggedin',
            'moodle_domain'     => self::get_moodle_domain(), // Dominio de Moodle sin protocolo
            'userid'            => $userid,
            'username'          => $username,
            'lastlogin'         => $lastlog_format,        // El 'lastlogin' oficial de la BD
            'event_timecreated' => $event_timecreated,   // El momento en que se disparó el evento
        ];
        
        self::notify_frappe($payload);
    }
    /**
     * Evento: el usuario ve (entra) a un curso.
     */
    public static function course_viewed(\core\event\course_viewed $event) {
        $tz = new DateTimeZone('Europe/Madrid');
        global $DB; // $USER no es necesario aquí si usamos $d['userid']
        $d = $event->get_data();

        $userid_viewing = $d['userid'];
        $courseid_viewed = $d['contextinstanceid']; // Este es el ID del curso

        $user_record = $DB->get_record('user', ['id' => $userid_viewing], 'id, username', IGNORE_MISSING);

        // Excluimos usuarios no encontrados, sin username, o el usuario invitado
        if (!$user_record || empty($user_record->username) || $user_record->username === 'guest') {
            error_log("Frappe Integration (course_viewed): User with ID {$userid_viewing} not found, has no username, or is guest for course {$courseid_viewed}. Skipping notification.");
            return;
        }
        $username_viewing = $user_record->username;

        // Obtenemos información adicional del curso para este usuario
        // (Asumiendo que external.php y su función course_user_info ya están correctos)
        $course_specific_info = external::course_user_info($username_viewing, $courseid_viewed);

        // Verificar que obtuvimos información válida antes de fusionar
        if ($course_specific_info === false || !is_array($course_specific_info)) {
            error_log("Frappe Integration (course_viewed): Failed to get course_user_info for user '{$username_viewing}' (ID: {$userid_viewing}) and course '{$courseid_viewed}'. Skipping notification.");
            // Podrías decidir enviar datos parciales si es apropiado, o no enviar nada:
            return;
        }
        $timecreated = self::to_local_timestamp($d['timecreated'], $tz);
        // Fusionamos la información específica del curso con los datos del evento
        $payload = array_merge($course_specific_info, [
            'action'    => 'course_viewed',
            'moodle_domain' => self::get_moodle_domain(), // Dominio de Moodle sin protocolo
            'userid'    => $userid_viewing,
            'username'  => $username_viewing,
            'courseid'  => $courseid_viewed,
            'timestamp' => $timecreated, // Momento en que se vio el curso
        ]);

        self::notify_frappe($payload);
    }
    public static function user_graded(\core\event\user_graded $event) {
        $tz = new DateTimeZone('Europe/Madrid');
        global $DB;
        $userid   = $event->relateduserid;
        $courseid = $event->courseid; // Corrección: Obtenerlo directamente del evento

        $username = $DB->get_field('user', 'username', ['id' => $userid]);
        if (!$username) {
            error_log("Frappe Integration: Usuario no encontrado ID $userid");
            return;
        }

        // Llamamos a tus funciones, pero ahora con el courseid correcto y sin consultas redundantes
        $course_specific_info = external::course_user_info($username, $courseid);
        $grades_response = external::obtener_clasificaciones_usuario($username, $courseid);
        $timecreated = self::to_local_timestamp($event->timecreated, $tz);
        
        $payload_completo = array_merge($course_specific_info['data'] ?? [], [
            'action'        => 'grade_updated',
            'moodle_domain' => self::get_moodle_domain(),
            'userid'        => $userid,
            'username'      => $username,
            'courseid'      => $courseid,
            'timestamp'     => $timecreated,
            'grades'        => $grades_response['data'] ?? [],
        ]);

        self::notify_frappe($payload_completo);
        error_log("Frappe Integration - Payload Completo: " . count($payload_completo['grades']) . " notas enviadas.");
    }
}