<?php
namespace local_frappe_integration;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php');
// Al principio del archivo observer.php, añade:
global $CFG;


class observer {
    // Función helper para obtener el host sin protocolo
    protected static function get_moodle_domain(): string {
        global $CFG;
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
        return $host ?: '';
    }
    /**
     * Envía un POST a Frappe con el payload y token.
     */
    protected static function notify_frappe(array $payload_data) { // Renombrado $data a $payload_data para claridad
        // global $CFG; // No parece necesario aquí

        $baseurl = get_config('local_frappe_integration','frappe_api_url');
        $token   = get_config('local_frappe_integration','frappe_api_token');

        if (empty($baseurl)) {
            error_log('Frappe Integration: Frappe API URL is not configured.');
            return false;
        }
        if (empty($token)) {
            error_log('Frappe Integration: Frappe API Token is not configured.');
            return false;
        }

        $curl = new \curl();
        // $curl->setHeader('Content-Type: application/json'); // Descomenta si tu API Frappe espera JSON
                                                              // y asegúrate de enviar $data_to_send como json_encode($data_to_send)

        // Preparamos los datos a enviar, incluyendo el token
        $data_to_send = $payload_data;
        $data_to_send['token'] = $token; // Aseguramos que el token siempre se incluya aquí

        $url = rtrim($baseurl, '/') . '/api/method/moodle_integration.notify_frappe';

        try {
            // Si Frappe espera JSON, la llamada sería:
            // $response_body = $curl->post($url, json_encode($data_to_send));
            // Si Frappe espera datos de formulario (x-www-form-urlencoded), la llamada es:
            $response_body = $curl->post($url, $data_to_send);

            $http_status = $curl->get_info(CURLINFO_HTTP_CODE);

            // Log de la petición
            error_log(sprintf(
                'Frappe Integration: Sent data to %s → HTTP %d',
                $url,
                $http_status
            ));
            // Log del payload (evita Array to string conversion)
            error_log('Frappe Integration: Payload: ' . json_encode($data_to_send));
            // Log de la respuesta
            error_log('Frappe Integration: Response: ' . $response_body);

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

        } catch (\curl_exception $e) { // Captura excepciones específicas de la clase \core\curl de Moodle
            error_log('Frappe Integration: Moodle cURL exception: ' . $e->getMessage() . ' URL: ' . $url);
            return false;
        } catch (\Exception $e) { // Captura otras excepciones generales
            error_log('Frappe Integration: General exception during cURL: ' . $e->getMessage() . ' URL: ' . $url);
            return false;
        }
    }

    public static function user_loggedout(\core\event\user_loggedout $event) {
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
    
        $payload = [
            'action'        => 'user_loggedout',
            'moodle_domain' => self::get_moodle_domain(),
            'userid'        => $userid,
            'username'      => $user_record->username,
            'timestamp'     => $d['timecreated'], // cuándo ocurrió el logout
        ];
    
        self::notify_frappe($payload);
    }

    public static function user_loggedin(\core\event\user_loggedin $event) {
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
        $event_timecreated = $d['timecreated'];       // Timestamp del evento

        $payload = [
            'action'            => 'user_loggedin',
            'moodle_domain'     => self::get_moodle_domain(), // Dominio de Moodle sin protocolo
            'userid'            => $userid,
            'username'          => $username,
            'lastlogin'         => $db_lastlogin,        // El 'lastlogin' oficial de la BD
            'event_timecreated' => $event_timecreated,   // El momento en que se disparó el evento
        ];
        
        self::notify_frappe($payload);
    }
    /**
     * Evento: el usuario ve (entra) a un curso.
     */
    public static function course_viewed(\core\event\course_viewed $event) {
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

        // Fusionamos la información específica del curso con los datos del evento
        $payload = array_merge($course_specific_info, [
            'action'    => 'course_viewed',
            'moodle_domain' => self::get_moodle_domain(), // Dominio de Moodle sin protocolo
            'userid'    => $userid_viewing,
            'username'  => $username_viewing,
            'courseid'  => $courseid_viewed,
            'timestamp' => $d['timecreated'], // Momento en que se vio el curso
        ]);

        self::notify_frappe($payload);
    }
    public static function grade_updated(base $event) {
        global $DB, $CFG;
        error_log("Frappe Integration: **grade_updated** disparado"); 

        $d = $event->get_data();
        $userid    = $d['relateduserid'];
        $itemid    = $d['objectid'];
        $timestamp = $d['timecreated'];

        // 2) Username
        $user = $DB->get_record('user', ['id'=>$userid], 'username', IGNORE_MISSING);
        if (!$user) {
            error_log("Frappe Integration: grade_updated user no encontrado $userid");
            return;
        }
        $username = $user->username;

        // 3) Courseid
        $courseid = $d['courseid'] ?? null;
        if (!$courseid) {
            $giid = $DB->get_field('grade_grades', 'itemid', ['id'=>$itemid]);
            $rec  = $DB->get_record('grade_items', ['id'=>$giid], 'courseid', IGNORE_MISSING);
            $courseid = $rec? $rec->courseid : null;
        }
        if (!$courseid) {
            error_log("Frappe Integration: grade_updated no pudo extraer courseid");
            return;
        }

        // 4) Datos generales
        $course_specific_info = external::course_user_info($username, $courseid);
        if (empty($course_specific_info) || !is_array($course_specific_info)) {
            error_log("Frappe Integration: grade_updated course_user_info falló");
            return;
        }

        // 5) Lista completa de calificaciones
        $grades_response = external::obtener_clasificaciones_usuario($username, $courseid);
        $grades_list = $grades_response['data'] ?? [];
        error_log("Frappe Integration: grade_updated obtained " . count($grades_list) . " grades");

        // 6) Payload
        $payload = array_merge($course_specific_info, [
            'action'        => 'grade_updated',
            'moodle_domain' => self::get_moodle_domain(),
            'userid'        => $userid,
            'username'      => $username,
            'courseid'      => $courseid,
            'timestamp'     => $timestamp,
            'grades'        => $grades_list,
        ]);

        // 7) Envío
        self::notify_frappe($payload);
    }
}