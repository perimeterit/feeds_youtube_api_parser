# Feeds Youtube API

The Feeds Youtube API module integrates YouTube with [Feeds](https://drupal.org/project/feeds) module via the
Google Youtube API V3.

This allows users to fetch data from YouTube playlists and process it using
Feeds parsers and processors at will.

A common use case is to automatically import all videos from a YouTube playlist
intro Drupal media or node entities.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/admin_menu).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/admin_menu).

## Table of contents

- Features
- This module and Feeds YouTube
- Requirements
- Installation
  - How to reduce the `vendor` folder size
- Configuration
- Available data
- FAQ
- Maintainers

## Features

- Create feed channels for Youtube playlists
- Limit API calls by setting a maximum number of items and a number of items per page
- Batches requests to avoid hitting quota usages.
- Only needs a Google Developer Key, no OAuth needed.

## This module and Feeds YouTube

This module is based on the no longer maintained [Feeds Youtube]() module, with
the following differences:

- __Feeds YouTube allows for OAuth 2.0 API calls, this module won't.__
  For the time being, this module uses regular API calls without authentication.
  This makes life easier for the developer at the cost of not being able to
  access some data. Since this module aims to be able to fetch public
  YouTube data (for which OAuth is not mandatory), this is not a problem.
- __This module allows to simplify API calls and batches requests.__
  Google API has quotas and, in order to fetch all of a playlist video's data,
  two resources need to be fetched: [PlaylistItems](https://developers.google.com/youtube/v3/docs/playlistItems?hl=en#resource) and [Videos](https://developers.google.com/youtube/v3/docs/videos/list?hl=en).
  This module allows you to skip Video requests if you don't need its data and
  will batch requests to the Videos endpoint by grouping them in order to
  avoid excessive quota usage.

## Requirements

- [Feeds](https://drupal.org/project/feeds) module
- [Feeds Extensible Parsers](https://drupal.org/project/feeds_ex) module for the JsonPath parser.
- [google/apiclient library](https://github.com/googleapis/google-api-php-client). Note that `composer` managed sites will install it automatically.
- A [Google API Console](https://console.developers.google.com) account, with a Developer key and the Youtube Data API V3 active.

## Installation

- Install as you would normally install a contributed Drupal module. Visit
[this guide](https://www.drupal.org/node/1897420) for further information.

After install, you might want to reduce your `vendor` size by performing the following:

### How to reduce the `vendor` folder size

The required library `google/apiclient` downloads a ton of Google Services. In
order to keep your dependencies folder tidy, you can add the following to your
`composer.json` to remove all unwanted services:

```json
"scripts": {
    "pre-autoload-dump": "Google\\Task\\Composer::cleanup"
},
"extra": {
    "google/apiclient-services": [
        "YouTube"
    ],
```

The `scripts` part executes a script that performs the cleanup and the `extra`
part indicates which services need to stay - in our case, we only need the
YouTube API Client.

## Configuration

- Add a new `Feed type` on `/admin/structure/feeds`
- Choose `YouTube API` as the fetcher.
- Choose `JsonPath` as the parser.
- Choose your desired processor, i.e: a `Media` entity of type `Remote Video`
- On the `Fetcher Settings` input your Google Developer API key and configure your API limits.
- Save the Feed Type and proceed to the `Mappings` tab.
- __Important!__ Input `$.*` on `Context`.
- Map your fields to the JSON incoming data. Refer to the [Feeds documentation](https://www.drupal.org/docs/contributed-modules/feeds/creating-and-editing-import-feeds) if you need
more info. Refer to [Available data](#available-data) to see the JSON scheme.
  - You can use [Feeds Tamper](https://drupal.org/project/feeds_tamper) to perform conversions (i.e. convert the `status` field to a boolean)
- Go to `/admin/content/feed` and create a `Feed Channel` of the new Feed type.
- Input a valid playlist ID.
- Save, import and enjoy while the playlist data gets imported to your mapped entity.

## Available data

Each JSON payload holds an array of items, and each one follows this scheme:

```php
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
```

__Be aware__ that not all properties might be populated, especially if you choose to
skip the `Videos` resource.

These property names should be used when mapping data to fields.

## FAQ

### How do I create a Google API Console account?

In order to make API calls you will need to :

- Log in into [Google API Console](https://console.developers.google.com) with a valid google account.
- [Create a project](https://console.cloud.google.com/projectcreate) (or use an existing one)
- Into the project, enable the YouTube Data API v3. You can use this [direct link](https://console.cloud.google.com/apis/library/youtube.googleapis.com).
- Enable your Developer API Key in the [Credentials section](https://console.cloud.google.com/apis/credentials) by clicking on "Create credentials" and "API Key". Your key will appear on a pop-up.
  - It is advisable to edit your API key and put some restrictions into place: you can limit the API key to a given set of
  websites or IPs or you can restrict its usage to a single API - on this case, the YouTube API.

For the time being, this module won't use OAuth so you won't need to create OAuth 2.0 credentials.

### How do I get a playlist ID?

You can navigate to your preferred channel or user, find a playlist and click on "Play all".

The URL will be shomething like this: `https://www.youtube.com/watch?v=wVOsEnb71Ls&list=PLc6y_bIejLFHlJY3v81_yOaGaqn9Ld0dI`

Your playlist ID is the `&list=` part: `PLc6y_bIejLFHlJY3v81_yOaGaqn9Ld0dI`.

### I want to fetch all the videos of a user!

There is a playlist automatically generated called "uploads" for each user.

You can find it on the account page, usually under the "Videos" name.

If you struggle finding it you can use the youtube [API explorer](https://developers.google.com/youtube/v3/docs/channels/list) to find the "uploads" playlist ID of a given username.

- [Example of finding a channel ID by username=rtve](https://developers.google.com/youtube/v3/docs/channels/list?hl=en&apix_params=%7B%22part%22%3A%5B%22contentDetails%22%5D%2C%22forUsername%22%3A%22rtve%22%7D). Click "Execute" without OAuth2.0 to see the result: `items[0].contentDetails.relatedPlaylists.uploads` is the "uploads" playlist.

Be aware that the "uploads" playlist can become huge: use the configuration limits to tame it!

## Maintainers

### Current maintainers:

 * Ignacio Díaz-Roncero Fraile [idiazroncero](https://www.drupal.org/u/idiazroncero)
