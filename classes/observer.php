<?php
namespace local_frappe_integration;
defined('MOODLE_INTERNAL') || die();
use local_frappe_integration\task\send_frappe_event;
use core\task\manager;
use \local_frappe_integration\external;
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/../locallib.php');
global $CFG;


class observer {
    // Función helper para obtener el host sin protocolo
    protected static function get_moodle_domain(): string {
        global $CFG;
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
        return $host ?: '';
    }

    protected static function enqueue_frappe_event(array $payload_data) {
        // 1) Log al entrar
    
        $task = new \local_frappe_integration\task\send_frappe_event();
        $task->set_custom_data($payload_data);
    
        try {
            \core\task\manager::queue_adhoc_task($task);
            // 2) Log si no ha saltado excepción
        } catch (\Throwable $e) {
            $message = 'Frappe Integration: enqueue_frappe_event → ¡ERROR al encolar!: ' . $e->getMessage();
            \local_frappe_integration\event\frappe_error::create([
                'context' => \context_system::instance(),
                'other'   => ['message' => $message]
            ])->trigger();

        }
    }
    
    
/**
 * Envía un POST con JSON a Frappe con el payload y token.
 *
 * @param array $payload_data  Array asociativo con los datos de la notificación.
 * @return mixed               Cadena de respuesta en caso de éxito, false en caso de error.
 */
public static function notify_frappe(array $payload_data) {
    // 1. Cargamos URL base y token desde configuración
    $baseurl = get_config('local_frappe_integration', 'frappe_api_url');
    $token   = get_config('local_frappe_integration', 'frappe_api_token');

    if (empty($baseurl)) {
        return false;
    }
    if (empty($token)) {
        return false;
    }

    // 2. Preparamos URL y payload completo
    $url = rtrim($baseurl, '/') . '/api/method/moodle_integration.notify_frappe';
    $payload = $payload_data;
    $payload['token'] = $token;

    // 3. Codificamos a JSON
    $jsonpayload = json_encode($payload);
    if ($jsonpayload === false) {
        $message = 'Frappe Integration: json_encode error: ' . json_last_error_msg();
        \local_frappe_integration\event\frappe_error::create([
            'context' => \context_system::instance(),
            'other'   => ['message' => $message]
        ])->trigger();
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

        $response = json_decode($response_body, true);

        // Condición de éxito: HTTP OK *y* status interno = "success"
        if (
            ($http_status >= 200 && $http_status < 300)
            || isset($response['message']['status'])
            && $response['message']['status'] === 'success'
        ) {
            return $response_body;
        }
        \local_frappe_integration\event\frappe_error::create([
            'context' => \context_system::instance(),
            'other'   => ['message' => "HTTP {$http_status}, status interno: ".
                             ($response['message']['status'] ?? 'n/d')]
        ])->trigger();
        return false;

    } catch (\curl_exception $e) {
            \local_frappe_integration\event\frappe_error::create([
                'context' => \context_system::instance(),
                'other'   => ['message' => $e->getMessage()]
            ])->trigger();

        return false;
    } catch (\Exception $e) {
        \local_frappe_integration\event\frappe_error::create([
            'context' => \context_system::instance(),
            'other'   => ['message' => $e->getMessage()]
        ])->trigger();
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
            return;
        }
    
        $payload = [
            'action'        => 'user_loggedout',
            'moodle_domain' => self::get_moodle_domain(),
            'userid'        => $userid,
            'username'      => $user_record->username,
            'timestamp'     => $d['timecreated'], // cuándo ocurrió el logout
        ];
    
        self::enqueue_frappe_event($payload);
    }

    public static function user_loggedin(\core\event\user_loggedin $event) {
        global $DB;
        $d = $event->get_data();
        $userid = $d['objectid'];

        $user_record = $DB->get_record('user', ['id' => $userid], 'id, username, lastlogin', IGNORE_MISSING);

        if (!$user_record || empty($user_record->username)) {
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
        
        self::enqueue_frappe_event($payload);
    }
      /**
     * Método interno que construye y encola el payload para un "acceso" a curso.
     *
     * @param int $userid   ID de usuario
     * @param int $courseid ID de curso
     * @param int $time     Timestamp del evento
     */
    protected static function handle_course_access(int $userid, int $courseid, int $time) {
        global $DB;

        // 1) Ignorar guest u otros filtrados
        $user = $DB->get_record('user', ['id'=>$userid], 'username,firstname,lastname', IGNORE_MISSING);
        if (!$user || $user->username === 'guest') {
            return;
        }

        // 2) Invocar tu API para datos genéricos y seguimiento
        $info = external::course_user_info($user->username, $courseid);
        $track = [];
        if (!empty($info['status']) && $info['status']==='success') {
            $trackresp = external::seguimiento_usuario($user->username, $courseid);
            if (!empty($trackresp['status']) && $trackresp['status']==='success') {
                $track = $trackresp['data'];
            }
        }

        // 3) Construir payload
        $payload = array_merge(
            $info['data'] ?? [],
            [
                'action'      => 'course_viewed',
                'moodle_domain'=> self::get_moodle_domain(),
                'userid'      => $userid,
                'username'    => $user->username,
                'firstname'   => $user->firstname,
                'lastname'    => $user->lastname,
                'courseid'    => $courseid,
                'timestamp'   => $time,
                'seguimiento' => $track,
            ]
        );

        // 4) Encolar
        self::enqueue_frappe_event($payload);
    }

    /**
     * Evento: el usuario ve el curso completo.
     */
    public static function course_viewed(\core\event\course_viewed $event) {
        $d = $event->get_data();
        $userid   = $d['userid'];
        $courseid = $d['contextinstanceid'];
        $time     = $d['timecreated'];
        self::handle_course_access($userid, $courseid, $time);
    }

    /**
     * Evento: el usuario ve cualquier módulo dentro del curso.
     */
    public static function course_module_viewed(\core\event\course_module_viewed $event) {
        $d = $event->get_data();
        $userid   = $d['userid'];
        $courseid = $d['courseid'];            // ojo, aquí el campo es courseid
        $time     = $d['timecreated'];
        self::handle_course_access($userid, $courseid, $time);
    }
    public static function user_graded(\core\event\user_graded $event) {
        global $DB;
        $userid   = $event->relateduserid;
        $courseid = $event->courseid; // Corrección: Obtenerlo directamente del evento

        $username = $DB->get_field('user', 'username', ['id' => $userid]);
        if (!$username) {
            
            return;
        }

        // Llamamos a tus funciones, pero ahora con el courseid correcto y sin consultas redundantes
        $course_specific_info = external::course_user_info($username, $courseid);
        $grades_response = external::obtener_clasificaciones_usuario($username, $courseid);
        
        $payload_completo = array_merge($course_specific_info['data'] ?? [], [
            'action'        => 'grade_updated',
            'moodle_domain' => self::get_moodle_domain(),
            'userid'        => $userid,
            'username'      => $username,
            'courseid'      => $courseid,
            'timestamp'     => $event->timecreated,
            'grades'        => $grades_response['data'] ?? [],
        ]);

        self::enqueue_frappe_event($payload_completo);
       
    }
    /**
 * Evento: un ítem de calificación ha sido creado en el gradebook.
 */
public static function grade_item_created(\core\event\grade_item_created $event) {
    global $DB;
    $data = $event->get_data();
    // El ID del curso suele venir en contextinstanceid
    $courseid = (int)$data['contextinstanceid'];
    $itemid   = (int)$data['objectid'];

    // 1) Llamamos a tu endpoint interno para obtener todos los ítems
    $items_response = external::obtener_items_calificador($courseid);
    if ($items_response['status'] !== 'success') {
        return;
    }

    // 2) Preparamos el payload
    $payload = [
        'action'        => 'grade_item_created',
        'moodle_domain' => self::get_moodle_domain(),
        'courseid'      => $courseid,
        'itemid'        => $itemid,
        'items' => $items_response['data'],
        'timestamp'     => $event->timecreated,
    ];

    // 3) Encolamos la notificación
    self::enqueue_frappe_event($payload);
}

/**
 * Evento: un ítem de calificación ha sido modificado.
 */
public static function grade_item_updated(\core\event\grade_item_updated $event) {
    global $DB;
    $data = $event->get_data();
    $courseid = (int)$data['contextinstanceid'];
    $itemid   = (int)$data['objectid'];

    $items_response = external::obtener_items_calificador($courseid);
    if ($items_response['status'] !== 'success') {
        return;
    }

    $payload = [
        'action'        => 'grade_item_updated',
        'moodle_domain' => self::get_moodle_domain(),
        'courseid'      => $courseid,
        'itemid'        => $itemid,
        'items' => $items_response['data'],
        'timestamp'     => $event->timecreated,
    ];

    self::enqueue_frappe_event($payload);
}

/**
 * Evento: un ítem de calificación ha sido eliminado.
 */
public static function grade_item_deleted(\core\event\grade_item_deleted $event) {
    global $DB;
    $data = $event->get_data();
    $courseid = (int)$data['contextinstanceid'];
    $itemid   = (int)$data['objectid'];

    $items_response = external::obtener_items_calificador($courseid);
    if ($items_response['status'] !== 'success') {
        return;
    }

    $payload = [
        'action'        => 'grade_item_deleted',
        'moodle_domain' => self::get_moodle_domain(),
        'courseid'      => $courseid,
        'itemid'        => $itemid,
        'items' => $items_response['data'],
        'timestamp'     => $event->timecreated,
    ];

    self::enqueue_frappe_event($payload);
}
}