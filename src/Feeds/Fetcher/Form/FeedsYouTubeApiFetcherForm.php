<?php

namespace Drupal\feeds_youtube_api_parser\Feeds\Fetcher\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds\Plugin\Type\ExternalPluginFormBase;

/**
 * The configuration form for YouTube fetchers.
 */
class FeedsYouTubeApiFetcherForm extends ExternalPluginFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = $this->plugin->getConfiguration();

    $form['google_developer_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Google API Key'),
      '#default_value' => $config['google_developer_key'],
      '#required' => TRUE,
    ];
    $form['import_video_limit'] = array(
      '#type' => 'number',
      '#min' => 1,
      '#title' => t('Limit the total number of imported videos'),
      '#description' => t('Specify a limit for the total number of videos to import from YouTube.'),
      '#default_value' => $config['import_video_limit'],
      '#required' => TRUE,
    );
    $form['results_per_page'] = array(
      '#type' => 'number',
      '#min' => 1,
      '#max' => 50,
      '#title' => $this->t('Limit videos per API request'),
      '#description' => $this->t('Limit the number of retrieved videos per API request.'),
      '#default_value' => $config['results_per_page'],
      '#required' => TRUE,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Trim all values before saving.
    $values = $form_state->getValues();
    foreach ($values as &$value) {
      if (is_string($value)) {
        $value = trim($value);
      }
    }
    $this->plugin->setConfiguration($values);
  }

}
