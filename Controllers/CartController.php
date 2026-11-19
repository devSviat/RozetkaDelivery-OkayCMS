<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Controllers;

use Okay\Core\Cart;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\DeliveriesEntity;
use Okay\Helpers\UserHelper;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CitySearch;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CrossSiteRequest;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointList;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\UahTotal;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\CartQuotes;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryDate;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\QuoteSession;

/**
 * Публічні endpoints кошика. Міста й точки — лише з власних таблиць модуля; до Rozetka йде тільки
 * публічна орієнтовна дата для точки з таблиці. Жодних замовлень і персональних даних.
 */
class CartController
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function cities(Request $request, Response $response, CitySearch $search, QuoteSession $session)
    {
        if (!$session->allowSearch(time())) {
            $response->setStatusCode(429);
            $this->json($response, ['cities' => [], 'problem' => 'busy']);

            return;
        }
        $query = self::typed($request);
        $this->json($response, ['cities' => is_string($query) ? $search->find($query) : []]);
    }

    /** Точки міста з проблемами для обох випадків оплати: скрипт перемикає їх, коли покупець змінює оплату. */
    public function points(Request $request, Response $response, PointList $points, Cart $cart, UserHelper $userHelper, UahTotal $uahTotal, QuoteSession $session)
    {
        $cityId = self::uuid($request->get('city_id', null, null, false));
        if ($cityId === null) {
            $response->setStatusCode(400);
            $this->json($response, ['error' => 'city_id']);

            return;
        }
        if (!$session->allowSearch(time())) {
            $response->setStatusCode(429);
            $this->json($response, ['groups' => [], 'group' => null, 'points' => [], 'problem' => 'busy']);

            return;
        }
        $groupId = (int)$request->get('group_id', 'integer');
        $query = self::typed($request);
        $this->json($response, $points->forCity(
            $cityId,
            $uahTotal->metrics(self::visitorCart($cart, $userHelper)),
            false,
            $groupId > 0 ? $groupId : null,
            is_string($query) ? $query : '',
        ));
    }

    /**
     * Орієнтовна дата для точки (`ajax/rozetka/date?point_id=&delivery_id=`). `point_id` спершу звіряється
     * з форматом і таблицею точок, і лише потім пишеться в сесію й іде в Rozetka.
     */
    public function date(Request $request, Response $response, DeliveryDate $dates, EntityFactory $entityFactory, GroupsStore $groups, QuoteSession $session, CartQuotes $quotes)
    {
        // Маршрут пише вибір у сесію й витрачає ліміт дат сесії: посилання з чужого сайту не повинне до нього дістатися.
        if (CrossSiteRequest::is($_SERVER)) {
            $response->setStatusCode(403);
            $this->json($response, ['error' => 'origin']);

            return;
        }
        $pointId = self::uuid($request->get('point_id', null, null, false));
        $point = $pointId === null ? null : $entityFactory->get(PointEntity::class)->get($pointId);
        if (!$point || (int)($point->can_give_out ?? 0) !== 1 || !in_array((string)$point->kind, PointList::visibleKinds($groups->all()), true)) {
            $response->setStatusCode(400);
            $this->json($response, ['error' => 'point_id']);

            return;
        }
        $deliveryId = (int)$request->get('delivery_id', 'integer');
        $delivery = $deliveryId > 0 ? $entityFactory->get(DeliveriesEntity::class)->get($deliveryId) : null;
        if ($delivery && $quotes->isOwnMethod($delivery)) {
            $session->choose($deliveryId, (string)$pointId);
        }
        $this->json($response, ['date' => $dates->estimate((string)$pointId)]);
    }

    private function json(Response $response, array $data): void
    {
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE), RESPONSE_JSON);
    }

    private static function uuid(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match(self::UUID, $value) === 1 ? $value : null;
    }

    /**
     * Текст пошуку як введено. Request::get() типово кодує HTML, що перетворює "Кам'янець" на
     * "Кам&#039;янець" і нічого не знаходить; значення лише підставляється в запити й повертається як JSON.
     */
    private static function typed(Request $request): mixed
    {
        return $request->get('q', null, null, false);
    }

    /**
     * Кошик відвідувача так, як його бачить сторінка кошика. Ці endpoints оминають AbstractController ядра,
     * чий mergeCart(true) і завантажує кошик авторизованого покупця: Cart::init() лишає його порожнім.
     */
    private static function visitorCart(Cart $cart, UserHelper $userHelper): Cart
    {
        $userHelper->mergeCart(true);

        return $cart->get();
    }
}
