<?php
namespace local_frappe_integration\task;
defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;
use local_frappe_integration\observer;

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
        // Intentamos obtener el data
        $payload = (array)$this->get_custom_data();
        error_log('Frappe Integration Task: execute() iniciado. Payload: ' . json_encode($payload));

        // Si no hay datos, abortamos
        if (empty($payload)) {
            error_log('Frappe Integration Task: payload vacío, abortando.');
            return;
        }
        try {
            // Llamamos al notify real
            $response = observer::notify_frappe($payload);
            if ($response === false) {
                error_log('Frappe Integration Task: notify_frappe devolvió false (error en el post).');
            } else {
                error_log('Frappe Integration Task: notify_frappe OK. Respuesta: ' . $response);
            }
        } catch (\Exception $e) {
            error_log('Frappe Integration Task: excepción capturada en execute(): ' . $e->getMessage());
        }
    }
}
