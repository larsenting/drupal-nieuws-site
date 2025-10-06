<?php

namespace Drupal\voetbalblok\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;

/**
 * Block dat voetbalwedstrijden toont via football-data.org.
 *
 * Dit block haalt live voetbaldata op (uitslagen, live scores, nieuwsachtig overzicht)
 * en presenteert deze in een sidebar-achtig component. Het combineert API calls,
 * eenvoudige data parsing en een render array die door Drupal in HTML wordt omgezet.
 */
class VoetbalblokBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * HTTP client service.
   *
   * Deze client wordt gebruikt om de externe API aan te roepen. Drupal levert
   * Guzzle standaard mee, zodat we geen eigen cURL-code hoeven te schrijven.
   */
  protected ClientInterface $httpClient;

  /**
   * Settings service.
   *
   * Via deze service halen we configuratiewaarden op uit settings.php of
   * settings.local.php. Hierdoor kunnen API keys veilig buiten de code
   * bewaard worden, wat veiliger en flexibeler is.
   */
  protected $settings;

  /**
   * Fallback API key.
   *
   * Wordt alleen gebruikt als er nergens anders een API key beschikbaar is.
   * Handig in een lokale ontwikkelomgeving of om te zorgen dat het block
   * nooit volledig breekt.
   */
  protected string $fallbackApiKey = 'e23c33ccb028410799a7220b54aec3bb';

  /**
   * Constructor.
   *
   * Ontvangt alle services en instellingen die dit block nodig heeft en
   * bewaart ze in properties voor later gebruik. Dependency injection zorgt
   * ervoor dat de code testbaar en uitbreidbaar blijft.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client, $settings) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
    $this->settings = $settings;
  }

  /**
   * Factory methode voor dependency injection.
   *
   * Drupal roept deze methode aan wanneer het block-object wordt aangemaakt.
   * Hier halen we de juiste services (zoals http_client en settings) uit de
   * service container en geven we die door aan de constructor.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('settings')
    );
  }

  /**
   * Bepaalt welke API key gebruikt moet worden.
   *
   * De volgorde is bewust opgebouwd: eerst wordt gekeken of er configuratie
   * aanwezig is, daarna in settings.php, vervolgens in environment variables
   * en als laatste wordt de fallback key gebruikt. Zo heeft een beheerder of
   * ontwikkelaar meerdere manieren om de key in te stellen.
   */
  protected function getApiKey(): string {
    try {
      $config = \Drupal::configFactory()->get('voetbalblok.settings');
      if ($config && $config->get('api_key')) {
        return $config->get('api_key');
      }
    } catch (\Throwable $e) {
      // Config niet beschikbaar of niet ingesteld.
    }

    try {
      $key = $this->settings->get('voetbalblok.api_key');
      if (!empty($key)) {
        return $key;
      }
    } catch (\Throwable $e) {
      // Settings niet beschikbaar, ga verder naar de volgende bron.
    }

    $envKeys = ['VOETBALBLOK_API_KEY', 'FOOTBALL_DATA_API_KEY', 'FOOTBALLDATA_KEY'];
    foreach ($envKeys as $envKey) {
      if ($val = getenv($envKey)) {
        return $val;
      }
    }

    return $this->fallbackApiKey;
  }

  /**
   * Bouwt de weergave van het block.
   *
   * Deze methode wordt aangeroepen telkens wanneer het block gerenderd wordt.
   * Hier wordt de API aangeroepen, de data verwerkt en omgezet naar HTML die
   * in de frontend getoond kan worden.
   */
  public function build() {
    $apiKey = $this->getApiKey();

    if (empty($apiKey)) {
      // Als er helemaal geen API key beschikbaar is, krijgt de gebruiker
      // een nette melding in plaats van een blanco block.
      $message = $this->t('Voetbalblok is niet geconfigureerd. Voeg een API key toe in settings.php of als omgevingsvariabele.');
      return [
        '#markup' => '<div class="voetbalblok error">' . Html::escape($message) . '</div>',
      ];
    }

    // Bepaal de periode waarover wedstrijden opgehaald worden. We kiezen hier
    // bewust voor enkele dagen terug tot morgen, zodat recente uitslagen en
    // komende wedstrijden zichtbaar zijn.
    $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $from = $today->modify('-2 days')->format('Y-m-d');
    $to = $today->modify('+1 day')->format('Y-m-d');

    $endpoint = 'https://api.football-data.org/v4/matches';
    $query = ['dateFrom' => $from, 'dateTo' => $to];
    $headers = [
      'X-Auth-Token' => $apiKey,
      'Accept' => 'application/json',
    ];

    try {
      // Vraag de wedstrijden op via de API.
      $response = $this->httpClient->request('GET', $endpoint, [
        'headers' => $headers,
        'query' => $query,
        'timeout' => 8,
      ]);

      if ($response->getStatusCode() !== 200) {
        throw new \Exception('football-data.org returned status ' . $response->getStatusCode());
      }

      // Decode de JSON response naar een PHP array.
      $payload = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Throwable $e) {
      // Als er iets misgaat loggen we dit en tonen we een nette foutmelding
      // aan de bezoeker. Zo blijft de site stabiel, zelfs als de API niet werkt.
      \Drupal::logger('voetbalblok')->error('Fout bij ophalen voetbaldata: @m', ['@m' => $e->getMessage()]);
      return [
        '#markup' => '<div class="voetbalblok"><div class="vb-header"><h3>' . $this->t('Voetbal') . '</h3></div><div class="vb-error">' . Html::escape($this->t('Geen verbinding met voetbal-API.')) . '</div></div>',
        '#cache' => ['max-age' => 60],
      ];
    }

    // Verwerk de lijst met wedstrijden uit de API.
    // We onderscheiden nieuwswaardige items (afgelopen wedstrijden)
    // en live/score items (voor snelle blik).
    $matches = $payload['matches'] ?? [];
    $news = [];
    $scores = [];

    foreach ($matches as $match) {
      if (count($news) >= 2 && count($scores) >= 5) {
        break; // Hou de lijst beperkt om het block compact te houden
      }

      $home = $match['homeTeam']['name'] ?? 'Home';
      $away = $match['awayTeam']['name'] ?? 'Away';
      $status = $match['status'] ?? '';
      $scoreHome = $match['score']['fullTime']['home'] ?? null;
      $scoreAway = $match['score']['fullTime']['away'] ?? null;

      if ($status === 'FINISHED' && $scoreHome !== null && $scoreAway !== null && count($news) < 2) {
        $title = "$home verslaat $away $scoreHome-$scoreAway";
        $intro = $match['competition']['name'] ?? $this->t('Competitie');
        $news[] = ['title' => $title, 'intro' => $intro];
      }

      if (count($scores) < 5) {
        $scores[] = [
          'match' => "$home - $away",
          'score' => ($scoreHome !== null && $scoreAway !== null) ? "$scoreHome - $scoreAway" : ($status === 'IN_PLAY' ? $this->t('Live') : $this->t('TBD')),
          'status' => $status,
        ];
      }
    }

    // Als er geen nieuws gevonden is, vullen we dit op met geplande wedstrijden.
    if (empty($news)) {
      foreach (array_slice($matches, 0, 2) as $match) {
        $home = $match['homeTeam']['name'] ?? 'Home';
        $away = $match['awayTeam']['name'] ?? 'Away';
        $title = "$home - $away";
        $intro = $match['competition']['name'] ?? $this->t('Competitie');
        $news[] = ['title' => $title, 'intro' => $intro];
      }
    }

    // Bouw de HTML output samen. Dit gebeurt hier als string, maar zou
    // in een geavanceerdere versie ook via een Twig template kunnen.
    $module_path = \Drupal::service('extension.list.module')->getPath('voetbalblok');
    $icon_path = '/' . $module_path . '/images/voetbal-icon.svg';

    $output = '<div class="voetbalblok">';
    $output .= '<div class="vb-header">';
    $output .= '<img src="' . Html::escape($icon_path) . '" alt="Voetbal" class="vb-icon" />';
    $output .= '<h3 class="vb-title">' . $this->t('Voetbal') . '</h3>';
    $output .= '</div>';

    $output .= '<div class="vb-section vb-news"><h4>' . $this->t('Laatste nieuws') . '</h4>';
    foreach ($news as $n) {
      $output .= '<div class="vb-news-item">';
      $output .= '<strong class="vb-news-title">' . Html::escape($n['title']) . '</strong>';
      $output .= '<div class="vb-news-intro">' . Html::escape($n['intro']) . '</div>';
      $output .= '</div>';
    }
    $output .= '</div>';

    $output .= '<div class="vb-section vb-scores"><h4>' . $this->t('Uitslagen / Live') . '</h4><ul class="vb-score-list">';
    foreach ($scores as $s) {
      $output .= '<li class="vb-score-item">';
      $output .= '<span class="vb-match">' . Html::escape($s['match']) . '</span>';
      $output .= ' <span class="vb-score">' . Html::escape($s['score']) . '</span>';
      $output .= ' <span class="vb-status">(' . Html::escape($s['status']) . ')</span>';
      $output .= '</li>';
    }
    $output .= '</ul></div>';

    $output .= '</div>';

    // Retourneer een render array. Hiermee kan Drupal caching toepassen
    // en CSS/JS libraries koppelen voor een consistente presentatie.
    return [
      '#type' => 'markup',
      '#markup' => $output,
      '#cache' => [
        'max-age' => 120, // korte cache omdat sportdata snel verandert
      ],
      '#attached' => [
        'library' => ['voetbalblok/voetbalblok.styles'],
      ],
    ];
  }

}
