<?php

namespace App\Services\Publishers;

interface PublisherInterface
{
    /**
     * Publish a post to the social platform.
     *
     * @param array $post Post data: content (string), media_urls (array)
     * @param array $account Social account data: access_token, provider_id, etc.
     * @return array Result: success (bool), external_id (?string), error (?string)
     */
    public function publish(array $post, array $account): array;
}
