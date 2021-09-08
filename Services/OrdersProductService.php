<?php

use App\Models\OrdersProduct;

class OrdersProductService
{
    public static function updateOrCreate(OrdersProduct $product, array $data): OrdersProduct
    {
        $product->pp_id = $data['pp_id'];
        $product->order_id = $data['order_id'];
        $product->parent_id = $data['parent_id'];
        $product->datetime = $data['datetime'];
        $product->partner_id = $data['partner_id'];
        $product->offer_id = $data['offer_id'];
        $product->link_id = $data['link_id'];
        $product->product_id = $data['product_name'];
        $product->product_name = $data['product_name'];
        $product->category = $data['category'];
        $product->price = $data['price'];
        $product->quantity = $data['quantity'];
        $product->total = $data['quantity'];
        $product->web_id = $data['web_id'];
        $product->click_id = $data['click_id'];
        $product->pixel_id = $data['pixel_id'];
        $product->amount = $data['amount'];
        $product->amount_advert = $data['amount_advert'];
        $product->fee_advert = $data['fee_advert'];

        $product->save();

        return $product;
    }

    public function getProductsByOrderId(int $orderId, int $ppId)
    {
        return OrdersProduct::query()
            ->where('order_id', $orderId)
            ->where('pp_id', $ppId)
            ->get();
    }

}