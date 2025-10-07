<?php

namespace Drupal\feedbackblok\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Render\Markup;

/**
 * Provides a 'Feedbackblok' Block.
 *
 * @Block(
 *   id = "feedbackblok",
 *   admin_label = @Translation("Feedbackblok"),
 *   category = @Translation("Custom")
 * )
 */
class FeedbackblokBlock extends BlockBase {

  public function build() {
    $html = '
      <div class="feedback-block">
        <h3>Beoordeel ons</h3>
        <div class="star-rating" data-selected="0">
          <span data-value="1">&#9733;</span>
          <span data-value="2">&#9733;</span>
          <span data-value="3">&#9733;</span>
          <span data-value="4">&#9733;</span>
          <span data-value="5">&#9733;</span>
        </div>
      </div>
    ';

    return [
      '#markup' => Markup::create($html),
      '#attached' => [
        'library' => [
          'feedbackblok/feedbackblok.styles',
          'feedbackblok/feedbackblok.script',
        ],
      ],
    ];
  }

  public function getCacheMaxAge() {
    return 0;
  }
}
