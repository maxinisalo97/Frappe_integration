<?php
namespace local_frappe_integration;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once(__DIR__ . '/../locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
$blockdir = \core_component::get_plugin_directory('block', 'dedication_atu');
require_once($blockdir . '/models/course.php');
require_once($blockdir . '/dedication_atu_lib.php'); // aquí se define el manager y la constante por defecto
require_once($blockdir . '/lib.php');             // aquí está la clase libDedication_atu

global $CFG;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class external extends external_api {
    /**
     * Función helper para obtener el host sin protocolo
     */
    protected static function get_moodle_domain(): string {
        global $CFG;
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
        return $host ?: '';
    }

    /**
     * 1) Parámetros del endpoint genérico
     */
    public static function api_parameters(): external_function_parameters {
        return new external_function_parameters([
            'method'  => new external_value(PARAM_ALPHANUMEXT, 'Nombre del método interno a invocar'),
            'payload' => new external_value(PARAM_RAW_TRIMMED, 'JSON con parámetros para el método'),
        ]);
    }

    /**
     * 2) Dispatch genérico a cada método interno
     */
    public static function api($method, $payload) {
        global $DB;
        $params = self::validate_parameters(
            self::api_parameters(),
            compact('method','payload')
        );

        // Lista blanca de métodos permitidos
        $allowed = [
            'course_user_info' => 'course_user_info',
            'obtener_clasificaciones_usuario'=> 'obtener_clasificaciones_usuario',
            'obtener_items_calificador' => 'obtener_items_calificador',
            'seguimiento_usuario'            => 'seguimiento_usuario',
            'seguimiento_curso'              => 'seguimiento_curso',
            'generar_excel_seguimiento'      => 'generar_excel_seguimiento',
            'obtener_notas_curso'            => 'obtener_notas_curso',
            'generar_pdf_conjunto_usuario'   => 'generar_pdf_conjunto_usuario',
        ];

        if (!isset($allowed[$params['method']])) {
            throw new \invalid_parameter_exception('Método no permitido: ' . $params['method']);
        }

        // Decodifica payload JSON
        $args = json_decode($params['payload'], true);
        if (!is_array($args)) {
            throw new \invalid_parameter_exception('Payload JSON inválido');
        }

        // Llama al método interno estático
        $internal = $allowed[$params['method']];
        // Tras el call_user_func_array en api():
        $raw = call_user_func_array(
            [self::class, $internal],
            array_values($args)
        );

        // Si $raw['data'] es array u object, serialízalo.
        if (is_array($raw['data']) || is_object($raw['data'])) {
            $raw['data'] = json_encode($raw['data']);
        }

        return $raw;
    }

    /**
     * 3) Estructura de retorno genérica
     */
    public static function api_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, '"success" o "error"'),
            'data'    => new external_value(PARAM_RAW,  'Resultado del método interno'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje opcional en caso de error'),
        ]);
    }

    /**
     * Método interno: course_user_info
     */
    public static function course_user_info($username, $courseid) {
        global $DB;
        // reutilizamos la validación típica
        $params = self::validate_parameters(
            new external_function_parameters([
                'username' => new external_value(PARAM_ALPHANUMEXT, 'Username en Moodle'),
                'courseid' => new external_value(PARAM_INT, 'ID de curso')
            ]),
            compact('username','courseid')
        );

        // 1) Buscar usuario
        $user = $DB->get_record('user', ['username' => $params['username']], 'id, lastlogin', IGNORE_MISSING);
        if (!$user) {
            return ['status' => 'error', 'data' => null, 'message' => 'Usuario no existe'];
        }
        $uid = (int)$user->id;

        // 2) Verificar curso
        if (!$DB->record_exists('course', ['id' => $params['courseid']])) {
            return ['status' => 'error', 'data' => null, 'message' => 'Curso no existe'];
        }

        // 3) Primer acceso
        $first = $DB->get_field_sql(
            "SELECT MIN(timecreated) FROM {logstore_standard_log} WHERE userid = :u AND courseid = :c",
            ['u'=>$uid,'c'=>$params['courseid']]
        );

        // 4) Último acceso al curso
        $last = $DB->get_field(
            'user_lastaccess', 'timeaccess', ['userid'=>$uid,'courseid'=>$params['courseid']]
        );

        // 5) Online
        $threshold = time() - 300;
        $online = (bool)$DB->record_exists_select(
            'sessions', 'userid = :u AND timemodified > :t', ['u'=>$uid,'t'=>$threshold]
        );

        // Resultado
        $result = [
            'moodle_domain' => self::get_moodle_domain(),
            'firstaccess'   => $first ? (int)$first : null,
            'lastaccess'    => $last  ? (int)$last  : null,
            'lastlogin'     => (int)$user->lastlogin,
            'online'        => $online
        ];

        return [
            'status'=>'success',
            'data'=>$result,      // array puro
            'message'=>''
          ];
    }
        /**
     * Método interno: obtener_clasificaciones_usuario
     *   Parámetros: ['username' => string, 'courseid' => int]
     * Devuelve: lista ordenada de items con:
     *   item, calculated_weight, grade, range, percentage, feedback, contribution_to_course
     */

     public static function obtener_clasificaciones_usuario($username, $courseid) {
        global $DB, $CFG;
    
        // 1) Carga librerías de Gradebook
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');
    
        // 2) Valida parámetros
        $params = self::validate_parameters(
            new external_function_parameters([
                'username' => new external_value(PARAM_ALPHANUMEXT, 'Username en Moodle'),
                'courseid' => new external_value(PARAM_INT,       'ID de curso'),
            ]),
            compact('username','courseid')
        );
    
        // 3) Busca usuario
        $user = $DB->get_record('user',
            ['username' => $params['username']],
            'id',
            IGNORE_MISSING
        );
        if (!$user) {
            return ['status'=>'error','data'=>null,'message'=>'Usuario no existe'];
        }
        $uid = (int)$user->id;
    
        // 4) Trae todos los ítems de calificación
        $gradeitems = \grade_item::fetch_all(['courseid' => $params['courseid']]);
        if (empty($gradeitems)) {
            return ['status'=>'error','data'=>null,'message'=>'No hay ítems de calificación en el curso'];
        }
    
        // 5) Calcula la suma total de coeficientes para determinar pesos
        $sumcoef = 0;
        $countnonzero = 0;
        foreach ($gradeitems as $gi) {
            if ($gi->itemtype === 'course') {
                continue;
            }
            $coef = (float)$gi->aggregationcoef;
            if ($coef > 0) {
                $sumcoef += $coef;
                $countnonzero++;
            }
        }
        // Si todos los coefs son cero, usamos conteo igualitario
        if ($sumcoef <= 0) {
            // número de ítems no-course
            $totalitems = 0;
            foreach ($gradeitems as $gi) {
                if ($gi->itemtype !== 'course') {
                    $totalitems++;
                }
            }
            $equalWeight = $totalitems > 0 ? 100.0 / $totalitems : 0;
        }
    
        $rows = [];
        foreach ($gradeitems as $gi) {
            // 6) Saltar total del curso
            if ($gi->itemtype === 'course') {
                continue;
            }
    
            // 7) Carga nota del usuario
            $gg = \grade_grade::fetch(['itemid'=>$gi->id,'userid'=>$uid], IGNORE_MISSING);
            if (!$gg) {
                continue;
            }
    
            // 8) Formatea nota y porcentaje interno
            $gradeval  = $gg->finalgrade;
            $formatted = ($gradeval === null) ? null : round($gradeval, 2);
    
            $raw        = $gg->rawgrade;
            $percentage = null;
            if ($raw !== null && $gi->grademax > $gi->grademin) {
                $percentage = round((($raw - $gi->grademin) / ($gi->grademax - $gi->grademin)) * 100, 2);
            }
    
            // 9) Calcula el peso relativo dentro del curso
            $coef = (float)$gi->aggregationcoef;
            if ($sumcoef > 0) {
                $weightpct = round($coef / $sumcoef * 100, 2);
            } else {
                $weightpct = round($equalWeight, 2);
            }
    
            $rows[] = [
                'item'                   => $gi->get_name(false),
                'calculated_weight'      => $weightpct,        // porcentaje de peso
                'grade'                  => $formatted,
                'range'                  => "{$gi->grademin}-{$gi->grademax}",
                'percentage'             => $percentage,
                'feedback'               => $gg->feedback,
                'contribution_to_course' => $weightpct,        // igual que calculated_weight
                'sortorder'              => $gi->sortorder,
            ];
        }
    
        // 10) Ordenar y limpiar sortorder
        usort($rows, function($a,$b){ return $a['sortorder'] - $b['sortorder']; });
        foreach ($rows as &$r) {
            unset($r['sortorder']);
        }
    
        return ['status'=>'success','data'=>$rows,'message'=>''];
    }
    /**
 * Método interno: obtener_items_calificador
 *   Parámetros: ['courseid' => int]
 * Devuelve: lista de ítems de calificación del curso ordenados
 */
public static function obtener_items_calificador($courseid) {
    global $DB, $CFG;

    // 1) Validación de parámetros
    $params = self::validate_parameters(
        new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'ID de curso'),
        ]),
        compact('courseid')
    );

    // 2) Comprobar que el curso existe
    if (!$DB->record_exists('course', ['id' => $params['courseid']])) {
        return ['status'=>'error','data'=>null,'message'=>'Curso no existe'];
    }

    // 3) Cargar librería de Gradebook
    require_once($CFG->libdir . '/grade/grade_item.php');

    // 4) Recuperar todos los ítems de calificación del curso
    $gradeitems = \grade_item::fetch_all(['courseid' => $params['courseid']]);
    if (empty($gradeitems)) {
        return ['status'=>'error','data'=>null,'message'=>'No hay ítems de calificación en el curso'];
    }

    // 5) Ordenar por sortorder
    usort($gradeitems, function($a, $b) {
        return $a->sortorder - $b->sortorder;
    });

    // 6) Formatear resultado
    $rows = [];
    foreach ($gradeitems as $gi) {
        if ($gi->itemtype === 'course') {
            continue;  // omitimos el Total del curso
        }
        $rows[] = [
            'id'              => $gi->id,
            'itemname'        => $gi->get_name(false),
            'itemtype'        => $gi->itemtype,
            'aggregationcoef' => (float)$gi->aggregationcoef,
            'grademin'        => $gi->grademin,
            'grademax'        => $gi->grademax,
            'sortorder'       => $gi->sortorder,
        ];
    }

    // 7) Devolver respuesta
    return [
        'status'  => 'success',
        'data'    => $rows,
        'message' => ''
    ];
}
/**
 * Devuelve el tiempo total (en segundos) y el avance de contenidos de un usuario.
 * @param string $username
 * @param int    $courseid
 * @return array status,data,message
 */
public static function seguimiento_usuario($username, $courseid) {
    global $DB, $CFG;
    

    // 1) Validación de parámetros
    $params = self::validate_parameters(
        new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Username en Moodle'),
            'courseid' => new external_value(PARAM_INT,      'ID de curso'),
        ]),
        compact('username','courseid')
    );

    // 2) Usuario
    $user = $DB->get_record('user',
        ['username' => $params['username']],
        'id, username, firstname, lastname',
        IGNORE_MISSING
    );
    if (!$user) {
        return ['status'=>'error','data'=>null,'message'=>'Usuario no existe'];
    }

    // 3) Curso
    if (!$DB->record_exists('course',['id'=>$params['courseid']])) {
        return ['status'=>'error','data'=>null,'message'=>'Curso no existe'];
    }
    $course = $DB->get_record('course',['id'=>$params['courseid']]);

    // 4) Tiempo dedicado
    require_once($CFG->dirroot . '/blocks/dedication_atu/models/course.php');
    require_once($CFG->dirroot . '/blocks/dedication_atu/lib.php');
    $mintime = $course->startdate;
    $maxtime = time();
    $limit   = BLOCK_DEDICATION_DEFAULT_SESSION_LIMIT;
    $dm      = new \block_dedication_atu_manager($course, $mintime, $maxtime, $limit);

    $sessions = $dm->get_user_dedication_atu($user);
    $totalsecs     = 0;
    $sessions_data = [];
    foreach ($sessions as $s) {
        $totalsecs += $s->dedicationtime;
        $sessions_data[] = [
            'start_date'       => userdate($s->start_date, '%Y-%m-%d %H:%M:%S'),
            'duration_seconds' => $s->dedicationtime,
            'ips'              => $s->ips,
        ];
    }
    $sessioncount = count($sessions_data);
    $meansecs     = $sessioncount ? round($totalsecs / $sessioncount, 2) : 0;

    // 5) Avance de contenidos
    $compinfo   = \courseModel::getCompletions($params['courseid'], $user->id);
    $completed  = $compinfo['no_of_completed'];
    $total      = isset($compinfo['activities']) ? count($compinfo['activities']) : 0;
    $percent    = $total ? round($completed / $total * 100, 2) : 0;

    // 6) Ítems de prueba y notas con filtro 
    $clave = 'prueba';
    $sql = "
        SELECT
          gi.id               AS id_examen,
          gi.itemtype         AS tipo_prueba,
          gi.itemname         AS nombre_prueba,
          COALESCE(
            ROUND(gg.finalgrade,2),
            ROUND(gg.rawgrade,2)
          )                   AS nota
        FROM {grade_items} gi
        LEFT JOIN {grade_grades} gg
          ON gg.itemid = gi.id
         AND gg.userid = :userid
        WHERE gi.courseid = :courseid
          AND gi.itemname LIKE :like
        ORDER BY gi.sortorder
    ";
    $params_sql = [
        'courseid' => $course->id,
        'userid'   => $user->id,
        'like'     => "%{$clave}%",
    ];
    $records = $DB->get_records_sql($sql, $params_sql);

    $items_list  = [];
    $user_grades = [];
    foreach ($records as $r) {
        $items_list[] = [
            'id'   => $r->id_examen,
            'name' => $r->nombre_prueba,
            'type' => $r->tipo_prueba,
        ];
        $user_grades[] = [
            'itemid'      => $r->id_examen,
            'final_grade' => $r->nota,  // null si no tiene nota
        ];
    }
    require_once($CFG->dirroot . '/group/lib.php');   // <<< grupos
    list($usergroupids, $unused) = groups_get_user_groups($courseid, $user->id);
    $groups = [];
    if (!empty($usergroupids)) {
        // traerse id y name de todos, si puede estar en varios
        $groups = array_values($DB->get_records_list(
            'groups', 'id', $usergroupids,
            'name ASC', 'id, name'
        ));
    }

    // 7) Montaje de la respuesta
    $data = [
        'userid'               => $user->id,
        'username'             => $user->username,
        'firstname'            => $user->firstname,
        'lastname'             => $user->lastname,
        'time_spent_seconds'   => $totalsecs,
        'session_count'        => $sessioncount,
        'mean_session_seconds' => $meansecs,
        'sessions'             => $sessions_data,
        'completed_activities' => $completed,
        'total_activities'     => $total,
        'progress_percent'     => $percent,
        'grade_items'          => $items_list,
        'user_grades'          => $user_grades,
        'groups'               => $groups,  // array de grupos con id y name
    ];

    return ['status'=>'success','data'=>$data,'message'=>''];
}



/**
 * Devuelve un listado con seguimiento (tiempo + avance) de todos los alumnos de un curso.
 * @param int $courseid
 * @return array status,data,message
 */
public static function seguimiento_curso($courseid, $groupname = '') {
    global $DB;

    // 1) Validación de parámetros
    $params = self::validate_parameters(
        new external_function_parameters([
            'courseid'  => new external_value(PARAM_INT,  'ID de curso'),
            'groupname' => new external_value(PARAM_TEXT, 'Nombre de grupo (vacío = todos)', VALUE_DEFAULT, ''),
        ]),
        compact('courseid','groupname')
    );

    // 2) Comprobar curso
    if (!$DB->record_exists('course', ['id' => $params['courseid']])) {
        return ['status'=>'error','data'=>null,'message'=>'Curso no existe'];
    }
    $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

    // 3) Recuperar todos los grupos del curso
    $allgroups = $DB->get_records('groups', ['courseid' => $course->id]);

    // 4) Identificar groupid a partir de groupname (número, name exacto, normalizado, idnumber)
    $groupid = 0;
    $gname   = trim($params['groupname']);
    if ($gname !== '') {
        if (ctype_digit($gname)) {
            $groupid = (int)$gname;
        } else {
            // exacto
            $group = $DB->get_record('groups', ['courseid'=>$course->id,'name'=>$gname], 'id', IGNORE_MISSING);
            // guiones bajos ↔ espacios (case-insensitive)
            if (!$group) {
                $normalized = str_ireplace('_', ' ', $gname);
                $group = $DB->get_record_sql(
                    "SELECT id FROM {groups} WHERE courseid = :cid AND LOWER(name) = LOWER(:n)",
                    ['cid'=>$course->id, 'n'=>$normalized], IGNORE_MISSING
                );
            }
            // idnumber
            if (!$group) {
                $group = $DB->get_record('groups', ['courseid'=>$course->id,'idnumber'=>$gname], 'id', IGNORE_MISSING);
            }
            if ($group) {
                $groupid = $group->id;
            }
        }
        if ($groupid === 0) {
            return ['status'=>'error','data'=>null,'message'=>'Grupo "'.s($params['groupname']).'" no existe en este curso'];
        }
    }

    // 5) Recuperar usuarios: filtrado por grupo si aplica
    $context = \context_course::instance($course->id);
    if ($groupid > 0) {
        $students = get_enrolled_users($context, '', $groupid);
    } else {
        $students = get_enrolled_users($context);
    }

    // 6) Reutilizar seguimiento_usuario()
    $result = [];
    foreach ($students as $stu) {
        $resp = self::seguimiento_usuario($stu->username, $course->id);
        if ($resp['status'] === 'success') {
            $result[] = $resp['data'];
        }
    }

    // 7) Devolver datos y lista de grupos
    return [
        'status' => 'success',
        'groups' => array_values($allgroups),  // array indexado con objetos: id, courseid, name, idnumber, etc.
        'data'   => $result,
        'message'=> ''
    ];
}


    /**
 * Método interno: generar_excel_seguimiento
 *   Parámetros: ['courseid' => int]
 * Devuelve el fichero Excel binario del Resumen de Evaluación ATU.
 */
/**
 * Método interno: generar_excel_seguimiento
 *   Parámetros: ['courseid' => int]
 * Devuelve el fichero Excel binario del Resumen de Evaluación ATU.
 */
public static function generar_excel_seguimiento($courseid, $groupname = '') {
    global $DB, $CFG;

    // 1) Validación de parámetros
    $params = self::validate_parameters(
        new external_function_parameters([
            'courseid'  => new external_value(PARAM_INT,  'ID de curso'),
            'groupname' => new external_value(PARAM_TEXT, 'Nombre de grupo (vacío = todos)', VALUE_DEFAULT, ''),
        ]),
        compact('courseid','groupname')
    );

    // 2) Obtener datos puros de seguimiento
    $resp = self::seguimiento_curso($params['courseid'], $params['groupname']);
    if ($resp['status'] !== 'success') {
        return ['status'=>'error','data'=>null,'message'=>'No se pudo obtener datos de seguimiento'];
    }
    $rows_data = $resp['data'];

    // 3) Reconstruir las cabeceras
    $cabeceras = [
        get_string('firstname'),
        get_string('lastname'),
        'DNI',
        get_string('tiempototal', 'block_dedication_atu'),
        get_string('avancecontenidos', 'block_dedication_atu'),
    ];

    // 3.2) Dinámicas: mismas pruebas que en seguimiento_usuario
    $clave = 'prueba';
    $sql_items = "
        SELECT
            gi.id            AS id_examen,
            gi.itemname      AS nombre_prueba
        FROM {grade_items} gi
        WHERE gi.courseid = :courseid
          AND gi.itemname LIKE :like
        ORDER BY gi.sortorder
    ";
    $pruebas = $DB->get_records_sql($sql_items, [
        'courseid' => $params['courseid'],
        'like'     => "%{$clave}%"
    ]);
    foreach ($pruebas as $p) {
        $cabeceras[] = $p->nombre_prueba;
    }

    // 3.3) Columnas finales
    $cabeceras[] = 'MEDIA';
    $cabeceras[] = 'CONJUNTO';

    // 4) Montar filas de exportación
    $exportrows = [];
    $exportrows[] = $cabeceras;

    foreach ($rows_data as $d) {
        $flat = [];

        // 4.1) firstname, lastname, DNI
        $flat[] = $d['firstname'];
        $flat[] = $d['lastname'];
        $flat[] = $d['username'];

        // 4.2) tiempo total → HH:MM:SS
        $secs = (int)$d['time_spent_seconds'];
        $flat[] = sprintf(
            '%02d:%02d:%02d',
            floor($secs/3600),
            floor(($secs%3600)/60),
            $secs%60
        );

        // 4.3) avance contenidos → "x/y (z%)"
        $flat[] = sprintf(
            "%d/%d (%.2f%%)",
            $d['completed_activities'],
            $d['total_activities'],
            $d['progress_percent']
        );

        // 4.4) cada prueba en el orden de $pruebas
        foreach ($pruebas as $p) {
            $nota = '-';
            foreach ($d['user_grades'] as $ug) {
                if ($ug['itemid'] == $p->id_examen) {
                    $nota = ($ug['final_grade'] !== null)
                          ? round($ug['final_grade'], 2)
                          : '-';
                    break;
                }
            }
            $flat[] = $nota;
        }

        // 4.5) MEDIA (promedio simple de las notas numéricas)
        $sum = 0; $count = 0;
        foreach ($d['user_grades'] as $ug) {
            if ($ug['final_grade'] !== null) {
                $sum += $ug['final_grade'];
                $count++;
            }
        }
        $flat[] = $count ? round($sum/$count, 2) : '-';

        // 4.6) CONJUNTO (dejamos en blanco o calcula aquí si lo necesitas)
        $flat[] = '';

        $exportrows[] = $flat;
    }

    // 5) Volcar a Excel con tu helper del bloque
    require_once($CFG->dirroot . '/blocks/dedication_atu/models/course.php');
    require_once($CFG->dirroot . '/blocks/dedication_atu/lib.php');
    $course = $DB->get_record('course', ['id'=>$params['courseid']], '*', MUST_EXIST);
    $dm = new \block_dedication_atu_manager(
        $course,
        $course->startdate,
        time(),
        BLOCK_DEDICATION_DEFAULT_SESSION_LIMIT
    );

    ob_start();
    $dm->download_students_pruebas($exportrows);
    $excel = ob_get_clean();

    // 6) Devolver Base64
    return [
        'status'  => 'success',
        'data'    => base64_encode($excel),
        'message' => ''
    ];
}

/**
 * Método interno: obtener_notas_items_usuarios
 *   Parámetros: ['courseid' => int]
 * Devuelve: lista de usuarios con sus calificaciones en cada ítem del curso.
 */
public static function obtener_notas_curso($courseid) {
    global $DB, $CFG;

    // 1) Validación de parámetros
    $params = self::validate_parameters(
        new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'ID de curso'),
        ]),
        compact('courseid')
    );

    // 2) Comprobar que el curso existe
    if (!$DB->record_exists('course', ['id' => $params['courseid']])) {
        return ['status'=>'error','data'=>null,'message'=>'Curso no existe'];
    }

    // 3) Cargar librerías de Gradebook
    require_once($CFG->libdir . '/grade/grade_item.php');
    require_once($CFG->libdir . '/grade/grade_grade.php');

    // 4) Recuperar TODOS los ítems, en orden de sortorder (incluye categorías)
    $allitems = \grade_item::fetch_all(['courseid' => $params['courseid']]) ?: [];
    $items = [];
    foreach ($allitems as $gi) {
        // Sólo saltamos el "Total del curso" (itemtype = 'course')
        if ($gi->itemtype === 'course') {
            continue;
        }
        $items[] = [
            'id'      => $gi->id,
            'name'    => $gi->get_name(true),             // nombre tal cual Moodle
            'itemtype'=> $gi->itemtype,                   // 'mod', 'manual', 'category', …
            'min'     => $gi->grademin,
            'max'     => $gi->grademax,                   // nota máxima
            'weight'  => (float)$gi->aggregationcoef,     // peso en el curso
            'sort'    => $gi->sortorder,
        ];
    }

    // 5) Obtener los usuarios matriculados en el curso
    $context = \context_course::instance($params['courseid']);
    $users = get_enrolled_users($context);

    // 6) Para cada usuario, obtener la nota y el feedback de cada ítem
    $result = [];
    foreach ($users as $u) {
        $userRow = [
            'userid'    => $u->id,
            'username'  => $u->username,
            'firstname' => $u->firstname,
            'lastname'  => $u->lastname,
            'grades'    => [],
        ];
        foreach ($items as $item) {
            $gg = \grade_grade::fetch([
                'itemid' => $item['id'],
                'userid' => $u->id
            ], IGNORE_MISSING);

            if ($gg) {
                $grade    = $gg->finalgrade !== null ? round($gg->finalgrade, 2) : '-';
                $feedback = $gg->feedback ?? '';
            } else {
                $grade    = '-';
                $feedback = '';
            }

            $userRow['grades'][] = [
                'itemid'   => $item['id'],
                'grade'    => $grade,
                'feedback' => $feedback,
            ];
        }
        $result[] = $userRow;
    }

    // 7) Devolver datos
    return [
        'status' => 'success',
        'data'   => [
            'items' => $items,
            'users' => $result,
        ],
        'message'=> ''
    ];
}

    /**
     * Define los parámetros para la función generar_pdf_conjunto_usuario.
     * @return external_function_parameters
     */
    public static function generar_pdf_conjunto_usuario_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'ID del curso.'),
            'username' => new external_value(PARAM_USERNAME, 'Nombre de usuario del estudiante.'),
        ]);
    }

    /**
     * Genera un único PDF que combina todos los informes de "prueba" para un usuario en un curso.
     * @param int $courseid
     * @param string $username
     * @return array
     */
    public static function generar_pdf_conjunto_usuario($courseid, $username) {
        global $DB, $CFG;

        // 1) Validar los parámetros de entrada.
        // Esto asegura que los tipos de datos son correctos antes de usarlos.
        $params = self::validate_parameters(self::generar_pdf_conjunto_usuario_parameters(), [
            'courseid' => $courseid,
            'username' => $username
        ]);

        // 2) Buscar al usuario en la base de datos de Moodle.
        $user = $DB->get_record('user', ['username' => $params['username']], 'id, firstname, lastname', IGNORE_MISSING);
        if (!$user) {
            // Si el usuario no existe, devolvemos un error claro.
            throw new invalid_parameter_exception('El usuario especificado no existe.');
        }

        // 3) Recoger todos los intentos de cuestionarios cuyo nombre contenga "prueba".
        // Esta es la consulta clave para encontrar los intentos que queremos combinar.
        $clave_busqueda = 'prueba';
        $sql = "
            SELECT qa.id AS attemptid, qa.quiz
              FROM {quiz_attempts} qa
              JOIN {grade_items} gi ON gi.iteminstance = qa.quiz AND gi.itemmodule = 'quiz'
             WHERE gi.courseid = :courseid
               AND gi.itemname LIKE :like
               AND qa.userid = :userid
             ORDER BY qa.timestart, qa.attempt
        ";
        $intentos = $DB->get_records_sql($sql, [
            'courseid' => $params['courseid'],
            'like'     => "%{$clave_busqueda}%",
            'userid'   => $user->id
        ]);

        if (empty($intentos)) {
            // Si no hay intentos que cumplan la condición, informamos del problema.
            throw new moodle_exception('notenoughdata', 'quiz', '', null, 'No se encontraron pruebas para este usuario en el curso especificado.');
        }

        // 4) Cargar la librería del bloque `dedication_atu` para acceder a sus funciones.
        // Es fundamental para poder reutilizar su lógica de generación de informes y PDFs.
        $lib_path = $CFG->dirroot . '/blocks/dedication_atu/lib.php';
        if (!file_exists($lib_path)) {
             throw new moodle_exception('invaliddblockfile', 'block_dedication_atu', '', null, 'No se encuentra el fichero lib.php del bloque dedication_atu.');
        }
        require_once($lib_path);
        
        // Verificamos que las funciones que vamos a usar existen.
        if (!function_exists('\libDedication_atu::devuelve_informe_respuestas_html') || !function_exists('\libDedication_atu::genera_pdf_prueba')) {
            throw new moodle_exception('undefinedfunction', 'block_dedication_atu', '', null, 'Las funciones necesarias de lib.php no están disponibles.');
        }

        // 5) Preparar el contenido HTML que se convertirá en PDF.
        // Primero, el CSS para que el estilo sea idéntico al del bloque original.
        $css = <<<HTML
<style>
    * { box-sizing: border-box; font-family: sans-serif; font-size: 11px; }
    body { margin: 2cm; }
    h2 { font-size: 1.5em; color: #333; border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-bottom: 20px; }
    table.quizreviewsummary { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
    table.quizreviewsummary th, table.quizreviewsummary td { border: 1px solid #ddd; padding: 8px; background-color: #f9f9f9; }
    table.quizreviewsummary th { color: #3e65a0; font-weight: bold; text-align: right; padding-right: 10px; width: 30%; }
    div.que { border: 1px solid #cad0d7; margin-top: 2rem; background-color: #fff; padding: 1rem; }
    div.info { width: 10rem; height: auto; padding: 1rem; background-color: #dee2e6; border: 1px solid #cad0d7; margin-bottom: 1rem; }
    div.formulation { width: 100%; color: #2f6473; background-color: #def2f8; border: 1px solid #d1edf6; padding: 1rem; margin-bottom: 1rem; }
    div.outcome { width: 100%; color: #7d5a29; background-color: #fcefdc; border: 1px solid #fbe8cd; padding: 1rem; }
    .page-break { page-break-after: always; }
</style>
HTML;

        // Segundo, el cuerpo del documento, iterando sobre cada intento.
        $html_cuerpo = "<h2>Conjunto de pruebas: {$user->firstname} {$user->lastname}</h2>";
        $total_intentos = count($intentos);
        $contador = 0;

        foreach ($intentos as $intento) {
            $contador++;
            
            // Obtenemos el ID del módulo del curso (cmid), necesario para la función.
            $cm = get_coursemodule_from_instance('quiz', $intento->quiz, $params['courseid'], false, MUST_EXIST);

            // Llamamos a la función de la librería para obtener el HTML de un solo informe.
            // Esta función debe devolver solo el fragmento de HTML del informe.
            $html_cuerpo .= \libDedication_atu::devuelve_informe_respuestas_html(
                $intento->attemptid,
                $cm->id,
                $params['courseid']
            );

            // Añadimos un salto de página después de cada informe, excepto en el último.
            if ($contador < $total_intentos) {
                $html_cuerpo .= '<div class="page-break"></div>';
            }
        }

        // Tercero, ensamblamos el documento HTML final y completo.
        $html_completo = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF--8">
    <title>Conjunto de pruebas</title>
    {$css}
</head>
<body>
    {$html_cuerpo}
</body>
</html>
HTML;
        
        // 6) Generar el PDF usando la función de la librería y capturar su salida.
        // Usamos ob_start/ob_get_clean porque la función del bloque probablemente
        // imprime el PDF directamente en lugar de devolverlo como una variable.
        ob_start();
        \libDedication_atu::genera_pdf_prueba($html_completo, "Conjunto_{$user->username}_{$courseid}");
        $pdf_contenido_raw = ob_get_clean();

        if (empty($pdf_contenido_raw)) {
            throw new \moodle_exception('pdfgenerationfailed', 'core', '', null, 'La generación del PDF no produjo ningún contenido.');
        }

        // 7) Devolver la respuesta final en el formato esperado por la API.
        // El contenido del PDF se codifica en Base64 para su transmisión segura.
        return [
            'status'  => 'success',
            'data'    => base64_encode($pdf_contenido_raw),
            'message' => 'PDF generado correctamente con ' . $total_intentos . ' informe(s).'
        ];
    }
    
    /**
     * Define el tipo de dato que devuelve la función.
     * @return external_description
     */
    public static function generar_pdf_conjunto_usuario_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'Estado de la operación: "success" o "error".'),
            'data'    => new external_value(PARAM_BASE64, 'Contenido del PDF codificado en Base64.', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'Mensaje descriptivo del resultado.'),
        ]);
    }

}
