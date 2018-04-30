<?php

/**
 * @file
 * Definition of Drupal\media_view_addons\Plugin\views\field\MediaViewAddonsNodesField.
 */

namespace Drupal\media_view_addons\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Url;

/**
 * Plugin to add a top level entity link to the media view.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("media_view_addons_nodes_field")
 */
class MediaViewAddonsNodesField extends FieldPluginBase {
  /**
   * @{inheritdoc}
   */
  public function query() {
    // Leave empty to avoid a query on this field.
  }

  /**
   * Overrides \Drupal\views\Plugin\views\field\FieldPluginBase::render().
   *
   * Renders the top level node edit links for each media View row.
   *
   * @param \Drupal\views\ResultRow $row
   *   The values retrieved from a single row of a view's query result.
   *
   * @return \Drupal\Component\Render\MarkupInterface|\Drupal\Core\StringTranslation\TranslatableMarkup|\Drupal\views\Render\ViewsRenderPipelineMarkup|string
   */
  public function render(ResultRow $row) {
    if (!empty($this->view->field['mid'])) {
      // Get the mid from the media View.
      $row_media_image_id = intval($this->view->field['mid']->getValue($row));

      // Get all the top level node IDs.
      if ($top_level_node_ids = $this->topLevelNids('media', $row_media_image_id, $nesting_level = 0)) {
        $links = [];
        $node_storage = \Drupal::entityTypeManager()->getStorage('node');
        foreach ($top_level_node_ids as $top_level_node_id) {
          $node = $node_storage->load($top_level_node_id);
          $links[$top_level_node_id] = [
            'title' => $this->t('@title', ['@title' => $node->title->value]),
            'url' => Url::fromRoute('entity.node.edit_form', ['node' => $top_level_node_id]),
          ];
        }
        // Allow other modules to alter the links array.
        \Drupal::moduleHandler()->invokeAll('media_view_addons_links', [&$links]);
        // Make node edit links look like fancy operation dropdowns.
        $operations['data'] = [
          '#type' => 'operations',
          '#links' => $links,
          '#cache' => [
            'tags' => ['node_list'],
          ],
        ];
        return $this->renderer->render($operations);
      }
      else {
        return t('No nodes retrieved');
      }
    }
  }

  /**
   * Get fields that reference entities.
   *
   * @param array $entity_types
   * @return array
   */
  protected function entityReferenceFieldMap($entity_types = ['node', 'paragraph']) {
    static $entity_reference_map;
    if (is_array($entity_reference_map)) {
      return $entity_reference_map;
    }

    foreach ($entity_types as $entity_type_id) {
      $bundles = \Drupal::getContainer()->get('entity_type.bundle.info')->getBundleInfo($entity_type_id);
      foreach ($bundles as $bundle_id => $bundle) {
        $field_definitions = \Drupal::getContainer()->get('entity_field.manager')->getFieldDefinitions($entity_type_id, $bundle_id);
        foreach ($field_definitions as $field_definition) {
          if ($target_type = $field_definition->getSetting('target_type')) {
            $entity_reference_map[$target_type][$entity_type_id][$field_definition->getName()] = $field_definition;
          }
        }
      }
    }

    return $entity_reference_map;
  }

  /**
   * Return fields that reference provided entity type.
   *
   * @param $entity_type_id
   * @return array
   */
  protected function entityReferenceFields($entity_type_id) {
    $map = $this->entityReferenceFieldMap();
    return $map[$entity_type_id] ?: [];
  }

  /**
   * Get all top level nodes from their referenced entities.
   *
   * @param $entity_type_id
   * @param $entity_id
   * @param $nesting_level
   * @param int $nesting_limit
   * @return array
   */
  public function topLevelNids($entity_type_id, $entity_id, $nesting_level, $nesting_limit = 5) {
    // Prevent infinite loop.
    if ($nesting_level >= $nesting_limit) {
      return [];
    }

    $connection = \Drupal::database();
    $nids = [];
    foreach ($this->entityReferenceFields($entity_type_id) as $parent_entity_type_id => $field_definitions) {
      foreach ($field_definitions as $field_definition) {
        $field_name = $field_definition->getName();
        $query = $connection->select($parent_entity_type_id . '__' . $field_name, 'enf')
          ->condition($field_name . '_target_id', $entity_id, '=')
          ->fields('enf', ['entity_id']);
        $result = $query->execute()->fetchAllAssoc('entity_id');
        foreach ($result as $row) {
          if ($parent_entity_type_id == 'node') {
            $nids[] = intval($row->entity_id);
          }
          else {
            $nids = array_merge($nids, $this->topLevelNids($parent_entity_type_id, $row->entity_id, $nesting_level++));
          }
        }
      }
    }
    return $nids;
  }
}
