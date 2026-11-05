<?php

namespace FluentCommunity\App\Http\Controllers;

use FluentCommunity\App\Services\Search\SearchQuery;
use FluentCommunity\App\Services\Search\SearchService;
use FluentCommunity\Framework\Http\Request\Request;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $start = microtime(true);

        $query = new SearchQuery($request->getSafe('q', 'sanitize_text_field', ''));

        $types = array_filter(array_map('sanitize_key', (array) $request->get('types', [])));
        $perType = (int) $request->getSafe('per_type', 'intval', 6);

        $results = SearchService::search($query, $types, $this->getUser(), $perType, [
            'page'  => $request->getSafe('page', 'intval', 1),
            'space' => $request->getSafe('space', 'sanitize_title', ''),
            'sort'  => $request->getSafe('sort', 'sanitize_key', 'relevance'),
        ]);

        /**
         * Filters the global search response.
         *
         * @param array       $response { results: object keyed by result type, terms: string[], execution_time: float }
         * @param SearchQuery $query    The parsed search text.
         * @param string[]    $types    The requested result types.
         */
        return apply_filters('fluent_community/search/api_response', [
            'results'        => (object) $results,
            'terms'          => $query->terms,
            'execution_time' => microtime(true) - $start,
        ], $query, $types);
    }
}
