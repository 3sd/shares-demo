# CiviShares demo

Generates a realistic set of sample data for [CiviShares](https://civicrm.org/extensions/civishares) ([source](https://lab.civicrm.org/extensions/shares)) for demo sites, manual testing and development. It builds about ten years of history for four UK societies by running the same code that the CiviShares screens use, so every holding, account balance, statement and payment is consistent.

> **Warning:** only install this extension on demo, test or development sites. It creates contacts, contributions, bank accounts and share transactions that cannot be edited or deleted on the CiviShares screens (share accounts, transactions and entries are append only). `SharesDemo.reset` removes them again.

## Generating the data

Enable the extension. It needs CiviShares (`shares`), which in turn needs `bank_account` and `notification`. Run as an administrator:

```bash
cv api4 --user=admin SharesDemo.generate
```

History is built back from today. To build it back from a fixed date, e.g. so that screenshots or tests always match, pass `baseDate`:

```bash
cv api4 --user=admin SharesDemo.generate baseDate=2026-11-04
```

The same base date always produces the same data. Generating takes a few minutes. It refuses to run again until the existing sample data has been removed with `SharesDemo.reset`.

### Existing share data

The site can already have its own societies and share issues. Generating only sends certificates and statements, runs payment batches and counts records for the sample societies and investors, so other share data is left as it is. The missing bank account notification job covers every holding on the site, so it is skipped if another society has a holder without bank details.

The changes described under Site settings and templates do apply to the whole site.

### Bank account keys

Bank account details are encrypted with the public key in the `bank_account_public_key` setting. If no public key is set, a new key pair is created, the public key is saved, and the private key is returned as `private_key` in the result. Paste the private key into the **Decrypt bank details** screen to decrypt payment batch exports. If a public key is already set it is used, and you will need its private key to decrypt.

### Site settings and templates

The data is UK based, so the generator also:

- sets the default currency to GBP and the default country to the United Kingdom
- replaces the editable copies of the **Shares - Statement** and **Shares - Certificate** message templates with designed versions from `messageTemplates/`, printed on A4 (portrait statements, landscape certificates)
- fills in each society's statement and certificate texts (letterhead, registration number, footer, email intro and outro)
- gives each society its own look on statements and certificates using the existing **Logo** and **CSS** fields (CoShares society info): a logo from `images/logos/`, an accent colour, and the names and signatures of its secretary and chair

The sample templates show the society's logo when one is set, and add the society's CSS after their own styles, so CSS rules for `.shares-statement` and `.shares-certificate` override the defaults. Signatures go in the empty `.signature-secretary` and `.signature-chair` areas, e.g. `.shares-certificate .signature-secretary:before { content: url(...); }`, and printed names before the `.line-secretary` and `.line-chair` labels. The logo is loaded by URL when the PDF is made, so it must be reachable from the web server.

Organisations are expected to personalise these templates, so they are a starting point to copy rather than the shipped defaults. `SharesDemo.reset` restores the default templates.

### Emails

All contacts get `@example.org` email addresses and mobile numbers in the `07700 900xxx` range Ofcom reserves for drama. Certificates and statements are queued as they would be on a live site, so check the site's outbound email settings before running the `Job.email_share_certificates` and `Job.email_share_statements` API actions.

## What is created

All contacts, including the societies, are added to the **Shares sample data** group.

### Societies and issues

| Society                                                          | Issue                              | Type  | Started | Pattern                                          | Status                                      |
| ---------------------------------------------------------------- | ---------------------------------- | ----- | ------- | ------------------------------------------------ | ------------------------------------------- |
| Strathcorrie Community Hydro Society (Aberfeldy)                 | Strathcorrie Hydro Bond            | Bond  | 2016    | Flat: 4.5% and 10% of capital repaid every year  | Completed, all capital repaid               |
| Strathcorrie Community Hydro Society                             | Strathcorrie Hydro Share Offer     | Share | 2017    | Varying, cut to 1% in 2020, repayments from 2022 | Open, reinvests into itself                 |
| Kilbrannan Wind Co-operative (Campbeltown)                       | Kilbrannan Wind Share Offer        | Share | 2015    | Varying, no interest in 2020                     | Closed, reinvests into the repowering offer |
| Kilbrannan Wind Co-operative                                     | Kilbrannan Repowering Share Offer  | Share | 2024    | Interest only                                    | Open, reinvests into itself                 |
| Kilbrannan Wind Co-operative                                     | Kilbrannan Community Bond          | Bond  | 2020    | Flat: 5% and 12.5% of capital repaid every year  | Open                                        |
| Ashcombe Vale Solar Co-operative (Frome)                         | Ashcombe Vale Solar Share Offer    | Share | 2018    | Varying, cut to 0.5% in 2020, rising repayments  | Open, reinvests into itself                 |
| Ashcombe Vale Solar Co-operative                                 | Ashcombe Vale Battery Bond         | Bond  | 2025    | One period at 5%                                 | Open                                        |
| Ashcombe Vale Solar Co-operative                                 | Ashcombe Vale Rooftops Share Offer | Share | 2026    | Still in its first period                        | Open, reinvests into itself                 |
| Plough Inn Community Benefit Society (Little Haddon, Shropshire) | Plough Inn Community Share Offer   | Share | 2019    | No interest for three years, then rising         | Open                                        |

The Strathcorrie and Plough Inn societies round capital repayments to whole shares, the other two do not.

Accounting periods run from 1 April to 31 March. Capital repayments are a percentage of the capital raised, rounded to £100. Every issue that is not completed has an open current period. Periods with no interest and no repayment have no transactions, as on a live site.

### Investors

About 75 individuals and 8 local organisations (community councils, a parish council, a church, a curling club, a cricket club, a WI and a morris side) hold shares or bonds. The individuals have a mix of backgrounds broadly in line with the UK population, and include names with accents, apostrophes and hyphens (Siobhán O'Neill, Tomasz Wiśniewski, Zoë Ashton-Clarke). Investors live near the society they first invested in or in a nearby city, with believable addresses and postcodes, and family members share an address. Most receive their interest, some reinvest it and some waive it. Most purchases happen in the two months before an issue's first period, with some later investors and top ups.

### Scenarios

| Scenario                                                                             | Where to look                                                                                                                                                                                                                                                                                                                                        |
| ------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Shares bought on behalf of a child and transferred on their 18th birthday            | Fiona MacLeod and Isla MacLeod, Strathcorrie Hydro Share Offer                                                                                                                                                                                                                                                                                       |
| Transfer to a beneficiary after a death                                              | Archibald Fraser (deceased) and Morag Fraser, Kilbrannan Wind Share Offer                                                                                                                                                                                                                                                                            |
| Ad hoc transfer between investors                                                    | Nigel Ashworth to Clara Ashworth, Ashcombe Vale Solar Share Offer                                                                                                                                                                                                                                                                                    |
| Out of sequence capital repayment                                                    | Douglas Kerr, Strathcorrie Hydro Share Offer                                                                                                                                                                                                                                                                                                         |
| Capital donation                                                                     | Penelope Whitcombe, Ashcombe Vale Solar Share Offer                                                                                                                                                                                                                                                                                                  |
| Reinvestment into another issue                                                      | Kilbrannan Wind Share Offer holders who reinvest                                                                                                                                                                                                                                                                                                     |
| Issue completed with a final repayment, including payment by cheque                  | Strathcorrie Hydro Bond, Rory McIntyre                                                                                                                                                                                                                                                                                                               |
| Closed payment batches                                                               | Every year for every society                                                                                                                                                                                                                                                                                                                         |
| Failed outbound payment                                                              | Harriet Pemberton, latest Ashcombe Vale Solar Co-operative payment batch                                                                                                                                                                                                                                                                             |
| Pending payment batch                                                                | Latest Kilbrannan Wind Co-operative payment batch                                                                                                                                                                                                                                                                                                    |
| Holders without bank details                                                         | See the **No bank account** search                                                                                                                                                                                                                                                                                                                   |
| Missing bank account notifications                                                   | Active, read (Ailsa Murray, Reg Bartlett) and dismissed after bank details were added (Giles Hargreaves, Sophie Foxley)                                                                                                                                                                                                                              |
| Share certificates and statements                                                    | Certificates are sent monthly and statements three weeks after each year's periods close. Each one sent has a completed **Share Certificate** or **Share Statement** activity, dated when it was sent, with the PDF attached. No email is sent while generating. Certificates from the last month and statements for the latest periods are pending. |
| Contributions not yet turned into purchases                                          | Three completed and one pending contribution from the last few days                                                                                                                                                                                                                                                                                  |

## Resetting

To delete the sample data and generate it again:

```bash
cv api4 --user=admin SharesDemo.reset
cv api4 --user=admin SharesDemo.generate
```

Reset deletes the contacts in the **Shares sample data** group (societies and investors) and everything attached to them: share issues, holdings, accounts, transactions, accounting periods, statement and certificate queues, payment batches, bank accounts, contributions, notifications, the society and issue register groups, and the statement and certificate activities with their PDFs. It also deletes the group itself and reverts the statement and certificate templates to the CiviShares defaults. It takes about two minutes.

It leaves the GBP currency, the United Kingdom default country and the `bank_account_public_key` setting in place, so a new run reuses the same key and does not return a private key. Holdings and transactions added by hand to the sample issues are deleted too, including those of other contacts. Share issues of other societies are left alone.

## Tests

```bash
phpunit9
```

The test generates the data once for the whole class (a few minutes), checks that reset removes it, and rebuilds the test database afterwards.

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).
