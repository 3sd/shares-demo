<?php

namespace Civi\SharesDemo;

use Civi\Api4\Activity;
use Civi\Api4\ActivityContact;
use Civi\Api4\BankAccount;
use Civi\Api4\BankAccountReference;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\MessageTemplate;
use Civi\Api4\OptionValue;
use Civi\Api4\SavedSearch;
use Civi\Api4\ShareAccount;
use Civi\Api4\ShareAccountingPeriod;
use Civi\Api4\ShareEntry;
use Civi\Api4\ShareHolding;
use Civi\Api4\ShareIssue;
use Civi\Api4\ShareOutboundPayment;
use Civi\Api4\ShareOutboundPaymentBatch;
use Civi\Api4\ShareOutboundPaymentItem;
use Civi\Api4\ShareTransaction;

/**
 * Deletes everything SampleData::generate() created, leaving CiviShares
 * installed so it can be generated again.
 */
class SampleDataReset {

  private array $contactIds = [];

  private array $summary = [];

  public function reset(): array {
    $this->contactIds = GroupContact::get(FALSE)
      ->addSelect('contact_id')
      ->addWhere('group_id.name', '=', SampleData::GROUP_NAME)
      ->execute()
      ->column('contact_id');

    if (!empty($this->contactIds)) {
      $this->deleteShares();
      $this->deleteActivities();
      $this->deleteContacts();
    }
    $this->summary['Group'] = Group::delete(FALSE)
      ->addWhere('name', '=', SampleData::GROUP_NAME)
      ->execute()
      ->count();
    $this->revertTemplates();

    return $this->summary;
  }

  private function deleteShares(): void {
    $issueIds = ShareIssue::get(FALSE)
      ->addSelect('id')
      ->addWhere('society_id', 'IN', $this->contactIds)
      ->execute()
      ->column('id');
    if (empty($issueIds)) {
      return;
    }
    $holdingIds = ShareHolding::get(FALSE)
      ->addSelect('id')
      ->addWhere('issue_id', 'IN', $issueIds)
      ->execute()
      ->column('id');
    $accountIds = ShareAccount::get(FALSE)
      ->addSelect('id')
      ->addWhere('holding_id', 'IN', $holdingIds)
      ->execute()
      ->column('id');
    $transactionIds = ShareEntry::get(FALSE)
      ->addSelect('transaction_id')
      ->addClause('OR', ['from_account_id', 'IN', $accountIds], ['to_account_id', 'IN', $accountIds])
      ->execute()
      ->column('transaction_id');

    // CiviShares refuses to delete batches with completed payments. Items cascade from the payment.
    ShareOutboundPayment::delete(FALSE)
      ->addWhere('outbound_payment_batch_id.society_id', 'IN', $this->contactIds)
      ->execute();
    $this->summary['ShareOutboundPaymentBatch'] = ShareOutboundPaymentBatch::delete(FALSE)
      ->addWhere('society_id', 'IN', $this->contactIds)
      ->execute()
      ->count();
    ShareOutboundPaymentItem::delete(FALSE)
      ->addWhere('holding_id', 'IN', $holdingIds)
      ->execute();
    ShareEntry::delete(FALSE)
      ->addWhere('transaction_id', 'IN', array_unique($transactionIds))
      ->execute();
    // Certificate queue items cascade from the transaction.
    $this->summary['ShareTransaction'] = ShareTransaction::delete(FALSE)
      ->addWhere('id', 'IN', array_unique($transactionIds))
      ->execute()
      ->count();
    ShareAccount::delete(FALSE)
      ->addWhere('id', 'IN', $accountIds)
      ->execute();
    $this->summary['ShareHolding'] = ShareHolding::delete(FALSE)
      ->addWhere('id', 'IN', $holdingIds)
      ->execute()
      ->count();
    // Statement queue items cascade from the period.
    $this->summary['ShareAccountingPeriod'] = ShareAccountingPeriod::delete(FALSE)
      ->addWhere('issue_id', 'IN', $issueIds)
      ->execute()
      ->count();
    ShareIssue::update(FALSE)
      ->addWhere('id', 'IN', $issueIds)
      ->addValue('reinvestment_issue_id', NULL)
      ->execute();
    $this->summary['ShareIssue'] = ShareIssue::delete(FALSE)
      ->addWhere('id', 'IN', $issueIds)
      ->execute()
      ->count();
  }

  /**
   * APIv4 does not delete activity attachments, so the statement and
   * certificate PDFs are removed first.
   */
  private function deleteActivities(): void {
    $activityIds = ActivityContact::get(FALSE)
      ->addSelect('activity_id')
      ->addWhere('contact_id', 'IN', $this->contactIds)
      ->addWhere('activity_id.activity_type_id:name', 'IN', ['Share Certificate', 'Share Statement'])
      ->execute()
      ->column('activity_id');
    if (empty($activityIds)) {
      return;
    }
    $activityIds = array_unique($activityIds);
    foreach ($activityIds as $activityId) {
      \CRM_Core_BAO_File::deleteEntityFile('civicrm_activity', $activityId);
    }
    $this->summary['Activity'] = Activity::delete(FALSE)
      ->addWhere('id', 'IN', $activityIds)
      ->execute()
      ->count();
  }

  /**
   * Contributions go first because contacts with financial records are not
   * deleted. Notifications cascade from the contact.
   */
  private function deleteContacts(): void {
    BankAccountReference::delete(FALSE)
      ->addWhere('ba_id.contact_id', 'IN', $this->contactIds)
      ->execute();
    $this->summary['BankAccount'] = BankAccount::delete(FALSE)
      ->addWhere('contact_id', 'IN', $this->contactIds)
      ->execute()
      ->count();
    $this->summary['Contribution'] = Contribution::delete(FALSE)
      ->addWhere('contact_id', 'IN', $this->contactIds)
      ->execute()
      ->count();

    $registers = [];
    foreach ($this->contactIds as $contactId) {
      $registers[] = 'society_' . $contactId . '_register';
    }
    Group::delete(FALSE)
      ->addWhere('name', 'IN', $registers)
      ->execute();
    SavedSearch::delete(FALSE)
      ->addWhere('name', 'IN', $registers)
      ->execute();

    $this->summary['Contact'] = Contact::delete(FALSE)
      ->setUseTrash(FALSE)
      ->addWhere('id', 'IN', $this->contactIds)
      ->execute()
      ->count();
  }

  private function revertTemplates(): void {
    $templateIds = MessageTemplate::get(FALSE)
      ->addSelect('id')
      ->addWhere('workflow_name', 'IN', ['share_statement', 'share_certificate'])
      ->addWhere('is_default', '=', TRUE)
      ->execute()
      ->column('id');
    foreach ($templateIds as $templateId) {
      \CRM_Core_BAO_MessageTemplate::revert($templateId);
    }
    OptionValue::delete(FALSE)
      ->addWhere('option_group_id:name', '=', 'pdf_format')
      ->addWhere('name', 'IN', ['shares_sample_portrait', 'shares_sample_landscape'])
      ->execute();
  }

}
