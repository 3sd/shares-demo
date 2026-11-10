<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'shares_demo.civix.php';
// phpcs:enable

use CRM_SharesDemo_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function shares_demo_civicrm_config(\CRM_Core_Config $config): void {
  _shares_demo_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function shares_demo_civicrm_install(): void {
  _shares_demo_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function shares_demo_civicrm_enable(): void {
  _shares_demo_civix_civicrm_enable();
}
