<?php
namespace local_frappe_integration\task;
defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;
use local_frappe_integration\observer;
use local_frappe_integration\event\frappe_error;
use context_system;

class send_frappe_event extends adhoc_task {
    /**
     * Descripción legible para el UI de tareas.
     */
    public function get_name(): string {
        return get_string('tasksendfrappeevent', 'local_frappe_integration');
    }

    /**
     * Ejecuta la tarea: aquí sí mandamos el POST.
     */
    public function execute() {
        $payload = (array)$this->get_custom_data();

        // Si no hay datos, abortamos sin log adicional.
        if (empty($payload)) {
            return;
        }

        try {
            $response = observer::notify_frappe($payload);
            if ($response === false) {
                // Si notify_frappe devolvió false, lanzamos un evento de error
                frappe_error::create([
                    'context' => context_system::instance(),
                    'other'   => ['message' => 'notify_frappe returned false for payload: '. json_encode($payload)]
                ])->trigger();
            }
        } catch (\Exception $e) {
            // Único punto donde hacemos log de la excepción
            frappe_error::create([
                'context' => context_system::instance(),
                'other'   => ['message' => 'Exception in send_frappe_event: '. $e->getMessage()]
            ])->trigger();
        }
    }
}
