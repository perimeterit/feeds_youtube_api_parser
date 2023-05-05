<?php

namespace Drupal\feeds_youtube_api_parser\Feeds\Fetcher;

use Google\Client;
use Google\Service\YouTube;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\StateInterface;
use Google\Service\YouTube\Video;
use Drupal\feeds\Plugin\Type\PluginBase;
use Google\Service\YouTube\PlaylistItem;
use Drupal\feeds\Result\RawFetcherResult;
use Drupal\feeds\Plugin\Type\ClearableInterface;
use Google\Service\YouTube\PlaylistItemListResponse;
use Drupal\feeds\Plugin\Type\Fetcher\FetcherInterface;
use Drupal\feeds_youtube_api_parser\Exception\YouTubeApiException;

/**
 * Constructs FeedsYouTubeApiFetcher object.
 *
 * @FeedsFetcher(
 *   id = "feeds_youtube_api_parser_fetcher",
 *   title = @Translation("YouTube API"),
 *   description = @Translation("Fetch videos from a YouTube playlist"),
 *   form = {
 *     "configuration" = "Drupal\feeds_youtube_api_parser\Feeds\Fetcher\Form\FeedsYouTubeApiFetcherForm",
 *     "feed" = "Drupal\feeds_youtube_api_parser\Feeds\Fetcher\Form\FeedsYouTubeApiFetcherFeedForm",
 *   }
 * )
 */
class FeedsYouTubeApiFetcher extends PluginBase implements ClearableInterface, FetcherInterface {

  /**
   * The Youtube API Client.
   *
   * @var \Google\Service\YouTube
   */
  protected $youtubeClient;

  /**
   * The maximum number of videos to import.
   *
   * @var int
   */
  protected $videoLimit;

  /**
   * The pagination limit for the API requests.
   *
   * @var int
   */
  protected $pageLimit;

  /**
   * The developer API key needed for API calls.
   *
   * @var string
   */
  protected $developerKey;

  /**
   * Constructs an FeedsYouTubeApiFetcher object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param array $plugin_definition
   *   The plugin definition.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->videoLimit = (int) $this->getConfiguration('import_video_limit');
    $this->pageLimit = (int) $this->getConfiguration('results_per_page');
    $this->developerKey = $this->getConfiguration('google_developer_key');
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(FeedInterface $feed, StateInterface $state) {
    $this->youtubeClient = $this->getYoutubeClient();
    $result = $this->get($feed->getSource(), $feed->id());
    if ($result !== FALSE) {
      return new RawFetcherResult($result);
    }
    else {
      return new RawFetcherResult('');
    }
  }

  /**
   * Helper function to get client factory.
   *
   * @param string $id
   *   The feed Id.
   *
   * @return \Google\Service\YouTube
   *   The Google Youtube client.
   *
   * @throws \Drupal\feeds_youtube_api_parser\Exception\YouTubeApiException
   *   Thrown if the developer key is wrong or missing..
   */
  public function getYoutubeClient(): YouTube {
    if (!empty($this->developerKey)) {
      $client = new Client();
      $client->setDeveloperKey($this->developerKey);
      $client->setScopes('https://www.googleapis.com/auth/youtube.readonly');
      $youtube = new YouTube($client);
      return $youtube;
    }
    else {
      throw new YouTubeApiException('Google API developer key is wrong or missing.');
    }
  }

  /**
   * Make the API queries to get the data the parser needs.
   *
   * @param string $source
   *   The URL source.
   * @param string $id
   *   The feed Id.
   *
   * @return string
   *   Returns a JSON-encoded array of stdClass objects.
   */
  public function get(string $source): string {
    $playlistItems = [];
    $videos = [];
    $data = [];

    // Get the number of calls needed to fulfill the quota.
    $requests_number = ceil($this->videoLimit / $this->pageLimit);
    $i = 0;
    $next_page_token = NULL;

    do {
      $playlistItemsResponse = $this->requestNextPage($source, $next_page_token);
      $next_page_token = $playlistItemsResponse->getNextPageToken();
      $playlistItems = array_merge($playlistItems, $playlistItemsResponse->getItems());
      $i++;
    } while ($i < $requests_number && !empty($next_page_token));

    if (!empty($playlistItems)) {
      $videos = $this->getVideosFromPlaylistItems($playlistItems);

      // Unfortunately we had to fetch playlistItems and videos separately so
      // this manual step for "re-joining" them is mandatory.
      foreach ($playlistItems as $playlistItem) {
        $video = $this->findPlaylistItemVideo($playlistItem, $videos);
        $data[] = $this->parseVideoItem($playlistItem, $video);
      }
    }

    return json_encode($data);
  }

  /**
   * Request the next page of playlistItem resources to Youtube Client.
   *
   * @param string $source
   *   The source ID, a playlist.
   * @param string|null $next_page_token
   *   The next page token, if any.
   *
   * @return \Google\Service\YouTube\PlaylistItemListResponse
   *   A response containing the data and pagination tokens.
   */
  protected function requestNextPage(string $source, string $next_page_token = NULL): PlaylistItemListResponse {
    $request_options = [
      'playlistId' => $source,
      'maxResults' => $this->pageLimit,
    ];
    if ($next_page_token) {
      $request_options['pageToken'] = $next_page_token;
    }
    $playlistItemListResponse = $this->youtubeClient->playlistItems->listPlaylistItems('snippet', $request_options);
    return $playlistItemListResponse;
  }

  /**
   * Perform an unexpensive query over the source playlist to test endpoint.
   *
   * @param string $source
   *   A playlist ID resource.
   */
  public function testApi(string $source) {
    $youtubeClient = $this->getYoutubeClient();
    $playlist = $youtubeClient->playlistItems->listPlaylistItems('id', [
      'playlistId' => $source
    ]);
  }

  /**
   * Get all the videos related to a list of playlistItems.
   *
   * This is needed because the YouTube API makes a difference between the
   * "playlist items" and "videos" resources. Playlist items contain some but
   * not all of the data on a video resource, (i.e. statistics, or tags)
   * so additional API calls are required to get all the needed info.
   *
   * In order to avoid making an expensive use of the API (and to prevent
   * hitting quotas), we group the calls to the listVideos endpoint using the
   * same pagination config as the playlist items instead of making one request
   * per each playlistItem.
   *
   * @param \Google\Service\YouTube\PlaylistItem[] $playlistItems
   *   An array of playlist item resources.
   *
   * @return \Google\Service\YouTube\Video[]
   *   An array of video resources.
   */
  protected function getVideosFromPlaylistItems(array $playlistItems): array {
    $videos = [];
    $vids = array_map(function ($playlistItem) {
      return $playlistItem->getSnippet()->getResourceId()->getVideoId();
    }, $playlistItems);
    $batches = array_chunk($vids, $this->pageLimit);

    foreach ($batches as $batch) {
      $new_videos = $this->youtubeClient->videos->listVideos('snippet, statistics, player, status, contentDetails', [
        'id' => implode(',', $batch),
      ]);
      $videos = array_merge($videos, $new_videos->getItems());
    }

    return $videos;
  }

  /**
   * Retrieve the video related to a playlist item.
   *
   * In order to avoid hitting quotas, batched calls to the API were made on
   * ::getVideosFromPlaylistItems. This means that there is a need to
   * manually match each playlist item with its video.
   *
   * To increase efficiency, $videos is an array passed by reference and each
   * match is removed, making the array smaller as more playlist items are
   * processed.
   *
   * @param \Google\Service\YouTube\PlaylistItem $item
   *   The playlist item.
   * @param \Google\Service\YouTube\Video[] $videos
   *   An array containing video resources to match against the PlaylistItem.
   *
   * @return \Google\Service\YouTube\Video|null
   *   The matching video, or null if not found.
   */
  protected function findPlaylistItemVideo(PlaylistItem $item, array &$videos): Video|null {
    $result = NULL;
    $playlistItemVideoId = $item->getSnippet()->getResourceId()->getVideoId();

    foreach ($videos as $key => $video) {
      $videoId = $video->getId();

      if ($videoId === $playlistItemVideoId) {
        $result = $video;
        unset($videos[$key]);
        break;
      }
    }

    return $result;
  }

  /**
   * Convert YouTube video duration to time interval.
   *
   * @param string $duration
   *   YouTube video duration.
   *
   * @return string
   *   The time interval.
   */
  private function timeToDuration($duration): string {
    $di = new \DateInterval($duration);
    $string = '';
    if ($di->h > 0) {
      $string .= $di->h . ':';
    }
    return $string . $di->i . ':' . $di->s;
  }

  /**
   * Parse a YouTube video feed.
   *
   * @param \Google\Service\YouTube\PlaylistItem $playlistItem
   *   The playlist item.
   * @param \Google\Service\YouTube\Video|null $video
   *   Optionally, the related video.
   *
   * @return array
   *   A array of items with all the data parsed.
   */
  private function parseVideoItem(PlaylistItem $playlistItem, Video $video = NULL): array {

    $item = [
      'status' => '',
      'video_id' => '',
      'video_url' => '',
      'title' => '',
      'description' => '',
      'thumbnail_default' => '',
      'thumbnail_medium' => '',
      'thumbnail_high' => '',
      'thumbnail_standard' => '',
      'thumbnail_maxres' => '',
      'category' => '',
      'tags' => '',
      'duration' => '',
      'duration_raw' => '',
      'published_datetime' => '',
      'published_timestamp' => '',
      'view_count' => '',
      'fav_count' => '',
      'likes' => '',
      'dislikes' => '',
      'favorites' => '',
      'embedded_player' => '',
    ];

    $playlistItemData = $playlistItem->getSnippet();

    if ($playlistItemData->getResourceId()->getVideoId()) {
      $item['video_id'] = $playlistItemData->getResourceId()->getVideoId();
    }

    if (!empty($item['video_id'])) {
      $item['video_url'] = 'https://www.youtube.com/watch?v=' . $item['video_id'];
    }

    $item['title'] = $playlistItemData->getTitle();
    $item['description'] = $playlistItemData->getDescription();

    if (!empty($playlistItemData->getThumbnails()->getDefault())) {
      $item['thumbnail_default'] = $playlistItemData->getThumbnails()->getDefault()->getUrl();
    }
    if (!empty($playlistItemData->getThumbnails()->getStandard())) {
      $item['thumbnail_default'] = $playlistItemData->getThumbnails()->getStandard()->getUrl();
    }
    if (!empty($playlistItemData->getThumbnails()->getMedium())) {
      $item['thumbnail_medium'] = $playlistItemData->getThumbnails()->getMedium()->getUrl();
    }
    if (!empty($playlistItemData->getThumbnails()->getHigh())) {
      $item['thumbnail_high'] = $playlistItemData->getThumbnails()->getHigh()->getUrl();
    }
    if (!empty($playlistItemData->getThumbnails()->getMaxres())) {
      $item['thumbnail_maxres'] = $playlistItemData->getThumbnails()->getMaxres()->getUrl();
    }

    // Extra data might not be present.
    if ($video instanceof Video) {

      $videoData = $video->getSnippet();
      if ($videoData->getCategoryId()) {
        $item['category'] = $videoData->getCategoryId();
      }
      if ($videoData->getTags()) {
        $item['tags'] = implode(', ', $videoData->getTags());
      }

      if ($video->getStatus()) {
        $item['status'] = $video->getStatus()->getPrivacyStatus();
      }

      if ($statistics = $video->getStatistics()) {
        $item['view_count'] = $statistics->getViewCount();
        $item['fav_count'] = $statistics->getFavoriteCount();
        $item['likes'] = $statistics->getLikeCount();
        $item['dislikes'] = $statistics->getDislikeCount();
        $item['favorites'] = $statistics->getFavoriteCount();
      }

      if ($video->getContentDetails()) {
        if ($video->getContentDetails()->getDuration()) {
          $item['duration'] = $this->timeToDuration($video->getContentDetails()->getDuration());
          $item['duration_raw'] = $video->getContentDetails()->getDuration();
        }
      }

      if ($video->getPlayer()) {
        $item['embedded_player'] = $video->getPlayer();
      }
    }

    $published_timestamp = strtotime($playlistItemData->getPublishedAt());
    if (isset($published_timestamp)) {
      $item['published_datetime'] = date('Y-m-d H:i:s', $published_timestamp);
      $item['published_timestamp'] = $published_timestamp;
    }

    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function clear(FeedInterface $feed, StateInterface $state) {
    $this->onFeedDeleteMultiple([$feed]);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'google_developer_key' => '',
      'import_video_limit' => 50,
      'results_per_page' => 50,
    ];
  }

}
