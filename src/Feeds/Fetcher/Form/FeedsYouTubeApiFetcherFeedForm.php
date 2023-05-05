<?php

namespace Drupal\feeds_youtube_api_parser\Feeds\Fetcher\Form;

use Google\Exception as GoogleException;
use Google\Service\Exception as GoogleServiceException;
use Drupal\feeds\FeedInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\feeds\Plugin\Type\FeedsPluginInterface;
use Drupal\feeds\Plugin\Type\ExternalPluginFormBase;
use Drupal\feeds_youtube_api_parser\Exception\YouTubeApiException;

/**
 * Provides a form on the feed edit page for the FeedsYouTubeFetcher.
 */
class FeedsYouTubeApiFetcherFeedForm extends ExternalPluginFormBase {

  /**
   * The Feeds plugin.
   *
   * @var \Drupal\feeds\Plugin\Type\FeedsPluginInterface
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  public function setPlugin(FeedsPluginInterface $plugin) {
    $this->plugin = $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FeedInterface $feed = NULL) {
    $form['source'] = [
      '#title' => $this->t('Playlist ID'),
      '#type' => 'textfield',
      '#default_value' => $feed->getSource(),
      '#maxlength' => 2048,
      '#description' => $this->t('Input the target playlist ID. You can use the "uploads" playlist of a user if you want to have all of its videos listed'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state, FeedInterface $feed = NULL) {
    $source = $form_state->getValue('source');

    try {
      $test = $this->plugin->testApi($source);
    } catch (YouTubeApiException $e) {
      $form_state->setError($form['source'], $this->t('A YouTube API error occurred: %error', [
        '%error' => $e->getMessage()
      ]));
    } catch (GoogleServiceException $e) {
      $form_state->setError($form['source'], $this->t('A service error occurred: %error', [
        '%error' => $e->getMessage()
      ]));
    } catch (GoogleException $e) {
      $form_state->setError($form['source'], $this->t('A client error occurred: %error', [
        '%error' => $e->getMessage()
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state, FeedInterface $feed = NULL) {
    $feed->setSource($form_state->getValue('source'));
  }
}
