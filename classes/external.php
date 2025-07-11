<?php
namespace local_frappe_integration;
defined('MOODLE_INTERNAL') || die();
global $CFG;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

// -----------------------------------------------------------------------------
// INCLUDES
// -----------------------------------------------------------------------------
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once(__DIR__ . '/../locallib.php');
require_once($CFG->dirroot . '/course/lib.php');

// Necesario para TCPDF
require_once($CFG->dirroot . '/lib/tcpdf/tcpdf.php');

// Dedication Atu block
$blockdir = \core_component::get_plugin_directory('block', 'dedication_atu');
require_once($blockdir . '/models/course.php');
require_once($blockdir . '/dedication_atu_lib.php');
require_once($blockdir . '/lib.php');

// -----------------------------------------------------------------------------
// CLASES TCPDF
// -----------------------------------------------------------------------------
/**
 * Para el ZIP de varios informes de grupo.
 */
class MYPDF extends \TCPDF {
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica','I',8);
        $this->Cell(
            0, 10,
            'Página '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),
            0, false, 'C', 0, '', 0, false, 'T', 'M'
        );
    }
}

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
            'generar_zip_informes_grupo' => 'generar_zip_informes_grupo',
            'generar_pdf_informe_usuario' => 'generar_pdf_informe_usuario',
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
public static function generar_pdf_conjunto_usuario($courseid, $username) {
    global $DB, $CFG;

    // 1) Validar
    $params = self::validate_parameters(
        new external_function_parameters([
            'courseid' => new external_value(PARAM_INT,      'ID de curso'),
            'username' => new external_value(PARAM_USERNAME, 'Username'),
        ]),
        compact('courseid','username')
    );

    // 2) Usuario
    $user = $DB->get_record('user',
        ['username'=>$params['username']], 'id, firstname, lastname', IGNORE_MISSING);
    if (!$user) {
        return ['status'=>'error','data'=>null,'message'=>'Usuario no existe'];
    }

    // 3) Obtengo todos los attempt de “prueba”
    $rs = $DB->get_records_sql("
        SELECT qa.id AS attemptid, qa.quiz
          FROM {quiz_attempts} qa
          JOIN {grade_items} gi ON gi.iteminstance = qa.quiz
         WHERE gi.courseid   = :c
           AND gi.itemname LIKE :k
           AND qa.userid     = :u
         ORDER BY qa.attempt
    ", [
        'c'=> $params['courseid'],
        'k'=> '%prueba%',
        'u'=> $user->id
    ]);
    if (empty($rs)) {
        return ['status'=>'error','data'=>null,'message'=>'No hay pruebas para este usuario'];
    }

    // 4) Construyo HTML combinado
    require_once($CFG->dirroot.'/blocks/dedication_atu/lib.php');
    $html = "<h2>Conjunto de pruebas: ".fullname($user)."</h2>";
    foreach ($rs as $r) {
        $cm = \get_coursemodule_from_instance('quiz', $r->quiz, $params['courseid'], false, MUST_EXIST);
        $html .= \libDedication_atu::devuelve_informe_respuestas_html(
            $r->attemptid, $cm->id, $params['courseid']
        );
        $html .= '<div style="page-break-after: always;"></div>';
    }

    // 5) Generar PDF “en memoria”
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetTitle('Conjunto de pruebas');
    $pdf->SetProtection(['modify']);
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);
    $pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN]);
    $pdf->setFooterFont([PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA]);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->AddPage();
    $pdf->SetFont('helvetica','',10);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->lastPage();

    // 6) Output en string (flag 'S')
    $rawpdf = $pdf->Output('', 'S');

    return [
        'status' => 'success',
        'data'   => base64_encode($rawpdf),
        'message'=> 'PDF generado correctamente'
    ];
}
public static function generar_pdf_informe_usuario($username, $courseid) {
    global $DB, $CFG;

    // 1) Validación de parámetros
    $params = self::validate_parameters(
        new external_function_parameters([
            'username' => new external_value(PARAM_USERNAME, 'Username en Moodle'),
            'courseid' => new external_value(PARAM_INT,      'ID de curso'),
        ]),
        compact('username', 'courseid')
    );

    // 2) Cargar el usuario
    $user = $DB->get_record('user',
        ['username' => $params['username']],
        'id, firstname, lastname',
        IGNORE_MISSING
    );
    if (!$user) {
        return ['status'=>'error','data'=>null,'message'=>'Usuario no existe'];
    }

    // 3) Incluir el lib del customreport para tener genera_informe_html()
    $reportdir = \core_component::get_plugin_directory('report', 'customreport');
    require_once($reportdir . '/lib.php');

    // 4) Generar el HTML completo (con la cabecera original, como querías)
    $html = genera_informe_html($params['courseid'], $user->id, true, null);
    // La ruta relativa que usa el HTML original
    $ruta_relativa_a_buscar = 'src="images/logo.png"';
    
    // La ruta física REAL donde se encuentra el logo en el sistema de archivos
    $ruta_fisica_real_logo = $CFG->dirroot . '/report/customreport/images/logo.png';

    // Verificamos que el logo existe para no causar errores
    if (file_exists($ruta_fisica_real_logo)) {
        // Leemos el contenido binario de la imagen
        $logo_data = file_get_contents($ruta_fisica_real_logo);
        // Obtenemos el tipo de imagen (png, jpg, etc.)
        $tipo_imagen = pathinfo($ruta_fisica_real_logo, PATHINFO_EXTENSION);
        // Creamos la cadena completa para la imagen incrustada en Base64
        $logo_incrustado_base64 = 'src="data:image/' . $tipo_imagen . ';base64,' . base64_encode($logo_data) . '"';
        
        // Reemplazamos la ruta rota por la imagen incrustada en el HTML
        $html = str_replace($ruta_relativa_a_buscar, $logo_incrustado_base64, $html);
    }
    // Si el logo no existe, no hacemos nada y el HTML se queda como está (con la ruta rota).
    // =========================================================================
    // FIN DE LA CORRECCIÓN
    // =========================================================================

    // 5) Montar el PDF “en memoria” con el HTML ya corregido
    // Usamos tu clase MYPDF, sin necesidad de crear una nueva.
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetTitle("Informe {$user->firstname} {$user->lastname}");
    
    $pdf->setPrintHeader(false); // Ponemos a false para evitar cualquier cabecera por defecto de TCPDF
    $pdf->setPrintFooter(true);  // Mantenemos el pie de página

    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

    $pdf->AddPage();
    $pdf->SetFont('helvetica','',10);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->lastPage();

    // 6) Devolver el PDF en Base64
    $raw = $pdf->Output('', 'S');
    return [
        'status'  => 'success',
        'data'    => base64_encode($raw),
        'message' => ''
    ];
}
public static function generar_zip_informes_grupo($courseid, $groupid) {
    global $DB, $CFG;

    // 1) Incluir librerías de tu customreport
    require_once($CFG->dirroot . '/report/customreport/lib.php');          // contiene genera_informe_html()
    require_once($CFG->dirroot . '/report/customreport/models/messages.php');
    require_once($CFG->dirroot . '/report/customreport/models/chats.php');

    // 2) Recuperar miembros del grupo
    $members = $DB->get_records_sql("
        SELECT u.id, u.firstname, u.lastname
          FROM {groups_members} gm
          JOIN {user} u ON u.id = gm.userid
         WHERE gm.groupid = :g
    ", ['g' => $groupid]);
    if (empty($members)) {
        return ['status'=>'error','data'=>null,'message'=>'No hay alumnos en el grupo'];
    }

    // 3) Crear archivo ZIP temporal
    $zipFile = tempnam(sys_get_temp_dir(), 'atu_zip_');
    $zip = new \ZipArchive();
    $res = $zip->open($zipFile, \ZipArchive::CREATE);
    if ($res !== true) {
        throw new \moodle_exception("Error abriendo ZIP (código $res) en $zipFile");
    }

    // 4) Generar un PDF por cada miembro y añadirlo al ZIP
    foreach ($members as $m) {
        // Instanciar tu clase TCPDF
        $pdf = new MYPDF( PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false );
        $pdf->SetTitle('Informe ATU');
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);
        $pdf->setHeaderFont([PDF_FONT_NAME_MAIN,'',PDF_FONT_SIZE_MAIN]);
        $pdf->setFooterFont([PDF_FONT_NAME_DATA,'',PDF_FONT_SIZE_DATA]);
        $pdf->SetFont('helvetica','',10);
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $pdf->AddPage();

        // Generar HTML con tu función global
        $html = genera_informe_html($courseid, $m->id, true, $groupid);
        if (empty(trim($html))) {
            throw new \moodle_exception("La función genera_informe_html devolvió contenido vacío para el usuario {$m->id}");
        }

        // Escribir HTML en el PDF
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->lastPage();

        // Volcar a un fichero temporal y añadirlo al ZIP
        $tmpPdf = tempnam(sys_get_temp_dir(), 'atu_pdf_');
        $pdf->Output($tmpPdf, 'F');
        $zip->addFile($tmpPdf, "Informe_{$m->lastname}_{$m->firstname}.pdf");
        @unlink($tmpPdf);
    }

    // 5) Cerrar ZIP y comprobar que contiene archivos
    $zip->close();
    if (filesize($zipFile) === 0) {
        throw new \moodle_exception("El ZIP generado está vacío: $zipFile");
    }

    // 6) Leer ZIP y devolverlo en Base64
    $zipData = file_get_contents($zipFile);
    @unlink($zipFile);

    return [
        'status'  => 'success',
        'data'    => base64_encode($zipData),
        'message' => ''
    ];
}
}
