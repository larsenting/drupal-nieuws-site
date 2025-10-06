<?php

namespace Drupal\fileblok\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;

/**
 * Provides a 'Fileblok' block.
 *
 * @Block(
 *   id = "fileblok_block",
 *   admin_label = @Translation("Fileblok"),
 * )
 */
class FileblokBlock extends BlockBase {

  public function build() {
    $module_path = \Drupal::service('extension.list.module')->getPath('fileblok');
    $json_file = $module_path . '/data/verkeer.json';

    if (!file_exists($json_file)) {
      return [
        '#markup' => $this->t('Geen verkeersinformatie beschikbaar.'),
      ];
    }

    $json = file_get_contents($json_file);
    $data = json_decode($json, TRUE);

    // Max 3 files voor de sidebar
    $files_to_show = array_slice($data['files'], 0, 3);

    // Link naar detailpagina
    $url = Url::fromUri('internal:/verkeersinformatie');
    $link = '<a href="' . $url->toString() . '">' . $this->t('Verkeersinformatie') . '</a>';

    $output = '<div class="fileblok">';
    $output .= '<h2>' . $link . '</h2>';

    if (!empty($files_to_show)) {
      foreach ($files_to_show as $file) {
        $output .= '<div class="file-item">';
        $output .= '<strong>' . $file['locatie'] . '</strong><br>';
        $output .= 'Lengte: ' . $file['lengte'] . '<br>';
        $output .= 'Vertraging: ' . $file['vertraging'];
        $output .= '</div>';
      }
    } else {
      $output .= '<p>Geen files gevonden.</p>';
    }

    $output .= '</div>';

    return [
      '#markup' => $output,
      '#cache' => ['max-age' => 0],
      '#attached' => [
        'library' => ['fileblok/fileblok.styles'],
      ],
    ];
  }

}
