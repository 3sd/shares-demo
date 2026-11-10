<?php

namespace Civi\Api4\Action\SharesDemo;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\SharesDemo\SampleDataReset;

/**
 * Delete the sample data: the contacts in the sample data group with their
 * share issues, transactions, payments, bank accounts, contributions and
 * statement and certificate activities, and revert the statement and
 * certificate templates.
 *
 * Site settings (currency, default country, bank account key) are kept.
 */
class Reset extends AbstractAction {

  public function _run(Result $result): void {
    $result[] = (new SampleDataReset())->reset();
  }

}
