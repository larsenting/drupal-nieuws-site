<?php

namespace Drupal\weerblok\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\weerblok\WeerblokService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WeerblokController extends ControllerBase {

  protected WeerblokService $weerService;

  /**
   * Constructor.
   */
  public function __construct(WeerblokService $weerService) {
    $this->weerService = $weerService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $http_client = $container->get('http_client');

    // Haal de API key op uit de config of settings
    $config = \Drupal::config('weerblok.settings');
    $api_key = $config->get('api_key');

    // Fallback: als config leeg is, probeer settings.php
    if (empty($api_key) && isset($GLOBALS['settings']['weerblok.api_key'])) {
      $api_key = $GLOBALS['settings']['weerblok.api_key'];
    }

    $weerService = new WeerblokService($http_client, $api_key);

    return new static($weerService);
  }

  /**
   * Toon het weer voor de komende dagen in Assen.
   */
  public function weerData() {
    $week_data = $this->weerService->getWeerWeek('Assen');

    if ($week_data && isset($week_data['forecast']['forecastday'])) {
        $output = '<h2>Weer voor de komende dagen in Assen</h2>';
        $output .= '<div class="week-weer">';

        foreach ($week_data['forecast']['forecastday'] as $day) {
            $date = \Drupal::service('date.formatter')->format(strtotime($day['date']), 'custom', 'l d M');
            $maxtemp = $day['day']['maxtemp_c'];
            $mintemp = $day['day']['mintemp_c'];
            $cond = $day['day']['condition']['text'];
            $icon = $day['day']['condition']['icon'];

            $output .= '<div class="dag-weer">';
            $output .= '<strong>' . $date . '</strong><br>';
            $output .= '<img src="' . $icon . '" alt="' . $cond . '"><br>';
            $output .= $cond . '<br>';
            $output .= 'Max: ' . $maxtemp . '°C, Min: ' . $mintemp . '°C';
            $output .= '</div>';
        }

        $output .= '</div>'; // sluit week-weer
    }
    else {
        $output = '<p>Weerdata is momenteel niet beschikbaar.</p>';
    }

    return [
        '#markup' => $output,
        '#attached' => [
            'library' => [
                'weerblok/weerblok-styles',
            ],
        ],
        '#cache' => [
            'max-age' => 0,
        ],
    ];
  }

}
