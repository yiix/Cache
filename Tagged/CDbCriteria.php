<?php
namespace Yiix\Cache\Tagged;
class CDbCriteria extends \CDbCriteria {

    /**
     * list of manualy added tags
     *
     * @var array
     */
    public $tags = array();
}