<?php

namespace Drupal\fileblok\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;

/**
 * Block dat verkeersinformatie uit een JSON-bestand toont.
 *
 * @Block(
 *   id = "fileblok_block",
 *   admin_label = @Translation("Fileblok"),
 * )
 */
class FileblokBlock extends BlockBase {

  /**
   * Bouwt de output van het block.
   */
  public function build() {
    // Haal het pad naar de module op, zodat we weten waar de JSON file staat
    $module_path = \Drupal::service('extension.list.module')->getPath('fileblok');

    // Stel het volledige pad naar het JSON-bestand in
    $json_file = $module_path . '/data/verkeer.json';

    // Controleer of het bestand bestaat
    if (!file_exists($json_file)) {
      // Toon een vriendelijke melding als er geen data is
      return [
        '#markup' => $this->t('Geen verkeersinformatie beschikbaar.'),
      ];
    }

    // Lees de inhoud van het JSON-bestand
    $json = file_get_contents($json_file);

    // Decodeer JSON naar een PHP array zodat we ermee kunnen werken
    $data = json_decode($json, TRUE);

    // Kies maximaal de eerste 3 files voor de sidebar
    $files_to_show = array_slice($data['files'], 0, 3);

    // Maak een link naar de detailpagina van verkeersinformatie
    $url = Url::fromUri('internal:/verkeersinformatie');
    $link = '<a href="' . $url->toString() . '">' . $this->t('Verkeersinformatie') . '</a>';

    // Start de HTML-output
    $output = '<div class="fileblok">';

    // Voeg de titel toe, waarin de link staat
    $output .= '<h3 class="fileblok-sidebar-title">' . $link . '</h3>';

    // Als er files zijn om te tonen
    if (!empty($files_to_show)) {
      foreach ($files_to_show as $file) {
        // Voor elke file tonen we locatie, lengte en vertraging
        $output .= '<div class="file-item">';
        $output .= '<strong>' . $file['locatie'] . '</strong><br>';
        $output .= 'Lengte: ' . $file['lengte'] . '<br>';
        $output .= 'Vertraging: ' . $file['vertraging'];
        $output .= '</div>';
      }
    }
    else {
      // Als er geen files zijn, toon dit
      $output .= '<p>Geen files gevonden.</p>';
    }

    $output .= '</div>'; // einde wrapper div

    // Return de render array
    return [
      '#markup' => $output,            // HTML output
      '#cache' => ['max-age' => 0],    // cache uit, omdat files snel veranderen
      '#attached' => [
        'library' => ['fileblok/fileblok.styles'], // voeg CSS toe
      ],
    ];
  }

}
