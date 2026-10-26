<?php

declare(strict_types=1);

namespace Drupal\eracuni\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\RedundantEditableConfigNamesTrait;
use Drupal\eracuni\Invoice\VatTreatment;

/**
 * How invoices are issued: not who issues them, which is settings.php's.
 */
final class SettingsForm extends ConfigFormBase {

  use RedundantEditableConfigNamesTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'eracuni_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['credentials'] = [
      '#markup' => '<p>' . $this->t("Credentials are not set here but in settings.php, as <code>\$settings['eracuni']</code>: <code>username</code>, <code>secret_key</code>, <code>token</code> and, for an instance other than the Croatian one, <code>endpoint</code>.") . '</p>',
    ];

    $form['business_unit'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Business unit'),
      '#description' => $this->t('A fiscalised one for invoices paid by card: e-računi fiscalises those on creation.'),
      '#config_target' => 'eracuni.settings:business_unit',
    ];
    $form['reference_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order reference prefix'),
      '#description' => $this->t('Put in front of every order reference, so two sites issuing into one account never share one.'),
      '#config_target' => 'eracuni.settings:reference_prefix',
    ];
    $form['status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Create invoices as'),
      '#options' => [
        'IssuedInvoice' => $this->t('Issued: numbered, and fiscalised where it is paid by card'),
        'Draft' => $this->t('Drafts: for a test account, where nothing should be numbered'),
      ],
      '#config_target' => 'eracuni.settings:status',
    ];
    $form['send_by_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('e-računi mails the issued invoice to the buyer'),
      '#config_target' => 'eracuni.settings:send_by_email',
    ];
    $form['document_language'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Document language'),
      '#config_target' => 'eracuni.settings:document_language',
    ];
    $form['seller_country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Seller country'),
      '#maxlength' => 2,
      '#size' => 4,
      '#config_target' => 'eracuni.settings:seller_country',
    ];

    $form['vat_transaction_types'] = [
      '#type' => 'details',
      '#title' => $this->t('VAT transaction types'),
      '#description' => $this->t("e-računi's code for each treatment, as your accountant gives it. A business elsewhere in the EU and every buyer outside it need one; an invoice that needs one with none mapped is refused rather than guessed."),
      '#open' => TRUE,
    ];
    $labels = [
      VatTreatment::DomesticB2b->value => $this->t('Domestic business, with a valid OIB'),
      VatTreatment::DomesticB2c->value => $this->t('Domestic consumer'),
      VatTreatment::EuB2b->value => $this->t('EU business with a VAT id (reverse charge)'),
      VatTreatment::EuB2c->value => $this->t('EU consumer'),
      VatTreatment::ExportB2b->value => $this->t('Business outside the EU'),
      VatTreatment::ExportB2c->value => $this->t('Consumer outside the EU'),
    ];
    foreach ($labels as $treatment => $label) {
      $form['vat_transaction_types'][$treatment] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#size' => 8,
        '#config_target' => 'eracuni.settings:vat_transaction_types.' . $treatment,
      ];
    }

    $form['default_kpd'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default KPD code'),
      '#description' => $this->t("For a line whose source names none. Empty sends none, and the document says so. A line for a product in e-računi's catalogue takes the product's own instead."),
      '#config_target' => 'eracuni.settings:default_kpd',
    ];
    $form['describe_products'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Print the line's own description on a catalogue product"),
      '#description' => $this->t("Otherwise the product's name prints. e-računi refuses the invoice for a product that does not allow changing its description."),
      '#config_target' => 'eracuni.settings:describe_products',
    ];
    $form['alert_after_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Hours before a missing invoice is an error'),
      '#description' => $this->t('Croatian law gives two days to fiscalise an invoice that was not.'),
      '#min' => 1,
      '#max' => 48,
      '#config_target' => 'eracuni.settings:alert_after_hours',
    ];
    $form['max_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Attempts before only the alarm is left'),
      '#min' => 1,
      '#config_target' => 'eracuni.settings:max_attempts',
    ];

    return parent::buildForm($form, $form_state);
  }

}
