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
        <h3 id="output">Beoordeling is: 0/5</h3>
        <div class="star-rating">
          <span onclick="gfg(5)" class="star">&#9733;</span>
          <span onclick="gfg(4)" class="star">&#9733;</span>
          <span onclick="gfg(3)" class="star">&#9733;</span>
          <span onclick="gfg(2)" class="star">&#9733;</span>
          <span onclick="gfg(1)" class="star">&#9733;</span>
        </div>
        <button class="send-review-btn" type="button">Verstuur</button>
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
