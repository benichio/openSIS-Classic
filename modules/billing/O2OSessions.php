<?php
include('../../RedirectModulesInc.php');
include('modules/billing/includes/BillingBootstrap.php');

if (billing_require_schema()) {
    billing_render_o2o_sessions();
}
?>
