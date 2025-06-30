<?php
namespace local_frappe_integration\event;
defined('MOODLE_INTERNAL') || die();

use core\event\base;

class frappe_error extends base {
    protected function init(): void {
        $this->data['objecttable'] = 'user';    // La usamos por defecto, ya que debe tener una tabla "asociada”
        $this->data['crud']        = 'e';       // e = error
        $this->data['edulevel']    = self::LEVEL_OTHER;
    }

    public static function get_name(): string {
        return get_string('eventfrapperror', 'local_frappe_integration');
    }

    public function get_description(): string {
        return "Error en integración con Frappe: ".$this->other['message'];
    }
}
