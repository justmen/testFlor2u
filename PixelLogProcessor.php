<?php

	/**
	 * ЗАДАЧА СОИСКАТЕЛЮ
	 *
	 * Применяя принципы SOLID и заветы чистого кода
	 * 1) Отрефакторить метод parseDataLayerEvent
	 *
	 * Полученный результат должен соответствовать DRY, KISS
	 * Очевидно что рефакторинг абстрактный и как-то запускаться/тестироваться не должнен.
	 * Важно понимание говнокодинка и правил написания чистого кода.
	 *
	 * 2) Рассказать о проблемах данного класса в частности и о подходе который привел к его появлению в общем.
	 */

	namespace App\Processors;

	use App\Models\Click;
	use App\Models\Client;
	use App\Models\Link;
	use App\Models\Order;
	use App\Models\OrdersProduct;
	use App\Models\PixelLog;
	use Illuminate\Support\Facades\Validator;
	use Illuminate\Validation\ValidationException;
    use App\Services\OrderService;
    use App\Services\OrdersProductService;
    use App\Services\PixelLogService;

	class PixelLogProcessor
	{
		protected $pixel_log;
		protected $client;
		protected $link;

		public function __construct(PixelLog $pixel_log)
		{
			$this->pixel_log = $pixel_log;
		}

		public function process()
		{
			try {
				// Получаем новое значение для поля is_valid
				// Валидация записи. В случае ошибки - выдаст Exception
				$this->pixel_log->is_valid = $this->pixel_log->isDataValid();

				// Записываем client_id
				$this->parseClientId();

				// Получаем модель ссылки
				$this->parseLink();

				// Проверяем, если эта запись - клик - обрабатываем
				$this->parseClick();

				// А если это продажа - тоже обрабатываем!
				$this->parsePurchase();

				$this->pixel_log->status = null;
			} catch (ValidationException $e) {
				$this->pixel_log->status = json_encode($e->errors(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
				logger()->warning('e', $e->errors());
			} catch (\Throwable $e) {
				$this->pixel_log->status = $e->getMessage();
				logger()->warning($e->getMessage());
				logger('Пойман Exception в pixel_log #' . $this->pixel_log->id, [$e]);
				dump($e);
			} finally {
				$this->pixel_log->save();
			}
		}

		/**
		 * Проверяем, существует ли такой clientId
		 * Если нет - создаем
		 *
		 * @return \App\Models\Client|false
		 */
		public function parseClientId()
		{
			if (empty($this->pixel_log->data['uid'])) {
				throw new \Exception('Пустой uid');
			}

			$this->client = Client::where('id', '=', $this->pixel_log->data['uid'])->first() ?? new Client();
			$this->client->id = $this->pixel_log->data['uid'];
			$this->client->pp_id = $this->pixel_log->pp_id;
			$this->client->save();

			return $this->client;
		}

		public function parseLink()
		{
			// Обрабатываем тот момент, когда url содержит в себе наши UTM-метки
			$this->link = Link::query()
				->where('pp_id', '=', $this->pixel_log->pp_id)
				->where('id', '=', $this->pixel_log->data['utm_campaign'])
				->where('partner_id', '=', $this->pixel_log->data['utm_content'])
				->first();

			if (!$this->link) {
				throw new \Exception('Не найден линк #' . $this->pixel_log->data['utm_campaign'] . ' у партнера #' . $this->pixel_log->data['utm_content']);
			}
			return $this->link;
		}

		public function parseClick() : ?Click
		{
			$this->pixel_log->is_click = false;
			if (!$this->pixel_log->isClick()) {
				// Это не клик, пропускаем
				return null;
			}

			if (!$this->link) {
				throw new \Exception('Не найден линк #' . $this->pixel_log->data['utm_campaign'] . ' у партнера #' . $this->pixel_log->data['utm_content']);
			}

			// Тут мы проверяем, что данная запись не существовала до этого в таблице clicks
			$click = Click::query()
					->where('pp_id', '=', $this->pixel_log->pp_id)
					->where('pixel_log_id', '=', $this->pixel_log->id)
					->first() ?? new Click();
			$click->pp_id = $this->pixel_log->pp_id;
			$click->partner_id = $this->link->partner_id;
			$click->link_id = $this->link->id;
			$click->client_id = $this->client->id;
			$click->click_id = $this->pixel_log->data['click_id'] ?? null;
			$click->web_id = $this->pixel_log->data['utm_term'] ?? null;
			$click->pixel_log_id = $this->pixel_log->id;
			$click->save();

			$this->pixel_log->is_click = true;
			return $click;
		}

		public function parsePurchase()
		{
			if ($this->pixel_log->isPurchase() === false) {
				logger()->debug('Не является заказом');
				$this->pixel_log->is_order = false;
				return false;
			}

			$this->pixel_log->is_order = false;
			if ($this->pixel_log->data['ev'] === 'purchase' && !empty($this->pixel_log->data['ed']['order_id']) && !empty($this->pixel_log->data['dataLayer'])) {
                PixelLogService::validateOrder($this->pixel_log);
				logger()->debug('Это ваще-заказ в 1 клик');
				$this->parseCheckoutDataLayerEvent();
				return true;
			} elseif ($this->pixel_log->data['ev'] === 'pageload' && !empty($this->pixel_log->data['dataLayer'])) {
                PixelLogService::validateOrder($this->pixel_log);
				logger()->debug('Это ваще-заказ');
				$this->parseDataLayerEvent();
				return true;
			} elseif ($this->pixel_log->data['ev'] === 'purchase' && !empty($this->pixel_log->data['ed']) && !empty($this->pixel_log->data['ed']['order_id'])) {
				// pixel_event
				logger()->debug('Это лид-заказ');
				$this->parsePurchaseEvent();
				return true;
			} else {
				logger()->debug('Это странный заказ');
				dump($this->pixel_log->data);
				throw new \Exception('Странный формат заказа!');
			}
		}

		public function parsePurchaseEvent()
		{
			$order_id = $this->pixel_log->data['ed']['order_id'];
			$order = Order::query()
					->where('pp_id', '=', $this->pixel_log->pp_id)
					->where('order_id', '=', $order_id)
					->first() ?? new Order();

			$order->order_id = $order_id;
			$order->datetime = $this->pixel_log->created_at;
			$order->pp_id = $this->pixel_log->pp_id;
			$order->partner_id = $this->link->partner_id;
			$order->link_id = $this->link->id;
			$order->click_id = $this->pixel_log->data['click_id'] ?? null;
			$order->web_id = $this->pixel_log->data['utm_term'] ?? null;
			$order->offer_id = $this->link->offer_id;
			$order->client_id = $this->client->id;
			$order->pixel_id = $this->pixel_log->id;
			$order->status = 'new';
			$order->save();

			$this->pixel_log->is_order = true;

			logger()->debug('Это продажа');
		}

		public function parseDataLayerEvent() :bool
		{
            $events = $this->pixelLog->data['dataLayer'];

			foreach ($events as $event) {
				$purchase = $event['ecommerce']['purchase'];
				$order_id = $purchase['actionField']['id'];
                $gross_amount = $this->getGrossAmount($purchase['products']);;
                $productCount = count($purchase['products']);
                $orderFields = $this->getOrderFields($order_id, $gross_amount, $productCount);
                $order = OrderService::updateOrCreate($orderFields);

				logger()->debug('Найдено продуктов: ' . $productCount);

               $products = OrdersProductService::getProductsByOrderId( $order->order_id, $this->pixel_log->pp_id);

				foreach ($purchase['products'] as $product_data) {
					$product_id = $product_data['id'];

                    $filtered = $products->filter(function ($product) use ($product_id) {
                        return $product['id'] === $product_id;
                    });

                    $product = $filtered->first() ?? new OrdersProduct();
                    $productFields = $this->getProductFields($order, $product_data, $product);
                    OrdersProductService::updateOrCreate($product, $productFields);

					logger()->debug('Сохранен продукт: ' . $product->product_name);
				}
				$this->pixel_log->is_order = true;

				return true;
			}
		}

		public function parseCheckoutDataLayerEvent() :bool
		{
			$events = $this->pixel_log->data['dataLayer'];

			foreach ($events as $event) {
				$purchase = $event['ecommerce']['checkout'];

				$order_id = $this->pixel_log->data['ed']['order_id'];
                $grossAmount = $this->getGrossAmount($purchase['products']);;
                $productCount = count($purchase['products']);
                $orderFields = $this->getOrderFields($order_id, $grossAmount, $productCount);
                $order = OrderService::updateOrCreate($orderFields);

				logger()->debug('Найдено продуктов: ' . $productCount);

                $products = OrdersProductService::getProductsByOrderId( $order->order_id, $this->pixel_log->pp_id);

				foreach ($purchase['products'] as $product_data) {
					$product_id = $product_data['id'];

                    $filtered = $products->filter(function ($product) use ($product_id) {
                        return $product['id'] === $product_id;
                    });

                    $product = $filtered->first() ?? new OrdersProduct();

                    $productFields = $this->getProductFields($order, $product_data, $product);
                    OrdersProductService::updateOrCreate($product, $productFields);
					logger()->debug('Сохранен продукт: ' . $product->product_name);
				}
				$this->pixel_log->is_order = true;

				return true;
			}
		}

        private function getGrossAmount(array $products) :int
        {
            $grossAmount = 0;
            foreach ($products as $product_data) {
                $grossAmount += $product_data['price'] * ($product_data['quantity'] ?? 1);
            }

            return $grossAmount;
        }
        /**
         * @param $order
         * @param $productData
         * @param OrdersProduct $product
         * @return array
         */
        public function getProductFields($order, $productData, OrdersProduct $product): array
        {
            $productFields = [
                'pp_id' => $this->pixel_log->pp_id,
                'order_id' => $order->order_id,
                'parent_id' => $order->id,
                'datetime' => $order->datetime,
                'partner_id' => $order->partner_id,
                'offer_id' => $order->offer_id,
                'link_id' => $order->link_id,
                'product_id' => $productData['id'],
                'product_name' => trim(($productData['name'] ?? '') . ' ' . ($productData['variant'] ?? '')),
                'category' => $productData['category'] ?? null,
                'price' => $productData['price'],
                'quantity' => $productData['quantity'] ?? 1,
                'total' => $product->price * $product->quantity,
                'web_id' => $order->web_id,
                'click_id' => $order->click_id,
                'pixel_id' => $order->pixel_id,
                'amount' => 0,
                'amount_advert' => 0,
                'fee_advert' => 0,
            ];
            return $productFields;
        }

        /**
         * @param $order_id
         * @param $gross_amount
         * @param int $productCount
         * @return array
         */
        public function getOrderFields($order_id, $gross_amount, int $productCount): array
        {
            $orderFields = [
                'pp_id' => $this->pixel_log->pp_id,
                'order_id' => $order_id,
                'status' => 'new',
                'pixel_id' => $this->pixel_log->id,
                'datetime' => $this->pixel_log->created_at,
                'partner_id' => $this->link->partner_id,
                'link_id' => $this->link->id,
                'click_id' => $this->pixel_log->data['click_id'] ?? null,
                'web_id' => $this->pixel_log->data['utm_term'] ?? null,
                'offer_id' => $this->link->offer_id,
                'client_id' => $this->client->id,
                'gross_amount' => $gross_amount,
                'cnt_products' => $productCount
            ];
            return $orderFields;
        }
    }