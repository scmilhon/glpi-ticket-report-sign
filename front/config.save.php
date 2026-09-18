<?php
/**
 * Legacy redirect. 1.6.0 moved the save handler into config.form.php
 * so the form posts to itself (GLPI 11's global CSRF listener
 * rejects some cross-URL POSTs). Anything that still posts here
 * gets redirected to the form, preserving any in-flight session
 * messages.
 */
include('../../../inc/includes.php');
Html::redirect(plugin_glpiticketreportsign_web_dir() . '/front/config.form.php');
