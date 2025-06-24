<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_frappe_integration', 'Frappe Integration');

    // URL base de Frappe (editable; se usará como prefijo para todos los endpoints)
    $settings->add(new admin_setting_configtext(
        'local_frappe_integration/frappe_api_url',
        'URL de Frappe',
        'URL base de tu instancia de Frappe; se usará luego para construir cada endpoint (ej: {frappe_api_url}/api/method).',
        'https://erp.grupoatu.com',
        PARAM_URL
    ));

    // Token secreto para autenticar las peticiones
    $settings->add(new admin_setting_configtext(
        'local_frappe_integration/frappe_api_token',
        'Token secreto',
        'Token que usarás para autenticarte desde Moodle',
        '',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('localplugins', $settings);
}
