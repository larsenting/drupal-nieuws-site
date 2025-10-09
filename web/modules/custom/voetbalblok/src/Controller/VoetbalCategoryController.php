<?php

namespace Drupal\voetbalblok\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Utility\Html;

/**
 * Controller that renders extra voetbalinformatie on /categorie/voetbal.
 */
class VoetbalCategoryController extends ControllerBase {

  protected ClientInterface $httpClient;
  protected $settings;

  protected string $fallbackKey = '15f6c4cec3msh10ed9ea02bba506p104697jsn4aca691e7a00';
  protected string $fallbackHost = 'free-api-live-football-data.p.rapidapi.com';

  public function __construct(ClientInterface $http_client, $settings) {
    $this->httpClient = $http_client;
    $this->settings = $settings;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('settings')
    );
  }

  protected function getRapidApiKey(): ?string {
    try {
      if (method_exists($this->settings, 'get')) {
        $k = $this->settings->get('voetbalblok.rapidapi_key');
        if (!empty($k)) {
          return $k;
        }
      }
    } catch (\Throwable $e) {}
    $env = getenv('VOETBALBLOK_RAPIDAPI_KEY') ?: getenv('RAPIDAPI_KEY');
    if (!empty($env)) {
      return $env;
    }
    return $this->fallbackKey;
  }

  protected function getRapidApiHost(): string {
    try {
      if (method_exists($this->settings, 'get')) {
        $h = $this->settings->get('voetbalblok.rapidapi_host');
        if (!empty($h)) {
          return $h;
        }
      }
    } catch (\Throwable $e) {}
    return getenv('VOETBALBLOK_RAPIDAPI_HOST') ?: getenv('RAPIDAPI_HOST') ?: $this->fallbackHost;
  }

  /**
   * Fetch matches similar to block (reuse logic).
   */
  protected function fetchMatches(string $from, string $to): array {
    $host = $this->getRapidApiHost();
    $key = $this->getRapidApiKey();
    $base = 'https://' . $host;
    $endpoints = ['/matches', '/fixtures', '/livescores'];

    foreach ($endpoints as $endpoint) {
      try {
        $res = $this->httpClient->request('GET', rtrim($base, '/') . $endpoint, [
          'headers' => [
            'x-rapidapi-key' => $key,
            'x-rapidapi-host' => $host,
            'Accept' => 'application/json',
          ],
          'query' => [
            'dateFrom' => $from,
            'dateTo' => $to,
          ],
          'timeout' => 10,
        ]);
        if ($res->getStatusCode() !== 200) {
          continue;
        }
        $json = json_decode($res->getBody()->getContents(), TRUE);
        if (isset($json['matches'])) {
          return $json['matches'];
        }
        if (isset($json['response'])) {
          return $json['response'];
        }
        if (isset($json['data'])) {
          return $json['data'];
        }
        if (is_array($json) && !empty($json)) {
          return $json;
        }
      } catch (\Throwable $e) {
        \Drupal::logger('voetbalblok')->notice('fetchMatches error: @m', ['@m' => $e->getMessage()]);
        continue;
      }
    }
    return [];
  }

  public function content() {
    $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $from = $today->format('Y-m-d');
    $to = $today->modify('+7 days')->format('Y-m-d');

    $matches = $this->fetchMatches($from, $to);

    if (empty($matches)) {
      return [
        '#markup' => '<div class="voetbal-cat-empty">' . Html::escape($this->t('Geen voetbaldata beschikbaar.')) . '</div>',
      ];
    }

    $tz = new \DateTimeZone('Europe/Amsterdam');
    $grouped = [];
    foreach ($matches as $m) {
      if (empty($m['utcDate']) && !empty($m['fixture']['utcDate'])) {
        $utc = $m['fixture']['utcDate'];
      } else {
        $utc = $m['utcDate'] ?? null;
      }
      $dt = $utc ? new \DateTimeImmutable($utc) : new \DateTimeImmutable('now');
      $key = $dt->setTimezone($tz)->format('Y-m-d');
      $grouped[$key][] = $m;
    }

    $out = '<div class="voetbal-cat">';
    $out .= '<h2>' . Html::escape($this->t('Extra voetbalinformatie')) . '</h2>';
    foreach ($grouped as $date => $list) {
      $displayDate = (new \DateTimeImmutable($date))->format('D d M');
      $out .= '<div class="vc-day"><div class="vc-day-title">' . Html::escape($displayDate) . '</div><ul>';
      foreach ($list as $mm) {
        $home = $mm['homeTeam']['name'] ?? ($mm['teams'][0]['name'] ?? ($mm['fixture']['homeTeam']['name'] ?? 'Home'));
        $away = $mm['awayTeam']['name'] ?? ($mm['teams'][1]['name'] ?? ($mm['fixture']['awayTeam']['name'] ?? 'Away'));
        $utc = $mm['utcDate'] ?? ($mm['fixture']['utcDate'] ?? null);
        $time = $utc ? (new \DateTimeImmutable($utc))->setTimezone($tz)->format('H:i') : '';
        $status = $mm['status'] ?? ($mm['fixture']['status'] ?? '');
        $scoreHome = $mm['score']['fullTime']['home'] ?? ($mm['fixture']['result']['home'] ?? null);
        $scoreAway = $mm['score']['fullTime']['away'] ?? ($mm['fixture']['result']['away'] ?? null);

        $scoreText = ($status === 'FINISHED' && $scoreHome !== null && $scoreAway !== null) ? ($scoreHome . ' - ' . $scoreAway) : ($status === 'IN_PLAY' ? $this->t('Live') : ($time ?: $this->t('TBD')));

        $out .= '<li class="vc-match-item"><span class="vc-time">' . Html::escape($time) . '</span> <span class="vc-teams"><strong>' . Html::escape($home) . '</strong> - ' . Html::escape($away) . '</span> <span class="vc-score">' . Html::escape($scoreText) . '</span></li>';
      }
      $out .= '</ul></div>';
    }
    $out .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => $out,
      '#attached' => [
        'library' => ['voetbalblok/voetbalblok.styles'],
      ],
      '#cache' => [
        'max-age' => 120,
      ],
    ];
  }
}
