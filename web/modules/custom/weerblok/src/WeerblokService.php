<?php

namespace Drupal\weerblok;

use GuzzleHttp\ClientInterface;

/**
 * Service om weerdata op te halen.
 */
class WeerblokService {

  protected ClientInterface $httpClient;
  protected string $apiKey;

  public function __construct(ClientInterface $http_client, string $api_key) {
    $this->httpClient = $http_client;
    $this->apiKey = $api_key;
  }

  /**
   * Haal weerdata op van WeatherAPI.
   */
  public function getWeerData(string $stad = 'Amsterdam'): ?array {
    $url = 'http://api.weatherapi.com/v1/current.json';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => [
          'key' => $this->apiKey,
          'q' => $stad,
          'aqi' => 'no',
        ],
      ]);

      if ($response->getStatusCode() === 200) {
        return json_decode($response->getBody()->getContents(), true);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('weerblok')->error('Fout bij ophalen weerdata: @msg', ['@msg' => $e->getMessage()]);
    }

    return NULL;
  }

  public function getWeerWeek(string $stad = 'Assen'): ?array {
    $url = 'http://api.weatherapi.com/v1/forecast.json';
    try {
        $response = $this->httpClient->request('GET', $url, [
            'query' => [
                'key' => $this->apiKey,
                'q' => $stad,
                'days' => 7,
                'aqi' => 'no',
                'alerts' => 'no',
            ],
        ]);

        if ($response->getStatusCode() === 200) {
            return json_decode($response->getBody()->getContents(), true);
        }
    }
    catch (\Exception $e) {
        \Drupal::logger('weerblok')->error('Fout bij ophalen weekweer: @msg', ['@msg' => $e->getMessage()]);
    }

    return NULL;
}


}
