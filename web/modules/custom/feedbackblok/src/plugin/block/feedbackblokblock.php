<?php

namespace Web\Modules\Custom\Feedbackblok\Src\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Blok dat het feedbackformulier toont.
 *
 * @Block(
 *   id = "feedbackblok",
 *   admin_label = @Translation("Feedbackblok"),
 *   category = @Translation("Custom")
 * )
 */
class FeedbackblokBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => '
        <div class="feedback-block">
          <h3>Laat een review achter</h3>
          <form class="feedback-form" method="post" onsubmit="event.preventDefault(); alert(\'Bedankt voor je feedback!\'); this.reset();">

            <div class="form-item">
              <label for="review">Review</label><br/>
              <textarea id="review" name="review" rows="3" required></textarea>
            </div>

            <div class="form-item rating">
              <label>Sterren:</label><br/>
              <span class="star-rating">
                <input type="radio" id="5-stars" name="rating" value="5" required/><label for="5-stars">&#9733;</label>
                <input type="radio" id="4-stars" name="rating" value="4"/><label for="4-stars">&#9733;</label>
                <input type="radio" id="3-stars" name="rating" value="3"/><label for="3-stars">&#9733;</label>
                <input type="radio" id="2-stars" name="rating" value="2"/><label for="2-stars">&#9733;</label>
                <input type="radio" id="1-star" name="rating" value="1"/><label for="1-star">&#9733;</label>
              </span>
            </div>

            <button type="submit">Verstuur</button>
          </form>
        </div>
      ',
      '#attached' => [
        'library' => [
          'feedbackblok/feedbackblok.css',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
