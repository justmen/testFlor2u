<?php

declare(strict_types=1);

use App\Models\Order;

class OrderService
{
    public static function updateOrCreate(array $data)
    {
        $order = Order::orderIdPpId($data['order_id'], $data['pp_id']);

        //если заказ не найден, то создаем новый заказ
        if (!$order) {
            logger()->debug('Заказ №' . $data['order_id'] . ' не существует, создаем');
            $order = new Order();
            $order->pp_id = $data['pp_id'];
            $order->order_id = $data['order_id'];
            $order->status = 'new';
        } else {
            logger()->debug('Заказ №' . $data['order_id'] . ' существует, обновляем');
        }

        //обновляем/заполняем заказ
        $order->pixel_id = $data['id'];
        $order->datetime = $data['created_at'];
        $order->partner_id = $data['partner_id'];
        $order->link_id = $data['id'];
        $order->click_id = $data['click_id'] ?? null;
        $order->web_id = $data['utm_term'] ?? null;
        $order->offer_id = $data['offer_id'];
        $order->client_id = $data['id'];
        $order->gross_amount = $data['gross_amount'];
        $order->cnt_products = $data['cnt_products'];

        $order->save();

        return $order;
    }
}