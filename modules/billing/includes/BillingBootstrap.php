<?php
if (!defined('billing')) {
    define('billing', 'billing');
}
if (!defined('_billing')) {
    define('_billing', 'Facturacion');
}

function billing_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function billing_sql($value)
{
    return str_replace("'", "''", trim((string) $value));
}

function billing_money($value)
{
    return number_format((float) $value, 2, ',', '.') . ' EUR';
}

function billing_user_id()
{
    return User('STAFF_ID') ? User('STAFF_ID') : User('STUDENT_ID');
}

function billing_table_exists($table)
{
    $ret = DBGet(DBQuery("SHOW TABLES LIKE '" . billing_sql($table) . "'"));
    return count($ret) > 0;
}

function billing_require_schema()
{
    if (billing_table_exists('billing_configuration')) {
        return true;
    }

    echo '<div class="alert bg-warning alert-styled-left">';
    echo 'Modulo de facturacion pendiente de instalar. Ejecuta <code>modules/billing/sql/001_billing_schema.sql</code>.';
    echo '</div>';
    return false;
}

function billing_draw_header($title, $action = '')
{
    DrawBC(_billing . ' > ' . $title);
    echo '<div class="panel panel-white">';
    DrawHeader($title, $action);
}

function billing_close_panel()
{
    echo '</div>';
}

function billing_alert($message, $type)
{
    $class = $type == 'error' ? 'bg-danger' : 'bg-success';
    echo '<div class="alert ' . $class . ' alert-styled-left">' . billing_h($message) . '</div>';
}

function billing_field($name, $label, $value, $type)
{
    echo '<div class="form-group col-md-6">';
    echo '<label>' . billing_h($label) . '</label>';
    echo '<input class="form-control" type="' . billing_h($type) . '" name="' . billing_h($name) . '" value="' . billing_h($value) . '">';
    echo '</div>';
}

function billing_select($name, $label, $value, $options)
{
    echo '<div class="form-group col-md-6"><label>' . billing_h($label) . '</label>';
    echo '<select class="form-control" name="' . billing_h($name) . '">';
    foreach ($options as $key => $title) {
        echo '<option value="' . billing_h($key) . '"' . ((string) $key == (string) $value ? ' selected' : '') . '>' . billing_h($title) . '</option>';
    }
    echo '</select></div>';
}

function billing_post($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function billing_get($key, $default = '')
{
    return isset($_REQUEST[$key]) ? $_REQUEST[$key] : $default;
}

function billing_insert($table, $data)
{
    $fields = array();
    $values = array();
    foreach ($data as $field => $value) {
        $fields[] = $field;
        $values[] = $value === null ? 'NULL' : "'" . billing_sql($value) . "'";
    }
    DBQuery('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
}

function billing_update($table, $data, $where)
{
    $pairs = array();
    foreach ($data as $field => $value) {
        $pairs[] = $field . '=' . ($value === null ? 'NULL' : "'" . billing_sql($value) . "'");
    }
    DBQuery('UPDATE ' . $table . ' SET ' . implode(',', $pairs) . ' WHERE ' . $where);
}

function billing_audit($action, $entity, $entity_id, $before, $after)
{
    if (!billing_table_exists('billing_audit_log')) {
        return;
    }

    billing_insert('billing_audit_log', array(
        'user_id' => billing_user_id(),
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entity_id,
        'old_values' => $before,
        'new_values' => $after,
        'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        'origin' => 'openSIS',
    ));
}

class BillingFiscalAdapter
{
    function recordInvoice($invoice_id)
    {
    }
}

class LocalFiscalRecordAdapter extends BillingFiscalAdapter
{
    function recordInvoice($invoice_id)
    {
        $last = DBGet(DBQuery('SELECT hash FROM billing_invoice_records ORDER BY id DESC LIMIT 1'));
        $previous = isset($last[1]['HASH']) ? $last[1]['HASH'] : '';
        $payload = $invoice_id . '|' . $previous . '|' . date('c');
        $hash = hash('sha256', $payload);

        billing_insert('billing_invoice_records', array(
            'invoice_id' => $invoice_id,
            'record_type' => 'INVOICE_ISSUED',
            'recorded_at' => date('Y-m-d H:i:s'),
            'hash' => $hash,
            'previous_hash' => $previous,
            'version' => 'local-1',
            'fiscal_payload' => $payload,
            'aeat_status' => 'NOT_SENT',
        ));

        return $hash;
    }
}

class BillingCalculator
{
    function simulate($period_start, $period_end)
    {
        $students = DBGet(DBQuery("
            SELECT DISTINCT s.STUDENT_ID, s.FIRST_NAME, s.LAST_NAME
            FROM students s
            INNER JOIN student_enrollment se ON se.STUDENT_ID=s.STUDENT_ID
            WHERE se.SCHOOL_ID='" . UserSchool() . "'
              AND se.SYEAR='" . UserSyear() . "'
              AND se.START_DATE<='" . billing_sql($period_end) . "'
              AND (se.END_DATE IS NULL OR se.END_DATE='0000-00-00' OR se.END_DATE>='" . billing_sql($period_start) . "')
            ORDER BY s.LAST_NAME, s.FIRST_NAME
        "));

        $items = array();
        foreach ($students as $student) {
            $items[] = $this->calculateStudent($student, $period_start, $period_end);
        }
        return $items;
    }

    function calculateStudent($student, $period_start, $period_end)
    {
        $student_id = $student['STUDENT_ID'];
        $name = trim($student['LAST_NAME'] . ', ' . $student['FIRST_NAME']);
        $account = $this->accountForStudent($student_id);
        $lines = array();

        $contracts = DBGet(DBQuery("
            SELECT c.*, s.code AS SERVICE_CODE, s.name AS SERVICE_NAME, tr.code AS TAX_CODE, tr.rate AS TAX_RATE, tr.exempt AS TAX_EXEMPT
            FROM billing_contracts c
            INNER JOIN billing_services s ON s.id=c.service_id
            LEFT JOIN billing_tax_rules tr ON tr.id=c.tax_rule_id
            WHERE c.student_id='" . billing_sql($student_id) . "'
              AND c.status='ACTIVE'
              AND c.start_date<='" . billing_sql($period_end) . "'
              AND (c.end_date IS NULL OR c.end_date>='" . billing_sql($period_start) . "')
        "));

        foreach ($contracts as $contract) {
            if ($contract['SERVICE_CODE'] == 'O2O_HOURLY') {
                foreach ($this->o2oLines($contract, $student_id, $period_start, $period_end) as $line) {
                    $lines[] = billing_apply_promotion($line, $contract, $period_start, $period_end);
                }
            } else {
                $lines[] = billing_apply_promotion($this->fixedLine($contract, $period_start), $contract, $period_start, $period_end);
            }
        }

        $subtotal = 0;
        $tax = 0;
        foreach ($lines as $line) {
            $subtotal += $line['base_amount'];
            $tax += $line['tax_amount'];
        }

        return array(
            'student_id' => $student_id,
            'student_name' => $name,
            'account_id' => $account ? $account['ACCOUNT_ID'] : '',
            'account_name' => $account ? $account['ACCOUNT_NAME'] : '',
            'status' => (!$account ? 'ERROR' : (count($lines) ? 'BILLABLE' : 'EMPTY')),
            'message' => !$account ? 'Sin responsable fiscal' : '',
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'total' => round($subtotal + $tax, 2),
        );
    }

    function fixedLine($contract, $period_start)
    {
        $base = billing_contract_price($contract, $period_start);
        $unit_price = $base;
        $quantity = 1;
        $unit = 'ud';
        if ($contract['MODALITY'] == 'GROUP_MONTHLY') {
            $quantity = round((float) $contract['GROUP_WEEKLY_HOURS'] * 4.33, 2);
            $unit = 'h/mes';
            $base = round($quantity * $base, 2);
        }
        $rate = (float) $contract['TAX_RATE'];
        $tax = $contract['TAX_EXEMPT'] == 'Y' ? 0 : round($base * $rate / 100, 2);
        return array(
            'type' => $contract['SERVICE_CODE'],
            'description' => $contract['SERVICE_NAME'],
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $unit_price,
            'discount_amount' => 0,
            'base_amount' => $base,
            'tax_rule_id' => $contract['TAX_RULE_ID'],
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            'total_amount' => round($base + $tax, 2),
            'origin_type' => 'CONTRACT',
            'origin_id' => $contract['ID'],
            'price_rule' => 'CONTRACT_PRICE',
        );
    }

    function o2oLines($contract, $student_id, $period_start, $period_end)
    {
        $sessions = DBGet(DBQuery("
            SELECT SUM(billable_minutes) AS MINUTES
            FROM billing_o2o_sessions
            WHERE student_id='" . billing_sql($student_id) . "'
              AND contract_id='" . billing_sql($contract['ID']) . "'
              AND session_date BETWEEN '" . billing_sql($period_start) . "' AND '" . billing_sql($period_end) . "'
              AND status IN ('PLANNED','COMPLETED','STUDENT_ABSENCE_BILLABLE')
        "));
        $minutes = (int) $sessions[1]['MINUTES'];
        if ($minutes <= 0) {
            return array();
        }

        $hours = round($minutes / 60, 2);
        $base = round($hours * billing_contract_price($contract, $period_start), 2);
        $rate = (float) $contract['TAX_RATE'];
        $tax = $contract['TAX_EXEMPT'] == 'Y' ? 0 : round($base * $rate / 100, 2);

        return array(array(
            'type' => 'O2O_HOURLY',
            'description' => 'Clases individuales ' . date('m/Y', strtotime($period_start)),
            'quantity' => $hours,
            'unit' => 'h',
            'unit_price' => billing_contract_price($contract, $period_start),
            'discount_amount' => 0,
            'base_amount' => $base,
            'tax_rule_id' => $contract['TAX_RULE_ID'],
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            'total_amount' => round($base + $tax, 2),
            'origin_type' => 'O2O_SESSION_SUMMARY',
            'origin_id' => $contract['ID'],
            'price_rule' => 'CONTRACT_PRICE',
        ));
    }

    function accountForStudent($student_id)
    {
        $ret = DBGet(DBQuery("
            SELECT a.id AS ACCOUNT_ID,
                   COALESCE(NULLIF(a.business_name,''), CONCAT(a.first_name,' ',a.last_name)) AS ACCOUNT_NAME
            FROM billing_accounts a
            INNER JOIN billing_account_students bas ON bas.account_id=a.id
            WHERE bas.student_id='" . billing_sql($student_id) . "'
              AND a.active='Y'
            ORDER BY bas.is_primary DESC, bas.id
            LIMIT 1
        "));
        return count($ret) ? $ret[1] : null;
    }
}

class BillingRunService
{
    function createDrafts($period_start, $period_end)
    {
        $code = 'RUN-' . date('Y-m', strtotime($period_start));
        $existing = DBGet(DBQuery("SELECT id FROM billing_runs WHERE school_id='" . UserSchool() . "' AND period_start='" . billing_sql($period_start) . "' LIMIT 1"));
        if ($existing) {
            $run_id = $existing[1]['ID'];
            DBQuery("DELETE FROM billing_run_items WHERE run_id='" . $run_id . "'");
        } else {
            billing_insert('billing_runs', array(
                'school_id' => UserSchool(),
                'syear' => UserSyear(),
                'run_code' => $code,
                'period_start' => $period_start,
                'period_end' => $period_end,
                'status' => 'DRAFT',
                'created_by' => billing_user_id(),
            ));
            $run_id = DBGet(DBQuery("SELECT id FROM billing_runs WHERE run_code='" . billing_sql($code) . "' AND school_id='" . UserSchool() . "' ORDER BY id DESC LIMIT 1"));
            $run_id = $run_id[1]['ID'];
        }

        billing_generate_o2o_for_period($period_start, $period_end);
        $calculator = new BillingCalculator();
        $items = $calculator->simulate($period_start, $period_end);
        foreach ($items as $item) {
            billing_insert('billing_run_items', array(
                'run_id' => $run_id,
                'student_id' => $item['student_id'],
                'account_id' => $item['account_id'] ? $item['account_id'] : null,
                'status' => $item['status'],
                'message' => $item['message'],
                'group_amount' => 0,
                'o2o_amount' => 0,
                'other_amount' => $item['subtotal'],
                'discount_amount' => 0,
                'tax_amount' => $item['tax'],
                'total_amount' => $item['total'],
                'calculation_payload' => json_encode($item),
            ));
        }

        DBQuery("UPDATE billing_runs SET status='CALCULATED' WHERE id='" . $run_id . "'");
        billing_audit('DRAFT_GENERATED', 'billing_runs', $run_id, '', json_encode(array('period_start' => $period_start, 'period_end' => $period_end)));
        return $run_id;
    }
}

function billing_issue_invoice($invoice_id)
{
    $invoice = DBGet(DBQuery("SELECT * FROM billing_invoices WHERE id='" . billing_sql($invoice_id) . "' LIMIT 1"));
    if (!$invoice || $invoice[1]['STATUS'] == 'ISSUED') {
        return false;
    }

    $series = $invoice[1]['SERIES'] ? $invoice[1]['SERIES'] : 'F';
    $year = date('Y');
    DBQuery("START TRANSACTION");
    $seq = DBGet(DBQuery("SELECT next_number FROM billing_invoice_sequences WHERE series='" . billing_sql($series) . "' AND fiscal_year='" . $year . "' FOR UPDATE"));
    if (!$seq) {
        billing_insert('billing_invoice_sequences', array('series' => $series, 'fiscal_year' => $year, 'next_number' => 1));
        $next = 1;
    } else {
        $next = (int) $seq[1]['NEXT_NUMBER'];
    }

    $number = $series . '-' . $year . '-' . str_pad($next, 6, '0', STR_PAD_LEFT);
    DBQuery("UPDATE billing_invoice_sequences SET next_number='" . ($next + 1) . "' WHERE series='" . billing_sql($series) . "' AND fiscal_year='" . $year . "'");
    DBQuery("UPDATE billing_invoices SET invoice_number='" . billing_sql($number) . "', issue_date='" . date('Y-m-d') . "', status='ISSUED', issued_by='" . billing_user_id() . "', issued_at=NOW() WHERE id='" . billing_sql($invoice_id) . "' AND status IN ('DRAFT','READY')");
    $adapter = new LocalFiscalRecordAdapter();
    $hash = $adapter->recordInvoice($invoice_id);
    DBQuery("UPDATE billing_invoices SET fiscal_hash='" . billing_sql($hash) . "' WHERE id='" . billing_sql($invoice_id) . "'");
    DBQuery("COMMIT");
    billing_audit('INVOICE_ISSUED', 'billing_invoices', $invoice_id, '', $number);
    return true;
}
function billing_options_from($table, $id, $label, $where)
{
    $options = array('' => '');
    if (!billing_table_exists($table)) {
        return $options;
    }
    $rows = DBGet(DBQuery('SELECT ' . $id . ' AS ID, ' . $label . ' AS LABEL FROM ' . $table . ($where ? ' WHERE ' . $where : '') . ' ORDER BY LABEL'));
    foreach ($rows as $row) {
        $options[$row['ID']] = $row['LABEL'];
    }
    return $options;
}

function billing_student_options()
{
    return billing_options_from('students', 'STUDENT_ID', "CONCAT(LAST_NAME, ', ', FIRST_NAME)", '1=1');
}

function billing_save_configuration()
{
    $keys = array('legal_name','trade_name','tax_id','address','postal_code','city','province','country','phone','email','logo','iban_info','registry_info','proration_enabled');
    foreach ($keys as $key) {
        $exists = DBGet(DBQuery("SELECT id FROM billing_configuration WHERE school_id='" . UserSchool() . "' AND config_key='" . billing_sql($key) . "'"));
        if ($exists) {
            billing_update('billing_configuration', array('config_value' => billing_post($key)), "id='" . $exists[1]['ID'] . "'");
        } else {
            billing_insert('billing_configuration', array('school_id' => UserSchool(), 'config_key' => $key, 'config_value' => billing_post($key)));
        }
    }
    billing_audit('CONFIG_UPDATED', 'billing_configuration', UserSchool(), '', json_encode($_POST));
}

function billing_configuration_values()
{
    $values = array();
    $rows = DBGet(DBQuery("SELECT config_key, config_value FROM billing_configuration WHERE school_id='" . UserSchool() . "'"));
    foreach ($rows as $row) {
        $values[$row['CONFIG_KEY']] = $row['CONFIG_VALUE'];
    }
    return $values;
}

function billing_render_configuration()
{
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        billing_save_configuration();
        billing_alert('Configuracion guardada.', 'ok');
    }
    $v = billing_configuration_values();
    billing_draw_header('Configuracion');
    echo '<form method="post" action="Modules.php?modname=billing/Configuration.php"><div class="panel-body"><div class="row">';
    billing_field('legal_name', 'Razon social', isset($v['legal_name']) ? $v['legal_name'] : '', 'text');
    billing_field('trade_name', 'Nombre comercial', isset($v['trade_name']) ? $v['trade_name'] : '', 'text');
    billing_field('tax_id', 'NIF', isset($v['tax_id']) ? $v['tax_id'] : '', 'text');
    billing_field('address', 'Domicilio', isset($v['address']) ? $v['address'] : '', 'text');
    billing_field('postal_code', 'CP', isset($v['postal_code']) ? $v['postal_code'] : '', 'text');
    billing_field('city', 'Municipio', isset($v['city']) ? $v['city'] : '', 'text');
    billing_field('province', 'Provincia', isset($v['province']) ? $v['province'] : '', 'text');
    billing_field('country', 'Pais', isset($v['country']) ? $v['country'] : 'ES', 'text');
    billing_field('phone', 'Telefono', isset($v['phone']) ? $v['phone'] : '', 'text');
    billing_field('email', 'Email', isset($v['email']) ? $v['email'] : '', 'email');
    billing_field('logo', 'Logo', isset($v['logo']) ? $v['logo'] : '', 'text');
    billing_field('iban_info', 'IBAN informativo', isset($v['iban_info']) ? $v['iban_info'] : '', 'text');
    billing_field('registry_info', 'Datos registrales', isset($v['registry_info']) ? $v['registry_info'] : '', 'text');
    billing_select('proration_enabled', 'Prorrateo', isset($v['proration_enabled']) ? $v['proration_enabled'] : 'N', array('N' => 'No', 'Y' => 'Si'));
    echo '</div></div><div class="panel-footer text-right"><button class="btn btn-primary">Guardar</button></div></form>';
    billing_close_panel();
}

function billing_render_accounts()
{
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        billing_insert('billing_accounts', array(
            'type' => billing_post('type', 'PERSON'),
            'first_name' => billing_post('first_name'),
            'last_name' => billing_post('last_name'),
            'business_name' => billing_post('business_name'),
            'tax_id' => billing_post('tax_id'),
            'address' => billing_post('address'),
            'postal_code' => billing_post('postal_code'),
            'city' => billing_post('city'),
            'province' => billing_post('province'),
            'country' => billing_post('country', 'ES'),
            'email' => billing_post('email'),
            'phone' => billing_post('phone'),
            'invoice_preference' => billing_post('invoice_preference', 'PER_STUDENT'),
            'active' => 'Y',
        ));
        $id = DBGet(DBQuery('SELECT id FROM billing_accounts ORDER BY id DESC LIMIT 1'));
        if (billing_post('student_id')) {
            billing_insert('billing_account_students', array('account_id' => $id[1]['ID'], 'student_id' => billing_post('student_id'), 'relationship' => billing_post('relationship'), 'is_primary' => 'Y'));
        }
        billing_audit('ACCOUNT_CREATED', 'billing_accounts', $id[1]['ID'], '', json_encode($_POST));
        billing_alert('Responsable creado.', 'ok');
    }

    billing_draw_header('Responsables fiscales');
    echo '<form method="post" action="Modules.php?modname=billing/Accounts.php"><div class="panel-body"><div class="row">';
    billing_select('type', 'Tipo', 'PERSON', array('PERSON' => 'Persona', 'COMPANY' => 'Empresa'));
    billing_field('first_name', 'Nombre', '', 'text');
    billing_field('last_name', 'Apellidos', '', 'text');
    billing_field('business_name', 'Razon social', '', 'text');
    billing_field('tax_id', 'NIF/NIE/CIF', '', 'text');
    billing_field('address', 'Direccion', '', 'text');
    billing_field('postal_code', 'CP', '', 'text');
    billing_field('city', 'Localidad', '', 'text');
    billing_field('province', 'Provincia', '', 'text');
    billing_field('country', 'Pais', 'ES', 'text');
    billing_field('email', 'Email', '', 'email');
    billing_field('phone', 'Telefono', '', 'text');
    billing_select('invoice_preference', 'Preferencia', 'PER_STUDENT', array('PER_STUDENT' => 'Una factura por alumno', 'FAMILY' => 'Familiar futura'));
    billing_select('student_id', 'Alumno asociado', '', billing_student_options());
    billing_field('relationship', 'Relacion', '', 'text');
    echo '</div></div><div class="panel-footer text-right"><button class="btn btn-primary">Crear</button></div></form>';
    $rows = DBGet(DBQuery("SELECT id, type, COALESCE(NULLIF(business_name,''), CONCAT(first_name,' ',last_name)) AS name, tax_id, email, active FROM billing_accounts ORDER BY id DESC LIMIT 50"));
    billing_table($rows, array('ID','TYPE','NAME','TAX_ID','EMAIL','ACTIVE'));
    billing_close_panel();
}

function billing_table($rows, $cols)
{
    echo '<div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr>';
    foreach ($cols as $col) {
        echo '<th>' . billing_h($col) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($cols as $col) {
            echo '<td>' . billing_h(isset($row[$col]) ? $row[$col] : '') . '</td>';
        }
        echo '</tr>';
    }
    if (!count($rows)) {
        echo '<tr><td colspan="' . count($cols) . '">Sin registros.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function billing_render_services_legacy()
{
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        billing_insert('billing_services', array('code' => billing_post('code'), 'name' => billing_post('name'), 'description' => billing_post('description'), 'default_price' => billing_post('default_price', '0'), 'unit' => billing_post('unit', 'UNIT'), 'active' => 'Y'));
        billing_alert('Servicio creado.', 'ok');
    }
    billing_draw_header('Servicios');
    echo '<form method="post" action="Modules.php?modname=billing/Services.php"><div class="panel-body"><div class="row">';
    billing_field('code', 'Codigo', '', 'text');
    billing_field('name', 'Nombre', '', 'text');
    billing_field('description', 'Descripcion', '', 'text');
    billing_field('default_price', 'Precio base', '0.00', 'number');
    billing_select('unit', 'Unidad', 'UNIT', array('MONTH' => 'Mes', 'HOUR' => 'Hora', 'UNIT' => 'Unidad'));
    echo '</div></div><div class="panel-footer text-right"><button class="btn btn-primary">Crear</button></div></form>';
    billing_table(DBGet(DBQuery('SELECT id, code, name, default_price, unit, active FROM billing_services ORDER BY sort_order, name')), array('ID','CODE','NAME','DEFAULT_PRICE','UNIT','ACTIVE'));
    billing_close_panel();
}

function billing_render_contracts_legacy()
{
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        billing_insert('billing_contracts', array('student_id' => billing_post('student_id'), 'service_id' => billing_post('service_id'), 'course_period_id' => billing_post('course_period_id') ? billing_post('course_period_id') : null, 'start_date' => billing_post('start_date'), 'end_date' => billing_post('end_date') ? billing_post('end_date') : null, 'price_amount' => billing_post('price_amount', '0'), 'modality' => billing_post('modality'), 'tax_rule_id' => billing_post('tax_rule_id') ? billing_post('tax_rule_id') : null, 'status' => 'ACTIVE', 'created_by' => billing_user_id()));
        $id = DBGet(DBQuery('SELECT id FROM billing_contracts ORDER BY id DESC LIMIT 1'));
        billing_insert('billing_contract_prices', array('contract_id' => $id[1]['ID'], 'valid_from' => billing_post('start_date'), 'old_price' => null, 'new_price' => billing_post('price_amount', '0'), 'changed_by' => billing_user_id()));
        billing_audit('CONTRACT_CREATED', 'billing_contracts', $id[1]['ID'], '', json_encode($_POST));
        billing_alert('Contrato creado.', 'ok');
    }
    billing_draw_header('Contratos');
    echo '<form method="post" action="Modules.php?modname=billing/Contracts.php" enctype="multipart/form-data"><div class="panel-body"><div class="row">';
    billing_select('student_id', 'Alumno', '', billing_student_options());
    billing_select('service_id', 'Servicio', '', billing_options_from('billing_services', 'id', 'name', "active='Y'"));
    billing_field('course_period_id', 'Grupo / course_period_id', '', 'number');
    billing_field('start_date', 'Inicio', date('Y-m-d'), 'date');
    billing_field('end_date', 'Fin', '', 'date');
    billing_field('price_amount', 'Precio', '0.00', 'number');
    billing_select('modality', 'Modalidad', 'GROUP_MONTHLY', array('GROUP_MONTHLY' => 'Mensualidad grupo', 'O2O_HOURLY' => 'O2O hora', 'ONE_TIME' => 'Pago unico', 'ANNUAL' => 'Anual', 'OTHER' => 'Otro'));
    billing_select('tax_rule_id', 'Regla fiscal', '', billing_options_from('billing_tax_rules', 'id', 'code', "active='Y'"));
    echo '</div></div><div class="panel-footer text-right"><button class="btn btn-primary">Crear</button></div></form>';
    $rows = DBGet(DBQuery("SELECT c.id, CONCAT(st.last_name, ', ', st.first_name) AS student, s.code, c.price_amount, c.modality, c.status FROM billing_contracts c INNER JOIN students st ON st.student_id=c.student_id INNER JOIN billing_services s ON s.id=c.service_id ORDER BY c.id DESC LIMIT 80"));
    billing_table($rows, array('ID','STUDENT','CODE','PRICE_AMOUNT','MODALITY','STATUS'));
    billing_close_panel();
}

function billing_render_promotions()
{
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        billing_insert('billing_promotions', array('code' => billing_post('code'), 'name' => billing_post('name'), 'type' => billing_post('type'), 'value' => billing_post('value', '0'), 'start_date' => billing_post('start_date') ? billing_post('start_date') : null, 'end_date' => billing_post('end_date') ? billing_post('end_date') : null, 'active' => 'Y'));
        billing_alert('Promocion creada.', 'ok');
    }
    billing_draw_header('Promociones');
    echo '<form method="post" action="Modules.php?modname=billing/Promotions.php"><div class="panel-body"><div class="row">';
    billing_field('code', 'Codigo', '', 'text');
    billing_field('name', 'Nombre', '', 'text');
    billing_select('type', 'Tipo', 'PERCENT', array('PERCENT' => 'Porcentaje', 'FIXED' => 'Importe fijo', 'FREE_MONTHS' => 'Meses gratis', 'TEMPORARY' => 'Temporal', 'PERMANENT' => 'Permanente', 'SIBLING' => 'Hermanos', 'CUSTOM' => 'Personalizado'));
    billing_field('value', 'Valor', '0.00', 'number');
    billing_field('start_date', 'Desde', '', 'date');
    billing_field('end_date', 'Hasta', '', 'date');
    echo '</div></div><div class="panel-footer text-right"><button class="btn btn-primary">Crear</button></div></form>';
    billing_table(DBGet(DBQuery('SELECT id, code, name, type, value, active FROM billing_promotions ORDER BY id DESC')), array('ID','CODE','NAME','TYPE','VALUE','ACTIVE'));
    billing_close_panel();
}

function billing_render_o2o_sessions_legacy()
{
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        billing_insert('billing_o2o_sessions', array('student_id' => billing_post('student_id'), 'teacher_id' => billing_post('teacher_id') ? billing_post('teacher_id') : null, 'session_date' => billing_post('session_date'), 'start_time' => billing_post('start_time'), 'end_time' => billing_post('end_time'), 'scheduled_minutes' => billing_post('scheduled_minutes', '0'), 'billable_minutes' => billing_post('billable_minutes', '0'), 'status' => billing_post('status')));
        billing_alert('Sesion registrada.', 'ok');
    }
    billing_draw_header('Sesiones O2O');
    echo '<form method="post" action="Modules.php?modname=billing/O2OSessions.php"><div class="panel-body"><div class="row">';
    billing_select('student_id', 'Alumno', '', billing_student_options());
    billing_field('teacher_id', 'Profesor ID', '', 'number');
    billing_field('session_date', 'Fecha', date('Y-m-d'), 'date');
    billing_field('start_time', 'Inicio', '', 'time');
    billing_field('end_time', 'Fin', '', 'time');
    billing_field('scheduled_minutes', 'Minutos programados', '60', 'number');
    billing_field('billable_minutes', 'Minutos facturables', '60', 'number');
    billing_select('status', 'Estado', 'COMPLETED', array('PLANNED' => 'Planificada', 'COMPLETED' => 'Completada', 'STUDENT_ABSENCE_BILLABLE' => 'Ausencia facturable', 'STUDENT_ABSENCE_NOT_BILLABLE' => 'Ausencia no facturable', 'TEACHER_CANCELLED' => 'Cancelada profesor', 'CENTER_CANCELLED' => 'Cancelada centro', 'RESCHEDULED' => 'Reprogramada'));
    echo '</div></div><div class="panel-footer text-right"><button class="btn btn-primary">Registrar</button></div></form>';
    $rows = DBGet(DBQuery("SELECT o.id, CONCAT(s.last_name, ', ', s.first_name) AS student, o.session_date, o.billable_minutes, o.status FROM billing_o2o_sessions o INNER JOIN students s ON s.student_id=o.student_id ORDER BY o.session_date DESC, o.id DESC LIMIT 80"));
    billing_table($rows, array('ID','STUDENT','SESSION_DATE','BILLABLE_MINUTES','STATUS'));
    billing_close_panel();
}

function billing_period_from_request()
{
    $month = billing_get('month', date('Y-m'));
    $start = date('Y-m-01', strtotime($month . '-01'));
    return array($start, date('Y-m-t', strtotime($start)));
}

function billing_render_run()
{
    list($start, $end) = billing_period_from_request();
    if (billing_get('action') == 'generate' && AllowEdit()) {
        $service = new BillingRunService();
        $run_id = $service->createDrafts($start, $end);
        billing_alert('Borradores calculados para run #' . $run_id . '.', 'ok');
    }
    $calculator = new BillingCalculator();
    $items = $calculator->simulate($start, $end);
    $total = 0; $billable = 0; $empty = 0; $errors = 0;
    foreach ($items as $item) {
        $total += $item['total'];
        if ($item['status'] == 'BILLABLE') $billable++;
        if ($item['status'] == 'EMPTY') $empty++;
        if ($item['status'] == 'ERROR') $errors++;
    }
    billing_draw_header('Facturacion mensual');
    echo '<div class="panel-body"><form class="form-inline" method="get" action="Modules.php"><input type="hidden" name="modname" value="billing/BillingRun.php"><input class="form-control" type="month" name="month" value="' . billing_h(date('Y-m', strtotime($start))) . '"> <button class="btn btn-default">Simular</button> <a class="btn btn-primary" href="Modules.php?modname=billing/BillingRun.php&month=' . billing_h(date('Y-m', strtotime($start))) . '&action=generate">Generar borradores</a></form><hr>';
    echo '<div class="row"><div class="col-md-3"><h4>' . count($items) . '</h4><span>Alumnos encontrados</span></div><div class="col-md-3"><h4>' . $billable . '</h4><span>Facturables</span></div><div class="col-md-3"><h4>' . $empty . '</h4><span>Sin importe</span></div><div class="col-md-3"><h4>' . $errors . '</h4><span>Errores</span></div></div><hr>';
    echo '<h4>Importe previsto: ' . billing_money($total) . '</h4>';
    billing_table($items, array('student_id','student_name','account_name','status','message','subtotal','tax','total'));
    echo '</div>';
    billing_close_panel();
}

function billing_create_invoices_from_run($run_id)
{
    $items = DBGet(DBQuery("SELECT * FROM billing_run_items WHERE run_id='" . billing_sql($run_id) . "' AND status='BILLABLE' AND invoice_id IS NULL"));
    foreach ($items as $item) {
        $payload = json_decode($item['CALCULATION_PAYLOAD'], true);
        if (!$payload || !$item['ACCOUNT_ID']) {
            continue;
        }
        billing_insert('billing_invoices', array('run_id' => $run_id, 'student_id' => $item['STUDENT_ID'], 'account_id' => $item['ACCOUNT_ID'], 'series' => 'F', 'operation_date' => date('Y-m-d'), 'school_year' => UserSyear(), 'student_snapshot' => $payload['student_name'], 'account_snapshot' => $payload['account_name'], 'issuer_snapshot' => json_encode(billing_configuration_values()), 'taxable_base' => $item['OTHER_AMOUNT'], 'discount_total' => $item['DISCOUNT_AMOUNT'], 'tax_total' => $item['TAX_AMOUNT'], 'total_amount' => $item['TOTAL_AMOUNT'], 'status' => 'READY', 'created_by' => billing_user_id()));
        $invoice = DBGet(DBQuery('SELECT id FROM billing_invoices ORDER BY id DESC LIMIT 1'));
        $invoice_id = $invoice[1]['ID'];
        foreach ($payload['lines'] as $line) {
            billing_insert('billing_invoice_lines', array('invoice_id' => $invoice_id, 'line_type' => $line['type'], 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit' => $line['unit'], 'unit_price' => $line['unit_price'], 'discount_amount' => $line['discount_amount'], 'base_amount' => $line['base_amount'], 'tax_rule_id' => $line['tax_rule_id'] ? $line['tax_rule_id'] : null, 'tax_rate' => $line['tax_rate'], 'tax_amount' => $line['tax_amount'], 'total_amount' => $line['total_amount'], 'origin_type' => $line['origin_type'], 'origin_id' => $line['origin_id'], 'price_rule' => $line['price_rule']));
        }
        billing_update('billing_run_items', array('invoice_id' => $invoice_id, 'status' => 'INVOICED'), "id='" . $item['ID'] . "'");
    }
    DBQuery("UPDATE billing_runs SET status='INVOICED' WHERE id='" . billing_sql($run_id) . "'");
}

function billing_render_drafts()
{
    if (billing_get('action') == 'create_invoices' && billing_get('run_id') && AllowEdit()) {
        billing_create_invoices_from_run(billing_get('run_id'));
        billing_alert('Facturas READY creadas.', 'ok');
    }
    billing_draw_header('Prefacturas');
    $runs = DBGet(DBQuery('SELECT id, run_code, period_start, period_end, status FROM billing_runs ORDER BY id DESC LIMIT 20'));
    echo '<div class="panel-body">';
    foreach ($runs as $run) {
        echo '<p><a class="btn btn-xs btn-primary" href="Modules.php?modname=billing/DraftInvoices.php&run_id=' . $run['ID'] . '&action=create_invoices">Crear facturas</a> ' . billing_h($run['RUN_CODE'] . ' ' . $run['STATUS']) . '</p>';
    }
    $rows = DBGet(DBQuery("SELECT ri.id, r.run_code, CONCAT(s.last_name, ', ', s.first_name) AS student, ri.status, ri.message, ri.total_amount FROM billing_run_items ri INNER JOIN billing_runs r ON r.id=ri.run_id INNER JOIN students s ON s.student_id=ri.student_id ORDER BY ri.id DESC LIMIT 100"));
    billing_table($rows, array('ID','RUN_CODE','STUDENT','STATUS','MESSAGE','TOTAL_AMOUNT'));
    echo '</div>';
    billing_close_panel();
}

function billing_render_invoices()
{
    if (billing_get('action') == 'issue' && billing_get('id') && AllowEdit()) {
        billing_issue_invoice(billing_get('id')) ? billing_alert('Factura emitida.', 'ok') : billing_alert('No se pudo emitir.', 'error');
    }
    billing_draw_header('Facturas');
    $rows = DBGet(DBQuery("SELECT i.id, i.series, i.invoice_number, i.issue_date, CONCAT(s.last_name, ', ', s.first_name) AS student, i.total_amount, i.status, i.payment_status FROM billing_invoices i INNER JOIN students s ON s.student_id=i.student_id ORDER BY i.id DESC LIMIT 100"));
    echo '<div class="panel-body">';
    echo '<div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr><th>ID</th><th>Numero</th><th>Fecha</th><th>Alumno</th><th>Total</th><th>Estado</th><th>Cobro</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . $row['ID'] . '</td><td>' . billing_h($row['INVOICE_NUMBER']) . '</td><td>' . billing_h($row['ISSUE_DATE']) . '</td><td>' . billing_h($row['STUDENT']) . '</td><td>' . billing_money($row['TOTAL_AMOUNT']) . '</td><td>' . billing_h($row['STATUS']) . '</td><td>' . billing_h($row['PAYMENT_STATUS']) . '</td><td><a href="Modules.php?modname=billing/InvoiceView.php&id=' . $row['ID'] . '">Ver</a> ' . ($row['STATUS'] == 'READY' ? '<a href="Modules.php?modname=billing/Invoices.php&id=' . $row['ID'] . '&action=issue">Emitir</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    billing_close_panel();
}

function billing_render_invoice_view()
{
    $id = billing_get('id');
    billing_draw_header('Factura');
    $invoice = DBGet(DBQuery("SELECT * FROM billing_invoices WHERE id='" . billing_sql($id) . "'"));
    if (!$invoice) {
        echo '<div class="panel-body">Factura no encontrada.</div>';
        billing_close_panel();
        return;
    }
    $lines = DBGet(DBQuery("SELECT description, quantity, unit, unit_price, base_amount, tax_rate, tax_amount, total_amount FROM billing_invoice_lines WHERE invoice_id='" . billing_sql($id) . "'"));
    echo '<div class="panel-body"><h3>' . billing_h($invoice[1]['INVOICE_NUMBER'] ? $invoice[1]['INVOICE_NUMBER'] : 'READY #' . $id) . '</h3><p>' . billing_h($invoice[1]['ACCOUNT_SNAPSHOT']) . '</p>';
    billing_table($lines, array('DESCRIPTION','QUANTITY','UNIT','UNIT_PRICE','BASE_AMOUNT','TAX_RATE','TAX_AMOUNT','TOTAL_AMOUNT'));
    echo '<h4 class="text-right">Total: ' . billing_money($invoice[1]['TOTAL_AMOUNT']) . '</h4><hr><div style="height:90px;border:1px dashed #bbb;text-align:center;padding-top:30px;">QR fiscal reservado</div></div>';
    billing_close_panel();
}

function billing_render_payments()
{
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        billing_insert('billing_payments', array('account_id' => billing_post('account_id'), 'student_id' => billing_post('student_id') ? billing_post('student_id') : null, 'amount' => billing_post('amount'), 'payment_date' => billing_post('payment_date'), 'method' => billing_post('method'), 'reference' => billing_post('reference'), 'status' => 'CONFIRMED', 'origin' => 'MANUAL', 'created_by' => billing_user_id()));
        billing_audit('PAYMENT_RECORDED', 'billing_payments', null, '', json_encode($_POST));
        billing_alert('Cobro registrado.', 'ok');
    }
    billing_draw_header('Cobros');
    echo '<form method="post" action="Modules.php?modname=billing/Payments.php"><div class="panel-body"><div class="row">';
    billing_select('account_id', 'Responsable', '', billing_options_from('billing_accounts', 'id', "COALESCE(NULLIF(business_name,''), CONCAT(first_name,' ',last_name))", "active='Y'"));
    billing_select('student_id', 'Alumno', '', billing_student_options());
    billing_field('amount', 'Importe', '0.00', 'number');
    billing_field('payment_date', 'Fecha', date('Y-m-d'), 'date');
    billing_select('method', 'Metodo', 'TRANSFER', array('CASH' => 'Efectivo', 'TRANSFER' => 'Transferencia', 'CARD' => 'Tarjeta', 'DIRECT_DEBIT' => 'Domiciliacion', 'OTHER' => 'Otro'));
    billing_field('reference', 'Referencia', '', 'text');
    echo '</div></div><div class="panel-footer text-right"><button class="btn btn-primary">Registrar</button></div></form>';
    billing_table(DBGet(DBQuery('SELECT id, account_id, student_id, amount, payment_date, method, status FROM billing_payments ORDER BY id DESC LIMIT 80')), array('ID','ACCOUNT_ID','STUDENT_ID','AMOUNT','PAYMENT_DATE','METHOD','STATUS'));
    billing_close_panel();
}

function billing_render_dashboard_legacy()
{
    billing_draw_header('Dashboard');
    $issued = DBGet(DBQuery("SELECT COUNT(*) AS NUM, COALESCE(SUM(total_amount),0) AS TOTAL FROM billing_invoices WHERE status='ISSUED'"));
    $paid = DBGet(DBQuery("SELECT COALESCE(SUM(amount),0) AS TOTAL FROM billing_payments WHERE status='CONFIRMED'"));
    $unpaid = DBGet(DBQuery("SELECT COUNT(*) AS NUM, COALESCE(SUM(total_amount),0) AS TOTAL FROM billing_invoices WHERE status='ISSUED' AND payment_status IN ('UNPAID','PARTIALLY_PAID')"));
    echo '<div class="panel-body"><div class="row">';
    echo '<div class="col-md-3"><h3>' . billing_money($issued[1]['TOTAL']) . '</h3><span>Facturado</span></div>';
    echo '<div class="col-md-3"><h3>' . billing_money($paid[1]['TOTAL']) . '</h3><span>Cobrado</span></div>';
    echo '<div class="col-md-3"><h3>' . billing_money($unpaid[1]['TOTAL']) . '</h3><span>Pendiente</span></div>';
    echo '<div class="col-md-3"><h3>' . billing_h($issued[1]['NUM']) . '</h3><span>Facturas</span></div>';
    echo '</div></div>';
    billing_close_panel();
}

function billing_render_accountant()
{
    if (billing_get('export') == 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="facturas_emitidas.csv"');
        echo "numero;serie;fecha_expedicion;fecha_operacion;nif_destinatario;nombre_destinatario;base;iva;total;estado\n";
        $rows = DBGet(DBQuery("SELECT invoice_number, series, issue_date, operation_date, account_snapshot, taxable_base, tax_total, total_amount, status FROM billing_invoices WHERE status IN ('ISSUED','RECTIFIED') ORDER BY issue_date, invoice_number"));
        foreach ($rows as $row) {
            echo implode(';', array($row['INVOICE_NUMBER'], $row['SERIES'], $row['ISSUE_DATE'], $row['OPERATION_DATE'], '', str_replace(';', ',', $row['ACCOUNT_SNAPSHOT']), $row['TAXABLE_BASE'], $row['TAX_TOTAL'], $row['TOTAL_AMOUNT'], $row['STATUS'])) . "\n";
        }
        exit;
    }
    billing_draw_header('Gestoria');
    echo '<div class="panel-body"><a class="btn btn-primary" href="Modules.php?modname=billing/Accountant.php&export=csv">Exportar CSV</a></div>';
    billing_close_panel();
}

function billing_render_reports()
{
    billing_draw_header('Informes');
    $rows = DBGet(DBQuery("SELECT status, payment_status, COUNT(*) AS invoices, COALESCE(SUM(taxable_base),0) AS base, COALESCE(SUM(tax_total),0) AS tax, COALESCE(SUM(total_amount),0) AS total FROM billing_invoices GROUP BY status, payment_status ORDER BY status, payment_status"));
    echo '<div class="panel-body">';
    billing_table($rows, array('STATUS','PAYMENT_STATUS','INVOICES','BASE','TAX','TOTAL'));
    echo '</div>';
    billing_close_panel();
}

function billing_render_rectifications()
{
    billing_draw_header('Rectificativas');
    echo '<div class="panel-body">Flujo reservado. Las facturas emitidas no se editan; la correccion se hara con serie R y enlace a la factura original.</div>';
    billing_close_panel();
}


function billing_contract_price($contract, $period_start)
{
    $prices = DBGet(DBQuery("SELECT new_price FROM billing_contract_prices WHERE contract_id='" . billing_sql($contract['ID']) . "' AND valid_from<='" . billing_sql($period_start) . "' ORDER BY valid_from DESC, id DESC LIMIT 1"));
    return round((float) ($prices ? $prices[1]['NEW_PRICE'] : $contract['PRICE_AMOUNT']), 2);
}

function billing_update_service_price($service_id, $price, $valid_from)
{
    $contracts = DBGet(DBQuery("SELECT id, price_amount FROM billing_contracts WHERE service_id='" . billing_sql($service_id) . "' AND status='ACTIVE' AND (price_source='SERVICE' OR price_source IS NULL)"));
    foreach ($contracts as $contract) {
        billing_update('billing_contracts', array('price_amount' => $price), "id='" . $contract['ID'] . "'");
        billing_insert('billing_contract_prices', array('contract_id' => $contract['ID'], 'valid_from' => $valid_from, 'old_price' => $contract['PRICE_AMOUNT'], 'new_price' => $price, 'changed_by' => billing_user_id()));
    }
    return count($contracts);
}

function billing_render_services()
{
    $service_id = billing_get('id');
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        $service_id = billing_post('id');
        $data = array('code' => billing_post('code'), 'name' => billing_post('name'), 'description' => billing_post('description'), 'default_price' => billing_post('default_price', '0'), 'unit' => billing_post('unit', 'UNIT'), 'active' => billing_post('active', 'Y'));
        if ($service_id) {
            billing_update('billing_services', $data, "id='" . billing_sql($service_id) . "'");
            $affected = 0;
            if (billing_post('apply_price') == 'Y') {
                $affected = billing_update_service_price($service_id, $data['default_price'], billing_post('valid_from', date('Y-m-d')));
            }
            billing_audit('SERVICE_UPDATED', 'billing_services', $service_id, '', json_encode($_POST));
            billing_alert('Servicio actualizado. Contratos ajustados: ' . $affected . '.', 'ok');
        } else {
            billing_insert('billing_services', $data);
            billing_alert('Servicio creado.', 'ok');
        }
    }
    $selected = $service_id ? DBGet(DBQuery("SELECT * FROM billing_services WHERE id='" . billing_sql($service_id) . "'")) : array();
    $selected = $selected ? $selected[1] : array('ID' => '', 'CODE' => '', 'NAME' => '', 'DESCRIPTION' => '', 'DEFAULT_PRICE' => '0.00', 'UNIT' => 'UNIT', 'ACTIVE' => 'Y');
    billing_draw_header('Servicios');
    echo '<form method="post" action="Modules.php?modname=billing/Services.php"><input type="hidden" name="id" value="' . billing_h($selected['ID']) . '"><div class="panel-body"><div class="row">';
    billing_field('code', 'Codigo', $selected['CODE'], 'text');
    billing_field('name', 'Nombre', $selected['NAME'], 'text');
    billing_field('description', 'Descripcion', $selected['DESCRIPTION'], 'text');
    billing_field('default_price', 'Precio base', $selected['DEFAULT_PRICE'], 'number');
    billing_select('unit', 'Unidad', $selected['UNIT'], array('MONTH' => 'Mes', 'HOUR' => 'Hora', 'UNIT' => 'Unidad'));
    billing_select('active', 'Activo', $selected['ACTIVE'], array('Y' => 'Si', 'N' => 'No'));
    if ($selected['ID']) {
        billing_field('valid_from', 'Aplicar desde', date('Y-m-d'), 'date');
        echo '<div class="form-group col-md-6"><label><input type="checkbox" name="apply_price" value="Y"> Actualizar precio en contratos automaticos activos</label></div>';
    }
    echo '</div></div><div class="panel-footer text-right"><button class="btn btn-primary">' . ($selected['ID'] ? 'Guardar cambios' : 'Crear') . '</button></div></form>';
    $rows = DBGet(DBQuery('SELECT id, code, name, default_price, unit, active FROM billing_services ORDER BY sort_order, name'));
    echo '<div class="panel-body"><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Codigo</th><th>Servicio</th><th>Precio</th><th>Unidad</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) echo '<tr><td>' . billing_h($row['CODE']) . '</td><td>' . billing_h($row['NAME']) . '</td><td>' . billing_money($row['DEFAULT_PRICE']) . '</td><td>' . billing_h($row['UNIT']) . '</td><td><a href="Modules.php?modname=billing/Services.php&id=' . $row['ID'] . '">Editar</a></td></tr>';
    echo '</tbody></table></div></div>';
    billing_close_panel();
}

function billing_generate_o2o_for_period($period_start, $period_end)
{
    if (!billing_table_exists('billing_o2o_months')) return;
    $contracts = DBGet(DBQuery("SELECT c.* FROM billing_contracts c INNER JOIN billing_services s ON s.id=c.service_id WHERE s.code='O2O_HOURLY' AND c.status='ACTIVE' AND c.o2o_first_class_date IS NOT NULL AND c.o2o_duration_minutes>0 AND c.start_date<='" . billing_sql($period_end) . "' AND (c.end_date IS NULL OR c.end_date>='" . billing_sql($period_start) . "')"));
    foreach ($contracts as $contract) billing_generate_o2o_month($contract, $period_start, $period_end);
}

function billing_generate_o2o_month($contract, $period_start, $period_end)
{
    $month = DBGet(DBQuery("SELECT id FROM billing_o2o_months WHERE contract_id='" . $contract['ID'] . "' AND period_start='" . billing_sql($period_start) . "'"));
    if ($month) return $month[1]['ID'];
    billing_insert('billing_o2o_months', array('contract_id' => $contract['ID'], 'period_start' => $period_start, 'period_end' => $period_end, 'generated_by' => billing_user_id()));
    $month = DBGet(DBQuery("SELECT id FROM billing_o2o_months WHERE contract_id='" . $contract['ID'] . "' AND period_start='" . billing_sql($period_start) . "'"));
    $month_id = $month[1]['ID'];
    $weekdays = array_filter(explode(',', $contract['O2O_WEEKDAYS']));
    $date = new DateTime($period_start);
    $end = new DateTime($period_end);
    $first = new DateTime($contract['O2O_FIRST_CLASS_DATE']);
    while ($date <= $end) {
        $day = $date->format('N');
        if ($date >= $first && in_array($day, $weekdays) && (!$contract['END_DATE'] || $date->format('Y-m-d') <= $contract['END_DATE'])) {
            billing_insert('billing_o2o_sessions', array('student_id' => $contract['STUDENT_ID'], 'contract_id' => $contract['ID'], 'o2o_month_id' => $month_id, 'session_date' => $date->format('Y-m-d'), 'scheduled_minutes' => $contract['O2O_DURATION_MINUTES'], 'billable_minutes' => $contract['O2O_DURATION_MINUTES'], 'status' => 'PLANNED', 'source_type' => 'O2O_MONTH', 'source_id' => $month_id));
        }
        $date->modify('+1 day');
    }
    return $month_id;
}

function billing_render_o2o_sessions()
{
    list($start, $end) = billing_period_from_request();
    if (billing_get('action') == 'generate' && billing_get('contract_id') && AllowEdit()) {
        $contract = DBGet(DBQuery("SELECT * FROM billing_contracts WHERE id='" . billing_sql(billing_get('contract_id')) . "'"));
        if ($contract) { billing_generate_o2o_month($contract[1], $start, $end); billing_alert('Mes O2O creado. Ya puedes anular o ajustar sesiones.', 'ok'); }
    }
    if ($_POST && billing_post('action') == 'cancel' && AllowEdit()) {
        billing_update('billing_o2o_sessions', array('status' => 'CENTER_CANCELLED', 'billable_minutes' => 0), "id='" . billing_sql(billing_post('session_id')) . "'");
        billing_alert('Sesion anulada.', 'ok');
    }
    billing_draw_header('Planificacion O2O');
    echo '<div class="panel-body"><form class="form-inline" method="get" action="Modules.php"><input type="hidden" name="modname" value="billing/O2OSessions.php"><input class="form-control" type="month" name="month" value="' . billing_h(date('Y-m', strtotime($start))) . '"> <button class="btn btn-default">Ver mes</button></form><hr>';
    $contracts = DBGet(DBQuery("SELECT c.id, CONCAT(s.last_name, ', ', s.first_name) AS student, c.o2o_first_class_date, c.o2o_duration_minutes, c.o2o_classes_per_week, c.o2o_weekdays FROM billing_contracts c INNER JOIN students s ON s.student_id=c.student_id INNER JOIN billing_services bs ON bs.id=c.service_id WHERE bs.code='O2O_HOURLY' AND c.status='ACTIVE' ORDER BY s.last_name, s.first_name"));
    echo '<table class="table table-striped"><thead><tr><th>Alumno</th><th>Inicio</th><th>Duracion</th><th>Clases/semana</th><th>Dias</th><th></th></tr></thead><tbody>';
    foreach ($contracts as $contract) echo '<tr><td>' . billing_h($contract['STUDENT']) . '</td><td>' . billing_h($contract['O2O_FIRST_CLASS_DATE']) . '</td><td>' . billing_h($contract['O2O_DURATION_MINUTES']) . ' min</td><td>' . billing_h($contract['O2O_CLASSES_PER_WEEK']) . '</td><td>' . billing_h($contract['O2O_WEEKDAYS']) . '</td><td><a class="btn btn-xs btn-primary" href="Modules.php?modname=billing/O2OSessions.php&month=' . date('Y-m', strtotime($start)) . '&contract_id=' . $contract['ID'] . '&action=generate">Crear mes</a></td></tr>';
    echo '</tbody></table>';
    $rows = DBGet(DBQuery("SELECT o.id, CONCAT(s.last_name, ', ', s.first_name) AS student, o.session_date, o.scheduled_minutes, o.billable_minutes, o.status FROM billing_o2o_sessions o INNER JOIN students s ON s.student_id=o.student_id WHERE o.session_date BETWEEN '" . billing_sql($start) . "' AND '" . billing_sql($end) . "' ORDER BY o.session_date, s.last_name"));
    echo '<h4>Sesiones creadas</h4><table class="table table-condensed"><thead><tr><th>Alumno</th><th>Fecha</th><th>Programada</th><th>Facturable</th><th>Estado</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) { echo '<tr><td>' . billing_h($row['STUDENT']) . '</td><td>' . billing_h($row['SESSION_DATE']) . '</td><td>' . $row['SCHEDULED_MINUTES'] . ' min</td><td>' . $row['BILLABLE_MINUTES'] . ' min</td><td>' . billing_h($row['STATUS']) . '</td><td>'; if ($row['STATUS'] == 'PLANNED') echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="cancel"><input type="hidden" name="session_id" value="' . $row['ID'] . '"><button class="btn btn-xs btn-danger">Anular</button></form>'; echo '</td></tr>'; }
    echo '</tbody></table></div>';
    billing_close_panel();
}

function billing_render_dashboard()
{
    $start = billing_get('start_date', date('Y-m-01'));
    $end = billing_get('end_date', date('Y-m-t'));
    billing_draw_header('Dashboard');
    $issued = DBGet(DBQuery("SELECT COUNT(*) AS NUM, COALESCE(SUM(total_amount),0) AS TOTAL FROM billing_invoices WHERE status='ISSUED' AND issue_date BETWEEN '" . billing_sql($start) . "' AND '" . billing_sql($end) . "'"));
    $paid = DBGet(DBQuery("SELECT COALESCE(SUM(amount),0) AS TOTAL FROM billing_payments WHERE status='CONFIRMED' AND payment_date BETWEEN '" . billing_sql($start) . "' AND '" . billing_sql($end) . "'"));
    $pending = max(0, (float) $issued[1]['TOTAL'] - (float) $paid[1]['TOTAL']);
    $series = DBGet(DBQuery("SELECT DATE_FORMAT(issue_date, '%Y-%m') AS LABEL, COALESCE(SUM(total_amount),0) AS TOTAL FROM billing_invoices WHERE status='ISSUED' AND issue_date BETWEEN '" . billing_sql($start) . "' AND '" . billing_sql($end) . "' GROUP BY DATE_FORMAT(issue_date, '%Y-%m') ORDER BY LABEL"));
    $max = 1; foreach ($series as $row) $max = max($max, (float) $row['TOTAL']);
    echo '<div class="panel-body"><form class="form-inline" method="get" action="Modules.php"><input type="hidden" name="modname" value="billing/Dashboard.php"><input class="form-control" type="date" name="start_date" value="' . billing_h($start) . '"> <input class="form-control" type="date" name="end_date" value="' . billing_h($end) . '"> <button class="btn btn-default">Aplicar</button></form><hr><div class="row"><div class="col-md-3"><h3>' . billing_money($issued[1]['TOTAL']) . '</h3><span>Facturado</span></div><div class="col-md-3"><h3>' . billing_money($paid[1]['TOTAL']) . '</h3><span>Cobrado</span></div><div class="col-md-3"><h3>' . billing_money($pending) . '</h3><span>Pendiente</span></div><div class="col-md-3"><h3>' . billing_h($issued[1]['NUM']) . '</h3><span>Facturas</span></div></div><hr><h4>Facturacion por mes</h4>';
    foreach ($series as $row) echo '<div style="margin:8px 0"><span style="display:inline-block;width:90px">' . billing_h($row['LABEL']) . '</span><span style="display:inline-block;vertical-align:middle;height:18px;background:#2d7db3;width:' . round(((float) $row['TOTAL'] / $max) * 70) . '%"></span> ' . billing_money($row['TOTAL']) . '</div>';
    if (!count($series)) echo '<p>Sin facturas emitidas en el periodo.</p>';
    echo '</div>';
    billing_close_panel();
}


function billing_render_contracts()
{
    if (billing_get('action') == 'pdf' && billing_get('id')) {
        billing_render_contract_pdf(billing_get('id'));
        return;
    }
    if ($_POST && AllowEdit()) {
        if (!empty($_FILES['template']['tmp_name']) && $_FILES['template']['error'] == UPLOAD_ERR_OK) {
            $name = basename($_FILES['template']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, array('html', 'htm'))) {
                billing_insert('billing_contract_templates', array('name' => pathinfo($name, PATHINFO_FILENAME), 'file_name' => $name, 'content' => file_get_contents($_FILES['template']['tmp_name']), 'created_by' => billing_user_id()));
            }
        }
        $service_id = billing_post('service_id');
        $service = DBGet(DBQuery("SELECT code, default_price FROM billing_services WHERE id='" . billing_sql($service_id) . "'"));
        $service = $service ? $service[1] : array('CODE' => '', 'DEFAULT_PRICE' => '0');
        $source = billing_post('price_source', 'SERVICE');
        $price = $source == 'SERVICE' ? $service['DEFAULT_PRICE'] : billing_post('price_amount', '0');
        $data = array(
            'student_id' => billing_post('student_id'),
            'academic_syear' => billing_post('academic_syear', UserSyear()),
            'service_id' => $service_id,
            'course_period_id' => billing_post('course_period_id') ?: null,
            'start_date' => billing_post('start_date'),
            'end_date' => billing_post('end_date') ?: null,
            'price_amount' => $price,
            'price_source' => $source,
            'group_weekly_hours' => billing_post('group_weekly_hours') ?: null,
            'promotion_id' => billing_post('promotion_id') ?: null,
            'template_id' => billing_post('template_id') ?: null,
            'modality' => billing_post('modality'),
            'o2o_first_class_date' => $service['CODE'] == 'O2O_HOURLY' ? billing_post('o2o_first_class_date') : null,
            'o2o_duration_minutes' => $service['CODE'] == 'O2O_HOURLY' ? billing_post('o2o_duration_minutes') : null,
            'o2o_classes_per_week' => $service['CODE'] == 'O2O_HOURLY' ? count((array) billing_post('o2o_weekdays', array())) : null,
            'o2o_weekdays' => $service['CODE'] == 'O2O_HOURLY' ? implode(',', (array) billing_post('o2o_weekdays', array())) : null,
            'tax_rule_id' => billing_post('tax_rule_id') ?: null,
            'status' => 'ACTIVE',
            'created_by' => billing_user_id(),
        );
        if ($service['CODE'] == 'O2O_HOURLY' && (!$data['o2o_first_class_date'] || !$data['o2o_duration_minutes'] || !$data['o2o_weekdays'])) {
            billing_alert('Para O2O indica inicio, duracion y al menos un dia semanal.', 'error');
        } else {
            billing_insert('billing_contracts', $data);
            $id = DBGet(DBQuery('SELECT id FROM billing_contracts ORDER BY id DESC LIMIT 1'));
            billing_insert('billing_contract_prices', array('contract_id' => $id[1]['ID'], 'valid_from' => $data['start_date'], 'old_price' => null, 'new_price' => $price, 'changed_by' => billing_user_id()));
            billing_audit('CONTRACT_CREATED', 'billing_contracts', $id[1]['ID'], '', json_encode($_POST));
            billing_alert('Contrato creado.', 'ok');
        }
    }
    billing_draw_header('Contratos');
    echo '<form method="post" enctype="multipart/form-data" action="Modules.php?modname=billing/Contracts.php"><div class="panel-body"><div class="row">';
    billing_select('student_id', 'Alumno', '', billing_student_options());
    billing_select('academic_syear', 'Curso academico', UserSyear(), billing_options_from('school_years', 'syear', 'title', "school_id='" . UserSchool() . "'"));
    billing_select('service_id', 'Servicio', '', billing_options_from('billing_services', 'id', 'name', "active='Y'"));
    billing_field('course_period_id', 'Grupo / course_period_id', '', 'number');
    billing_field('start_date', 'Inicio contrato', date('Y-m-d'), 'date');
    billing_field('end_date', 'Fin contrato', '', 'date');
    billing_select('price_source', 'Precio', 'SERVICE', array('SERVICE' => 'Automatico del servicio', 'CUSTOM' => 'Personalizado'));
    billing_field('price_amount', 'Precio personalizado', '0.00', 'number');
    billing_field('group_weekly_hours', 'Horas semanales del grupo', '', 'number');
    billing_select('promotion_id', 'Promocion', '', billing_options_from('billing_promotions', 'id', "CONCAT(code, ' - ', name)", "active='Y'"));
    billing_select('template_id', 'Plantilla PDF', '', billing_options_from('billing_contract_templates', 'id', 'name', "active='Y'"));
    echo '<div class="form-group col-md-6"><label>Subir plantilla HTML</label><input class="form-control" type="file" name="template" accept=".html,.htm"></div>';
    billing_select('modality', 'Modalidad', 'GROUP_MONTHLY', array('GROUP_MONTHLY' => 'Mensualidad grupo', 'O2O_HOURLY' => 'O2O hora', 'ONE_TIME' => 'Pago unico', 'ANNUAL' => 'Anual', 'OTHER' => 'Otro'));
    billing_select('tax_rule_id', 'Regla fiscal', '', billing_options_from('billing_tax_rules', 'id', 'code', "active='Y'"));
    echo '<div id="o2o-config" class="col-md-12"><h4>Planificacion O2O</h4>';
    billing_field('o2o_first_class_date', 'Primera clase', date('Y-m-d'), 'date');
    billing_field('o2o_duration_minutes', 'Duracion por clase (minutos)', '60', 'number');
    echo '<div class="form-group col-md-12"><label>Dias semanales de clase</label><br>';
    foreach (array(1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D') as $day => $label) echo '<label class="checkbox-inline"><input type="checkbox" name="o2o_weekdays[]" value="' . $day . '"> ' . $label . '</label> ';
    echo '</div></div></div><script>document.querySelector("select[name=modality]").addEventListener("change", function () { document.getElementById("o2o-config").style.display = this.value == "O2O_HOURLY" ? "block" : "none"; }); document.querySelector("select[name=modality]").dispatchEvent(new Event("change"));</script></div><div class="panel-footer text-right"><button class="btn btn-primary">Crear contrato</button></div></form>';
    $rows = DBGet(DBQuery("SELECT c.id, CONCAT(st.last_name, ', ', st.first_name) AS student, s.code, c.price_amount, c.price_source, c.academic_syear, c.group_weekly_hours, c.o2o_first_class_date, c.o2o_weekdays, c.status FROM billing_contracts c INNER JOIN students st ON st.student_id=c.student_id INNER JOIN billing_services s ON s.id=c.service_id ORDER BY c.id DESC LIMIT 80"));
    echo '<div class="panel-body"><table class="table table-striped"><thead><tr><th>ID</th><th>Alumno</th><th>Curso</th><th>Servicio</th><th>Precio</th><th>Horas/semana</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) echo '<tr><td>' . $row['ID'] . '</td><td>' . billing_h($row['STUDENT']) . '</td><td>' . billing_h($row['ACADEMIC_SYEAR']) . '</td><td>' . billing_h($row['CODE']) . '</td><td>' . billing_money($row['PRICE_AMOUNT']) . '</td><td>' . billing_h($row['GROUP_WEEKLY_HOURS']) . '</td><td><a href="Modules.php?modname=billing/Contracts.php&action=pdf&id=' . $row['ID'] . '&_openSIS_PDF=true">PDF</a></td></tr>';
    echo '</tbody></table></div>';
    billing_close_panel();
}

function billing_render_contract_pdf($contract_id)
{
    $contract = DBGet(DBQuery("SELECT c.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name, bs.name AS service_name, sy.title AS academic_title FROM billing_contracts c INNER JOIN students s ON s.student_id=c.student_id INNER JOIN billing_services bs ON bs.id=c.service_id LEFT JOIN school_years sy ON sy.syear=c.academic_syear AND sy.school_id='" . UserSchool() . "' WHERE c.id='" . billing_sql($contract_id) . "'"));
    if (!$contract) { echo 'Contrato no encontrado.'; return; }
    $contract = $contract[1];
    $template = DBGet(DBQuery("SELECT content FROM billing_contract_templates WHERE id='" . billing_sql($contract['TEMPLATE_ID']) . "' AND active='Y'"));
    $html = $template ? $template[1]['CONTENT'] : '<h1>Contrato academico</h1><p>Alumno: {{student_name}}</p><p>Curso: {{academic_year}}</p><p>Servicio: {{service_name}}</p><p>Precio: {{price}}</p><p>Firmas:</p><br><br>____________________  ____________________';
    $values = array('{{student_name}}' => billing_h($contract['STUDENT_NAME']), '{{academic_year}}' => billing_h($contract['ACADEMIC_TITLE'] ?: $contract['ACADEMIC_SYEAR']), '{{service_name}}' => billing_h($contract['SERVICE_NAME']), '{{price}}' => billing_money($contract['PRICE_AMOUNT']), '{{start_date}}' => billing_h($contract['START_DATE']), '{{end_date}}' => billing_h($contract['END_DATE']));
    echo str_replace(array_keys($values), array_values($values), $html);
}

function billing_apply_promotion($line, $contract, $period_start, $period_end)
{
    if (!$contract['PROMOTION_ID']) return $line;
    $promotion = DBGet(DBQuery("SELECT * FROM billing_promotions WHERE id='" . billing_sql($contract['PROMOTION_ID']) . "' AND active='Y' AND (start_date IS NULL OR start_date<='" . billing_sql($period_end) . "') AND (end_date IS NULL OR end_date>='" . billing_sql($period_start) . "')"));
    if (!$promotion) return $line;
    $promotion = $promotion[1];
    $discount = $promotion['TYPE'] == 'PERCENT' ? round($line['base_amount'] * (float) $promotion['VALUE'] / 100, 2) : (($promotion['TYPE'] == 'FIXED') ? min($line['base_amount'], (float) $promotion['VALUE']) : 0);
    $line['discount_amount'] = $discount;
    $line['base_amount'] = round($line['base_amount'] - $discount, 2);
    $line['tax_amount'] = ($line['tax_rate'] && $promotion['TYPE'] != 'FREE_MONTHS') ? round($line['base_amount'] * $line['tax_rate'] / 100, 2) : $line['tax_amount'];
    $line['total_amount'] = round($line['base_amount'] + $line['tax_amount'], 2);
    $line['price_rule'] = 'PROMOTION_' . $promotion['CODE'];
    return $line;
}
?>
