<?php

namespace Drupal\weerblok\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Component\Utility\Html;

/**
 * Blok dat de 3-daagse weersvoorspelling toont via WeatherAPI.com.
 *
 * @Block(
 *   id = "weerblok",
 *   admin_label = @Translation("Weerblok"),
 *   category = @Translation("Custom")
 * )
 */
class WeerblokBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * HTTP client om externe API-aanvragen te doen.
   *
   * Waarom: We hebben een manier nodig om de WeatherAPI aan te roepen.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Drupal settings service.
   *
   * Waarom: Om API-keys uit settings.php of environment variables op te halen.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * Constructor: ontvangt configuratie en services.
   *
   * Waarom: Hiermee kunnen we dependency injection gebruiken en de blockclass beter testen.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $http_client, $settings) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    // Sla de HTTP client op voor later gebruik.
    $this->httpClient = $http_client;

    // Sla de settings service op zodat we de API-key kunnen ophalen.
    $this->settings = $settings;
  }

  /**
   * Factory-methode voor dependency injection via de Drupal container.
   *
   * Waarom: Drupal gebruikt deze methode om automatisch services zoals http_client te injecteren.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'), // Haal de HTTP client service op
      $container->get('settings')     // Haal de settings service op
    );
  }

  /**
   * Haalt de API-key op uit verschillende mogelijke locaties.
   *
   * Volgorde:
   * 1. Config via Drupal (toekomstige optie voor admin-config)
   * 2. settings.php / settings.local.php
   * 3. Environment variables (handig bij Docker of CI/CD)
   *
   * Waarom: Zo kan de site draaien zonder dat de key hardcoded staat.
   *
   * @return string|null
   *   De gevonden API-key of NULL als er geen key is gevonden.
   */
  protected function getApiKey(): ?string {
    try {
      // Probeer Drupal configuratie
      $config = \Drupal::configFactory()->get('weerblok.settings');
      $key = $config ? $config->get('api_key') : NULL;
    }
    catch (\Exception $e) {
      $key = NULL; // Config bestaat niet of is leeg
    }
    if (!empty($key)) {
      return $key; // Config gevonden
    }

    // Probeer settings service
    $key = $this->settings->get('weerblok.api_key');
    if (!empty($key)) {
      return $key; // Key gevonden in settings.php
    }

    // Probeer environment variables
    $key = getenv('WEERBLOK_API_KEY') ?: getenv('WEERBLOCK_API_KEY') ?: NULL;
    return $key ?: NULL; // Return NULL als geen key gevonden
  }

  /**
   * Build de output van het block.
   *
   * Waarom: Dit is de centrale functie die bepaalt wat er op de pagina verschijnt.
   *
   * @return array
   *   Drupal render array voor het block.
   */
  public function build() {
    // Stel een vaste locatie in voor de weersvoorspelling
    $location = 'Assen';

    // Haal de API-key op
    $apiKey = $this->getApiKey();
    if (empty($apiKey)) {
      // Toon een duidelijke foutmelding voor de sitebeheerder
      $msg = $this->t('Weerblok is niet geconfigureerd. Voeg je WeatherAPI sleutel toe in settings.php of via een environment variabele.');
      return [
        '#markup' => '<div class="weerblok error">' . Html::escape($msg) . '</div>',
      ];
    }

    // WeatherAPI endpoint en queryparameters
    $endpoint = 'https://api.weatherapi.com/v1/forecast.json';
    $query = [
      'key' => $apiKey,        // API-key nodig voor authenticatie
      'q' => $location,        // Plaatsnaam
      'days' => 3,             // 3-daagse forecast
      'aqi' => 'no',           // Geen luchtkwaliteitsindex
      'alerts' => 'no',        // Geen weeralerts
      'lang' => 'nl',          // Nederlandse labels
    ];

    try {
      // Doe de HTTP GET request
      $response = $this->httpClient->request('GET', $endpoint, ['query' => $query, 'timeout' => 6]);

      // Check of de API succesvol reageert
      if ($response->getStatusCode() !== 200) {
        throw new \Exception('Non-200 response: ' . $response->getStatusCode());
      }

      // Decode JSON response naar PHP array
      $data = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (RequestException $e) {
      // Fout bij HTTP request, log het
      \Drupal::logger('weerblok')->error('HTTP error bij WeatherAPI: @m', ['@m' => $e->getMessage()]);
      return [
        '#markup' => '<div class="weerblok error">' . Html::escape($this->t('Weerdata tijdelijk niet beschikbaar (HTTP error).')) . '</div>',
      ];
    }
    catch (\Exception $e) {
      // Algemene fout (bijv. JSON parse error)
      \Drupal::logger('weerblok')->error('Fout bij WeatherAPI: @m', ['@m' => $e->getMessage()]);
      return [
        '#markup' => '<div class="weerblok error">' . Html::escape($this->t('Weerdata tijdelijk niet beschikbaar.')) . '</div>',
      ];
    }

    // Start HTML voor de weerkaarten
    $cards_html = '';

    // Controleer of forecast aanwezig is en een array is
    if (!empty($data['forecast']['forecastday']) && is_array($data['forecast']['forecastday'])) {
      foreach ($data['forecast']['forecastday'] as $day) {
        // Haal datum en formatteer voor weergave
        $date = $day['date'] ?? '';
        $formatted = \Drupal::service('date.formatter')->format(strtotime($date), 'custom', 'D d M');

        // Haal temperaturen en conditie
        $maxt = isset($day['day']['maxtemp_c']) ? round($day['day']['maxtemp_c']) . '°C' : '';
        $mint = isset($day['day']['mintemp_c']) ? round($day['day']['mintemp_c']) . '°C' : '';
        $cond = $day['day']['condition']['text'] ?? '';
        $icon = $day['day']['condition']['icon'] ?? '';

        // Zorg dat icon altijd een volledige URL is
        if (!empty($icon) && strpos($icon, '//') === 0) {
          $icon = 'https:' . $icon;
        }
        elseif (!empty($icon) && strpos($icon, 'http') !== 0) {
          $icon = 'https:' . $icon;
        }

        // Bouw de HTML voor één dag
        $cards_html .= '<div class="weer-day">';
        $cards_html .= '<div class="weer-day-name">' . Html::escape($formatted) . '</div>';
        if (!empty($icon)) {
          $cards_html .= '<img class="weer-icon" src="' . Html::escape($icon) . '" alt="' . Html::escape($cond) . '">';
        }
        $cards_html .= '<div class="weer-day-temp">' . Html::escape($maxt ?: $mint) . '</div>';
        $cards_html .= '<div class="weer-day-cond">' . Html::escape($cond) . '</div>';
        $cards_html .= '</div>';
      }
    }
    else {
      // Geen forecast beschikbaar, toon fallback
      return [
        '#markup' => '<div class="weerblok">' . Html::escape($this->t('Geen weersvoorspelling beschikbaar.')) . '</div>',
      ];
    }

    // Render het block met alle dagen
    return [
      '#type' => 'markup',
      '#markup' => '<div class="weerblok"><h3 class="weerblok-title">' . $this->t('Weer — @place', ['@place' => $location]) . '</h3><div class="weer-forecast">' . $cards_html . '</div></div>',
      '#cache' => [
        'max-age' => 900,           // Cache 15 minuten, daarna opnieuw API-aanroep
        'contexts' => ['url.query_args'], // Houd rekening met query parameters (later dynamische locatie)
      ],
      '#attached' => [
        'library' => ['weerblok/weerblok.styles'], // Voeg eigen CSS/JS toe
      ],
    ];
  }

}
