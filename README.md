# e-računi

Issues invoices in [e-računi](https://e-racuni.com) through its JSON API, keeps
a document per invoice beside what it invoices, keeps each issued invoice's
PDF for its buyer to download, and raises an alarm when one is late.

`eracuni` knows nothing of Commerce: a **source** says what was sold and to
whom. `eracuni_commerce` is the source for Commerce orders. A site that sells
without Commerce writes its own (`InvoiceSourceInterface`, tagged
`eracuni.source`).

## Credentials

In `settings.php`, never in configuration, which is exported:

```php
$settings['eracuni'] = [
  'username' => '…',
  'secret_key' => '…',
  'token' => '…',
  // Optional: the Croatian instance otherwise.
  'endpoint' => 'https://e-racuni.com/WebServicesHR/API',
];
```

`drush eracuni:status` proves them with a lookup that finds nothing.

## Settings

`/admin/config/services/eracuni` (`eracuni.settings`):

- **Business unit**: a fiscalised one, for invoices paid by card. e-računi
  fiscalises a Retail invoice paid by card or Stripe in such a unit on
  creation; nothing here holds a certificate.
- **Order reference prefix**: in front of every reference, so two sites that
  issue into one account never share one.
- **Create invoices as**: Issued, or Drafts for a test account. A draft is
  numbered by nobody and has no PDF.
- **VAT transaction types**: e-računi's code per VAT treatment, from the
  accountant. A business elsewhere in the EU and every buyer outside it need
  one; an invoice that needs one with none mapped is refused, not guessed.
- **Default KPD code**: for a line its source names none for.
- **Print the line's own description on a catalogue product**: off, and a
  line naming a product in e-računi's catalogue prints the product's name.
- **Alarm** after 24 hours by default: Croatian law gives two days to
  fiscalise an invoice that was not.

## How an invoice is issued

1. The sale records a **pending document** (`Issuer::owe()`) and queues it.
   Nothing calls e-računi inside a checkout or a payment webhook.
2. The queue (`eracuni_issue`) asks the source for the invoice as it is now,
   builds it (`PayloadBuilder`), lets a site add to it
   (`EracuniEvents::PAYLOAD`) and sends `SalesInvoiceCreate`.
3. Issued: e-računi's document ID and number are on the document, and its PDF
   is queued (`eracuni_pdf`). Refused or unreachable: the reason is on it, and
   cron sends it again after 15 minutes, up to `max_attempts`.
4. Still not issued after `alert_after_hours`: an error in the log and
   `EracuniEvents::OVERDUE`, once, for a site to send where people look.

### The transaction id

Every create carries an `apiTransactionId`. e-računi answers an id it has
seen with **the answer it gave the first time**, whatever is sent with it
(tried on its demo account, 26-10-2026):

- An answer lost on the way is sent again with the same id, and the answer is
  the invoice the first request made, if it made one. No second invoice.
- A refusal is replayed too, so nothing fixed afterwards would ever be read. A
  refused document is given a new id (`…-r2`, `…-r3`); a refusal made
  nothing, so that is safe.
- A document deleted in e-računi is not made again: the same id answers with
  the deleted document's ID. Issue it again under a new id, by hand.

### Shapes

| Treatment | Invoice | Prices |
|---|---|---|
| A consumer anywhere; an EU business without a valid id | `Retail`, buyer inline | `price` gross, `vatPercentage` |
| A domestic business with a valid OIB, an EU business with a VAT id, a business outside the EU | `Gross`, `buyerTaxNumber` | `netPrice`, `vatPercentage` |

A `Gross` invoice with a line that has no KPD code is refused by e-računi
(`#classificationCodeField`). The code goes in `classificationCode` as
e-računi keeps it, section letter first (`L64.99.09`); one without the letter
is refused. Retail invoices are taken without one.

The lines have to come to what was charged, within rounding, or the invoice is
refused before it is sent.

e-računi takes no invoice without the buyer's street, postal code and city. A
retail invoice says `-` for any its source does not have: a consumer's address
is not needed on one, and a payment provider often collects only the country.
A business invoice without a city is refused before it is sent.

### Catalogue products

A line may name a product in e-računi's catalogue (`Line::$productCode`, sent
as `productCode`). On e-računi's demo account, 26-10-2026:

- The invoice prints the product's name and its unit, whatever unit is sent.
- Its KPD code is the product's unless the line names one, which wins. The
  site's default KPD is not sent for such a line.
- A product that does not allow changing its description on the invoice,
  e-računi's default for a new one, refuses any description, its own name
  too. So none is sent, unless the site prints its own (the setting above).
- The line's price is always sent, so the product has to allow changing the
  price, or the invoice is refused. So is a VAT rate other than the
  product's, unless it allows changing that as well.
- A code e-računi does not know is refused.

`EracuniEvents::PAYLOAD` is for a field the module does not model: a
`PayloadEvent` carries the parameters as built and checked, and a subscriber
changes them. Nothing is checked again; the transaction id is put back.

## The PDF

Fetched once after issuing (`SalesInvoiceGetPDF`) and kept in the private
scheme, `private://eracuni/YYYY-MM/<number>.pdf`, as a file on the document.
Downloaded at `/eracuni/invoice/{document}/pdf` by whoever the source lets see
the sale (an order: its view access) and by e-računi admins. A fetch that
failed is made when it is first downloaded. The copy is the invoice as it was
issued; e-računi stays the archive of record.

## Commerce (`eracuni_commerce`)

`eracuni_commerce.settings`:

- **gateways**: per payment gateway, e-računi's method of payment and when
  the order is invoiced: `paid` (a card, on `commerce_order.order.paid`) or
  `placed` (invoice terms). A gateway not listed is not invoiced.
- **without_gateway**: the same for orders placed with no gateway, which is
  how a site that bills on invoice terms itself places them. An empty method
  leaves them uninvoiced, the default.
- **kpd_by_type**: a KPD code per product variation type or order item type;
  **kpd_field**: a field on the purchased entity that wins over both.
- **product_code_by_type** and **product_code_field**: the product in
  e-računi's catalogue a line is, the same way. A variation's `sku` is the
  field when the catalogue uses the site's SKUs.

Lines are the order items at what was charged, net of included tax. An
order-level adjustment (a promotion, a site's own discount) is a line of its
own, split across the items' rates by their gross, so the invoice comes to
exactly what was paid. `OrderInvoiceEvent` lets a site correct the request:
whether the buyer is a business, a due date it keeps.

`OrderOwedEvent` (`eracuni_commerce.order_owed`) comes first, before an order
is recorded as owed: a site calls `decline()` with a reason for an order that
is not a sale, such as one of its own accounts, or the order a subscription
billed in arrears was bought on. No document is made, the reason is logged,
and the backfill lists it as not invoiced.

`drush eracuni:commerce:backfill --dry-run` lists orders of the last 48 hours
that should have an invoice and have no document.

## Drush

- `eracuni:status`: the credentials, and documents by status.
- `eracuni:issue <source> <id> [reference]`: record and issue one sale now.
- `eracuni:retry`: queue what is due another attempt.
- `eracuni:lookup <reference> <from>`: e-računi's invoices for a reference.

## Testing against e-računi

e-računi's demo account is shared by everybody who tries the API: invented
buyers only, status **Draft**, and every draft deleted afterwards
(`SalesInvoiceDelete`). About forty calls in quick succession get
"Too Many Requests"; space them.
