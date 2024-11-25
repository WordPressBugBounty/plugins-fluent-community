<?php

namespace FluentCommunity\Framework\Http\Middleware;

use FluentCommunity\Framework\Foundation\App;

class RateLimiter
{
    protected $limit;
    protected $interval;

    public function __construct($limit, $interval)
    {
        $this->limit = $limit;
        $this->interval = $interval;
    }

    public function handle($request, $next)
    {
        if ($this->shouldAllow($request)) {
            return $next($request);
        }

        $settings = $this->getSettings(
            $request, $currentTime = time()
        );

        if ($this->isIntervalExpired($settings, $currentTime)) {
            $settings = $this->resetRateLimit($currentTime);
        } else {
            $settings['count']++;
        }

        $this->updateSettings($request, $settings);

        if ($this->isRateLimitExceeded($settings)) {
            return $request->abort(429, 'Too many requests.');
        }

        return $next($request);
    }

    protected function shouldAllow($request)
    {
        return is_user_logged_in() || $request->method() === 'HEAD';
    }

    protected function getSettings($request, $currentTime)
    {
        $settings = $this->getTransient($request);
        return $settings ?: ['count' => 0, 'firstTime' => $currentTime];
    }

    protected function isIntervalExpired($settings, $currentTime)
    {
        return (
            $currentTime - $settings['firstTime']
        ) > $this->interval;
    }

    protected function resetRateLimit($currentTime)
    {
        return ['count' => 1, 'firstTime' => $currentTime];
    }

    protected function isRateLimitExceeded($settings)
    {
        return $settings['count'] > $this->limit;
    }

    protected function getTransient($request)
    {
        return get_transient($this->makeTransientKey($request));
    }

    protected function updateSettings($request, $settings)
    {
        $key = $this->makeTransientKey($request);

        set_transient($key, $settings, $this->interval);
    }

    protected function makeTransientKey($request)
    {
        $slug = App::config()->get('app.slug');

        return "{$slug}_rate_limit_" . md5($request->getIp());
    }
}
