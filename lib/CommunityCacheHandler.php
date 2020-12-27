<?php

namespace ffmap;

/**
 * Class CommunityCacheHandler
 * @package lib
 */
class CommunityCacheHandler
{
    /**
     * @var string
     */
    protected string $cachePath;

    /**
     * @var array
     */
    protected array $memoryCache = [];

    /**
     * CommunityCacheHandler constructor.
     * @param string $path
     */
    public function __construct(string $path)
    {
        $this->setCachePath($path);
    }

    /**
     * @param string $path
     */
    public function setCachePath(string $path)
    {
        $this->cachePath = $path;
    }

    /**
     * @param string $key
     * @return string
     */
    protected function getCachePathByKey(string $key): string
    {
        $targetDir = $this->cachePath . '/communities';
        $key = str_replace('/', '_', $key);
        $key = str_replace('.', '', $key);
        return $targetDir.'/c_' . $key . '.json';
    }

    /**
     * @param string $communityKey
     * @return false|mixed
     */
    protected function getFromCacheFile(string $communityKey)
    {
        $filePath = $this->getCachePathByKey($communityKey);

        if (!file_exists($filePath)) {
            return false;
        }

        $fileContent = file_get_contents($filePath);

        $cacheData = json_decode($fileContent);

        if (!is_object($cacheData)) {
            return false;
        }

        return $cacheData;
    }

    /**
     * TODO external filehandler-class
     * @param string $communityKey
     * @param string $entryKey
     * @param string $cacheTimeout
     * @return false|object
     */
    public function readCache(string $communityKey, string $entryKey, string $cacheTimeout)
    {
        if (isset($this->memoryCache[$communityKey])) {
            $cacheData = $this->memoryCache[$communityKey];
        } else {
            $cacheData = $this->getFromCacheFile($communityKey);

            if (!$cacheData) {
                return false;
            }

            $this->memoryCache[$communityKey] = $cacheData;
        }


        if (!isset($cacheData->$entryKey->updated)) {
            return false;
        }

        $updated = new \DateTime($cacheData->$entryKey->updated);
        $cacheInvalidationTime = new \DateTime($cacheTimeout);

        // is it older than our $cacheTimeout limit?
        if ($updated->getTimestamp() < $cacheInvalidationTime->getTimestamp()) {
            return false;
        }

        return isset($cacheData->$entryKey->content)
            ? $cacheData->$entryKey->content
            : false;
    }

    /**
     * TODO external filehandler-class
     * @param string $communityKey
     * @param string $entryKey
     * @param object $data
     */
    public function storeCache(string $communityKey, string $entryKey, object $data)
    {
        $cacheData = $this->getFromCacheFile($communityKey);

        if (!$cacheData) {
            $cacheData = (object) [

            ];
        }

        $now = new \DateTime();
        $cacheData->$entryKey = (object) [
            'updated' => $now->format(\DateTime::ATOM),
            'content' => $data
        ];

        $targetDir = $this->cachePath . '/communities';

        if (!is_dir($targetDir)) {
            mkdir($targetDir);
        }

        $filePath = $this->getCachePathByKey($communityKey);
        file_put_contents($filePath, json_encode($cacheData));
        chmod($filePath, 0777);

        $this->memoryCache[$communityKey] = $cacheData;
    }
}
