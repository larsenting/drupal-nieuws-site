<?php

namespace Drupal\voetbalblok\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Site\Settings;

/**
 * Controller for extra voetbalinformatie op de categoriepagina.
 */
class VoetbalCategoryController extends ControllerBase {

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * @var \Drupal\Core\Site\Settings
   */
  protected Settings $settings;

  /**
   * API key fallback.
   */
  protected string $fallbackApiKey = 'e23c33ccb028410799a7220b54aec3bb';

  public function __construct(ClientInterface $http_client, Settings $settings) {
    $this->httpClient = $http_client;
    $this->settings = $settings;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('settings')
    );
  }

  /**
   * Haalt en toont extra voetbalinformatie.
   */
  public function content() {
    $apiKey = $this->getApiKey();
    if (empty($apiKey)) {
      return [
        '#markup' => '<div class="voetbal-cat-error">' . Html::escape($this->t('Voetbal API-sleutel ontbreekt.')) . '</div>',
      ];
    }

    $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $dateFrom = $today->format('Y-m-d');
    $dateTo = $today->modify('+7 days')->format('Y-m-d');

    $endpoint = 'https://api.football-data.org/v4/matches';
    $headers = ['X-Auth-Token' => $apiKey, 'Accept' => 'application/json'];

    try {
      $response = $this->httpClient->request('GET', $endpoint, [
        'headers' => $headers,
        'query' => ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
        'timeout' => 10,
      ]);
      $payload = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Throwable $e) {
      \Drupal::logger('voetbalblok')->error('Error fetching voetbal data: @m', ['@m' => $e->getMessage()]);
      return [
        '#markup' => '<div class="voetbal-cat-error">' . Html::escape($this->t('Voetbaldata tijdelijk niet beschikbaar.')) . '</div>',
      ];
    }

    $matches = $payload['matches'] ?? [];
    if (empty($matches)) {
      return ['#markup' => '<div class="voetbal-cat-empty">' . Html::escape($this->t('Geen wedstrijden gevonden.')) . '</div>'];
    }

    // Groeperen per dag (NL tijdzone)
    $tz = new \DateTimeZone('Europe/Amsterdam');
    $grouped = [];
    foreach ($matches as $m) {
      if (empty($m['utcDate'])) {
        continue;
      }
      $dt = new \DateTimeImmutable($m['utcDate']);
      $key = $dt->setTimezone($tz)->format('Y-m-d');
      $grouped[$key][] = $m;
    }

    // Build markup
    $out = '<div class="voetbal-cat">';
    $out .= '<h2>' . Html::escape($this->t('Extra voetbalinformatie')) . '</h2>';
    foreach ($grouped as $date => $list) {
      $displayDate = (new \DateTimeImmutable($date))->format('D d M');
      $out .= '<div class="vc-day"><div class="vc-day-title">' . Html::escape($displayDate) . '</div><ul>';
      foreach ($list as $match) {
        $home = $match['homeTeam']['name'] ?? 'Thuis';
        $away = $match['awayTeam']['name'] ?? 'Uit';
        $dt = new \DateTimeImmutable($match['utcDate']);
        $time = $dt->setTimezone($tz)->format('H:i');

        $status = $match['status'] ?? '';
        $scoreHome = $match['score']['fullTime']['home'] ?? '';
        $scoreAway = $match['score']['fullTime']['away'] ?? '';
        $scoreText = ($status === 'FINISHED') ? "$scoreHome - $scoreAway" : $time;

        $out .= '<li class="vc-match-item">'
          . Html::escape($home) . ' - ' . Html::escape($away)
          . ' (' . Html::escape($scoreText) . ')</li>';
      }
      $out .= '</ul></div>';
    }
    $out .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => $out,
      '#attached' => ['library' => ['voetbalblok/voetbalblok.styles']],
    ];
  }

  protected function getApiKey(): ?string {
    $k = $this->settings->get('voetbalblok.api_key');
    if (!empty($k)) {
      return $k;
    }
    $env = getenv('VOETBALBLOK_API_KEY');
    if (!empty($env)) {
      return $env;
    }
    return $this->fallbackApiKey;
  }

}
