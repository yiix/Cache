<?php
namespace Yiix\Cache\Tagged;

class Helper
{
    const VERSION = "0.02";

    private static $_cache = null;

    /**
     * generate tag prefix
     *
     * @param CModel $model
     * @return string
     */
    public static function prefix($model)
    {
        return strtolower(ltrim(get_class($model),'M'));
    }

    /**
     * merge tags array, removes dublicates while merging
     *
     * @param array $tags1
     * @param array $tags2
     * @return array
     */
    public static function mergeTags($tags1,$tags2)
    {
        $args = func_get_args();
        $res = array();
        foreach ($args as $ar) {
            foreach ($ar as $v) {
                if (!in_array($v,$res)) {
                    $res[] = $v;
                }
            }
        }
        return $res;
    }

    /**
     * Generate list of tags based on predefainded rules at model
     *
     * @param CActiveRecord $model
     * @param array|CDbCriteria $attrbutes
     * @return array
     */
    public static function generateTags($model, $attributes=array(),$mode='read')
    {
        if (!$model->cacheTags($mode)) {
            return array();
        }

        $tags = array();
        $prefix = self::prefix($model);
        if (!empty($attributes)) {
            if ($attributes instanceof CDbCriteria) {
                if ($attributes->with) {
                    foreach ($attributes->with as $alias=>$join) {
                        if (!empty($join['params'])) {
                            foreach ($join['params'] as $column=>$value) {

                                if (preg_match('/^:'.$prefix.'_/', $column)) {
                                    $column = preg_replace('/^:'.$prefix.'_/', '', $column);
                                    $tags[$column] = $value;

                                } elseif (is_array($value)) {
                                    foreach ($value as $v) {
                                        $tags[$column] = $v;
                                    }

                                } else {
                                    $tags[$column] = $value;
                                }
                            }
                        }
                    }
                }
                if (!empty($attributes->tags)) {
                    $tags = array_merge($tags,$attributes->tags);
                }
            } elseif ($attributes instanceof \CDbCriteria) {
                if ($attributes->with) {
                    foreach ($attributes->with as $alias=>$join) {
                        if (!empty($join['params'])) {
                            foreach ($join['params'] as $column=>$value) {

                                if (preg_match('/^:'.$prefix.'_/', $column)) {
                                    $column = preg_replace('/^:'.$prefix.'_/', '', $column);
                                    $tags[$column] = $value;

                                } elseif (is_array($value)) {
                                    foreach ($value as $v) {
                                        $tags[$column] = $v;
                                    }

                                } else {
                                    $tags[$column] = $value;
                                }
                            }
                        }
                    }
                }

            } else {
                foreach ($attributes as $column=>$value) {
                    if (preg_match('/^:'.$prefix.'_/', $column)) {
                        $column = preg_replace('/^:'.$prefix.'_/', '', $column);
                        $tags[$column] = $value;

                    } elseif (is_array($value)) {
                        foreach ($value as $v) {
                            $tags[$column] = $v;
                        }

                    } else {
                        $tags[$column] = $value;
                    }
                }

            }
        } else {
            $tags = self::generatePrimaryKeyTagValues($model, $model->getPrimaryKey());
        }

        return self::filterTags($model, $tags, $mode,$prefix);
    }

    /**
     * Generate tags by composite primary key
     *
     * @param CActiveRecord $model
     * @param string|array $pk
     * @param string $mode
     * @return array
     */
    public static function generatePrimaryKeyTagValues($model,$pk,$mode='read')
    {
        if (!$model->cacheTags($mode)) {
            return array();
        }


        $tagValues = array();
        $primaryKey = $model->getTableSchema()->primaryKey;
        if (is_array($primaryKey)) {
            if (!is_array($pk)) {
                $tagValues[array_shift($primaryKey)] = $pk;
            } else {
                foreach ($pk as $key=>$val) {
                    if (in_array($key,$primaryKey)) {
                        if ($val) {
                            $tagValues[$key] = $val;
                        }
                    }
                }
            }

        } else {
            if (is_array($pk)) {
                foreach ($pk as $key=>$val) {
                    if ($val) {
                        $tagValues[$key] = $val;
                    }
                }
            } else {
                if ($pk) {
                    $tagValues[$primaryKey] = $pk;
                }
            }
        }

        return $tagValues;
    }

    /**
     * remove leading ':' character from all tags
     *
     * @param array $tags
     * @return array
     */
    public static function sanitizeCacheTagnames($tags)
    {
        foreach ($tags as $k=>&$v) {
            $k = ltrim($k,':');
            if (is_array($v)) {
                $v = self::sanitizeCacheTagnames($v);
            } else {
                $v = ltrim($v,':');
            }
        }
        return $tags;
    }

    /**
     * Filter and modify list tags based on tag types they asscociated with
     *
     * @param CModel $model
     * @param array $tags
     * @param string $mode
     * @param string $prefix
     * @return array
     */
    public static function filterTags($model,$tags,$mode,$prefix)
    {

        if (method_exists($model, 'cacheTags')) {
            $_tags = $model->cacheTags($mode);
        } else {
            $_tags = array();
        }

        $tagsFiltered = array();

        foreach ($_tags as $_k=>$_v) {
            if (
                ($tag = self::filterTagComposition($tags,$_tags, $_k, $_v, $prefix))
                || ($tag = self::filterTagAlias($tags,$_tags, $_k, $_v, $prefix))
                || ($tag = self::filterTagRaw($tags,$_tags, $_k, $_v, $prefix))

            ) {
                if (is_array($tag)) {
                    $tagsFiltered = array_merge($tagsFiltered,$tag);
                } else {
                    $tagsFiltered[] = $tag;
                }
            }
        }

        foreach ( $tags as $_k=>$_v) {
            if ($_k[0]==':') {
                $tag = ltrim($_k,':').'='.$_v;
                if (!in_array($tag,$tagsFiltered)) {
                    $tagsFiltered[] = $tag;
                }
            }
        }

        return $tagsFiltered;
    }

    /**
     * If tag name associated with static rule, than will be generated tag based on
     * static tag rules
     * otherwise return false
     *
     * @param array $tags
     * @param array $config
     * @param string $name
     * @param string $value
     * @param string $prefix
     * @return boolean|string
     */
    public static function filterTagRaw($tags,$config,$name,$value,$prefix){

        if (!is_numeric($name)) {
            return false;
        }

        if ($value[0] == ':') {
            $prefix = null;
        }

        $alias = $value = ltrim($value, ':');

        foreach ($tags as $k => $v){
            if ($k == $value) {
                if (is_array($v)) {
                    return array_map(function($a) use ($alias,$prefix) {
                        if (in_array(gettype($a),array('integer','double'))) {
                            $a = number_format($a, 0, '', '');
                        }
                        return (!empty($prefix)?$prefix.'_':'').$alias.'='.$a;
                    },$v);
                } else {
                    if (in_array(gettype($v),array('integer','double'))) {
                        $v = number_format($v, 0, '', '');
                    }
                    return (!empty($prefix)?$prefix.'_':'').$alias.'='.$v;
                }
            }
        }

        return false;
    }

    /**
     * if tag name associated with composite tag rule than will be generated
     * tag based on composite tag rules
     * otherwise return false
     *
     * @param array $tags
     * @param array $config
     * @param string $name
     * @param string $value
     * @param string $prefix
     * @return string|boolean
     */
    public static function filterTagComposition($tags,$config,$name,$value,$prefix)
    {

        if (isset($config[$name]) && is_array($config[$name])) {
            $subkey = array();
            foreach ($config[$name] as $k=>$v){
                if ($tag = self::filterTagRaw($tags,$config[$name], $k, $v, $prefix)) {
                    $subkey[] = $tag;
                }elseif($tag = self::filterTagAlias($tags,$config[$name], $k, $v, $prefix)){
                    $subkey[] = $tag;
                }
            }
            if ($subkey && count($config[$name]) == count($subkey)) {
                return $prefix.':'.implode(',',$subkey);
            }
        }

        return false;
    }

    /**
     * if tag name associated with alias rule than will be generated tag based on tag alias rule
     * otherwise return false
     *
     * @param array $tags
     * @param array $config
     * @param string $name
     * @param string $value
     * @param string $prefix
     * @return boolean|string
     */
    public static function filterTagAlias($tags,$config,$name,$value,$prefix){

        if (is_numeric($name) || !isset($config[$name]) || !is_string($config[$name])) {
            return false;
        }

        if ($value[0] == ':') {
            $prefix = null;
        }

        $alias = ltrim($value, ':');


        foreach ($tags as $k => $v){
            if ($k == $name) {
                if (is_array($v)) {
                    return array_map(function($a) use ($alias,$prefix) {return (!empty($prefix)?$prefix.'_':'').$alias.'='.$a; },$v);
                } else {
                    return (!empty($prefix)?$prefix.'_':'').$alias.'='.$v;
                }
            }
        }

        return false;
    }


    /**
     * initialize the cache component
     *
     * @param \ICache $cacheId
     * @return boolean
     */
    public static function init(\ICache $cacheId = null)
    {
        if ($cacheId === null)
        {
            if (self::$_cache !== null) {
                return true;
            }

            self::$_cache = \Yii::app()->cache;
        }
        else {
            self::$_cache = $cacheId;
        }

        return (self::$_cache !== null);
    }

    /**
     * Delete cache tags.
     *
     * @param array $tags
     * @return bool
     */
    public static function deleteByTags($tags = array())
    {
        if (!self::init()) return false;

        if (is_string($tags)) {
            $tags = array($tags);
        }

        if (is_array($tags)) {
            foreach ($tags as $tag) {
                self::$_cache->delete(self::mangleTag($tag));
            }
        }

        return true;
    }

    /**
     * Generate unique key based on tag name
     *
     * @param string $tag
     * @return string
     */
    public static function mangleTag($tag)
    {
        return get_called_class() . "_" . self::VERSION . "_" . $tag;
    }

    /**
     *
     * mangleTag method mapper for tag list
     *
     * @see self::_mangleTag
     * @param string $tags
     * return array
     */
    public static function mangleTags($tags)
    {
        foreach ($tags as $i => $tag) {
            $tags[$i] = self::mangleTag($tag);
        }
        return $tags;
    }

    /**
     * generate unique id for tag version
     *
     * @return string
     */
    public static function generateNewTagVersion()
    {
        static $counter = 0;
        $counter++;
        return md5(microtime() . getmypid() . uniqid('')) . '_' . $counter;
    }
}