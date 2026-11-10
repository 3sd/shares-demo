<?php

namespace Civi\Api4\Action\SharesDemo;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\SharesDemo\SampleData;

/**
 * Generate sample societies, issues, investors and transactions for demos and testing.
 *
 * Refuses to run when any share issue already exists.
 */
class Generate extends AbstractAction {

  /**
   * Date the sample history is built back from, e.g. 2026-11-04. Defaults to today.
   *
   * @var string
   */
  protected $baseDate;

  public function _run(Result $result): void {
    $result[] = (new SampleData($this->baseDate))->generate();
  }

}
