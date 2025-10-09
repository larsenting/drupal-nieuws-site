<?php

namespace Drupal\voetbalblok\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Utility\Html;

/**
 * Provides a 'Voetbalblok' block (RapidAPI compatible).
 *
 * @Block(
 *   id = "voetbalblok",
 *   admin_label = @Translation("Voetbalblok"),
 *   category = @Translation("Custom")
 * )
 */
class VoetbalblokBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected ClientInterface $httpClient;
  protected $settings;

  // Fallback key/host — zet in settings.local.php in plaats van hardcoden.
  protected string $fallbackKey = '15f6c4cec3msh10ed9ea02bba506p104697jsn4aca691e7a00';
  protected string $fallbackHost = 'free-api-live-football-data.p.rapidapi.com';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client, $settings) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
    $this->settings = $settings;
  }

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
   * Haal RapidAPI key uit settings / env / fallback.
   */
  protected function getRapidApiKey(): ?string {
    try {
      if (is_object($this->settings) && method_exists($this->settings, 'get')) {
        $k = $this->settings->get('voetbalblok.rapidapi_key');
        if (!empty($k)) {
          return $k;
        }
        $k2 = $this->settings->get('voetbalblok.api_key');
        if (!empty($k2)) {
          return $k2;
        }
      }
    } catch (\Throwable $e) {
      // ignore
    }
    $env = getenv('VOETBALBLOK_RAPIDAPI_KEY') ?: getenv('RAPIDAPI_KEY');
    return $env ?: $this->fallbackKey;
  }

  protected function getRapidApiHost(): string {
    try {
      if (is_object($this->settings) && method_exists($this->settings, 'get')) {
        $h = $this->settings->get('voetbalblok.rapidapi_host');
        if (!empty($h)) {
          return $h;
        }
      }
    } catch (\Throwable $e) {
      // ignore
    }
    $env = getenv('VOETBALBLOK_RAPIDAPI_HOST') ?: getenv('RAPIDAPI_HOST');
    return $env ?: $this->fallbackHost;
  }

  /**
   * Probeer meerdere endpoint-varianten en normalizeer response naar matches array.
   */
  protected function fetchMatchesRapidApi(string $from, string $to): array {
    $host = $this->getRapidApiHost();
    $key = $this->getRapidApiKey();
    $base = 'https://' . rtrim($host, '/');

    $endpoints = [
      '/matches',
      '/fixtures',
      '/livescores',
      '/fixtures/date',
    ];

    foreach ($endpoints as $ep) {
      $url = $base . $ep;
      try {
        $response = $this->httpClient->request('GET', $url, [
          'headers' => [
            'x-rapidapi-key' => $key,
            'x-rapidapi-host' => $host,
            'Accept' => 'application/json',
          ],
          'query' => [
            'dateFrom' => $from,
            'dateTo' => $to,
          ],
          'timeout' => 8,
        ]);

        if ($response->getStatusCode() !== 200) {
          continue;
        }

        $json = json_decode($response->getBody()->getContents(), TRUE);

        if (isset($json['matches']) && is_array($json['matches'])) {
          return $json['matches'];
        }
        if (isset($json['response']) && is_array($json['response'])) {
          return $json['response'];
        }
        if (isset($json['data']) && is_array($json['data'])) {
          return $json['data'];
        }
        if (isset($json['items']) && is_array($json['items'])) {
          return $json['items'];
        }
        if (is_array($json)) {
          $first = reset($json);
          if (is_array($first) && (isset($first['homeTeam']) || isset($first['teams']) || isset($first['fixture']) || isset($first['match']))) {
            return $json;
          }
        }
      } catch (\Throwable $e) {
        \Drupal::logger('voetbalblok')->notice('RapidAPI endpoint ' . $ep . ' gaf fout: @m', ['@m' => $e->getMessage()]);
        continue;
      }
    }

    return [];
  }

  /**
   * Normaliseer één match naar vaste keys.
   */
  protected function normalizeMatch(array $m): array {
    // home/away names: veel vormen mogelijk, probeer meerdere paden
    $home = $m['homeTeam']['name'] ?? $m['teams']['home']['name'] ?? $m['teams'][0]['name'] ?? $m['fixture']['homeTeam']['name'] ?? $m['match']['home'] ?? ($m['teamHome']['name'] ?? 'Home');
    $away = $m['awayTeam']['name'] ?? $m['teams']['away']['name'] ?? $m['teams'][1]['name'] ?? $m['fixture']['awayTeam']['name'] ?? $m['match']['away'] ?? ($m['teamAway']['name'] ?? 'Away');

    $status = $m['status'] ?? $m['fixture']['status'] ?? $m['match']['status'] ?? ($m['matchStatus'] ?? '');

    $utc = $m['utcDate'] ?? $m['fixture']['utcDate'] ?? $m['match']['utcDate'] ?? $m['date'] ?? null;

    $scoreHome = $m['score']['fullTime']['home'] ?? $m['fixture']['result']['home'] ?? $m['goalsHomeTeam'] ?? ($m['match']['score']['home'] ?? null);
    $scoreAway = $m['score']['fullTime']['away'] ?? $m['fixture']['result']['away'] ?? $m['goalsAwayTeam'] ?? ($m['match']['score']['away'] ?? null);

    $competition = $m['competition']['name'] ?? $m['league']['name'] ?? $m['competition_name'] ?? '';

    return [
      'home' => $home,
      'away' => $away,
      'status' => (string) $status,
      'utc' => $utc,
      'score_home' => $scoreHome,
      'score_away' => $scoreAway,
      'competition' => $competition,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Periode: gisteren -> morgen
    $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $from = $today->modify('-1 day')->format('Y-m-d');
    $to = $today->modify('+1 day')->format('Y-m-d');

    $matches = $this->fetchMatchesRapidApi($from, $to);

    $normalized = [];
    foreach ($matches as $m) {
      if (is_array($m)) {
        $normalized[] = $this->normalizeMatch($m);
      }
    }

    // 1) Laatste uitslagen: filter FINISHED, sorteer op utc desc en neem 3
    $finished = array_filter($normalized, function ($n) {
      $status = strtoupper((string) ($n['status'] ?? ''));
      return (strpos($status, 'FINISH') !== FALSE) || ($status === 'FINISHED') || ($status === 'FT');
    });

    usort($finished, function ($a, $b) {
      $ta = !empty($a['utc']) ? strtotime($a['utc']) : 0;
      $tb = !empty($b['utc']) ? strtotime($b['utc']) : 0;
      return $tb <=> $ta;
    });

    $lastResults = array_slice($finished, 0, 3);

    // 2) News (max 2): finished recent matches or scheduled (reuse previous logic)
    $news = [];
    foreach ($normalized as $n) {
      if (count($news) >= 2) {
        break;
      }
      $status = strtoupper((string) ($n['status'] ?? ''));
      if (($status === 'FINISHED' || strpos($status, 'FINISH') !== FALSE) && $n['score_home'] !== null && $n['score_away'] !== null) {
        $news[] = [
          'title' => "{$n['home']} verslaat {$n['away']} {$n['score_home']}-{$n['score_away']}",
          'intro' => $n['competition'] ?? '',
        ];
      }
    }
    if (empty($news)) {
      foreach (array_slice($normalized, 0, 2) as $n) {
        $news[] = [
          'title' => "{$n['home']} - {$n['away']}",
          'intro' => $n['competition'] ?? '',
        ];
      }
    }

    // 3) Scores (max 3): in_play first, then finished, then scheduled
    $scores = [];
    foreach ($normalized as $n) {
      if (count($scores) >= 3) {
        break;
      }
      $status = strtoupper((string) ($n['status'] ?? ''));
      if ($status === 'IN_PLAY') {
        $scores[] = [
          'match' => "{$n['home']} - {$n['away']}",
          'score' => ($n['score_home'] !== null && $n['score_away'] !== null) ? "{$n['score_home']} - {$n['score_away']}" : $this->t('Live'),
          'status' => 'IN_PLAY',
        ];
      }
    }
    // add finished if still space
    foreach ($normalized as $n) {
      if (count($scores) >= 3) {
        break;
      }
      $status = strtoupper((string) ($n['status'] ?? ''));
      if (($status === 'FINISHED' || strpos($status, 'FINISH') !== FALSE) && $n['score_home'] !== null && $n['score_away'] !== null) {
        $scores[] = [
          'match' => "{$n['home']} - {$n['away']}",
          'score' => "{$n['score_home']} - {$n['score_away']}",
          'status' => 'FINISHED',
        ];
      }
    }
    // fill with scheduled if still space
    foreach ($normalized as $n) {
      if (count($scores) >= 3) {
        break;
      }
      $scores[] = [
        'match' => "{$n['home']} - {$n['away']}",
        'score' => (!empty($n['utc']) ? (new \DateTimeImmutable($n['utc']))->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('H:i') : $this->t('TBD')),
        'status' => strtoupper((string) ($n['status'] ?? '')),
      ];
    }

    // Fallback placeholders if no data at all
    if (empty($lastResults) && empty($scores) && empty($news)) {
      \Drupal::logger('voetbalblok')->notice('Voetbalblok: geen data gevonden in API response.');
      $scores = [
        ['match' => 'Ajax - PSV', 'score' => $this->t('TBD'), 'status' => 'SCHEDULED'],
        ['match' => 'Feyenoord - AZ', 'score' => $this->t('TBD'), 'status' => 'SCHEDULED'],
        ['match' => 'FC Groningen - Sparta', 'score' => $this->t('TBD'), 'status' => 'SCHEDULED'],
      ];
      $news = [
        ['title' => $scores[0]['match'], 'intro' => $this->t('Aankomend')],
        ['title' => $scores[1]['match'], 'intro' => $this->t('Aankomend')],
      ];
    }

    // Build compact sidebar markup
    $module_path = \Drupal::service('extension.list.module')->getPath('voetbalblok');
    $icon_path = '/' . $module_path . '/images/voetbal-icon.svg';

    $output = '<div class="voetbalblok">';
    $output .= '<div class="vb-header">';
    $output .= '<img src="' . Html::escape($icon_path) . '" alt="Voetbal" class="vb-icon" />';
    $output .= '<h3 class="vb-title">' . $this->t('Voetbal') . '</h3>';
    $output .= '</div>';

    // Laatste uitslagen (3)
    $output .= '<div class="vb-section vb-results"><h4>' . $this->t('Laatste uitslagen') . '</h4><ul class="vb-results-list">';
    foreach ($lastResults as $r) {
      $time = '';
      if (!empty($r['utc'])) {
        try {
          $time = (new \DateTimeImmutable($r['utc']))->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('d M H:i');
        } catch (\Throwable $e) {
          $time = '';
        }
      }
      $scoreText = ($r['score_home'] !== null && $r['score_away'] !== null) ? ($r['score_home'] . ' - ' . $r['score_away']) : $this->t('n.v.t.');
      $output .= '<li class="vb-result-item"><span class="vb-result-match">'. Html::escape("{$r['home']} - {$r['away']}") .'</span> <span class="vb-result-score">'. Html::escape($scoreText) .'</span> <div class="vb-result-time">'. Html::escape($time) .'</div></li>';
    }
    if (empty($lastResults)) {
      $output .= '<li class="vb-result-item">' . Html::escape($this->t('Geen recente uitslagen.')) . '</li>';
    }
    $output .= '</ul></div>';

    // News
    $output .= '<div class="vb-section vb-news"><h4>' . $this->t('Laatste nieuws') . '</h4>';
    foreach ($news as $n) {
      $output .= '<div class="vb-news-item">';
      $output .= '<strong class="vb-news-title">' . Html::escape($n['title']) . '</strong>';
      $output .= '<div class="vb-news-intro">' . Html::escape($n['intro']) . '</div>';
      $output .= '</div>';
    }
    $output .= '</div>';

    // Short scores
    $output .= '<div class="vb-section vb-scores"><h4>' . $this->t('Uitslagen / Live') . '</h4>';
    $output .= '<ul class="vb-score-list">';
    foreach ($scores as $s) {
      $output .= '<li class="vb-score-item">';
      $output .= '<span class="vb-match">' . Html::escape($s['match']) . '</span>';
      $output .= ' <span class="vb-score">' . Html::escape($s['score']) . '</span>';
      $output .= ' <span class="vb-status">(' . Html::escape($s['status']) . ')</span>';
      $output .= '</li>';
    }
    $output .= '</ul></div>';

    $output .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => $output,
      '#cache' => [
        'max-age' => 60,
      ],
      '#attached' => [
        'library' => ['voetbalblok/voetbalblok.styles'],
      ],
    ];
  }

}
