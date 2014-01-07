<?php

namespace Yiix\Cache\Tagged;

class Dependency implements \ICacheDependency
{
    /**
     * initilized list of tags
     *
     * @var array
     */
    public $_tags = null;

    /**
     * link to cache component
     *
     * @var ICache
     */
    public $_backend;

    /**
     * Associative array of tag versions
     *
     * @var array
     */
    public $_tag_versions = null;

    /**
     * initilialize object by given list of tags
     *
     * @param array $tags
     */
    public function __construct(array $tags)
    {
        $this->_tags = $tags;
    }

    /**
     * initialize cache component
     */
    public function initBackend()
    {
        $this->_backend = \Yii::app()->cache;
    }

    /**
     * Will be called before data saving into cache
     * Generate version ids for given tags
     * @return void
     */
    public function evaluateDependency() {
        $this->initBackend();
        $this->_tag_versions = null;
        if($this->_tags === null || !is_array($this->_tags)) {
            return;
        }

        if (!$this->_backend) return;

        $tagsWithVersion = array();

        foreach ($this->_tags as $tag) {
            $mangledTag = Helper::mangleTag($tag);
            $tagVersion = $this->_backend->get($mangledTag);
            if ($tagVersion === false) {
                $tagVersion = Helper::generateNewTagVersion();
                $this->_backend->set($mangledTag, $tagVersion, 0);
            }
            $tagsWithVersion[$tag] = $tagVersion;
        }

        $this->_tag_versions = $tagsWithVersion;
        return;
    }

    /**
     * test if tags are actual. true - cach is actual, false - cache needs to be reseted
     * @return bool
     */
    public function getHasChanged()
    {
        $this->initBackend();

        if ($this->_tag_versions === null || !is_array($this->_tag_versions) || empty($this->_tag_versions)) {
            return true;
        }

        $allMangledTagValues = $this->_backend->mget(Helper::mangleTags(array_keys($this->_tag_versions)));

        foreach ($this->_tag_versions as $tag => $savedTagVersion) {

            $mangleTag = Helper::mangleTag($tag);

            if (!isset($allMangledTagValues[$mangleTag])) {
                return true;
            }

            $actualTagVersion = $allMangledTagValues[$mangleTag];

            if ($actualTagVersion !== $savedTagVersion) {
                return true;
            }
        }

        return false;
    }
}