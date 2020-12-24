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
     * TODO external filehandler-class
     * @param string $key
     * @param string $cacheTimeout
     * @return false|object
     */
    public function readCache(string $key, string $cacheTimeout)
    {
        $filePath = $this->getCachePathByKey($key);

        if (!file_exists($filePath)) {
            return false;
        }

        $fileContent = file_get_contents($filePath);

        $cacheData = json_decode($fileContent);

        if (!is_object($cacheData)) {
            return false;
        }

        if (!isset($cacheData->communityFile->updated)) {
            // strange, how is that even possible?
            return false;
        }

        $updated = new \DateTime($cacheData->communityFile->updated);
        $cacheInvalidationTime = new \DateTime($cacheTimeout);

        // is it older than our $cacheTimeout limit?
        if ($updated->getTimestamp() < $cacheInvalidationTime->getTimestamp()) {
            return false;
        }

        return isset($cacheData->communityFile->content)
            ? $cacheData->communityFile->content
            : false;
    }

    /**
     * TODO external filehandler-class
     * @param object $data
     * @param string $key
     */
    public function storeCache(object $data, string $key)
    {
        $now = new \DateTime();
        $cacheData = (object) [
            'communityFile' => [
                'updated' => $now->format(\DateTime::ATOM),
                'content' => $data,
            ]
        ];

        $targetDir = $this->cachePath . '/communities';

        if (!is_dir($targetDir)) {
            mkdir($targetDir);
        }

        $filePath = $this->getCachePathByKey($key);
        file_put_contents($filePath, json_encode($cacheData));
        chmod($filePath, 0777);
    }
}
