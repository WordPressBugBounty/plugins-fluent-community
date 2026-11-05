<?php

namespace FluentCommunity\Modules\Integrations\FluentCart\Http\Controllers;

use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Permission\PermissionManager as CartPermissionManager;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\App\Services\Helper;
use FluentCart\App\Helpers\Helper as CartHelper;
use FluentCart\App\Helpers\Status as CartStatus;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\App\Http\Controllers\Controller;

class PaywallController extends Controller
{
    public function getPaywalls(Request $request, $spaceId)
    {
        /** @var BaseSpace $space */
        $space = BaseSpace::withoutGlobalScopes()->findOrFail($spaceId);

        $userId = get_current_user_id();
        if ($space->privacy === 'secret' && !$space->isActiveMember($userId) && !$space->isAdmin($userId, true)) {
            return $this->sendError([
                'message' => __('The space could not be found', 'fluent-community'),
            ], 404);
        }

        $paywallIds = array_map('intval', $request->get('paywall_ids', []));
        $productIds = Arr::get($space->settings, 'cart_product_ids', []);

        $paywallQuery = Product::whereIn('ID', $productIds)
            ->select('ID', 'post_title', 'post_status', 'post_excerpt')
            ->where('post_status', 'publish')
            ->with([
        'detail', 'variants' => function ($query) {
                $query->select('id', 'post_id', 'item_price', 'variation_title', 'other_info');
        },
        ]);

        if (!empty($paywallIds)) {
            $paywallQuery->whereHas('variants', function ($query) use ($paywallIds) {
                $query->whereIn('id', (array) $paywallIds);
            });
        }

        $paywalls = $paywallQuery->get();

        $baseUrl = Helper::baseUrl('/');
        $cartAdminUrl = apply_filters('fluent_cart/admin_base_url', admin_url('admin.php?page=fluent-cart#/'), []);

        $paywalls = $paywalls->map(function ($paywall) use ($baseUrl, $cartAdminUrl, $spaceId) {
            $paywall->view_url = $paywall->view_url;
            $paywall->admin_url = $cartAdminUrl . 'products/' . $paywall->ID;
            $isSimple = Arr::get($paywall->detail, 'variation_type') === 'simple';
            $paywall->variants->map(function ($variant) use ($baseUrl, $spaceId, $isSimple, $paywall) {
                $variant->photo = $isSimple ? $paywall->thumbnail : $variant->thumbnail;
                $variant->total_price = $variant->formatted_total;
                $variant->checkout_url = add_query_arg([
                    'fluent-cart' => 'instant_checkout',
                    'item_id'     => $variant->id,
                    'quantity'    => 1,
                    'redirect_to' => $baseUrl . 'checkout?space_id=' . $spaceId,
                ], site_url());
                return $variant;
            });
            return $paywall;
        });

        return [
            'paywalls'             => $paywalls,
            'unavailable_paywalls' => $paywallIds ? [] : $this->getUnavailablePaywalls($space, array_diff($productIds, $paywalls->pluck('ID')->all()), $cartAdminUrl),
        ];
    }

    // Unbuyable attached products, for admins to remove.
    private function getUnavailablePaywalls($space, $productIds, $cartAdminUrl)
    {
        if (!$productIds || !$space->isAdmin(get_current_user_id())) {
            return [];
        }

        $products = Product::whereIn('ID', $productIds)
            ->select('ID', 'post_title', 'post_status')
            ->get()
            ->keyBy('ID');

        $statusLabels = CartStatus::getProductStatuses();

        $unavailable = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if ($product && $product->post_status === 'publish') {
                continue;
            }

            $unavailable[] = [
                'ID'           => $productId,
                /* translators: %d is the ID of a FluentCart product that is deleted or has no title */
                'post_title'   => $product && $product->post_title ? $product->post_title : sprintf(__('Product #%d', 'fluent-community'), $productId),
                'status'       => $product ? $product->post_status : 'deleted',
                'status_label' => $product ? Arr::get($statusLabels, $product->post_status, $product->post_status) : __('Deleted', 'fluent-community'),
                'admin_url'    => $product ? $cartAdminUrl . 'products/' . $productId : '',
            ];
        }

        return $unavailable;
    }

    public function addPaywall(Request $request, $spaceId)
    {
        // Adding a paywall also writes the product's integration feed, which belongs to the shop.
        if (!CartPermissionManager::hasPermission('products/edit')) {
            return $this->sendError([
                'message' => __('You do not have permission to edit products', 'fluent-community'),
            ], 403);
        }

        $space = BaseSpace::withoutGlobalScopes()->findOrFail($spaceId);

        $productId = $request->getSafe('cart_product_id', 'intval');

        $product = Product::where('ID', $productId)->first();
        if (!$product) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-community'),
            ]);
        }

        if ($product->post_status !== 'publish') {
            return $this->sendError([
                'message' => __('Product must be published to be used as a paywall', 'fluent-community'),
            ]);
        }

        $spaceSettings = $space->settings;
        $cartProductIds = Arr::get($spaceSettings, 'cart_product_ids', []);

        if (in_array((int) $productId, array_map('intval', $cartProductIds), true)) {
            return $this->sendError([
                'message' => __('Product already exists', 'fluent-community'),
            ]);
        }

        $cartProductIds[] = $productId;
        $spaceSettings['cart_product_ids'] = array_values($cartProductIds);
        $space->settings = $spaceSettings;
        $space->save();

        do_action('fluent_community/paywall_added', $space, $productId);

        return [
            'message' => __('Paywall has been added', 'fluent-community'),
            'paywall' => $product,
        ];
    }

    public function removePaywall(Request $request, $spaceId)
    {
        $space = BaseSpace::withoutGlobalScopes()->findOrFail($spaceId);

        $productId = $request->getSafe('cart_product_id', 'intval');

        $spaceSettings = $space->settings;
        $cartProductIds = Arr::get($spaceSettings, 'cart_product_ids', []);

        // Draft or deleted products must stay removable.
        if (!in_array((int) $productId, array_map('intval', $cartProductIds), true)) {
            return $this->sendError([
                'message' => __('Product not added to this community', 'fluent-community'),
            ]);
        }

        $cartProductIds = array_diff($cartProductIds, [ $productId ]);
        $spaceSettings['cart_product_ids'] = array_values($cartProductIds);
        $space->settings = $spaceSettings;
        $space->save();

        do_action('fluent_community/paywall_removed', $space, $productId, $request->all());

        return [
            'message' => __('Paywall has been removed', 'fluent-community'),
        ];
    }

    public function searchProduct(Request $request)
    {
        $search = trim((string) $request->getSafe('search', 'sanitize_text_field', ''));

        $products = Product::query()
            ->select('ID', 'post_title')
            ->when($search !== '', function ($query) use ($search) {
                $query->where('post_title', 'LIKE', '%' . $search . '%');
            })
            ->limit(20)
            ->where('post_status', 'publish')
            ->whereHas('detail')
            ->whereHas('variants')
            ->with([
        'variants' => function ($query) {
                $query->select('id', 'post_id', 'item_price');
        },
        ])
            ->get();

        $formattedProducts = $products->map(function ($product) {
            return [
                'id'    => $product->ID,
                'title' => $product->post_title,
                'image' => $product->thumbnail,
                'price' => $this->getFormattedPrice($product),
            ];
        });

        return [
            'products' => $formattedProducts,
        ];
    }

    public function createProduct(Request $request)
    {
        if (!CartPermissionManager::hasPermission('products/create')) {
            return $this->sendError([
                'message' => __('You do not have permission to create products', 'fluent-community'),
            ], 422);
        }

        $title = $request->getSafe('title');
        $price = $request->getSafe('price', 'floatval');

        $postData = [
            'post_title'  => $title,
            'post_name'   => sanitize_title($title),
            'post_status' => 'publish',
            'post_type'   => \FluentCart\App\CPT\FluentProducts::CPT_NAME,
        ];

        $createdPostId = wp_insert_post($postData);

        if (is_wp_error($createdPostId)) {
            return $this->sendError([
                'code'    => 403,
                'message' => $createdPostId->get_error_message(),
            ]);
        }

        $detailData = [
            'post_id'          => $createdPostId,
            'fulfillment_type' => 'digital',
            'min_price'        => $price * 100,
            'max_price'        => $price * 100,
        ];

        $productDetail = ProductDetail::create($detailData);

        $variationData = [
            'post_id'          => $createdPostId,
            'serial_index'     => 1,
            'variation_title'  => $title,
            'stock_status'     => 'in-stock',
            'payment_type'     => 'onetime',
            'total_stock'      => 1,
            'available'        => 1,
            'fulfillment_type' => 'digital',
            'item_price'       => $price * 100,
            'other_info'       => [
                'description'        => '',
                'payment_type'       => 'onetime',
                'times'              => '',
                'repeat_interval'    => '',
                'trial_days'         => '',
                'billing_summary'    => '',
                'manage_setup_fee'   => 'no',
                'signup_fee_name'    => '',
                'signup_fee'         => '',
                'setup_fee_per_item' => 'no',
            ],
        ];

        $variation = ProductVariation::create($variationData);

        if (!$productDetail || !$variation) {
            return $this->sendError([
                'code'    => 403,
                'message' => __('Failed to create product', 'fluent-community'),
            ]);
        }

        $product = Product::where('ID', $createdPostId)->first();

        $product->updateProductMeta('created_from', 'fluent_community');

        $productData = [
            'id'    => $product->ID,
            'title' => $product->post_title,
            'image' => $product->thumbnail,
            'price' => $this->getFormattedPrice($product),
        ];

        return [
            'product' => $productData,
        ];
    }

    private function getFormattedPrice($product)
    {
        if (!$product->detail) {
            return '';
        }

        $minPrice = $product->detail->min_price;
        $maxPrice = $product->detail->max_price;

        $formattedPrice = ($minPrice === $maxPrice)
            ? CartHelper::toDecimal($minPrice)
            : CartHelper::toDecimal($minPrice) . ' - ' . CartHelper::toDecimal($maxPrice);

        return $formattedPrice;
    }
}
