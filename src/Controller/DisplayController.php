<?php

namespace Drupal\custom_export_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for exporting allocation outlook data as CSV.
 */
class DisplayController extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the DisplayController object.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Dependency injection via service container.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Exports allocation outlook data as CSV.
   */
  public function content(): array {
    // Set headers to download CSV.
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="allocation-outlook-by-resource.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $view = \Drupal\views\Views::getView('allocation_outlook_by_resources1');
    $view->execute();

    $taxonomy_titles = [
      'resource_name' => [],
      'resource_type' => [],
      'account_name' => [],
      'project_name' => [],
      'billing_type' => [],
      'probability' => [],
    ];

    $allocation_ids = [];

    foreach ($view->result as $row) {
      $entity = $row->_entity;

      $this->extractTaxonomyField($entity->get('field_re')->getValue(), 'resource_name', $taxonomy_titles);
      $this->extractTaxonomyField($entity->get('field_resource_type')->getValue(), 'resource_type', $taxonomy_titles);
      $this->extractTaxonomyField($entity->get('field_a')->getValue(), 'account_name', $taxonomy_titles);
      $this->extractTaxonomyField($entity->get('field_p')->getValue(), 'project_name', $taxonomy_titles);
      $this->extractTaxonomyField($entity->get('field_b')->getValue(), 'billing_type', $taxonomy_titles);
      $this->extractSimpleField($entity->get('field_probability')->getValue(), 'probability', $taxonomy_titles);

      $duration_refs = array_column($entity->get('field_allocation_duration')->getValue(), 'target_id');
      foreach ($duration_refs as $id) {
        if (!in_array($id, $allocation_ids, TRUE)) {
          $allocation_ids[] = $id;
        }
      }
    }

    [$allocations, $start_dates, $end_dates] = $this->getAllocationDetails($allocation_ids);

    $this->generateCSV(
      $taxonomy_titles['resource_name'],
      $taxonomy_titles['account_name'],
      $taxonomy_titles['project_name'],
      $taxonomy_titles['resource_type'],
      $taxonomy_titles['billing_type'],
      $taxonomy_titles['probability'],
      $allocations,
      $start_dates,
      $end_dates
    );

    exit(); // Ends response after file download
  }

  /**
   * Extracts taxonomy term names from a field value.
   */
  protected function extractTaxonomyField(array $field_values, string $key, array &$taxonomy_titles): void {
    foreach ($field_values as $value) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($value['target_id'] ?? $value);
      if ($term) {
        $taxonomy_titles[$key][] = $term->label();
      }
    }
  }

  /**
   * Extracts simple values (non-entity fields).
   */
  protected function extractSimpleField(array $field_values, string $key, array &$taxonomy_titles): void {
    foreach ($field_values as $value) {
      $taxonomy_titles[$key][] = $value['value'];
    }
  }

  /**
   * Retrieves allocation paragraph details.
   */
  protected function getAllocationDetails(array $ids): array {
    $allocations = $start_dates = $end_dates = [];

    foreach ($ids as $id) {
      $paragraph = Paragraph::load($id);
      if ($paragraph) {
        $allocations[] = $paragraph->get('field_allocation')->value;
        $start_dates[] = $paragraph->get('field_from_to')->value;
        $end_dates[] = $paragraph->get('field_from_to')->end_value;
      }
    }

    return [$allocations, $start_dates, $end_dates];
  }

  /**
   * Outputs the CSV file.
   */
  protected function generateCSV(array $names, array $accounts, array $projects, array $types, array $billing, array $prob, array $allocs, array $starts, array $ends): void {
    $file = fopen('php://output', 'w');
    fputcsv($file, ['ResourceName', 'AccountName', 'ProjectName', 'ResourceType', 'BillingType', 'Probability', 'ResourceAllocation', 'StartDate', 'EndDate']);

    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
      fputcsv($file, [
        $names[$i] ?? '',
        $accounts[$i] ?? '',
        $projects[$i] ?? '',
        $types[$i] ?? '',
        $billing[$i] ?? '',
        $prob[$i] ?? '',
        $allocs[$i] ?? '',
        $starts[$i] ?? '',
        $ends[$i] ?? '',
      ]);
    }

    fclose($file);
  }

}
