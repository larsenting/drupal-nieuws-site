<?php 

namespace Web\Modules\Custom\Feedbackblok\Src\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;

class Feedbackblokblock extends BlockBase implements ContainerFactoryPluginInterface 