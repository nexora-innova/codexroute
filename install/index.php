<?php

define('GLPI_ROOT', '../../..');
include(GLPI_ROOT . '/inc/includes.php');

Session::checkRight("config", READ);

Html::header(__('Access denied'), $_SERVER['PHP_SELF'], "config", "plugins");
echo "<div class='center'><br><br>";
echo "<img src='" . $CFG_GLPI["root_doc"] . "/pics/warning.png' alt='warning'><br><br>";
echo "<b>" . __('Access denied') . "</b><br>";
echo "</div>";
Html::footer();

