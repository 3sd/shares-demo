<?php
namespace Civi\Api4;

/**
 * Sample data for CiviShares demo, test and development sites.
 *
 * @package Civi\Api4
 */
class SharesDemo extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   * @return Action\SharesDemo\Generate
   */
  public static function generate($checkPermissions = TRUE) {
    return (new Action\SharesDemo\Generate(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Action\SharesDemo\Reset
   */
  public static function reset($checkPermissions = TRUE) {
    return (new Action\SharesDemo\Reset(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(static::getEntityName(), __FUNCTION__, [self::class, 'getFieldSpecs']))
      ->setCheckPermissions($checkPermissions);
  }

  public static function getFieldSpecs(): array {
    return [];
  }

  public static function permissions() {
    return [
      'default' => ['administer CiviCRM'],
    ];
  }

}
