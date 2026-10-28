# e-računi

Issues invoices in [e-računi](https://e-racuni.com) through its JSON API.

For every sale it invoices, the module:

- stores an e-računi document entity that links the sale to its invoice;
- issues the invoice from a queue, and retries it when it fails;
- keeps the issued invoice's PDF so the buyer can download it;
- raises an alarm when an invoice is late.

## Modules

- **eracuni**: the API client, the document entity, the invoice builder, the
  queues and the PDF download. It does not depend on Commerce.
- **eracuni_commerce**: invoices Drupal Commerce orders.

The base module gets each invoice's contents from a **source**: a service that
says what was sold, to whom and for how much. `eracuni_commerce` provides the
source for Commerce orders. To invoice anything else, implement
`Drupal\eracuni\Source\InvoiceSourceInterface` and tag the service
`eracuni.source`.

## Credentials

Credentials go in `settings.php`. They are never stored in configuration,
because configuration is exported.

```php
$settings['eracuni'] = [
  'username' => '…',
  'secret_key' => '…',
  'token' => '…',
  // Optional. Defaults to the Croatian instance.
  'endpoint' => 'https://e-racuni.com/WebServicesHR/API',
  // Optional. Logs every API request and response; see below.
  'log_traffic' => TRUE,
];
```

`drush eracuni:status` checks the credentials by running a lookup that is
expected to find nothing.

### Logging requests and responses

With `log_traffic` set, each API call writes two debug messages to the
`eracuni` log channel: the method with its parameters, then the HTTP status
with e-računi's response. Each message is cut at 16 KB.

Credentials are never logged. The invoice is, including the buyer's name,
address and tax ID, so enable it on a test account or only while you debug a
response.

## Settings

At `/admin/config/services/eracuni` (`eracuni.settings`):

- **Business unit**: the e-računi business unit invoices are issued in. For
  invoices paid by card it must be a fiscalised unit: e-računi fiscalises a
  Retail invoice paid by card or Stripe in such a unit when it is created.
  The module holds no fiscal certificate itself.
- **Order reference prefix**: added in front of every order reference, so two
  sites issuing into one e-računi account never use the same reference.
- **Create invoices as**: Issued, or Draft for a test account. A draft has no
  number and no PDF.
- **VAT transaction types**: e-računi's code for each VAT treatment, from your
  accountant. EU businesses outside the seller's country and every buyer
  outside the EU need one. An invoice whose treatment has no code mapped is
  refused, not guessed.
- **Default KPD code**: used for a line that has no KPD code of its own.
- **Print the line's own description on a catalogue product**: off by default,
  so a line linked to an e-računi catalogue product prints the product's name.
- **Hours before a missing invoice is an error**: 24 by default. Croatian law
  allows two days to fiscalise an invoice that was not fiscalised when it was
  issued.
- **Attempts before only the alarm is left**: how many times cron retries a
  failed invoice (`max_attempts`).

## How an invoice is issued

1. The sale is recorded as a **pending document** (`Issuer::owe()`) and
   queued. e-računi is never called during checkout or inside a payment
   webhook.
2. The `eracuni_issue` queue worker asks the source for the invoice's current
   contents, builds the request (`PayloadBuilder`), dispatches
   `EracuniEvents::PAYLOAD` so a site can change it, and calls
   `SalesInvoiceCreate`.
3. On success, e-računi's document ID and invoice number are stored on the
   document and the PDF is queued (`eracuni_pdf`). On a refusal or a network
   failure, the reason is stored on the document and cron tries again 15
   minutes later, up to the configured number of attempts.
4. If the invoice is still not issued after the configured hours, an error is
   logged and `EracuniEvents::OVERDUE` is dispatched once, so a site can send
   the alert where people will see it.

### The transaction ID

Every create request carries an `apiTransactionId`. When e-računi receives an
ID it has seen before, it returns **the response it gave the first time**,
whatever the request contains. Verified on e-računi's demo account:

- A request whose response was lost is sent again with the same ID. e-računi
  returns the invoice the first request created, if it created one, so no
  duplicate invoice is made.
- A refusal is returned again too, so a corrected request under the same ID
  would never be read. After a refusal the document gets a new ID (`…-r2`,
  `…-r3`). A refused request created nothing, so this is safe.
- If an invoice is deleted in e-računi, the same ID returns the deleted
  document's ID and nothing new is created. To issue it again, use a new ID.

### Invoice types

| Buyer | e-računi invoice type | Prices sent |
|---|---|---|
| A consumer anywhere; an EU business without a valid VAT ID | `Retail`, buyer details on the invoice | `price` (gross) and `vatPercentage` |
| A domestic business with a valid OIB; an EU business with a VAT ID; a business outside the EU | `Gross`, with `buyerTaxNumber` | `netPrice` and `vatPercentage` |

e-računi refuses a `Gross` invoice if any line has no KPD code
(`#classificationCodeField`). The code is sent in `classificationCode` in the
format e-računi stores, with the section letter first (`L64.99.09`); a code
without the letter is refused. Retail invoices are accepted without KPD codes.

### Totals

Before sending, the module checks that the lines add up to the amount charged,
allowing for rounding. If they do not, the invoice is refused before it is
sent.

On a `Gross` invoice, e-računi calculates VAT once per rate, on that rate's
net total. Shops usually round VAT on each line, which can differ by a few
cents: six lines of 5.50 at 25% come to 8.28 VAT line by line, but 8.25 on
the total. To make the invoice match the amount charged, the module adds the
difference to the net unit price of the largest line at the main VAT rate.
e-računi accepts net prices to four decimals. The change is recorded in the
document's warnings. A difference larger than one cent per line is never
absorbed this way.

### Buyer address

e-računi requires the buyer's street, postal code and city on every invoice.
A Retail invoice sends `-` for any of these the source does not have: a
consumer's address is not required, and payment providers often collect only
the country. A business invoice without a city is refused before it is sent.

### Catalogue products

A line can be linked to a product in the e-računi catalogue
(`Line::$productCode`, sent as `productCode`). Verified on e-računi's demo
account:

- The invoice prints the product's name and unit, whatever unit is sent.
- The product's KPD code is used unless the line has its own, which takes
  precedence. The site's default KPD code is not sent for such a line.
- By default a new e-računi product does not allow its description to be
  changed on an invoice, and then any description is refused, even the
  product's own name. So no description is sent, unless the site prints the
  line's own (the setting above).
- The line's price is always sent, so the product must allow its price to be
  changed, or the invoice is refused. The same applies to a VAT rate that
  differs from the product's.
- A product code e-računi does not know is refused.

### Changing the request

`EracuniEvents::PAYLOAD` lets a site set a field the module does not model. A
subscriber receives a `PayloadEvent` with the request parameters after they
were built and checked, and may change them. Nothing is checked again
afterwards, and the transaction ID is always restored.

## The PDF

After an invoice is issued, its PDF is fetched once (`SalesInvoiceGetPDF`) and
stored in the private file system as
`private://eracuni/YYYY-MM/<number>.pdf`, attached to the document.

It can be downloaded at `/eracuni/invoice/{document}/pdf` by anyone the source
allows to see the sale (for a Commerce order, anyone with view access to the
order) and by users with the *administer eracuni* permission. If the fetch
failed, the PDF is fetched on its first download. The stored copy is the
invoice as issued; e-računi remains the archive of record.

## Commerce (`eracuni_commerce`)

Settings in `eracuni_commerce.settings`:

- **gateways**: for each payment gateway, e-računi's method of payment and
  when the order is invoiced: `paid` (card payments, on
  `commerce_order.order.paid`) or `placed` (invoice terms). Orders paid
  through a gateway that is not listed are not invoiced.
- **without_gateway**: the same, for orders placed without a payment gateway,
  as a site that bills on invoice terms itself places them. Empty by default,
  which leaves those orders uninvoiced.
- **kpd_by_type**: a KPD code for each product variation type or order item
  type. **kpd_field**: a field on the purchased entity whose value takes
  precedence over both.
- **product_code_by_type** and **product_code_field**: the e-računi catalogue
  product a line is linked to, chosen the same way. Use a variation's `sku`
  as the field when the catalogue uses the site's SKUs.

Each order item becomes a line at the price charged, net of included tax. An
order-level adjustment, such as a promotion or a site's own discount, becomes
a line of its own, split across the items' VAT rates in proportion to their
gross totals, so the invoice comes to exactly the amount paid.

Two events let a site adjust what is invoiced:

- `OrderOwedEvent` (`eracuni_commerce.order_owed`) is dispatched before an
  order is recorded as owed. Call `decline()` with a reason for an order that
  should not be invoiced, such as an internal account's order or a
  subscription billed in arrears. No document is created, the reason is
  logged, and the backfill lists the order as not invoiced.
- `OrderInvoiceEvent` lets a site correct the invoice request, for example
  whether the buyer is a business, or a due date the site keeps itself.

## Drush

- `eracuni:status`: checks the credentials and counts documents by status.
- `eracuni:issue <source> <id> [reference]`: records one sale as owed and
  issues it immediately.
- `eracuni:retry`: queues every document due another attempt.
- `eracuni:lookup <reference> <from>`: lists e-računi's invoices for a
  reference since a date.
- `eracuni:commerce:backfill`: finds Commerce orders from the last 48 hours
  (`--hours`) that should have an invoice and have no document, and records
  and queues them. `--dry-run` only lists them. Declined orders are listed
  with their reason.

## Testing against e-računi

e-računi's demo account is shared by everyone who tries the API. Use invented
buyers only, set **Create invoices as** to Draft, and delete every draft
afterwards (`SalesInvoiceDelete`). Around forty calls in quick succession are
answered with "Too Many Requests", so space them out.
