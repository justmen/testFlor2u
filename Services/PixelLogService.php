<?php

use App\Processors\PixelLog;

class PixelLogService
{
    public static function validateOrder(PixelLog $pixelLog) :void
    {
        $events = $pixelLog->data['dataLayer'];

        if (!is_array($events)) {
            throw new \Exception('dataLayer is not an array');
        }

        $validationFields = [
            'products.*.id' => 'required|string',
            'products.*.name' => 'required|string',
            'products.*.price' => 'required|numeric',
            'products.*.variant' => 'nullable|string',
            'products.*.category' => 'nullable|string',
            'products.*.quantity' => 'nullable|numeric|min:1',
            'actionField.id' => 'required|string',
            'actionField.action' => 'nullable|string|in:purchase',
            'actionField.revenue' => 'required|numeric',
        ];
        $validatedEvents = [];

        foreach ($events as $event) {
            if (!isset($event['event']) ||
                !isset($event['ecommerce']) ||
                !isset($event['ecommerce']['purchase'])) {
                continue;
            }

            $purchase = $event['ecommerce']['purchase'];

            $validator = Validator::make($purchase, $validationFields);

            if ($validator->fails()) {
                logger()->debug('Ошибка валидации заказа');
                throw new ValidationException($validator);
            }

            $validatedEvents[] = $event;
        }

        $pixelLog->data['dataLayer'] = $validatedEvents;
    }

}