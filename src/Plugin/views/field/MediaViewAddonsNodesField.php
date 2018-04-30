<?php

/**
 * @file
 * Definition of Drupal\media_view_addons\Plugin\views\field\MediaViewAddonsNodesField.
 */

namespace Drupal\media_view_addons\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Url;
use Drupal\Core\Cache\Cache;

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

      // Use only nodes and paragraphs, this might expand in the future.
      $entity_types = ['node', 'paragraph'];

      // Get all entity reference revision fields on paragraphs and nodes.
      $entity_rev_fields = $this->entityReferenceRevisionFields($entity_types);
      // Get all image fields on paragraphs and nodes.
      $image_fields = $this->entityImageFields($entity_types);
      $fields_to_search = array_merge($entity_rev_fields, $image_fields);

      // Here's where top level node IDs get stored.
      $node_ids = [];
      // And that's where we keep their references.
      // For a start, just add the media View row image ID.
      $non_node_ids = [$row_media_image_id];

      // Get all the top level node IDs.
      $top_level_node_ids = $this->topLevelMediaNodes($entity_types, $fields_to_search, $non_node_ids, $node_ids);
      if (!empty($top_level_node_ids)) {
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
   * Get Entity Reference Revision fields from entity types.
   *
   * @param array $entity_types
   * @return array
   */
  public function entityReferenceRevisionFields(array $entity_types) {
    $entity_rr_fields = [];
    foreach ($entity_types as $entity_type) {
      $field_map = \Drupal::getContainer()->get('entity_field.manager')->getFieldMap();
      $row_entity_fields = $field_map[$entity_type];
      foreach ($row_entity_fields as $field_name => $field_info) {
        // @todo This can be expanded to other types but
        // entity reference revisions work really well with paragraphs.
        if ($field_info['type'] == 'entity_reference_revisions') {
          $entity_rr_fields[] = $field_name;
        }
      }
    }
    return array_values(array_unique($entity_rr_fields));
  }

  /**
   * Get all image fields from entity types.
   *
   * @param array $entity_types
   * @return array
   */
  public function entityImageFields(array $entity_types) {
    $image_fields = [];
    $field_map = \Drupal::getContainer()->get('entity_field.manager')->getFieldMap();
    foreach ($field_map as $entity_type => $entity_field_maps) {
      if (in_array($entity_type, $entity_types)) {
        foreach ($entity_field_maps as $field_name => $field_info) {
          // Only get image type fields.
          if ($field_info['type'] == 'image') {
            $image_fields[] = $field_name;
          }
        }
      }
    }
    return array_values(array_unique($image_fields));
  }

  /**
   * Get all top level media nodes from their referenced entities / media images.
   *
   * @param array $entity_types
   * @param array $fields
   * @param array $non_node_ids
   * @param array $node_ids
   * @param int $end
   * @return array
   */
  public function topLevelMediaNodes(array $entity_types, array $fields, array $non_node_ids, array $node_ids, $end = 0) {
    $fetched_node_ids = $fetched_non_node_ids = [];
    foreach ($non_node_ids as $non_node_id) {
      foreach ($entity_types as $entity_type) {
        foreach ($fields as $field) {
          if (db_table_exists($entity_type . '__' . $field)) {
            $connection = \Drupal::database();
            $query = $connection->select($entity_type . '__' . $field, 'enf')
              ->condition($field . '_target_id', $non_node_id, '=')
              ->fields('enf', ['entity_id']);
            $result = $query->execute()->fetchAllAssoc('entity_id');
            foreach ($result as $row) {
              if ($entity_type == 'node') {
                $fetched_node_ids[] = intval($row->entity_id);
              }
              else {
                $fetched_non_node_ids[] = intval($row->entity_id);
              }
            }
          }
        }
      }
    }
    // Add the found node IDS to the initial basket.
    foreach ($fetched_node_ids as $fetched_node_id) {
      $node_ids[] = $fetched_node_id;
    }
    // Keep only the newly found "non-node" IDs for further looking up the tree.
    $non_node_ids = $fetched_non_node_ids;

    // If there are no other "non-node" IDs to scrutinize, it's exit time.
    if (empty($non_node_ids) || $end == 4) {
      return $node_ids;
    }
    else {
      // If we're beyond level 2 just look for node type parents.
      $entity_types = ($end == 3) ? ['node'] : $entity_types;
      return $this->topLevelMediaNodes($entity_types, $fields, $non_node_ids, $node_ids, $end+1);
    }
  }
}
