<?php
include('../../RedirectModulesInc.php');

if (!defined('billing')) {
    define('billing', 'billing');
}
if (!defined('_billing')) {
    define('_billing', 'Facturacion');
}

$menu['billing']['admin'] = array(
    'billing/Dashboard.php' => 'Dashboard',
    1 => 'Administracion',
    'billing/Configuration.php' => 'Configuracion',
    'billing/Accounts.php' => 'Responsables fiscales',
    'billing/Services.php' => 'Servicios',
    'billing/Contracts.php' => 'Contratos',
    'billing/Promotions.php' => 'Promociones',
    'billing/O2OSessions.php' => 'Sesiones O2O',
    2 => 'Procesos',
    'billing/BillingRun.php' => 'Facturacion mensual',
    'billing/DraftInvoices.php' => 'Prefacturas',
    'billing/Invoices.php' => 'Facturas',
    'billing/Rectifications.php' => 'Rectificativas',
    'billing/Payments.php' => 'Cobros',
    3 => 'Gestoria',
    'billing/Accountant.php' => 'Paquete gestoria',
    'billing/Reports.php' => 'Informes',
);

$menu['billing']['teacher'] = array();
$menu['billing']['parent'] = array(
    'billing/Invoices.php' => 'Facturas',
);

$exceptions['billing'] = array(
    'billing/Configuration.php' => true,
    'billing/BillingRun.php' => true,
    'billing/Invoices.php' => true,
    'billing/Rectifications.php' => true,
);
?>
