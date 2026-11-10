<?php

namespace Civi\SharesDemo;

use Civi\Api4\Activity;
use Civi\Api4\BankAccount;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\EntityFile;
use Civi\Api4\Group;
use Civi\Api4\MessageTemplate;
use Civi\Api4\Notification;
use Civi\Api4\SavedSearch;
use Civi\Api4\ShareAccount;
use Civi\Api4\ShareAccountingPeriod;
use Civi\Api4\ShareCertificateQueue;
use Civi\Api4\ShareEntry;
use Civi\Api4\ShareHolding;
use Civi\Api4\ShareIssue;
use Civi\Api4\ShareOutboundPayment;
use Civi\Api4\ShareStatementQueue;
use Civi\Api4\ShareTransaction;
use Civi\Shares\Certificate;
use Civi\Shares\Issue;
use Civi\Shares\Purchase;
use Civi\Shares\Statement;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * Generating takes a few minutes, so it runs once for the whole class and the
 * database is rebuilt afterwards instead of rolling back each test.
 *
 * @group headless
 */
class SampleDataTest extends TestCase implements HeadlessInterface {

  private static bool $generated = FALSE;

  public function setUpHeadless() {
    return self::getHeadless()->apply();
  }

  private static function getHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install('bank_account')
      ->install('notification')
      ->install('shares')
      ->installMe(__DIR__);
  }

  public function setUp(): void {
    parent::setUp();
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['all CiviCRM permissions and ACLs'];
    if (!self::$generated) {
      self::createOtherSociety();
      (new SampleData('2026-11-04'))->generate();
      self::$generated = TRUE;
    }
  }

  /**
   * Share data that exists before generating and must be left alone.
   */
  private static function createOtherSociety(): void {
    $society = Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('contact_sub_type', ['Society'])
      ->addValue('organization_name', 'Other Society')
      ->execute()
      ->first();
    $issue = Issue::create($society, 'Other Share Offer', 'share', 1);
    $holder = Contact::create(FALSE)
      ->addValue('first_name', 'Other')
      ->addValue('last_name', 'Holder')
      ->execute()
      ->first();
    $contribution = Contribution::create(FALSE)
      ->setValues([
        'contact_id' => $holder['id'],
        'financial_type_id:name' => 'Donation',
        'payment_instrument_id:name' => 'EFT',
        'contribution_status_id:name' => 'Completed',
        'total_amount' => 500,
        'receive_date' => '2026-01-15',
        'Share_purchase.Issue' => $issue['id'],
        'Share_purchase.Interest_choice:name' => 'receive',
        'Share_purchase.Terms_and_conditions' => TRUE,
      ])
      ->execute()
      ->first();
    Purchase::process($issue, [$contribution['id']], new \DateTimeImmutable('2026-01-15'));
    $bankAccount = BankAccount::create(FALSE)
      ->addValue('contact_id', $holder['id'])
      ->execute()
      ->first();
    ShareHolding::update(FALSE)
      ->addWhere('contact_id', '=', $holder['id'])
      ->addValue('bank_account_id', $bankAccount['id'])
      ->execute();
  }

  public function testOtherShareDataIsLeftAlone(): void {
    $certificates = ShareCertificateQueue::get(FALSE)
      ->addSelect('status_id:name')
      ->addJoin('Contribution AS contribution', 'INNER', ['purchase_id.purchase_id', '=', 'contribution.id'])
      ->addWhere('contribution.contact_id.display_name', '=', 'Other Holder')
      ->execute();
    $otherContactIds = Contact::get(FALSE)
      ->addWhere('display_name', 'IN', ['Other Holder', 'Other Society'])
      ->execute()
      ->column('id');
    $activities = Activity::get(FALSE)
      ->addSelect('activity_type_id:name')
      ->addWhere('source_contact_id', 'IN', $otherContactIds)
      ->execute();

    $this->assertSame(['pending'], $certificates->column('status_id:name'));
    $this->assertSame(['Contribution'], $activities->column('activity_type_id:name'));
  }

  public static function tearDownAfterClass(): void {
    self::getHeadless()->apply(TRUE);
    parent::tearDownAfterClass();
  }

  public function testIssues(): void {
    $issues = ShareIssue::get(FALSE)
      ->addSelect('name', 'status_id:name')
      ->execute()
      ->column('status_id:name', 'name');

    $this->assertEquals([
      'Kilbrannan Wind Share Offer' => 'closed',
      'Strathcorrie Hydro Bond' => 'completed',
      'Strathcorrie Hydro Share Offer' => 'open',
      'Ashcombe Vale Solar Share Offer' => 'open',
      'Plough Inn Community Share Offer' => 'open',
      'Kilbrannan Community Bond' => 'open',
      'Kilbrannan Repowering Share Offer' => 'open',
      'Ashcombe Vale Battery Bond' => 'open',
      'Ashcombe Vale Rooftops Share Offer' => 'open',
      'Other Share Offer' => 'open',
    ], $issues);
  }

  public function testEveryTransactionTypeIsUsed(): void {
    $used = ShareTransaction::get(FALSE)
      ->addSelect('transaction_type_id:name')
      ->addGroupBy('transaction_type_id')
      ->execute()
      ->column('transaction_type_id:name');
    $types = ShareTransaction::getFields(FALSE)
      ->setLoadOptions(['name'])
      ->addWhere('name', '=', 'transaction_type_id')
      ->execute()
      ->first()['options'];

    $this->assertEqualsCanonicalizing(array_column($types, 'name'), $used);
  }

  public function testClosedPeriodsWithInterestOrRepaymentHaveTransactions(): void {
    $periods = ShareAccountingPeriod::get(FALSE)
      ->addSelect('id', 'status_id:name', 'interest_rate', 'repayment_amount', 'COUNT(share_transaction.id) AS transactions')
      ->addJoin('ShareTransaction AS share_transaction', 'LEFT', ['id', '=', 'share_transaction.accounting_period_id'])
      ->addGroupBy('id')
      ->execute();

    $this->assertCount(62, $periods);
    foreach ($periods as $period) {
      $paysOut = $period['interest_rate'] > 0 || $period['repayment_amount'] > 0;
      if ($period['status_id:name'] === 'closed' && $paysOut) {
        $this->assertGreaterThan(0, $period['transactions'], "Period {$period['id']} has no transactions");
      }
    }
  }

  public function testFlatIssuesRepayTheSameEveryYear(): void {
    foreach (['Strathcorrie Hydro Bond', 'Kilbrannan Community Bond'] as $issueName) {
      $periods = ShareAccountingPeriod::get(FALSE)
        ->addSelect('interest_rate', 'repayment_amount')
        ->addWhere('issue_id.name', '=', $issueName)
        ->addWhere('status_id:name', '=', 'closed')
        ->execute();

      $this->assertGreaterThan(5, $periods->count());
      $this->assertCount(1, array_unique($periods->column('interest_rate')), $issueName);
      $this->assertCount(1, array_unique($periods->column('repayment_amount')), $issueName);
    }
  }

  public function testNoNegativeBalances(): void {
    $negative = ShareAccount::get(FALSE)
      ->addWhere('account_type_id:name', 'IN', ['holding', 'payable'])
      ->addWhere('balance', '<', 0)
      ->execute();

    $this->assertCount(0, $negative);
  }

  public function testCompletedIssueIsFullyRepaid(): void {
    $remaining = ShareAccount::get(FALSE)
      ->addWhere('holding_id.issue_id.name', '=', 'Strathcorrie Hydro Bond')
      ->addWhere('account_type_id:name', 'IN', ['holding', 'payable'])
      ->addWhere('balance', '!=', 0)
      ->execute();

    $this->assertCount(0, $remaining);
  }

  public function testPayments(): void {
    $payments = ShareOutboundPayment::get(FALSE)
      ->addSelect('status_id:name', 'COUNT(id) AS count')
      ->addGroupBy('status_id')
      ->execute()
      ->column('count', 'status_id:name');

    $this->assertSame(1, $payments['failed']);
    $this->assertGreaterThan(0, $payments['pending']);
    $this->assertGreaterThan(0, $payments['completed']);
  }

  public function testInvestorsHaveUkAddresses(): void {
    $contacts = Contact::get(FALSE)
      ->addSelect('display_name', 'address_primary.postal_code', 'address_primary.country_id:abbr')
      ->addWhere('groups:name', 'IN', [SampleData::GROUP_NAME])
      ->execute();

    $this->assertCount(86, $contacts);
    foreach ($contacts as $contact) {
      $this->assertMatchesRegularExpression('/^[A-Z]{1,2}\d{1,2} \d[A-Z]{2}$/', $contact['address_primary.postal_code'], $contact['display_name']);
      $this->assertSame('GB', $contact['address_primary.country_id:abbr'], $contact['display_name']);
    }
  }

  public function testStatementAndCertificateRender(): void {
    $statement = ShareStatementQueue::get(FALSE)
      ->addSelect('contact_id', 'accounting_period_id')
      ->addWhere('contact_id.display_name', '=', 'Douglas Kerr')
      ->execute();
    $html = Statement::generate(Statement::getData($statement[0]['contact_id'], $statement->column('accounting_period_id')), 'html');
    $this->assertStringContainsString('shares-statement', $html);
    $this->assertStringContainsString('Strathcorrie Hydro Share Offer', $html);
    $this->assertStringContainsString('£', $html);

    $certificate = ShareCertificateQueue::get(FALSE)
      ->addSelect('purchase_id')
      ->addWhere('purchase_id.on_behalf_of_id', 'IS NOT NULL')
      ->execute()
      ->single();
    $html = Certificate::generate(Certificate::getData($certificate['purchase_id']), 'html');
    $this->assertStringContainsString('shares-certificate', $html);
    $this->assertStringContainsString('bought on behalf of Isla MacLeod', $html);
    $this->assertStringContainsString('images/logos/strathcorrie.svg', $html);
    $this->assertStringContainsString('.signature-secretary:before { content: url(data:image/svg+xml;base64,', $html);
    $this->assertStringContainsString('Catherine Lindsay', $html);
  }

  public function testSentItemsHaveActivitiesWithPdfs(): void {
    $sentCertificates = ShareCertificateQueue::get(FALSE)
      ->addWhere('status_id:name', '=', 'sent')
      ->selectRowCount()
      ->execute()
      ->countMatched();
    $activities = Activity::get(FALSE)
      ->addSelect('id', 'activity_type_id:name', 'activity_date_time')
      ->addWhere('activity_type_id:name', 'IN', ['Share Certificate', 'Share Statement'])
      ->execute();
    $byType = array_count_values($activities->column('activity_type_id:name'));
    $attachments = EntityFile::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_activity')
      ->addWhere('entity_id', 'IN', $activities->column('id'))
      ->selectRowCount()
      ->execute()
      ->countMatched();

    $this->assertSame($sentCertificates, $byType['Share Certificate']);
    $this->assertGreaterThan(300, $byType['Share Statement']);
    $this->assertSame($activities->count(), $attachments);
    $this->assertLessThan('2016-01-01', min($activities->column('activity_date_time')));
    $this->assertLessThan('2026-11-04', max($activities->column('activity_date_time')));
  }

  public function testNoStatementsAfterDeath(): void {
    $contact = Contact::get(FALSE)
      ->addSelect('id', 'deceased_date')
      ->addWhere('display_name', '=', 'Archibald Fraser')
      ->execute()
      ->single();
    $dates = Activity::get(FALSE)
      ->addSelect('activity_date_time')
      ->addWhere('activity_type_id:name', '=', 'Share Statement')
      ->addWhere('source_contact_id', '=', $contact['id'])
      ->execute()
      ->column('activity_date_time');

    $this->assertNotEmpty($dates);
    $this->assertLessThan($contact['deceased_date'], max($dates));
  }

  public function testRecentItemsArePending(): void {
    $certificates = ShareCertificateQueue::get(FALSE)
      ->addWhere('status_id:name', '=', 'pending')
      ->selectRowCount()
      ->execute()
      ->countMatched();
    $statements = ShareStatementQueue::get(FALSE)
      ->addWhere('status_id:name', '=', 'pending')
      ->selectRowCount()
      ->execute()
      ->countMatched();

    $this->assertGreaterThan(0, $certificates);
    $this->assertGreaterThan(0, $statements);
  }

  public function testNotifications(): void {
    $notifications = Notification::get(FALSE)
      ->addSelect('contact_id.display_name', 'read_date', 'dismissed_date')
      ->execute()
      ->indexBy('contact_id.display_name');

    $this->assertNotEmpty($notifications['Giles Hargreaves']['dismissed_date']);
    $this->assertNotEmpty($notifications['Ailsa Murray']['read_date']);
    $this->assertEmpty($notifications['Isla MacLeod']['read_date']);
    $this->assertEmpty($notifications['Isla MacLeod']['dismissed_date']);
  }

  public function testRefusesToRunTwice(): void {
    $this->expectException(\CRM_Core_Exception::class);
    (new SampleData('2026-11-04'))->generate();
  }

  /**
   * Runs last as it deletes the generated data.
   */
  public function testReset(): void {
    $otherContact = Contact::create(FALSE)
      ->addValue('first_name', 'Not')
      ->addValue('last_name', 'Sample')
      ->execute()
      ->first();

    (new SampleDataReset())->reset();

    $notOther = [
      'ShareIssue' => 'society_id.display_name',
      'ShareHolding' => 'issue_id.society_id.display_name',
      'ShareAccount' => 'holding_id.issue_id.society_id.display_name',
      'ShareEntry' => 'to_account_id.holding_id.issue_id.society_id.display_name',
      'ShareAccountingPeriod' => 'issue_id.society_id.display_name',
      'ShareStatementQueue' => 'accounting_period_id.issue_id.society_id.display_name',
      'ShareOutboundPaymentBatch' => 'society_id.display_name',
      'BankAccount' => 'contact_id.display_name',
      'Contribution' => 'contact_id.display_name',
    ];
    foreach ($notOther as $entity => $field) {
      $count = civicrm_api4($entity, 'get', [
        'checkPermissions' => FALSE,
        'select' => ['row_count'],
        'where' => [[$field, 'NOT IN', ['Other Society', 'Other Holder']]],
      ])->countMatched();
      $this->assertSame(0, $count, $entity);
    }
    $transactions = ShareTransaction::get(FALSE)->selectRowCount()->execute()->countMatched();
    $otherTransactions = ShareEntry::get(FALSE)
      ->addSelect('COUNT(DISTINCT transaction_id) AS count')
      ->execute()
      ->first();
    $this->assertSame($otherTransactions['count'], $transactions);
    $this->assertSame(0, ShareOutboundPayment::get(FALSE)->selectRowCount()->execute()->countMatched());
    $this->assertSame(1, ShareCertificateQueue::get(FALSE)->selectRowCount()->execute()->countMatched());
    $this->assertSame(0, EntityFile::get(FALSE)->selectRowCount()->execute()->countMatched());
    $this->testOtherShareDataIsLeftAlone();

    $activities = Activity::get(FALSE)
      ->addWhere('activity_type_id:name', 'IN', ['Share Certificate', 'Share Statement'])
      ->execute();
    $this->assertCount(0, $activities);
    $groups = Group::get(FALSE)
      ->addSelect('title')
      ->addClause('OR', ['name', '=', SampleData::GROUP_NAME], ['name', 'LIKE', 'society\\_%\\_register'], ['name', 'LIKE', 'issue\\_%\\_register'])
      ->execute();
    $this->assertEqualsCanonicalizing(['Other Society society register', 'Other Share Offer register'], $groups->column('title'));
    $searches = SavedSearch::get(FALSE)
      ->addClause('OR', ['name', 'LIKE', 'society\\_%\\_register'], ['name', 'LIKE', 'issue\\_%\\_register'])
      ->execute();
    $this->assertCount(2, $searches);
    $societies = Contact::get(FALSE)
      ->addWhere('contact_sub_type', '=', 'Society')
      ->execute()
      ->column('display_name');
    $this->assertSame(['Other Society'], $societies);
    $this->assertCount(1, Contact::get(FALSE)->addWhere('id', '=', $otherContact['id'])->execute());

    $templates = MessageTemplate::get(FALSE)
      ->addSelect('msg_html', 'is_reserved', 'pdf_format_id')
      ->addWhere('workflow_name', '=', 'share_certificate')
      ->execute()
      ->indexBy('is_reserved');
    $this->assertSame($templates[1]['msg_html'], $templates[0]['msg_html']);
    $this->assertNull($templates[0]['pdf_format_id']);
  }


}
