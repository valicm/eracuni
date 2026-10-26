<?php

declare(strict_types=1);

namespace Drupal\eracuni\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\EntityViewsData;

/**
 * One invoice this site asked e-računi for, and what became of it.
 *
 * Kept here rather than only in e-računi so the site can answer, without a
 * call, whether something it sold has been invoiced — which is what the
 * two-day alarm and a refund both have to know. One document per sold thing:
 * a retry updates the row, it does not add one, and an issued row is never
 * issued again.
 */
#[ContentEntityType(
  id: 'eracuni_document',
  label: new TranslatableMarkup('e-računi document'),
  label_collection: new TranslatableMarkup('e-računi documents'),
  label_singular: new TranslatableMarkup('e-računi document'),
  label_plural: new TranslatableMarkup('e-računi documents'),
  label_count: [
    'singular' => '@count e-računi document',
    'plural' => '@count e-računi documents',
  ],
  handlers: [
    'list_builder' => DocumentListBuilder::class,
    'views_data' => EntityViewsData::class,
  ],
  base_table: 'eracuni_document',
  admin_permission: 'administer eracuni',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'reference',
  ],
  links: [
    'collection' => '/admin/config/services/eracuni/documents',
  ],
)]
final class EracuniDocument extends ContentEntityBase implements EracuniDocumentInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   The fields.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['source_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source'))
      ->setDescription(new TranslatableMarkup("What sold it: commerce_order, or a source of a site's own."))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['source_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source ID'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['reference'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Reference'))
      ->setDescription(new TranslatableMarkup('The order reference e-računi files it under, prefix included.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['transaction_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Transaction ID'))
      ->setDescription(new TranslatableMarkup('Sent as apiTransactionId on every attempt, so a repeated create returns the invoice the first made.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::PENDING => 'Pending',
        self::ISSUED => 'Issued',
        self::FAILED => 'Failed',
      ])
      ->setDefaultValue(self::PENDING);

    $fields['draft'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Draft'))
      ->setDescription(new TranslatableMarkup('Created as a draft, as a test account is set to: e-računi numbers it for nobody and prints no PDF of it.'))
      ->setDefaultValue(FALSE);

    $fields['document_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('e-računi document ID'))
      ->setSetting('max_length', 64);

    $fields['number'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Invoice number'))
      ->setSetting('max_length', 64);

    $fields['treatment'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('VAT treatment'))
      ->setSetting('max_length', 32);

    $fields['method_of_payment'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Method of payment'))
      ->setSetting('max_length', 64);

    $fields['attempts'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Attempts'))
      ->setDefaultValue(0);

    $fields['error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Last error'));

    $fields['warnings'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Warnings'))
      ->setDescription(new TranslatableMarkup('What deserved a look when it was built: a line with no KPD code, a domestic line at 0%.'));

    $fields['pdf'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('PDF'))
      ->setDescription(new TranslatableMarkup('The invoice as e-računi issued it, kept privately for the buyer to download.'))
      ->setSetting('uri_scheme', 'private')
      ->setSetting('file_directory', 'eracuni')
      ->setSetting('file_extensions', 'pdf');

    $fields['due'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Due'))
      ->setDescription(new TranslatableMarkup('When the invoice became owed: the sale, from which the two days are counted.'));

    $fields['issued'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Issued'));

    $fields['alerted'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Alerted'))
      ->setDescription(new TranslatableMarkup('When it was announced as late. Once.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isIssued(): bool {
    return $this->getStatus() === self::ISSUED;
  }

  /**
   * {@inheritdoc}
   */
  public function isDraft(): bool {
    return (bool) $this->get('draft')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDocumentId(): string {
    return (string) $this->get('document_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getNumber(): string {
    return (string) $this->get('number')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getReference(): string {
    return (string) $this->get('reference')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTransactionId(): string {
    return (string) $this->get('transaction_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttempts(): int {
    return (int) $this->get('attempts')->value;
  }

}
