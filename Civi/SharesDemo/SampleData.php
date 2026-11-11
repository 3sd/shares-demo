<?php

namespace Civi\SharesDemo;

use Civi\Api4\Activity;
use Civi\Api4\BankAccount;
use Civi\Api4\BankAccountReference;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractUpdateAction;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\MessageTemplate;
use Civi\Api4\Notification;
use Civi\Api4\OptionValue;
use Civi\Api4\ShareAccount;
use Civi\Api4\ShareAccountingPeriod;
use Civi\Api4\ShareCertificateQueue;
use Civi\Api4\ShareEntry;
use Civi\Api4\ShareHolding;
use Civi\Api4\ShareIssue;
use Civi\Api4\ShareOutboundPayment;
use Civi\Api4\ShareOutboundPaymentBatch;
use Civi\Api4\ShareStatementQueue;
use Civi\Core\Event\GenericHookEvent;
use Civi\Shares\AccountingPeriod;
use Civi\Shares\Certificate;
use Civi\Shares\Date;
use Civi\Shares\Holding;
use Civi\Shares\Issue;
use Civi\Shares\OutboundPaymentBatch;
use Civi\Shares\Purchase;
use Civi\Shares\Statement;
use Civi\Shares\Transaction;
use CRM_SharesDemo_ExtensionUtil as E;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

/**
 * Builds about ten years of share history for four UK societies by replaying
 * dated events (issues, purchases, period closes, payouts, transfers) in order
 * through the normal CiviShares business logic.
 *
 * Amounts, addresses and interest choices are derived from a hash of the
 * investor and issue, so every run produces the same data relative to the
 * base date.
 */
class SampleData {

  public const GROUP_NAME = 'shares_sample_data';

  private \DateTimeImmutable $baseDate;

  /**
   * Start (1 April) of the financial year containing the base date.
   */
  private \DateTimeImmutable $yearStart;

  private array $events = [];

  private array $lastPayout = [];

  private int $groupId;

  private array $societies = [];

  private array $issues = [];

  private array $raised = [];

  private array $contacts = [];

  private array $addresses = [];

  private array $choices = [];

  private array $bankAccounts = [];

  private int $countryId;

  private RSA\PublicKey $publicKey;

  private ?string $privateJwk = NULL;

  public function __construct(?string $baseDate = NULL) {
    if (empty($baseDate)) {
      $baseDate = 'today';
    }
    $this->baseDate = (new \DateTimeImmutable($baseDate))->setTime(12, 0);
    $this->yearStart = $this->baseDate->setDate($this->baseDate->format('Y'), 4, 1)->setTime(0, 0);
    if ($this->yearStart > $this->baseDate) {
      $this->yearStart = $this->yearStart->modify('-1 year');
    }
  }

  public function generate(): array {
    $existingGroup = Group::get(FALSE)
      ->addWhere('name', '=', self::GROUP_NAME)
      ->execute()
      ->count();
    if ($existingGroup) {
      throw new \CRM_Core_Exception('Sample data has already been generated. Run SharesDemo.reset first.');
    }

    $this->configureSite();
    $this->createGroup();
    $this->createSocieties();
    $this->setupEncryption();
    $this->createInvestors();
    $this->scheduleIssues();
    $this->scheduleInvestments();
    $this->scheduleScenarios();
    $this->scheduleMailings();
    $this->runEvents();
    $this->createNotifications();
    $this->createUnprocessedContributions();

    return $this->getSummary();
  }

  private function getSocietyDefinitions(): array {
    return [
      'strathcorrie' => [
        'name' => 'Strathcorrie Community Hydro Society',
        'email' => 'members@strathcorriehydro.example.org',
        'phone' => '01632 960114',
        'street' => 'The Old Mill, Kenmore Road',
        'village' => 'Strathcorrie',
        'city' => 'Aberfeldy',
        'postcode' => 'PH15 2DA',
        'registration' => 'SP002316',
        'region' => 'perthshire',
        'cause' => 'community hydro power in Strathcorrie',
        'fund' => 'the Strathcorrie community fund, which supports local groups and the village hall',
        'round_to_share' => TRUE,
        'colour' => '#1d4e45',
        'secretary' => 'Catherine Lindsay',
        'chair' => 'Duncan McGregor',
      ],
      'kilbrannan' => [
        'name' => 'Kilbrannan Wind Co-operative',
        'email' => 'info@kilbrannanwind.example.org',
        'phone' => '01632 960287',
        'street' => '14 Longrow',
        'village' => '',
        'city' => 'Campbeltown',
        'postcode' => 'PA28 6AL',
        'registration' => 'SP002490',
        'region' => 'kintyre',
        'cause' => 'community-owned wind power on Kintyre',
        'fund' => 'the Kilbrannan community benefit fund, which helps with home insulation and fuel bills across Kintyre',
        'round_to_share' => FALSE,
        'colour' => '#1f4a7a',
        'secretary' => 'Iona Galbraith',
        'chair' => 'Alastair Reid',
      ],
      'ashcombe' => [
        'name' => 'Ashcombe Vale Solar Co-operative',
        'email' => 'hello@ashcombevalesolar.example.org',
        'phone' => '01632 960431',
        'street' => 'Unit 4, Saxonvale Yard',
        'village' => '',
        'city' => 'Frome',
        'postcode' => 'BA11 1PS',
        'registration' => '7318',
        'region' => 'somerset',
        'cause' => 'community solar in the Ashcombe valley',
        'fund' => 'the Ashcombe Vale community benefit fund, which pays for energy advice and school projects',
        'round_to_share' => FALSE,
        'colour' => '#9a5b13',
        'secretary' => 'Helen Marsh',
        'chair' => 'Jonathan Price',
      ],
      'plough' => [
        'name' => 'Plough Inn Community Benefit Society',
        'email' => 'shareholders@ploughinn.example.org',
        'phone' => '01632 960552',
        'street' => 'The Plough Inn, High Street',
        'village' => 'Little Haddon',
        'city' => 'Craven Arms',
        'postcode' => 'SY7 9QE',
        'registration' => '8142',
        'region' => 'shropshire',
        'cause' => 'the Plough Inn, the last pub in Little Haddon',
        'fund' => 'the upkeep of the Plough and its community room',
        'round_to_share' => TRUE,
        'colour' => '#7a1f2b',
        'secretary' => 'Sue Bradley',
        'chair' => 'Martin Hale',
      ],
    ];
  }

  private function getRegions(): array {
    $scottishStreets = ['Kenmore Street', 'Dunkeld Road', 'Atholl Road', 'Bridgend', 'Burnside', 'Kirk Wynd', 'Mill Brae', 'Main Street', 'Tay Terrace', 'Breadalbane Crescent', 'Glebe Road', 'Station Road'];
    $englishStreets = ['Church Lane', 'The Green', 'Vicarage Road', 'Mill Lane', 'Orchard Close', 'High Street', 'Castle Street', 'Bath Street', 'Broad Street', 'Manor Farm Lane', 'Bell Lane', 'Rectory Gardens'];
    $scottishHouses = ['Rowan Cottage', 'The Old Manse', 'Birchwood', 'Tigh na Mara', 'Craigard', 'Braeside'];
    $englishHouses = ['Rose Cottage', 'The Old Forge', 'Orchard House', 'Meadowbank', 'The Granary', 'Hollybush Cottage'];
    return [
      'perthshire' => [
        'towns' => [['Aberfeldy', 'PH15'], ['Pitlochry', 'PH16'], ['Dunkeld', 'PH8'], ['Crieff', 'PH7'], ['Perth', 'PH2'], ['Killin', 'FK21'], ['Stirling', 'FK8'], ['Edinburgh', 'EH10']],
        'streets' => $scottishStreets,
        'houses' => $scottishHouses,
      ],
      'kintyre' => [
        'towns' => [['Campbeltown', 'PA28'], ['Tarbert', 'PA29'], ['Carradale', 'PA28'], ['Lochgilphead', 'PA31'], ['Oban', 'PA34'], ['Glasgow', 'G12']],
        'streets' => $scottishStreets,
        'houses' => $scottishHouses,
      ],
      'somerset' => [
        'towns' => [['Frome', 'BA11'], ['Bruton', 'BA10'], ['Wells', 'BA5'], ['Shepton Mallet', 'BA4'], ['Bath', 'BA2'], ['Bristol', 'BS6']],
        'streets' => $englishStreets,
        'houses' => $englishHouses,
      ],
      'shropshire' => [
        'towns' => [['Craven Arms', 'SY7'], ['Ludlow', 'SY8'], ['Church Stretton', 'SY6'], ["Bishop's Castle", 'SY9'], ['Shrewsbury', 'SY3']],
        'streets' => $englishStreets,
        'houses' => $englishHouses,
      ],
    ];
  }

  /**
   * Each closed period is [interest rate %, capital repayment as % of the
   * capital raised so far]. The first period starts 'first_period' relative
   * to the current year start.
   */
  private function getIssueDefinitions(): array {
    return [
      'SH1' => [
        'society' => 'strathcorrie',
        'name' => 'Strathcorrie Hydro Bond',
        'asset' => 'bond',
        'min' => 500,
        'max' => 50000,
        'first_period' => '-10 years',
        'periods' => array_fill(0, 10, [4.5, 10]),
        'status' => 'completed',
        'content' => '<p>A ten year bond that funded the 250 kW run-of-river hydro scheme on the Allt Corrie burn above Strathcorrie. Interest of 4.5% and a tenth of the capital were repaid every year.</p>',
      ],
      'SH2' => [
        'society' => 'strathcorrie',
        'name' => 'Strathcorrie Hydro Share Offer',
        'asset' => 'share',
        'min' => 100,
        'max' => 100000,
        'first_period' => '-9 years',
        'periods' => [[3.0, 0], [3.25, 0], [3.5, 0], [1.0, 0], [1.5, 0], [2.5, 2], [4.0, 3], [4.0, 3], [3.75, 4]],
        'status' => 'open',
        'reinvest' => TRUE,
        'content' => '<p>Withdrawable community shares that bought out the original developer\'s stake in the Allt Corrie hydro scheme. Surplus income goes to the Strathcorrie community fund.</p>',
      ],
      'KW1' => [
        'society' => 'kilbrannan',
        'name' => 'Kilbrannan Wind Share Offer',
        'asset' => 'share',
        'min' => 100,
        'max' => 100000,
        'first_period' => '-11 years',
        'periods' => [[2.0, 0], [2.5, 0], [3.0, 0], [3.0, 2], [3.0, 2], [0, 0], [1.0, 0], [3.5, 3], [4.0, 4], [4.0, 5], [3.5, 5]],
        'status' => 'open',
        'reinvest' => TRUE,
        'content' => '<p>Three community-owned turbines on Cnoc Dubh, overlooking Kilbrannan Sound.</p>',
      ],
      'KW2' => [
        'society' => 'kilbrannan',
        'name' => 'Kilbrannan Repowering Share Offer',
        'asset' => 'share',
        'min' => 50,
        'max' => 100000,
        'first_period' => '-2 years',
        'periods' => [[4.0, 0], [4.25, 0]],
        'status' => 'open',
        'reinvest' => TRUE,
        'replaces' => 'KW1',
        'content' => '<p>Replacing the original Cnoc Dubh turbines with two larger, quieter machines. Members of the 2015 offer who reinvest their interest do so here.</p>',
      ],
      'KW3' => [
        'society' => 'kilbrannan',
        'name' => 'Kilbrannan Community Bond',
        'asset' => 'bond',
        'min' => 250,
        'max' => 50000,
        'first_period' => '-6 years',
        'periods' => array_fill(0, 6, [5.0, 12.5]),
        'status' => 'open',
        'content' => '<p>An eight year bond paying 5% a year, funding the battery and grid connection upgrade at Cnoc Dubh. An eighth of the capital is repaid every year.</p>',
      ],
      'AV1' => [
        'society' => 'ashcombe',
        'name' => 'Ashcombe Vale Solar Share Offer',
        'asset' => 'share',
        'min' => 100,
        'max' => 100000,
        'first_period' => '-8 years',
        'periods' => [[3.0, 0], [3.0, 0], [0.5, 0], [2.0, 0], [3.5, 2.5], [4.0, 5], [4.0, 5], [4.5, 7.5]],
        'status' => 'open',
        'reinvest' => TRUE,
        'content' => '<p>Rooftop solar on schools, the village hall and farm buildings across the Ashcombe valley.</p>',
      ],
      'AV2' => [
        'society' => 'ashcombe',
        'name' => 'Ashcombe Vale Battery Bond',
        'asset' => 'bond',
        'min' => 250,
        'max' => 50000,
        'first_period' => '-1 year',
        'periods' => [[5.0, 0]],
        'status' => 'open',
        'content' => '<p>A five year bond for battery storage beside the Ashcombe solar farm.</p>',
      ],
      'AV3' => [
        'society' => 'ashcombe',
        'name' => 'Ashcombe Vale Rooftops Share Offer',
        'asset' => 'share',
        'min' => 50,
        'max' => 100000,
        'first_period' => '+0 years',
        'periods' => [],
        'status' => 'open',
        'reinvest' => TRUE,
        'content' => '<p>Our newest offer, putting solar panels on 30 more community buildings around Frome.</p>',
      ],
      'PI1' => [
        'society' => 'plough',
        'name' => 'Plough Inn Community Share Offer',
        'asset' => 'share',
        'min' => 100,
        'max' => 20000,
        'first_period' => '-7 years',
        'periods' => [[0, 0], [0, 0], [0, 0], [1.5, 0], [2.0, 0], [2.5, 0], [3.0, 2]],
        'status' => 'open',
        'content' => '<p>The village bought the freehold of the Plough, Little Haddon\'s last pub, in 2019. Interest is only paid once the pub trades at a surplus.</p>',
      ],
    ];
  }

  /**
   * [key, contact type, first name or organisation name, last name, issues, has bank account, fixed amount, interest choice]
   */
  private function getInvestorDefinitions(): array {
    return [
      ['fmacleod', 'Individual', 'Fiona', 'MacLeod', ['SH2'], TRUE],
      ['hmacleod', 'Individual', 'Hamish', 'MacLeod', ['SH1', 'SH2'], TRUE],
      ['imacleod', 'Individual', 'Isla', 'MacLeod', [], FALSE],
      ['afraser', 'Individual', 'Archibald', 'Fraser', ['KW1'], TRUE, 4000],
      ['mfraser', 'Individual', 'Morag', 'Fraser', ['SH2'], TRUE],
      ['ecampbell', 'Individual', 'Eilidh', 'Campbell', ['SH2', 'KW3'], TRUE],
      ['agrant', 'Individual', 'Alasdair', 'Grant', ['SH1'], TRUE],
      ['amurray', 'Individual', 'Ailsa', 'Murray', ['SH2'], FALSE],
      ['fmackenzie', 'Individual', 'Fergus', 'Mackenzie', ['KW1', 'KW3'], TRUE],
      ['istewart', 'Individual', 'Iain', 'Stewart', ['SH1', 'SH2'], TRUE],
      ['kross', 'Individual', 'Kirsty', 'Ross', ['KW1', 'KW2'], TRUE],
      ['dkerr', 'Individual', 'Douglas', 'Kerr', ['SH2'], TRUE, 10000],
      ['cmacdonald', 'Individual', 'Catriona', 'MacDonald', ['KW1', 'KW2'], TRUE],
      ['rmcintyre', 'Individual', 'Rory', 'McIntyre', ['SH1'], FALSE],
      ['smunro', 'Individual', 'Shona', 'Munro', ['KW3'], TRUE],
      ['gpaterson', 'Individual', 'Gordon', 'Paterson', ['SH1', 'KW1'], TRUE],
      ['jdouglas', 'Individual', 'Jean', 'Douglas', ['SH2'], TRUE],
      ['ehenderson', 'Individual', 'Euan', 'Henderson', ['KW1'], TRUE],
      ['bsutherland', 'Individual', 'Blair', 'Sutherland', ['KW2'], TRUE],
      ['mgillies', 'Individual', 'Mhairi', 'Gillies', ['KW2', 'KW3'], TRUE],
      ['tcameron', 'Individual', 'Torquil', 'Cameron', ['KW1'], FALSE],
      ['lbuchanan', 'Individual', 'Lorna', 'Buchanan', ['SH1'], TRUE],
      ['nrobertson', 'Individual', 'Neil', 'Robertson', ['SH2', 'KW2'], TRUE],
      ['eforbes', 'Individual', 'Elspeth', 'Forbes', ['KW3'], FALSE],
      ['cmackay', 'Individual', 'Callum', 'Mackay', ['SH1', 'SH2'], TRUE],
      ['mduncan', 'Individual', 'Morven', 'Duncan', ['KW1'], TRUE],
      ['aanderson', 'Individual', 'Angus', 'Anderson', ['SH1', 'KW3'], TRUE],
      ['jwallace', 'Individual', 'Jamie', 'Wallace', ['KW2'], TRUE],
      ['hsinclair', 'Individual', 'Heather', 'Sinclair', ['SH2'], TRUE],
      ['rmaclean', 'Individual', 'Ruaridh', 'MacLean', ['KW1', 'KW3'], TRUE],
      ['strathcc', 'Organization', 'Strathcorrie Community Council', NULL, ['SH1', 'SH2'], TRUE],
      ['kilbtrust', 'Organization', 'Kilbrannan Community Trust', NULL, ['KW1', 'KW3'], TRUE],
      ['curling', 'Organization', 'Breadalbane Curling Club', NULL, ['SH2'], FALSE],
      ['hpemberton', 'Individual', 'Harriet', 'Pemberton', ['AV1', 'AV2'], TRUE, NULL, 'receive'],
      ['nashworth', 'Individual', 'Nigel', 'Ashworth', ['AV1'], TRUE, 3000],
      ['cashworth', 'Individual', 'Clara', 'Ashworth', ['AV1'], TRUE],
      ['pwhitcombe', 'Individual', 'Penelope', 'Whitcombe', ['AV1'], TRUE, 5000],
      ['rfairbanks', 'Individual', 'Rupert', 'Fairbanks', ['AV1', 'AV2'], TRUE],
      ['ghargreaves', 'Individual', 'Giles', 'Hargreaves', ['AV1'], FALSE],
      ['bthornton', 'Individual', 'Beatrice', 'Thornton', ['AV1', 'AV3'], TRUE],
      ['ewhitaker', 'Individual', 'Edward', 'Whitaker', ['AV2'], TRUE],
      ['mbarrington', 'Individual', 'Margaret', 'Barrington', ['AV1'], TRUE],
      ['orawlings', 'Individual', 'Oliver', 'Rawlings', ['AV1', 'PI1'], TRUE],
      ['sfoxley', 'Individual', 'Sophie', 'Foxley', ['AV2'], FALSE],
      ['tpickering', 'Individual', 'Toby', 'Pickering', ['AV1', 'AV3'], TRUE],
      ['gcartwright', 'Individual', 'Gwen', 'Cartwright', ['AV1', 'AV2'], TRUE],
      ['hlangley', 'Individual', 'Hugo', 'Langley', ['AV3'], TRUE],
      ['jholloway', 'Individual', 'Jemima', 'Holloway', ['AV1', 'AV3'], TRUE],
      ['apennington', 'Individual', 'Arthur', 'Pennington', ['AV2', 'PI1'], TRUE],
      ['vashdown', 'Individual', 'Violet', 'Ashdown', ['AV1'], TRUE],
      ['wcrowther', 'Individual', 'William', 'Crowther', ['AV1'], FALSE],
      ['dbramley', 'Individual', 'Daphne', 'Bramley', ['AV3'], TRUE],
      ['rhawthorne', 'Individual', 'Robert', 'Hawthorne', ['AV1', 'AV2'], TRUE],
      ['tbuckley', 'Individual', 'Tom', 'Buckley', ['PI1'], TRUE],
      ['shiggins', 'Individual', 'Sally', 'Higgins', ['PI1'], TRUE],
      ['rbartlett', 'Individual', 'Reg', 'Bartlett', ['PI1'], FALSE],
      ['jmorley', 'Individual', 'Joan', 'Morley', ['PI1'], TRUE],
      ['pwakefield', 'Individual', 'Pete', 'Wakefield', ['PI1'], TRUE],
      ['kosborne', 'Individual', 'Keith', 'Osborne', ['PI1'], TRUE],
      ['bnewbold', 'Individual', 'Brenda', 'Newbold', ['PI1'], TRUE],
      ['dfletcher', 'Individual', 'Dave', 'Fletcher', ['PI1', 'AV1'], TRUE],
      ['lpritchard', 'Individual', 'Linda', 'Pritchard', ['PI1'], FALSE],
      ['cdavies', 'Individual', 'Cerys', 'Davies', ['PI1'], TRUE],
      ['gmorgan', 'Individual', 'Gareth', 'Morgan', ['PI1'], TRUE],
      ['mtranter', 'Individual', 'Mike', 'Tranter', ['PI1'], TRUE],
      ['ahopkins', 'Individual', 'Anne', 'Hopkins', ['PI1'], TRUE],
      ['ashcombepc', 'Organization', 'Ashcombe Vale Parish Council', NULL, ['AV1', 'AV3'], TRUE],
      ['staldhelm', 'Organization', "St Aldhelm's Parochial Church Council", NULL, ['AV1'], FALSE],
      ['avwi', 'Organization', "Ashcombe Vale Women's Institute", NULL, ['AV2'], TRUE],
      ['haddoncc', 'Organization', 'Little Haddon Cricket Club', NULL, ['PI1'], TRUE],
      ['morrismen', 'Organization', 'Haddon Morris Men', NULL, ['PI1'], FALSE],
      ['psharma', 'Individual', 'Priya', 'Sharma', ['SH2'], TRUE],
      ['ihussain', 'Individual', 'Imran', 'Hussain', ['KW2', 'KW3'], TRUE],
      ['wchen', 'Individual', 'Wei', 'Chen', ['SH1'], TRUE],
      ['twisniewski', 'Individual', 'Tomasz', 'Wiśniewski', ['SH2'], TRUE],
      ['soneill', 'Individual', 'Siobhán', "O'Neill", ['KW3'], FALSE],
      ['kmensah', 'Individual', 'Kwame', 'Mensah', ['AV1', 'AV3'], TRUE],
      ['gokafor', 'Individual', 'Grace', 'Okafor', ['AV2'], TRUE],
      ['fbegum', 'Individual', 'Fatima', 'Begum', ['AV1'], FALSE],
      ['hkaur', 'Individual', 'Harpreet', 'Kaur', ['AV1', 'AV2'], TRUE],
      ['jsingh', 'Individual', 'Jaspal', 'Singh', ['AV1'], TRUE],
      ['zashtonclarke', 'Individual', 'Zoë', 'Ashton-Clarke', ['AV3'], TRUE],
    ];
  }

  /**
   * Investors who live in a particular town rather than one picked for them.
   */
  private function getHomeTowns(): array {
    return [
      'psharma' => ['Edinburgh', 'EH10'],
      'ihussain' => ['Glasgow', 'G41'],
      'wchen' => ['Edinburgh', 'EH9'],
      'twisniewski' => ['Perth', 'PH1'],
      'soneill' => ['Glasgow', 'G12'],
      'kmensah' => ['Bristol', 'BS5'],
      'gokafor' => ['Bath', 'BA2'],
      'fbegum' => ['Bristol', 'BS5'],
      'hkaur' => ['Bath', 'BA1'],
      'zashtonclarke' => ['Frome', 'BA11'],
    ];
  }

  /**
   * Family members share an address.
   */
  private function getHouseholds(): array {
    return [
      'hmacleod' => 'fmacleod',
      'imacleod' => 'fmacleod',
      'mfraser' => 'afraser',
      'cashworth' => 'nashworth',
      'jsingh' => 'hkaur',
    ];
  }

  private function configureSite(): void {
    \Civi::settings()->set('defaultCurrency', 'GBP');
    $country = \Civi\Api4\Country::get(FALSE)
      ->addWhere('iso_code', '=', 'GB')
      ->execute()
      ->first();
    $this->countryId = $country['id'];
    \Civi::settings()->set('defaultContactCountry', $this->countryId);
    $this->installTemplates();
  }

  /**
   * Replaces the editable default statement and certificate templates with the
   * designed ones in messageTemplates/, printed on A4.
   */
  private function installTemplates(): void {
    $templates = [
      'share_statement' => ['A4 portrait (CiviShares sample)', 'portrait'],
      'share_certificate' => ['A4 landscape (CiviShares sample)', 'landscape'],
    ];
    foreach ($templates as $workflow => [$label, $orientation]) {
      $format = OptionValue::save(FALSE)
        ->addRecord([
          'option_group_id:name' => 'pdf_format',
          'name' => 'shares_sample_' . $orientation,
          'label' => $label,
          'value' => json_encode([
            'paper_size' => 'a4',
            'orientation' => $orientation,
            'metric' => 'mm',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 18,
            'margin_right' => 18,
          ]),
        ])
        ->setMatch(['option_group_id', 'name'])
        ->execute()
        ->first();
      MessageTemplate::update(FALSE)
        ->addWhere('workflow_name', '=', $workflow)
        ->addWhere('is_default', '=', TRUE)
        ->addValue('msg_html', file_get_contents(E::path("messageTemplates/$workflow.html")))
        ->addValue('pdf_format_id', $format['id'])
        ->execute();
    }
  }

  private function createGroup(): void {
    $group = Group::save(FALSE)
      ->addRecord([
        'name' => self::GROUP_NAME,
        'title' => 'Shares sample data',
        'description' => 'Contacts created by SharesDemo.generate',
      ])
      ->setMatch(['name'])
      ->execute()
      ->first();
    $this->groupId = $group['id'];
  }

  private function createSocieties(): void {
    foreach ($this->getSocietyDefinitions() as $key => $society) {
      $this->societies[$key] = Contact::create(FALSE)
        ->setValues(array_merge([
          'contact_type' => 'Organization',
          'contact_sub_type' => ['Society'],
          'organization_name' => $society['name'],
          'legal_name' => $society['name'] . ' Limited',
          'email_primary.email' => $society['email'],
          'phone_primary.phone' => $society['phone'],
          'address_primary.street_address' => $society['street'],
          'address_primary.supplemental_address_1' => $society['village'],
          'address_primary.city' => $society['city'],
          'address_primary.postal_code' => $society['postcode'],
          'address_primary.country_id' => $this->countryId,
          'Society_Options.Round_to_share' => $society['round_to_share'],
          'CoShares_society_info.Logo' => $this->getLogoUrl($key),
          'CoShares_society_info.CSS' => $this->getSocietyCss($society),
        ], $this->getSocietyTexts($society)))
        ->execute()
        ->first();
      $this->addToGroup($this->societies[$key]['id']);
    }
  }

  private function getLogoUrl(string $key): string {
    $file = "images/logos/$key.svg";
    $path = E::path($file);
    $config = \CRM_Core_Config::singleton();
    $relativePath = \CRM_Utils_File::relativize($path, \CRM_Utils_File::addTrailingSlash($config->extensionsDir));
    if ($relativePath === $path) {
      return E::url($file);
    }
    return \CRM_Utils_File::addTrailingSlash($config->extensionsURL, '/') . $relativePath;
  }

  private function getSocietyTexts(array $society): array {
    $address = implode(', ', array_filter([$society['street'], $society['village'], $society['city'] . ' ' . $society['postcode']]));
    $contact = "{$society['email']} &middot; {$society['phone']}";
    $registered = "Registered under the Co-operative and Community Benefit Societies Act 2014, no. {$society['registration']}";
    return [
      'Statement_text.Statement_header_text' => "<p>$address<br />$contact</p>",
      'Statement_text.Statement_footer_text' => "<p>Interest is paid gross, without deduction of income tax. If your savings interest is more than your Personal Savings Allowance you may need to tell HMRC. Please let us know if you move house or change bank.</p><p>{$society['name']} Limited. $registered. Registered office: $address.</p>",
      'Statement_text.Statement_intro_text' => "<p>Your annual statement is attached. Thank you for continuing to support {$society['cause']}.</p>",
      'Statement_text.Statement_outro_text' => "<p>With best wishes,<br />The board of {$society['name']}</p>",
      'Statement_text.Statement_waived_text' => "<p>Waived interest stays with the society and goes to {$society['fund']}. Thank you.</p>",
      'Certificate_text.Certificate_header_text' => "<p>$registered</p>",
      'Certificate_text.Certificate_footer_text' => "<p>Please keep this certificate safe. Your holding is subject to the rules of the society and the terms of the offer document. Registered office: $address.</p>",
      'Certificate_text.Certificate_email_intro_text' => "<p>Thank you for investing in {$society['cause']}.</p>",
      'Certificate_text.Certificate_email_outro_text' => "<p>If any of the details on your certificate are wrong, please contact us at {$society['email']}.</p>",
    ];
  }

  /**
   * Overrides the accent colour of the sample statement and certificate
   * templates and adds the signatories' names and signatures.
   */
  private function getSocietyCss(array $society): string {
    $colour = $society['colour'];
    $rules = [
      '.shares-statement .society-name, .shares-statement .doc-title .doc-name, .shares-statement h2, .shares-statement .summary .total, .shares-certificate .society, .shares-certificate .amount' => "color: $colour;",
      '.shares-statement .rule' => "border-top-color: $colour;",
      '.shares-statement .summary' => "border-left-color: $colour;",
      '.shares-statement .periods th' => "background: $colour;",
      '.shares-certificate' => "border-color: $colour;",
      '.shares-certificate .signature-secretary:before' => 'content: url(' . $this->getSignature($society['secretary']) . ');',
      '.shares-certificate .signature-chair:before' => 'content: url(' . $this->getSignature($society['chair']) . ');',
      '.shares-certificate .line-secretary:before' => "content: \"{$society['secretary']}, \";",
      '.shares-certificate .line-chair:before' => "content: \"{$society['chair']}, \";",
    ];
    $css = '';
    foreach ($rules as $selector => $declaration) {
      $css .= "$selector { $declaration }\n";
    }
    return $css;
  }

  /**
   * A handwritten looking scribble, different for every name: a tall looped
   * capital, a run of lower case humps with the odd ascender or descender,
   * and an underline flourish.
   */
  private function getSignature(string $name): string {
    $i = 0;
    $base = 38;
    $x = 8;
    $top = 4 + crc32($name . $i++) % 8;
    $path = sprintf('M%d %d C%d %d %d %d %d %d C%d %d %d %d %d %d', $x, $base, $x + 2, $top + 10, $x + 4, $top, $x + 9, $top + 2, $x + 14, $top + 6, $x + 6, $base, $x + 12, $base - 4);
    $x += 12;
    $humps = 5 + crc32($name . $i++) % 4;
    for ($hump = 0; $hump < $humps; $hump++) {
      $width = 6 + crc32($name . $i++) % 6;
      $height = 18 + crc32($name . $i++) % 12;
      if (crc32($name . $i++) % 5 === 0) {
        $height = 6 + crc32($name . $i++) % 8;
      }
      $end = $base;
      if (crc32($name . $i++) % 8 === 0) {
        $end = 50;
      }
      $path .= sprintf(' C%.1f %d %.1f %d %d %d', $x + $width * 0.2, $height, $x + $width * 0.8, $height, $x + $width, $end);
      $x += $width;
    }
    $path .= sprintf(' C%d %d %d %d %d %d', $x + 8, $base + 6, $x + 18, $base - 6, $x + 28, $base - 16);
    $path .= sprintf(' M%d 48 C60 %d 110 50 %d 42', 8 + crc32($name . $i++) % 12, 42 + crc32($name . $i++) % 6, min($x + 24, 165));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="170" height="55" viewBox="0 0 170 55">'
      . '<path d="' . $path . '" fill="none" stroke="#1f2f5c" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
  }

  private function createInvestors(): void {
    $issues = $this->getIssueDefinitions();
    $societies = $this->getSocietyDefinitions();
    $households = $this->getHouseholds();
    foreach ($this->getInvestorDefinitions() as $index => $investor) {
      [$key, $type, $firstName, $lastName, $issueKeys] = $investor;
      $region = 'perthshire';
      if (!empty($issueKeys)) {
        $region = $societies[$issues[$issueKeys[0]]['society']]['region'];
      }
      $address = $this->getAddress($key, $region);
      if (!empty($households[$key]) && !empty($this->addresses[$households[$key]])) {
        $address = $this->addresses[$households[$key]];
      }
      $this->addresses[$key] = $address;

      $values = [
        'contact_type' => $type,
        'address_primary.country_id' => $this->countryId,
      ];
      foreach ($address as $field => $value) {
        $values["address_primary.$field"] = $value;
      }
      if ($type === 'Individual') {
        $values['first_name'] = $firstName;
        $values['last_name'] = $lastName;
        $values['email_primary.email'] = $this->getEmail($firstName, $lastName);
        $values['phone_primary.phone'] = sprintf('07700 900%03d', $index);
        $values['phone_primary.phone_type_id:name'] = 'Mobile';
      }
      else {
        $values['organization_name'] = $firstName;
        $values['email_primary.email'] = "$key@example.org";
      }
      if ($key === 'imacleod') {
        $values['birth_date'] = $this->getChildBirthday()->modify('-18 years')->format('Y-m-d');
      }
      $this->contacts[$key] = Contact::create(FALSE)
        ->setValues($values)
        ->execute()
        ->first();
      $this->addToGroup($this->contacts[$key]['id']);

      if (!empty($investor[5])) {
        $this->createBankAccount($key);
      }
    }
  }

  private function getEmail(string $firstName, string $lastName): string {
    $name = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', "$firstName.$lastName");
    return preg_replace('/[^a-z.-]/', '', $name) . '@example.org';
  }

  /**
   * A believable address in the region. Postcodes use a real district with a
   * made up sector and unit.
   */
  private function getAddress(string $key, string $region): array {
    $region = $this->getRegions()[$region];
    $homeTowns = $this->getHomeTowns();
    if (!empty($homeTowns[$key])) {
      [$town, $district] = $homeTowns[$key];
    }
    else {
      [$town, $district] = $this->pick($key . 'town', $region['towns']);
    }
    $street = (crc32($key . 'number') % 60 + 1) . ' ' . $this->pick($key . 'street', $region['streets']);
    if (crc32($key . 'house') % 6 === 0) {
      $street = $this->pick($key . 'name', $region['houses']) . ', ' . $this->pick($key . 'street', $region['streets']);
    }
    $letters = 'ABDEFGHJLNPQRSTUWXYZ';
    $unit = $letters[crc32($key . 'unit1') % 20] . $letters[crc32($key . 'unit2') % 20];
    return [
      'street_address' => $street,
      'city' => $town,
      'postal_code' => $district . ' ' . (crc32($key . 'sector') % 9 + 1) . $unit,
    ];
  }

  private function addToGroup(int $contactId): void {
    GroupContact::create(FALSE)
      ->addValue('group_id', $this->groupId)
      ->addValue('contact_id', $contactId)
      ->addValue('status', 'Added')
      ->execute();
  }

  /**
   * Uses the site's public key if one is configured, otherwise creates a key
   * pair and returns the private key in the summary so the Decrypt screen works.
   */
  private function setupEncryption(): void {
    $publicJwk = \Civi::settings()->get('bank_account_public_key');
    if (!empty($publicJwk)) {
      if (is_array($publicJwk)) {
        $publicJwk = json_encode($publicJwk);
      }
      $this->publicKey = PublicKeyLoader::load($publicJwk);
    }
    else {
      $privateKey = RSA::createKey(4096);
      $this->privateJwk = json_encode(json_decode($privateKey->toString('JWK'), TRUE)['keys'][0]);
      $publicKeyData = json_decode($privateKey->getPublicKey()->toString('JWK'), TRUE)['keys'][0];
      \Civi::settings()->set('bank_account_public_key', $publicKeyData);
      $this->publicKey = $privateKey->getPublicKey();
    }
    $this->publicKey = $this->publicKey
      ->withPadding(RSA::ENCRYPTION_OAEP)
      ->withHash('sha256')
      ->withMGFHash('sha256');
  }

  /**
   * Mirrors js/encrypt-lib.js so the stored references look like ones entered on the form.
   */
  private function createBankAccount(string $key): void {
    $contact = Contact::get(FALSE)
      ->addSelect('display_name')
      ->addWhere('id', '=', $this->contacts[$key]['id'])
      ->execute()
      ->first();
    $accountName = $contact['display_name'];
    $bankName = $this->pick($key . 'bank', ['Co-operative Bank', 'Triodos Bank', 'Nationwide', 'Bank of Scotland', 'Royal Bank of Scotland', 'Lloyds Bank', 'Barclays', 'Monzo', 'Starling Bank', 'Clydesdale Bank', 'Ecology Building Society']);
    $accountNumber = sprintf('%08d', crc32($key . 'account') % 100000000);
    $sortCode = sprintf('%06d', crc32($key . 'sort') % 1000000);

    $encrypted = $this->publicKey->encrypt(json_encode([
      'accountName' => $accountName,
      'accountNumber' => $accountNumber,
      'sortCode' => $sortCode,
      'bankName' => $bankName,
    ]));
    $redacted = implode(' ', [$accountName, $bankName, substr($accountNumber, 0, 4) . '****', substr($sortCode, 0, 2) . '-**-**']);

    $bankAccount = BankAccount::create(FALSE)
      ->addValue('contact_id', $this->contacts[$key]['id'])
      ->execute()
      ->first();
    BankAccountReference::save(FALSE)
      ->setRecords([
        ['reference_type_id:name' => 'RED_UK', 'reference' => $redacted],
        ['reference_type_id:name' => 'ENC_UK', 'reference' => base64_encode($encrypted)],
      ])
      ->setDefaults(['ba_id' => $bankAccount['id']])
      ->execute();
    $this->bankAccounts[$key] = $bankAccount['id'];
  }

  private function scheduleIssues(): void {
    foreach ($this->getIssueDefinitions() as $key => $issue) {
      $periodStart = $this->yearStart->modify($issue['first_period']);
      $this->at($periodStart->modify('-2 months'), 'openIssue', [$key]);
      $this->at($periodStart, 'openFirstPeriod', [$key, $periodStart->format('Y-m-d')]);

      $lastIndex = count($issue['periods']) - 1;
      foreach ($issue['periods'] as $index => [$rate, $repaymentPercent]) {
        $closeDate = $periodStart->modify('+' . ($index + 1) . ' years +2 months');
        $openNext = $index < $lastIndex || $issue['status'] !== 'completed';
        $this->at($closeDate, 'closePeriod', [$key, $rate, $repaymentPercent, $openNext]);
        $this->at($closeDate->modify('+14 days'), 'runPayouts', [$issue['society']]);
        $this->at($closeDate->modify('+21 days'), 'sendStatements', [$issue['society']]);
      }

      if ($issue['status'] === 'completed') {
        $completeDate = $periodStart->modify('+' . ($lastIndex + 1) . ' years +4 months');
        $this->at($completeDate, 'completeIssue', [$key]);
        $this->at($completeDate->modify('+14 days'), 'runPayouts', [$issue['society']]);
        $this->at($completeDate->modify('+1 month'), 'setIssueStatus', [$key, 'completed']);
      }
    }
  }

  /**
   * Initial purchases fall in the two month offer window before the first
   * period. Some holders of established share issues join late or top up later.
   */
  private function scheduleInvestments(): void {
    $issues = $this->getIssueDefinitions();
    $amounts = [100, 250, 500, 500, 1000, 1000, 1500, 2000, 2500, 5000, 7500, 10000, 20000];
    foreach ($this->getInvestorDefinitions() as $investor) {
      [$key, , , , $issueKeys] = $investor;
      foreach ($issueKeys as $issueKey) {
        $issue = $issues[$issueKey];
        $choice = $investor[7] ?? $this->pick($key . $issueKey . 'choice', ['receive', 'receive', 'receive', 'receive', 'receive', 'receive', 'reinvest', 'reinvest', 'waive']);
        if ($issue['asset'] === 'bond' && $choice === 'reinvest') {
          $choice = 'receive';
        }
        $this->choices[$key][$issueKey] = $choice;

        $amount = $investor[6] ?? $this->pick($key . $issueKey . 'amount', $amounts);
        $amount = min($amount, $issue['max']);
        $periodStart = $this->yearStart->modify($issue['first_period']);
        $purchaseDate = $periodStart->modify('-2 months +' . (crc32($key . $issueKey) % 55) . ' days');
        $isEstablishedShareIssue = $issue['status'] === 'open' && $issue['asset'] === 'share' && count($issue['periods']) > 1;
        if ($isEstablishedShareIssue && crc32($key . $issueKey . 'late') % 5 === 0) {
          $purchaseDate = $periodStart->modify('+1 year +' . (crc32($key . $issueKey) % 200) . ' days');
        }
        $this->at($purchaseDate, 'purchase', [$key, $issueKey, $amount]);

        if ($isEstablishedShareIssue && crc32($key . $issueKey . 'topup') % 4 === 0) {
          $topUpDate = $periodStart->modify('+2 years +' . (crc32($key . $issueKey . 'topup') % 200) . ' days');
          $this->at($topUpDate, 'purchase', [$key, $issueKey, $this->pick($key . $issueKey . 'topupamount', [250, 500, 1000])]);
        }
      }
    }
  }

  private function scheduleScenarios(): void {
    $sh2Start = $this->yearStart->modify('-9 years');

    $this->choices['fmacleod']['SH2'] = 'reinvest';
    $this->at($sh2Start->modify('-50 days'), 'purchase', ['fmacleod', 'SH2', 2000, 'imacleod']);
    $this->at($this->getChildBirthday(), 'transfer', ['fmacleod', 'imacleod', 'SH2', 'transfer_child', 'Shares bought on behalf of Isla transferred to her on her 18th birthday']);

    $this->at($this->yearStart->modify('-3 years +5 months'), 'markDeceased', ['afraser']);
    $this->at($this->yearStart->modify('-3 years +8 months'), 'transfer', ['afraser', 'mfraser', 'KW1', 'transfer_deceased', 'Transferred to Morag Fraser as executor and beneficiary of the estate of Archibald Fraser']);

    $this->at($this->yearStart->modify('-2 years +7 months'), 'transfer', ['nashworth', 'cashworth', 'AV1', 'transfer_other', 'Gift to his daughter, approved by the board', 500]);

    $this->at($this->yearStart->modify('-1 year +3 months'), 'outOfSequenceRepayment', ['dkerr', 'SH2', 1000, 'Early withdrawal approved by the board (hardship request)']);

    $this->at($this->yearStart->modify('-1 year +8 months'), 'donateCapital', ['pwhitcombe', 'AV1', 500, 'Donated to the Ashcombe Vale community benefit fund']);

    $this->at($this->baseDate->modify('-12 days'), 'purchase', ['jwallace', 'KW2', 250]);
    $this->at($this->baseDate->modify('-9 days'), 'purchase', ['hlangley', 'AV3', 500]);
    $this->at($this->baseDate->modify('-6 days'), 'purchase', ['bthornton', 'AV1', 500]);
  }

  private function getChildBirthday(): \DateTimeImmutable {
    return $this->yearStart->modify('-1 year +4 months +10 days');
  }

  /**
   * Schedule an event method to run on a date. Events after the base date are
   * dropped and duplicates (e.g. two issues paying out on the same day) are merged.
   */
  private function at(\DateTimeImmutable $date, string $method, array $args = []): void {
    if ($date > $this->baseDate) {
      return;
    }
    $date = $date->setTime(10, 0);
    $key = $date->format('Y-m-d') . $method . json_encode($args);
    if (isset($this->events[$key])) {
      return;
    }
    $this->events[$key] = [$date, count($this->events), $method, $args];
    if ($method === 'runPayouts') {
      $this->lastPayout[$args[0]] = max($this->lastPayout[$args[0]] ?? $date, $date);
    }
  }

  /**
   * Certificates and statements go through the normal send code so each gets
   * an activity with the PDF attached, but no email leaves the site.
   */
  private function runEvents(): void {
    $events = array_values($this->events);
    usort($events, [self::class, 'compareEvents']);
    \Civi::dispatcher()->addListener('hook_civicrm_alterMailParams', [self::class, 'abortMailSend']);
    try {
      foreach ($events as [$date, , $method, $args]) {
        $this->$method($date, ...$args);
      }
    }
    finally {
      \Civi::dispatcher()->removeListener('hook_civicrm_alterMailParams', [self::class, 'abortMailSend']);
    }
  }

  private static function compareEvents(array $a, array $b): int {
    return [$a[0], $a[1]] <=> [$b[0], $b[1]];
  }

  private function openIssue(\DateTimeImmutable $date, string $key): void {
    $definition = $this->getIssueDefinitions()[$key];
    $issue = Issue::create($this->societies[$definition['society']], $definition['name'], $definition['asset'], 1);
    $values = [
      'min_purchase' => $definition['min'],
      'max_purchase' => $definition['max'],
      'CoShares_issue_info.Content' => $definition['content'],
      'CoShares_issue_info.Payment_reference' => $key . '-' . $date->format('Y'),
    ];
    if (!empty($definition['reinvest'])) {
      $values['reinvestment_issue_id'] = $issue['id'];
    }
    ShareIssue::update(FALSE)
      ->addWhere('id', '=', $issue['id'])
      ->setValues($values)
      ->execute();
    $this->issues[$key] = $values + $issue;
    $this->raised[$key] = 0;

    if (!empty($definition['replaces'])) {
      ShareIssue::update(FALSE)
        ->addWhere('id', '=', $this->issues[$definition['replaces']]['id'])
        ->addValue('status_id:name', 'closed')
        ->addValue('reinvestment_issue_id', $issue['id'])
        ->execute();
    }
  }

  private function openFirstPeriod(\DateTimeImmutable $date, string $issueKey, string $startDate): void {
    AccountingPeriod::save($this->issues[$issueKey], ['start_date' => $startDate]);
  }

  private function closePeriod(\DateTimeImmutable $date, string $issueKey, float $rate, float $repaymentPercent, bool $openNext): void {
    $issue = $this->issues[$issueKey];
    $period = AccountingPeriod::getPreviousAccountingPeriod($issue);
    ShareAccountingPeriod::update(FALSE)
      ->addWhere('id', '=', $period['id'])
      ->addValue('interest_rate', $rate)
      ->addValue('repayment_amount', round($this->raised[$issueKey] * $repaymentPercent / 100, -2))
      ->execute();
    AccountingPeriod::close($period, Date::toString($date));
    if ($openNext) {
      AccountingPeriod::save($issue, []);
    }
  }

  private function setIssueStatus(\DateTimeImmutable $date, string $issueKey, string $status): void {
    ShareIssue::update(FALSE)
      ->addWhere('id', '=', $this->issues[$issueKey]['id'])
      ->addValue('status_id:name', $status)
      ->execute();
  }

  private function purchase(\DateTimeImmutable $date, string $contactKey, string $issueKey, float $amount, ?string $onBehalfOfKey = NULL): void {
    $issue = $this->issues[$issueKey];
    $contact = $this->contacts[$contactKey];
    $contribution = Contribution::create(FALSE)
      ->setValues([
        'contact_id' => $contact['id'],
        'financial_type_id:name' => 'Donation',
        'payment_instrument_id:name' => 'EFT',
        'contribution_status_id:name' => 'Completed',
        'total_amount' => $amount,
        'currency' => 'GBP',
        'receive_date' => Date::toString($date),
        'source' => $issue['name'],
        'Share_purchase.Issue' => $issue['id'],
        'Share_purchase.Interest_choice:name' => $this->choices[$contactKey][$issueKey],
        'Share_purchase.Terms_and_conditions' => TRUE,
      ])
      ->execute()
      ->first();

    if (empty($onBehalfOfKey)) {
      Purchase::process($issue, [$contribution['id']], $date);
    }
    else {
      $holding = $this->getHolding($contactKey, $issueKey);
      $transaction = Transaction::purchase($holding, $amount, $date, [
        'purchase_id' => $contribution['id'],
        'on_behalf_of_id' => $this->contacts[$onBehalfOfKey]['id'],
        'notes' => 'Bought on behalf of a child',
      ]);
      Certificate::enqueue($transaction['id']);
    }
    $this->raised[$issueKey] += $amount;
    $this->updateHolding($contactKey, $issueKey);
  }

  private function getHolding(string $contactKey, string $issueKey): array {
    $holding = Holding::get($this->issues[$issueKey], $this->contacts[$contactKey]);
    if (empty($holding)) {
      $holding = Holding::create($this->issues[$issueKey], $this->contacts[$contactKey]);
    }
    return $holding;
  }

  private function updateHolding(string $contactKey, string $issueKey): array {
    $holding = $this->getHolding($contactKey, $issueKey);
    $values = [
      'interest_choice_id:name' => $this->choices[$contactKey][$issueKey] ?? 'receive',
    ];
    if (!empty($this->bankAccounts[$contactKey])) {
      $values['bank_account_id'] = $this->bankAccounts[$contactKey];
    }
    ShareHolding::update(FALSE)
      ->addWhere('id', '=', $holding['id'])
      ->setValues($values)
      ->execute();
    return $holding;
  }

  private function getBalance(array $holding, string $accountType): float {
    $account = ShareAccount::get(FALSE)
      ->addSelect('balance')
      ->addWhere('holding_id', '=', $holding['id'])
      ->addWhere('account_type_id:name', '=', $accountType)
      ->execute()
      ->first();
    return $account['balance'] ?? 0;
  }

  /**
   * Transfers the whole holding and payable balance unless an amount is given.
   */
  private function transfer(\DateTimeImmutable $date, string $fromKey, string $toKey, string $issueKey, string $type, string $notes, ?float $amount = NULL): void {
    $from = $this->getHolding($fromKey, $issueKey);
    $to = $this->updateHolding($toKey, $issueKey);
    $payableAmount = 0;
    if (empty($amount)) {
      $amount = $this->getBalance($from, 'holding');
      $payableAmount = $this->getBalance($from, 'payable');
    }
    Transaction::transfer($from, $to, $amount, $payableAmount, $date, ['type' => $type, 'notes' => $notes]);
  }

  private function markDeceased(\DateTimeImmutable $date, string $contactKey): void {
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->contacts[$contactKey]['id'])
      ->addValue('is_deceased', TRUE)
      ->addValue('deceased_date', $date->format('Y-m-d'))
      ->execute();
  }

  private function outOfSequenceRepayment(\DateTimeImmutable $date, string $contactKey, string $issueKey, float $amount, string $notes): void {
    Transaction::outOfSequenceRepayment($this->getHolding($contactKey, $issueKey), $amount, $date, ['notes' => $notes]);
  }

  private function donateCapital(\DateTimeImmutable $date, string $contactKey, string $issueKey, float $amount, string $notes): void {
    Transaction::donateCapital($this->getHolding($contactKey, $issueKey), $amount, $date, ['notes' => $notes]);
  }

  /**
   * Repays all remaining capital. Holders without a bank account are paid by cheque.
   */
  private function completeIssue(\DateTimeImmutable $date, string $issueKey): void {
    foreach (Issue::getHoldings($this->issues[$issueKey]) as $holding) {
      $balance = $this->getBalance($holding, 'holding');
      if ($balance > 0) {
        Transaction::outOfSequenceRepayment($holding, $balance, $date, ['notes' => 'Final capital repayment on completion of the issue']);
      }
      $payable = $this->getBalance($holding, 'payable');
      if (empty($holding['bank_account_id']) && $payable > 0) {
        Transaction::payment($holding, $payable, $date->modify('+7 days'), ['notes' => 'Paid by cheque']);
      }
    }
  }

  /**
   * Creates a payment batch for everything payable. Older batches are paid and
   * closed. The latest Ashcombe Vale batch has one failed payment and the
   * latest Kilbrannan batch is left pending.
   */
  private function runPayouts(\DateTimeImmutable $date, string $societyKey): void {
    $this->assignBankAccounts();
    $societyId = $this->societies[$societyKey]['id'];
    OutboundPaymentBatch::save($societyId, 'Payment run ' . $date->format('j F Y'));
    $batch = ShareOutboundPaymentBatch::get(FALSE)
      ->addWhere('society_id', '=', $societyId)
      ->addOrderBy('id', 'DESC')
      ->setLimit(1)
      ->execute()
      ->first();
    $paymentIds = ShareOutboundPayment::get(FALSE)
      ->addWhere('outbound_payment_batch_id', '=', $batch['id'])
      ->addOrderBy('id')
      ->execute()
      ->column('id');

    if (empty($paymentIds)) {
      ShareOutboundPaymentBatch::delete(FALSE)
        ->addWhere('id', '=', $batch['id'])
        ->execute();
      return;
    }

    ShareOutboundPaymentBatch::update(FALSE)
      ->addWhere('id', '=', $batch['id'])
      ->addValue('datetime', Date::toString($date))
      ->execute();

    $isLatest = $date == $this->lastPayout[$societyKey];
    if ($isLatest && $societyKey === 'kilbrannan') {
      return;
    }
    if ($isLatest && $societyKey === 'ashcombe') {
      $failedIds = ShareOutboundPayment::get(FALSE)
        ->addWhere('outbound_payment_batch_id', '=', $batch['id'])
        ->addWhere('contact_id', '=', $this->contacts['hpemberton']['id'])
        ->execute()
        ->column('id');
      ShareOutboundPayment::fail(FALSE)
        ->setWhere([['id', 'IN', $failedIds]])
        ->execute();
      $paymentIds = array_diff($paymentIds, $failedIds);
    }

    $paidDate = min($date->modify('+3 days'), $this->baseDate);
    ShareOutboundPayment::complete(FALSE)
      ->setWhere([['id', 'IN', $paymentIds]])
      ->setValues(['transaction_date' => Date::toString($paidDate)])
      ->execute();
    ShareOutboundPaymentBatch::close(FALSE)
      ->setWhere([['id', 'IN', [$batch['id']]]])
      ->execute();
  }

  /**
   * Holdings created by reinvestment into another issue start without a bank account.
   */
  private function assignBankAccounts(): void {
    foreach ($this->bankAccounts as $key => $bankAccountId) {
      ShareHolding::update(FALSE)
        ->addWhere('contact_id', '=', $this->contacts[$key]['id'])
        ->addWhere('bank_account_id', 'IS NULL')
        ->addValue('bank_account_id', $bankAccountId)
        ->execute();
    }
  }

  /**
   * Certificates are sent monthly. Purchases in the month before the base
   * date are left pending.
   */
  private function scheduleMailings(): void {
    $date = $this->yearStart->modify('-12 years')->modify('first day of this month');
    $lastRun = $this->baseDate->modify('-1 month');
    while ($date <= $lastRun) {
      $this->at($date, 'sendCertificates');
      $date = $date->modify('+1 month');
    }
  }

  public static function abortMailSend(GenericHookEvent $event): void {
    $event->params['abortMailSend'] = TRUE;
  }

  private function sendCertificates(\DateTimeImmutable $date): void {
    $certificates = ShareCertificateQueue::get(FALSE)
      ->addSelect('id', 'purchase_id')
      ->addWhere('status_id:name', '=', 'pending')
      ->addJoin('Contribution AS contribution', 'INNER', ['purchase_id.purchase_id', '=', 'contribution.id'])
      ->addWhere('contribution.contact_id', 'IN', array_column($this->contacts, 'id'))
      ->addWhere('purchase_id.datetime', '<', Date::toString($date))
      ->addOrderBy('purchase_id.datetime')
      ->execute();
    foreach ($certificates as $certificate) {
      Certificate::sendEmail($certificate['purchase_id']);
      $this->markSent(ShareCertificateQueue::update(FALSE), [$certificate['id']], $date);
    }
  }

  /**
   * One statement per holder covering all of the society's issues. Statements
   * for the latest periods are left pending.
   */
  private function sendStatements(\DateTimeImmutable $date, string $societyKey): void {
    $queue = ShareStatementQueue::get(FALSE)
      ->addSelect('id', 'contact_id', 'accounting_period_id')
      ->addWhere('status_id:name', '=', 'pending')
      ->addWhere('accounting_period_id.issue_id.society_id', '=', $this->societies[$societyKey]['id'])
      ->addWhere('accounting_period_id.end_date', '<', $this->yearStart->modify('-1 day')->format('Y-m-d'))
      ->execute();
    $statements = [];
    foreach ($queue as $item) {
      $statements[$item['contact_id']]['period_ids'][] = $item['accounting_period_id'];
      $statements[$item['contact_id']]['ids'][] = $item['id'];
    }
    foreach ($statements as $contactId => $statement) {
      Statement::sendEmail($contactId, $statement['period_ids'], $date->format('Y-m-d'));
      $this->markSent(ShareStatementQueue::update(FALSE), $statement['ids'], $date);
    }
  }

  private function markSent(AbstractUpdateAction $update, array $ids, \DateTimeImmutable $sent): void {
    $update
      ->addWhere('id', 'IN', $ids)
      ->addValue('status_id:name', 'sent')
      ->addValue('sent', Date::toString($sent))
      ->execute();
  }

  /**
   * Runs the missing bank account job, marks some notifications read, then adds bank details for two
   * holders, which dismisses their notifications. The job covers every
   * holding, so it is skipped when it would also notify other societies' holders.
   */
  private function createNotifications(): void {
    $this->assignBankAccounts();
    if (!$this->hasOtherHoldersWithoutBankAccount()) {
      ShareHolding::notifyMissingBankAccount(FALSE)->execute();
      Notification::markRead(FALSE)
        ->addWhere('contact_id', 'IN', [$this->contacts['amurray']['id'], $this->contacts['rbartlett']['id']])
        ->execute();
    }

    $this->createBankAccount('ghargreaves');
    $this->createBankAccount('sfoxley');
    $this->assignBankAccounts();
  }

  private function hasOtherHoldersWithoutBankAccount(): bool {
    $holdings = ShareHolding::get(FALSE)
      ->addWhere('issue_id.society_id', 'NOT IN', $this->getSocietyIds())
      ->addWhere('contact_id', '!=', 'issue_id.society_id', TRUE)
      ->addWhere('bank_account_id', 'IS EMPTY')
      ->execute()
      ->count();
    return $holdings > 0;
  }

  private function getSocietyIds(): array {
    return array_column($this->societies, 'id');
  }

  /**
   * Recent contributions that have not been turned into purchases yet,
   * including one that is still pending payment.
   */
  private function createUnprocessedContributions(): void {
    $contributions = [
      ['rfairbanks', 'AV2', 1000, 3, 'Completed'],
      ['bsutherland', 'KW2', 500, 5, 'Completed'],
      ['hpemberton', 'AV1', 750, 8, 'Completed'],
      ['shiggins', 'PI1', 250, 2, 'Pending'],
    ];
    foreach ($contributions as [$contactKey, $issueKey, $amount, $daysAgo, $status]) {
      Contribution::create(FALSE)
        ->setValues([
          'contact_id' => $this->contacts[$contactKey]['id'],
          'financial_type_id:name' => 'Donation',
          'payment_instrument_id:name' => 'EFT',
          'contribution_status_id:name' => $status,
          'total_amount' => $amount,
          'currency' => 'GBP',
          'receive_date' => Date::toString($this->baseDate->modify("-$daysAgo days")),
          'source' => $this->issues[$issueKey]['name'],
          'Share_purchase.Issue' => $this->issues[$issueKey]['id'],
          'Share_purchase.Interest_choice:name' => 'receive',
          'Share_purchase.Terms_and_conditions' => TRUE,
        ])
        ->execute();
    }
  }

  private function getSummary(): array {
    $societyIds = $this->getSocietyIds();
    $contactIds = array_merge($societyIds, array_column($this->contacts, 'id'));
    $holdingIds = ShareHolding::get(FALSE)
      ->addSelect('id')
      ->addWhere('issue_id.society_id', 'IN', $societyIds)
      ->execute()
      ->column('id');
    $transactions = ShareEntry::get(FALSE)
      ->addSelect('COUNT(DISTINCT transaction_id) AS count')
      ->addClause('OR', ['from_account_id.holding_id', 'IN', $holdingIds], ['to_account_id.holding_id', 'IN', $holdingIds])
      ->execute()
      ->first();
    $summary = [
      'base_date' => $this->baseDate->format('Y-m-d'),
      'group_id' => $this->groupId,
      'societies' => count($this->societies),
      'issues' => count($this->issues),
      'contacts' => count($this->contacts),
      'ShareHolding' => count($holdingIds),
      'ShareTransaction' => $transactions['count'],
      'ShareAccountingPeriod' => $this->count('ShareAccountingPeriod', [['issue_id.society_id', 'IN', $societyIds]]),
      'ShareOutboundPaymentBatch' => $this->count('ShareOutboundPaymentBatch', [['society_id', 'IN', $societyIds]]),
      'ShareOutboundPayment' => $this->count('ShareOutboundPayment', [['outbound_payment_batch_id.society_id', 'IN', $societyIds]]),
      'BankAccount' => $this->count('BankAccount', [['contact_id', 'IN', $contactIds]]),
    ];
    $summary['Notification'] = $this->count('Notification', [['contact_id', 'IN', $contactIds]]);
    $activities = Activity::get(FALSE)
      ->addSelect('activity_type_id:name', 'COUNT(id) AS count')
      ->addWhere('activity_type_id:name', 'IN', ['Share Certificate', 'Share Statement'])
      ->addWhere('source_contact_id', 'IN', $contactIds)
      ->addGroupBy('activity_type_id')
      ->execute()
      ->column('count', 'activity_type_id:name');
    foreach ($activities as $type => $count) {
      $summary[$type . ' activities'] = $count;
    }
    if (!empty($this->privateJwk)) {
      $summary['private_key'] = $this->privateJwk;
    }
    return $summary;
  }

  private function count(string $entity, array $where): int {
    return civicrm_api4($entity, 'get', ['checkPermissions' => FALSE, 'select' => ['row_count'], 'where' => $where])->countMatched();
  }

  /**
   * Deterministically picks an option based on a key.
   */
  private function pick(string $key, array $options) {
    return $options[crc32($key) % count($options)];
  }

}
