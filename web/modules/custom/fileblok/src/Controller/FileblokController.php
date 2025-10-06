<?php

namespace Drupal\fileblok\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller voor het Fileblok.
 *
 * Deze controller toont verkeersinformatie uit een lokaal JSON-bestand.
 * De data wordt gecombineerd met een afbeelding en als HTML gepresenteerd.
 */
class FileblokController extends ControllerBase {

  /**
   * Detailpagina voor verkeersinformatie.
   *
   * Deze methode leest een JSON-bestand in met actuele filedata,
   * bouwt een eenvoudige HTML-structuur en geeft die terug aan Drupal.
   * Er wordt geen externe API gebruikt, zodat de informatie ook
   * offline of uit een statisch bestand kan worden geladen.
   */
  public function detail() {
    // Zoek het pad van de module zodat we het JSON-bestand kunnen vinden.
    // Dit maakt de code flexibel: de module kan in een andere map staan
    // zonder dat de logica aangepast hoeft te worden.
    $module_path = \Drupal::service('extension.list.module')->getPath('fileblok');
    $json_file = $module_path . '/data/verkeer.json';

    // Als het bestand niet bestaat, tonen we een nette fallback-melding
    // in plaats van een fout. Dit zorgt voor een robuuste ervaring.
    if (!file_exists($json_file)) {
      return ['#markup' => $this->t('Geen verkeersinformatie beschikbaar.')];
    }

    // Het JSON-bestand wordt ingelezen en omgezet naar een PHP-array,
    // zodat de gegevens eenvoudig door de code verwerkt kunnen worden.
    $json = file_get_contents($json_file);
    $data = json_decode($json, TRUE);

    // Begin met het opbouwen van de HTML-output.
    // Er wordt hier een string opgebouwd, maar dit zou in een complexere
    // versie ook met Twig-templates kunnen gebeuren.
    $output = '<div class="fileblok-page">';

    // Voeg een vaste afbeelding toe boven de tekst. Dit zorgt voor
    // visuele context bij de verkeersinformatie.
    $output .= '<div class="fileblok-image">';
    $output .= '<img src="/sites/default/files/fileblok/verkeersinfo.jpg" class="fileblok-page-image">';
    $output .= '</div>';

    // Toon vervolgens de tekstuele verkeersinformatie.
    // We centreren de tekst om de presentatie leesbaar en consistent te houden.
    $output .= '<div class="fileblok-text" style="text-align: center;">';
    foreach ($data['files'] as $file) {
      $output .= '<div class="file-item-page">';
      $output .= '<strong>' . $file['locatie'] . '</strong><br>';
      $output .= 'Lengte: ' . $file['lengte'] . '<br>';
      $output .= 'Vertraging: ' . $file['vertraging'];
      $output .= '</div>';
    }
    $output .= '</div>';

    // Sluit de container af.
    $output .= '</div>';

    // Retourneer een render array. Hiermee kan Drupal caching toepassen
    // en een CSS librarie toevoegen. De max-age is hier 0 gezet omdat
    // verkeersinformatie vaak verandert en nooit gebufferd moet worden.
    return [
      '#markup' => $output,
      '#cache' => ['max-age' => 0],
      '#attached' => [
        'library' => ['fileblok/fileblok.styles'],
      ],
    ];
  }
}
