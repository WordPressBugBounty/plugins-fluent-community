<?php

namespace FluentCommunity\App\Services\Insights\Cards;

use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

/**
 * A placeholder for a Pro card. It lets the UI draw the card's shape with an
 * upgrade prompt, and never computes anything.
 */
class LockedCard extends AbstractCard
{
    private $key;

    private $page;

    private $type;

    private $title;

    private $description;

    private $size;

    private $order;

    public function __construct($key, $page, $type, $title, $description = '', $size = 'half', $order = 100)
    {
        $this->key = $key;
        $this->page = $page;
        $this->type = $type;
        $this->title = $title;
        $this->description = $description;
        $this->size = $size;
        $this->order = $order;
    }

    public function key()
    {
        return $this->key;
    }

    public function page()
    {
        return $this->page;
    }

    public function type()
    {
        return $this->type;
    }

    public function title()
    {
        return $this->title;
    }

    public function description()
    {
        return $this->description;
    }

    public function size()
    {
        return $this->size;
    }

    public function order()
    {
        return $this->order;
    }

    public function isLocked()
    {
        return true;
    }

    public function supports()
    {
        return [];
    }

    public function compute(Range $range, Scope $scope, array $options)
    {
        return [];
    }
}
